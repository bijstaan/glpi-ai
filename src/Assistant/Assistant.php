<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Assistant;

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Conversation;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Message;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Toolbox;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolRegistry;

/**
 * The troubleshooting assistant.
 *
 * The first thing in this plugin that is a *conversation* rather than a single
 * request with a shape. Everything else asks one question with one answer:
 * rank these tickets, classify this one, write this up. This is open-ended, and
 * that changes what it needs — a transcript, the whole tool set rather than a
 * chosen few, and enough turns to actually work a problem.
 *
 * It is also the first thing where the tools matter more than the model. A
 * model asked "why is that laptop slow" from its own knowledge produces a
 * competent list of general causes, which the technician already knew. The same
 * model with `osquery_live` looks at the machine. The entire value is in what
 * it can reach, which is why the tool registry other plugins extend was built
 * before anything used it.
 *
 * Technician-facing, like everything else here. There is no path from this to a
 * requester: the panel renders only in the central interface, and nothing it
 * produces is written anywhere a customer reads.
 */
final class Assistant
{
    /**
     * The output ceiling for one answer, from the settings page.
     *
     * It used to be a hard-coded 2,000, and that is the number behind "the
     * answer keeps getting cut off". A reasoning model spends the ceiling
     * thinking before it writes a word, so two thousand buys a paragraph on a
     * plain model and half a sentence on a thinking one — and the failure is
     * silent, because the model reports an ordinary finish reason and the text
     * stops somewhere plausible.
     *
     * Clamped rather than trusted: the value comes from a form, and a ceiling
     * of zero would make every answer empty while looking like a configuration
     * anybody might try.
     */
    private static function maxTokens(): int
    {
        return max(500, min(32000, (int) Settings::get('assistant_max_tokens')));
    }

    /** Longest question accepted. Beyond this it is a document, not a question. */
    private const MAX_QUESTION = 4000;

    /**
     * Can the signed-in user actually use the assistant, here, right now?
     *
     * The entity policy is part of the answer and used not to be. Without it
     * this returned true for an instance whose allowlist was empty: the panel
     * opened, took a question, and only then did Client::complete() refuse with
     * "Entity 0 is not permitted to use AI features" — a message that arrives
     * after the work, names a setting three cards away, and is the first
     * indication anything is wrong. Availability now means what it says.
     *
     * @param int|null $entities_id defaults to the session's active entity
     */
    public static function available(?int $entities_id = null): bool
    {
        if (!Settings::flag('enabled') || !Settings::flag('assistant_enabled')) {
            return false;
        }

        if (!Client::isReady()) {
            return false;
        }

        return Settings::entityAllowed($entities_id ?? (int) \Session::getActiveEntity());
    }

    /**
     * Ask a question in a thread.
     *
     * @return array{answer:string,answer_html:string,trail:string,
     *               tools:array<int,array<string,mixed>>,exhausted:bool,
     *               truncated:bool,continued:int}
     * @throws AiException
     */
    public static function ask(int $threads_id, string $question): array
    {
        $question = trim(mb_substr($question, 0, self::MAX_QUESTION));

        if ($question === '') {
            throw new AiException(AiException::INVALID, 'Ask something.');
        }

        $thread = Thread::mine($threads_id);
        if ($thread === null) {
            throw new AiException(AiException::INVALID, 'That conversation is not yours.');
        }

        $item        = Context::item((string) $thread['itemtype'], (int) $thread['items_id']);
        $entities_id = Context::entity($item);

        $prompt = Prompt::make($question, self::instruction($item, $question))
            ->withTier(Prompt::TIER_QUALITY)
            ->withMaxTokens(self::maxTokens());

        // The transcript goes in *before* the new question, which Prompt::make
        // has already placed. Rebuilding the message list is the only way round
        // that, and it is worth doing here rather than complicating Prompt for
        // the one caller that has a history.
        $prompt->messages = array_merge(self::replay($threads_id), $prompt->messages);

        // Everything the registry has. A troubleshooting conversation cannot be
        // told in advance which tools it will need, which is exactly the case
        // the other features do not have — they each offer a chosen few.
        //
        // Past a threshold "everything" stops being a good idea and Toolbox
        // narrows the declared set to the core plus a search tool; the loop
        // adds back whatever the model finds. Below it, this is a no-op.
        $all = ToolRegistry::all($entities_id);
        $prompt->withTools(Toolbox::offer($all));

        $context = new ToolContext(
            entities_id: $entities_id,
            itemtype: $item?->getType(),
            items_id: $item !== null ? (int) $item->getID() : null
        );

        // No turn budget of its own: this used to pass a hard-coded twelve,
        // which meant the "Maximum tool turns" setting did nothing at all for
        // the one feature people actually hit the limit in — an administrator
        // could raise it, watch nothing change, and reasonably conclude the
        // setting was decorative.
        //
        // Passing null makes Client::run read the configured value. This is the
        // feature that budget exists for: a troubleshooting question genuinely
        // is "find the machine, look at its disks, check whether anyone else
        // reported this, read that ticket" — four tools before a word of the
        // answer — so if the default is too low, the fix is to raise the
        // setting, and now that works.
        $conversation = Client::run($prompt, $context, null, $all);

        Thread::nameFrom($threads_id, $question);
        Thread::append($threads_id, ['role' => 'user', 'content' => $question]);
        Thread::append($threads_id, [
            'role'    => 'assistant',
            'content' => $conversation->text(),
            'trail'   => $conversation->trail(),
        ]);

        return [
            'answer'    => $conversation->text(),
            // Said out loud rather than left to be noticed. A truncated answer
            // is fluent and stops somewhere plausible, so the only person who
            // can tell it is missing its last third is the one who is told.
            'truncated' => $conversation->truncated(),
            'continued' => $conversation->continued,
            // Rendered server-side rather than in the panel: the renderer is
            // already here, it is the one every other feature uses, and a
            // second markdown implementation in JavaScript would be a second
            // place for the escaping to be wrong.
            'answer_html' => Markdown::toHtml($conversation->text()),
            'trail'     => $conversation->trail(),
            'tools'     => self::toolSummary($conversation),
            'exhausted' => $conversation->exhausted,
        ];
    }

