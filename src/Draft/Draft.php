<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Draft;

use DBmysql;
use Session;

/**
 * One drafted artefact for one ticket, and what a human did with it.
 *
 * Two kinds, and they are genuinely different jobs rather than one feature with
 * a flag. A **solution** is about this ticket: what was wrong here, what fixed
 * it, addressed to whoever reads this ticket next. An **article** is about the
 * class of problem: it must generalise, drop the customer, and be useful to
 * somebody who has never seen this ticket. A model told to do both at once does
 * neither, so they are separate rows, separate prompts and separate schemas.
 *
 * Like {@see \GlpiPlugin\Glpiai\Triage\Suggestion}, the outcome is recorded.
 * Unlike triage there are only two — a draft is used or it is not; there is no
 * "was already right".
 */
final class Draft
{
    public const TABLE = 'glpi_plugin_glpiai_drafts';

    public const SOLUTION = 'solution';
    public const ARTICLE  = 'article';

    public const PENDING = 'pending';
    public const READY   = 'ready';
    public const FAILED  = 'failed';

    public const OPEN      = '';
    /** Inserted into the solution editor, or turned into an article. */
    public const USED      = 'used';
    public const DISCARDED = 'discarded';

    /** @return array<string,mixed>|null */
    public static function forTicket(int $tickets_id, string $kind): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['tickets_id' => $tickets_id, 'kind' => $kind],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Start (or restart) a draft, returning its id.
     *
     * Regenerating replaces rather than accumulating. A technician who asks for
     * a second draft wants a second draft, not a list to choose between — and
     * a table with a history of every attempt would need a UI to pick from,
     * which is a feature nobody asked for.
     */
    public static function begin(int $tickets_id, int $entities_id, string $kind): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $existing = self::forTicket($tickets_id, $kind);

        if ($existing !== null) {
            $DB->update(self::TABLE, [
                'state'         => self::PENDING,
                'content'       => '',
                'title'         => '',
                'gaps'          => '',
                'confidence'    => '',
                'evidence'      => '',
                'error_message' => null,
                'outcome'       => self::OPEN,
                'date_mod'      => $now,
            ], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $DB->insert(self::TABLE, [
            'tickets_id'    => $tickets_id,
            'entities_id'   => $entities_id,
            'kind'          => $kind,
            'state'         => self::PENDING,
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        return (int) $DB->insertId();
    }

    /** @param array<string,mixed> $values */
    public static function store(int $id, array $values): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $values['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->update(self::TABLE, $values, ['id' => $id]);
    }

    public static function fail(int $id, string $message): void
    {
        self::store($id, [
            'state'         => self::FAILED,
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    public static function decide(int $id, string $outcome, int $knowbaseitems_id = 0): void
    {
        $values = [
            'outcome'          => $outcome,
            'users_id_decided' => (int) Session::getLoginUserID(),
            'date_decided'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        if ($knowbaseitems_id > 0) {
            $values['knowbaseitems_id'] = $knowbaseitems_id;
        }

        self::store($id, $values);
    }

    public static function forget(int $tickets_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, ['tickets_id' => $tickets_id]);
    }

    /**
     * How often a draft was good enough to use, per kind.
     *
     * The same argument as triage's accept rate: without it, "the drafts seem
     * decent" is the most anyone can say, and a regression after a vendor
     * changes a model under you is invisible.
     *
     * @return array<string,array{used:int,discarded:int,open:int,rate:?float}>
     */
    public static function usage(?int $entities_id = null): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['state' => self::READY];
        if ($entities_id !== null) {
            $where['entities_id'] = $entities_id;
        }

        $out = [];
        foreach ([self::SOLUTION, self::ARTICLE] as $kind) {
            $counts = ['used' => 0, 'discarded' => 0, 'open' => 0];

            foreach (
                $DB->request([
                    'SELECT'  => ['outcome', 'COUNT' => 'id AS n'],
                    'FROM'    => self::TABLE,
                    'WHERE'   => $where + ['kind' => $kind],
                    'GROUPBY' => ['outcome'],
                ]) as $row
            ) {
                $key = (string) $row['outcome'] === self::OPEN ? 'open' : (string) $row['outcome'];
                if (isset($counts[$key])) {
                    $counts[$key] = (int) $row['n'];
                }
            }

            $decided    = $counts['used'] + $counts['discarded'];
            // Cast, because PHP's `/` returns an int when the division happens
            // to be exact — one used and none discarded gives int(1), not 1.0,
            // and a caller comparing strictly against a float is then wrong
            // only for the tidiest numbers.
            $out[$kind] = $counts + [
                'rate' => $decided > 0 ? (float) ($counts['used'] / $decided) : null,
            ];
        }

        return $out;
    }
}
