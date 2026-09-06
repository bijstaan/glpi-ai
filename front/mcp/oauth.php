<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The OAuth callback for an MCP server.
 *
 * Reached only by an administrator's browser coming back from an authorization
 * server, and deliberately *not* anonymous: unlike a login callback there is
 * already a session here, so this needs no firewall exception and no stateless
 * path registration. The session is what tells us the person finishing the
 * authorization is the person who started it.
 *
 * The state parameter is still checked against what was stored, because a
 * signed-in administrator is exactly who an attacker would want to walk through
 * a callback of their own construction.
 *
 * **Two kinds of authorization come back here.** An administrator authorizing
 * the instance, and a technician connecting their own account to a server that
 * authenticates people. They are told apart by where the pending state was
 * found — on the server record, or on that person's own grant — and never by
 * anything in the URL. A technician finishing a personal connection needs no
 * administrative right, which is the entire point; what they do need is to be
 * the person the pending authorization belongs to, and a grant is only ever
 * looked up by the session's own user id.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\Mcp\Grant;
use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Mcp\OAuth;
use GlpiPlugin\Glpiai\Mcp\Server;

// Signed in, and that is the only blanket requirement: the administrative
// right is checked below, but only on the administrative path.
Session::checkLoginUser();

$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');

/**
 * Back to wherever makes sense, with a message.
 *
 * A technician goes back to their own preferences, an administrator to the
 * server they were configuring. Sending a technician to a server form they
 * cannot open would end a successful connection on an access-denied page.
 */
$finish = static function (Server $server, string $message, int $level, bool $personal = false): never {
    Session::addMessageAfterRedirect(htmlspecialchars($message), false, $level);

    if ($personal) {
        Html::redirect((string) (\Preference::getSearchURL()
            . '?forcetab=' . urlencode(GlpiPlugin\Glpiai\Mcp\Connections::class . '$1')));
    }

    Html::redirect(
        $server->getID() > 0
            ? $server->getFormURLWithID((int) $server->getID())
            : Server::getSearchURL()
    );
};

$server = new Server();

// The authorization server's own refusal, which is a normal outcome — somebody
// pressed Cancel — and is reported as it was given rather than translated into
// a generic failure.
if (isset($_GET['error'])) {
    $detail = trim((string) $_GET['error'] . ' ' . (string) ($_GET['error_description'] ?? ''));

    $finish($server, sprintf(__('Authorization was refused: %s', 'glpiai'), $detail), ERROR);
}

if ($state === '' || $code === '') {
    $finish($server, __('That callback was missing its code or state.', 'glpiai'), ERROR);
}

/** @var DBmysql $DB */
global $DB;

// A personal connection first: the pending authorization is on *this person's*
// own grant, so the lookup is scoped to their user id and cannot reach anybody
// else's half-finished connection even if the state were guessed.
foreach (
    $DB->request([
        'FROM'  => Grant::getTable(),
        'WHERE' => ['users_id' => (int) Session::getLoginUserID(), 'NOT' => ['pending' => '']],
    ]) as $row
) {
    $grant = new Grant();
    if (!$grant->getFromDB((int) $row['id'])) {
        continue;
    }

    // Read without clearing, to identify the callback; OAuth::complete() takes
    // and clears it properly, including its own expiry check.
    $pending = json_decode(
        (string) (new GLPIKey())->decrypt((string) $grant->fields['pending']),
        true
    );

    if (!is_array($pending) || !hash_equals((string) ($pending['state'] ?? ''), $state)) {
        continue;
    }

    $mine = new Server();
    if (!$mine->getFromDB((int) $grant->fields['plugin_glpiai_mcps_servers_id'])) {
        $finish($server, __('That connection\'s service no longer exists.', 'glpiai'), ERROR, true);
    }

    try {
        OAuth::complete($mine, $code, $state, $grant);
    } catch (AiException $e) {
        $finish($mine, $e->getMessage(), ERROR, true);
    }

    $finish(
        $mine,
        sprintf(__('Connected your account to %s.', 'glpiai'), (string) $mine->fields['name']),
        INFO,
        true
    );
}

// Otherwise it is the administrative flow, which needs the administrative
// right. Checked here rather than at the top of the file so that a technician
// finishing their own connection never meets it.
Session::checkRight('plugin_glpiai_config', UPDATE);

// Which server this belongs to is found by the state, not passed in the URL: a
// server id in the query string would be something the caller chooses, and the
// state is the only value here that we know we issued.
$found = null;
foreach (
    $DB->request([
        'FROM'  => Server::getTable(),
        'WHERE' => ['NOT' => ['oauth_pending' => '']],
    ]) as $row
) {
    $candidate = new Server();
    $candidate->getFromDB((int) $row['id']);

    $pending = json_decode((string) $candidate->secret('oauth_pending'), true);

    if (is_array($pending) && hash_equals((string) ($pending['state'] ?? ''), $state)) {
        $found = $candidate;
        break;
    }
}

if ($found === null) {
    $finish($server, __('No authorization is in progress for that callback.', 'glpiai'), ERROR);
}

if (!$found->can((int) $found->getID(), UPDATE)) {
    $finish($server, __('You may not authorize that server.', 'glpiai'), ERROR);
}

try {
    OAuth::complete($found, $code, $state);
} catch (AiException $e) {
    $finish($found, $e->getMessage(), ERROR);
}

$finish($found, __('Authorized. GLPI will renew the token by itself from now on.', 'glpiai'), INFO);
