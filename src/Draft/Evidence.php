<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Draft;

use CommonITILObject;
use DBmysql;
use Dropdown;
use Glpi\RichText\RichText;
use ITILFollowup;
use Plugin;
use Ticket;
use TicketTask;
use Toolbox;

/**
 * What actually happened on a ticket.
 *
 * This class is the feature. Drafting a solution from a ticket's title and
 * description is a thing any chat window can do and it produces exactly what
 * you would expect: a fluent paragraph restating the problem and inventing a
 * fix. What makes a draft worth reading is the evidence underneath it — the
 * followups, the tasks, the procedure somebody worked through and what they
 * answered at each step, and how the last few tickets like this one were
 * actually resolved.
 *
 * Three of those come from GLPI itself. The fourth is glpi-sop's, and is read
 * directly because its answers are structured rather than prose. A fifth source
 * the roadmap named — glpi-osquery's before-and-after machine state — needs no
 * code here at all: that plugin already writes its findings into the timeline
 * as a followup, so reading the timeline collects it. A plugin that is not
 * installed simply contributes nothing.
 */
final class Evidence
{
    /** Per entry. Long enough for a real diagnosis, short enough that ten fit. */
    private const MAX_ENTRY = 1500;

    /** The whole evidence block. Beyond this a prompt stops being about one ticket. */
    private const MAX_TOTAL = 12000;

    /**
     * Everything known about how this ticket went.
     *
     * @return array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>,
     *               procedures:array<int,array<string,mixed>>}
     */
    public static function forTicket(Ticket $ticket): array
    {
        return [
            'ticket'     => self::describeTicket($ticket),
            'timeline'   => self::timeline($ticket),
            'procedures' => self::procedures($ticket),
        ];
    }

    /** @return array<string,mixed> */
    private static function describeTicket(Ticket $ticket): array
    {
        $category = (int) ($ticket->fields['itilcategories_id'] ?? 0);

        return [
            'id'       => (int) $ticket->getID(),
            'title'    => (string) $ticket->fields['name'],
            'category' => $category > 0
                ? Dropdown::getDropdownName('glpi_itilcategories', $category)
                : '',
            'reported' => self::plain((string) ($ticket->fields['content'] ?? '')),
        ];
    }

    /**
     * Followups and tasks, in the order they happened.
     *
     * Private entries are included and marked. They are usually where the
     * actual diagnosis is — a technician writes "it was the proxy config" to
     * colleagues, not to the requester — so excluding them would drop the most
     * useful evidence on the ticket. Marking them is what lets the article
     * prompt be told not to repeat them; see Drafter.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function timeline(Ticket $ticket): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tickets_id = (int) $ticket->getID();
        $entries    = [];

        foreach (
            $DB->request([
                'FROM'  => ITILFollowup::getTable(),
                'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
                'ORDER' => 'date ASC',
            ]) as $row
        ) {
            $entries[] = [
                'kind'    => 'note',
                'when'    => (string) $row['date'],
                'private' => (int) $row['is_private'] === 1,
                'who'     => self::who((int) $row['users_id']),
                'text'    => self::plain((string) $row['content']),
            ];
        }

        foreach (
            $DB->request([
                'FROM'  => TicketTask::getTable(),
                'WHERE' => ['tickets_id' => $tickets_id],
                'ORDER' => 'date ASC',
            ]) as $row
        ) {
            $entries[] = [
                'kind'    => 'task',
                'when'    => (string) $row['date'],
                'private' => (int) $row['is_private'] === 1,
                'who'     => self::who((int) $row['users_id']),
                'text'    => self::plain((string) $row['content']),
            ];
        }

        usort($entries, static fn(array $a, array $b): int => strcmp($a['when'], $b['when']));

        return array_values(array_filter($entries, static fn(array $e): bool => $e['text'] !== ''));
    }

    /**
     * glpi-sop's runs on this ticket, step by step, with the answers.
     *
     * The most valuable evidence there is, and the only kind that is not prose:
     * a procedure records what was *checked*, including the checks that came
     * back clean. "The disk was not full and the service was running" is
     * something a draft should be able to say, and nothing else on a ticket
     * records it — a technician who finds nothing wrong rarely writes a
     * followup saying so.
     *
     * Every reference is a string behind a guard. Without glpi-sop this returns
     * an empty array and the rest of the feature is unaffected.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function procedures(Ticket $ticket): array
    {
        if (
            !Plugin::isPluginActive('glpisop')
            || !class_exists('GlpiPlugin\\Glpisop\\Run')
            || !class_exists('GlpiPlugin\\Glpisop\\Step')
            || !class_exists('GlpiPlugin\\Glpisop\\Answer')
        ) {
            return [];
        }

        $out = [];

        foreach (
            (array) call_user_func(
                ['GlpiPlugin\\Glpisop\\Run', 'forItem'],
                Ticket::class,
                (int) $ticket->getID(),
                false
            ) as $run
        ) {
            $steps = (array) call_user_func(
                ['GlpiPlugin\\Glpisop\\Step', 'allFor'],
                (int) $run['plugin_glpisop_sops_id'],
                true
            );

            $ids     = array_map(static fn(array $s): int => (int) $s['id'], $steps);
            $answers = (array) call_user_func(
                ['GlpiPlugin\\Glpisop\\Answer', 'forRun'],
                (int) $run['id'],
                $ids
            );

            $done = [];
            foreach ($steps as $step) {
                $answer = $answers[(int) $step['id']] ?? [];
                $state  = (string) ($answer['state'] ?? 'pending');

                // An untouched step is not evidence of anything. Saying "step 4
                // was pending" in a solution would be describing the tool
                // rather than the fault.
                if ($state === 'pending') {
                    continue;
                }

                $done[] = [
                    'step'  => (string) $step['label'],
                    'state' => $state,
                    'value' => trim((string) ($answer['value'] ?? '')),
                    'note'  => self::plain((string) ($answer['note'] ?? '')),
                ];
            }

            if ($done !== []) {
                $out[] = [
                    'name'  => (string) ($run['sop']['name'] ?? ''),
                    'steps' => $done,
                ];
            }
        }

        return $out;
    }

    private static function solutionOf(int $tickets_id): string
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => 'glpi_itilsolutions',
                'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
                'ORDER' => 'id DESC',
                'LIMIT' => 1,
            ]) as $row
        ) {
            return self::plain((string) $row['content']);
        }

        return '';
    }

    /** The solution already on this ticket, if a technician wrote one. */
    public static function existingSolution(Ticket $ticket): string
    {
        return self::solutionOf((int) $ticket->getID());
    }

