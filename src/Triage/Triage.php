<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Triage;

use CommonDBTM;
use CronTask;
use Dropdown;
use Glpi\RichText\RichText;
use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Settings;
use Plugin;
use Ticket;
use Toolbox;

/**
 * Triage: propose a category, a severity and a procedure for a new ticket.
 *
 * The first feature in this plugin that offers to change something. Everything
 * before it either answered a question or ranked records that already existed,
 * so it is worth being explicit about the line: nothing here writes to a
 * ticket. The model produces a row in {@see Suggestion}; a technician's click
 * is what calls `Ticket::update()`, under their own name, in their own history.
 * There is no configuration that turns that off, because a plugin that can
 * silently recategorise a queue is a different and much worse product.
 *
 * The other deliberate shape is *when* it runs. Triage is queued at creation
 * and executed by cron, not inline. A provider round trip inside the request
 * that created the ticket would put a vendor's latency in front of whoever
 * pressed submit — for portal tickets, that is a customer — and would make the
 * mail collector's runtime depend on an external API.
 */
final class Triage
{
    /** Ticket text sent to the provider, per ticket. Enough for prose, not a thread. */
    private const MAX_CHARS = 3000;

    /**
     * Output budget for one triage answer.
     *
     * The answer itself is about eighty tokens, so this looks absurdly
     * generous — and four hundred was the first guess for exactly that reason.
     * It fails completely against a reasoning model, which spends its budget
     * thinking before it writes anything: the request comes back with
     * `finish_reason: length`, an empty message, and a thousand words of
     * discarded reasoning. Nothing about that failure suggests the cause, which
     * is why the ceiling is now well clear of it and why {@see suggest()} names
     * truncation explicitly when it happens anyway.
     */
    private const MAX_TOKENS = 1500;

    /**
     * Should this ticket be triaged, and if not, why not?
     *
     * Returns null when it should. The reason strings are for the log and the
     * settings page, not for a user — "not eligible" with no explanation is the
     * kind of thing that costs an afternoon to work out.
     */
    public static function skipReason(Ticket $ticket): ?string
    {
        if (!Settings::flag('enabled')) {
            return 'AI features are switched off';
        }

        if (!Settings::flag('triage_enabled')) {
            return 'triage is switched off';
        }

        $entities_id = (int) $ticket->fields['entities_id'];
        if (!Settings::entityAllowed($entities_id)) {
            return sprintf('entity %d is not permitted to use AI features', $entities_id);
        }

        // The session, not the ticket, answers "who raised this". A ticket from
        // the mail collector has no session at all, which is precisely the case
        // this is trying to include.
        if (
            Settings::flag('triage_skip_central')
            && (($_SESSION['glpiactiveprofile']['interface'] ?? '') === 'central')
        ) {
            return 'raised from the technician interface';
        }

        $types = Settings::triageRequestTypes();
        if ($types !== [] && !in_array((int) $ticket->fields['requesttypes_id'], $types, true)) {
            return 'request type is not triaged';
        }

        return null;
    }

    /** Queue a newly created ticket, if it qualifies. Called from the ITEM_ADD hook. */
    public static function ticketCreated(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket || $item->isNewItem()) {
            return;
        }

        if (self::skipReason($item) !== null) {
            return;
        }

