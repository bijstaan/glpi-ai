<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use GlpiPlugin\Glpiai\Mcp\Catalogue;
use GlpiPlugin\Glpiai\Tools\FindAsset;
use GlpiPlugin\Glpiai\Tools\History;
use GlpiPlugin\Glpiai\Tools\Network;
use GlpiPlugin\Glpiai\Tools\People;
use GlpiPlugin\Glpiai\Tools\ReadAsset;
use GlpiPlugin\Glpiai\Tools\ReadTicket;
use GlpiPlugin\Glpiai\Tools\SearchItil;
use GlpiPlugin\Glpiai\Tools\SearchKnowledge;
use GlpiPlugin\Glpiai\Tools\SearchTickets;
use GlpiPlugin\Glpiai\Tools\WriteKnowledge;
use GlpiPlugin\Glpiai\Tools\WriteTicket;

/**
 * Everything the model may be told it can do, from three sources.
 *
 * 1. **Native tools** — a small read-only set over GLPI's own data.
 * 2. **Other plugins**, through the `glpiai_tools` hook. This is the reason the
 *    registry exists rather than a hard-coded array: glpi-sop knows what a
 *    procedure's steps say, glpi-osquery knows what a machine looked like an
 *    hour ago, glpi-netscan knows what is on the wire. None of that belongs in
 *    this plugin, and all of it is worth a model being able to ask for.
 * 3. **MCP servers** configured by an administrator.
 *
 * A name collision between sources is resolved by refusing the newcomer, not by
 * overwriting: two tools answering to one name is how a model ends up calling
 * something other than what it was told about, and the failure would be
 * invisible.
 */
final class ToolRegistry
{
    /** The `$PLUGIN_HOOKS` key another plugin registers its tools under. */
    public const HOOK = 'glpiai_tools';