    private static function who(int $users_id): string
    {
        return $users_id > 0 ? (string) getUserName($users_id) : '';
    }

    /** HTML in, plain text out, with the line breaks that carry meaning kept. */
    private static function plain(string $html): string
    {
        $text = RichText::getTextFromHtml($html, false, true, false, true, true);

        return Toolbox::substr(trim($text), 0, self::MAX_ENTRY);
    }

    /**
     * Is there enough here to draft from?
     *
     * A ticket with a title and nothing else produces a confident invention,
     * which is worse than no draft: a technician who reads a plausible
     * paragraph is more likely to post it than one who is told there is nothing
     * to go on.
     */
    public static function isThin(array $evidence): bool
    {
        return $evidence['timeline'] === []
            && $evidence['procedures'] === [];
    }

    /**
     * The evidence as prompt text.
     *
     * Ordered oldest to newest and labelled by source, because the model has to
     * be able to tell "the requester said" from "a technician checked" —
     * collapsing those into one narrative is how a draft ends up asserting that
     * something was done when it was only suggested.
     */
    public static function render(array $evidence, bool $mark_private = true): string
    {
        $ticket = $evidence['ticket'];
        $lines  = [
            'TICKET #' . $ticket['id'] . ': ' . $ticket['title'],
        ];

        if ($ticket['category'] !== '') {
            $lines[] = 'Category: ' . $ticket['category'];
        }

        $lines[] = '';
        $lines[] = 'What was reported:';
        $lines[] = $ticket['reported'];

        if ($evidence['timeline'] !== []) {
            $lines[] = '';
            $lines[] = 'What happened next, oldest first:';
            foreach ($evidence['timeline'] as $entry) {
                $tag = $entry['kind'] === 'task' ? 'TASK' : 'NOTE';
                if ($mark_private && $entry['private']) {
                    $tag .= ', INTERNAL';
                }
                $lines[] = sprintf(
                    '  [%s%s] %s',
                    $tag,
                    $entry['who'] !== '' ? ' by ' . $entry['who'] : '',
                    $entry['text']
                );
            }
        }

        if ($evidence['procedures'] !== []) {
            $lines[] = '';
            $lines[] = 'Procedures worked through, and what each check found:';
            foreach ($evidence['procedures'] as $procedure) {
                $lines[] = '  ' . $procedure['name'] . ':';
                foreach ($procedure['steps'] as $step) {
                    $lines[] = sprintf(
                        '    - %s: %s%s%s',
                        $step['step'],
                        $step['state'],
                        $step['value'] !== '' ? ' = ' . $step['value'] : '',
                        $step['note'] !== '' ? ' (' . $step['note'] . ')' : ''
                    );
                }
            }
        }

        return Toolbox::substr(implode("\n", $lines), 0, self::MAX_TOTAL);
    }

    /** A short human-readable note of what the draft was built from. */
    public static function summarise(array $evidence): string
    {
        $bits = [];

        $notes = count(array_filter(
            $evidence['timeline'],
            static fn(array $e): bool => $e['kind'] === 'note'
        ));
        $tasks = count($evidence['timeline']) - $notes;

        if ($notes > 0) {
            $bits[] = sprintf(_n('%d followup', '%d followups', $notes, 'glpiai'), $notes);
        }
        if ($tasks > 0) {
            $bits[] = sprintf(_n('%d task', '%d tasks', $tasks, 'glpiai'), $tasks);
        }

        $steps = 0;
        foreach ($evidence['procedures'] as $procedure) {
            $steps += count($procedure['steps']);
        }
        if ($steps > 0) {
            $bits[] = sprintf(
                _n('%d procedure step', '%d procedure steps', $steps, 'glpiai'),
                $steps
            );
        }

        return $bits === []
            ? __('the ticket description only', 'glpiai')
            : implode(', ', $bits);
    }
}
