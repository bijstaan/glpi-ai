<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * MCP servers that authenticate the person rather than the instance.
 *
 * The feature is a separation, so the assertions are mostly about what one
 * person's credential does *not* do. Four of them are the whole point:
 *
 *  - another technician's lookup finds nothing, and their call is refused;
 *  - a run with no signed-in person — cron, the mail collector — is refused
 *    rather than falling back to whichever grant happens to exist, which is the
 *    failure this feature exists to prevent;
 *  - the pending authorization lives on the person's own grant, so two people
 *    can be half-way through connecting at once;
 *  - nothing personal is ever written to the server record, which is the row an
 *    administrator can read.
 *
 * The rest check that a refusal says what to do about it: an unconnected server
 * is a normal state, met by every new technician on their first day, and "AUTH
 * error" is not an answer they can act on.
 *
 * Usage, inside the GLPI container:
 *   php tests/mcp-user-auth.php
 */

require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Mcp\Catalogue;
use GlpiPlugin\Glpiai\Mcp\Connections;
use GlpiPlugin\Glpiai\Mcp\Grant;
use GlpiPlugin\Glpiai\Mcp\OAuth;
use GlpiPlugin\Glpiai\Mcp\Server;

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}
(new Plugin())->init(true);
global $DB;

$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name
       . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

$made = [];
register_shutdown_function(function () use (&$made) {
    foreach (array_reverse($made) as [$class, $id]) { (new $class())->delete(['id' => $id], true); }
});

// --- a server that authenticates people
$server  = new Server();
$servers_id = (int) $server->add([
    'name'          => 'ZZ grants-check',
    'url'           => 'http://127.0.0.1:9098/mcp',
    'entities_id'   => 0,
    'is_recursive'  => 1,
    'is_active'     => 1,
    'auth_type'     => Server::AUTH_BEARER,
    'auth_identity' => Server::IDENTITY_USER,
    'timeout'       => 10,
]);
$made[] = [Server::class, $servers_id];
$server->getFromDB($servers_id);

check('a per-user server was created', $servers_id > 0);
check('and says it wants a person', $server->wantsUserAuth());

echo "\n== with nothing connected ==\n";

check('the grant lookup refuses, with something to act on', (function (Server $s): bool {
    try { $s->grant(); return false; }
    catch (AiException $e) { return str_contains($e->getMessage(), 'My settings'); }
})($server));

check('and so does building the headers', (function (Server $s): bool {
    try { $s->authHeaders(); return false; }
    catch (AiException $e) { return $e->kind === AiException::AUTH; }
})($server));

echo "\n== the tab ==\n";
$mine = Connections::forMe();
$row  = null;
foreach ($mine as $entry) { if ((int) $entry['server']->getID() === $servers_id) { $row = $entry; } }
check('it appears in my connections', $row !== null);
check('marked as not connected', $row !== null && $row['connected'] === false);
check('and counted as outstanding', Connections::outstanding() >= 1);

echo "\n== connecting ==\n";
$grant = Grant::open($servers_id);
check('a grant opened for me', $grant !== null && (int) $grant->fields['users_id'] === (int) Session::getLoginUserID());
$grant->keep('my-personal-token', null, 3600);
check('the token is readable back', $grant->secret('access_token') === 'my-personal-token');
check('and is NOT stored in plain text',
    !str_contains((string) $grant->fields['access_token'], 'my-personal-token'),
    substr((string) $grant->fields['access_token'], 0, 24));

$server->getFromDB($servers_id);
$headers = $server->authHeaders();
check('the call now carries my token', ($headers['Authorization'] ?? '') === 'Bearer my-personal-token',
    json_encode($headers));
check('and nothing landed on the server record',
    (string) $server->fields['auth_token'] === '' && (string) $server->fields['oauth_access_token'] === '');

echo "\n== somebody else ==\n";
$other = new User();
$others_id = (int) $other->add([
    'name' => 'zz.grants.other', 'realname' => 'Other',
    'password' => 'not-used-here-1234', 'password2' => 'not-used-here-1234',
]);
$made[] = [User::class, $others_id];

$was = $_SESSION['glpiID'];
$_SESSION['glpiID'] = $others_id;

check('their lookup finds nothing of mine', Grant::mine($servers_id) === null);
check('and the server refuses them by name', (function (Server $s): bool {
    try { $s->authHeaders(); return false; }
    catch (AiException $e) { return $e->kind === AiException::AUTH; }
})($server));

$_SESSION['glpiID'] = $was;

echo "\n== no session at all (cron) ==\n";
$_SESSION['glpiID'] = 0;
check('a background run is refused, not fallen back', (function (Server $s): bool {
    try { $s->grant(); return false; }
    catch (AiException $e) { return str_contains($e->getMessage(), 'background work'); }
})($server));
$_SESSION['glpiID'] = $was;

echo "\n== the OAuth flow, per person ==\n";
$oauth = new Server();
$oauth_id = (int) $oauth->add([
    'name' => 'ZZ grants-check oauth', 'url' => 'https://example.invalid/mcp',
    'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1,
    'auth_type' => Server::AUTH_OAUTH, 'auth_identity' => Server::IDENTITY_USER,
    'oauth_grant' => OAuth::AUTHORIZATION_CODE, 'oauth_auth_url' => 'https://example.invalid/authorize',
    'oauth_token_url' => 'https://example.invalid/token', 'oauth_client_id' => 'cid', 'timeout' => 10,
]);
$made[] = [Server::class, $oauth_id];
$oauth->getFromDB($oauth_id);

$g = Grant::open($oauth_id);
$url = OAuth::begin($oauth, 'https://glpi.example/plugins/glpiai/front/mcp/oauth.php', $g);
check('it sends the person to the provider with PKCE',
    str_contains($url, 'code_challenge=') && str_contains($url, 'code_challenge_method=S256'));
check('and with the resource indicator', str_contains($url, 'resource='));

$g->getFromDB((int) $g->getID());
check('the pending state is on MY grant, not the server',
    (string) $g->fields['pending'] !== '' && (string) $oauth->fields['oauth_pending'] === '');

parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$pending = $g->takePending();
check('the state matches what was sent', ($pending['state'] ?? '') === ($q['state'] ?? 'x'));
check('and it is single use', $g->takePending() === null);

echo "\n== what the model is offered ==\n";
$tools = Catalogue::tools(0);
// Building the catalogue must not throw for a server nobody has connected:
// the refusal belongs at call time, where it can name the person and tell them
// where to go, not at list time where it would take every other tool with it.
check('an unconnected per-user server does not break the tool list', is_array($tools));
foreach ($tools as $t) {
    if (str_contains($t->name, 'grants-check')) {
        check('and they are gated on the connection, not the config right', $t->right === null, (string) $t->right);
    }
}

echo "\n== purge ==\n";
$grant_id = (int) $grant->getID();
(new Server())->delete(['id' => $servers_id], true);
array_shift($made);
check('deleting the server took the credential with it',
    countElementsInTable(Grant::getTable(), ['id' => $grant_id]) === 0);

echo "\n";
if ($failures === []) {
    echo "\033[32mAll checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($failures) . " failed.\033[0m\n";
exit(1);
