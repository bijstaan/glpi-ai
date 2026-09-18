<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * Ranking a short corpus against a plain-language query.
 *
 * Word overlap, which is crude and is the right amount of machinery. The corpus
 * is a few dozen sentences — tool descriptions we wrote, or procedures an
 * administrator wrote — the query comes from a model that has already been told
 * roughly what exists, and anything cleverer would be a second retrieval system
 * to keep working.
 *
 * Extracted from {@see Toolbox} when {@see Assistant\Skillbox} needed the same
 * thing. Two rankers would have drifted in the way that is hardest to notice:
 * both would keep returning *something*, and only the ordering would quietly
 * stop agreeing.
 */
final class Search
{
    /**
     * Rank a corpus and return the keys of what matched, best first.
     *
     * Each entry is `['key' => mixed, 'name' => string, 'text' => string]`. The
     * name is weighted double because a query that contains it is not a search
     * — it is somebody asking for a thing by name.
     *
     * An empty query returns the first `$max` keys unranked. That is not a
     * failure case: a model calling a search tool with nothing in particular to
     * say is asking what exists, and a list is the answer.
     *
     * @param array<int,array{key:mixed,name:string,text:string}> $corpus
     * @return array<int,mixed> the keys, best first
     */
    public static function rank(array $corpus, string $query, int $max): array
    {
        $terms = self::words($query);

        if ($terms === []) {
            return array_map(
                static fn(array $entry): mixed => $entry['key'],
                array_slice(array_values($corpus), 0, $max)
            );
        }

        $scored = [];

        foreach ($corpus as $entry) {
            $name = self::words(str_replace('_', ' ', (string) $entry['name']));
            $text = self::words((string) $entry['text']);

            $score = count(array_intersect($terms, $name)) * 2
                   + count(array_intersect($terms, $text));

            if ($score > 0) {
                $scored[] = ['key' => $entry['key'], 'score' => $score];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            static fn(array $row): mixed => $row['key'],
            array_slice($scored, 0, $max)
        );
    }

    /**
     * @return string[]
     */
    public static function words(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];

        // Stopwords, and the tool-description filler that behaves like one.
        // Without this every query matches everything on "use" and "this".
        static $noise = [
            'the', 'and', 'for', 'this', 'that', 'with', 'use', 'used', 'using', 'get', 'from',
            'what', 'when', 'which', 'their', 'them', 'you', 'your', 'are', 'was', 'not', 'its',
            'tool', 'tools', 'returns', 'return', 'about', 'into', 'out', 'one', 'can',
        ];

        $kept = array_filter(
            $words,
            static fn(string $w): bool => strlen($w) > 2 && !in_array($w, $noise, true)
        );

        // Collapse a trailing plural, so "machine" matches "machines" and
        // "alarm" matches "alarms". Crude stemming, and the right amount for a
        // corpus of English we wrote ourselves — without it the single most
        // obvious query ("check disk space on a machine") misses the tool that
        // answers it, on an 's'.
        return array_values(array_unique(array_map(
            static fn(string $w): string => strlen($w) > 3 && str_ends_with($w, 's')
                && !str_ends_with($w, 'ss')
                ? substr($w, 0, -1)
                : $w,
            $kept
        )));
    }
}
