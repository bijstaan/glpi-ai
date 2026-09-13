<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpiai\Assistant\Skill;
use GlpiPlugin\Glpiai\Mcp\Grant;
use GlpiPlugin\Glpiai\Mcp\Server;
use GlpiPlugin\Glpiai\Assistant\Thread;
use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Draft\Drafter;
use GlpiPlugin\Glpiai\Draft\SolutionPanel;
use GlpiPlugin\Glpiai\Reply\Panel as ReplyPanel;
use GlpiPlugin\Glpiai\Reply\Review;
use GlpiPlugin\Glpiai\Triage\Panel;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\Triage\Triage;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\ToolLog;
use GlpiPlugin\Glpiai\UsageLog;

/**
 * Install: three tables, the discovery job, the right, and settings defaults.
 *
 * Note what is *not* a table. Provider credentials live in GLPI's config table,
 * encrypted — a plugin-private table for them would duplicate the config
 * mechanism without adding anything, and would miss `glpi:security:changekey`.
 * The MCP servers do get one, because they are per-entity records with history
 * and rights rather than a settings block.
 */
function plugin_glpiai_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    if (!$DB->tableExists(UsageLog::TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . UsageLog::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `provider` VARCHAR(32) NOT NULL,
                `model` VARCHAR(190) NOT NULL,
                `tier` VARCHAR(16) NOT NULL DEFAULT 'fast',
                `input_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
                `output_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `finish_reason` VARCHAR(32) NULL,
                `fingerprint` CHAR(64) NULL,
                `prompt_text` MEDIUMTEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `reporting` (`date_creation`,`provider`,`model`),
                KEY `entities_id` (`entities_id`),
                KEY `fingerprint` (`fingerprint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(ToolLog::TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . ToolLog::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `tool` VARCHAR(64) NOT NULL,
                `source` VARCHAR(64) NOT NULL DEFAULT 'native',
                `is_mutating` TINYINT NOT NULL DEFAULT 0,
                `arguments` TEXT NULL,
                `is_error` TINYINT NOT NULL DEFAULT 0,
                `error_message` VARCHAR(500) NULL,
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `itemtype` VARCHAR(100) NULL,
                `items_id` INT UNSIGNED NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `reporting` (`date_creation`,`tool`),
                KEY `entities_id` (`entities_id`),
                KEY `item` (`itemtype`,`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Server::getTable())) {
        $DB->doQuery(
            "CREATE TABLE `" . Server::getTable() . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 1,
                `name` VARCHAR(190) NOT NULL,
                `url` VARCHAR(500) NOT NULL DEFAULT '',
                `is_active` TINYINT NOT NULL DEFAULT 0,
                `protocol_version` VARCHAR(20) NOT NULL DEFAULT '',
                `auth_type` VARCHAR(20) NOT NULL DEFAULT 'none',
                `auth_token` TEXT NULL,
                `auth_header` VARCHAR(100) NOT NULL DEFAULT '',
                -- `instance` or `user`: whose credential the calls use. See
                -- Mcp\\Server and Mcp\\Grant.
                `auth_identity` VARCHAR(16) NOT NULL DEFAULT 'instance',
                -- OAuth 2.1. Discovered from the server's own URL where it
                -- publishes the metadata, typed in where it does not.
                `oauth_grant` VARCHAR(32) NOT NULL DEFAULT 'client_credentials',
                `oauth_auth_url` VARCHAR(500) NOT NULL DEFAULT '',
                `oauth_token_url` VARCHAR(500) NOT NULL DEFAULT '',
                `oauth_client_id` VARCHAR(255) NOT NULL DEFAULT '',
                `oauth_client_secret` TEXT NULL,
                `oauth_scope` VARCHAR(500) NOT NULL DEFAULT '',
                `oauth_access_token` TEXT NULL,
                `oauth_refresh_token` TEXT NULL,
                `oauth_expires_at` TIMESTAMP NULL DEFAULT NULL,
                -- The in-flight authorization-code exchange: state, verifier
                -- and redirect, encrypted, single-use.
                `oauth_pending` TEXT NULL,
                `timeout` INT UNSIGNED NOT NULL DEFAULT 30,
                -- Whether this server's own tool annotations are believed.
                --
                -- MCP tools declare `readOnlyHint`, `destructiveHint` and
                -- `idempotentHint` about themselves. The hint is supplied by
                -- the server being asked about, so it is not evidence — but it
                -- is per tool, which a blanket judgement is not: a real server
                -- mixes a search with a submit, and calling all of it writable
                -- or all of it safe is wrong either way.
                --
                -- So the administrator vouches for the *source* once, and the
                -- source distinguishes its own tools. Off by default: until
                -- somebody says they trust it, every tool is a write.
                `trust_annotations` TINYINT NOT NULL DEFAULT 0,
                -- Per-tool decisions that override whatever the annotations
                -- say, or supply one where they say nothing. JSON, keyed by the
                -- remote tool name, values `read` or `write`.
                --
                -- Kept apart from tools_cache so that re-running discovery —
                -- which replaces that wholesale — does not throw away a
                -- judgement somebody made about a tool.
                `tool_overrides` TEXT NULL,
                -- Declare this server's tools on every request, rather than
                -- leaving the model to find them via `find_tools`.
                `always_offer` TINYINT NOT NULL DEFAULT 1,
                `tool_allowlist` TEXT NULL,
                `tools_cache` MEDIUMTEXT NULL,
                `date_lastdiscovery` TIMESTAMP NULL DEFAULT NULL,
                `last_error` VARCHAR(500) NULL,
                `comment` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                -- Unique per entity, not globally: two entities may each have a
                -- server they both call Monitoring, and they are not the same
                -- server.
                UNIQUE KEY `name` (`entities_id`,`name`),
                KEY `entities_id` (`entities_id`,`is_recursive`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Whose credential a server is called with. Added after that table
    // shipped, defaulting to the behaviour every existing server already has:
    // one credential for the instance.
    if (
        $DB->tableExists(Server::getTable())
        && !$DB->fieldExists(Server::getTable(), 'auth_identity')
    ) {
        $DB->doQuery(
            'ALTER TABLE `' . Server::getTable() . "` "
            . "ADD COLUMN `auth_identity` VARCHAR(16) NOT NULL DEFAULT 'instance' AFTER `auth_header`"
        );
    }

    // Added after the MCP server table shipped. `always_offer` defaults to 1
    // so servers configured before this exists start being offered rather than
    // staying invisible, which is the state that made them look ignored;
    // `trust_annotations` defaults to 0, leaving the safety posture where it
    // was until somebody decides otherwise per server.
    foreach (
        [
            'trust_annotations' => 'TINYINT NOT NULL DEFAULT 0',
            'always_offer'      => 'TINYINT NOT NULL DEFAULT 1',
            'tool_overrides'    => 'TEXT NULL',
        ] as $column => $definition
    ) {
        if (!$DB->fieldExists(Server::getTable(), $column)) {
            $DB->doQuery(
                'ALTER TABLE `' . Server::getTable() . '` ADD COLUMN `' . $column . '` ' . $definition
            );
        }
    }

    // `is_readonly` was the first attempt at this and lasted one afternoon: a
    // single yes/no for a whole server, which is the wrong shape for a server
    // that offers both a search and a submit. Replaced by trust_annotations,
    // which asks the same question of each tool. Dropped rather than left
    // behind, since nothing ever read it but that code.
    if ($DB->fieldExists(Server::getTable(), 'is_readonly')) {
        $DB->doQuery('ALTER TABLE `' . Server::getTable() . '` DROP COLUMN `is_readonly`');
    }

    if (!$DB->tableExists(Skill::getTable())) {
        $DB->doQuery(
            "CREATE TABLE `" . Skill::getTable() . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 1,
                `name` VARCHAR(190) NOT NULL,
                -- Comma-separated words that bring this skill into a request.
                -- Empty means every request, which is the honest reading of an
                -- unconditional skill.
                `triggers` TEXT NULL,
                `instructions` MEDIUMTEXT NULL,
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `comment` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`entities_id`,`name`),
                KEY `entities_id` (`entities_id`,`is_recursive`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Suggestion::TABLE)) {
        // One row per ticket, holding both what the model proposed and what a
        // human did about it. The outcome columns are the reason the table
        // exists at all: a suggestion nobody recorded the fate of cannot be
        // measured, and the accept rate is the only honest answer to "is this
        // any good".
        $DB->doQuery(
            "CREATE TABLE `" . Suggestion::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tickets_id` INT UNSIGNED NOT NULL,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `itilcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `urgency` TINYINT NOT NULL DEFAULT 0,
                `impact` TINYINT NOT NULL DEFAULT 0,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `confidence` VARCHAR(8) NOT NULL DEFAULT '',
                `reasoning` VARCHAR(500) NOT NULL DEFAULT '',
                `provider` VARCHAR(32) NULL,
                `model` VARCHAR(190) NULL,
                `error_message` VARCHAR(500) NULL,
                -- '' | accepted | dismissed | matched. See Triage\\Suggestion
                -- for why `matched` is neither of the first two.
                `category_outcome` VARCHAR(12) NOT NULL DEFAULT '',
                `urgency_outcome` VARCHAR(12) NOT NULL DEFAULT '',
                `impact_outcome` VARCHAR(12) NOT NULL DEFAULT '',
                `sop_outcome` VARCHAR(12) NOT NULL DEFAULT '',
                `users_id_decided` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_decided` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                -- One suggestion per ticket. Triage is a thing that happens at
                -- creation, not a running commentary.
                UNIQUE KEY `ticket` (`tickets_id`),
                KEY `queue` (`state`,`id`),
                KEY `reporting` (`entities_id`,`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Draft::TABLE)) {
        // One row per ticket per kind. Regenerating replaces rather than
        // accumulating: a technician asking for a second draft wants a second
        // draft, not a list to choose between.
        $DB->doQuery(
            "CREATE TABLE `" . Draft::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tickets_id` INT UNSIGNED NOT NULL,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `kind` VARCHAR(16) NOT NULL,
                `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `title` VARCHAR(255) NOT NULL DEFAULT '',
                `content` MEDIUMTEXT NULL,
                `gaps` TEXT NULL,
                `confidence` VARCHAR(8) NOT NULL DEFAULT '',
                -- What the draft was built from, in words, for the technician
                -- reading it and for anyone auditing it later.
                `evidence` VARCHAR(255) NOT NULL DEFAULT '',
                `provider` VARCHAR(32) NULL,
                `model` VARCHAR(190) NULL,
                `error_message` VARCHAR(500) NULL,
                `outcome` VARCHAR(12) NOT NULL DEFAULT '',
                `knowbaseitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id_decided` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_decided` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ticket_kind` (`tickets_id`,`kind`),
                KEY `reporting` (`entities_id`,`kind`,`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Grant::getTable())) {
        // One person's credential for one MCP server.
        //
        // Its own table rather than more columns on the server, because the
        // relationship is one row per (server, person) and because these are
        // *somebody's* tokens: a table nothing joins to by accident, that the
        // server form never reads, and that a person's deletion empties.
        $DB->doQuery(
            "CREATE TABLE `" . Grant::getTable() . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpiai_mcps_servers_id` INT UNSIGNED NOT NULL,
                `users_id` INT UNSIGNED NOT NULL,
                `access_token` TEXT NULL,
                `refresh_token` TEXT NULL,
                `expires_at` TIMESTAMP NULL DEFAULT NULL,
                `scope` VARCHAR(500) NOT NULL DEFAULT '',
                -- The in-flight authorization: state, PKCE verifier, redirect.
                -- Per person, which is what lets two technicians be half-way
                -- through connecting at the same time.
                `pending` TEXT NULL,
                `last_used_at` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_person` (`plugin_glpiai_mcps_servers_id`,`users_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Review::TABLE)) {
        // One row per review of a drafted reply, and what became of that reply.
        //
        // A fingerprint of the text rather than the text: the reply itself
        // lands on the ticket where anybody entitled to read it will find it,
        // and a second copy in a plugin's audit table is one nobody would
        // think to look for when a requester asks what was written about them.
        $DB->doQuery(
            "CREATE TABLE `" . Review::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `verdict` VARCHAR(16) NOT NULL DEFAULT '',
                `flag_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `kinds` VARCHAR(255) NOT NULL DEFAULT '',
                `text_hash` CHAR(64) NOT NULL DEFAULT '',
                `text_length` INT UNSIGNED NOT NULL DEFAULT 0,
                `provider` VARCHAR(32) NULL,
                `model` VARCHAR(190) NULL,
                -- Whether the reply was actually posted afterwards, and
                -- whether it had changed by the time it was. Together these
                -- are the only outcome this feature has: it applies nothing,
                -- so there is no accept to count.
                `sent` TINYINT NOT NULL DEFAULT 0,
                `changed` TINYINT NOT NULL DEFAULT 0,
                `date_sent` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `pending` (`itemtype`,`items_id`,`users_id`,`sent`),
                KEY `reporting` (`entities_id`,`flag_count`,`sent`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists(Thread::TABLE)) {
        // One conversation per user per context. The transcript is JSON in one
        // column rather than a row per message: a message is never queried
        // independently of its thread, never updated and never counted, which
        // are the three things that would make a table the right shape.
        $DB->doQuery(
            "CREATE TABLE `" . Thread::TABLE . "` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `title` VARCHAR(255) NOT NULL DEFAULT '',
                -- Empty when the panel was opened somewhere with no record on
                -- it, which is an ordinary case rather than a missing value.
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `messages` MEDIUMTEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `mine` (`users_id`,`itemtype`,`items_id`,`date_mod`),
                KEY `retention` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    CronTask::register(
        Triage::class,
        'triage',
        5 * MINUTE_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Suggest triage for newly created tickets',
        ]
    );

    CronTask::register(
        Server::class,
        'mcpdiscovery',
        DAY_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Refresh the tool list offered by each configured MCP server',
        ]
    );

    plugin_glpiai_install_rights();

    plugin_glpiai_drop_semantic();

    // Seeded rather than left to the Settings defaults so the values are
    // visible in the config table — an administrator auditing what a plugin
    // will do should be able to read it, not infer it from code.
    //
    // Only the keys that are *missing*, though. GLPI runs this hook again on
    // every upgrade of the plugin, and `Config::setConfigurationValues()`
    // overwrites a key that already exists — so seeding the whole default set
    // would reset an administrator's configuration to stock on every update:
    // the provider, the entity allowlist, every feature switch, silently and
    // with nothing to say why. Writing only what is absent keeps the audit
    // intent and is also what lets a new setting arrive in an upgrade at all.
    $stored  = Config::getConfigurationValues(
        PLUGIN_GLPIAI_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );
    $missing = array_diff_key(Settings::DEFAULTS, $stored);
    if ($missing !== []) {
        Config::setConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, $missing);
    }

    return true;
}

/**
 * One right, held by whoever configures GLPI.
 *
 * Deliberately not split per feature yet. There is nothing for a
 * non-administrator to do here until the first feature ships; inventing a
 * permission model before the thing it governs exists tends to produce rights
 * that do not match how the feature ends up working.
 *
 * Granted as the full standard set rather than READ|UPDATE, because the MCP
 * servers are GLPI items: without CREATE and PURGE, `canCreate()` is false and
 * the "new server" form renders as an empty page — no error, no explanation.
 */
function plugin_glpiai_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    $right = 'plugin_glpiai_config';

    $exists = false;
    foreach (
        $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => $right],
            'LIMIT'  => 1,
        ]) as $row
    ) {
        $exists = true;
    }

    // GLPI runs the install hook on upgrade too, and addProfileRights() inserts
    // unconditionally — calling it for an existing right raises a duplicate-key
    // error that aborts the whole upgrade.
    if (!$exists) {
        ProfileRight::addProfileRights([$right]);
    }

    // Granted to profiles that can already administer configuration. Keying off
    // the installing user's session does not work: plugins are routinely
    // installed from the console, where there is no active profile.
    $targets = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        $targets[] = (int) $row['profiles_id'];
    }

    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $targets[] = (int) $_SESSION['glpiactiveprofile']['id'];
    }

    foreach (array_unique($targets) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, [$right => ALLSTANDARDRIGHT]);
    }
}

function plugin_glpiai_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (
        [
            UsageLog::TABLE, ToolLog::TABLE,
            Suggestion::TABLE, Draft::TABLE, Thread::TABLE, Server::getTable(),
            Review::TABLE, Grant::getTable(),
        ] as $table
    ) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    CronTask::unregister('glpiai');

    ProfileRight::deleteProfileRights(['plugin_glpiai_config']);

    // Settings::allKeys() enumerates the provider credential keys as well as
    // the global ones, so uninstalling really does remove the stored secrets
    // rather than leaving encrypted keys behind in glpi_configs.
    Config::deleteConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, Settings::allKeys());

    return true;
}

// ---------------------------------------------------------------- dispatchers

/**
 * Fan one item event out to the features that care about it.
 *
 * GLPI allows a single callback per itemtype per hook, and a purged ticket
 * interests three features. None of them is asked about here: each already
 * knows its own rules — which itemtypes it covers, whether its feature switch is
 * on — and a dispatcher that second-guessed them would be a second place for
 * those rules to be wrong.
 */
function plugin_glpiai_item_add(CommonDBTM $item)
{
    Triage::ticketCreated($item);
}

function plugin_glpiai_item_purge(CommonDBTM $item)
{
    Triage::ticketPurged($item);
    Drafter::ticketPurged($item);

    // A conversation about a record does not outlive the record. It quotes it.
    Thread::forgetItem($item::class, (int) $item->getID());

    // Nor does the record that a reply on it was reviewed. It holds no reply
    // text, only a fingerprint, but a purged ticket should leave nothing.
    if ($item instanceof CommonITILObject) {
        Review::forget($item);
    }
}

/**
 * A followup was posted: close off the reply review it answers, if any.
 *
 * Its own dispatcher rather than a branch of plugin_glpiai_item_add(), which is
 * registered for Ticket — GLPI allows one callback per itemtype per hook, and a
 * function that had to ask which itemtype it was handed would be one place for
 * two unrelated features to interfere with each other.
 *
 * No feature flag: a review recorded while the feature was on must still be
 * closed off after somebody switches it off, or the numbers keep an open row
 * that never resolves.
 */
function plugin_glpiai_followup_add(CommonDBTM $item)
{
    if ($item instanceof ITILFollowup) {
        Review::observeSend($item);
    }
}

/**
 * A person was deleted: their own MCP credentials go too.
 *
 * Its own dispatcher rather than a branch of the item purge, which is about
 * records this plugin kept *about* an item. This is about credentials belonging
 * to a person, and the two have nothing in common but the hook.
 */
function plugin_glpiai_user_purge(CommonDBTM $item)
{
    if ($item instanceof User) {
        Grant::forgetUser((int) $item->getID());
    }
}

/**
 * The triage panel, above the fields of an existing ticket.
 *
 * Nothing renders in the helpdesk interface. Every AI feature in this plugin is
 * technician-facing by design, and a suggestion chip on a requester's own
 * ticket would put a model's opinion in front of a requester — which is the one
 * thing the roadmap rules out.
 *
 * Seeing the panel takes no right beyond seeing the ticket, and deliberately
 * not `plugin_glpiai_config`: that right belongs to whoever holds the provider
 * credentials, which is an administrator, and gating a technician's triage
 * chips behind it would mean the feature was invisible to everybody it was
 * built for. Acting on a chip is a different question, and is answered by
 * `Ticket::canUpdateItem()` per ticket — both here, for whether to draw the
 * apply button, and again in ajax/triage.php, which is the check that counts.
 */
function plugin_glpiai_pre_item_form($params)
{
    $item = $params['item'] ?? null;

    // GLPI fires this hook from several forms, and the solution editor is one
    // of them — which is where a drafted solution is actually wanted. The
    // solution handed over is blank; the ticket it belongs to is in the hook's
    // options, which is the only place it exists at that point.
    //
    // The ticket's own triage panel is not drawn here: it belongs to the ITIL
    // field panel and is drawn from PRE_ITIL_INFO_SECTION instead — see
    // plugin_glpiai_pre_itil_info_section().
    if ($item instanceof ITILSolution) {
        SolutionPanel::render($item, $params['options']['item'] ?? null);
    }

    // The reply editor. Note what is *not* passed: the followup form's options
    // do not carry the parent — the timeline includes that template with
    // `form_mode`, `subitem` and `mention_options` and nothing else — so the
    // panel reads the itemtype and items_id core sets on the blank followup
    // itself. Reading $params['options']['item'] here, as the solution branch
    // above legitimately does, renders nothing at all and says nothing about
    // why.
    if ($item instanceof ITILFollowup) {
        ReplyPanel::render($item);
    }
}

/**
 * The triage chips, in the ticket's field panel.
 *
 * A hook of its own rather than a branch of the one above, because a callback
 * cannot ask which hook invoked it: registering one function on both would
 * draw the chips twice on every ticket, once in each place.
 */
function plugin_glpiai_pre_itil_info_section($params)
{
    $item = $params['item'] ?? null;

    if (
        !($item instanceof Ticket)
        || $item->isNewItem()
        || ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central'
        || !$item->canViewItem()
    ) {
        return;
    }

    Panel::render($item);
}

/**
 * Remove what semantic search left behind.
 *
 * The feature is gone, so its table is dead weight — tens of thousands of
 * vectors nothing reads — and its settings are dead credentials, which is
 * worse. Neither can be enumerated from code any more, since the classes that
 * knew the key names went with the feature, so the names are written out here.
 *
 * Runs from the install hook, which GLPI also runs on upgrade. It is a
 * one-way step: a site that pulls the feature back would rebuild the index
 * from scratch, which is what a model change made it do anyway.
 */
function plugin_glpiai_drop_semantic()
{
    /** @var DBmysql $DB */
    global $DB;

    if ($DB->tableExists('glpi_plugin_glpiai_embeddings')) {
        $DB->doQuery('DROP TABLE `glpi_plugin_glpiai_embeddings`');
    }

    // By itemtype rather than CronTask::unregister(), which takes a plugin name
    // and would take the triage and MCP-discovery jobs with it.
    $DB->delete('glpi_crontasks', ['itemtype' => 'GlpiPlugin\\Glpiai\\Search\\Indexer']);

    $dead = [
        'semantic_enabled', 'embedder', 'semantic_types',
        'semantic_candidates', 'semantic_batch',
    ];

    // The union of every field the three embedders declared. Over-listing is
    // free — deleting a config key that was never written is a no-op — and
    // under-listing leaves an encrypted API key in the table forever.
    foreach (['openai_compatible', 'azure', 'gemini'] as $embedder) {
        foreach (
            [
                'base_url', 'api_key', 'model', 'api_version', 'auth_mode',
                'tenant_id', 'client_id', 'client_secret', 'scope', 'authority',
                'dimensions', 'batch_size', 'timeout',
            ] as $field
        ) {
            $dead[] = 'emb_' . $embedder . '_' . $field;
        }
    }

    Config::deleteConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, $dead);
}
