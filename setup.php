<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI AI — a vendor-neutral model layer for GLPI, and the features built on it.
 *
 * This first cut ships the substrate only: one normalised request/response
 * vocabulary, adapters for Anthropic, OpenAI, Gemini and Azure AI Foundry, a
 * settings page that generates itself from whatever providers are registered,
 * and the two things that have to exist before any feature does — a per-entity
 * gate on whether data may leave at all, and a usage log recording which model
 * answered.
 *
 * The features are all technician-facing by design: the model proposes, a
 * person disposes, and nothing reaches a requester unread.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpiai\Draft\Tab as DraftTab;
use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Skill;
use GlpiPlugin\Glpiai\Mcp\Connections as McpConnections;
use GlpiPlugin\Glpiai\Mcp\Grant as McpGrant;
use GlpiPlugin\Glpiai\Mcp\Server as McpServer;
use GlpiPlugin\Glpiai\MobileController;
use GlpiPlugin\Glpiai\Settings;

define('PLUGIN_GLPIAI_VERSION', '0.7.0');
define('PLUGIN_GLPIAI_MIN_GLPI', '12.0');

// Settings and provider credentials live under this config context.
define('PLUGIN_GLPIAI_CONFIG_CONTEXT', 'plugin:glpiai');

function plugin_init_glpiai()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpiai'] = true;

    // The plugin's rights, on Administration > Profiles.
    //
    // Core stores a plugin's rights and saves them back with its own, but
    // renders a form for its rights only — so without this tab the ones below
    // are enforced everywhere and grantable nowhere but SQL.
    Plugin::registerClass(\GlpiPlugin\Glpiai\Profile::class, ['addtabon' => ['Profile']]);

    // config_page and nothing else. Registering a Setup-menu entry as well would
    // put a second link to this same page one row below the Plugins entry that
    // already points at it, and eight plugins doing that is what turns the Setup
    // menu into a list nobody reads. Everything else this plugin has a page for
    // hangs off the settings page instead.
    $PLUGIN_HOOKS['config_page']['glpiai'] = 'front/config.php';

    // The MCP server list, and only that.
    //
    // The settings page keeps its no-menu-entry stance above — Setup > Plugins
    // already reaches it. The server list is a different thing: it is a list of
    // records, which the Plugins page does not reach, and GLPI takes the Add
    // button on a list page from this registration rather than from the page
    // itself. Without an entry here the list rendered correctly and could not
    // be added to, which is not a failure anything reports.
    $PLUGIN_HOOKS['menu_toadd']['glpiai'] = [
        'config' => [McpServer::class, Skill::class],
    ];

    Plugin::registerClass(McpServer::class);
    // Registering it is also what puts skills in the search engine, which gives
    // the library filtering and entity scoping for free.
    Plugin::registerClass(Skill::class);

    // `addtabon` is what actually attaches the tab; registerClass alone does
    // nothing visible.
    Plugin::registerClass(DraftTab::class, ['addtabon' => [Ticket::class]]);

    // "AI connections" on a person's own preferences: where a technician
    // authorises their own account for an MCP server that authenticates people
    // rather than the instance. My settings is where a person's own
    // preferences live, and this is one — the server form is administrative
    // and the people who need to connect are exactly the ones who cannot open
    // it. The tab hides itself when no server asks for a personal credential.
    Plugin::registerClass(McpConnections::class, ['addtabon' => ['Preference']]);

    // The MCP token lives in this plugin's own table, so naming the column here
    // is the correct use of SECURED_FIELDS — unlike glpi_configs.value, which
    // belongs to core and is shared with everything else GLPI stores.
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['glpiai'] = [
        McpServer::getTable() . '.auth_token',
        McpServer::getTable() . '.oauth_client_secret',
        McpServer::getTable() . '.oauth_access_token',
        McpServer::getTable() . '.oauth_refresh_token',
        McpServer::getTable() . '.oauth_pending',
        // Per-technician credentials for the servers that authenticate people
        // rather than the instance. Named here so a key rotation re-encrypts
        // them too — a rotation that missed these would leave every technician
        // silently disconnected, with no error to explain it.
        McpGrant::getTable() . '.access_token',
        McpGrant::getTable() . '.refresh_token',
        McpGrant::getTable() . '.pending',
    ];

    /**
     * Reacting to items changing.
     *
     * Everything here marks work and returns; nothing calls a provider. A
     * technician saving a ticket must not wait on a model, and a model that is
     * down must not stop them saving — so the cron tasks do the work and these
     * only record what needs doing.
     *
     * GLPI allows one callback per itemtype per hook, so these go through
     * dispatchers in hook.php rather than naming a method directly. Each
     * feature still decides for itself whether it cares; the dispatcher does
     * not know their rules.
     *
     * The purge hooks have no feature flag on purpose: an item deleted while a
     * feature was switched off must still lose the copy this plugin kept of it.
     */
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpiai']   = [
        Ticket::class        => 'plugin_glpiai_item_add',
        // A posted reply closes off the review of it, if there was one. That
        // is the whole outcome measurement for reply review: nothing is
        // applied, so there is no accept to count — only whether the text
        // changed between being read and being sent.
        ITILFollowup::class  => 'plugin_glpiai_followup_add',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpiai'] = [];

    // Purge covers everything this plugin keeps a copy of or a conversation
    // about — tickets, and every itemtype the assistant can start a thread
    // from. A thread quotes the record it is about, so a Computer missing from
    // this list means a conversation that outlives the machine it discusses.
    foreach (
        array_unique(array_merge(
            GlpiPlugin\Glpiai\Assistant\Context::SUPPORTED,
            [Ticket::class]
        )) as $itemtype
    ) {
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpiai'][$itemtype] = 'plugin_glpiai_item_purge';
    }

    // A person leaving takes their delegated credentials with them. This is the
    // one a data-protection question is actually about: tokens that reach
    // somebody's mailbox must not outlive their account.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpiai'][User::class] = 'plugin_glpiai_user_purge';

    /**
     * The triage panel, above the ticket fields — and the drafted solution,
     * in the solution editor.
     *
     * Not a tab: both are things a technician does while looking at the form,
     * and a tab means noticing it, clicking away, and clicking back.
     *
     * Two hooks, and a callback each, because the two surfaces are different
     * places and a callback cannot ask which hook invoked it. The triage panel
     * belongs to the ITIL field panel, and PRE_ITEM_FORM lands it inside the
     * "Ticket" accordion body, which core's fields_panel script force-collapses
     * below 768px — rendered, and display:none on a phone. PRE_ITIL_INFO_SECTION
     * renders beside the sections instead. The solution editor is an ordinary
     * form with no such panel, so the drafted solution stays where it was.
     */
    $PLUGIN_HOOKS[Hooks::PRE_ITIL_INFO_SECTION]['glpiai'] = 'plugin_glpiai_pre_itil_info_section';
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['glpiai']         = 'plugin_glpiai_pre_item_form';

    // assistant.js builds its own panel on every central page, the way
    // glpi-palette does: GLPI has no hook that renders markup into every page,
    // and one PHP-rendered panel per request would put a database read in front
    // of every page for something most of them never open.
    //
    // It is only shipped to somebody who can use it. The script draws a button
    // in the header the moment it loads, and it has no way of knowing whether
    // the feature is on, a provider is configured, or this entity is permitted
    // — so it drew one regardless, and the button then failed at the endpoint.
    // A control that is visible and refuses is worse than no control: it reads
    // as broken rather than as switched off, and there is nothing on screen to
    // say which.
    //
    // Deciding here costs the settings read on a central page load. That is one
    // cached config lookup against a script and a panel that would otherwise be
    // sent to every user on every page for a feature they cannot reach.
    $scripts = ['js/ai.js'];
    if (\Session::getLoginUserID() !== false && Assistant::available()) {
        $scripts[] = 'js/assistant.js';
    }

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpiai'] = $scripts;
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpiai']        = ['css/ai.css', 'css/assistant.css'];

    // High-level API routes for the technician app (glpi-mobile): the
    // assistant, the drafted solution, the triage suggestion and the reply
    // review. The HL layer authenticates the bearer; every route re-checks
    // this plugin's own rules, mirroring the ajax/ endpoints it stands in for.
    $PLUGIN_HOOKS['api_controllers']['glpiai'] = [MobileController::class];

    /**
     * Feature discovery for glpi-mobile's /capabilities endpoint, evaluated
     * per session by that plugin. Cheap by contract — settings reads and the
     * entity gate, nothing that calls a provider.
     */
    $PLUGIN_HOOKS['glpimobile_capabilities']['glpiai'] = 'plugin_glpiai_mobile_capabilities';

    // Declaring which config entries hold credentials is what makes GLPI
    // encrypt them on write, mask them in the history log, and re-encrypt them
    // when an administrator runs `glpi:security:changekey`. Without it, a key
    // rotation silently orphans every stored credential and the plugin starts
    // failing auth with no obvious cause.
    //
    // SECURED_CONFIGS, specifically — not SECURED_FIELDS. That hook takes whole
    // `table.column` pairs, and the only column these values live in is
    // `glpi_configs.value`; naming it would point the key-rotation migration at
    // every configuration entry in GLPI, ours and core's alike, and re-encrypt
    // the lot.
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['glpiai'] = Settings::secretKeys();
}

