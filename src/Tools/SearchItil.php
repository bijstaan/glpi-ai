<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Change;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Problem;

/**
 * Changes and problems, which are where the answer often actually is.
 *
 * `search_tickets` covers the incident record and stops there, and that is a
 * gap with a shape: the tickets say six people lost the shared drive on
 * Tuesday, and the *problem* record says why, and the *change* record says
 * somebody moved the DFS namespace on Monday night. A model that can only read
 * tickets reads six accounts of a symptom and none of the cause.
 *
 * One tool rather than two, with the type as an argument. They are the same
 * search over two tables that a technician thinks of as one question — "is
 * this already known about" — and two tools would mean the model picking one
 * and stopping.
 *
 * Unlike `search_tickets`, each hit carries its description. A change is
 * usually worth reading in full and there are rarely twenty of them, so the
 * round trip a second `read_` tool would cost buys nothing here.
 *
 * What it does not carry is *when the work is planned for*: core keeps no such
 * column on a change — the dates live on its tasks — and glpi-change already
 * contributes `change_calendar` and `change_schedule` for exactly that
 * question. A second, worse answer here would be one the model could reach
 * first.
 */
final class SearchItil
{
    private const SO_NAME   = 1;
    private const SO_STATUS = 12;

    /** @var array<string,class-string> */
    private const TYPES = [
        'change'  => Change::class,
        'problem' => Problem::class,
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'search_itil',
            description: 'Search changes and problems by their words — the planned work and the '
                . 'known underlying faults, as opposed to the tickets people raised about them. '
                . 'Use it when several tickets look like one cause, when a fault started at a '
                . 'suspiciously round time, or before telling somebody a fault is unknown. Each '
                . 'result carries its description, so there is no second call to read one.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query'    => [
                        'type'        => 'string',
                        'description' => 'Words to look for, as they would appear in the title or '
                            . 'the description.',
                    ],
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => array_keys(self::TYPES),
                        'description' => 'Restrict to one of them. Omit to search both.',
                    ],
                    'limit'    => [
                        'type'        => 'integer',
                        'description' => 'Results per type, 1-25. Defaults to 5.',
                    ],
                ],
                'required'   => ['query'],
            ],
            handler: [self::class, 'run'],
            right: 'change',
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

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            throw new ToolException('Give something to search for.');
        }

        $limit = Lookup::limit($arguments['limit'] ?? null, 5);
        $only  = (string) ($arguments['itemtype'] ?? '');

        $types = $only !== '' && isset(self::TYPES[$only])
            ? [$only => self::TYPES[$only]]
            : self::TYPES;

        $found = [];
        foreach ($types as $kind => $itemtype) {
            // Per type, so a profile that may read changes and not problems
            // gets the changes rather than a refusal. The tool's own `right` is
            // `change`, which is the coarse gate; this is the accurate one.
            $probe = getItemForItemtype($itemtype);
            if (!$probe || !$probe->canView()) {
                continue;
            }

            foreach (Lookup::search($itemtype, $query, $limit, $context) as $raw) {
                $id = (int) ($raw['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                /** @var \CommonITILObject $item */
                $item = new $itemtype();
                if (!$item->getFromDB($id) || !$item->canViewItem()) {
                    continue;
                }

                $found[] = array_filter([
                    'type'        => $kind,
                    'id'          => $id,
                    'title'       => Lookup::column($raw, $itemtype, self::SO_NAME)
                                   ?? (string) $item->fields['name'],
                    'status'      => $itemtype::getStatus((int) $item->fields['status'])
                                   ?: Lookup::column($raw, $itemtype, self::SO_STATUS),
                    'opened'      => (string) ($item->fields['date'] ?? ''),
                    'last_update' => (string) ($item->fields['date_mod'] ?? ''),
                    'solved'      => (string) ($item->fields['solvedate'] ?? ''),
                    'description' => Lookup::plain((string) ($item->fields['content'] ?? ''), 1500),
                ], static fn($v): bool => $v !== null && $v !== '');
            }
        }

        return [
            'count'   => count($found),
            'results' => $found,
            'note'    => $found === []
                ? 'No change or problem matches. That is not proof there is none — try the words '
                  . 'an engineer would have used rather than the words the requester used.'
                : null,
        ];
    }
}
