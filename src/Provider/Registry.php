<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\Settings;

/**
 * The list of known providers, and the only place one gets built.
 *
 * Adding a vendor is adding a class and one line here. Everything downstream —
 * the settings form, the save handler, the uninstall key list, the connection
 * test — reads from this registry and the declared fields, so none of them
 * needs a per-vendor branch.
 */
final class Registry
{
    /**
     * @return array<string,class-string<Provider>> id => class
     */
    public static function all(): array
    {
        $classes = [Anthropic::class, OpenAi::class, Gemini::class, AzureFoundry::class];

        $out = [];
        foreach ($classes as $class) {
            $out[$class::id()] = $class;
        }

        return $out;
    }

    /** @return class-string<Provider>|null */
    public static function classFor(string $id): ?string
    {
        return self::all()[$id] ?? null;
    }

    /** @return array<string,string> id => label, for a dropdown */
    public static function labels(): array
    {
        $out = [];
        foreach (self::all() as $id => $class) {
            $out[$id] = $class::label();
        }

        return $out;
    }

    /**
     * Build a provider with its stored settings, decrypted.
     *
     * Returns null for an unknown id rather than throwing: the id comes from
     * configuration, and a plugin downgrade that removed a provider should
     * degrade to "not configured" rather than fataling on every page that
     * touches AI.
     */
    public static function build(string $id): ?Provider
    {
        $class = self::classFor($id);
        if ($class === null) {
            return null;
        }

        return new $class(Settings::forProvider($id));
    }

    /** The provider the administrator selected, or null if none is usable. */
    public static function active(): ?Provider
    {
        $id = (string) Settings::get('provider');
        if ($id === '') {
            return null;
        }

        return self::build($id);
    }
}
