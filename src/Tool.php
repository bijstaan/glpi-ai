<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * One thing a model may ask us to do on its behalf.
 *
 * A tool is a name, a description, a schema for its arguments, and something
 * that runs. The description is not documentation — it is the entire basis on
 * which the model decides whether to call this rather than something else, so
 * it is worth writing as carefully as the code.
 *
 * Two fields here are about safety rather than function:
 *
 * `$right` is the GLPI right the caller must hold. Tools run inside a session
 * and inherit that user's entity restriction and profile, which is the property
 * that keeps tool use from becoming a way around GLPI's permission model: a
 * model asked to "find similar tickets" can only ever see what the technician
 * driving it could have found by hand.
 *
 * `$mutates` marks a tool that changes something. Those are refused unless an
 * administrator has explicitly turned write tools on, because the failure modes
 * are not symmetric — a bad read wastes a few hundred tokens, and a bad write
 * is in the ticket history under a technician's name.
 */
final class Tool
{
    /**
     * The intersection of what all four vendors accept as a function name.
     *
     * Anthropic and OpenAI allow `[a-zA-Z0-9_-]{1,64}`; Gemini additionally
     * insists on a leading letter or underscore. Names are validated at
     * registration rather than at call time so a bad one fails when a developer
     * writes it, not in front of a technician six weeks later.
     */
    public const NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/';

    /**
     * @param string                                     $name    unique, stable, matches NAME_PATTERN
     * @param string                                     $description what it does and when to reach for it
     * @param array<string,mixed>                        $schema  JSON Schema for the arguments object
     * @param null|callable(array,ToolContext):mixed     $handler what runs; null for tools resolved elsewhere (MCP)
     * @param string                                     $source  where it came from, for the audit log
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $schema = ['type' => 'object', 'properties' => []],
        public $handler = null,
        public readonly bool $mutates = false,
        public readonly ?string $right = null,
        public readonly string $source = 'native',
        /**
         * Which bit of `$right` the caller must hold.
         *
         * READ is the sensible default and is wrong often enough to be worth a
         * parameter: GLPI rights are bitmasks, and a tool that *does* something
         * usually maps to UPDATE. glpi-osquery gates free-form SQL on UPDATE of
         * `plugin_glpiosquery_rawsql`, so a tool checking READ would let it
         * through for anyone holding the weaker half.
         */
        public readonly int $right_level = READ,
        /**
         * Declare this tool on every request, rather than leaving it to be
         * found by `find_tools`.
         *
         * Past `tool_search_threshold` tools the request declares a searchable
         * subset instead of everything — see Toolbox. Anything the
         * administrator installed on purpose is pinned, because a tool nobody
         * can see is indistinguishable from one that was never configured, and
         * that is exactly how a freshly added MCP server looked: registered,
         * listed in the settings page, and never once offered to the model.
         */
        public readonly bool $pinned = true
    ) {
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * The argument keys the schema marks as required.
     *
     * @return string[]
     */
    public function requiredArguments(): array
    {
        $required = $this->schema['required'] ?? [];

        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /**
     * May the current session use this tool?
     *
     * Deliberately returns a *reason* rather than a bool. The reason goes back
     * to the model as the tool's result, so a refusal teaches it to stop asking
     * instead of leaving it to retry the same call until the turn budget runs
     * out.
     */
    public function refusalReason(): ?string
    {
        if ($this->mutates && !Settings::flag('allow_write_tools')) {
            return 'This tool can modify data and write tools are disabled on this GLPI instance.';
        }

        if ($this->right !== null && !\Session::haveRight($this->right, $this->right_level)) {
            return 'The signed-in user does not have permission to use this tool.';
        }

        return null;
    }
}
