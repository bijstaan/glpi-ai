<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * MCP OAuth 2.1, and tool search.
 *
 * Two features that arrived together and have nothing to do with each other,
 * tested together because both are about what a *request* carries rather than
 * about what a model says.
 *
 * The OAuth half is asserted against a mock authorization server that actually
 * checks things — the client id, the client secret, the PKCE verifier, and
 * whether a code has already been used. A mock that accepted anything would let
 * a client that never sends its secret pass every test it has.
 *
 * The toolbox half is about a threshold and a ranking, both of which are easy
 * to get subtly wrong in ways that produce no error: a search that returns the
 * wrong tool looks exactly like a search that returns the right one.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9098 tests/mock-mcp.php &
 *   php tests/oauth-toolbox.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Mcp\OAuth;
use GlpiPlugin\Glpiai\Mcp\Server;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\Toolbox;
use GlpiPlugin\Glpiai\ToolRegistry;

require __DIR__ . '/config-guard.php';

/** @var DBmysql $DB */
global $DB;

const AS_BASE  = 'http://127.0.0.1:9098';
const MCP_URL  = 'http://127.0.0.1:9098/mcp/oauth';
const REDIRECT = 'http://localhost:8081/plugins/glpiai/front/mcp/oauth.php';

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);

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

$before  = GlpiaiConfigGuard::snapshot();
$servers = [];

register_shutdown_function(static function () use ($before, &$servers): void {
    foreach ($servers as $id) {
        (new Server())->delete(['id' => $id], true);
    }

    // Raw rows, straight back. Restoring through the config API re-encrypts
    // anything secured, a layer per run — see tests/config-guard.php.
    GlpiaiConfigGuard::restore($before);

    @unlink(sys_get_temp_dir() . '/glpiai-oauth.json');
});

Settings::save(['enabled' => '1', 'entity_mode' => 'all']);

// ------------------------------------------------------------------ discovery

echo "\nFinding the authorization server\n";

$found = OAuth::discover(MCP_URL);

check('the token endpoint is discovered from the MCP URL alone',
    ($found['token_endpoint'] ?? '') === AS_BASE . '/token', json_encode($found));
check('and the authorization endpoint with it',
    ($found['authorization_endpoint'] ?? '') === AS_BASE . '/authorize');
check('the issuer comes back too', ($found['issuer'] ?? '') === AS_BASE);

check('a server that publishes nothing discovers nothing rather than guessing',
    OAuth::discover('http://127.0.0.1:9/mcp') === []);
check('and a URL that is not one is handled',
    OAuth::discover('not a url') === []);

// --------------------------------------------------------- client credentials

echo "\nClient credentials\n";

$server = new Server();
$cc     = (int) $server->add([
    'name'                => 'glpiai-oauth-cc',
    'url'                 => MCP_URL,
    'entities_id'         => 0,
    'is_active'           => 1,
    'timeout'             => 30,
    'auth_type'           => Server::AUTH_OAUTH,
    'oauth_grant'         => OAuth::CLIENT_CREDENTIALS,
    'oauth_token_url'     => $found['token_endpoint'],
    'oauth_auth_url'      => $found['authorization_endpoint'],
    'oauth_client_id'     => 'mcp-client',
    'oauth_client_secret' => 'mcp-secret',
    'oauth_scope'         => 'mcp:tools',
]);
$servers[] = $cc;

check('the server saves', $cc > 0);

$server->getFromDB($cc);
check('the client secret is encrypted at rest',
    (string) $server->fields['oauth_client_secret'] !== 'mcp-secret'
    && (string) $server->fields['oauth_client_secret'] !== '');
check('and decrypts back', $server->secret('oauth_client_secret') === 'mcp-secret');

$token = OAuth::token($server);
check('a token is minted', str_starts_with($token, 'access-'), $token);

$server->getFromDB($cc);
check('it is cached on the record', $server->secret('oauth_access_token') === $token);
check('with an expiry', (string) $server->fields['oauth_expires_at'] !== '');
check('the stored token is encrypted, not in plain text',
    (string) $server->fields['oauth_access_token'] !== $token);

