<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * What every AI call cost, and which model answered.
 *
 * The roadmap's fifth architecture decision: when a feature's accuracy moves,
 * the first question is whether we changed the prompt or the vendor changed the
 * model under us. That is unanswerable after the fact unless provider, model
 * and prompt fingerprint were written down at the time.
 *
 * It also makes cost visible per entity, which for an MSP is the difference
 * between "AI costs us something" and a line on a client's invoice.
 *
 * Note the deliberate absence of a cost column. Token counts are not comparable
 * across providers — the same text tokenises differently on each — so a single
 * money figure computed here would be wrong the moment a second provider was
 * used. Rates belong wherever the reporting happens, applied per provider.
 */
final class UsageLog
{
    public const TABLE = 'glpi_plugin_glpiai_usagelogs';

    /**
     * Aggregate usage for a period, grouped by provider and model.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function summary(string $since, ?int $entities_id = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['date_creation' => ['>=', $since]];
        if ($entities_id !== null) {
            $where['entities_id'] = $entities_id;
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT'  => [
                    'provider',
                    'model',
                    'tier',
                    'COUNT' => 'id AS calls',
                    'SUM'   => ['input_tokens AS input', 'output_tokens AS output'],
                    'AVG'   => 'duration_ms AS avg_ms',
                ],
                'FROM'    => self::TABLE,
                'WHERE'   => $where,
                'GROUPBY' => ['provider', 'model', 'tier'],
                'ORDER'   => ['calls DESC'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** Drop rows older than the retention window. */
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
