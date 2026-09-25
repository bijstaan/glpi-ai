<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Group_Ticket;
use ITILFollowup;
use ITILSolution;
use Item_Ticket;
use Ticket;
use Ticket_Ticket;
use Ticket_User;
use TicketTask;

/**
 * Read one ticket, including what was said and done on it.
 *
 * The timeline is the point. A ticket's title and description say what somebody
 * thought was wrong; the followups and the solution say what it actually was.
 * For the roadmap's drafting feature that difference is the entire signal —
 * a resolution written from the description alone is a restatement of the
 * problem.
 */
final class ReadTicket
{
    public static function tool(): Tool
    {
        return new Tool(
            name: 'read_ticket',
            description: 'Read a single ticket in full: description, status, category, who '
                . 'raised it and who it is assigned to, when it is due and whether that has been '
                . 'missed, the assets it names, the tickets it is linked to, and its timeline of '
                . 'followups, tasks and solution. Use this after search_tickets to find out what '
                . 'was actually done about a similar problem, or on the ticket in front of you '
                . 'before answering anything about who is dealing with it.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'id'       => [
                        'type'        => 'integer',
                        'description' => 'The ticket id.',
                    ],
                    'timeline' => [
                        'type'        => 'boolean',
                        'description' => 'Include followups, tasks and the solution. Defaults to true.',
                    ],
                ],
                'required'   => ['id'],
            ],
            handler: [self::class, 'run'],
            right: 'ticket'
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $id     = (int) ($arguments['id'] ?? 0);
        $ticket = new Ticket();

        // The same message for "does not exist" and "you may not see it". A
        // different answer for each would turn this tool into a way of probing
        // which ticket ids are real in entities the user has no access to.
        if ($id <= 0 || !$ticket->getFromDB($id) || !$ticket->canViewItem()) {
            throw new ToolException("No ticket $id is visible to you.");
        }

        $out = [
            'id'          => $id,
            'title'       => (string) $ticket->fields['name'],
            'status'      => Ticket::getStatus((int) $ticket->fields['status']),
            'urgency'     => (int) $ticket->fields['urgency'],
            'impact'      => (int) $ticket->fields['impact'],
            'priority'    => (int) $ticket->fields['priority'],
            'category'    => self::dropdownName('ITILCategory', (int) $ticket->fields['itilcategories_id']),
            'type'        => (int) $ticket->fields['type'] === Ticket::INCIDENT_TYPE ? 'incident' : 'request',
            'opened'      => (string) $ticket->fields['date'],
            'location'    => self::dropdownName('Location', (int) ($ticket->fields['locations_id'] ?? 0)),
            'entity'      => Lookup::entityName((int) $ticket->fields['entities_id']),
            // Who is on it, which the first version of this tool left out
            // entirely — so a model reading a ticket could not answer "who is
            // dealing with this", the most common question asked about one.
            'requesters'  => self::actors($id, \CommonITILActor::REQUESTER),
            'assigned_to' => self::actors($id, \CommonITILActor::ASSIGN),
            'watchers'    => self::actors($id, \CommonITILActor::OBSERVER),
            'due'         => self::due($ticket),
            // The machine the ticket is about. A ticket that names one is the
            // difference between guessing at a model and reading its record.
            'items'       => self::items($id),
            'linked'      => self::linked($id),
            'description' => Lookup::plain($ticket->fields['content'] ?? ''),
        ];

        $out = array_filter($out, static fn($v): bool => $v !== null && $v !== '' && $v !== []);

        if (($arguments['timeline'] ?? true) === false) {
            return $out;
        }

        $out['timeline'] = self::timeline($id);
        $out['solution'] = self::solution($id);

        return array_filter($out, static fn($v): bool => $v !== null && $v !== '');
    }

    /**
     * Requesters, assignees or observers, people and groups together.
     *
     * Both, because either alone is misleading in the same way: a ticket
     * assigned to "Network" with nobody named reads as unassigned, and one
     * assigned to a person whose group is what the escalation path names reads
     * as having no team.
     *
     * @return string[]
     */
    private static function actors(int $tickets_id, int $type): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Ticket_User::getTable(), [
                'tickets_id' => $tickets_id,
                'type'       => $type,
            ]) as $row
        ) {
            $users_id = (int) $row['users_id'];

            if ($users_id > 0) {
                $name = \getUserName($users_id);
                $out[] = is_string($name) && trim($name) !== '' ? trim($name) : "user $users_id";
                continue;
            }

            // An anonymous requester: an email address and no account, which is
            // what a ticket raised by the mail collector from an unknown sender
            // looks like. Worth saying rather than dropping the row.
            $email = trim((string) ($row['alternative_email'] ?? ''));
            if ($email !== '') {
                $out[] = $email;
            }
        }

        foreach (
            getAllDataFromTable(Group_Ticket::getTable(), [
                'tickets_id' => $tickets_id,
                'type'       => $type,
            ]) as $row
        ) {
            $name = \Dropdown::getDropdownName('glpi_groups', (int) $row['groups_id']);
            if (is_string($name) && $name !== '' && $name !== '&nbsp;') {
                $out[] = $name . ' (group)';
            }
        }

        return $out;
    }

    /**
     * When it has to be resolved by, and whether that has already gone.
     *
     * The breach is stated rather than left to date arithmetic. A model working
     * out for itself whether a timestamp is in the past will occasionally tell
     * a technician there is time left on a ticket that went red yesterday.
     *
     * @return array<string,mixed>
     */
    private static function due(Ticket $ticket): array
    {
        $ttr = (string) ($ticket->fields['time_to_resolve'] ?? '');
        if ($ttr === '') {
            return [];
        }

        $solved = (string) ($ticket->fields['solvedate'] ?? '');

        return array_filter([
            'resolve_by' => $ttr,
            // Against the solve time where there is one, and against now where
            // there is not: a ticket solved late is still late, and comparing a
            // closed ticket's target with today would quietly forgive it.
            'breached'   => ($solved !== '' ? $solved : date('Y-m-d H:i:s')) > $ttr,
            'sla'        => self::dropdownName('SLA', (int) ($ticket->fields['slas_id_ttr'] ?? 0)),
            'own_by'     => (string) ($ticket->fields['time_to_own'] ?? ''),
        ], static fn($v): bool => $v !== null && $v !== '');
    }

    /** @return array<int,array<string,mixed>> */
    private static function items(int $tickets_id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Item_Ticket::getTable(), ['tickets_id' => $tickets_id]) as $row
        ) {
            $itemtype = (string) $row['itemtype'];
            $item     = getItemForItemtype($itemtype);

            if ($item === false || !$item->getFromDB((int) $row['items_id']) || !$item->canViewItem()) {
                continue;
            }

            $out[] = array_filter([
                'itemtype' => $itemtype,
                'id'       => (int) $row['items_id'],
                'name'     => (string) ($item->fields['name'] ?? ''),
                'serial'   => (string) ($item->fields['serial'] ?? ''),
            ], static fn($v): bool => $v !== '');
        }

        return $out;
    }

    /**
     * Tickets linked to this one, and how.
     *
     * "Duplicate of #4412" is often the whole answer, and without this the
     * model has no way to reach it — the link is not in the timeline, not in
     * the description, and not in any search result.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function linked(int $tickets_id): array
    {
        $labels = [
            Ticket_Ticket::LINK_TO        => 'related',
            Ticket_Ticket::DUPLICATE_WITH => 'duplicate',
            Ticket_Ticket::SON_OF         => 'child of',
            Ticket_Ticket::PARENT_OF      => 'parent of',
        ];

        $out = [];

        foreach (
            getAllDataFromTable(Ticket_Ticket::getTable(), [
                'OR' => [
                    ['tickets_id_1' => $tickets_id],
                    ['tickets_id_2' => $tickets_id],
                ],
            ]) as $row
        ) {
            $first = (int) $row['tickets_id_1'] === $tickets_id;
            $other = $first ? (int) $row['tickets_id_2'] : (int) $row['tickets_id_1'];
            $link  = (int) $row['link'];

            // SON_OF and PARENT_OF are the same row read from either end, so
            // reporting the stored label on both would tell the child it is the
            // parent.
            if (!$first && $link === Ticket_Ticket::SON_OF) {
                $link = Ticket_Ticket::PARENT_OF;
            } elseif (!$first && $link === Ticket_Ticket::PARENT_OF) {
                $link = Ticket_Ticket::SON_OF;
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB($other) || !$ticket->canViewItem()) {
                continue;
            }

            $out[] = [
                'id'     => $other,
                'how'    => $labels[$link] ?? 'related',
                'title'  => (string) $ticket->fields['name'],
                'status' => Ticket::getStatus((int) $ticket->fields['status']),
            ];
        }

        return $out;
    }

    /**
     * Followups and tasks, oldest first.
     *
     * Private entries are included when the reader may see them, and excluded
     * otherwise, by asking GLPI rather than by filtering on the flag: whether a
     * private note is visible depends on the profile and on whether the reader
     * is assigned, which is not something to reimplement here.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function timeline(int $id): array
    {
        $entries = [];

        foreach (
            [
                ['class' => ITILFollowup::class, 'kind' => 'followup'],
                ['class' => TicketTask::class, 'kind' => 'task'],
            ] as $source
        ) {
            /** @var \CommonDBTM $item */
            $item = new $source['class']();

            $criteria = $source['class'] === ITILFollowup::class
                ? ['itemtype' => Ticket::class, 'items_id' => $id]
                : ['tickets_id' => $id];

            foreach (getAllDataFromTable($item->getTable(), $criteria) as $row) {
                $item->fields = $row;
                if (method_exists($item, 'canViewItem') && !$item->canViewItem()) {
                    continue;
                }

                $entries[] = [
                    'kind'    => $source['kind'],
                    'date'    => (string) ($row['date'] ?? $row['date_creation'] ?? ''),
                    'private' => (bool) ($row['is_private'] ?? false),
                    'text'    => Lookup::plain((string) ($row['content'] ?? ''), 2000),
                ];
            }
        }

        usort($entries, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $entries;
    }

    private static function solution(int $id): ?string
    {
        $rows = getAllDataFromTable(
            (new ITILSolution())->getTable(),
            ['itemtype' => Ticket::class, 'items_id' => $id, 'ORDER' => 'date_creation DESC']
        );

        foreach ($rows as $row) {
            $text = Lookup::plain((string) ($row['content'] ?? ''), 3000);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private static function dropdownName(string $itemtype, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $name = \Dropdown::getDropdownName($itemtype::getTable(), $id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : null;
    }
}
