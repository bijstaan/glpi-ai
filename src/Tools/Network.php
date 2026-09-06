<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use DBmysql;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolException;

/**
 * The network inventory, as tools.
 *
 * Two questions, and between them they are most of what a technician asks a
 * switch. *Where is this thing plugged in?* — asked constantly, answered today
 * by opening the inventory, finding the asset, finding its port, following the
 * link, and reading the switch. And *what is that port doing?* — status, speed,
 * duplex, and whether it is counting errors.
 *
 * Native rather than contributed by glpi-netscan, because none of this is that
 * plugin's data: GLPI's own network inventory holds it, filled by whatever
 * populates it — the native agent, glpi-netscan's SNMP scanner, or an import.
 * A site with no scanning plugin at all still has ports and connections, and
 * the tools should work there.
 */
final class Network
{
    /** Ports listed for one device. A core switch has hundreds; nobody reads them all. */
    private const MAX_PORTS = 60;

    public static function trace(): Tool
    {
        return new Tool(
            name: 'network_trace',
            description: 'Find where something is physically connected: give a MAC address, an IP '
                . 'address or a hostname and get back the switch and port it is plugged into, '
                . 'with that port\'s status, speed and error counters. Use this for "is it even '
                . 'plugged in", "which port is that machine on", and anything about a link that '
                . 'keeps dropping.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'address' => [
                        'type'        => 'string',
                        'description' => 'A MAC address, an IP address, or a hostname.',
                    ],
                ],
                'required'   => ['address'],
            ],
            handler: [self::class, 'runTrace'],
            right: 'networking'
        );
    }

    public static function ports(): Tool
    {
        return new Tool(
            name: 'network_ports',
            description: 'List the ports of a network device with their status, speed, duplex, '
                . 'error counters and what is connected to each. Use it to see which ports are '
                . 'down, which are counting errors, and where a device is missing from.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'device' => [
                        'type'        => 'string',
                        'description' => 'The network device name, or part of it.',
                    ],
                    'only_problems' => [
                        'type'        => 'string',
                        'description' => 'Set to "yes" to list only ports that are down or '
                            . 'counting errors.',
                    ],
                ],
                'required'   => ['device'],
            ],
            handler: [self::class, 'runPorts'],
            right: 'networking'
        );
    }

    // ---------------------------------------------------------------- trace

    /** @param array<string,mixed> $arguments */
    public static function runTrace(array $arguments): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $address = trim((string) ($arguments['address'] ?? ''));
        if ($address === '') {
            throw new ToolException('Give a MAC address, an IP address or a hostname.');
        }

        $ports = self::portsFor($address);

        if ($ports === []) {
            return [
                'found' => false,
                'note'  => sprintf(
                    'Nothing in the inventory has %s on a network port. Either it has never been '
                    . 'inventoried, or the address is wrong.',
                    $address
                ),
            ];
        }

        $out = [];
        foreach ($ports as $port) {
            $out[] = [
                'device'    => self::itemName((string) $port['itemtype'], (int) $port['items_id']),
                'port'      => self::describePort($port),
                'connected' => self::peerOf((int) $port['id']),
            ];
        }

        return [
            'found' => true,
            'links' => $out,
            'note'  => 'A connection recorded here is what the last inventory saw. A cable moved '
                     . 'since then will not show until the switch is scanned again.',
        ];
    }

    /**
     * The ports carrying an address, whichever kind it is.
     *
     * MAC and IP are matched exactly; a hostname is matched against the asset
     * rather than the port, because a machine's port is rarely named after it.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function portsFor(string $address): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = 'glpi_networkports';
        $scope = getEntitiesRestrictCriteria($table, 'entities_id', '', true);

        // A MAC, normalised: people paste them in three notations and the
        // inventory stores one.
        $mac = strtolower(preg_replace('/[^0-9a-f]/i', '', $address) ?? '');
        if (strlen($mac) === 12) {
            $formatted = implode(':', str_split($mac, 2));

            return self::rows([
                'FROM'  => $table,
                'WHERE' => ['mac' => $formatted, 'is_deleted' => 0] + $scope,
                'LIMIT' => 10,
            ]);
        }

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            // ip → networkname → port. The address is not on the port itself.
            $port_ids = [];
            foreach (
                $DB->request([
                    'SELECT' => ['n.items_id AS port'],
                    'FROM'   => 'glpi_ipaddresses AS a',
                    'INNER JOIN' => [
                        'glpi_networknames AS n' => [
                            'ON' => [
                                'a' => 'items_id',
                                'n' => 'id',
                                ['AND' => ['a.itemtype' => 'NetworkName']],
                            ],
                        ],
                    ],
                    'WHERE'  => [
                        'a.name'       => $address,
                        'a.is_deleted' => 0,
                        'n.itemtype'   => 'NetworkPort',
                    ],
                    'LIMIT'  => 10,
                ]) as $row
            ) {
                $port_ids[] = (int) $row['port'];
            }

            return $port_ids === [] ? [] : self::rows([
                'FROM'  => $table,
                'WHERE' => ['id' => $port_ids, 'is_deleted' => 0] + $scope,
            ]);
        }

        // A hostname. Matched against assets that have ports, then their ports.
        $items = [];
        foreach (['Computer', 'NetworkEquipment', 'Printer', 'Phone', 'Peripheral'] as $itemtype) {
            $item_table = getTableForItemType($itemtype);

            foreach (
                $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $item_table,
                    'WHERE'  => ['name' => ['LIKE', '%' . $address . '%'], 'is_deleted' => 0]
                        + getEntitiesRestrictCriteria($item_table, 'entities_id', '', true),
                    'LIMIT'  => 5,
                ]) as $row
            ) {
                $items[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['id']];
            }
        }

        $ports = [];
        foreach ($items as $item) {
            foreach (
                self::rows([
                    'FROM'  => $table,
                    'WHERE' => [
                        'itemtype'   => $item['itemtype'],
                        'items_id'   => $item['items_id'],
                        'is_deleted' => 0,
                    ] + $scope,
                    'LIMIT' => 10,
                ]) as $port
            ) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    /**
     * What is on the other end of a port.
     *
     * The link table is undirected — a connection is stored once, and this port
     * may be either side of it — so both columns are asked.
     *
     * @return array<string,mixed>|null
     */
    private static function peerOf(int $ports_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => 'glpi_networkports_networkports',
                'WHERE' => ['OR' => [
                    ['networkports_id_1' => $ports_id],
                    ['networkports_id_2' => $ports_id],
                ]],
                'LIMIT' => 1,
            ]) as $link
        ) {
            $peer = (int) $link['networkports_id_1'] === $ports_id
                ? (int) $link['networkports_id_2']
                : (int) $link['networkports_id_1'];

            foreach (
                $DB->request(['FROM' => 'glpi_networkports', 'WHERE' => ['id' => $peer], 'LIMIT' => 1])
                as $port
            ) {
                return [
                    'device' => self::itemName((string) $port['itemtype'], (int) $port['items_id']),
                    'port'   => self::describePort($port),
                ];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- ports

    /** @param array<string,mixed> $arguments */
    public static function runPorts(array $arguments): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $name = trim((string) ($arguments['device'] ?? ''));
        if ($name === '') {
            throw new ToolException('Name the network device.');
        }

        $only_problems = in_array(
            strtolower((string) ($arguments['only_problems'] ?? '')),
            ['yes', 'true', '1'],
            true
        );

        $device = null;
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_networkequipments',
                'WHERE'  => [
                    'name'        => ['LIKE', '%' . $name . '%'],
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_networkequipments', 'entities_id', '', true),
                'LIMIT'  => 1,
            ]) as $row
        ) {
            $device = $row;
        }

        if ($device === null) {
            throw new ToolException(sprintf('No network device matching "%s" is visible to you.', $name));
        }

        $ports = [];
        $shown = 0;

        foreach (
            self::rows([
                'FROM'  => 'glpi_networkports',
                'WHERE' => [
                    'itemtype'   => 'NetworkEquipment',
                    'items_id'   => (int) $device['id'],
                    'is_deleted' => 0,
                ],
                'ORDER' => 'logical_number ASC',
            ]) as $port
        ) {
            $described = self::describePort($port);
            $errors    = (int) $port['ifinerrors'] + (int) $port['ifouterrors'];
            $down      = (string) $port['ifstatus'] !== '1';

            if ($only_problems && !$down && $errors === 0) {
                continue;
            }

            if ($shown >= self::MAX_PORTS) {
                break;
            }
            $shown++;

            $ports[] = $described + ['connected' => self::peerOf((int) $port['id'])];
        }

        return [
            'device' => (string) $device['name'],
            'ports'  => $ports,
            'note'   => $ports === [] && $only_problems
                ? 'Every port is up and none is counting errors.'
                : '',
        ];
    }

    // --------------------------------------------------------------- shared

    /**
     * One port, in words.
     *
     * `ifstatus` is SNMP's ifOperStatus, where 1 is up and everything else is
     * some flavour of not. Translated here rather than passed through, because
     * a model handed the number 2 will guess, and it will guess "second port".
     *
     * @param array<string,mixed> $port
     * @return array<string,mixed>
     */
    private static function describePort(array $port): array
    {
        $status = match ((string) $port['ifstatus']) {
            '1'     => 'up',
            '2'     => 'down',
            '3'     => 'testing',
            '5'     => 'dormant',
            '6'     => 'not present',
            '7'     => 'lower layer down',
            default => 'unknown',
        };

        $out = [
            'number' => (int) $port['logical_number'],
            'name'   => (string) $port['name'],
            'status' => $status,
            'mac'    => (string) $port['mac'],
        ];

        // Only what was actually inventoried. A zero speed on a device nobody
        // scanned by SNMP is absent data, and reporting it as "0 Mbps" invites
        // a confident wrong conclusion about the link.
        if ((int) $port['ifspeed'] > 0) {
            $out['speed_mbps'] = (int) round(((int) $port['ifspeed']) / 1000000);
        }
        if (trim((string) $port['ifalias']) !== '') {
            $out['description'] = trim((string) $port['ifalias']);
        }

        $errors = (int) $port['ifinerrors'] + (int) $port['ifouterrors'];
        if ($errors > 0) {
            $out['errors'] = ['in' => (int) $port['ifinerrors'], 'out' => (int) $port['ifouterrors']];
        }
        if (trim((string) $port['lastup']) !== '') {
            $out['last_up'] = (string) $port['lastup'];
        }
        if ((int) $port['trunk'] === 1) {
            $out['trunk'] = true;
        }

        return $out;
    }

    private static function itemName(string $itemtype, int $items_id): string
    {
        $item = getItemForItemtype($itemtype);

        if ($item === false || !$item->getFromDB($items_id)) {
            return sprintf('%s #%d', $itemtype, $items_id);
        }

        return sprintf('%s (%s)', (string) $item->fields['name'], $itemtype::getTypeName(1));
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $criteria): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
