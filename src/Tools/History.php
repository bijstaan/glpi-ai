<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Change;
use Computer;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Log;
use Monitor;
use NetworkEquipment;
use Peripheral;
use Phone;
use Printer;
use Problem;
use Ticket;

/**
 * What changed on this record, and when.
 *
 * "It worked on Friday" is the most common sentence in support and the hardest
 * one to act on, because the thing that changed is almost never on the ticket.
 * GLPI has been writing it down the whole time — every field edit, every
 * component added or removed, every software install, every reassignment, with
 * a timestamp and a name against it — in a tab nobody opens while they are on
 * the phone.
 *
 * This is that tab. It is a read of `glpi_logs` through `Log::getHistoryData()`
 * rather than a query of its own, because the raw rows are unreadable: the
 * table stores foreign keys and field numbers, and core's own formatter is
 * what turns them into "Status changed from Planned to In progress". Doing it
 * here would be a second, worse copy that drifts every time core adds a
 * loggable type.
 *
 * Two things it deliberately is not:
 *
 *  - **Not the timeline.** Followups and tasks are what people wrote; this is
 *    what the system recorded. `read_ticket` covers the first.
 *  - **Not an audit trail for compliance.** It is capped, newest first, and
 *    filtered by what the caller may see. Somebody proving what happened opens
 *    the tab.
 */
final class History
{
    /** @var array<string,class-string> What a support question is ever about. */
    private const TYPES = [
        'ticket'            => Ticket::class,
        'change'            => Change::class,
        'problem'           => Problem::class,
        'computer'          => Computer::class,
        'monitor'           => Monitor::class,
        'printer'           => Printer::class,
        'network_equipment' => NetworkEquipment::class,
        'phone'             => Phone::class,
        'peripheral'        => Peripheral::class,
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'item_history',
            description: 'What recently changed on a ticket, change, problem, machine or other '
                . 'asset, newest first: field edits, reassignments, components and software '
                . 'added or removed, with the date and who did it. Reach for this whenever the '
                . 'question is "it was working before", "what changed recently" or "when did '
                . 'this change" — the timeline says what people wrote, and the history says what '
                . 'actually changed underneath them.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => array_keys(self::TYPES),
                        'description' => 'What kind of record.',
                    ],
                    'id'       => ['type' => 'integer', 'description' => 'Its id.'],
                    'limit'    => [
                        'type'        => 'integer',
                        'description' => 'Entries to return, 1-50. Defaults to 20.',
                    ],
                ],
                'required'   => ['itemtype', 'id'],
            ],
            handler: [self::class, 'run'],
            // No right of its own. GLPI gates history on being able to see the
            // item, which is checked below — and there is no profile right
            // that means "may read history" to gate it on instead.
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

        $kind  = (string) ($arguments['itemtype'] ?? '');
        $id    = (int) ($arguments['id'] ?? 0);
        $limit = Lookup::limit($arguments['limit'] ?? null, 20, 50);

        if (!isset(self::TYPES[$kind])) {
            throw new ToolException(
                'Unknown record type. Use one of: ' . implode(', ', array_keys(self::TYPES)) . '.'
            );
        }

        $itemtype = self::TYPES[$kind];
        /** @var \CommonDBTM $item */
        $item = new $itemtype();

        if ($id <= 0 || !$item->getFromDB($id) || !$item->canViewItem()) {
            throw new ToolException("No $kind $id is visible to you.");
        }

        $entries = [];
        foreach (Log::getHistoryData($item, 0, $limit) as $row) {
            $change = self::text((string) ($row['change'] ?? ''));
            if ($change === '') {
                continue;
            }

            $entries[] = array_filter([
                'when'   => (string) ($row['date_mod'] ?? ''),
                'who'    => trim((string) ($row['user_name'] ?? '')),
                'field'  => self::text((string) ($row['field'] ?? '')),
                'change' => $change,
            ], static fn(string $v): bool => $v !== '');
        }

        return [
            'item'    => ['type' => $kind, 'id' => $id, 'name' => (string) ($item->fields['name'] ?? '')],
            'count'   => count($entries),
            'history' => $entries,
            'note'    => $entries === []
                ? 'Nothing has been recorded as changing on this record. On an asset that often '
                  . 'means no inventory agent has reported it, rather than that nothing changed.'
                : null,
        ];
    }

    /**
     * One history cell as plain text.
     *
     * `Log::getHistoryData()` builds its strings for a table cell, so they
     * arrive HTML-escaped and occasionally with markup in them. Handing a model
     * `&quot;` and `&amp;` costs tokens twice — once going in, and again in
     * whatever it quotes back — and teaches it that the field is spelled that
     * way.
     */
    private static function text(string $value): string
    {
        $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }
}
