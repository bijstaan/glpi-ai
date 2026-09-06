<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use KnowbaseItem;

/**
 * Search the knowledge base.
 *
 * Separate from search_tickets rather than folded into one "search everything"
 * tool, deliberately. A model choosing between two clearly-named tools does so
 * reliably; the same model given one tool and an `in:` parameter routinely
 * forgets the parameter. Tool names are the cheapest prompt there is.
 *
 * Returns the article text, not a link — the caller of an AI feature is a
 * technician who wants the answer, and a result that says "see KB #14" costs a
 * round trip to be useful.
 */
final class SearchKnowledge
{
    private const SO_NAME   = 6;
    private const SO_ANSWER = 7;

    public static function tool(): Tool
    {
        return new Tool(
            name: 'search_knowledge',
            description: 'Search the knowledge base for documented procedures, known issues and '
                . 'how-to articles. Returns the article text. Prefer this over recalling a general '
                . 'answer: an article written for this organisation reflects how things are '
                . 'actually configured here.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to look for.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many articles, 1-25. Defaults to 5.'],
                ],
                'required'   => ['query'],
            ],
            handler: [self::class, 'run'],
            right: 'knowbase'
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $limit = Lookup::limit($arguments['limit'] ?? null, 5);
        $rows  = Lookup::search(KnowbaseItem::class, (string) ($arguments['query'] ?? ''), $limit, $context);

        $articles = [];
        foreach ($rows as $raw) {
            $id = (int) ($raw['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $articles[] = array_filter([
                'id'    => $id,
                'title' => Lookup::column($raw, KnowbaseItem::class, self::SO_NAME),
                // Shorter than a ticket body on purpose: several articles come
                // back at once, and the whole result set is paid for on the
                // next turn.
                'text'  => Lookup::plain(Lookup::column($raw, KnowbaseItem::class, self::SO_ANSWER), 2500),
            ], static fn($v): bool => $v !== null);
        }

        return ['count' => count($articles), 'articles' => $articles];
    }
}
