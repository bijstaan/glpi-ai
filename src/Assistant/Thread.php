<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Assistant;

use DBmysql;
use Session;

/**
 * One conversation, and its transcript.
 *
 * Persisted rather than kept in the PHP session, for two reasons that pull the
 * same way. A troubleshooting conversation outlives a page: the panel opens
 * over whatever the technician is looking at, and they will navigate — to the
 * asset, to a similar ticket, to the osquery console — while it is open.
 * Losing the thread on every navigation would make follow-up questions
 * impossible, which is most of what a conversation is.
 *
 * The second reason is that "what did the AI tell somebody" is a question an
 * MSP will eventually be asked, by a client or by itself. The usage log records
 * that a call happened and the tool log records what it reached for; without
 * this, neither records what was actually said.
 *
 * The transcript is JSON in one column rather than a row per message. A message
 * is never queried independently of its thread, never updated, and never
 * counted — three properties that make a table the wrong shape for it.
 */
final class Thread
{
    public const TABLE = 'glpi_plugin_glpiai_threads';

    /** Turns kept in a thread. Beyond this the oldest are dropped from the prompt. */
    public const MAX_TURNS = 20;

    /**
     * Find or start this user's thread for a given context.
     *
     * Keyed by user *and* context, so opening the panel on a ticket resumes the
     * conversation about that ticket rather than whatever was being discussed
     * on the last page. A conversation about one machine is rarely a useful
     * starting point for a question about another.
     */
    public static function open(string $itemtype, int $items_id, int $entities_id): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $users_id = (int) Session::getLoginUserID();
        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => [
                    'users_id' => $users_id,
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ],
                'ORDER' => 'date_mod DESC',
                'LIMIT' => 1,
            ]) as $row
        ) {
            return (int) $row['id'];
        }

        $DB->insert(self::TABLE, [
            'users_id'      => $users_id,
            'entities_id'   => $entities_id,
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            'messages'      => '[]',
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * This user's most recent conversations, newest first.
     *
     * For the app's history list, which the web panel has no equivalent of:
     * a browser keeps the panel open across navigation, and a phone does not,
     * so resuming yesterday's thread has to be something you can go and find.
     *
     * Scoped to the caller in the query rather than filtered afterwards —
     * ownership is the whole access rule here, and a list is exactly where a
     * forgotten filter would be least visible.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['users_id' => (int) Session::getLoginUserID()],
                'ORDER' => 'date_mod DESC',
                'LIMIT' => max(1, min(100, $limit)),
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
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
     * A thread the current user owns, or null.
     *
     * Ownership rather than a right: a conversation is one person's working
     * notes, and a technician's half-formed questions about a machine are not
     * something a colleague should be able to read by guessing an id.
     *
     * @return array<string,mixed>|null
     */
    public static function mine(int $id): ?array
    {
        $row = self::byId($id);

        return $row !== null && (int) $row['users_id'] === (int) Session::getLoginUserID()
            ? $row
            : null;
    }

    /**
     * The transcript.
     *
     * @return array<int,array{role:string,content:string,trail?:string}>
     */
    public static function messages(int $id): array
    {
        $row = self::byId($id);
        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row['messages'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $message */
    public static function append(int $id, array $message): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $messages   = self::messages($id);
        $messages[] = $message;

        // Trimmed from the front. The oldest turns of a troubleshooting thread
        // are the least useful — the question has usually moved on — and an
        // unbounded transcript makes every subsequent turn more expensive than
        // the last for no benefit.
        if (count($messages) > self::MAX_TURNS * 2) {
            $messages = array_slice($messages, -(self::MAX_TURNS * 2));
        }

        $DB->update(self::TABLE, [
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Name the thread from its first question.
     *
     * Only ever set once. A title that changed as the conversation wandered
     * would make the history list unrecognisable from one visit to the next.
     */
    public static function nameFrom(int $id, string $question): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = self::byId($id);
        if ($row === null || trim((string) $row['title']) !== '') {
            return;
        }

        $DB->update(self::TABLE, [
            'title' => mb_substr(trim(preg_replace('/\s+/', ' ', $question) ?? ''), 0, 120),
        ], ['id' => $id]);
    }

    public static function clear(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'messages' => '[]',
            'title'    => '',
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Drop conversations older than the retention period.
     *
     * @return int rows removed
     */
    public static function prune(int $days): int
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        $DB->delete(self::TABLE, [
            'date_mod' => ['<', date('Y-m-d H:i:s', strtotime("-$days days"))],
        ]);

        return $DB->affectedRows();
    }

    /** Threads about an item go with it. */
    public static function forgetItem(string $itemtype, int $items_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, ['itemtype' => $itemtype, 'items_id' => $items_id]);
    }
}
