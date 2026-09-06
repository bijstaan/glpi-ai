<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Computer;
use Contract;
use Contract_Item;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Infocom;
use Item_OperatingSystem;
use Item_SoftwareVersion;
use Item_Ticket;
use Monitor;
use NetworkEquipment;
use Peripheral;
use Phone;
use Printer;
use Ticket;

/**
 * One asset, in the detail `find_asset` deliberately does not carry.
 *
 * `find_asset` turns a vague mention into a record and stops there — five
 * fields per row, because it may return twenty rows. This is the other half:
 * everything about one machine that a technician would otherwise open four
 * tabs for. The split is the same one GLPI's own UI makes between a list and a
 * form, and it exists for the same reason.
 *
 * What it puts together, and why each earns its tokens:
 *
 *  - **The warranty and the contracts.** "Is this still covered" decides
 *    whether the next step is a repair or a quote, and it is two clicks away in
 *    the UI and invisible to every other tool here.
 *  - **When it was last inventoried.** An agent that stopped reporting three
 *    weeks ago is the answer to a surprising number of "the software is not
 *    installed" tickets — the software is; the record is stale.
 *  - **The operating system and the installed software.** Version questions
 *    are most of desktop support, and a model that has to guess the build
 *    guesses the current one.
 *  - **The tickets already on it.** The same reasoning as read_user: the third
 *    fault on one laptop this month is a different conversation from the
 *    first.
 */
final class ReadAsset
{
    /** @var array<string,class-string> The same vocabulary find_asset uses. */
    private const TYPES = [
        'computer'          => Computer::class,
        'monitor'           => Monitor::class,
        'printer'           => Printer::class,
        'network_equipment' => NetworkEquipment::class,
        'phone'             => Phone::class,
        'peripheral'        => Peripheral::class,
    ];

    /** Installed software named in full. A full list is a page of noise. */
    private const MAX_SOFTWARE = 15;

    private const MAX_TICKETS = 8;

