<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Exception\CommonMarkException;

/**
 * Model output, rendered.
 *
 * Models write markdown whether or not they are asked to, and every feature in
 * this plugin was displaying it as plain text — so an answer arrived reading
 * `**`sw-back-office`** on port **`Gi1/0/7`**` with the asterisks and backticks
 * in it. Instructing the model not to use markdown was the other option and it
 * does not work reliably; rendering it does.
 *
 * `league/commonmark` is already in GLPI's own vendor tree, so this is a
 * configuration decision rather than a dependency. The configuration is the
 * whole point:
 *
 *   - **`html_input: escape`** — raw HTML in the source is escaped, not passed
 *     through. This is model output about entity data and it must never be
 *     parsed as markup, whatever ended up in it.
 *   - **`allow_unsafe_links: false`** — a `javascript:` href is dropped and the
 *     link renders without one.
 *   - **A nesting limit**, because the input is untrusted in the boring sense:
 *     deeply nested constructs are a way to spend a lot of CPU on one string.
 *
 * Together those mean the output is a known-safe subset — headings, emphasis,
 * lists, code, tables, links to ordinary schemes — and nothing else, which is
 * why callers can put it into the DOM directly.
 */
final class Markdown
{
    private static ?CommonMarkConverter $converter = null;

    /**
     * Markdown to HTML, safely.
     *
     * Never throws. A converter that fails on some pathological input must not
     * take an answer with it — the text is worth showing even unrendered, and a
     * technician mid-problem would rather read asterisks than an error.
     */
    public static function toHtml(string $markdown): string
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return '';
        }

        try {
            return trim((string) self::converter()->convert($markdown));
        } catch (CommonMarkException | \Throwable) {
            return '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }

    /**
     * The same, without the paragraph wrapper.
     *
     * For the places that are a line rather than a document — a triage chip's
     * reasoning, a confidence note — where a `<p>` would introduce a margin
     * into the middle of a sentence.
     */
    public static function toInline(string $markdown): string
    {
        $html = self::toHtml($markdown);

        // Only when the whole thing is one paragraph. Something that rendered
        // as several blocks is a document being asked to behave like a line,
        // and stripping the first wrapper would leave it malformed.
        if (preg_match('#^<p>(.*)</p>$#s', $html, $m) === 1 && !str_contains($m[1], '<p>')) {
            return $m[1];
        }

        return $html;
    }

    private static function converter(): CommonMarkConverter
    {
        return self::$converter ??= new CommonMarkConverter([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 20,
        ]);
    }
}
