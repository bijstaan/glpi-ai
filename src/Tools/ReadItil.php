<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Change;
use CommonITILObject;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use ITILFollowup;
use ITILSolution;
use Problem;

/**
 * Read one problem or change in full.
 *
 * `search_itil` finds them and stops at the title, which is the half of the
 * record that repeats what the tickets already said. Everything that makes a
 * problem worth having — the symptom, the cause somebody worked out, the
 * impact statement — and everything that makes a change reviewable — the
 * rollout plan, the backout plan, the checklist — lives in fields no other
 * tool here has ever returned. A model asked "what is the backout plan for
 * tonight's change" could find the change and not read it.
 *
 * Deliberately one tool over two itemtypes rather than `read_problem` and
 * `read_change`. The shape is identical, the difference is four field names,
 * and two nearly-identical descriptions in the prompt is exactly the
 * near-duplicate competition that makes a model choose worse.
 *
 * Tickets are not accepted here: {@see ReadTicket} does that job better,
 * knowing about SLA breach and ticket links, and a second door onto the same
 * object would split the model's choice for nothing.
 */
final class ReadItil
{
    /**
     * The prose fields that differ per type, in the order somebody reads them.
     *
     * @var array<class-string<CommonITILObject>,array<string,string>>
     */
    private const NARRATIVE = [
        Problem::class => [
            'symptomcontent' => 'symptom',
            'causecontent'   => 'cause',
            'impactcontent'  => 'impact',
        ],
        Change::class => [
            'impactcontent'      => 'impact',
            'rolloutplancontent' => 'rollout_plan',
            'backoutplancontent' => 'backout_plan',
            'checklistcontent'   => 'checklist',
            'controlistcontent'  => 'control_list',
        ],
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'read_itil',
            description: 'Read one problem or change in full — not a ticket. For a problem: the '
                . 'symptom, the root cause somebody worked out, the impact, and its tasks and '
                . 'solution. For a change: the impact, the rollout plan, the backout plan and '
                . 'the checklist, with its planned window and the tickets it is meant to fix. '
                . 'Use it after search_itil, before saying what a change will do or what a '
                . 'problem was found to be caused by — the titles of both are usually just the '
                . 'symptom restated.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => ['Problem', 'Change'],
                        'description' => 'Which of the two to read.',
                    ],
                    'id'       => ['type' => 'integer', 'description' => 'Its id.'],
                    'timeline' => [
                        'type'        => 'boolean',
                        'description' => 'Include followups, tasks and the solution. Defaults to true.',
                    ],
                ],
                'required'   => ['itemtype', 'id'],
            ],
            handler: [self::class, 'run'],
            // The narrower of the two rights the tool can reach. Asking for
            // `problem` here and letting the item check sort out changes would
            // hand every change to anyone holding the problem right, because
            // `canViewItem()` on a change asks about the change right and this
            // one never would.
            right: null,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $itemtype = self::itemtype((string) ($arguments['itemtype'] ?? ''));
        $id       = (int) ($arguments['id'] ?? 0);

        /** @var CommonITILObject $item */
        $item = new $itemtype();

        // The profile right first, then the item. The right says "may see
        // changes at all" and is the one a READ-less profile fails; `can()`
        // says "may see this one", which is where the entity restriction is.
        if (
            !\Session::haveRight($itemtype::$rightname, READ)
            || $id <= 0
            || !$item->getFromDB($id)
            || !$item->canViewItem()
        ) {
            throw new ToolException(sprintf('No %s %d is visible to you.', $itemtype, $id));
        }

        $out = [
            'itemtype' => $itemtype,
            'id'       => $id,
            'title'    => (string) $item->fields['name'],
            'status'   => $itemtype::getStatus((int) $item->fields['status']),
            'urgency'  => (int) $item->fields['urgency'],
            'impact_rating' => (int) $item->fields['impact'],
            'priority' => (int) $item->fields['priority'],
            'category' => self::dropdownName('ITILCategory', (int) ($item->fields['itilcategories_id'] ?? 0)),
            'entity'   => Lookup::entityName((int) $item->fields['entities_id']),
            'opened'   => (string) $item->fields['date'],
            'solved'   => (string) ($item->fields['solvedate'] ?? ''),
            'requesters'  => self::actors($itemtype, $id, \CommonITILActor::REQUESTER),
            'assigned_to' => self::actors($itemtype, $id, \CommonITILActor::ASSIGN),
            'description' => Lookup::plain($item->fields['content'] ?? ''),
        ];

        foreach (self::NARRATIVE[$itemtype] as $field => $label) {
            $out[$label] = Lookup::plain((string) ($item->fields[$field] ?? ''), 2500);
        }

        if ($itemtype === Change::class) {
            // Approval is the fact that decides whether a change may run
            // tonight, and it is a single field nobody would think to ask for.
            $out['approval'] = \CommonITILValidation::getStatus(
                (int) ($item->fields['global_validation'] ?? 0)
            );
            $out['planned'] = self::window($id);
        }

        $out['items']  = self::items($itemtype, $id);
        $out['linked'] = self::linked($itemtype, $id);

        $out = array_filter($out, static fn($v): bool => $v !== null && $v !== '' && $v !== []);

        if (($arguments['timeline'] ?? true) === false) {
            return $out;
        }

        $out['timeline'] = self::timeline($itemtype, $id);
        $out['solution'] = self::solution($itemtype, $id);

        return array_filter($out, static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /** @return class-string<CommonITILObject> */
    private static function itemtype(string $given): string
    {
        return match (strtolower(trim($given))) {
            'problem' => Problem::class,
            'change'  => Change::class,
            default   => throw new ToolException(
                'itemtype must be Problem or Change. For a ticket use read_ticket.'
            ),
        };
    }

    /**
     * Who is on it, people and groups together.
     *
     * @return string[]
     */
    private static function actors(string $itemtype, int $items_id, int $type): array
    {
        $fk  = getForeignKeyFieldForItemType($itemtype);
        $out = [];

        foreach (
            getAllDataFromTable(
                $itemtype === Problem::class ? 'glpi_problems_users' : 'glpi_changes_users',
                [$fk => $items_id, 'type' => $type]
            ) as $row
        ) {
            $users_id = (int) $row['users_id'];
            if ($users_id > 0) {
                $name  = \getUserName($users_id);
                $out[] = is_string($name) && trim($name) !== '' ? trim($name) : "user $users_id";
                continue;
            }

            $email = trim((string) ($row['alternative_email'] ?? ''));
            if ($email !== '') {
                $out[] = $email;
            }
        }

        foreach (
            getAllDataFromTable(
                $itemtype === Problem::class ? 'glpi_groups_problems' : 'glpi_changes_groups',
                [$fk => $items_id, 'type' => $type]
            ) as $row
        ) {
            $name = \Dropdown::getDropdownName('glpi_groups', (int) $row['groups_id']);
            if (is_string($name) && $name !== '' && $name !== '&nbsp;') {
                $out[] = $name . ' (group)';
            }
        }

        return $out;
    }

    /**
     * The planned window, from the change's own tasks.
     *
     * Core keeps no begin/end on a change — the window is whatever its tasks
     * are planned for, which is why "when is this change running" is a
     * question the change form cannot answer at a glance and this can.
     *
     * @return array<string,string>
     */
    private static function window(int $changes_id): array
    {
        $begins = null;
        $ends   = null;

        foreach (getAllDataFromTable('glpi_changetasks', ['changes_id' => $changes_id]) as $row) {
            $start = (string) ($row['begin'] ?? '');
            $end   = (string) ($row['end'] ?? '');

            if ($start !== '' && !str_starts_with($start, '0000')) {
                $begins = $begins === null || $start < $begins ? $start : $begins;
            }
            if ($end !== '' && !str_starts_with($end, '0000')) {
                $ends = $ends === null || $end > $ends ? $end : $ends;
            }
        }

        return array_filter(['from' => $begins ?? '', 'to' => $ends ?? '']);
    }

    /** @return array<int,array<string,mixed>> */
    private static function items(string $itemtype, int $items_id): array
    {
        $table = $itemtype === Problem::class ? 'glpi_items_problems' : 'glpi_changes_items';
        $fk    = getForeignKeyFieldForItemType($itemtype);
        $out   = [];

        foreach (getAllDataFromTable($table, [$fk => $items_id]) as $row) {
            $type = (string) $row['itemtype'];
            $item = getItemForItemtype($type);

            if ($item === false || !$item->getFromDB((int) $row['items_id']) || !$item->canViewItem()) {
                continue;
            }

            $out[] = array_filter([
                'itemtype' => $type,
                'id'       => (int) $row['items_id'],
                'name'     => (string) ($item->fields['name'] ?? ''),
                'serial'   => (string) ($item->fields['serial'] ?? ''),
            ], static fn($v): bool => $v !== '');
        }

        return $out;
    }

    /**
     * The tickets, problems and changes on the other end.
     *
     * The most useful line in a change record is often "this is what fixes
     * those nine tickets", and it is stored nowhere a search can reach.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function linked(string $itemtype, int $items_id): array
    {
        $fk    = getForeignKeyFieldForItemType($itemtype);
        $links = $itemtype === Problem::class
            ? [
                'tickets'  => ['glpi_problems_tickets', 'tickets_id', \Ticket::class],
                'changes'  => ['glpi_changes_problems', 'changes_id', Change::class],
            ]
            : [
                'tickets'  => ['glpi_changes_tickets', 'tickets_id', \Ticket::class],
                'problems' => ['glpi_changes_problems', 'problems_id', Problem::class],
            ];

        $out = [];

        foreach ($links as $label => [$table, $column, $class]) {
            $rows = [];

            foreach (getAllDataFromTable($table, [$fk => $items_id]) as $row) {
                /** @var CommonITILObject $other */
                $other = new $class();
                if (!$other->getFromDB((int) $row[$column]) || !$other->canViewItem()) {
                    continue;
                }

                $rows[] = [
                    'id'     => (int) $row[$column],
                    'title'  => (string) $other->fields['name'],
                    'status' => $class::getStatus((int) $other->fields['status']),
                ];
            }

            if ($rows !== []) {
                $out[$label] = $rows;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function timeline(string $itemtype, int $id): array
    {
        $entries = [];
        $tasks   = $itemtype === Problem::class ? 'glpi_problemtasks' : 'glpi_changetasks';
        $fk      = getForeignKeyFieldForItemType($itemtype);

        foreach (
            getAllDataFromTable(
                (new ITILFollowup())->getTable(),
                ['itemtype' => $itemtype, 'items_id' => $id]
            ) as $row
        ) {
            $followup         = new ITILFollowup();
            $followup->fields = $row;
            if (!$followup->canViewItem()) {
                continue;
            }

            $entries[] = [
                'kind'    => 'followup',
                'date'    => (string) ($row['date'] ?? $row['date_creation'] ?? ''),
                'private' => (bool) ($row['is_private'] ?? false),
                'text'    => Lookup::plain((string) ($row['content'] ?? ''), 2000),
            ];
        }

        foreach (getAllDataFromTable($tasks, [$fk => $id]) as $row) {
            $entries[] = array_filter([
                'kind'    => 'task',
                'date'    => (string) ($row['date'] ?? $row['date_creation'] ?? ''),
                'private' => (bool) ($row['is_private'] ?? false),
                'planned' => (string) ($row['begin'] ?? ''),
                'text'    => Lookup::plain((string) ($row['content'] ?? ''), 2000),
            ], static fn($v): bool => $v !== '' && !(is_string($v) && str_starts_with($v, '0000')));
        }

        usort($entries, static fn(array $a, array $b): int
            => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $entries;
    }

    private static function solution(string $itemtype, int $id): ?string
    {
        $rows = getAllDataFromTable(
            (new ITILSolution())->getTable(),
            ['itemtype' => $itemtype, 'items_id' => $id],
            false,
            'date_creation DESC'
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