    /**
     * Every tool available to this entity right now.
     *
     * @return Tool[] keyed by name
     */
    public static function all(int $entities_id): array
    {
        $tools = [];

        foreach ([self::native(), self::fromPlugins(), self::fromMcp($entities_id)] as $source) {
            foreach ($source as $tool) {
                if (!$tool instanceof Tool) {
                    continue;
                }

                if (!Tool::isValidName($tool->name)) {
                    trigger_error(
                        sprintf('glpiai: ignoring tool "%s" — not a portable function name.', $tool->name),
                        E_USER_WARNING
                    );
                    continue;
                }

                if (isset($tools[$tool->name])) {
                    trigger_error(
                        sprintf('glpiai: ignoring duplicate tool "%s" from %s.', $tool->name, $tool->source),
                        E_USER_WARNING
                    );
                    continue;
                }

                $tools[$tool->name] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The tools this plugin ships.
     *
     * Two groups, and the line between them is the one that matters.
     *
     * **The reads** answer the questions a technician has before they can do
     * anything: what is this ticket, who is this person, what is this machine,
     * what changed on it, has anybody seen it before, and is there already a
     * change or a problem about it. They cost tokens and nothing else.
     *
     * **The writes** — five of them — were held back for a long time on the
     * grounds that a model which can only look things up is wrong in ways a
     * technician notices and discards, while one that can write is wrong in
     * ways that end up in a ticket's history under somebody's name. That is
     * still true, and it is an argument for *which* writes rather than for
     * none: what shipped is the work a technician does twenty times a day and
     * resents — write down what was found, log the time, fix the filing, link
     * the duplicates, save the article — with the customer-facing and
     * irreversible parts deliberately absent. A note is always private, no tool
     * changes status or assignment, nothing is deleted, and every one of them
     * needs the administrator's write-tools switch before it exists at all.
     * See {@see Tools\WriteTicket} for the reasoning per tool.
     *
     * Pinning follows the same split. Everything a routine question needs is
     * declared on every request; the rest — history, the ITIL search, and every
     * write — is found through `find_tools`, because a request that declares
     * eleven native schemas before the plugins have added theirs is one where
     * the model chooses worse and the bill is larger.
     *
     * @return Tool[]
     */
    public static function native(): array
    {
        return array_merge([
            SearchTickets::tool(),
            ReadTicket::tool(),
            SearchKnowledge::tool(),
            FindAsset::tool(),
            ReadAsset::tool(),
            People::tool(),
            History::tool(),
            SearchItil::tool(),
            Network::trace(),
            Network::ports(),
            WriteKnowledge::tool(),
        ], WriteTicket::tools());
    }

    /**
     * Tools contributed by other plugins.
     *
     * @return Tool[]
     */
    public static function fromPlugins(): array
    {
        global $PLUGIN_HOOKS;

        $tools = [];

        foreach ((array) ($PLUGIN_HOOKS[self::HOOK] ?? []) as $plugin => $callback) {
            if (!\Plugin::isPluginActive($plugin) || !is_callable($callback)) {
                continue;
            }

            try {
                // A plugin's tool list is other people's code running on the
                // path of every AI request. One that throws should cost its own
                // tools, not the whole feature.
                foreach ((array) $callback() as $tool) {
                    $tools[] = $tool;
                }
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf('glpiai: plugin "%s" failed to list tools: %s', $plugin, $e->getMessage()),
                    E_USER_WARNING
                );
            }
        }

        return $tools;
    }

    /** @return Tool[] */
    public static function fromMcp(int $entities_id): array
    {
        return Catalogue::tools($entities_id);
    }

    /**
     * Run one call and produce the result the model will see.
     *
     * Everything that can go wrong here comes back as an error *result* rather
     * than an exception, because the model is the one that can fix most of it:
     * a bad argument, a wrong id, a tool it should not have reached for. An
     * exception abandons a conversation that was one correction away from
     * working.
     *
     * @param Tool[] $offered the tools this request actually declared
     */
    public static function execute(ToolCall $call, array $offered, ToolContext $context): ToolInvocation
    {
        $started = microtime(true);
        $tool    = null;

        foreach ($offered as $candidate) {
            if ($candidate->name === $call->name) {
                $tool = $candidate;
                break;
            }
        }

        $elapsed = static fn(): int => (int) round((microtime(true) - $started) * 1000);

        if ($tool === null) {
            // Naming what does exist, because the usual cause is the model
            // inventing a plausible neighbour of a real tool.
            $known = implode(', ', array_map(static fn(Tool $t): string => $t->name, $offered));

            return new ToolInvocation(
                $call,
                ToolResult::refused($call, "No tool named \"{$call->name}\". Available tools: {$known}."),
                $elapsed(),
                'unknown'
            );
        }

        $refusal = $tool->refusalReason();
        if ($refusal !== null) {
            return new ToolInvocation($call, ToolResult::refused($call, $refusal), $elapsed(), $tool->source, $tool->mutates);
        }

        $missing = array_diff($tool->requiredArguments(), array_keys($call->arguments));
        if ($missing !== []) {
            return new ToolInvocation(
                $call,
                ToolResult::error($call, 'Missing required argument(s): ' . implode(', ', $missing) . '.'),
                $elapsed(),
                $tool->source,
                $tool->mutates
            );
        }

        if (!is_callable($tool->handler)) {
            return new ToolInvocation(
                $call,
                ToolResult::error($call, 'This tool is not runnable on this instance.'),
                $elapsed(),
                $tool->source,
                $tool->mutates
            );
        }

        try {
            $value  = ($tool->handler)($call->arguments, $context);
            $result = ToolResult::of($call, $value);
        } catch (ToolException $e) {
            // An ordinary no — wrong id, nothing found, not permitted. Handed
            // back for the model to work with, and deliberately not logged: a
            // model guessing and missing is the loop working, and an error log
            // full of those is an error log nobody reads.
            $result = ToolResult::error($call, $e->getMessage());
        } catch (\Throwable $e) {
            // Anything else is a defect and is worth knowing about. The message
            // still goes to the model, so it must not carry internals — and a
            // stack trace would be an expensive way to say "it broke".
            trigger_error(
                sprintf('glpiai: tool "%s" failed: %s', $tool->name, $e->getMessage()),
                E_USER_WARNING
            );
            $result = ToolResult::error($call, 'The tool failed: ' . $e->getMessage());
        }

        return new ToolInvocation($call, $result, $elapsed(), $tool->source, $tool->mutates);
    }
}