    public static function tool(): Tool
    {
        return new Tool(
            name: 'read_asset',
            description: 'Everything known about one asset: model and serial, who it is assigned '
                . 'to and where, its status, warranty and contracts, when it was last '
                . 'inventoried, its operating system, the software installed on it, and the '
                . 'tickets already raised against it. Use it after find_asset, or whenever a '
                . 'question turns on what a machine actually is — whether it is still under '
                . 'warranty, what version it runs, or whether it has been reported before.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => array_keys(self::TYPES),
                        'description' => 'The kind of asset, as find_asset returned it.',
                    ],
                    'id'       => ['type' => 'integer', 'description' => 'The asset id.'],
                    'software' => [
                        'type'        => 'boolean',
                        'description' => 'Include the installed software list. Defaults to true '
                            . 'for a computer and is ignored for anything else.',
                    ],
                ],
                'required'   => ['itemtype', 'id'],
            ],
            handler: [self::class, 'run'],
            right: 'computer'
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $kind = (string) ($arguments['itemtype'] ?? '');
        $id   = (int) ($arguments['id'] ?? 0);

        if (!isset(self::TYPES[$kind])) {
            throw new ToolException(
                'Unknown asset type. Use one of: ' . implode(', ', array_keys(self::TYPES)) . '.'
            );
        }

        $itemtype = self::TYPES[$kind];
        /** @var \CommonDBTM $asset */
        $asset = new $itemtype();

        if ($id <= 0 || !$asset->getFromDB($id) || !$asset->canViewItem()) {
            throw new ToolException("No $kind $id is visible to you.");
        }

        $fields = $asset->fields;

        $out = [
            'type'         => $kind,
            'id'           => $id,
            'name'         => (string) ($fields['name'] ?? ''),
            'status'       => self::dropdown('State', (int) ($fields['states_id'] ?? 0)),
            'serial'       => (string) ($fields['serial'] ?? ''),
            'inventory_number' => (string) ($fields['otherserial'] ?? ''),
            'manufacturer' => self::dropdown('Manufacturer', (int) ($fields['manufacturers_id'] ?? 0)),
            'model'        => self::modelName($itemtype, $fields),
            'assigned_to'  => self::userName((int) ($fields['users_id'] ?? 0)),
            'managed_by'   => self::userName((int) ($fields['users_id_tech'] ?? 0)),
            'group'        => self::dropdown('Group', (int) ($fields['groups_id'] ?? 0)),
            'location'     => self::dropdown('Location', (int) ($fields['locations_id'] ?? 0)),
            'entity'       => Lookup::entityName((int) ($fields['entities_id'] ?? 0)),
            'comment'      => Lookup::plain((string) ($fields['comment'] ?? ''), 800),
            'last_inventory' => (string) ($fields['last_inventory_update'] ?? ''),
            'operating_system' => self::operatingSystem($itemtype, $id),
            'lifecycle'    => self::lifecycle($itemtype, $id),
            'contracts'    => self::contracts($itemtype, $id),
            'tickets'      => self::tickets($itemtype, $id),
        ];

        if ($itemtype === Computer::class && ($arguments['software'] ?? true) !== false) {
            $out['software'] = self::software($id);
        }

        return array_filter(
            $out,
            static fn($v): bool => $v !== null && $v !== '' && $v !== []
        );
    }

    /**
     * Purchase, warranty and value, from the financial record.
     *
     * Gated on the Infocom right of its own: an MSP's helpdesk profile can
     * legitimately see a laptop and not what it cost. `Infocom::canView()` is
     * the same check the tab does, so a technician who cannot open that tab
     * does not get the numbers through here either.
     *
     * @return array<string,mixed>
     */
    private static function lifecycle(string $itemtype, int $id): array
    {
        if (!Infocom::canView()) {
            return [];
        }

        $infocom = new Infocom();
        if (!$infocom->getFromDBforDevice($itemtype, $id)) {
            return [];
        }

        $fields   = $infocom->fields;
        $warranty = (string) ($fields['warranty_date'] ?? '');
        $months   = (int) ($fields['warranty_duration'] ?? 0);

        $expiry = '';
        if ($warranty !== '' && $months > 0) {
            $expiry = date('Y-m-d', (int) strtotime("$warranty +$months months"));
        }

        return array_filter([
            'bought'          => (string) ($fields['buy_date'] ?? ''),
            'delivered'       => (string) ($fields['delivery_date'] ?? ''),
            'warranty_from'   => $warranty,
            'warranty_months' => $months > 0 ? $months : null,
            'warranty_until'  => $expiry,
            // Stated rather than left to be worked out. A model doing date
            // arithmetic on a warranty is a model that will occasionally tell a
            // customer their laptop is covered when it is not.
            'in_warranty'     => $expiry !== '' ? ($expiry >= date('Y-m-d')) : null,
            'supplier'        => self::dropdown('Supplier', (int) ($fields['suppliers_id'] ?? 0)),
        ], static fn($v): bool => $v !== null && $v !== '' && $v !== 0);
    }

    /** @return array<int,array<string,mixed>> */
    private static function contracts(string $itemtype, int $id): array
    {
        if (!Contract::canView()) {
            return [];
        }

        $out = [];
        foreach (
            getAllDataFromTable(Contract_Item::getTable(), [
                'itemtype' => $itemtype,
                'items_id' => $id,
            ]) as $link
        ) {
            $contract = new Contract();
            if (!$contract->getFromDB((int) $link['contracts_id']) || !$contract->canViewItem()) {
                continue;
            }

            $begin  = (string) ($contract->fields['begin_date'] ?? '');
            $months = (int) ($contract->fields['duration'] ?? 0);

            $out[] = array_filter([
                'name'  => (string) $contract->fields['name'],
                'type'  => self::dropdown('ContractType', (int) ($contract->fields['contracttypes_id'] ?? 0)),
                'from'  => $begin,
                'until' => $begin !== '' && $months > 0
                    ? date('Y-m-d', (int) strtotime("$begin +$months months"))
                    : '',
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        return $out;
    }

    private static function operatingSystem(string $itemtype, int $id): string
    {
        foreach (
            getAllDataFromTable(Item_OperatingSystem::getTable(), [
                'itemtype' => $itemtype,
                'items_id' => $id,
            ]) as $row
        ) {
            // The version is dropped when the name already contains it.
            // GLPI's inventory writes both, and on a Linux agent both are the
            // full release string — so joining them blindly produces "Ubuntu
            // 26.04 LTS (Resolute Raccoon) 26.04 LTS (Resolute Raccoon)".
            $name    = self::dropdown('OperatingSystem', (int) ($row['operatingsystems_id'] ?? 0));
            $version = self::dropdown('OperatingSystemVersion', (int) ($row['operatingsystemversions_id'] ?? 0));
            $arch    = self::dropdown('OperatingSystemArchitecture', (int) ($row['operatingsystemarchitectures_id'] ?? 0));

            if ($name !== null && $version !== null && str_contains($name, $version)) {
                $version = null;
            }

            $parts = array_filter([$name, $version, $arch]);

            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return '';
    }

    /**
     * Installed software, newest install first.
     *
     * Capped, and the cap is reported. A developer's laptop has four hundred
     * entries and a model handed all of them will summarise the list instead of
     * answering the question that was asked.
     *
     * @return array<string,mixed>
     */
    private static function software(int $computers_id): array
    {
        global $DB;

        $rows  = [];
        $total = 0;

        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_softwares.name AS software',
                    'glpi_softwareversions.name AS version',
                    Item_SoftwareVersion::getTable() . '.date_install',
                ],
                'FROM'      => Item_SoftwareVersion::getTable(),
                'LEFT JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            Item_SoftwareVersion::getTable() => 'softwareversions_id',
                            'glpi_softwareversions'          => 'id',
                        ],
                    ],
                    'glpi_softwares'        => [
                        'ON' => [
                            'glpi_softwareversions' => 'softwares_id',
                            'glpi_softwares'        => 'id',
                        ],
                    ],
                ],
                'WHERE'     => [
                    Item_SoftwareVersion::getTable() . '.itemtype'   => Computer::class,
                    Item_SoftwareVersion::getTable() . '.items_id'   => $computers_id,
                    Item_SoftwareVersion::getTable() . '.is_deleted' => 0,
                ],
                'ORDER'     => Item_SoftwareVersion::getTable() . '.date_install DESC',
            ]) as $row
        ) {
            $total++;
            if (count($rows) >= self::MAX_SOFTWARE) {
                continue;
            }

            $rows[] = array_filter([
                'name'      => (string) $row['software'],
                'version'   => (string) $row['version'],
                'installed' => (string) $row['date_install'],
            ], static fn($v): bool => $v !== '');
        }

        if ($rows === []) {
            return [];
        }

        return [
            'installed_count' => $total,
            'most_recent'     => $rows,
            'note'            => $total > count($rows)
                ? sprintf('%d installed in total; the %d most recently installed are listed.',
                    $total, count($rows))
                : null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function tickets(string $itemtype, int $id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Item_Ticket::getTable(), [
                'itemtype' => $itemtype,
                'items_id' => $id,
            ]) as $link
        ) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $link['tickets_id']) || !$ticket->canViewItem()) {
                continue;
            }

            $out[] = [
                'id'     => (int) $ticket->getID(),
                'title'  => (string) $ticket->fields['name'],
                'status' => Ticket::getStatus((int) $ticket->fields['status']),
                'opened' => (string) $ticket->fields['date'],
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($b['opened'], $a['opened']));

        return array_slice($out, 0, self::MAX_TICKETS);
    }

    /**
     * The model name.
     *
     * Derived from the *itemtype* rather than from this tool's own vocabulary
     * for the asset kind: `networkequipmentmodels_id` is the column, and
     * `network_equipmentmodels_id` — which is what the argument the model sent
     * would spell — is nothing at all, so reading it returns null on exactly
     * the asset type where the model matters most.
     *
     * @param array<string,mixed> $fields
     */
    private static function modelName(string $itemtype, array $fields): ?string
    {
        $key = strtolower($itemtype) . 'models_id';

        return self::dropdown($itemtype . 'Model', (int) ($fields[$key] ?? 0));
    }

    private static function userName(int $users_id): ?string
    {
        if ($users_id <= 0) {
            return null;
        }

        $name = \getUserName($users_id);

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private static function dropdown(string $itemtype, int $id): ?string
    {
        if ($id <= 0 || !class_exists($itemtype)) {
            return null;
        }

        $name = \Dropdown::getDropdownName($itemtype::getTable(), $id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : null;
    }
}