check('asking again returns the cached one rather than minting a second',
    OAuth::token($server) === $token);

// Expire it by hand: the cache is what stops every call costing a round trip,
// so "it is reused" and "it is renewed when stale" are different assertions.
$DB->update(Server::getTable(),
    ['oauth_expires_at' => date('Y-m-d H:i:s', time() - 10)], ['id' => $cc]);
$server->getFromDB($cc);

$renewed = OAuth::token($server);
check('an expired token is renewed', $renewed !== $token && str_starts_with($renewed, 'access-'));

check('the header the MCP session will send is a bearer',
    ($server->authHeaders()['Authorization'] ?? '') === 'Bearer ' . $renewed,
    json_encode($server->authHeaders()));

OAuth::forget($server);
$server->getFromDB($cc);
check('forgetting drops the token', $server->secret('oauth_access_token') === '');

// ---------------------------------------------------------------- the refusals

echo "\nWhen the credentials are wrong\n";

$bad = (int) (new Server())->add([
    'name'                => 'glpiai-oauth-bad',
    'url'                 => MCP_URL,
    'entities_id'         => 0,
    'timeout'             => 30,
    'auth_type'           => Server::AUTH_OAUTH,
    'oauth_grant'         => OAuth::CLIENT_CREDENTIALS,
    'oauth_token_url'     => AS_BASE . '/token',
    'oauth_client_id'     => 'mcp-client',
    'oauth_client_secret' => 'wrong-secret',
]);
$servers[] = $bad;

$bad_server = new Server();
$bad_server->getFromDB($bad);

try {
    OAuth::token($bad_server);
    check('a bad secret raises', false);
} catch (AiException $e) {
    check('a bad secret raises', $e->kind === AiException::AUTH);
    // The server's own words. "invalid_client: Bad client secret" is an answer;
    // "HTTP 401" is a puzzle for whoever has to fix it.
    check('and carries what the authorization server actually said',
        str_contains($e->getMessage(), 'invalid_client'), $e->getMessage());
}

$no_endpoint = new Server();
$no_endpoint->getFromDB($bad);
$no_endpoint->fields['oauth_token_url'] = '';

try {
    OAuth::token($no_endpoint);
    check('a missing token endpoint raises', false);
} catch (AiException $e) {
    check('a missing token endpoint raises', str_contains($e->getMessage(), 'no token endpoint'));
}

// -------------------------------------------------------- authorization code

echo "\nAuthorization code, with PKCE\n";

$ac = (int) (new Server())->add([
    'name'                => 'glpiai-oauth-ac',
    'url'                 => MCP_URL,
    'entities_id'         => 0,
    'timeout'             => 30,
    'auth_type'           => Server::AUTH_OAUTH,
    'oauth_grant'         => OAuth::AUTHORIZATION_CODE,
    'oauth_token_url'     => AS_BASE . '/token',
    'oauth_auth_url'      => AS_BASE . '/authorize',
    'oauth_client_id'     => 'mcp-client',
    'oauth_client_secret' => 'mcp-secret',
]);
$servers[] = $ac;

$auth = new Server();
$auth->getFromDB($ac);

try {
    OAuth::token($auth);
    check('an unauthorized server refuses rather than silently failing', false);
} catch (AiException $e) {
    check('an unauthorized server says to press Authorize',
        str_contains($e->getMessage(), 'press Authorize'), $e->getMessage());
}

$url = OAuth::begin($auth, REDIRECT);
parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

check('the authorization URL is on the right endpoint',
    str_starts_with($url, AS_BASE . '/authorize'));
check('it asks for a code', ($params['response_type'] ?? '') === 'code');
check('with PKCE, S256 rather than plain',
    ($params['code_challenge_method'] ?? '') === 'S256' && ($params['code_challenge'] ?? '') !== '');
