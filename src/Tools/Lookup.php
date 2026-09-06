<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\ToolContext;
use Search;

/**
 * Shared plumbing for the native tools.
 *
 * Every one of them goes through `Search::getDatas()` — the same path
 * `front/search.php` takes for the header search box — rather than composing
 * its own SQL. That is not a shortcut: it is what makes the tools inherit GLPI's
 * profile rights and entity restrictions for free. A hand-written `LIKE` query
 * would be faster and would quietly hand a model rows the technician driving it
 * is not entitled to see, which is the one failure mode that turns tool use into
 * a security problem rather than a quality one.
 *
 * The same reasoning is why these refuse to run without a session. There is no
 * "the plugin's own rights" to fall back on, and a tool that resolved to
 * everything when nobody was signed in would read across every tenant at once.
 */
final class Lookup
{
    /** GLPI's search option id for the entity column, on every entity-aware type. */
    private const ENTITY_FIELD = 80;

    /** The pseudo-field meaning "match anywhere the list would show". */
    private const ANYWHERE = 'view';

    public static function requireSession(): ?string
    {
        return (int) \Session::getLoginUserID() > 0
            ? null
            : 'Tools run with the rights of the signed-in user, and no user is signed in.';
    }

    /**
     * Search one itemtype, scoped to the conversation's entity and below.
     *
     * The entity criterion can only narrow: GLPI has already restricted the
     * query to the session's entities, and this is ANDed on top. So a
     * conversation about entity 5 cannot reach entity 6 even if the technician
     * could have.
     *
     * @return array<int,array<string,mixed>> raw search rows
     */
    public static function search(
        string $itemtype,
        string $term,
        int $limit,
        ToolContext $context,
        array $extra = [],
        /**
         * Narrow to the conversation's entity, on top of the session's.
         *
         * True for anything filed in an entity, which is nearly everything.
         * False for **people**: GLPI models a person's entity as a profile
         * assignment rather than as a column on the row, so a technician or a
         * shared-services user sits in the root entity and this criterion drops
         * them — a requester on the ticket in front of you comes back "no such
         * user". Turning it off does not widen what the caller may see:
         * `Search` has already restricted the query to the session's entities,
         * which is the boundary that matters. What is lost is the narrowing to
         * *this conversation*, and for people that narrowing was wrong.
         */
        bool $scope_to_entity = true
    ): array {
        $params                 = Search::manageParams($itemtype, ['reset' => 'reset'], false, true);
        $params['display_type'] = Search::GLOBAL_SEARCH;
        $params['start']        = 0;
        $params['list_limit']   = $limit;

        $criteria = [
            [
                'field'      => self::ANYWHERE,
                'searchtype' => 'contains',
                'value'      => $term,
            ],
        ];

        if ($scope_to_entity) {
            $criteria[] = [
                'link'       => 'AND',
                'field'      => self::ENTITY_FIELD,
                // "under" rather than "equals": permitting an entity means its
                // sub-entities too, which is how an MSP models a client with a
                // site per office.
                'searchtype' => 'under',
                'value'      => $context->entities_id,
            ];
        }

        foreach ($extra as $one) {
            $criteria[] = ['link' => 'AND'] + $one;
        }

        foreach ($criteria as $one) {
            $params['criteria'][count($params['criteria'])] = $one;
        }

        $data = Search::getDatas($itemtype, $params);

        $rows = [];
        foreach ($data['data']['rows'] ?? [] as $row) {
            if (isset($row['raw'])) {
                $rows[] = $row['raw'];
            }
        }

        return $rows;
    }

    /** A search-option column out of a raw row. */
    public static function column(array $raw, string $itemtype, int $option): ?string
    {
        $value = $raw['ITEM_' . $itemtype . '_' . $option] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Rich text as something worth spending tokens on.
     *
     * GLPI stores ticket bodies as HTML, and handing that to a model means
     * paying for every `<p>` and every inline style twice — once going in, and
     * again in whatever the model quotes back.
     */
    public static function plain(?string $html, int $limit = 4000): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // The arguments matter more than they look. GLPI's default
        // "presentation" mode is written for plain-text email: it renders <b>
        // as UPPERCASE and appends link targets as footnotes. Both are actively
        // harmful here — a model reading a ticket needs the original casing of
        // hostnames, serial numbers and error codes, and shouting them destroys
        // exactly the strings that identify anything. Line breaks are kept
        // because a numbered list of steps that arrives as one paragraph reads
        // as one step.
        $text = \Glpi\RichText\RichText::getTextFromHtml(
            $html,
            keep_presentation: false,
            compact: false,
            encode_output: false,
            preserve_case: true,
            preserve_line_breaks: true
        );

        $text = trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? '');

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit) . "\n…[truncated]"
            : $text;
    }

    /**
     * The entity's name, including the root one.
     *
     * Its own helper because entity 0 is a real entity — the root, which on a
     * single-tenant install is *every* record. The obvious `$id > 0` guard that
     * every other dropdown here uses drops it, so a ticket in the root entity
     * came back with no entity at all, which on an MSP install reads as "not
     * this customer" rather than as "the shared one".
     */
    public static function entityName(int $entities_id): ?string
    {
        if ($entities_id < 0) {
            return null;
        }

        $name = \Dropdown::getDropdownName('glpi_entities', $entities_id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : null;
    }

    /**
     * Clamp a model-supplied limit.
     *
     * Models ask for 100 results when 10 would do, and every row is paid for on
     * the next turn. The ceiling is low on purpose.
     */
    public static function limit(mixed $requested, int $default = 10, int $max = 25): int
    {
        $value = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($max, $value));
    }
}
