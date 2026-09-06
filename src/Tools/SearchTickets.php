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
 * Find tickets by free text.
 *
 * The tool the roadmap's drafting and triage features lean on hardest: "has
 * anyone seen this before" is the question a technician actually asks, and it
 * is the one GLPI's own search answers badly because it matches words rather
 * than meaning. This does not fix that — it is still GLPI's search — but it
 * lets the *model* do the paraphrasing, running several wordings and reading
 * the results — retrieval by meaning, on the model's initiative, with no index
 * to build and no second copy of every ticket to keep in step.
 */
final class SearchTickets
{
    private const SO_NAME    = 1;
    private const SO_STATUS  = 12;
    private const SO_DATE    = 15;
    private const SO_ENTITY  = 80;

    public static function tool(): Tool
    {
        return new Tool(
            name: 'search_tickets',
            description: 'Search tickets by free text: symptoms, error messages, hostnames, or a '
                . 'requester\'s wording. Returns matching tickets with their id, title, status and '
                . 'date. Use read_ticket afterwards to see what was actually done about one. '
                . 'Searching two or three different phrasings finds more than one long query.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query'  => [
                        'type'        => 'string',
                        'description' => 'Words to look for anywhere in the ticket.',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'enum'        => ['open', 'closed', 'any'],
                        'description' => 'Which tickets to consider. Defaults to open.',
                    ],
                    'limit'  => [
                        'type'        => 'integer',
                        'description' => 'How many to return, 1-25. Defaults to 10.',
                    ],
                ],
                'required'   => ['query'],
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

        $limit = Lookup::limit($arguments['limit'] ?? null);
        $rows  = Lookup::search(
            Ticket::class,
            (string) ($arguments['query'] ?? ''),
            $limit,
            $context,
            self::statusCriteria((string) ($arguments['status'] ?? 'open'))
        );

        $tickets = [];
        foreach ($rows as $raw) {
            $id = (int) ($raw['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $status = Lookup::column($raw, Ticket::class, self::SO_STATUS);

            $tickets[] = array_filter([
                'id'     => $id,
                'title'  => Lookup::column($raw, Ticket::class, self::SO_NAME),
                'status' => $status !== null ? Ticket::getStatus((int) $status) : null,
                'opened' => Lookup::column($raw, Ticket::class, self::SO_DATE),
                'entity' => Lookup::column($raw, Ticket::class, self::SO_ENTITY),
            ], static fn($v): bool => $v !== null);
        }

        return [
            'count'   => count($tickets),
            'tickets' => $tickets,
            // Said explicitly, because a model shown ten results assumes ten is
            // all there is and stops looking.
            'note'    => count($tickets) >= $limit
                ? 'Result list was truncated at the requested limit; there may be more.'
                : null,
        ];
    }

    /**
     * "Open" and "closed" as GLPI actually models them.
     *
     * GLPI has six ticket statuses and no boolean for this, so the model is
     * offered the two words a technician would use and the mapping happens
     * here — asking it to name status ids would be asking it to know GLPI's
     * schema, which it does not and should not.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function statusCriteria(string $status): array
    {
        return match ($status) {
            'closed' => [[
                'field'      => self::SO_STATUS,
                'searchtype' => 'equals',
                'value'      => 'old',      // GLPI's meta-value for solved + closed
            ]],
            'any'    => [],
            default  => [[
                'field'      => self::SO_STATUS,
                'searchtype' => 'equals',
                'value'      => 'notold',   // everything not yet solved or closed
            ]],
        };
    }
}