check('a state to tie the callback back to us', ($params['state'] ?? '') !== '');
check('and the resource indicator, so the token is issued for this server',
    ($params['resource'] ?? '') === MCP_URL, (string) ($params['resource'] ?? ''));

$auth->getFromDB($ac);
check('the pending exchange is stored encrypted',
    (string) $auth->fields['oauth_pending'] !== ''
    && !str_contains((string) $auth->fields['oauth_pending'], $params['state']));

// A state that is not ours must be refused: this is the CSRF defence for the
// callback, and a signed-in administrator is exactly who would be walked
// through one of somebody else's construction.
try {
    OAuth::complete($auth, 'code-whatever', 'not-the-state');
    check('a callback with the wrong state is refused', false);
} catch (AiException $e) {
    check('a callback with the wrong state is refused',
        str_contains($e->getMessage(), 'did not come from us'), $e->getMessage());
}

$auth->getFromDB($ac);
check('and the pending exchange is consumed either way, so it cannot be replayed',
    (string) $auth->fields['oauth_pending'] === '');

// The happy path: follow the redirect the way a browser would.
$auth->getFromDB($ac);
$url = OAuth::begin($auth, REDIRECT);
$auth->getFromDB($ac);

$context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
@file_get_contents($url, false, $context);

$location = '';
foreach ($http_response_header ?? [] as $header) {
    if (stripos($header, 'Location:') === 0) {
        $location = trim(substr($header, 9));
    }
}

parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
check('the authorization server redirects back with a code', ($back['code'] ?? '') !== '', $location);
check('and echoes our state', ($back['state'] ?? '') !== '');

OAuth::complete($auth, (string) $back['code'], (string) $back['state']);
$auth->getFromDB($ac);

check('the code is exchanged for an access token',
    str_starts_with($auth->secret('oauth_access_token'), 'access-'));
check('and a refresh token is kept', str_starts_with($auth->secret('oauth_refresh_token'), 'refresh-'));

$first = $auth->secret('oauth_access_token');
$DB->update(Server::getTable(),
    ['oauth_expires_at' => date('Y-m-d H:i:s', time() - 10)], ['id' => $ac]);
$auth->getFromDB($ac);

$refreshed = OAuth::token($auth);
check('an expired delegated token refreshes without a human',
    $refreshed !== $first && str_starts_with($refreshed, 'access-'));

$auth->getFromDB($ac);
check('and the refresh token survives a refresh that did not return a new one',
    $auth->secret('oauth_refresh_token') !== '');

// ---------------------------------------------------------- end to end

echo "\nTalking to the server with it\n";

$live = new Server();
$live->getFromDB($cc);

$result = $live->discover();
check('the MCP handshake succeeds using an OAuth token', $result['ok'] === true, $result['message']);
check('and tools came back', count($live->discovered()) > 0);

// A token the server no longer honours is inside its stated lifetime and still
// refused. Without the retry every call fails until the expiry passes.
$live->getFromDB($cc);
$DB->update(Server::getTable(), [
    'oauth_access_token' => (new GLPIKey())->encrypt('access-revoked-by-hand'),
    'oauth_expires_at'   => date('Y-m-d H:i:s', time() + 3600),
], ['id' => $cc]);
$live->getFromDB($cc);

$retried = $live->discover();
check('a revoked token is discarded and the call retried once',
    $retried['ok'] === true, $retried['message']);

// ------------------------------------------------------------------- toolbox

echo "\nTool search\n";

$make = static fn(
    string $name,
    string $description,
    string $source = 'native',
    bool $pinned = true
): Tool => new Tool(
    name: $name,
    description: $description,
    schema: ['type' => 'object', 'properties' => []],
    handler: static fn(): array => [],
    source: $source,
    pinned: $pinned
);

$few = [
    $make('read_ticket', 'Read a ticket in full.'),
    $make('find_asset', 'Find a computer by name or serial.'),
];

Settings::save(['tool_search_threshold' => '8']);

