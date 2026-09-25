<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Group;
use Group_User;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Planning;
use Ticket;
use User;

/**
 * The two questions about people that `read_user` cannot answer.
 *
 * `read_user` reads one person's record. What a dispatcher actually asks is
 * about *a set of them* — "who is in second line", "who has a free afternoon
 * on Thursday" — and both answers exist in GLPI and were unreachable.
 *
 *  - **`read_group`** turns a group name on a ticket into people. A ticket
 *    assigned to "Network" reads as unassigned to a model that cannot expand
 *    the group, and telling a technician "nobody is on it" when four people
 *    are is worse than saying nothing.
 *  - **`check_availability`** is the planning, per person or per group: what
 *    is already booked in a window, and how much of it. It is the one thing
 *    that has to be true before an assistant proposes a time to anybody.
 *
 * Neither of them books anything, and that is the line rather than an
 * omission. Planning work for somebody puts an appointment in their day under
 * their name, which is the same class of act as assigning a ticket — the one
 * thing the write tools deliberately do not do.
 *
 * Reading another person's diary is gated the way GLPI gates it: the
 * `planning` right, with READALL for anyone but yourself. A profile that may
 * only see its own planning gets its own, and is told why rather than handed
 * an empty week that reads as "they are free".
 */
final class Team
{
    /** Events before the answer stops being readable. */
    private const LIMIT = 40;

    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::group(), self::availability()];
    }

    // ---------------------------------------------------------------- group

    private static function group(): Tool
    {
        return new Tool(
            name: 'read_group',
            // "team" is in the description on purpose. `find_tools` matches
            // words with no stemming and no synonyms, and nobody asks "who is
            // in the network group" — they ask about the team, and every
            // network_* tool outranked this one on the other word.
            description: 'Who is in a group or team, and what it is for: its members and which '
                . 'of them manage it, whether it can be assigned tickets, and how many tickets '
                . 'are sitting with it right now. Use it whenever a ticket is assigned to a '
                . 'group rather than a person, when asked who is in a team or who covers '
                . 'something, and before saying that nobody is dealing with a ticket — a group '
                . 'assignment is not nobody.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'groups_id' => ['type' => 'integer', 'description' => 'The group id.'],
                    'name'      => [
                        'type'        => 'string',
                        'description' => 'Part of the group name, if you do not have its id.',
                    ],
                ],
            ],
            handler: [self::class, 'runGroup'],
            right: 'group',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runGroup(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $group = self::findGroup($arguments, $context);
        $id    = (int) $group->fields['id'];

        $members  = [];
        $managers = [];

        foreach (
            getAllDataFromTable(Group_User::getTable(), ['groups_id' => $id]) as $row
        ) {
            $user = new User();
            if (!$user->getFromDB((int) $row['users_id'])) {
                continue;
            }

            // A disabled account still in a group is the usual reason a queue
            // looks staffed and is not.
            if ((int) ($user->fields['is_active'] ?? 1) !== 1
                || (int) ($user->fields['is_deleted'] ?? 0) === 1) {
                continue;
            }

            $name = (string) $user->getFriendlyName();

            if ((int) ($row['is_manager'] ?? 0) === 1) {
                $managers[] = $name;
                continue;
            }

            $members[] = $name;
        }

        sort($members);
        sort($managers);

        $used_for = array_keys(array_filter([
            'assignment'   => (int) ($group->fields['is_assign'] ?? 0) === 1,
            'requests'     => (int) ($group->fields['is_requester'] ?? 0) === 1,
            'watching'     => (int) ($group->fields['is_watcher'] ?? 0) === 1,
            'tasks'        => (int) ($group->fields['is_task'] ?? 0) === 1,
            'notifications' => (int) ($group->fields['is_notify'] ?? 0) === 1,
        ]));

        return array_filter([
            'id'        => $id,
            'name'      => (string) $group->fields['name'],
            'full_name' => (string) ($group->fields['completename'] ?? ''),
            'entity'    => Lookup::entityName((int) $group->fields['entities_id']),
            'comment'   => Lookup::plain((string) ($group->fields['comment'] ?? ''), 800),
            'managers'  => $managers,
            'members'   => $members,
            'used_for'  => $used_for,
            'open_tickets' => self::groupQueue($id),
            'also_matched' => (string) ($group->fields['_also_matched'] ?? '') ?: null,
            'children'  => self::childGroups($id),
            'note'      => $members === [] && $managers === []
                ? 'The group has no active members. Anything assigned to it is effectively '
                    . 'unassigned — say so.'
                : null,
        ], static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    private static function findGroup(array $arguments, ToolContext $context): Group
    {
        $group = new Group();
        $id    = (int) ($arguments['groups_id'] ?? 0);

        if ($id > 0) {
            if (!$group->getFromDB($id) || !$group->canViewItem()) {
                throw new ToolException("No group $id is visible to you.");
            }

            return $group;
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new ToolException('Name the group, by id or by part of its name.');
        }

        $matches = [];

        foreach (
            getAllDataFromTable(Group::getTable(), [
                'name' => ['LIKE', '%' . $name . '%'],
                'ORDER' => 'name',
            ]) as $row
        ) {
            $candidate         = new Group();
            $candidate->fields = $row;

            if ($candidate->canViewItem()) {
                $matches[] = $candidate;
            }
        }

        if ($matches === []) {
            throw new ToolException("No group matching \"$name\" is visible to you.");
        }

        // The first match, and the others named. Asking the model to choose
        // costs a turn; telling it what else matched costs a line and lets it
        // correct itself if the first one is obviously wrong.
        if (count($matches) > 1) {
            $names = array_map(
                static fn(Group $g): string => (string) $g->fields['name'],
                array_slice($matches, 0, 6)
            );

            $matches[0]->fields['_also_matched'] = implode(', ', $names);
        }

        return $matches[0];
    }

    /** How much is sitting with this group right now. */
    private static function groupQueue(int $groups_id): ?int
    {
        if (!\Session::haveRight('ticket', Ticket::READALL)) {
            return null;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $open = array_merge(Ticket::getClosedStatusArray(), Ticket::getSolvedStatusArray());

        return (int) countElementsInTable(
            Ticket::getTable(),
            [
                'is_deleted' => 0,
                ['NOT' => ['status' => $open]],
                ['glpi_tickets.id' => new \Glpi\DBAL\QuerySubQuery([
                    'SELECT' => 'tickets_id',
                    'FROM'   => 'glpi_groups_tickets',
                    'WHERE'  => [
                        'groups_id' => $groups_id,
                        'type'      => \CommonITILActor::ASSIGN,
                    ],
                ])],
            ],
            ['DISTINCT' => true]
        );
    }

    /** @return string[] */
    private static function childGroups(int $groups_id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Group::getTable(), ['groups_id' => $groups_id, 'ORDER' => 'name']) as $row
        ) {
            $child         = new Group();
            $child->fields = $row;

            if ($child->canViewItem()) {
                $out[] = (string) $row['name'];
            }
        }

        return $out;
    }

    // --------------------------------------------------------- availability

    private static function availability(): Tool
    {
        return new Tool(
            name: 'check_availability',
            description: 'What is already in somebody\'s diary: the planned tasks, project work '
                . 'and calendar events for a technician or a group over the next few days, with '
                . 'how many hours of each day are already booked. Use it before proposing a '
                . 'time to anybody, before promising an on-site visit, and when asked who is '
                . 'free. It does not book anything — say what is free and let a person plan it.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'user'      => [
                        'type'        => 'string',
                        'description' => 'Login or name of the technician. Omit with groups_id '
                            . 'to ask about a whole group; omit both for yourself.',
                    ],
                    'users_id'  => ['type' => 'integer', 'description' => 'Their GLPI user id.'],
                    'groups_id' => [
                        'type'        => 'integer',
                        'description' => 'Ask about every member of this group instead.',
                    ],
                    'from'      => [
                        'type'        => 'string',
                        'description' => 'First day to look at, YYYY-MM-DD. Defaults to today.',
                    ],
                    'days'      => [
                        'type'        => 'integer',
                        'description' => 'How many days from then. Defaults to 7, maximum 31.',
                    ],
                ],
            ],
            handler: [self::class, 'runAvailability'],
            right: 'planning',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runAvailability(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $days  = max(1, min(31, (int) ($arguments['days'] ?? 7)));
        $from  = self::day($arguments['from'] ?? null) ?? date('Y-m-d');
        $to    = date('Y-m-d', strtotime("$from +$days days"));

        $groups_id = (int) ($arguments['groups_id'] ?? 0);
        $users_id  = self::whose($arguments, $groups_id);

        if ($groups_id > 0) {
            $group = new Group();
            if (!$group->getFromDB($groups_id) || !$group->canViewItem()) {
                throw new ToolException("No group $groups_id is visible to you.");
            }
        }

        $mine = $users_id > 0 && $users_id === (int) \Session::getLoginUserID();

        if (!$mine && !\Session::haveRight('planning', Planning::READALL)) {
            if ($groups_id > 0 && \Session::haveRight('planning', Planning::READGROUP)) {
                // Allowed: group planning is exactly what READGROUP is for.
                $users_id = 0;
            } else {
                throw new ToolException(
                    'You may only see your own planning on this profile, so somebody else\'s '
                    . 'diary cannot be checked here. An empty answer would read as "they are '
                    . 'free", which is why this is a refusal rather than nothing.'
                );
            }
        }

        $events = self::events($users_id, $groups_id, $from . ' 00:00:00', $to . ' 00:00:00');

        $by_day = [];
        foreach ($events as $event) {
            $day = substr((string) $event['begin'], 0, 10);
            $by_day[$day] = ($by_day[$day] ?? 0)
                + max(0, strtotime((string) $event['end']) - strtotime((string) $event['begin']));
        }

        ksort($by_day);

        return array_filter([
            'who'    => $groups_id > 0 && $users_id <= 0
                ? ['group' => \Dropdown::getDropdownName('glpi_groups', $groups_id)]
                : ['user' => \getUserName($users_id)],
            'window' => ['from' => $from, 'to' => $to],
            'booked' => array_map(
                static fn(int $seconds): string => sprintf('%.1f hours', $seconds / 3600),
                $by_day
            ),
            'events' => array_slice($events, 0, self::LIMIT),
            'note'   => $events === []
                ? 'Nothing is planned in that window. That means nothing is in GLPI\'s planning '
                    . '— it is not evidence that the person is not busy elsewhere.'
                : (count($events) > self::LIMIT
                    ? sprintf('%d events in all; the first %d are listed.', count($events), self::LIMIT)
                    : null),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /** @param array<string,mixed> $arguments */
    private static function whose(array $arguments, int $groups_id): int
    {
        $users_id = (int) ($arguments['users_id'] ?? 0);
        if ($users_id > 0) {
            return $users_id;
        }

        $name = trim((string) ($arguments['user'] ?? ''));

        if ($name === '') {
            return $groups_id > 0 ? 0 : (int) \Session::getLoginUserID();
        }

        foreach (
            getAllDataFromTable(User::getTable(), [
                'is_deleted' => 0,
                'is_active'  => 1,
                'OR'         => [
                    ['name'      => ['LIKE', '%' . $name . '%']],
                    ['realname'  => ['LIKE', '%' . $name . '%']],
                    ['firstname' => ['LIKE', '%' . $name . '%']],
                ],
                'ORDER' => 'name',
            ]) as $row
        ) {
            return (int) $row['id'];
        }

        throw new ToolException("No active user matching \"$name\".");
    }

    /**
     * Everything planned, from every planning-capable itemtype.
     *
     * Asked of each itemtype's own `populatePlanning()` rather than assembled
     * from the task tables, which is what makes this inherit the same rights
     * and the same set of types as GLPI's own planning page — including the
     * ones plugins add. A type that throws costs its own rows and not the
     * answer.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function events(int $users_id, int $groups_id, string $begin, string $end): array
    {
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $out = [];

        foreach ((array) ($CFG_GLPI['planning_types'] ?? []) as $itemtype) {
            if (!is_string($itemtype) || !class_exists($itemtype)
                || !method_exists($itemtype, 'populatePlanning')) {
                continue;
            }

            try {
                $rows = $itemtype::populatePlanning([
                    'who'      => $users_id,
                    'whogroup' => $groups_id,
                    'begin'    => $begin,
                    'end'      => $end,
                    'color'    => '',
                    'event_type_color' => '',
                    'state_done' => true,
                ]);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ((array) $rows as $row) {
                if (!is_array($row) || empty($row['begin']) || empty($row['end'])) {
                    continue;
                }

                $out[] = array_filter([
                    'what'     => self::title($row),
                    'itemtype' => (string) ($row['itemtype'] ?? $itemtype),
                    'id'       => (int) ($row['id'] ?? 0) ?: null,
                    'begin'    => (string) $row['begin'],
                    'end'      => (string) $row['end'],
                    'for'      => isset($row['users_id']) && (int) $row['users_id'] > 0
                        ? \getUserName((int) $row['users_id'])
                        : null,
                ], static fn($v): bool => $v !== null && $v !== '');
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['begin'], $b['begin']));

        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function title(array $row): string
    {
        foreach (['name', 'ticket_name', 'content', 'text'] as $key) {
            $value = trim(Lookup::plain((string) ($row[$key] ?? ''), 200));
            if ($value !== '') {
                return $value;
            }
        }

        return 'planned work';
    }

    private static function day(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