        Suggestion::queue((int) $item->getID(), (int) $item->fields['entities_id']);
    }

    /** A purged ticket takes its suggestion with it, whatever the settings say. */
    public static function ticketPurged(CommonDBTM $item): void
    {
        if ($item instanceof Ticket) {
            Suggestion::forget((int) $item->getID());
        }
    }

    /**
     * Ask the model about one queued ticket and store what it said.
     *
     * Every failure is recorded on the row rather than thrown at the caller: a
     * cron task that stopped at the first unparseable answer would leave the
     * rest of the queue behind a single bad ticket, and the technician looking
     * at that ticket deserves to be told what went wrong rather than shown an
     * empty panel forever.
     *
     * @return bool whether a suggestion was produced
     */
    public static function suggest(int $suggestions_id): bool
    {
        $row = Suggestion::byId($suggestions_id);
        if ($row === null) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $row['tickets_id'])) {
            Suggestion::forget((int) $row['tickets_id']);

            return false;
        }

        $entities_id = (int) $row['entities_id'];

        try {
            $prompt = Prompt::make(self::describe($ticket), Taxonomy::instruction($entities_id))
                ->withTier(Prompt::TIER_FAST)
                ->withSchema(self::schema(), 'triage')
                ->withMaxTokens(self::MAX_TOKENS);

            $completion = Client::complete($prompt, $entities_id);
        } catch (AiException $e) {
            Suggestion::fail($suggestions_id, $e->getMessage());

            return false;
        }

        $data = $completion->data;
        if (!is_array($data)) {
            // Truncation is called out by name. It is the failure a reasoning
            // model produces, it looks identical to a malformed answer from the
            // outside, and the fix — a bigger ceiling or a model that thinks
            // less — is not one anybody guesses from "unusable object".
            Suggestion::fail($suggestions_id, $completion->wasTruncated()
                ? sprintf(
                    'The model used its whole %d-token budget before answering. This usually means '
                    . 'a reasoning model: either raise the ceiling or use one that thinks less.',
                    self::MAX_TOKENS
                )
                : 'The provider did not return a usable triage object.');

            return false;
        }

        $values = self::validate($data, $entities_id, $ticket);

        Suggestion::store($suggestions_id, $values + [
            'state'    => Suggestion::READY,
            'provider' => $completion->provider,
            'model'    => $completion->model,
        ]);

        return true;
    }

    /**
     * Turn a model's answer into something safe to store.
     *
     * Nothing here trusts the response. Ids are checked against what the model
     * was actually offered, the scales are clamped, and anything that fails
     * becomes "no suggestion for that field" rather than an error — a model that
     * gets one field wrong should still be useful about the other three.
     *
     * Fields the ticket already agrees with are marked `matched` on the way
     * past, because they are not decisions anybody is going to make and should
     * not sit in the panel as though they were.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function validate(array $data, int $entities_id, Ticket $ticket): array
    {
        $category = (int) ($data['itilcategories_id'] ?? 0);
        $sop      = (int) ($data['sops_id'] ?? 0);

        $values = [
            'itilcategories_id'      => Taxonomy::hasCategory($entities_id, $category) ? $category : 0,
            'urgency'                => self::clamp($data['urgency'] ?? 0),
            'impact'                 => self::clamp($data['impact'] ?? 0),
            'plugin_glpisop_sops_id' => Taxonomy::hasSop($entities_id, $sop) ? $sop : 0,
            'confidence'             => in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? (string) $data['confidence']
                : 'low',
            'reasoning'              => mb_substr(trim((string) ($data['reasoning'] ?? '')), 0, 500),
        ];

        foreach (Suggestion::FIELDS as $field => $column) {
            $suggested = (int) $values[$field];
            $current   = (int) ($ticket->fields[$field] ?? 0);

            $values[$column] = ($suggested === 0 || $suggested === $current)
                ? Suggestion::MATCHED
                : Suggestion::OPEN;
        }

        return $values;
    }

    private static function clamp(mixed $value): int
    {
        $n = (int) $value;

        return $n >= 1 && $n <= 5 ? $n : 0;
    }

    /**
     * The ticket, as the model sees it.
     *
     * Its current values are included on purpose. Without them the model cannot
     * tell "this is already right" from "nobody has decided yet", and the panel
     * fills with chips proposing what the ticket already says.
     */
    private static function describe(Ticket $ticket): string
    {
        $text = RichText::getTextFromHtml(
            (string) ($ticket->fields['content'] ?? ''),
            false,
            true,
            false,
            true,
            true
        );

        $lines = [
            'Title: ' . (string) $ticket->fields['name'],
            'Currently filed as: ' . self::currentCategory($ticket),
            sprintf(
                'Currently urgency %d, impact %d.',
                (int) $ticket->fields['urgency'],
                (int) $ticket->fields['impact']
            ),
            '',
            'Description:',
            Toolbox::substr(trim($text), 0, self::MAX_CHARS),
        ];

        return implode("\n", $lines);
    }

    private static function currentCategory(Ticket $ticket): string
    {
        $id = (int) ($ticket->fields['itilcategories_id'] ?? 0);

        return $id > 0
            ? Dropdown::getDropdownName('glpi_itilcategories', $id)
            : 'nothing';
    }

    /**
     * The answer shape.
     *
     * Plain types, flat, and `enum` only — the three dialects behind
     * Prompt::withSchema() agree on that much and diverge past it. Ids are
     * integers with 0 for "none" rather than nullable fields, because null
     * support is exactly the sort of thing that works on two providers out of
     * four and fails as a 400 on the others.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'itilcategories_id' => [
                    'type'        => 'integer',
                    'description' => 'Category id from the list, or 0 if none fits.',
                ],
                'urgency' => ['type' => 'integer', 'description' => '1 to 5.'],
                'impact'  => ['type' => 'integer', 'description' => '1 to 5.'],
                'sops_id' => [
                    'type'        => 'integer',
                    'description' => 'Procedure id from the list, or 0.',
                ],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'reasoning'  => [
                    'type'        => 'string',
                    'description' => 'One short sentence for the technician.',
                ],
            ],
            'required' => [
                'itilcategories_id', 'urgency', 'impact', 'sops_id', 'confidence', 'reasoning',
            ],
        ];
    }

    /**
     * Apply one suggested field to its ticket.
     *
     * Goes through `Ticket::update()` rather than a direct write, so GLPI's
     * rules, notifications and history all see it as the ordinary edit it is —
     * attributed to the technician who clicked, because they are the one who
     * decided. The suggestion row records that a model proposed it.
     *
     * @return string an empty string on success, or why it was refused
     */
    public static function apply(int $suggestions_id, string $field): string
    {
        if (!isset(Suggestion::FIELDS[$field])) {
            return 'unknown field';
        }

        $row = Suggestion::byId($suggestions_id);
        if ($row === null || (string) $row['state'] !== Suggestion::READY) {
            return 'no suggestion';
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canUpdateItem()) {
            return 'not allowed';
        }

        $value = (int) $row[$field];
        if ($value <= 0) {
            return 'nothing suggested';
        }

        // The SOP chip attaches a procedure through glpi-sop rather than
        // writing a column: there is no such column on a ticket, and the run is
        // what a technician actually wants — a checklist, not a foreign key.
        if ($field === 'plugin_glpisop_sops_id') {
            $error = self::attachSop((int) $row['tickets_id'], $value);
            if ($error !== '') {
                return $error;
            }
        } else {
            $ticket->update(['id' => (int) $row['tickets_id'], $field => $value]);
        }

        Suggestion::decide($suggestions_id, $field, Suggestion::ACCEPTED);

        return '';
    }

    /**
     * Ask glpi-sop to start a run, when it is installed.
     *
     * The origin string is `'ai'` rather than a class constant so that this
     * keeps working against a glpi-sop that predates it: that plugin labels an
     * origin it does not recognise with the raw string instead of failing, so
     * the worst case is a slightly terse line in a run log.
     */
    private static function attachSop(int $tickets_id, int $sops_id): string
    {
        if (
            !Plugin::isPluginActive('glpisop')
            || !class_exists('GlpiPlugin\\Glpisop\\Run')
            || !class_exists('GlpiPlugin\\Glpisop\\Sop')
        ) {
            return 'the SOP plugin is not available';
        }

        $sop = new ('GlpiPlugin\\Glpisop\\Sop')();
        if (!$sop->getFromDB($sops_id)) {
            return 'that procedure no longer exists';
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return 'that ticket no longer exists';
        }

        $run = call_user_func(['GlpiPlugin\\Glpisop\\Run', 'attach'], $sop, $ticket, 'ai');

        return ((int) $run) > 0 ? '' : 'the procedure could not be started';
    }

    /** Record that a technician looked at a suggestion and said no. */
    public static function dismiss(int $suggestions_id, string $field): bool
    {
        $row = Suggestion::byId($suggestions_id);
        if ($row === null) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canViewItem()) {
            return false;
        }

        return Suggestion::decide($suggestions_id, $field, Suggestion::DISMISSED);
    }

    // ------------------------------------------------------------------- cron

    /** @return array{done:int,failed:int,remaining:int,message:string} */
    public static function run(?int $budget = null): array
    {
        if (!Settings::flag('enabled') || !Settings::flag('triage_enabled')) {
            return ['done' => 0, 'failed' => 0, 'remaining' => 0, 'message' => 'Triage is switched off.'];
        }

        $budget  = $budget ?? max(1, (int) Settings::get('triage_batch'));
        $pending = Suggestion::pending($budget);

        $done   = 0;
        $failed = 0;

        foreach ($pending as $row) {
            if (self::suggest((int) $row['id'])) {
                $done++;
            } else {
                $failed++;
            }
        }

        return [
            'done'      => $done,
            'failed'    => $failed,
            'remaining' => Suggestion::countPending(),
            'message'   => $pending === []
                ? 'Nothing queued for triage.'
                : sprintf('Triaged %d ticket(s), %d failed.', $done, $failed),
        ];
    }

    /** @return array<string,string> */
    public static function cronInfo(string $name): array
    {
        return ['description' => __('Suggest triage for newly created tickets', 'glpiai')];
    }

    public static function cronTriage(CronTask $task): int
    {
        $result = self::run();

        $task->addVolume($result['done']);
        $task->log($result['message']);

        return $result['done'] > 0 ? 1 : 0;
    }
}