function plugin_version_glpiai()
{
    return [
        'name'         => 'GLPI AI',
        'version'      => PLUGIN_GLPIAI_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-ai',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIAI_MIN_GLPI]],
    ];
}

function plugin_glpiai_check_prerequisites()
{
    return true;
}

function plugin_glpiai_check_config($verbose = false)
{
    return true;
}

/**
 * What this plugin offers the technician app, for whoever is asking.
 *
 * Read by glpi-mobile's `/capabilities` endpoint once per session, with that
 * session's rights and entity. Every answer here is about *this* caller in
 * *this* entity rather than about the instance: the entity gate is what
 * decides whether data may reach a provider at all, and a helpdesk-interface
 * session gets nothing, because every AI feature in this plugin is
 * technician-facing by design.
 *
 * Settings reads only. The contract says this runs on the session path, so it
 * must not call a provider, and it must not be the thing that makes signing in
 * slow.
 *
 * @return array{version:string,features:array<string,bool>}
 */
function plugin_glpiai_mobile_capabilities(): array
{
    $features = [
        'assistant'    => false,
        'draft'        => false,
        'reply_review' => false,
        'triage'       => false,
    ];

    if (Session::getCurrentInterface() === 'central') {
        $entities_id = (int) Session::getActiveEntity();
        // Both halves of "may this happen here": the master switch, and the
        // per-entity allowlist that decides whether this entity's data may
        // leave the instance.
        $permitted = Settings::flag('enabled') && Settings::entityAllowed($entities_id);

        $features['assistant']    = Assistant::available($entities_id);
        $features['draft']        = $permitted && Settings::flag('draft_enabled');
        $features['reply_review'] = $permitted && Settings::flag('reply_review_enabled');
        $features['triage']       = $permitted && Settings::flag('triage_enabled');
    }

    return ['version' => PLUGIN_GLPIAI_VERSION, 'features' => $features];
}
