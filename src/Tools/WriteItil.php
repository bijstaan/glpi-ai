<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Change;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use ITILFollowup;
use Item_Ticket;
use Problem;
use Ticket;

/**
 * Two more writes, held to the same line as the first four.
 *
 * The rule in {@see WriteTicket} is the rule here: nothing requester-facing,
 * nothing that changes status or assignment, nothing deleted, and everything
 * behind the administrator's write switch and a right above READ. What is
 * added is the same class of work — the recording a technician does after
 * doing the actual job:
 *
 *  - **`add_itil_note`** is `add_ticket_note` for the two records it could
 *    not reach. A problem investigation and a change record accumulate their
 *    findings as followups exactly as a ticket does, they are gated on the
 *    same `followup` right, and the note is private for the same reason.
 *  - **`link_asset_to_ticket`** is the filing nobody does. A ticket that does
 *    not name the machine it is about is invisible to every "has this
 *    happened to this machine before" question ever asked afterwards, and
 *    attaching one is reversible with two clicks — which is the test for
 *    whether a write belongs here at all.
 *
 * Neither of them detaches, unlinks or deletes anything. Getting a link wrong
 * costs somebody a click; the tool that could remove the right one is not
 * worth having.
 */
final class WriteItil
{
    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::note(), self::link()];
    }

    // ----------------------------------------------------------------- note

    private static function note(): Tool
    {
        return new Tool(
            name: 'add_itil_note',
            description: 'Write an internal note on a problem or a change — not a ticket, which '
                . 'is add_ticket_note. Use it to record what an investigation found, what a '
                . 'change actually did on the night, or what still has to be checked. The note '
                . 'is always private: technicians see it and the requester never does, and '
                . 'there is no way to make it public.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => ['Problem', 'Change'],
                        'description' => 'Which of the two to write on.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to use the one the conversation is about.',
                    ],
                    'content'  => [
                        'type'        => 'string',
                        'description' => 'The note, as plain prose. Say what you did and what '
                            . 'you found, not what you are about to do.',
                    ],
                ],
                'required'   => ['content'],
            ],
            handler: [self::class, 'runNote'],
            mutates: true,
            right: 'followup',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runNote(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $itemtype = ucfirst(strtolower(trim((string) ($arguments['itemtype'] ?? ''))));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context->items_id !== null && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        if (!in_array($itemtype, [Problem::class, Change::class], true)) {
            throw new ToolException(
                'itemtype must be Problem or Change. For a ticket use add_ticket_note.'
            );
        }

        $content = trim((string) ($arguments['content'] ?? ''));
        if ($content === '') {
            throw new ToolException('There is nothing to write.');
        }

        /** @var Problem|Change $item */
        $item = new $itemtype();
        if ($items_id <= 0 || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException(sprintf('No %s %d is visible to you.', $itemtype, $items_id));
        }

        $followup = new ITILFollowup();
        $input    = [
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
            'content'    => self::html($content),
            // Not a default and not an argument, for the reason in
            // WriteTicket: a public followup leaves the building.
            'is_private' => 1,
        ];

        // `can()` rather than the right alone: the right says this profile may
        // write followups, and this says whether it may write one *here* —
        // which is where the entity restriction and the closed-record rule
        // live.
        if (!$followup->can(-1, CREATE, $input)) {
            throw new ToolException(sprintf('You cannot add a note to that %s.', $itemtype));
        }

        $id = (int) $followup->add($input);
        if ($id <= 0) {
            throw new ToolException('The note could not be added.');
        }

        return [
            'added'    => 'private note',
            'id'       => $id,
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'note'     => 'Written as an internal note. The requester cannot see it.',
        ];
    }

    // ----------------------------------------------------------------- link

    private static function link(): Tool
    {
        return new Tool(
            name: 'link_asset_to_ticket',
            description: 'Attach an asset — a computer, printer, switch, phone — to a ticket, '
                . 'so the ticket appears in that machine\'s history and the machine appears on '
                . 'the ticket. Use it whenever a ticket turns out to be about a specific piece '
                . 'of kit and does not say so: it is what makes "has this happened to this '
                . 'machine before" answerable later. Find the asset with find_asset first.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket. Omit to use the one the conversation is '
                            . 'about.',
                    ],
                    'itemtype'   => [
                        'type'        => 'string',
                        'description' => 'The asset kind, e.g. Computer, Printer, '
                            . 'NetworkEquipment, Phone, Monitor.',
                    ],
                    'items_id'   => ['type' => 'integer', 'description' => 'The asset id.'],
                ],
                'required'   => ['itemtype', 'items_id'],
            ],
            handler: [self::class, 'runLink'],
            mutates: true,
            right: 'ticket',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runLink(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $tickets_id = (int) ($arguments['tickets_id'] ?? 0);
        if ($tickets_id <= 0 && $context->isAbout(Ticket::class)) {
            $tickets_id = (int) $context->items_id;
        }

        $ticket = new Ticket();
        if ($tickets_id <= 0 || !$ticket->getFromDB($tickets_id) || !$ticket->can($tickets_id, UPDATE)) {
            throw new ToolException("No ticket $tickets_id that you can edit.");
        }

        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);
        $asset    = $items_id > 0 ? getItemForItemtype($itemtype) : false;

        // The asset is checked with the *reader's* eyes as well: attaching
        // something you cannot see would put its name and serial onto a ticket
        // for everyone who can read the ticket.
        if ($asset === false || !$asset->getFromDB($items_id) || !$asset->canViewItem()) {
            throw new ToolException("No $itemtype $items_id is visible to you.");
        }

        $existing = getAllDataFromTable(Item_Ticket::getTable(), [
            'tickets_id' => $tickets_id,
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
        ]);

        if ($existing !== []) {
            return [
                'linked'     => 'already',
                'tickets_id' => $tickets_id,
                'itemtype'   => $itemtype,
                'items_id'   => $items_id,
                'note'       => 'That asset was already on the ticket. Nothing was changed.',
            ];
        }

        $link  = new Item_Ticket();
        $input = ['tickets_id' => $tickets_id, 'itemtype' => $itemtype, 'items_id' => $items_id];

        if (!$link->can(-1, CREATE, $input)) {
            throw new ToolException('You cannot attach that asset to that ticket.');
        }

        $id = (int) $link->add($input);
        if ($id <= 0) {
            throw new ToolException('The asset could not be attached.');
        }

        return [
            'linked'     => 'yes',
            'id'         => $id,
            'tickets_id' => $tickets_id,
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
            'name'       => (string) ($asset->fields['name'] ?? ''),
            'note'       => 'The ticket now appears in this asset\'s history. Detaching it again '
                . 'is a person\'s job — no tool here removes a link.',
        ];
    }

    /**
     * Plain prose as the HTML GLPI stores.
     *
     * The same treatment WriteTicket gives it, and deliberately the same
     * shape: blank lines become paragraphs, single newlines become breaks,
     * and nothing else is interpreted — a model writing `<b>` writes the
     * characters rather than the markup.
     */
    private static function html(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $paragraphs = preg_split('/\n{2,}/', trim($escaped)) ?: [];

        return implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(trim($p)) . '</p>',
            $paragraphs
        ));
    }
}
