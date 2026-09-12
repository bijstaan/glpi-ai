<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Ticket;

/**
 * How many, over a period, broken down by something.
 *
 * The gap this fills is not a reporting one. `search_tickets` returns at most
 * twenty-five rows, and every question of the form "how many tickets did this
 * customer raise last month", "which category is eating the week", "who is
 * carrying the queue" was previously answered by a model counting the rows it
 * happened to be given — confidently, and wrong by however many rows the limit
 * cut off. A tool that returns a count is worth more here than one that
 * returns better rows.
 *
 * **Gated on READALL rather than READ**, which is the unusual part. Counting
 * is the one operation that cannot be narrowed to "the ones you may see"
 * without reimplementing GLPI's per-actor ticket visibility — the rules that
 * make a requester see their own tickets and a group member their group's —
 * and a count that quietly included tickets the caller could not open would be
 * a leak with no page to notice it on. So the tool exists for the profiles
 * that may already list every ticket in the entity, and is refused for the
 * rest.
 *
 * The entity restriction is the ordinary one and is applied twice over: the
 * conversation's entity and its children, intersected with the session's own
 * active entities. Descended with `getSonsOf()` rather than with
 * `getEntitiesRestrictCriteria()`'s recursive flag, which means "this table
 * has an is_recursive column" — tickets do not, and asking for it dies.
 */
final class Stats
{
    /** Rows in a breakdown. Long tails are summarised, not listed. */
    private const TOP = 10;

