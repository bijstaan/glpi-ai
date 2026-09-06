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
use Group;
use Group_User;
use Monitor;
use Phone;
use Printer;
use Ticket;
use Ticket_User;
use User;

/**
 * Who this person is, and what they already have open.
 *
 * The gap this fills is the first question of most support calls and the one
 * the other tools could not answer: a ticket says `users_id 412`, and every
 * useful follow-on question — is this the third time this week, do they have a
 * laptop and a desk phone, are they in the finance group whose share keeps
 * dropping — needs the person rather than the number.
 *
 * It answers by name as well as by id, because a technician on the phone has a
 * name and a model reading a ticket has an id, and making them two tools would
 * mean the model picking wrong half the time.
 *
 * **The three lists are each rights-filtered on their own.** Being allowed to
 * read a user is not being allowed to read their tickets, and being allowed to
 * read their tickets is not being allowed to see the laptop assigned to them.
 * Each row is loaded and asked, rather than the whole thing being gated on the
 * `user` right — which would be the kind of shortcut that turns a lookup tool
 * into a way around the profile system.
 */
final class People
{
    /** Tickets and assets listed per person. Enough to see a pattern. */
    private const MAX_ROWS = 8;

    /** People returned when the query matches several. */
    private const MAX_PEOPLE = 5;

    /** @var array<string,class-string> Assets worth naming next to a person. */
    private const ASSETS = [
        'computer' => Computer::class,
        'phone'    => Phone::class,
        'printer'  => Printer::class,
        'monitor'  => Monitor::class,
    ];

