<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Assistant;

use CommonDBTM;
use CommonITILObject;
use Dropdown;
use Glpi\RichText\RichText;
use Session;
use Toolbox;

/**
 * What the technician is looking at when they open the panel.
 *
 * The reason the assistant is a slide-over rather than a page. A technician
 * with a ticket open and a question about it should not have to describe the
 * ticket — they are looking at it, the software is looking at it, and making
 * them type "ticket 4821" is the sort of small tax that stops people using a
 * tool at all.
 *
 * Context is *offered*, not asserted. It goes into the system instruction as
 * "this is what they have open", and the model is told it may be irrelevant,
 * because it frequently is: people open the panel on whatever page they happen
 * to be on and ask about something else entirely.
 *
 * Nothing here trusts the browser. The itemtype and id arrive from JavaScript
 * reading the URL, so both are validated and the item is loaded through GLPI's
 * own rights check before a word of it reaches a prompt.
 */
final class Context
{
    /**
     * Itemtypes worth describing. Anything else is context-free.
     *
     * Also the list setup.php registers a purge hook for: a conversation quotes
     * the record it is about, so it must not outlive it. Anything added here
     * therefore gets that for free rather than needing to be remembered twice.
     */
    public const SUPPORTED = [
        'Ticket', 'Change', 'Problem',
        'Computer', 'Monitor', 'Printer', 'NetworkEquipment', 'Phone', 'Peripheral',
        'Software', 'User', 'KnowbaseItem',
    ];

    private const MAX_CHARS = 2000;

    /**
     * Load the item the panel was opened on, or null.
     *
     * Returns null rather than throwing for every failure mode — unknown type,
     * missing record, no rights — because none of them is an error worth
     * showing. The conversation is perfectly usable without context.
     */
    public static function item(string $itemtype, int $items_id): ?CommonDBTM
    {
        if ($items_id <= 0 || !in_array($itemtype, self::SUPPORTED, true)) {
            return null;
        }

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB($items_id)) {
            return null;
        }

        return $item->canViewItem() ? $item : null;
    }

    /**
     * The item as prompt text.
     *
     * Deliberately brief. This is a hint about where the conversation starts,
     * not a substitute for the tools — the model has `read_ticket` and
     * `find_asset` and should use them when it needs the detail. Pasting the
     * whole record in would pay for it on every turn of every conversation.
     */
    public static function describe(?CommonDBTM $item): string
    {
        if ($item === null) {
            return '';
        }

        $itemtype = $item::class;
        $id       = (int) $item->getID();

        $lines = [sprintf('%s #%d: %s', $itemtype::getTypeName(1), $id, (string) $item->fields['name'])];

        if ($item instanceof CommonITILObject) {
            $lines[] = 'Status: ' . $itemtype::getStatus((int) $item->fields['status']);

            $category = (int) ($item->fields['itilcategories_id'] ?? 0);
            if ($category > 0) {
                $lines[] = 'Category: ' . Dropdown::getDropdownName('glpi_itilcategories', $category);
            }

            $lines[] = 'Reported: ' . self::plain((string) ($item->fields['content'] ?? ''));
        } else {
            foreach (['serial' => 'Serial', 'otherserial' => 'Inventory number'] as $field => $label) {
                $value = trim((string) ($item->fields[$field] ?? ''));
                if ($value !== '') {
                    $lines[] = $label . ': ' . $value;
                }
            }

            $entity = (int) ($item->fields['entities_id'] ?? 0);
            $lines[] = 'Entity: ' . Dropdown::getDropdownName('glpi_entities', $entity);
        }

        return implode("\n", $lines);
    }

    /** A short label for the panel header. */
    public static function label(?CommonDBTM $item): string
    {
        if ($item === null) {
            return '';
        }

        return sprintf(
            '%s #%d — %s',
            $item::getTypeName(1),
            (int) $item->getID(),
            (string) $item->fields['name']
        );
    }

    /** Which entity a conversation belongs to, for the gate and the usage log. */
    public static function entity(?CommonDBTM $item): int
    {
        if ($item !== null && isset($item->fields['entities_id'])) {
            return (int) $item->fields['entities_id'];
        }

        return (int) Session::getActiveEntity();
    }

    private static function plain(string $html): string
    {
        return Toolbox::substr(
            trim(RichText::getTextFromHtml($html, false, true, false, true, true)),
            0,
            self::MAX_CHARS
        );
    }
}