    /**
     * What a breakdown can be cut by: the column, and how to name a value.
     *
     * @var array<string,array{column:string,table:?string}>
     */
    private const DIMENSIONS = [
        'status'     => ['column' => 'status', 'table' => null],
        'category'   => ['column' => 'itilcategories_id', 'table' => 'glpi_itilcategories'],
        'priority'   => ['column' => 'priority', 'table' => null],
        'type'       => ['column' => 'type', 'table' => null],
        'entity'     => ['column' => 'entities_id', 'table' => 'glpi_entities'],
        'location'   => ['column' => 'locations_id', 'table' => 'glpi_locations'],
        'technician' => ['column' => 'users_id_assign', 'table' => 'glpi_users'],
        'requester'  => ['column' => 'users_id_requester', 'table' => 'glpi_users'],
        'group'      => ['column' => 'groups_id_assign', 'table' => 'glpi_groups'],
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'ticket_stats',
            description: 'Count tickets over a period and break the count down: by status, '
                . 'category, priority, type, location, entity, assigned technician, assigned '
                . 'group or requester. Returns how many were opened and solved in the window, '
                . 'how many are still open, how many missed their resolution target, and the '
                . 'median and mean time to resolve. Use this for any question with a number in '
                . 'the answer — "how many", "how often", "which category", "who is busiest", '
                . '"is it getting worse" — instead of counting search results, which are capped '
                . 'and will give a smaller number than the truth.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'days'        => [
                        'type'        => 'integer',
                        'description' => 'How far back to count, in days. Defaults to 30, '
                            . 'maximum 730. Ignored if from/to are given.',
                    ],
                    'from'        => [
                        'type'        => 'string',
                        'description' => 'Start of the window, YYYY-MM-DD.',
                    ],
                    'to'          => [
                        'type'        => 'string',
                        'description' => 'End of the window, YYYY-MM-DD. Defaults to today.',
                    ],
                    'group_by'    => [
                        'type'        => 'string',
                        'enum'        => ['status', 'category', 'priority', 'type', 'location',
                            'entity', 'technician', 'requester', 'group'],
                        'description' => 'What to break the count down by. Omit for headline '
                            . 'figures only.',
                    ],
                    'entities_id' => [
                        'type'        => 'integer',
                        'description' => 'Count this entity and its children instead of the '
                            . 'conversation\'s. Use read_entity or find the customer first.',
                    ],
                ],
            ],
            handler: [self::class, 'run'],
            right: 'ticket',
            // See the class comment: counting cannot be narrowed per actor, so
            // it is offered only to profiles that may already list the lot.
            right_level: Ticket::READALL,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        [$from, $to] = self::window($arguments);
        $entities    = self::entities($arguments, $context);

        if ($entities === []) {
            throw new ToolException('That entity is not one you can see.');
        }

        $scope   = ['entities_id' => $entities, 'is_deleted' => 0];

        // array_merge, not `+`. Every condition past the two named keys is
        // numerically indexed, and the union operator keeps the left-hand
        // value on a key collision — which silently dropped the breach test
        // below and reported every ticket in the window as breached.
        $opened  = array_merge($scope, [['date' => ['>=', $from]], ['date' => ['<=', $to]]]);

        $out = [
            'window'   => ['from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)],
            'entities' => count($entities) === 1
                ? Lookup::entityName($entities[0])
                : sprintf('%d entities', count($entities)),
            'opened'   => self::count($opened),
            'solved'   => self::count(
                $scope + [['solvedate' => ['>=', $from]], ['solvedate' => ['<=', $to]]]
            ),
            'still_open' => self::count(array_merge($scope, [
                ['NOT' => ['status' => array_merge(
                    Ticket::getClosedStatusArray(),
                    Ticket::getSolvedStatusArray()
                )]],
            ])),
        ];

        $out += self::resolution($entities, $from, $to);
        $out['breached_resolution'] = self::count(
            array_merge($opened, [
                ['NOT' => ['time_to_resolve' => null]],
                ['OR'  => [
                    // Solved after the target, or still running past it. Both
                    // are breaches; only counting the first forgives every
                    // ticket still sitting there overdue, which is the set
                    // somebody asking this question cares most about.
                    ['AND' => [
                        ['NOT' => ['solvedate' => null]],
                        new \QueryExpression(
                            $DB->quoteName('solvedate') . ' > ' . $DB->quoteName('time_to_resolve')
                        ),
                    ]],
                    ['AND' => [
                        'solvedate'       => null,
                        'time_to_resolve' => ['<', date('Y-m-d H:i:s')],
                    ]],
                ]],
            ])
        );

        $group_by = trim((string) ($arguments['group_by'] ?? ''));
        if ($group_by !== '') {
            if (!isset(self::DIMENSIONS[$group_by])) {
                throw new ToolException(
                    'group_by must be one of: ' . implode(', ', array_keys(self::DIMENSIONS)) . '.'
                );
            }

            $out['grouped_by']  = $group_by;
            $out['breakdown']   = self::breakdown($group_by, $opened, $out['opened']);
        }

        $out['note'] = sprintf(
            'Counts are of tickets opened in the window, except "solved" (solved in the window) '
            . 'and "still open" (open right now, whenever it was raised). %s',
            $out['opened'] === 0
                ? 'Nothing was raised in this window at all — check the dates before concluding '
                    . 'things are quiet.'
                : 'Every figure respects the entity and the rights of the signed-in user.'
        );

        return $out;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    private static function count(array $criteria): int
    {
        return (int) countElementsInTable(Ticket::getTable(), $criteria);
    }

    /**
     * Mean and median time to resolve, in hours.
     *
     * The median as well as the mean, because they answer different questions
     * and the mean alone is the one that misleads: one ticket left open over
     * Christmas moves a month's mean by days and moves the median not at all.
     *
     * @param int[] $entities
     * @return array<string,mixed>
     */
    private static function resolution(array $entities, string $from, string $to): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $hours = [];

        foreach (
            $DB->request([
                'SELECT' => ['date', 'solvedate'],
                'FROM'   => Ticket::getTable(),
                'WHERE'  => [
                    'entities_id' => $entities,
                    'is_deleted'  => 0,
                    ['NOT' => ['solvedate' => null]],
                    ['solvedate' => ['>=', $from]],
                    ['solvedate' => ['<=', $to]],
                ],
            ]) as $row
        ) {
            $open  = strtotime((string) $row['date']);
            $close = strtotime((string) $row['solvedate']);

            if ($open > 0 && $close >= $open) {
                $hours[] = ($close - $open) / 3600;
            }
        }

        if ($hours === []) {
            return [];
        }

        sort($hours);
        $middle = intdiv(count($hours), 2);

        return [
            'mean_hours_to_resolve'   => round(array_sum($hours) / count($hours), 1),
            'median_hours_to_resolve' => round(
                count($hours) % 2 === 1
                    ? $hours[$middle]
                    : ($hours[$middle - 1] + $hours[$middle]) / 2,
                1
            ),
        ];
    }

    /**
     * The count cut by one dimension, biggest first.
     *
     * Assignment lives in `glpi_tickets_users`, not on the ticket, so the two
     * actor dimensions join rather than group on a column that does not exist.
     * A ticket with two assignees counts once against each, which is the only
     * honest answer and is worth the note it gets.
     *
     * @param array<string,mixed> $criteria
     * @return array<int,array<string,mixed>>
     */
    private static function breakdown(string $dimension, array $criteria, int $total): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $spec  = self::DIMENSIONS[$dimension];
        $query = [
            'FROM'  => Ticket::getTable(),
            'WHERE' => $criteria,
        ];

        $actors = [
            'technician' => [\CommonITILActor::ASSIGN, 'glpi_tickets_users', 'users_id'],
            'requester'  => [\CommonITILActor::REQUESTER, 'glpi_tickets_users', 'users_id'],
            'group'      => [\CommonITILActor::ASSIGN, 'glpi_groups_tickets', 'groups_id'],
        ];

        if (isset($actors[$dimension])) {
            [$type, $table, $column] = $actors[$dimension];

            $query['SELECT'] = [
                "$table.$column AS value",
                new \QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('glpi_tickets.id') . ') AS cnt'),
            ];
            $query['INNER JOIN'] = [
                $table => [
                    'ON' => [
                        $table         => 'tickets_id',
                        'glpi_tickets' => 'id',
                        ['AND' => ["$table.type" => $type]],
                    ],
                ],
            ];
            $query['GROUPBY'] = ["$table.$column"];
        } else {
            $query['SELECT'] = [
                $spec['column'] . ' AS value',
                new \QueryExpression('COUNT(*) AS cnt'),
            ];
            $query['GROUPBY'] = [$spec['column']];
        }

        $query['ORDER'] = ['cnt DESC'];

        $rows  = [];
        $other = 0;

        foreach ($DB->request($query) as $row) {
            $label = self::label($dimension, $spec['table'], $row['value']);
            $count = (int) $row['cnt'];

            if (count($rows) >= self::TOP) {
                $other += $count;
                continue;
            }

            $rows[] = ['name' => $label, 'count' => $count];
        }

        if ($other > 0) {
            $rows[] = ['name' => 'everything else', 'count' => $other];
        }

        // Tickets with no actor of this type at all. The join above drops
        // them, so a breakdown by technician on a queue that is half
        // unassigned looked like a smaller queue rather than an unassigned
        // one — which is the opposite of the answer somebody wanted.
        if (isset($actors[$dimension])) {
            [$type, $table] = $actors[$dimension];

            $with = (int) countElementsInTable(
                Ticket::getTable(),
                array_merge($criteria, [
                    ['glpi_tickets.id' => new \QuerySubQuery([
                        'SELECT' => 'tickets_id',
                        'FROM'   => $table,
                        'WHERE'  => ['type' => $type],
                    ])],
                ]),
                ['DISTINCT' => true]
            );

            $nobody = max(0, $total - $with);
            if ($nobody > 0) {
                $rows[] = ['name' => 'nobody', 'count' => $nobody];
            }
        }

        return $rows;
    }

    private static function label(string $dimension, ?string $table, mixed $value): string
    {
        $id = (int) $value;

        if ($dimension === 'status') {
            return Ticket::getStatus($id);
        }

        if ($dimension === 'priority') {
            return \CommonITILObject::getPriorityName($id);
        }

        if ($dimension === 'type') {
            return $id === Ticket::INCIDENT_TYPE ? 'incident' : 'request';
        }

        if ($dimension === 'entity') {
            return Lookup::entityName($id) ?? 'unknown entity';
        }

        if ($id <= 0 || $table === null) {
            return $dimension === 'technician' || $dimension === 'group'
                ? 'nobody'
                : 'none';
        }

        if ($table === 'glpi_users') {
            $name = \getUserName($id);

            return is_string($name) && trim($name) !== '' ? trim($name) : "user $id";
        }

        $name = \Dropdown::getDropdownName($table, $id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : 'none';
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array{0:string,1:string}
     */
    private static function window(array $arguments): array
    {
        $to = self::day($arguments['to'] ?? null) ?? date('Y-m-d');

        $from = self::day($arguments['from'] ?? null);
        if ($from === null) {
            $days = (int) ($arguments['days'] ?? 30);
            $days = max(1, min(730, $days));
            $from = date('Y-m-d', strtotime("$to -$days days"));
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }

    private static function day(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The entities to count, narrowed twice.
     *
     * @param array<string,mixed> $arguments
     * @return int[]
     */
    private static function entities(array $arguments, ToolContext $context): array
    {
        $root = isset($arguments['entities_id'])
            ? (int) $arguments['entities_id']
            : $context->entities_id;

        $wanted = array_map('intval', \getSonsOf('glpi_entities', $root));
        $wanted[] = $root;

        $session = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));

        // The intersection, not the union. The conversation's entity narrows,
        // and the session's is the boundary that may never be crossed.
        return array_values(array_unique(array_intersect($wanted, $session)));
    }
}