    /**
     * Prior turns, as messages.
     *
     * Only the prose is replayed, not the tool calls. Replaying the calls would
     * be more faithful and is the wrong trade: tool results are the bulk of a
     * transcript by a wide margin, they are stale by the next question — a disk
     * that was full ten minutes ago may not be — and every provider charges for
     * them again on every subsequent turn. What survives is what the model
     * concluded, which is the part that was worth keeping.
     *
     * @return Message[]
     */
    private static function replay(int $threads_id): array
    {
        $messages = [];

        foreach (Thread::messages($threads_id) as $turn) {
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $messages[] = ($turn['role'] ?? '') === 'assistant'
                ? Message::assistant($content)
                : Message::user($content);
        }

        return $messages;
    }

    /**
     * The system instruction.
     *
     * Most of it is about how to use the tools, because that is what separates
     * a useful answer from a plausible one. The instruction to *look* before
     * answering is the single most valuable line in it: a model asked why a
     * machine is slow will happily produce a general answer, and the general
     * answer is the one the technician did not need.
     */
    private static function instruction(?\CommonDBTM $item, string $question = ''): string
    {
        $lines = [
            'You are HEIMDALL, helping an IT technician at a managed service provider',
            'troubleshoot. You are talking to the technician, never to their customer. Nothing',
            'you write is shown to a requester.',
            '',
            'Your name stands for Helpdesk Endpoint Inspection, Monitoring, Diagnostics And',
            'Live Lookup. Do not introduce yourself or mention it unless you are asked — and if',
            'you are asked, answer with whatever you know, including where the name comes from.',
            '',
            'Use the tools. This is the whole point of you: the technician can already guess at',
            'general causes, and what they cannot do quickly is look at six places at once. If a',
            'question can be answered by looking something up, look it up before answering. If a',
            'question is about a specific machine, find the machine first.',
            '',
            '  - Say what you actually found, and say where you found it. "The disk is at 96%',
            '    according to osquery" is useful; "the disk may be full" is not.',
            '  - When a tool tells you nothing, say so rather than filling the gap. An empty',
            '    result is information: it means that is not where the problem is.',
            '  - What you already know is welcome on top of what you found, and often it is the',
            '    most useful part — there is no tool for "what usually causes this". Offer it,',
            '    and keep it distinguishable from what you looked up. "The port is showing',
            '    18,000 input errors; that pattern is usually a duplex mismatch or a bad pair"',
            '    is right, because the first half came from the switch and the second is you,',
            '    and the technician can tell which is which. What you must never do is present',
            '    something you are recalling as something you checked.',
            '  - Do not repeat a query you have already run. If you need something a tool cannot',
            '    give you, say what you would need and let the technician get it.',
            '  - Live queries reach real machines belonging to a customer. Ask one when it will',
            '    answer the question; do not sweep a fleet to see what turns up.',
            '  - Be brief. A technician reading this is mid-problem. Short paragraphs, no',
            '    preamble, no summary of what you are about to do.',
            '  - You may be wrong, and the technician can check. Say what you are unsure about',
            '    rather than rounding it to confidence.',
        ];

        // What this instance has to say, after the shipped instruction and
        // before the open record. Order matters: the lines above are about how
        // to work, these are about where — and a model reads later text as
        // qualifying earlier text, which is the right way round here.
        $house = trim((string) Settings::get('assistant_instructions'));
        if ($house !== '') {
            $lines[] = '';
            $lines[] = 'This instance has its own standing instructions. They were written by an';
            $lines[] = 'administrator here and are more specific than anything you know generally:';
            $lines[] = '';
            $lines[] = $house;
        }

        $skills = Skill::instructionsFor($question, (int) \Session::getActiveEntity());
        if ($skills !== '') {
            $lines[] = $skills;
        }

        $context = Context::describe($item);
        if ($context !== '') {
            $lines[] = '';
            $lines[] = 'The technician currently has this open. It may be what they are asking';
            $lines[] = 'about, or it may be irrelevant — they opened the panel from wherever they';
            $lines[] = 'happened to be:';
            $lines[] = '';
            $lines[] = $context;
        }

        return implode("\n", $lines);
    }

    /**
     * What the model reached for, for the panel to show.
     *
     * Shown rather than hidden, and this is deliberate. An answer that came
     * from looking at the machine and an answer that came from the model's
     * general knowledge read identically, and the difference is the entire
     * question of whether to believe it.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function toolSummary(Conversation $conversation): array
    {
        $out = [];

        foreach ($conversation->invocations as $invocation) {
            $out[] = [
                'name'  => $invocation->call->name,
                'error' => $invocation->result->is_error,
                'args'  => mb_substr(json_encode($invocation->call->arguments) ?: '', 0, 200),
            ];
        }

        return $out;
    }
}
