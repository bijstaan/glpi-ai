<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use CartridgeItem;
use Computer;
use ConsumableItem;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Infocom;
use Software;

/**
 * Three questions about the estate that the asset tools cannot answer.
 *
 * `read_asset` reads one machine's record. It says nothing about what is
 * *installed* on it, nothing about when its warranty runs out, and nothing
 * about whether the printer beside it has any toner — and all three are
 * ordinary helpdesk questions with an exact answer sitting in GLPI.
 *
 *  - **`software_inventory`** answers both directions of the same question:
 *    what is on this machine, and which machines have this thing on them. The
 *    second is the one that matters during an incident ("who else is running
 *    the build that broke") and is not reachable through any search this
 *    plugin offers.
 *  - **`asset_lifecycle`** is the money and warranty side of an asset —
 *    bought when, from whom, under warranty until when, and worth what. It
 *    also answers it estate-wide, because "what is going out of warranty this
 *    quarter" is a question somebody asks once a quarter and answers by hand.
 *  - **`supply_levels`** is toner and consumables. Unglamorous, asked
 *    constantly, and the alarm threshold an administrator already configured
 *    is the difference between "eleven left" and "eleven left, and that is
 *    below the point somebody decided to reorder".
 *
 * Money is behind its own right throughout: `asset_lifecycle` returns purchase
 * values only where the profile holds the `infocom` right, which is GLPI's own
 * boundary for exactly this and not one to re-decide here.
 */
