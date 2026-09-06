<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\Completion;
use GlpiPlugin\Glpiai\Prompt;

/**
 * What every vendor adapter must do.
 *
 * Note what is *not* here: no streaming, no embeddings, no vision. Not because
 * they don't matter, but because the four vendors differ enough on each that a
 * shared method would be a lie — and an interface method that one
 * implementation throws on is worse than no method at all, because callers
 * write against it and only find out per-provider at runtime. Those get added
 * when a feature needs them, one at a time, with all four implemented together.
 *
 * Tool use was added that way and needed no new method: tools go out on the
 * {@see Prompt} and calls come back on the {@see Completion}, so a provider
 * that could not do it would simply ignore both. All four can.
 */
interface Provider
{
    /** Stable machine id, used as the settings key prefix. Never change it. */
    public static function id(): string;

    /** Human name for the settings page. */
    public static function label(): string;

    /**
     * The settings this provider needs.
     *
     * @return Field[]
     */
    public static function fields(): array;

    /**
     * Is this provider configured well enough to attempt a call?
     *
     * Cheap and local — it checks that required settings are non-empty, not
     * that the credentials work. Proving credentials requires a network round
     * trip, which belongs in the explicit connection test, not on the path of
     * every request.
     */
    public function isConfigured(): bool;

    /** Send one request and return the normalised answer. */
    public function complete(Prompt $prompt): Completion;

    /**
     * The model id for a tier, as configured.
     *
     * Exposed separately from complete() so the caller can record which model
     * answered without parsing it back out of the response — and so the
     * settings page can show what a tier currently resolves to.
     */
    public function modelFor(string $tier): string;
}