check('below the threshold every tool is offered and no search tool appears',
    Toolbox::offer($few) === $few);
check('and the toolbox says it does not engage', !Toolbox::engages($few));

$many = $few;
// Unpinned, which is what puts a tool behind the search. It used to be the
// *source* that decided — everything not native was withheld — and that was
// wrong in the way that matters: it hid the tools an administrator had just
// installed a server for. What is pinned is now their decision, so a fixture
// exercising the threshold has to make that decision too.
foreach (range(1, 10) as $i) {
    $many[] = $make(
        "remote_thing_$i",
        "Does remote thing number $i with widgets.",
        'someplugin',
        false
    );
}

check('above the threshold it engages', Toolbox::engages($many));

$offered = Toolbox::offer($many);
$names   = array_map(static fn(Tool $t): string => $t->name, $offered);

check('the native tools stay offered',
    in_array('read_ticket', $names, true) && in_array('find_asset', $names, true));
check('the contributed ones do not', !in_array('remote_thing_3', $names, true));
check('and the search tool is added', in_array(Toolbox::NAME, $names, true), implode(',', $names));
check('so the declared set is much smaller than the registry',
    count($offered) < count($many), count($offered) . ' of ' . count($many));

$box    = Toolbox::tool($many);
$result = ($box->handler)(['query' => 'remote thing with widgets']);

check('searching finds the contributed tools',
    count($result['tools']) > 0
    && str_starts_with($result['tools'][0]['name'], 'remote_thing_'),
    json_encode(array_column($result['tools'], 'name')));
check('each match carries its description, so the model can choose',
    ($result['tools'][0]['description'] ?? '') !== '');
check('and whether it is usable at all, rather than finding out by calling',
    array_key_exists('usable', $result['tools'][0]));
check('the search tool never returns itself',
    !in_array(Toolbox::NAME, array_column($result['tools'], 'name'), true));

$nothing = ($box->handler)(['query' => 'zzzz nothing like this exists']);
check('a query that matches nothing lists what does exist rather than shrugging',
    $nothing['tools'] === [] && str_contains((string) $nothing['note'], 'remote_thing_1'));

// The wire that makes the whole thing work: a search this turn must make the
// found tools callable on the next one.
$invocation = new GlpiPlugin\Glpiai\ToolInvocation(
    new GlpiPlugin\Glpiai\ToolCall('t1', Toolbox::NAME, ['query' => 'remote thing with widgets']),
    GlpiPlugin\Glpiai\ToolResult::of(
        new GlpiPlugin\Glpiai\ToolCall('t1', Toolbox::NAME, []),
        ['tools' => []]
    ),
    5
);

$expanded = Toolbox::expand($offered, [$invocation], $many);
$expanded_names = array_map(static fn(Tool $t): string => $t->name, $expanded);

check('after a search the found tools are offered on the next turn',
    count($expanded) > count($offered), count($offered) . ' -> ' . count($expanded));
check('and they are the ones that matched',
    in_array('remote_thing_1', $expanded_names, true), implode(',', $expanded_names));
check('nothing is offered twice',
    count($expanded_names) === count(array_unique($expanded_names)));

$again = Toolbox::expand($expanded, [$invocation], $many);
check('expanding twice adds nothing further', count($again) === count($expanded));

$untouched = Toolbox::expand($offered, [], $many);
check('a turn with no search changes nothing', $untouched === $offered);

// The real registry, at whatever size this instance happens to be.
$all = ToolRegistry::all(0);
echo sprintf("        this instance registers %d tools\n", count($all));

if (Toolbox::engages($all)) {
    $real = ($all[Toolbox::NAME] ?? Toolbox::tool($all));
    $hits = ($real->handler)(['query' => 'what is using memory on that machine']);

    check('a real question finds a real tool',
        count($hits['tools']) > 0, json_encode(array_column($hits['tools'], 'name')));
    echo '        "what is using memory on that machine" -> '
       . implode(', ', array_column($hits['tools'], 'name')) . "\n";
}

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
