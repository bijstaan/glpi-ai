<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

/**
 * One configuration input a provider needs.
 *
 * Providers declare their settings rather than the config page hard-coding a
 * form per vendor. That keeps the cost of a fifth provider at exactly one
 * class: without it, every adapter would also need a branch in the settings
 * page, a branch in the save handler, and a branch in the validator — three
 * places to forget, all of which fail silently by simply not persisting a
 * field.
 */
final class Field
{
    public const TEXT     = 'text';
    /** Stored encrypted and never rendered back to the browser. */
    public const SECRET   = 'secret';
    public const SELECT   = 'select';
    public const CHECKBOX = 'checkbox';

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly string $help = '',
        public readonly string $default = '',
        /** @var array<string,string> value => label, for SELECT */
        public readonly array $options = [],
        public readonly bool $required = false,
        public readonly string $placeholder = ''
    ) {
    }

    public function isSecret(): bool
    {
        return $this->type === self::SECRET;
    }
}
