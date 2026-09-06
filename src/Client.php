<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use Dropdown;
use GlpiPlugin\Glpiai\Provider\Provider;
use GlpiPlugin\Glpiai\Provider\Registry;
use GlpiPlugin\Glpiai\Provider\StreamingProvider;

/**
 * The one door features go through.
 *
 * Features never build a provider themselves. Everything that has to be true of
 * *every* AI call — the master switch, the tenant gate, the timeout, the usage
 * record — is enforced here, once. A feature that reached past this into
 * Registry would be a feature that silently opts out of the entity policy, and
 * nobody would notice until a client asked.
 *
 * Two entry points: {@see complete()} for a single exchange, and {@see run()}
 * for one where the model may call tools. run() is a loop over complete(), so
 * the guards hold on every turn rather than only the first.
 */
final class Client
{
    /**
     * Run a prompt on behalf of a given entity.
     *
     * The entity is a required argument rather than something read from the
     * session, and that is deliberate: background work — cron, the mail
     * collector, a queue worker — has no session, and an entity gate that
     * quietly resolved to entity 0 in those contexts would be no gate at all.
     * Callers must say whose data this is.
     *
     * @throws AiException
     */
    public static function complete(Prompt $prompt, int $entities_id): Completion
    {
        if (!Settings::flag('enabled')) {
            throw new AiException(AiException::DISABLED, 'AI features are switched off.', null);
        }

        if (!Settings::entityAllowed($entities_id)) {
            // Named, not numbered, and it says what to do. An entity id is the
            // one fact the reader already has and cannot act on; which setting
            // decided this is the part they need, and on a fresh install the
            // answer is usually that nobody has filled the allowlist in yet.
            throw new AiException(
                AiException::DISABLED,
                Settings::allowedEntities() === [] && Settings::get('entity_mode') !== 'all'
                    ? sprintf(
                        'No entity may use AI features yet: the allowlist under %s is empty, '
                        . 'and an empty allowlist permits nothing.',
                        __('Setup > Plugins > AI > Which entities may use AI', 'glpiai')
                    )
                    : sprintf(
                        '%s is not permitted to use AI features. Add it under %s.',
                        Dropdown::getDropdownName('glpi_entities', $entities_id) ?: sprintf('Entity %d', $entities_id),
                        __('Setup > Plugins > AI > Which entities may use AI', 'glpiai')
                    )
            );
        }

        $provider = Registry::active();
        if ($provider === null || !$provider->isConfigured()) {
            throw new AiException(AiException::DISABLED, 'No AI provider is configured.');
        }

        // The configured timeout is a ceiling, not an override: a caller that
        // asked for something shorter knows its own latency budget.
        $prompt->timeout = min($prompt->timeout, (int) Settings::get('timeout'));

        $started = microtime(true);

        // Streamed only when somebody is actually watching, and only where the
        // adapter implements it. A streamed call and a whole one produce the
        // same Completion, so nothing downstream branches on which happened —
        // the difference is entirely that the words arrive as they are written
        // instead of ninety seconds later, and there is no reason to pay the
        // extra connection complexity for a cron job nobody is looking at.
        $completion = $provider instanceof StreamingProvider && Progress::watched()
            ? $provider->stream($prompt, static function (string $kind, string $text): void {
                Progress::emit(
                    $kind === 'thinking' ? Progress::THINKING : Progress::TEXT,
                    ['text' => $text]
                );
            })
            : $provider->complete($prompt);

        self::record($prompt, $completion, microtime(true) - $started, $entities_id);

        return $completion;
    }