    public static function tool(): Tool
    {
        return new Tool(
            name: 'read_user',
            description: 'Look up a person: their contact details, entity, location, the groups '
                . 'they are in, the assets assigned to them and the tickets they currently have '
                . 'open. Use it to turn a name or a user id on a ticket into the person — before '
                . 'assuming this is their first call, check what else they already have open, '
                . 'and before asking what machine they are on, check what is assigned to them.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'id'    => [
                        'type'        => 'integer',
                        'description' => 'The user id, when a ticket or another tool gave you one.',
                    ],
                    'query' => [
                        'type'        => 'string',
                        'description' => 'A name, login or email address. Used when no id is '
                            . 'given; several matches come back as a shortlist.',
                    ],
                ],
            ],
            handler: [self::class, 'run'],
            right: 'user'
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $id    = (int) ($arguments['id'] ?? 0);
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($id > 0) {
            $user = new User();

            // One message for "no such user" and "not visible to you", the same
            // as read_ticket: two answers would make this a way of probing
            // which ids are real in entities the caller cannot see.
            if (!$user->getFromDB($id) || !$user->can($id, READ)) {
                throw new ToolException("No user $id is visible to you.");
            }

            return self::describe($user, $context);
        }

        if ($query === '') {
            throw new ToolException('Give either an id or something to search for.');
        }

        // Not narrowed to the conversation's entity — see Lookup::search().
        // A person's entity is a profile assignment, not a column, so the
        // narrowing that is right for a ticket drops every technician and
        // every shared-services user into "no such person".
        $found = Lookup::search(User::class, $query, self::MAX_PEOPLE, $context, [], false);

        $people = [];
        foreach ($found as $raw) {
            $user = new User();
            if (!$user->getFromDB((int) ($raw['id'] ?? 0)) || !$user->can((int) $raw['id'], READ)) {
                continue;
            }
            $people[] = $user;
        }

        if ($people === []) {
            return ['count' => 0, 'note' => "Nobody visible to you matches \"$query\"."];
        }

        // One match is the common case and is answered in full. Several are
        // answered as a shortlist: filling out five people costs five times the
        // tokens to say something the model has to ask about again anyway.
        if (count($people) === 1) {
            return self::describe($people[0], $context);
        }

        return [
            'count'  => count($people),
            'people' => array_map(static fn(User $u): array => array_filter([
                'id'       => (int) $u->getID(),
                'name'     => self::displayName($u),
                'login'    => (string) $u->fields['name'],
                'email'    => $u->getDefaultEmail(),
                'entity'   => Lookup::entityName((int) $u->fields['entities_id']),
                'is_active' => (bool) $u->fields['is_active'],
            ], static fn($v): bool => $v !== null && $v !== ''), $people),
            'note'   => 'Several people match. Call read_user again with the id of the one you '
                . 'mean.',
        ];
    }

    /** @return array<string,mixed> */
    private static function describe(User $user, ToolContext $context): array
    {
        $id = (int) $user->getID();

        return array_filter([
            'id'          => $id,
            'name'        => self::displayName($user),
            'login'       => (string) $user->fields['name'],
            'email'       => $user->getDefaultEmail(),
            'phone'       => (string) ($user->fields['phone'] ?? ''),
            'mobile'      => (string) ($user->fields['mobile'] ?? ''),
            'title'       => self::dropdown('UserTitle', (int) ($user->fields['usertitles_id'] ?? 0)),
            'location'    => self::dropdown('Location', (int) ($user->fields['locations_id'] ?? 0)),
            'entity'      => Lookup::entityName((int) $user->fields['entities_id']),
            // Said plainly rather than left to be inferred from a missing
            // field: "their account is disabled" is the answer to a surprising
            // number of tickets, and a model will not guess it.
            'is_active'   => (bool) $user->fields['is_active'],
            'last_login'  => (string) ($user->fields['last_login'] ?? ''),
            'groups'      => self::groups($id),
            'assets'      => self::assets($id, $context),
            'open_tickets' => self::tickets($id),
        ], static fn($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /** @return string[] */
    private static function groups(int $users_id): array
    {
        $names = [];

        foreach (
            getAllDataFromTable(Group_User::getTable(), ['users_id' => $users_id]) as $row
        ) {
            $group = new Group();
            if ($group->getFromDB((int) $row['groups_id']) && $group->canViewItem()) {
                $names[] = (string) $group->fields['name'];
            }
        }

        return $names;
    }

    /**
     * What is assigned to them, across the asset types a helpdesk asks about.
     *
     * `users_id` on the asset, which is GLPI's "assigned to this person" — not
     * `users_id_tech`, which is who looks after it. Confusing the two produces
     * a list of everything the IT manager is responsible for the moment
     * somebody looks them up.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function assets(int $users_id, ToolContext $context): array
    {
        $out = [];

        foreach (self::ASSETS as $kind => $itemtype) {
            $probe = getItemForItemtype($itemtype);
            if (!$probe || !$probe->canView()) {
                continue;
            }

            foreach (
                getAllDataFromTable($itemtype::getTable(), [
                    'users_id'   => $users_id,
                    'is_deleted' => 0,
                    'is_template' => 0,
                ]) as $row
            ) {
                /** @var \CommonDBTM $asset */
                $asset = new $itemtype();
                $asset->getFromResultSet($row);
                if (!$asset->canViewItem()) {
                    continue;
                }

                $out[] = array_filter([
                    'type'   => $kind,
                    'id'     => (int) $row['id'],
                    'name'   => (string) $row['name'],
                    'serial' => (string) ($row['serial'] ?? ''),
                ], static fn($v): bool => $v !== '');

                if (count($out) >= self::MAX_ROWS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Their open tickets, as requester.
     *
     * Requester rather than every ticket they touch: the question this answers
     * is "has this person already reported something", and including tickets
     * they were merely observing would answer a different one.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function tickets(int $users_id): array
    {
        $out = [];

        foreach (
            getAllDataFromTable(Ticket_User::getTable(), [
                'users_id' => $users_id,
                'type'     => \CommonITILActor::REQUESTER,
            ]) as $link
        ) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $link['tickets_id']) || !$ticket->canViewItem()) {
                continue;
            }

            if (in_array((int) $ticket->fields['status'], Ticket::getClosedStatusArray(), true)) {
                continue;
            }

            $out[] = [
                'id'     => (int) $ticket->getID(),
                'title'  => (string) $ticket->fields['name'],
                'status' => Ticket::getStatus((int) $ticket->fields['status']),
                'opened' => (string) $ticket->fields['date'],
            ];

            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp($b['opened'], $a['opened']));

        return $out;
    }

    /**
     * The person's name, spelled the way the rest of GLPI spells it.
     *
     * Through core's `getUserName()` rather than by joining firstname and
     * realname here, because the order is configuration —
     * `$CFG_GLPI['names_format']` — and an instance set to "Surname Firstname"
     * would otherwise get one order from this tool and the other from
     * `read_ticket`, which reads as two different people.
     */
    private static function displayName(User $user): string
    {
        $name = \getUserName((int) $user->getID());

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : (string) $user->fields['name'];
    }

    private static function dropdown(string $itemtype, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $name = \Dropdown::getDropdownName($itemtype::getTable(), $id);

        return is_string($name) && $name !== '' && $name !== '&nbsp;' ? $name : null;
    }
}
