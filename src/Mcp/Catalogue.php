<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;

/**
 * The configured MCP servers, as tools the model can be offered.
 *
 * Three things happen here that are not just plumbing.
 *
 * **Names are rewritten.** MCP puts no constraint on a tool name beyond being a
 * string, and the vendors do: no dots, no spaces, sixty-four characters. So an
 * MCP tool arrives as `mcp__<server>__<tool>`, which also namespaces it — two
 * servers both offering `search` would otherwise collide, and a collision that
 * resolves silently means the model calling something other than what it was
 * told about.
 *
 * **Descriptions are prefixed with the server's name.** The model is choosing
 * between a dozen tools on description alone, and "search issues" from two
 * different trackers is not a choice it can make.
 *
 * **Whose credential the call uses is the server's decision, and it changes who
 * may call.** A server authenticated as the instance is gated on the AI
 * configuration right — one shared credential, and the right is what stands
 * between it and everybody. A server authenticated per-technician is gated on
 * nothing but the technician having connected their own account, because their
 * token reaches exactly what they can already reach.
 *
 * **Schemas are passed through untouched.** They come from someone else's
 * server and may use keywords a given vendor rejects; the alternative — trying
 * to normalise arbitrary third-party schemas — would fail silently by mangling
 * one rather than loudly by being refused.
 */
final class Catalogue
{
    /** Marks a tool as belonging to a server, and keeps two servers from colliding. */
    public const PREFIX = 'mcp__';

    /**
     * Every tool offered by the servers this entity may use.
     *
     * Built from the stored discovery, not from a live `tools/list`. Asking
     * every configured server what it can do before every completion would put
     * a third party's availability on the latency path of an ordinary AI
     * request, and would do it several times over during a tool loop.
     *
     * @return Tool[]
     */
    public static function tools(int $entities_id): array
    {
        $tools = [];

        foreach (self::serversFor($entities_id) as $server) {
            foreach ($server->discovered() as $declared) {
                $remote = (string) ($declared['name'] ?? '');
                if ($remote === '' || !$server->allows($remote)) {
                    continue;
                }

                $name = self::localName($server, $remote);
                if (!Tool::isValidName($name)) {
                    trigger_error(
                        sprintf('glpiai: MCP tool "%s" cannot be given a portable name; skipping.', $remote),
                        E_USER_WARNING
                    );
                    continue;
                }

                $tools[] = new Tool(
                    name: $name,
                    description: sprintf('[%s] %s', $server->fields['name'], (string) ($declared['description'] ?? '')),
                    // `properties` has to be an object here as well: a
                    // provider validating the tool schema rejects
                    // `"properties":[]`, which is what an empty PHP array
                    // encodes to. It only bites for a tool that takes no
                    // arguments — which is exactly the kind a server is most
                    // likely to offer.
                    schema: is_array($declared['inputSchema'] ?? null)
                        ? $declared['inputSchema']
                        : ['type' => 'object', 'properties' => new \stdClass()],
                    handler: self::handler((int) $server->getID(), $remote),
                    // Does this tool write?
                    //
                    // The tool says so itself, in `readOnlyHint` — but only if
                    // an administrator has said this server's word is worth
                    // taking. The hint comes from the party being asked about,
                    // so it is a claim rather than evidence; what makes it
                    // usable is that the claim is per tool. A server that
                    // offers both a search and a submit is neither wholly safe
                    // nor wholly dangerous, and any single answer for the whole
                    // server is wrong about half of it.
                    //
                    // Untrusted, or trusted but unannotated, both come out as a
                    // write. Silence is not a claim to be read-only, and a tool
                    // that declined to say is exactly the one not to assume
                    // about.
                    mutates: !$server->toolReadsOnly($declared),
                    // Who may reach for it, and the answer depends on whose
                    // credential the call uses.
                    //
                    // An instance-authenticated server calls with one shared
                    // credential an administrator configured, so the AI
                    // configuration right is what stands between that
                    // credential and everybody.
                    //
                    // A per-user server has nothing shared to protect: the call
                    // uses the technician's own token, obtained by them
                    // consenting in their own browser, and it reaches exactly
                    // what they can already reach. Requiring an administrative
                    // right there would mean the only people who could use
                    // their own accounts were the ones who did not need to.
                    right: $server->wantsUserAuth() ? null : 'plugin_glpiai_config',
                    source: 'mcp:' . $server->fields['name'],
                    pinned: !empty($server->fields['always_offer'])
                );
            }
        }

        return $tools;
    }

    /**
     * The servers visible to an entity.
     *
     * Explicitly by entity rather than through the session's active entities:
     * this runs on cron paths too, and a server list that fell back to
     * "everything" without a session would offer one entity's tools while
     * working another's ticket.
     *
     * @return Server[]
     */
    public static function serversFor(int $entities_id): array
    {
        $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entities_id));

        $servers = [];
        foreach (
            getAllDataFromTable(Server::getTable(), ['is_active' => 1]) as $row
        ) {
            $owner = (int) $row['entities_id'];

            $visible = $owner === $entities_id
                || ((bool) $row['is_recursive'] && in_array($owner, $ancestors, true));

            if (!$visible) {
                continue;
            }

            $server         = new Server();
            $server->fields = $row;
            $servers[]      = $server;
        }

        return $servers;
    }

    /**
     * `mcp__<server>__<tool>`, squeezed into sixty-four characters.
     *
     * The server part is truncated rather than the tool part when the whole is
     * too long: the tool name is what the model reasons about, and half a tool
     * name is useless in a way that half a server name is not.
     */
    public static function localName(Server $server, string $remote): string
    {
        $slug = static fn(string $s): string => trim(
            preg_replace('/_+/', '_', preg_replace('/[^a-zA-Z0-9]+/', '_', $s) ?? '') ?? '',
            '_'
        );

        $tool   = $slug($remote);
        $prefix = $slug((string) $server->fields['name']);

        $room = 64 - strlen(self::PREFIX) - strlen($tool) - 2;
        if ($room < 1) {
            return substr(self::PREFIX . $tool, 0, 64);
        }

        return self::PREFIX . substr($prefix, 0, $room) . '__' . $tool;
    }

    /**
     * A handler bound to one server and one remote tool name.
     *
     * The server is looked up by id at call time rather than captured, because
     * a run can span minutes and an administrator disabling a server mid-flight
     * should stop it being called.
     */
    private static function handler(int $servers_id, string $remote): callable
    {
        return static function (array $arguments, ToolContext $context) use ($servers_id, $remote): string {
            $server = new Server();
            if (!$server->getFromDB($servers_id) || !$server->fields['is_active']) {
                throw new ToolException('That MCP server is no longer available.');
            }

            $result = $server->call(
                static fn(Session $session): array => $session->callTool($remote, $arguments)
            );

            if ($result['is_error']) {
                // The server's own words, because they are what tells the model
                // whether to fix its arguments or give up.
                throw new ToolException($result['text'] !== '' ? $result['text'] : 'The remote tool reported an error.');
            }

            return $result['text'];
        };
    }
}