    /**
     * Run a prompt to completion, executing whatever tools the model asks for.
     *
     * The loop is deliberately here and not in a caller. Every guard that
     * {@see complete()} enforces has to hold on *every* turn, not just the
     * first — a loop written in a feature would check the entity gate once and
     * then make five more calls without it — and the turn budget is the only
     * thing standing between a confused model and an unbounded bill.
     *
     * Tools must already be on the prompt; this does not attach them. Which
     * tools a feature offers is part of that feature's design, and a loop that
     * silently handed the model everything in the registry would mean adding a
     * tool anywhere changed the behaviour of every feature at once.
     *
     * @throws AiException
     */
    public static function run(
        Prompt $prompt,
        ToolContext $context,
        ?int $max_turns = null,
        /**
         * Everything the registry holds, when the caller wants tool search.
         *
         * Passed rather than read from {@see ToolContext}, which is deliberately
         * small, and rather than re-read from the registry here, which would
         * run every contributing plugin's hook once per turn. Empty means no
         * expansion: a caller that offered a fixed set gets exactly that set on
         * every turn, which is what the non-conversational features want.
         *
         * @var Tool[]
         */
        array $all_tools = []
    ): Conversation {
        $conversation = new Conversation();
        $budget       = $max_turns ?? max(1, (int) Settings::get('max_tool_turns'));

        // Continuations are not turns and do not come out of this budget: the
        // budget bounds how many times the model may *think again*, and being
        // cut off mid-sentence is not thinking again.
        for ($turn = 0; $turn < $budget; $turn++) {
            // Announced before the call, not after: the wait *is* the thing
            // being reported, and an event that arrives once the turn is over
            // describes a silence that has already happened.
            Progress::emit(Progress::TURN, [
                'turn'   => $turn + 1,
                'budget' => $budget,
                'tools'  => count($prompt->tools),
            ]);

            $completion            = self::complete($prompt, $context->entities_id);
            $conversation->turns[] = $completion;

            if (!$completion->wantsTools()) {
                return self::finish($conversation, $prompt, $context);
            }

            // The model's own turn goes back verbatim before the answers do:
            // all three conventions correlate a result to its request, and a
            // transcript with orphaned results is rejected rather than ignored.
            $prompt->add(Message::toolCalls($completion->text, $completion->tool_calls));

            $results           = [];
            $turn_invocations  = [];

            foreach ($completion->tool_calls as $call) {
                // Before it runs, with the arguments the model chose. Watching
                // a tool being *called* is what turns a silent pause into
                // "it is looking at the switch port" — and the arguments are
                // the half a technician judges: a search for the wrong words is
                // visible instantly and invisible in a summary afterwards.
                Progress::emit(Progress::TOOL, [
                    'name'      => $call->name,
                    'arguments' => $call->arguments,
                ]);

                $invocation = ToolRegistry::execute($call, $prompt->tools, $context);

                $conversation->invocations[] = $invocation;
                $turn_invocations[]          = $invocation;
                self::recordTool($invocation, $context);

                Progress::emit(Progress::TOOL_RESULT, [
                    'name'        => $call->name,
                    'source'      => $invocation->source,
                    'mutating'    => $invocation->mutating,
                    'error'       => $invocation->failed(),
                    'duration_ms' => $invocation->duration_ms,
                    // Capped hard. The panel shows what came back so a
                    // technician can see the model was told something wrong,
                    // and a 40KB osquery result would push the conversation off
                    // the screen to make a point that the first few lines make.
                    'result'      => mb_substr($invocation->result->content, 0, 1200),
                    'truncated'   => mb_strlen($invocation->result->content) > 1200,
                ]);

                $results[] = $invocation->result;
            }

            $prompt->add(Message::toolResults($results));

            // A `find_tools` call this turn makes what it found callable on the
            // next one. Without this the model can search, read back a list of
            // names, and then have no way to use any of them — which looks like
            // a broken feature rather than a missing wire.
            if ($all_tools !== []) {
                $prompt->withTools(
                    Toolbox::expand($prompt->tools, $turn_invocations, $all_tools),
                    $prompt->tool_choice
                );
            }
        }

        // Budget spent and the model still wants tools. Rather than hand back a
        // completion that is nothing but unanswered calls — which every caller
        // would have to special-case — take the tools away and ask once more,
        // so there is always prose to show. Marked, because an answer reached
        // this way is one the model did not think it was ready to give.
        $conversation->exhausted = true;
        $conversation->turns[]   = self::complete($prompt->withoutTools(), $context->entities_id);

        return self::finish($conversation, $prompt, $context);
    }

    /**
     * How many times one answer may be asked to carry on.
     *
     * Three, which is four ceilings' worth of answer. Past that the model is
     * not writing a long answer, it is looping — and each continuation is a
     * whole round trip with the transcript on it, so an unbounded version of
     * this is an unbounded bill.
     */
    private const MAX_CONTINUATIONS = 3;

    /**
     * Finish an answer that ran out of output budget mid-sentence.
     *
     * This is the failure people actually report — "it stops halfway", "it cut
     * off just as it was getting to the point" — and it is invisible from the
     * outside: the model reports an ordinary finish reason, the text is fluent,
     * and it stops at a place that looks like a place. Raising the ceiling
     * helps and does not fix it, because a reasoning model spends the same
     * budget thinking before it writes a word.
     *
     * So the answer is asked to continue, and the pieces are joined. Three
     * things make that safe rather than clever:
     *
     *  - **Only for prose.** A caller with a schema is getting JSON, and two
     *    JSON fragments concatenated are not JSON — those callers already
     *    retry with a bigger ceiling, which is the right move for a structured
     *    answer.
     *  - **Only what the model already said** goes back, as its own turn,
     *    followed by an instruction to carry on. Nothing is invented and
     *    nothing is re-asked.
     *  - **Bounded**, and the flag survives: if it is *still* truncated after
     *    the last attempt, `Conversation::truncated()` says so and the panel
     *    tells the technician rather than presenting three quarters of an
     *    answer as a whole one.
     */
    private static function finish(
        Conversation $conversation,
        Prompt $prompt,
        ToolContext $context
    ): Conversation {
        if ($prompt->schema !== null) {
            return $conversation;
        }

        for ($attempt = 0; $attempt < self::MAX_CONTINUATIONS; $attempt++) {
            $last = $conversation->final();

            if (!$last->wasTruncated()) {
                break;
            }

            Progress::emit(Progress::CONTINUED, ['attempt' => $attempt + 1]);

            // The partial answer goes back as the model's own turn, so it can
            // see exactly where it stopped — including mid-word, which is
            // usually where it stopped.
            $prompt->add(Message::assistant($last->text));
            $prompt->add(Message::user(
                'You were cut off by the output limit. Continue from exactly where you stopped, '
                . 'mid-sentence if that is where it was. Do not repeat anything you have already '
                . 'written, do not start again, and do not apologise or explain — just carry on.'
            ));

            // Tools off for the rest: the model was answering, not looking
            // something up, and offering them again invites it to start a new
            // investigation instead of finishing the sentence.
            $conversation->turns[] = self::complete($prompt->withoutTools(), $context->entities_id);
            $conversation->continued++;
        }

        return $conversation;
    }

