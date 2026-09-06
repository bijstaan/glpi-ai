<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Triage;

use DBmysql;
use Plugin;
use Ticket;

/**
 * What a model is allowed to choose from, for one entity.
 *
 * This is the *stable* half of a triage prompt: it depends on the entity and on
 * the administrator's taxonomy, not on the ticket. That is why it is built
 * separately and put in the system instruction rather than inlined with the
 * ticket text — it is identical across every ticket in an entity, which is
 * exactly the shape a provider's prompt cache is designed to make cheap. The
 * ticket itself, the part that changes every call, goes last.
 *
 * It is also the allowlist. Nothing the model returns is trusted; a category id
 * is accepted only if it came from here, which means a hallucinated id fails
 * closed as "no suggestion" rather than writing a category that does not exist
 * — or, worse, one from another customer's tree.
 */
final class Taxonomy
{
    /** Longer than this and a taxonomy is not a prompt, it is a document. */
    private const MAX_CATEGORIES = 300;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Categories and procedures, for one entity.
     *
     * The entity's own name is deliberately not in here. It would be one more
     * customer identifier travelling to a vendor for no gain — the ticket text
     * already carries whatever context the model needs, and this string is sent
     * with every single call.
     *
     * @return array{categories:array<int,array{id:int,name:string,comment:string}>,
     *               sops:array<int,array{id:int,name:string}>}
     */
    public static function forEntity(int $entities_id): array
    {
        if (self::$cache !== null && self::$cache['entities_id'] === $entities_id) {
            return self::$cache['data'];
        }

        $data = [
            'categories' => self::categories($entities_id),
            'sops'       => self::sops($entities_id),
        ];

        self::$cache = ['entities_id' => $entities_id, 'data' => $data];

        return $data;
    }

    /**
     * The categories a ticket in this entity may actually be given.
     *
     * Scoped with GLPI's own recursion rule — an entity sees its own categories
     * plus the recursive ones above it — rather than a flat "everything". Two
     * customers under one instance have separate taxonomies, and a model
     * offered the union would confidently file one customer's ticket under
     * another's category.
     *
     * @return array<int,array{id:int,name:string,comment:string}>
     */
    private static function categories(int $entities_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'completename', 'comment'],
                'FROM'   => 'glpi_itilcategories',
                // Either flag, not just incidents: the taxonomy has to be the
                // same string for every ticket in the entity for a provider's
                // prompt cache to hit, and narrowing it per ticket type would
                // produce two variants that each miss the other's cache.
                'WHERE'  => [
                    'OR' => ['is_incident' => 1, 'is_request' => 1],
                ] + getEntitiesRestrictCriteria('glpi_itilcategories', 'entities_id', $entities_id, true),
                'ORDER'  => 'completename ASC',
                'LIMIT'  => self::MAX_CATEGORIES,
            ]) as $row
        ) {
            $out[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['completename'],
                'comment' => trim((string) ($row['comment'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * glpi-sop's active procedures for tickets, when that plugin is installed.
     *
     * Checked rather than assumed, and every reference is a string: this plugin
     * works perfectly well without glpi-sop, and a hard dependency for one
     * optional chip would be a poor trade.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private static function sops(int $entities_id): array
    {
        if (!Plugin::isPluginActive('glpisop') || !class_exists('GlpiPlugin\\Glpisop\\Sop')) {
            return [];
        }

        $out = [];
        foreach ((array) call_user_func(
            ['GlpiPlugin\\Glpisop\\Sop', 'activeFor'],
            Ticket::class,
            $entities_id
        ) as $sop) {
            $id   = (int) ($sop['id'] ?? 0);
            $name = (string) ($sop['name'] ?? '');

            if ($id > 0 && $name !== '') {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }

        return $out;
    }

    /**
     * The system instruction: the vocabulary, the scales, and the rules.
     *
     * Written to be identical for every ticket in an entity, byte for byte, so
     * that a provider offering prompt caching can charge for it once.
     */
    public static function instruction(int $entities_id): string
    {
        $taxonomy = self::forEntity($entities_id);

        $lines = [
            'You are triaging an IT support ticket for a managed service provider.',
            'Your suggestions are shown to a technician as chips they may accept or dismiss.',
            'You never change the ticket yourself and you never write anything a customer sees.',
            '',
            'Choose a category from this list, by id. These are the only valid ids:',
        ];

        foreach ($taxonomy['categories'] as $category) {
            $lines[] = sprintf(
                '  %d. %s%s',
                $category['id'],
                $category['name'],
                $category['comment'] !== '' ? ' — ' . $category['comment'] : ''
            );
        }

        $lines[] = '';
        $lines[] = 'Urgency is how badly the requester needs it fixed. Impact is how much of the';
        $lines[] = 'business is affected. Both use this scale:';
        foreach (self::scale() as $value => $label) {
            $lines[] = sprintf('  %d = %s', $value, $label);
        }

        if ($taxonomy['sops'] !== []) {
            $lines[] = '';
            $lines[] = 'If one of these procedures is the documented way to handle this ticket,';
            $lines[] = 'name it by id. Otherwise use 0.';
            foreach ($taxonomy['sops'] as $sop) {
                $lines[] = sprintf('  %d. %s', $sop['id'], $sop['name']);
            }
        }

        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '  - Use 0 for a category or procedure when nothing in the list fits. A wrong';
        $lines[] = '    category costs a technician more time than an absent one.';
        $lines[] = '  - Prefer the most specific category that clearly applies, not the parent.';
        $lines[] = '  - Judge urgency and impact from what the ticket says, not from how it is';
        $lines[] = '    phrased. An angry message about one mailbox is not high impact.';
        $lines[] = '  - Say low confidence when the ticket does not actually contain enough to';
        $lines[] = '    decide. A technician can act on "I do not know"; they cannot act on a';
        $lines[] = '    confident guess.';
        $lines[] = '  - reasoning is one short sentence, for a technician, explaining the choice.';

        return implode("\n", $lines);
    }

    /** GLPI's shared urgency/impact scale. @return array<int,string> */
    public static function scale(): array
    {
        return [
            1 => Ticket::getUrgencyName(1),
            2 => Ticket::getUrgencyName(2),
            3 => Ticket::getUrgencyName(3),
            4 => Ticket::getUrgencyName(4),
            5 => Ticket::getUrgencyName(5),
        ];
    }

    /** Is this a category the model was actually offered? */
    public static function hasCategory(int $entities_id, int $id): bool
    {
        foreach (self::forEntity($entities_id)['categories'] as $category) {
            if ($category['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** Is this a procedure the model was actually offered? */
    public static function hasSop(int $entities_id, int $id): bool
    {
        foreach (self::forEntity($entities_id)['sops'] as $sop) {
            if ($sop['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** Drop the per-entity cache; the settings page changes what is offered. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
