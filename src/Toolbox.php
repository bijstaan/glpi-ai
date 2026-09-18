<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * Tool search, for when there are too many tools to send.
 *
 * Every tool offered on a request costs its whole schema in the prompt, on
 * every turn, and it costs it again on each turn of an agent loop. That is the
 * cheap half of the problem. The expensive half is that a model choosing
 * between twenty tools chooses worse than one choosing between eight: the
 * descriptions blur, near-duplicates compete, and the failure is not an error
 * but a slightly wrong call.
 *
 * So above a threshold the request declares a core set plus `find_tools`, and
 * the rest are *discoverable* rather than offered. The model searches, the loop
 * adds what it found to the next turn, and it calls them directly from then on.
 *
 * Which tools stay in the core set is decided by where they came from, not by
 * an opinion about usefulness: this plugin's own tools are always there, and
 * the long tail contributed by other plugins and MCP servers is what becomes
 * searchable. A site with two plugins and one MCP server should not find that
 * `read_ticket` has quietly become a two-step operation.
 *
 * Below the threshold none of this happens and everything is offered as before,
 * because the machinery costs a round trip and is not worth paying for a
 * handful of tools.
 */
final class Toolbox
{
    public const NAME = 'find_tools';

    /** Matches returned by one search. Enough to choose from, few enough to read. */
    private const MAX_MATCHES = 8;

    /**
     * The tools a request should declare.
     *
     * @param Tool[] $all everything registered
     * @return Tool[]
     */
    public static function offer(array $all): array
    {
        if (!self::engages($all)) {
            return $all;
        }

        // Pinned, not `source === 'native'`.
        //
        // Filtering on the source meant every tool this plugin did not define
        // itself was withheld the moment the count crossed the threshold — the
        // osquery and netscan integrations as well as every MCP server. On a
        // real install that was 11 of 17 tools invisible, including the three
        // an administrator had just added a server for. Nothing said so: the
        // settings page listed them, and the model was never told they existed.
        //
        // What is pinned is now the administrator's decision. Anything shipped
        // or installed is pinned by default; an MCP server can be un-pinned to
        // put its tools behind the search, which is what the threshold is for
        // when somebody really does connect dozens of them.
        $pinned = array_filter($all, static fn(Tool $t): bool => $t->pinned);

        if (count($pinned) === count($all)) {
            return $all;
        }

        return array_merge(array_values($pinned), [self::tool($all)]);
    }

    /** @param Tool[] $all */
    public static function engages(array $all): bool
    {
        $threshold = max(2, (int) Settings::get('tool_search_threshold'));

        return count($all) >= $threshold;
    }

    /**
     * Add whatever a `find_tools` call turned up to the offered set.
     *
     * Called by the agent loop after each turn. The search is re-run from the
     * call's own arguments rather than parsed back out of the result, which
     * keeps this stateless: the same query gives the same tools, there is
     * nothing to keep in step, and a result the model paraphrased or truncated
     * cannot lead the loop somewhere the registry did not.
     *
     * @param Tool[]           $offered
     * @param ToolInvocation[] $invocations from the turn that just ran
     * @param Tool[]           $all
     * @return Tool[] the set to offer on the next turn
     */
    public static function expand(array $offered, array $invocations, array $all): array
    {
        $named = [];
        foreach ($offered as $tool) {
            $named[$tool->name] = true;
        }

        $added = [];

        foreach ($invocations as $invocation) {
            if ($invocation->call->name !== self::NAME || $invocation->result->is_error) {
                continue;
            }

            foreach (self::search($all, (string) ($invocation->call->arguments['query'] ?? '')) as $tool) {
                if (!isset($named[$tool->name])) {
                    $named[$tool->name] = true;
                    $added[]            = $tool;
                }
            }
        }

        return $added === [] ? $offered : array_merge($offered, $added);
    }

    /**
     * The search tool itself.
     *
     * Its description has to do two jobs: say what it is for, and say that the
     * tools it finds become callable afterwards. Without the second, a model
     * that finds a tool reports the name back to the user as a suggestion
     * instead of using it — which looks like a broken feature and is really a
     * prompt that never said what happens next.
     *
     * @param Tool[] $all
     */
    public static function tool(array $all): Tool
    {
        $sources = [];
        foreach ($all as $tool) {
            if ($tool->source !== 'native') {
                $sources[$tool->source] = true;
            }
        }

        $hint = $sources === []
            ? ''
            : ' Available here: ' . implode(', ', array_keys($sources)) . '.';

        return new Tool(
            name: self::NAME,
            description: 'Search for other tools by what you want to do. There are more tools '
                . 'available than are listed here — things like querying an endpoint directly, '
                . 'reading network hardware, or whatever integrations this site has connected.'
                . $hint
                . ' Call this with a plain description of what you need ("check disk space on a '
                . 'machine", "recent alarms from a switch"), and the tools it returns become '
                . 'callable immediately afterwards — so search first, then call what you found, '
                . 'in the same conversation.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'What you are trying to do, in plain words.',
                    ],
                ],
                'required'   => ['query'],
            ],
            handler: static fn(array $arguments): array => self::run($all, $arguments),
            right: null
        );
    }

    /**
     * @param Tool[]              $all
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private static function run(array $all, array $arguments): array
    {
        $query   = trim((string) ($arguments['query'] ?? ''));
        $matches = self::search($all, $query);

        if ($matches === []) {
            return [
                'tools' => [],
                'note'  => 'Nothing matched. These exist: '
                    . implode(', ', array_map(static fn(Tool $t): string => $t->name, $all))
                    . '. Ask for one by name if you can see what you need.',
            ];
        }

        $out = [];
        foreach ($matches as $tool) {
            $refusal = $tool->refusalReason();

            $out[] = [
                'name'        => $tool->name,
                'description' => $tool->description,
                'source'      => $tool->source,
                // Reported here rather than discovered on the call. A model
                // told now that it may not use something picks the next best
                // thing; one that finds out by calling has spent a turn.
                'usable'      => $refusal === null,
                'why_not'     => $refusal,
            ];
        }

        return [
            'tools' => $out,
            'note'  => 'These are now callable. Call the one you want directly.',
        ];
    }

    /**
     * Rank tools against a plain-language query.
     *
     * The ranking itself is {@see Search}, shared with skill search. What stays
     * here is the corpus: a tool is its name and its description, and
     * `find_tools` never returns itself — a model that has just called it does
     * not need to be told it exists.
     *
     * @param Tool[] $all
     * @return Tool[]
     */
    private static function search(array $all, string $query): array
    {
        $corpus = [];
        $byName = [];

        foreach ($all as $tool) {
            if ($tool->name === self::NAME) {
                continue;
            }

            $byName[$tool->name] = $tool;
            $corpus[]            = [
                'key'  => $tool->name,
                'name' => $tool->name,
                'text' => $tool->description,
            ];
        }

        $out = [];
        foreach (Search::rank($corpus, $query, self::MAX_MATCHES) as $name) {
            $out[] = $byName[(string) $name];
        }

        return $out;
    }
}