    /**
     * Run a prompt without the entity gate — for administrative calls only.
     *
     * The connection test needs this: it sends a fixed string of our own with
     * no customer data in it, so gating it on a tenant policy would make the
     * settings page untestable until an allowlist happened to be filled in.
     * Named to be conspicuous at the call site.
     *
     * @throws AiException
     */
    public static function completeAsAdmin(Prompt $prompt, Provider $provider): Completion
    {
        $started    = microtime(true);
        $completion = $provider->complete($prompt);

        self::record($prompt, $completion, microtime(true) - $started, 0);

        return $completion;
    }

    /** Is there a usable provider right now? */
    public static function isReady(): bool
    {
        if (!Settings::flag('enabled')) {
            return false;
        }

        $provider = Registry::active();

        return $provider !== null && $provider->isConfigured();
    }

    /**
     * Write the call to the usage log.
     *
     * Provider, model and token counts go in every time; the prompt text only
     * when `log_prompts` is on, because it is ticket content and this table is
     * not the place to duplicate it by default.
     *
     * Deliberately swallows its own failures. A logging table that is missing
     * or full must not take down the feature it was added to observe.
     */
    private static function record(
        Prompt $prompt,
        Completion $completion,
        float $seconds,
        int $entities_id
    ): void {
        try {
            /** @var \DBmysql $DB */
            global $DB;

            $DB->insert(UsageLog::TABLE, [
                'entities_id'    => $entities_id,
                'users_id'       => (int) \Session::getLoginUserID(),
                'provider'       => $completion->provider,
                'model'          => $completion->model,
                'tier'           => $prompt->tier,
                'input_tokens'   => $completion->usage->input_tokens,
                'output_tokens'  => $completion->usage->output_tokens,
                'duration_ms'    => (int) round($seconds * 1000),
                'finish_reason'  => $completion->finish_reason,
                'fingerprint'    => $prompt->fingerprint(),
                'prompt_text'    => Settings::flag('log_prompts') ? self::renderForLog($prompt) : null,
                'date_creation'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trigger_error('glpiai: could not record usage: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Write one tool call to the audit trail.
     *
     * Arguments are recorded but results are not. The argument list is the part
     * that answers "what did the AI go looking for on this client's tenant",
     * which is the question that actually gets asked; results are ticket
     * content, and duplicating those into a second table is how a log becomes a
     * data-retention problem of its own.
     *
     * Swallows its own failures, for the same reason record() does: an audit
     * table that is missing must not take down the feature it observes. It does
     * warn, because a *silently* missing audit trail is worse than a noisy one.
     */
    private static function recordTool(ToolInvocation $invocation, ToolContext $context): void
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;

            $DB->insert(ToolLog::TABLE, [
                'entities_id'   => $context->entities_id,
                'users_id'      => (int) \Session::getLoginUserID(),
                'tool'          => $invocation->call->name,
                'source'        => $invocation->source,
                'is_mutating'   => $invocation->mutating ? 1 : 0,
                'arguments'     => mb_substr(
                    (string) json_encode($invocation->call->arguments, JSON_UNESCAPED_SLASHES),
                    0,
                    8000
                ),
                'is_error'      => $invocation->result->is_error ? 1 : 0,
                'error_message' => $invocation->result->is_error
                    ? mb_substr($invocation->result->content, 0, 500)
                    : null,
                'duration_ms'   => $invocation->duration_ms,
                'itemtype'      => $context->itemtype,
                'items_id'      => $context->items_id,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trigger_error('glpiai: could not record tool call: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    private static function renderForLog(Prompt $prompt): string
    {
        $parts = [];
        if ($prompt->system !== null) {
            $parts[] = '[system] ' . $prompt->system;
        }
        foreach ($prompt->messages as $message) {
            $parts[] = '[' . $message->role . '] ' . $message->content;
        }

        return mb_substr(implode("\n\n", $parts), 0, 60000);
    }
}