final class Estate
{
    /** Rows before a list becomes a data dump. */
    private const LIMIT = 40;

    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::software(), self::lifecycle(), self::supplies()];
    }

    // ------------------------------------------------------------- software

    private static function software(): Tool
    {
        return new Tool(
            name: 'software_inventory',
            description: 'What software is installed where. Give an asset to list what is on it '
                . 'with each version; give a software name to find every machine running it, '
                . 'broken down by version, with the licence position — how many are owned '
                . 'against how many are installed. Use it for "what version of X is this '
                . 'machine on", "who else is running X", "are we short of licences for X", and '
                . 'to find the blast radius of a bad update.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'name'     => [
                        'type'        => 'string',
                        'description' => 'Software name, or part of one. Finds installations '
                            . 'across the estate.',
                    ],
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'With items_id: list what is installed on this asset. '
                            . 'Usually Computer.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'The asset id, with itemtype. Omit both to use the '
                            . 'asset the conversation is about.',
                    ],
                ],
            ],
            handler: [self::class, 'runSoftware'],
            right: 'software',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runSoftware(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $name     = trim((string) ($arguments['name'] ?? ''));
        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($name === '' && $items_id <= 0 && $context->items_id !== null && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        if ($name !== '') {
            return self::byName($name, $context);
        }

        $itemtype = $itemtype !== '' ? $itemtype : Computer::class;

        return self::onItem($itemtype, $items_id);
    }

    /** @return array<string,mixed> */
    private static function onItem(string $itemtype, int $items_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $item = $items_id > 0 ? getItemForItemtype($itemtype) : false;

        if ($item === false || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException(
                'Name an asset: give itemtype and items_id, or a software name to search for.'
            );
        }

        $rows = [];

        foreach (
            $DB->request([
                'SELECT' => [
                    'glpi_softwares.name AS software',
                    'glpi_softwareversions.name AS version',
                    'glpi_items_softwareversions.date_install',
                ],
                'FROM'   => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            'glpi_items_softwareversions' => 'softwareversions_id',
                            'glpi_softwareversions'       => 'id',
                        ],
                    ],
                    'glpi_softwares' => [
                        'ON' => [
                            'glpi_softwareversions' => 'softwares_id',
                            'glpi_softwares'        => 'id',
                        ],
                    ],
                ],
                'WHERE'  => [
                    'glpi_items_softwareversions.itemtype'   => $itemtype,
                    'glpi_items_softwareversions.items_id'   => $items_id,
                    'glpi_items_softwareversions.is_deleted' => 0,
                    'glpi_softwares.is_deleted'              => 0,
                ],
                'ORDER'  => ['glpi_softwares.name ASC'],
            ]) as $row
        ) {
            $rows[] = array_filter([
                'name'      => (string) $row['software'],
                'version'   => (string) $row['version'],
                'installed' => self::date($row['date_install'] ?? null),
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        $total = count($rows);

        return array_filter([
            'asset'     => [
                'itemtype' => $itemtype,
                'id'       => $items_id,
                'name'     => (string) ($item->fields['name'] ?? ''),
            ],
            'installed' => array_slice($rows, 0, self::LIMIT),
            'count'     => $total,
            'note'      => $total === 0
                ? 'Nothing is recorded as installed. That usually means the machine has never '
                    . 'been inventoried rather than that it is empty.'
                : ($total > self::LIMIT
                    ? sprintf('%d packages in all; the first %d are listed.', $total, self::LIMIT)
                    : null),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /** @return array<string,mixed> */
    private static function byName(string $name, ToolContext $context): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entities = self::entities($context);
        $found    = [];

        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'entities_id'],
                'FROM'   => Software::getTable(),
                'WHERE'  => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                    'name'        => ['LIKE', '%' . $name . '%'],
                ],
                'ORDER'  => ['name ASC'],
                'LIMIT'  => 10,
            ]) as $row
        ) {
            $software = new Software();
            $software->fields = $row;
            if (!$software->canViewItem()) {
                continue;
            }

            $found[] = self::softwarePosition((int) $row['id'], (string) $row['name'], $entities);
        }

        if ($found === []) {
            throw new ToolException(
                "No software matching \"$name\" is visible to you. Try a shorter fragment of the "
                . 'name — the publisher\'s spelling is often not the one people use.'
            );
        }

        return [
            'matched' => $found,
            'note'    => 'Installation counts are of machines in this entity and below. A '
                . 'licence count of zero means none is recorded in GLPI, not that none was '
                . 'bought.',
        ];
    }

    /**
     * One software title: where it is, and whether it is paid for.
     *
     * @param int[] $entities
     * @return array<string,mixed>
     */
    private static function softwarePosition(int $softwares_id, string $name, array $entities): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $versions   = [];
        $installs   = 0;
        $machines   = [];

        foreach (
            $DB->request([
                'SELECT' => [
                    'glpi_softwareversions.name AS version',
                    'glpi_items_softwareversions.itemtype',
                    'glpi_items_softwareversions.items_id',
                ],
                'FROM'   => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            'glpi_items_softwareversions' => 'softwareversions_id',
                            'glpi_softwareversions'       => 'id',
                        ],
                    ],
                ],
                'WHERE'  => [
                    'glpi_softwareversions.softwares_id'     => $softwares_id,
                    'glpi_items_softwareversions.is_deleted' => 0,
                    'glpi_items_softwareversions.entities_id' => $entities,
                ],
            ]) as $row
        ) {
            $version = (string) $row['version'];
            $versions[$version] = ($versions[$version] ?? 0) + 1;
            $installs++;

            if (count($machines) < self::LIMIT) {
                $item = getItemForItemtype((string) $row['itemtype']);
                if ($item !== false && $item->getFromDB((int) $row['items_id']) && $item->canViewItem()) {
                    $machines[] = [
                        'itemtype' => (string) $row['itemtype'],
                        'id'       => (int) $row['items_id'],
                        'name'     => (string) ($item->fields['name'] ?? ''),
                        'version'  => $version,
                    ];
                }
            }
        }

        arsort($versions);

        $licensed = 0;
        foreach (
            $DB->request([
                'SELECT' => ['number'],
                'FROM'   => 'glpi_softwarelicenses',
                'WHERE'  => [
                    'softwares_id' => $softwares_id,
                    'is_deleted'   => 0,
                    'is_template'  => 0,
                    'entities_id'  => $entities,
                ],
            ]) as $row
        ) {
            $number = (int) $row['number'];

            // -1 is GLPI's "unlimited". Adding it as a negative would report a
            // site licence as a shortfall, which is the wrong way round.
            if ($number < 0) {
                $licensed = -1;
                break;
            }

            $licensed += $number;
        }

        return array_filter([
            'software'    => $name,
            'id'          => $softwares_id,
            'installed_on' => $installs,
            'versions'    => $versions,
            'licences'    => $licensed < 0 ? 'unlimited' : $licensed,
            'shortfall'   => $licensed >= 0 && $installs > $licensed ? $installs - $licensed : null,
            'machines'    => $machines,
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    // ------------------------------------------------------------ lifecycle

    private static function lifecycle(): Tool
    {
        return new Tool(
            name: 'asset_lifecycle',
            description: 'The purchase and warranty side of an asset: when it was bought, from '
                . 'which supplier, on what order, how long the warranty runs and when it '
                . 'expires, and what it cost. Give an asset for one, or ask for everything '
                . 'expiring within so many days to find what is coming out of warranty. Use it '
                . 'before promising a repair under warranty, when deciding whether to fix or '
                . 'replace, and for renewal and budget questions.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'The asset kind, e.g. Computer, Printer, Monitor.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'The asset id. Omit both to use the asset the '
                            . 'conversation is about.',
                    ],
                    'expiring_within_days' => [
                        'type'        => 'integer',
                        'description' => 'Instead of one asset: everything in this entity whose '
                            . 'warranty expires within this many days.',
                    ],
                ],
            ],
            handler: [self::class, 'runLifecycle'],
            right: 'infocom',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runLifecycle(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        if (isset($arguments['expiring_within_days'])) {
            return self::expiring(max(0, (int) $arguments['expiring_within_days']), $context);
        }

        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context->items_id !== null && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        $item = $items_id > 0 && $itemtype !== '' ? getItemForItemtype($itemtype) : false;

        if ($item === false || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException(
                'Name an asset with itemtype and items_id, or ask for what is expiring within so '
                . 'many days.'
            );
        }

        $infocom = new Infocom();
        if (!$infocom->getFromDBforDevice($itemtype, $items_id)) {
            return [
                'asset' => ['itemtype' => $itemtype, 'id' => $items_id,
                    'name' => (string) ($item->fields['name'] ?? '')],
                'note'  => 'Nothing financial is recorded against this asset — no purchase date, '
                    . 'no warranty, no supplier. That is an absence of data rather than an '
                    . 'expired warranty, and worth saying in those words.',
            ];
        }

        return array_filter([
            'asset'    => [
                'itemtype' => $itemtype,
                'id'       => $items_id,
                'name'     => (string) ($item->fields['name'] ?? ''),
                'serial'   => (string) ($item->fields['serial'] ?? ''),
            ],
            'bought'   => self::date($infocom->fields['buy_date'] ?? null),
            'in_use_since' => self::date($infocom->fields['use_date'] ?? null),
            'delivered' => self::date($infocom->fields['delivery_date'] ?? null),
            'supplier' => self::dropdownName('glpi_suppliers', (int) ($infocom->fields['suppliers_id'] ?? 0)),
            'order_number' => (string) ($infocom->fields['order_number'] ?? ''),
            'warranty' => self::warranty($infocom->fields),
            'value'    => self::money($infocom->fields['value'] ?? null),
            'warranty_value' => self::money($infocom->fields['warranty_value'] ?? null),
            'budget'   => self::dropdownName('glpi_budgets', (int) ($infocom->fields['budgets_id'] ?? 0)),
            'decommissioned' => self::date($infocom->fields['decommission_date'] ?? null),
            'comment'  => Lookup::plain((string) ($infocom->fields['comment'] ?? ''), 800),
        ], static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Warranty as a date and a verdict, not as a duration to be added up.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function warranty(array $fields): array
    {
        $months = (int) ($fields['warranty_duration'] ?? 0);

        if ($months === -1) {
            return ['expires' => 'never', 'in_warranty' => true,
                'info' => (string) ($fields['warranty_info'] ?? '')];
        }

        // `warranty_date` is when the warranty *starts*, not when it ends —
        // GLPI's own autofill copies the purchase date into it, so reading it
        // as an expiry reports every machine as out of warranty on the day it
        // was bought. The expiry is that date plus the duration.
        $start = self::date($fields['warranty_date'] ?? null)
            ?? self::date($fields['buy_date'] ?? null)
            ?? self::date($fields['delivery_date'] ?? null);

        $expires = $start !== null && $months > 0
            ? date('Y-m-d', strtotime($start . " +$months months"))
            : null;

        if ($expires === null) {
            return array_filter(['info' => (string) ($fields['warranty_info'] ?? '')]);
        }

        $today = date('Y-m-d');

        return array_filter([
            'months'      => $months > 0 ? $months : null,
            'starts'      => $start,
            'expires'     => $expires,
            'in_warranty' => $expires >= $today,
            'days_left'   => $expires >= $today
                ? (int) floor((strtotime($expires) - strtotime($today)) / 86400)
                : null,
            'expired_on'  => $expires < $today ? $expires : null,
            'info'        => (string) ($fields['warranty_info'] ?? ''),
        ], static fn($v): bool => $v !== null && $v !== '');
    }

    /** @return array<string,mixed> */
    private static function expiring(int $days, ToolContext $context): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entities = self::entities($context);
        $today    = date('Y-m-d');
        $deadline = date('Y-m-d', strtotime("+$days days"));

        $rows = [];

        foreach (
            $DB->request([
                'FROM'  => Infocom::getTable(),
                'WHERE' => ['entities_id' => $entities],
            ]) as $row
        ) {
            $warranty = self::warranty($row);
            $expires  = (string) ($warranty['expires'] ?? '');

            if ($expires === '' || $expires === 'never' || $expires < $today || $expires > $deadline) {
                continue;
            }

            $item = getItemForItemtype((string) $row['itemtype']);
            if ($item === false || !$item->getFromDB((int) $row['items_id']) || !$item->canViewItem()) {
                continue;
            }

            $rows[] = [
                'itemtype' => (string) $row['itemtype'],
                'id'       => (int) $row['items_id'],
                'name'     => (string) ($item->fields['name'] ?? ''),
                'expires'  => $expires,
                'supplier' => self::dropdownName('glpi_suppliers', (int) ($row['suppliers_id'] ?? 0)),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['expires'], $b['expires']));

        $total = count($rows);

        return array_filter([
            'window'   => ['from' => $today, 'to' => $deadline],
            'expiring' => array_slice($rows, 0, self::LIMIT),
            'count'    => $total,
            'note'     => $total === 0
                ? 'Nothing recorded expires in that window. Assets with no purchase record at '
                    . 'all are not counted here and are the usual reason for an empty answer.'
                : ($total > self::LIMIT
                    ? sprintf('%d in all; the %d soonest are listed.', $total, self::LIMIT)
                    : null),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    // -------------------------------------------------------------- supplies

    private static function supplies(): Tool
    {
        return new Tool(
            name: 'supply_levels',
            description: 'Toner, ink and consumable stock: how many of each are left unused, '
                . 'which are at or below the reorder threshold somebody set, and which printers '
                . 'a cartridge fits. Use it for "have we got toner for that printer", before '
                . 'sending somebody to site to swap one, and to answer a low-supply alert.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'low_only' => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Only stock at or below its alarm threshold. Defaults '
                            . 'to yes.',
                    ],
                    'name'     => [
                        'type'        => 'string',
                        'description' => 'Narrow to cartridges or consumables whose name or '
                            . 'reference contains this.',
                    ],
                ],
            ],
            handler: [self::class, 'runSupplies'],
            // The narrower of the two the tool reads. A profile with cartridge
            // but not consumable rights still gets its cartridges: the item
            // check below drops the half it may not see.
            right: 'cartridge',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runSupplies(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $low   = strtolower((string) ($arguments['low_only'] ?? 'yes')) !== 'no';
        $name  = trim((string) ($arguments['name'] ?? ''));
        $scope = self::entities($context);

        $out = [];

        foreach (
            [
                ['class' => CartridgeItem::class, 'stock' => 'glpi_cartridges',
                    'fk' => 'cartridgeitems_id', 'kind' => 'cartridge'],
                ['class' => ConsumableItem::class, 'stock' => 'glpi_consumables',
                    'fk' => 'consumableitems_id', 'kind' => 'consumable'],
            ] as $source
        ) {
            /** @var class-string<\CommonDBTM> $class */
            $class = $source['class'];

            if (!$class::canView()) {
                continue;
            }

            $criteria = ['is_deleted' => 0, 'entities_id' => $scope];
            if ($name !== '') {
                $criteria[] = ['OR' => [
                    ['name' => ['LIKE', '%' . $name . '%']],
                    ['ref'  => ['LIKE', '%' . $name . '%']],
                ]];
            }

            foreach (getAllDataFromTable($class::getTable(), $criteria, false, 'name') as $row) {
                $left = (int) countElementsInTable($source['stock'], [
                    $source['fk'] => (int) $row['id'],
                    'date_out'    => null,
                ]);

                $threshold = (int) ($row['alarm_threshold'] ?? 0);
                $is_low    = $threshold >= 0 && $left <= $threshold;

                if ($low && !$is_low) {
                    continue;
                }

                $out[] = array_filter([
                    'kind'      => $source['kind'],
                    'name'      => (string) $row['name'],
                    'reference' => (string) ($row['ref'] ?? ''),
                    'in_stock'  => $left,
                    'reorder_at' => $threshold >= 0 ? $threshold : null,
                    'low'       => $is_low ?: null,
                    'location'  => self::dropdownName('glpi_locations', (int) ($row['locations_id'] ?? 0)),
                    'fits'      => $source['kind'] === 'cartridge'
                        ? self::printers((int) $row['id'])
                        : null,
                ], static fn($v): bool => $v !== null && $v !== '');
            }
        }

        // Emptiest first: the list is read for what is about to run out.
        usort($out, static fn(array $a, array $b): int => $a['in_stock'] <=> $b['in_stock']);

        return array_filter([
            'stock' => array_slice($out, 0, self::LIMIT),
            'note'  => $out === []
                ? ($low
                    ? 'Nothing is at or below its reorder threshold.'
                    : 'No cartridges or consumables are recorded for this entity.')
                : 'Counts are of unused stock. A threshold of zero means nobody set one, so '
                    . '"low" there means genuinely none left.',
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * The printer models a cartridge is declared to fit.
     *
     * @return string[]
     */
    private static function printers(int $cartridgeitems_id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable('glpi_cartridgeitems_printermodels', [
                'cartridgeitems_id' => $cartridgeitems_id,
            ]) as $row
        ) {
            $name = \Dropdown::getDropdownName('glpi_printermodels', (int) $row['printermodels_id']);
            if (is_string($name) && $name !== '' && $name !== '&nbsp;') {
                $out[] = $name;
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------- bits

    /**
     * The conversation's entity and below, intersected with the session's.
     *
     * @return int[]
     */
    private static function entities(ToolContext $context): array
    {
        $wanted = array_map('intval', \getSonsOf('glpi_entities', $context->entities_id));
        $wanted[] = $context->entities_id;

        $session = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));

        return array_values(array_unique(array_intersect($wanted, $session)));
    }

    private static function date(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : substr($value, 0, 10);
    }

    private static function money(mixed $value): ?string
    {
        if (!is_numeric($value) || (float) $value == 0.0) {
            return null;
        }

        return \Html::formatNumber((float) $value);
    }

    private static function dropdownName(string $table, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $name = \Dropdown::getDropdownName($table, $id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : null;
    }
}
