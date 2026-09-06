<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Reply;

use CommonITILObject;
use ITILFollowup;
use Session;

/**
 * One review of one drafted reply, and what happened to that reply afterwards.
 *
 * The other features here are measured by a click: a triage chip is accepted
 * or dismissed, a draft is inserted or discarded. A review has no click to
 * count — nothing is applied, nothing is inserted, and a technician who reads
 * a flag and decides it is wrong does exactly what a technician who never saw
 * it does. Counting "reviews run" would measure the button, not the feature.
 *
 * So the outcome is read from the reply itself. The text that was reviewed is
 * fingerprinted, and when a followup is posted to the same item by the same
 * person the fingerprints are compared: **was the reply edited between being
 * reviewed and being sent?** Against the flagged reviews, that is as close to
 * "did this catch something real" as this feature can get without asking
 * anybody to rate it.
 *
 * The fingerprint is of the normalised plain text, so a formatting change or a
 * reflowed line does not read as an edit, and it is a hash rather than a copy:
 * this table says that a reply changed, never what it said. The reply is
 * already on the ticket for anybody entitled to read it, and a second copy
 * living in a plugin's audit table is one nobody would think to look for when
 * a customer asks what was written about them.
 */
final class Review
{
    public const TABLE = 'glpi_plugin_glpiai_replyreviews';

    /** How long after a review a posted reply is still plausibly that reply. */
    private const WINDOW_SECONDS = 7200;

    /**
     * Fingerprint of what a reply says, ignoring how it is marked up.
     *
     * Tags out, entities decoded, whitespace collapsed, case kept — case is
     * part of tone, and "PLEASE REBOOT" being softened is exactly the kind of
     * edit worth counting.
     */
    public static function fingerprint(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return hash('sha256', $text);
    }

    /**
     * @param array<int,array<string,string>> $flags
     */
    public static function record(
        string $itemtype,
        int $items_id,
        int $entities_id,
        string $verdict,
        array $flags,
        string $text,
        string $provider,
        string $model
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        $kinds = [];
        foreach ($flags as $flag) {
            $kind = (string) ($flag['kind'] ?? '');
            if ($kind !== '' && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        $DB->insert(self::TABLE, [
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            'entities_id'   => $entities_id,
            'users_id'      => (int) Session::getLoginUserID(),
            'verdict'       => mb_substr($verdict, 0, 16),
            'flag_count'    => count($flags),
            'kinds'         => mb_substr(implode(',', $kinds), 0, 255),
            'text_hash'     => self::fingerprint($text),
            'text_length'   => mb_strlen(strip_tags($text)),
            'provider'      => mb_substr($provider, 0, 64),
            'model'         => mb_substr($model, 0, 128),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        return (int) $DB->insertId();
    }

    /**
     * A followup was posted: close off the review it answers, if there is one.
     *
     * Matched on item and author within a window rather than by anything
     * carried through the form, because the reply that gets posted is not
     * necessarily the one that was reviewed — somebody can review, wander off,
     * rewrite from scratch and post. Matching the person and the item is the
     * claim that can actually be made, and the window keeps yesterday's review
     * from being closed by today's reply.
     *
     * Never throws and never blocks the followup. This is a measurement, and a
     * measurement that can stop a technician answering a customer is a
     * measurement that has to be removed.
     */
    public static function observeSend(ITILFollowup $followup): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        try {
            $itemtype = (string) ($followup->fields['itemtype'] ?? '');
            $items_id = (int) ($followup->fields['items_id'] ?? 0);
            $users_id = (int) ($followup->fields['users_id'] ?? 0);

            if ($itemtype === '' || $items_id <= 0 || $users_id <= 0) {
                return;
            }

            $row = null;
            foreach (
                $DB->request([
                    'FROM'  => self::TABLE,
                    'WHERE' => [
                        'itemtype' => $itemtype,
                        'items_id' => $items_id,
                        'users_id' => $users_id,
                        'sent'     => 0,
                        'date_creation' => [
                            '>=',
                            date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS),
                        ],
                    ],
                    'ORDER' => 'id DESC',
                    'LIMIT' => 1,
                ]) as $found
            ) {
                $row = $found;
            }

            if ($row === null) {
                return;
            }

            $now = self::fingerprint((string) ($followup->fields['content'] ?? ''));

            $DB->update(self::TABLE, [
                'sent'      => 1,
                'changed'   => $now === (string) $row['text_hash'] ? 0 : 1,
                'date_sent' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);
        } catch (\Throwable) {
            // Deliberately silent. See the docblock: nothing about counting
            // this may interfere with somebody answering a customer.
        }
    }

    /**
     * The numbers, for the settings page.
     *
     * `edited` is against *flagged* reviews only. A clean review followed by an
     * edited reply says nothing — people edit their own writing — and folding
     * those in would produce a figure that looks like accuracy and is mostly
     * measuring typing.
     *
     * @return array{reviews:int,flagged:int,sent:int,edited:int,rate:?float,kinds:array<string,int>}
     */
    public static function summary(?int $entities_id = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [
            'reviews' => 0,
            'flagged' => 0,
            'sent'    => 0,
            'edited'  => 0,
            'rate'    => null,
            'kinds'   => [],
        ];

        $where = $entities_id !== null ? ['entities_id' => $entities_id] : [];

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            $out['reviews']++;

            $flagged = (int) $row['flag_count'] > 0;
            if ($flagged) {
                $out['flagged']++;
                foreach (explode(',', (string) $row['kinds']) as $kind) {
                    if ($kind !== '') {
                        $out['kinds'][$kind] = ($out['kinds'][$kind] ?? 0) + 1;
                    }
                }
            }

            if ((int) $row['sent'] === 1) {
                $out['sent']++;
                if ($flagged && (int) $row['changed'] === 1) {
                    $out['edited']++;
                }
            }
        }

        // Only over the flagged reviews whose reply was actually posted: a
        // review whose reply never got sent has no outcome yet, and counting
        // it as "not acted on" would make the figure drift down over an
        // afternoon of half-written replies.
        $decided = 0;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where + ['sent' => 1]]) as $row) {
            if ((int) $row['flag_count'] > 0) {
                $decided++;
            }
        }
        if ($decided > 0) {
            $out['rate'] = $out['edited'] / $decided;
        }

        return $out;
    }

    /** Forget the reviews of an item that has been purged. */
    public static function forget(CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'itemtype' => $item->getType(),
            'items_id' => (int) $item->getID(),
        ]);
    }
}
