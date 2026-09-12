<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Contract;
use Contract_Item;
use Entity;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Ticket;

/**
 * Who the customer is, and what has been agreed with them.
 *
 * On an MSP install the entity *is* the customer, and until now nothing here
 * could read one. A model could list a customer's tickets and read their
 * machines and had no way to answer "who are they", "how big are they", "how
 * much do they raise", or the question underneath most escalations — "what
 * did we actually promise them".
 *
 * Two tools rather than one because they are asked at different moments.
 * `read_entity` is context, reached for once at the start of a conversation
 * about a customer. `read_contract` is evidence, reached for when somebody is
 * about to agree to work, quote for it, or explain why something is not
 * covered — and it has to be readable for an asset as well as for a customer,
 * because "is this laptop under warranty support" is a question about a
 * contract nobody can name.
 *
 * Neither tool declares a profile right for the entity itself: GLPI gates an
 * entity on `haveAccessToEntity()` rather than on a profile bit, and requiring
 * the `entity` right — an administrator's right — would have made a customer
 * summary unreadable by every technician who needed it. What each *part* of
 * the summary needs is asked for separately, which is why the ticket figures
 * disappear for a profile that may not list tickets rather than being counted
 * anyway.
 */
final class Customer
{
    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::entity(), self::contract()];
    }

    // --------------------------------------------------------------- entity

    private static function entity(): Tool
    {
        return new Tool(
            name: 'read_entity',
            description: 'Read a customer — a GLPI entity: its full name and place in the tree, '
                . 'its address and contact details, how many tickets it has open and raised '
                . 'recently, what it has in the way of assets, and which contracts cover it with '
                . 'the next one to expire. Use it at the start of a conversation about a '
                . 'customer, or whenever you need to know who a ticket\'s entity actually is '
                . 'rather than just its name.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'entities_id' => [
                        'type'        => 'integer',
                        'description' => 'The entity id. Omit for the one the conversation is '
                            . 'about. Entity 0 is the root and is a real entity.',
                    ],
                ],
            ],
            handler: [self::class, 'runEntity'],
            // See the class comment: an entity is gated on access to it, not
            // on a profile right, and the parts that need one ask separately.
            right: null,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runEntity(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $id = isset($arguments['entities_id'])
            ? (int) $arguments['entities_id']
            : $context->entities_id;

        $entity = new Entity();
        if ($id < 0 || !$entity->getFromDB($id) || !$entity->canViewItem()) {
            throw new ToolException("No entity $id is visible to you.");
        }

        $out = [
            'id'        => $id,
            'name'      => (string) $entity->fields['name'],
            'full_name' => (string) $entity->fields['completename'],
            'parent'    => $id === 0 ? null : Lookup::entityName((int) $entity->fields['entities_id']),
            'comment'   => Lookup::plain((string) ($entity->fields['comment'] ?? ''), 1500),
            'address'   => self::address($entity),
            'phone'     => (string) ($entity->fields['phonenumber'] ?? ''),
            'email'     => (string) ($entity->fields['email'] ?? ''),
            'website'   => (string) ($entity->fields['website'] ?? ''),
            'registration_number' => (string) ($entity->fields['registration_number'] ?? ''),
            'children'  => self::children($id),
            'tickets'   => self::tickets($id),
            'assets'    => self::assets($id),
            'contracts' => self::contractSummary($id),
        ];

        return array_filter($out, static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    private static function address(Entity $entity): string
    {
        $parts = array_filter([
            trim((string) ($entity->fields['address'] ?? '')),
            trim((string) ($entity->fields['postcode'] ?? '')),
            trim((string) ($entity->fields['town'] ?? '')),
            trim((string) ($entity->fields['state'] ?? '')),
            trim((string) ($entity->fields['country'] ?? '')),
        ], static fn(string $v): bool => $v !== '');

        return implode(', ', array_map(
            static fn(string $v): string => str_replace(["\r\n", "\n"], ' ', $v),
            $parts
        ));
    }

    /** @return array<int,array<string,mixed>> */
    private static function children(int $id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Entity::getTable(), ['entities_id' => $id], false, 'name') as $row
        ) {
            if (!\Session::haveAccessToEntity((int) $row['id'])) {
                continue;
            }

            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }

    /**
     * The ticket volume, for profiles that may already see it.
     *
     * Dropped rather than approximated for anyone else: the same reasoning as
     * {@see Stats}, where counting cannot be narrowed to "the ones you may
     * see" without reimplementing per-actor visibility.
     *
     * @return array<string,mixed>
     */
    private static function tickets(int $id): array
    {
        if (!\Session::haveRight('ticket', Ticket::READALL)) {
            return [];
        }

        $entities = array_values(array_intersect(
            array_merge(array_map('intval', \getSonsOf('glpi_entities', $id)), [$id]),
            array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))
        ));

        if ($entities === []) {
            return [];
        }

        $scope = ['entities_id' => $entities, 'is_deleted' => 0];

        return [
            'open_now'        => (int) countElementsInTable(Ticket::getTable(), array_merge($scope, [
                ['NOT' => ['status' => array_merge(
                    Ticket::getClosedStatusArray(),
                    Ticket::getSolvedStatusArray()
                )]],
            ])),
            'opened_last_30d' => (int) countElementsInTable(Ticket::getTable(), array_merge($scope, [
                ['date' => ['>=', date('Y-m-d 00:00:00', strtotime('-30 days'))]],
            ])),
            'overdue_now'     => (int) countElementsInTable(Ticket::getTable(), array_merge($scope, [
                ['NOT' => ['status' => array_merge(
                    Ticket::getClosedStatusArray(),
                    Ticket::getSolvedStatusArray()
                )]],
                ['NOT' => ['time_to_resolve' => null]],
                ['time_to_resolve' => ['<', date('Y-m-d H:i:s')]],
            ])),
        ];
    }

    /**
     * What they have, by kind.
     *
     * Only the kinds this profile may read, and only the ones with something
     * in them: a census listing nine zeroes is nine lines of prompt saying
     * nothing.
     *
     * @return array<string,int>
     */
    private static function assets(int $id): array
    {
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $entities = array_values(array_intersect(
            array_merge(array_map('intval', \getSonsOf('glpi_entities', $id)), [$id]),
            array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))
        ));

        if ($entities === []) {
            return [];
        }

        $out = [];

        foreach ((array) ($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
            if (!is_string($itemtype) || !class_exists($itemtype)) {
                continue;
            }

            /** @var \CommonDBTM $item */
            $item = new $itemtype();
            if (!$item::canView()) {
                continue;
            }

            $criteria = ['entities_id' => $entities];
            if ($item->maybeDeleted()) {
                $criteria['is_deleted'] = 0;
            }
            if ($item->maybeTemplate()) {
                $criteria['is_template'] = 0;
            }

            $count = (int) countElementsInTable($item->getTable(), $criteria);
            if ($count > 0) {
                $out[$itemtype::getTypeName(2)] = $count;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function contractSummary(int $id): array
    {
        if (!\Session::haveRight('contract', READ)) {
            return [];
        }

        $active = 0;
        $next   = null;

        foreach (self::contractsFor($id) as $row) {
            $end = self::endDate($row);

            if ($end !== null && $end < date('Y-m-d')) {
                continue;
            }

            $active++;

            if ($end !== null && ($next === null || $end < $next['expires'])) {
                $next = ['name' => (string) $row['name'], 'expires' => $end];
            }
        }

        return array_filter([
            'active'      => $active,
            'next_expiry' => $next,
        ], static fn($v): bool => $v !== null && $v !== 0);
    }

    // ------------------------------------------------------------- contract

    private static function contract(): Tool
    {
        return new Tool(
            name: 'read_contract',
            description: 'The contracts in force: what a customer has bought, or what covers one '
                . 'particular asset. Returns each contract\'s type, number, start and end date, '
                . 'notice period, whether it renews on its own, the hours of cover it buys, and '
                . 'how close it is to expiry. Use it before agreeing to work that might be '
                . 'chargeable, before promising out-of-hours attendance, when asked whether a '
                . 'machine is still under support, and when asked what a customer is entitled '
                . 'to.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'contracts_id' => [
                        'type'        => 'integer',
                        'description' => 'One contract, in full.',
                    ],
                    'entities_id'  => [
                        'type'        => 'integer',
                        'description' => 'Every contract for this customer. Defaults to the '
                            . 'conversation\'s entity when nothing else is given.',
                    ],
                    'itemtype'     => [
                        'type'        => 'string',
                        'description' => 'With items_id: the contracts covering one asset, e.g. '
                            . 'Computer, Printer, NetworkEquipment.',
                    ],
                    'items_id'     => [
                        'type'        => 'integer',
                        'description' => 'The asset id, with itemtype.',
                    ],
                    'expiring_within_days' => [
                        'type'        => 'integer',
                        'description' => 'Only contracts expiring within this many days. Use it '
                            . 'for renewal questions.',
                    ],
                ],
            ],
            handler: [self::class, 'runContract'],
            right: 'contract',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runContract(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $one = (int) ($arguments['contracts_id'] ?? 0);
        if ($one > 0) {
            $contract = new Contract();
            if (!$contract->getFromDB($one) || !$contract->canViewItem()) {
                throw new ToolException("No contract $one is visible to you.");
            }

            return self::describe($contract->fields, true);
        }

        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($itemtype !== '' && $items_id > 0) {
            $rows = self::contractsForItem($itemtype, $items_id);
            $for  = sprintf('%s %d', $itemtype, $items_id);
        } else {
            $id   = isset($arguments['entities_id'])
                ? (int) $arguments['entities_id']
                : $context->entities_id;
            $rows = self::contractsFor($id);
            $for  = Lookup::entityName($id) ?? "entity $id";
        }

        $within = isset($arguments['expiring_within_days'])
            ? max(0, (int) $arguments['expiring_within_days'])
            : null;

        $today    = date('Y-m-d');
        $deadline = $within === null ? null : date('Y-m-d', strtotime("+$within days"));

        $out = [];
        foreach ($rows as $row) {
            $end = self::endDate($row);

            if ($deadline !== null && ($end === null || $end > $deadline || $end < $today)) {
                continue;
            }

            $out[] = self::describe($row, false);
        }

        // Soonest to expire first: a list of contracts is read for the one
        // that is about to run out.
        usort($out, static fn(array $a, array $b): int
            => strcmp((string) ($a['expires'] ?? '9999'), (string) ($b['expires'] ?? '9999')));

        return array_filter([
            'for'       => $for,
            'contracts' => $out,
            'note'      => $out === []
                ? ($within !== null
                    ? 'Nothing is expiring in that window.'
                    : 'No contract covers this. Say so plainly — work here is probably chargeable.')
                : null,
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * One contract as prose-ready facts.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function describe(array $row, bool $full): array
    {
        $end    = self::endDate($row);
        $today  = date('Y-m-d');
        $notice = (int) ($row['notice'] ?? 0);

        $out = [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'number'   => (string) ($row['num'] ?? ''),
            'type'     => self::dropdownName('glpi_contracttypes', (int) ($row['contracttypes_id'] ?? 0)),
            'entity'   => Lookup::entityName((int) $row['entities_id']),
            'begins'   => self::date($row['begin_date'] ?? null),
            'expires'  => $end,
            'state'    => $end === null
                ? 'no end date recorded'
                : ($end < $today ? 'expired' : 'in force'),
            'renews'   => Contract::getContractRenewalName((int) ($row['renewal'] ?? 0)),
        ];

        if ($end !== null && $end >= $today) {
            $days = (int) floor((strtotime($end) - strtotime($today)) / 86400);
            $out['expires_in_days'] = $days;

            // The notice period is the date that actually matters and the one
            // nobody has in mind: a contract with 90 days' notice expiring in
            // 100 has ten days left to give it, not a hundred.
            if ($notice > 0) {
                $notice_by = date('Y-m-d', strtotime($end . " -$notice months"));
                $out['notice_period_months'] = $notice;
                $out['give_notice_by']       = $notice_by;
                if ($notice_by < $today) {
                    $out['notice_window'] = 'passed — this contract has already rolled on or '
                        . 'is inside its notice period';
                }
            }
        }

        $out['suppliers'] = self::suppliers((int) $row['id']);
        $out['cover']     = self::cover($row);

        if ($full) {
            $out['billing_periodicity_months'] = (int) ($row['periodicity'] ?? 0) ?: null;
            $out['accounting_number'] = (string) ($row['accounting_number'] ?? '');
            $out['comment']           = Lookup::plain((string) ($row['comment'] ?? ''), 1500);
            $out['covers_items']      = (int) countElementsInTable(
                Contract_Item::getTable(),
                ['contracts_id' => (int) $row['id']]
            );
        }

        return array_filter($out, static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * The hours the contract actually buys.
     *
     * Stored as four pairs of hour columns and shown nowhere a technician
     * looks before promising to be on site on a Saturday.
     *
     * @param array<string,mixed> $row
     */
    private static function cover(array $row): ?string
    {
        $week = self::hours($row['week_begin_hour'] ?? null, $row['week_end_hour'] ?? null);

        if ($week === null) {
            return null;
        }

        $parts = ['weekdays ' . $week];

        if ((int) ($row['use_saturday'] ?? 0) === 1) {
            $sat = self::hours($row['saturday_begin_hour'] ?? null, $row['saturday_end_hour'] ?? null);
            $parts[] = 'Saturday ' . ($sat ?? 'included');
        }

        if ((int) ($row['use_sunday'] ?? 0) === 1) {
            $sun = self::hours($row['sunday_begin_hour'] ?? null, $row['sunday_end_hour'] ?? null);
            $parts[] = 'Sunday ' . ($sun ?? 'included');
        }

        return implode(', ', $parts);
    }

    private static function hours(mixed $from, mixed $to): ?string
    {
        $from = is_scalar($from) ? substr((string) $from, 0, 5) : '';
        $to   = is_scalar($to) ? substr((string) $to, 0, 5) : '';

        if ($from === '' || $to === '' || ($from === '00:00' && $to === '00:00')) {
            return null;
        }

        return "$from to $to";
    }

    /** @return string[] */
    private static function suppliers(int $contracts_id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable('glpi_contracts_suppliers', ['contracts_id' => $contracts_id]) as $row
        ) {
            $name = \Dropdown::getDropdownName('glpi_suppliers', (int) $row['suppliers_id']);
            if (is_string($name) && $name !== '' && $name !== '&nbsp;') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Contracts filed against an entity, its children, and its parents'
     * recursive ones.
     *
     * The three-way criterion is the whole of it, and the middle branch is the
     * one that is easy to leave out: an MSP files the support contract on the
     * customer and the customer has an entity per site, so a contract that
     * covers a site sits on the parent with `is_recursive` set. Matching on
     * `entities_id` alone finds nothing for every branch office in the estate.
     *
     * Narrowed in SQL rather than by reading the table and filtering in PHP.
     * The rights check below still runs per row — it is what actually decides
     * — but a tool that loaded every contract on the instance to answer a
     * question about one customer would get slower with every year the
     * business trades.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function contractsFor(int $entities_id): array
    {
        $here      = array_map('intval', \getSonsOf('glpi_entities', $entities_id));
        $here[]    = $entities_id;
        $ancestors = array_map('intval', \getAncestorsOf('glpi_entities', $entities_id));

        $where = [
            'is_deleted'  => 0,
            'is_template' => 0,
            'OR'          => [
                ['entities_id' => array_values(array_unique($here))],
            ],
        ];

        if ($ancestors !== []) {
            $where['OR'][] = ['is_recursive' => 1, 'entities_id' => array_values($ancestors)];
        }

        $out = [];

        foreach (getAllDataFromTable(Contract::getTable(), $where) as $row) {
            $contract         = new Contract();
            $contract->fields = $row;

            if ($contract->canViewItem()) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function contractsForItem(string $itemtype, int $items_id): array
    {
        $item = getItemForItemtype($itemtype);

        if ($item === false || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException("No $itemtype $items_id is visible to you.");
        }

        $out = [];

        foreach (
            getAllDataFromTable(Contract_Item::getTable(), [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]) as $link
        ) {
            $contract = new Contract();
            if (!$contract->getFromDB((int) $link['contracts_id']) || !$contract->canViewItem()) {
                continue;
            }

            $out[] = $contract->fields;
        }

        return $out;
    }

    /**
     * When a contract actually ends.
     *
     * Core stores a start date and a duration in months and computes this on
     * the form; nothing persists it, so every consumer works it out again. A
     * tacit contract has no end until somebody gives notice, and saying "no
     * end date recorded" for one would be wrong in the direction that loses
     * money — so the current period's end is returned and the renewal type is
     * reported beside it.
     *
     * @param array<string,mixed> $row
     */
    private static function endDate(array $row): ?string
    {
        $begin    = self::date($row['begin_date'] ?? null);
        $duration = (int) ($row['duration'] ?? 0);

        if ($begin === null || $duration <= 0) {
            return null;
        }

        $end = date('Y-m-d', strtotime($begin . " +$duration months"));

        if ((int) ($row['renewal'] ?? 0) !== Contract::RENEWAL_TACIT) {
            return $end;
        }

        // Tacit renewal: roll forward by the period until it lands in the
        // future, which is where the next notice deadline is.
        $period = (int) ($row['periodicity'] ?? 0) ?: $duration;
        $today  = date('Y-m-d');
        $guard  = 0;

        while ($end < $today && $guard++ < 200) {
            $end = date('Y-m-d', strtotime($end . " +$period months"));
        }

        return $end;
    }

    private static function date(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : substr($value, 0, 10);
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
