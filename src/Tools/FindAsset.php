<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Computer;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Monitor;
use NetworkEquipment;
use Peripheral;
use Phone;
use Printer;

/**
 * Look up an asset by name, serial or inventory number.
 *
 * The tool that turns "the laptop in reception is slow" into something with a
 * model, an age and an owner. A requester almost never quotes an asset tag, so
 * this searches everywhere the list view would and across the asset types that
 * actually turn up in a helpdesk — which is not all of them, deliberately:
 * offering fifteen itemtypes would spend the model's attention on choosing
 * between racks and enclosures.
 */
final class FindAsset
{
    private const SO_NAME   = 1;
    private const SO_SERIAL = 5;
    private const SO_MODEL  = 40;
    private const SO_ENTITY = 80;

    /** @var array<string,class-string> */
    private const TYPES = [
        'computer'          => Computer::class,
        'monitor'           => Monitor::class,
        'printer'           => Printer::class,
        'network_equipment' => NetworkEquipment::class,
        'phone'             => Phone::class,
        'peripheral'        => Peripheral::class,
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'find_asset',
            description: 'Find an asset — computer, monitor, printer, network device, phone or '
                . 'peripheral — by name, hostname, serial number or inventory number. Use this to '
                . 'turn a vague mention of a machine into the actual record, including its model '
                . 'and the user it is assigned to.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Name, hostname, or serial number.'],
                    'type'  => [
                        'type'        => 'string',
                        'enum'        => array_keys(self::TYPES),
                        'description' => 'Restrict to one kind of asset. Omit to search all of them.',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results per asset type, 1-25. Defaults to 5.'],
                ],
                'required'   => ['query'],
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

        $query = (string) ($arguments['query'] ?? '');
        $limit = Lookup::limit($arguments['limit'] ?? null, 5);
        $only  = (string) ($arguments['type'] ?? '');

        $types = $only !== '' && isset(self::TYPES[$only])
            ? [$only => self::TYPES[$only]]
            : self::TYPES;

        $found = [];
        foreach ($types as $key => $itemtype) {
            // canView() per type, so a profile that may see computers but not
            // network gear gets the computers rather than an error.
            $probe = getItemForItemtype($itemtype);
            if (!$probe || !$probe->canView()) {
                continue;
            }

            foreach (Lookup::search($itemtype, $query, $limit, $context) as $raw) {
                $id = (int) ($raw['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $found[] = array_filter([
                    'type'   => $key,
                    'id'     => $id,
                    'name'   => Lookup::column($raw, $itemtype, self::SO_NAME),
                    'serial' => Lookup::column($raw, $itemtype, self::SO_SERIAL),
                    'model'  => Lookup::column($raw, $itemtype, self::SO_MODEL),
                    'entity' => Lookup::column($raw, $itemtype, self::SO_ENTITY),
                ], static fn($v): bool => $v !== null);
            }
        }

        return ['count' => count($found), 'assets' => $found];
    }
}
