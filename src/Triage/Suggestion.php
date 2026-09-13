<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Triage;

use DBmysql;
use Session;

/**
 * One model's proposal for one ticket, and what a human did about it.
 *
 * The second half of that sentence is the point. A suggestion nobody recorded
 * the fate of is an anecdote — "the AI seems decent" — and the roadmap's whole
 * argument for shipping triage at 85% accuracy rests on being able to say what
 * the accuracy actually is. Every field carries its own outcome, because they
 * are genuinely different questions: a model can be reliable about category and
 * hopeless about urgency, and one number averaged over both would hide it.
 *
 * Three outcomes, not two. A suggestion that merely agrees with what the ticket
 * already said is neither accepted nor rejected — nothing happened, and nobody
 * decided anything. Counting those as accepts is the easiest way to publish an
 * accuracy figure that is mostly measuring GLPI's own defaults, so they are
 * recorded as {@see self::MATCHED} and excluded from the rate.
 */
final class Suggestion
{
    public const TABLE = 'glpi_plugin_glpiai_triages';

    /** Queued at creation; no provider call has been made yet. */
    public const PENDING = 'pending';
    /** The model answered and the answer survived validation. */
    public const READY   = 'ready';
    /** The provider refused, timed out, or returned something unusable. */
    public const FAILED  = 'failed';

    /** No decision yet. */
    public const OPEN      = '';
    /** A human clicked apply, and the ticket changed. */
    public const ACCEPTED  = 'accepted';
    /** A human clicked the dismiss cross. */
    public const DISMISSED = 'dismissed';
    /** The suggestion was already what the ticket said. Not a decision. */
    public const MATCHED   = 'matched';

    /** The four fields triage proposes, and the outcome column for each. */
    public const FIELDS = [
        'itilcategories_id'      => 'category_outcome',
        'urgency'                => 'urgency_outcome',
        'impact'                 => 'impact_outcome',
        'plugin_glpisop_sops_id' => 'sop_outcome',
    ];

    /**
     * Queue a ticket for triage.
     *
     * Deliberately does not call a provider. This runs inside the request that
     * created the ticket — which, for anything from the self-service portal, is a
     * person waiting on a submit button — and a provider round trip there would
     * put a vendor's latency in front of a requester. The cron task picks it up
     * within minutes, and the panel offers to run it immediately for anyone who
     * arrives first.
     */
    public static function queue(int $tickets_id, int $entities_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (self::forTicket($tickets_id) !== null) {
            return;
        }

        $DB->insert(self::TABLE, [
            'tickets_id'    => $tickets_id,
            'entities_id'   => $entities_id,
            'state'         => self::PENDING,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'date_mod'      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function forTicket(int $tickets_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['tickets_id' => $tickets_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * The oldest queued tickets, for the cron task.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pending(int $limit): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['state' => self::PENDING],
                'ORDER' => 'id ASC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    public static function countPending(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        return (int) ($DB->request([
            'SELECT' => ['COUNT' => 'id AS n'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['state' => self::PENDING],
        ])->current()['n'] ?? 0);
    }

    /** @param array<string,mixed> $values */
    public static function store(int $id, array $values): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $values['id']       = $id;
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

    /**
     * Record what a human decided about one field.
     *
     * The deciding user is stamped once, on the first decision. A suggestion is
     * one technician's triage pass, not four independent votes, and recording
     * the last clicker would attribute the whole record to whoever happened to
     * dismiss the leftovers.
     */
    public static function decide(int $id, string $field, string $outcome): bool
    {
        $column = self::FIELDS[$field] ?? null;
        if ($column === null) {
            return false;
        }

        $row = self::byId($id);
        if ($row === null) {
            return false;
        }

        $values = [$column => $outcome];

        if ((int) $row['users_id_decided'] === 0 && $outcome !== self::MATCHED) {
            $values['users_id_decided'] = (int) Session::getLoginUserID();
            $values['date_decided']     = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }

        self::store($id, $values);

        return true;
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

    public static function forget(int $tickets_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, ['tickets_id' => $tickets_id]);
    }

    /**
     * Accept rate per field, over suggestions somebody actually decided.
     *
     * `matched` is excluded from both halves rather than counted as a win: it
     * measures GLPI's defaults agreeing with the model, which is not the thing
     * being asked about. `open` is excluded too — an undecided suggestion is
     * not evidence either way, and letting it dilute the denominator would make
     * the rate drift downwards purely because tickets are still fresh.
     *
     * @return array<string,array{accepted:int,dismissed:int,matched:int,rate:?float}>
     */
    public static function accuracy(?int $entities_id = null): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['state' => self::READY];
        if ($entities_id !== null) {
            $where['entities_id'] = $entities_id;
        }

        $out = [];
        foreach (self::FIELDS as $field => $column) {
            $counts = ['accepted' => 0, 'dismissed' => 0, 'matched' => 0];

            foreach (
                $DB->request([
                    'SELECT'  => [$column, 'COUNT' => 'id AS n'],
                    'FROM'    => self::TABLE,
                    'WHERE'   => $where,
                    'GROUPBY' => [$column],
                ]) as $row
            ) {
                $outcome = (string) $row[$column];
                if (isset($counts[$outcome])) {
                    $counts[$outcome] = (int) $row['n'];
                }
            }

            $decided = $counts['accepted'] + $counts['dismissed'];
            // Cast, because PHP's `/` returns an int when the division happens
            // to be exact — one accepted and none dismissed gives int(1), not
            // 1.0, and the declared return type would be a lie for exactly the
            // numbers a small sample produces.
            $out[$field] = $counts + [
                'rate' => $decided > 0 ? (float) ($counts['accepted'] / $decided) : null,
            ];
        }

        return $out;
    }
}
