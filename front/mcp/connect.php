<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Connect, disconnect, or store a personal token for one MCP server.
 *
 * Everything here acts on the signed-in person's own credential and nothing
 * else. There is no user id in the request and no way to name one: the grant is
 * always looked up from the session, so the worst a forged post can do is
 * connect or disconnect the account of whoever sent it — which is the person
 * doing it.
 *
 * **What stands in for a right here is the CSRF token.** These are
 * state-changing posts with no permission gate, so the thing that must hold is
 * that the request came from a page this instance rendered: GLPI 11 validates
 * the token before the script runs, on the strength of this plugin declaring
 * `csrf_compliant`, and the form emits one. Without that, a forged post could
 * store a *token of the attacker's choosing* against a technician's account —
 * which would then be used for their questions, sending their work to somebody
 * else's service. That is the one attack this file has to be proof against.
 *
 * No administrative right is checked, deliberately. Requiring one would mean
 * the only technicians who could connect their own accounts were the ones who
 * did not need to. What is checked is that the server exists, that it is
 * visible in one of the person's entities, and that it actually asks for a
 * personal credential — a server authenticated as the instance has nothing here
 * for anybody to connect.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Mcp\Catalogue;
use GlpiPlugin\Glpiai\Mcp\Connections;
use GlpiPlugin\Glpiai\Mcp\Grant;
use GlpiPlugin\Glpiai\Mcp\OAuth;
use GlpiPlugin\Glpiai\Mcp\Server;

Session::checkLoginUser();

/** Back to the tab this came from, with something to read. */
$back = static function (string $message, int $level): never {
    Session::addMessageAfterRedirect(htmlspecialchars($message), false, $level);

    Html::redirect(
        Preference::getSearchURL() . '?forcetab=' . urlencode(Connections::class . '$1')
    );
};

$servers_id = (int) ($_POST['servers_id'] ?? 0);

// Visible to *this* person, in one of their entities — not merely existing.
// Looked up through the same catalogue the tool list uses, so the two can never
// disagree about which servers somebody may reach.
$server = null;
foreach (Connections::forMe() as $entry) {
    if ((int) $entry['server']->getID() === $servers_id) {
        $server = $entry['server'];
        break;
    }
}

if (!$server instanceof Server) {
    $back(__('That service is not one you can connect.', 'glpiai'), ERROR);
}

$grant = Grant::open($servers_id);

if ($grant === null) {
    $back(__('That connection could not be opened.', 'glpiai'), ERROR);
}

// ------------------------------------------------------------------ disconnect

if (!empty($_POST['disconnect'])) {
    $grant->disconnect();

    $back(
        sprintf(
            __('Disconnected from %s. GLPI no longer holds a token for your account — revoke it '
                . 'at the service itself if you want the access gone entirely.', 'glpiai'),
            (string) $server->fields['name']
        ),
        INFO
    );
}

// ------------------------------------------------------------ a personal token

if (!empty($_POST['save_token'])) {
    $token = trim((string) ($_POST['token'] ?? ''));

    if ($token === '') {
        $back(__('No token was given.', 'glpiai'), ERROR);
    }

    // Kept in the same column an OAuth access token uses, with no expiry: a
    // personal API key does not expire on a schedule, and inventing one would
    // disconnect somebody on a timer for no reason. A far-future expiry rather
    // than none, so the freshness check has something to read.
    $grant->keep($token, null, 10 * YEAR_TIMESTAMP);

    $back(
        sprintf(__('Saved your token for %s.', 'glpiai'), (string) $server->fields['name']),
        INFO
    );
}

// ------------------------------------------------------------------- connect

if (empty($_POST['connect'])) {
    $back(__('Nothing to do.', 'glpiai'), ERROR);
}

if ((string) $server->fields['auth_type'] !== Server::AUTH_OAUTH) {
    $back(__('That service is not connected with OAuth.', 'glpiai'), ERROR);
}

/** @var array $CFG_GLPI */
global $CFG_GLPI;

try {
    // The same callback the administrative flow uses, spelled the same way:
    // absolute, because it leaves the instance and comes back, and it has to
    // match what was registered with the authorization server exactly. One
    // redirect URI per instance — a second one for personal connections would
    // mean every provider needing two registrations.
    $redirect = $CFG_GLPI['url_base'] . $CFG_GLPI['root_doc']
              . '/plugins/glpiai/front/mcp/oauth.php';

    $url = OAuth::begin($server, $redirect, $grant);
} catch (AiException $e) {
    $back($e->getMessage(), ERROR);
}

Html::redirect($url);
