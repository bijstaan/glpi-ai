<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * What a tool is allowed to know about why it was called.
 *
 * Deliberately small. A tool gets the entity the work belongs to and, when
 * there is one, the item the conversation is about — enough to scope a search
 * or resolve "this ticket", and not enough to start depending on the feature
 * that invoked it. Tools that know which feature is driving them stop being
 * reusable, which is the entire point of having a registry.
 *
 * The entity is passed rather than read from the session for the same reason
 * {@see Client::complete()} takes one: background work has no session, and a
 * tool that quietly resolved to entity 0 there would read across every tenant.
 */
final class ToolContext
{
    public function __construct(
        public readonly int $entities_id,
        /** The item the conversation is about, when there is one. */
        public readonly ?string $itemtype = null,
        public readonly ?int $items_id = null
    ) {
    }

    public function isAbout(string $itemtype): bool
    {
        return $this->itemtype === $itemtype && (int) $this->items_id > 0;
    }
}
