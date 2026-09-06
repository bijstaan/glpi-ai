<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * What the model went looking for, and on whose behalf.
 *
 * The usage log answers "what did this cost". This answers a different and, for
 * an MSP, more awkward question: a client asks what an AI feature touched on
 * their tenant, and the honest answer has to come from a record made at the
 * time. "It can only see what the technician could see" is true and is not an
 * answer.
 *
 * Separate from the usage log rather than a column on it because the cardinality
 * differs — one provider call can produce several tool calls, and a run can
 * make several provider calls — and because the retention question differs too.
 */
final class ToolLog
{
    public const TABLE = 'glpi_plugin_glpiai_toolcalls';

    /**
     * Recent calls, most recent first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 100, ?int $entities_id = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [];
        if ($entities_id !== null) {
            $where['entities_id'] = $entities_id;
        }

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => $where,
                'ORDER' => 'id DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * How often each tool was reached for, and how often it failed.
     *
     * The failure rate is the number worth watching: a tool the model keeps
     * calling and keeps getting errors from is usually a description problem,
     * not a code one.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function summary(string $since): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT'  => [
                    'tool',
                    'source',
                    'COUNT' => 'id AS calls',
                    'SUM'   => 'is_error AS failures',
                    'AVG'   => 'duration_ms AS avg_ms',
                ],
                'FROM'    => self::TABLE,
                'WHERE'   => ['date_creation' => ['>=', $since]],
                'GROUPBY' => ['tool', 'source'],
                'ORDER'   => ['calls DESC'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    public static function prune(int $days): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        $DB->delete(self::TABLE, [
            'date_creation' => ['<', date('Y-m-d H:i:s', time() - ($days * 86400))],
        ]);

        return $DB->affectedRows();
    }
}
