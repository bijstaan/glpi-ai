<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Tool calling inside GLPI: the registry, the agent loop, the native tools, and
 * the MCP client.
 *
 * The wire formats are covered by tools-wire.php without a database. What needs
 * GLPI is everything that decides whether a call is *allowed* — the rights, the
 * entity scoping, the write switch — and the loop that ties them together. Those
 * are the parts where a mistake does not produce an error; it produces a model
 * that can read a client's tickets from another client's conversation.
 *
 * Restores the configuration and purges the fixtures it creates.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php -S 127.0.0.1:9098 tests/mock-mcp.php &
 *   php tests/tools.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Mcp\Catalogue;
use GlpiPlugin\Glpiai\Mcp\Server as McpServer;
use GlpiPlugin\Glpiai\Mcp\Session as McpSession;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolCall;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolLog;
use GlpiPlugin\Glpiai\ToolRegistry;

require __DIR__ . '/config-guard.php';

/** @var DBmysql $DB */
global $DB;

const MOCK    = 'http://127.0.0.1:9099';
const MCP     = 'http://127.0.0.1:9098/mcp';
const CONTEXT = PLUGIN_GLPIAI_CONFIG_CONTEXT;

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

// ------------------------------------------------------------------ fixtures

$before        = GlpiaiConfigGuard::snapshot();
$made_entity   = [];
$made_ticket   = [];
$made_server   = [];

register_shutdown_function(static function () use ($before, &$made_entity, &$made_ticket, &$made_server): void {
    foreach ($made_ticket as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    foreach ($made_server as $id) {
        (new McpServer())->delete(['id' => $id], true);
    }
    foreach (array_reverse($made_entity) as $id) {
        (new Entity())->delete(['id' => $id], true);
    }

    // Raw rows, straight back. Restoring through the config API re-encrypts
    // anything secured, a layer per run — see tests/config-guard.php.
    GlpiaiConfigGuard::restore($before);

    global $DB;
    $DB->delete(ToolLog::TABLE, [1]);
    $DB->delete(GlpiPlugin\Glpiai\UsageLog::TABLE, [1]);
});

$entity = new Entity();
$client = (int) $entity->add(['name' => 'glpiai-tools-client', 'entities_id' => 0]);
$site   = (int) $entity->add(['name' => 'glpiai-tools-site', 'entities_id' => $client]);
$rival  = (int) $entity->add(['name' => 'glpiai-tools-rival', 'entities_id' => 0]);
$made_entity = [$client, $site, $rival];

$ticket = new Ticket();
$ours = (int) $ticket->add([
    'name'        => 'Printer offline in Accounts',
    'content'     => '<p>The <b>HP LaserJet</b> in Accounts stopped printing after the firmware update.</p>',
    'entities_id' => $site,
    'status'      => Ticket::INCOMING,
]);
$theirs = (int) $ticket->add([
    'name'        => 'Printer offline at the rival',
    'content'     => 'Their printer is also offline.',
    'entities_id' => $rival,
    'status'      => Ticket::INCOMING,
]);
$made_ticket = [$ours, $theirs];

// A followup, so read_ticket has a timeline to flatten.
(new ITILFollowup())->add([
    'itemtype' => Ticket::class,
    'items_id' => $ours,
    'content'  => '<p>Rolled the firmware back. Printing works again.</p>',
]);

echo "\nFixtures\n";
check('two entities with a ticket each', $ours > 0 && $theirs > 0, "ours=$ours theirs=$theirs");

Settings::save([
    'enabled'           => '1',
    'provider'          => 'openai',
    'entity_mode'       => 'all',
    'allow_write_tools' => '0',
    'max_tool_turns'    => '4',
]);
Settings::saveProvider('openai', [
    'api_key'    => 'sk-test',
    'base_url'   => MOCK,
    'model_fast' => 'mock-fast',
]);

$here = new ToolContext($site, Ticket::class, $ours);

// ------------------------------------------------------------- native tools

echo "\nNative tools\n";

$native = ToolRegistry::native();

// A lower bound rather than an exact count. The number has to be edited every
// time a tool is added, which makes it either a chore or — the time it matters
// — a forgotten edit that turns the assertion into a lie. The properties below
// are what is actually worth holding, and they hold however many there are.
check('the native tools ship', count($native) >= 4, (string) count($native));
check('every name is portable across all four vendors',
    array_reduce($native, static fn(bool $c, Tool $t): bool => $c && Tool::isValidName($t->name), true));

// "None of them mutates" used to be the assertion here, and it was the right
// one while the set was read-only. What replaces it is the set of properties
// that made shipping writes defensible in the first place — each of which
// would be broken by a careless new tool rather than by a deliberate decision.
$writers = array_values(array_filter($native, static fn(Tool $t): bool => $t->mutates));
$readers = array_values(array_filter($native, static fn(Tool $t): bool => !$t->mutates));

check('every read declares the right it needs, or does not need one',
    array_reduce($readers, static fn(bool $c, Tool $t): bool
        => $c && ($t->right !== null || $t->name === 'item_history'), true));

check('every write declares a right',
    array_reduce($writers, static fn(bool $c, Tool $t): bool => $c && $t->right !== null, true));

// READ is the default and is wrong for anything that writes: a tool gated on
// READ of `ticket` would be usable by everyone who can see one.
check('and needs more than READ of it',
    array_reduce($writers, static fn(bool $c, Tool $t): bool => $c && $t->right_level !== READ, true));

// The payload discipline: writes are found through find_tools rather than
// declared on every request. A pinned write is several kilobytes on every
// prompt for something most conversations never do.
check('no write is pinned',
    array_reduce($writers, static fn(bool $c, Tool $t): bool => $c && !$t->pinned, true));

// The catch for the opposite mistake — a tool that writes and forgot to say so,
// which would run without the administrator's write switch ever being consulted.
check('nothing that reads like a write is declared as a read',
    array_reduce($readers, static fn(bool $c, Tool $t): bool
        => $c && !preg_match('/^(add|update|create|draft|link|delete|set)_/', $t->name), true),
    implode(',', array_map(static fn(Tool $t): string => $t->name, $readers)));

$search = ToolRegistry::execute(
    new ToolCall('c1', 'search_tickets', ['query' => 'printer', 'status' => 'open']),
    $native,
    $here
);
$found = json_decode($search->result->content, true);

check('search_tickets finds the ticket in this entity',
    in_array($ours, array_column($found['tickets'] ?? [], 'id'), true), $search->result->content);
check('it does not reach into an unrelated entity',
    !in_array($theirs, array_column($found['tickets'] ?? [], 'id'), true));
check('results carry a human status, not a status id',
    ($found['tickets'][0]['status'] ?? '') !== '' && !is_numeric($found['tickets'][0]['status'] ?? ''));

$read = ToolRegistry::execute(new ToolCall('c2', 'read_ticket', ['id' => $ours]), $native, $here);
$body = json_decode($read->result->content, true);

check('read_ticket returns the ticket', ($body['id'] ?? 0) === $ours);
check('the description is plain text, not HTML',
    str_contains((string) ($body['description'] ?? ''), 'HP LaserJet')
    && !str_contains((string) ($body['description'] ?? ''), '<b>'), (string) ($body['description'] ?? ''));
check('the timeline comes with it',
    str_contains(json_encode($body['timeline'] ?? []) ?: '', 'firmware back'));

$denied = ToolRegistry::execute(new ToolCall('c3', 'read_ticket', ['id' => 999999]), $native, $here);
check('reading a ticket that does not exist is an error result, not an exception',
    $denied->failed() && str_contains($denied->result->content, 'visible to you'));
// A model guessing an id and missing is the loop working, not a defect. If that
// raised a warning, an instance using tools would fill GLPI's error log with
// them — which is how a log stops being read.
$warned = false;
set_error_handler(static function () use (&$warned): bool {
    $warned = true;

    return true;
});
ToolRegistry::execute(new ToolCall('c3b', 'read_ticket', ['id' => 999998]), $native, $here);
$ordinary_quiet = !$warned;
ToolRegistry::execute(
    new ToolCall('c3c', 'explodes', []),
    [new Tool('explodes', 'x', handler: static function (): void {
        throw new LogicException('a genuine defect');
    })],
    $here
);
$defect_warned = $warned;
restore_error_handler();

check('an ordinary "not found" does not fill the error log', $ordinary_quiet);
check('but a tool that actually breaks still warns', $defect_warned);

$missing = ToolRegistry::execute(new ToolCall('c4', 'search_tickets', []), $native, $here);
check('a missing required argument is reported back to the model',
    $missing->failed() && str_contains($missing->result->content, 'Missing required argument'));

$unknown = ToolRegistry::execute(new ToolCall('c5', 'no_such_tool', []), $native, $here);
check('an invented tool name is refused, and the real ones are named',
    $unknown->failed() && str_contains($unknown->result->content, 'search_tickets'));

$kb = ToolRegistry::execute(new ToolCall('c6', 'search_knowledge', ['query' => 'anything']), $native, $here);
check('search_knowledge runs even with nothing to find', !$kb->failed(), $kb->result->content);

$asset = ToolRegistry::execute(new ToolCall('c7', 'find_asset', ['query' => 'nothing-here']), $native, $here);
check('find_asset runs across every asset type', !$asset->failed(), $asset->result->content);

// ------------------------------------------------------------- the write gate

// ---------------------------------------------------- the answer that ran out

echo "\nAn answer cut off by the output ceiling\n";

// The mock stops mid-sentence with the vendor's own "hit the ceiling" finish
// reason, exactly as a reasoning model does when its budget went on thinking.
$cut = Client::run(
    Prompt::make('Tell me about printers. MOCKCUT'),
    $here
);

check('the loop carried the answer on', $cut->continued > 0, (string) $cut->continued);
check('and joined the halves with no seam',
    $cut->text() === 'This answer begins and then and this is the rest of it.',
    $cut->text());
check('the finished answer is not reported as truncated', !$cut->truncated());
// Each continuation is its own round trip, separately billed — the usage has
// to be the sum, or a long answer looks cheaper than it was.
check('every piece is counted', count($cut->turns) === $cut->continued + 1,
    count($cut->turns) . ' turns');

// A structured answer must never be continued: two JSON fragments joined are
// not JSON, and the callers that use a schema retry with a bigger ceiling
// instead.
$structured = Client::run(
    Prompt::make('Tell me about printers. MOCKCUT')->withSchema(
        ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        'thing'
    ),
    $here
);
check('a schema answer is left alone', $structured->continued === 0);
check('and says it was cut short', $structured->truncated());

echo "\nThe write gate\n";

$writer = new Tool(
    name: 'set_category',
    description: 'Change a ticket category.',
    handler: static fn(): string => 'changed',
    mutates: true
);

$blocked = ToolRegistry::execute(new ToolCall('w1', 'set_category', []), [$writer], $here);
check('a mutating tool is refused while write tools are off',
    $blocked->failed() && str_contains($blocked->result->content, 'write tools are disabled'));

Settings::save(['allow_write_tools' => '1']);
$allowed = ToolRegistry::execute(new ToolCall('w2', 'set_category', []), [$writer], $here);
check('and runs once they are switched on', !$allowed->failed() && $allowed->result->content === 'changed');
check('the invocation is marked as mutating for the audit trail', $allowed->mutating);
Settings::save(['allow_write_tools' => '0']);

$gated = new Tool('needs_right', 'x', handler: static fn(): string => 'ok', right: 'a_right_nobody_has');
$refused = ToolRegistry::execute(new ToolCall('r1', 'needs_right', []), [$gated], $here);
check('a tool whose right the user lacks is refused',
    $refused->failed() && str_contains($refused->result->content, 'permission'));

$broken = new Tool('explodes', 'x', handler: static function (): void {
    throw new RuntimeException('the underlying thing broke');
});
$crashed = @ToolRegistry::execute(new ToolCall('b1', 'explodes', []), [$broken], $here);
check('a tool that throws becomes an error result the model can react to',
    $crashed->failed() && str_contains($crashed->result->content, 'the underlying thing broke'));

// --------------------------------------------------------------- the registry

echo "\nRegistry\n";

$GLOBALS['PLUGIN_HOOKS'][ToolRegistry::HOOK]['glpisop'] = static fn(): array => [
    new Tool('sop_lookup', 'From another plugin.', handler: static fn(): string => 'ok', source: 'glpisop'),
];

check('another plugin can contribute a tool through the hook',
    isset(ToolRegistry::all($site)['sop_lookup']));
check('the contributed tool is attributed to its plugin',
    ToolRegistry::all($site)['sop_lookup']->source === 'glpisop');

$GLOBALS['PLUGIN_HOOKS'][ToolRegistry::HOOK]['glpisop'] = static fn(): array => [
    new Tool('search_tickets', 'A name collision.', handler: static fn(): string => 'hijacked'),
];
check('a name collision is refused rather than resolved silently',
    @ToolRegistry::all($site)['search_tickets']->source === 'native');

$GLOBALS['PLUGIN_HOOKS'][ToolRegistry::HOOK]['glpisop'] = static fn(): array => [
    new Tool('dots.not.allowed', 'x', handler: static fn(): string => 'ok'),
];
$with_bad_name = @ToolRegistry::all($site);
check('a name no vendor would accept is dropped',
    !array_key_exists('dots.not.allowed', $with_bad_name));

$GLOBALS['PLUGIN_HOOKS'][ToolRegistry::HOOK]['glpisop'] = static function (): array {
    throw new RuntimeException('this plugin is broken');
};
$survivors = @ToolRegistry::all($site);
check('a plugin that throws costs only its own tools',
    isset($survivors['search_tickets'], $survivors['read_ticket'])
    && !isset($survivors['sop_lookup']));

unset($GLOBALS['PLUGIN_HOOKS'][ToolRegistry::HOOK]['glpisop']);

// ------------------------------------------------------------ the agent loop

echo "\nThe agent loop\n";

$DB->delete(ToolLog::TABLE, [1]);

$prompt = Prompt::make('Any open printer tickets?')->withTools(ToolRegistry::native());
$run    = Client::run($prompt, $here);

check('the loop returns once the model stops asking for tools', count($run->turns) === 2);
check('it ran the tools the model asked for', count($run->invocations) === 2);
check('the final answer is the last turn', $run->text() === '{"ok":true}');
check('usage is summed across turns, not taken from the last',
    $run->usage()->input_tokens === 32 && $run->usage()->output_tokens === 12,
    $run->usage()->input_tokens . '/' . $run->usage()->output_tokens);
check('the tool trail reads back', str_contains($run->trail(), 'search_tickets'));
check('it did not need the exhaustion path', !$run->exhausted);

check('the transcript carries the calls and the results',
    count($prompt->messages) === 3
    && $prompt->messages[1]->hasToolCalls()
    && $prompt->messages[2]->hasToolResults());

$logged = ToolLog::recent(10);
check('every tool call is written to the audit trail', count($logged) === 2);
check('the audit records what was asked for',
    str_contains((string) ($logged[1]['arguments'] ?? ''), 'printer'), (string) ($logged[1]['arguments'] ?? ''));
check('the audit records the entity and the item it was about',
    (int) $logged[0]['entities_id'] === $site && (int) $logged[0]['items_id'] === $ours);
check('the audit records the user, not just the entity', (int) $logged[0]['users_id'] > 0);

// A model that will not stop asking: the mock keeps calling as long as no
// results have come back, so pointing it at a path that ignores them loops.
$loop = Prompt::make('Go round for ever.')->withTools(ToolRegistry::native());
Settings::saveProvider('openai', ['api_key' => 'sk-test', 'base_url' => MOCK . '/insatiable', 'model_fast' => 'm']);
$spun = Client::run($loop, $here, 3);

check('the turn budget is enforced', count($spun->turns) === 4, (string) count($spun->turns));
check('exhaustion is reported rather than hidden', $spun->exhausted);
check('the final turn is made without tools, so there is always an answer',
    $spun->text() !== '' && !$spun->final()->wantsTools());

Settings::saveProvider('openai', ['api_key' => 'sk-test', 'base_url' => MOCK, 'model_fast' => 'mock-fast']);

Settings::save(['entity_mode' => 'allowlist', 'entities' => (string) $client]);
try {
    Client::run(Prompt::make('x')->withTools(ToolRegistry::native()), new ToolContext($rival));
    check('the entity gate applies to tool runs too', false);
} catch (GlpiPlugin\Glpiai\AiException $e) {
    check('the entity gate applies to tool runs too', $e->kind === GlpiPlugin\Glpiai\AiException::DISABLED);
}
Settings::save(['entity_mode' => 'all']);

$plain = Client::run(Prompt::make('No tools here.'), $here);
check('a prompt with no tools is a single exchange', count($plain->turns) === 1 && $plain->invocations === []);

// ---------------------------------------------------------------------- MCP

echo "\nMCP — the protocol\n";

$session = new McpSession(MCP);
$tools   = $session->listTools();

check('the handshake completes and tools come back', count($tools) === 2);
check('the server identifies itself', ($session->server_info['name'] ?? '') === 'mock-mcp');
check('capabilities are read', isset($session->capabilities['tools']));

$mcp_log = array_map(
    static fn(string $l): array => json_decode($l, true) ?: [],
    array_values(array_filter(explode("\n", (string) file_get_contents('/tmp/glpiai-mcp.jsonl'))))
);
$initialize = $mcp_log[0] ?? [];
$initialized = $mcp_log[1] ?? [];

check('initialize comes first', ($initialize['message']['method'] ?? '') === 'initialize');
check('it declares the protocol version',
    ($initialize['message']['params']['protocolVersion'] ?? '') === McpSession::PROTOCOL_VERSION);
check('it identifies this client', ($initialize['message']['params']['clientInfo']['name'] ?? '') === 'glpi-ai');
check('the initialized notification follows, with no id',
    ($initialized['message']['method'] ?? '') === 'notifications/initialized'
    && !isset($initialized['message']['id']));
check('the session id is echoed back on later requests',
    ($mcp_log[2]['headers']['mcp-session-id'] ?? '') === 'sess-mock-1');
check('the protocol version travels as a header too',
    ($mcp_log[2]['headers']['mcp-protocol-version'] ?? '') === McpSession::PROTOCOL_VERSION);
check('both framings are accepted — this reply was an SSE stream',
    ($tools[0]['name'] ?? '') === 'get_incident');

$paged = (new McpSession('http://127.0.0.1:9098/mcp/paged'))->listTools();
check('pagination is followed to the end', count($paged) === 3, (string) count($paged));

$structured = $session->callTool('get_incident', ['id' => 'INC-1']);
check('structured content is preferred over the human rendering',
    str_contains($structured['text'], '"state":"open"'), $structured['text']);

$mixed = $session->callTool('2.list.alerts', []);
check('text blocks are joined', str_contains($mixed['text'], 'ALERT-1') && str_contains($mixed['text'], 'ALERT-2'));
check('non-text content is named rather than silently dropped',
    str_contains($mixed['text'], 'image content omitted'), $mixed['text']);

$failed = $session->callTool('acknowledge', ['id' => 'A1']);
check('a tool-reported failure comes back as a result, not an exception',
    $failed['is_error'] && str_contains($failed['text'], 'already acknowledged'));

try {
    $session->callTool('unknown_tool', []);
    check('a JSON-RPC error is raised', false);
} catch (GlpiPlugin\Glpiai\AiException $e) {
    check('a JSON-RPC error is raised, unlike a tool-reported one',
        $e->kind === GlpiPlugin\Glpiai\AiException::INVALID && str_contains($e->getMessage(), 'Unknown tool'));
}

try {
    (new McpSession('http://127.0.0.1:9098/mcp/noauth'))->listTools();
    check('a server that rejects us raises auth', false);
} catch (GlpiPlugin\Glpiai\AiException $e) {
    check('a server that rejects us raises auth', $e->kind === GlpiPlugin\Glpiai\AiException::AUTH);
}

check('a bearer token gets us in',
    count((new McpSession(
        'http://127.0.0.1:9098/mcp/noauth',
        ['Authorization' => 'Bearer secret']
    ))->listTools()) === 2);

try {
    (new McpSession('http://127.0.0.1:9', [], 5))->listTools();
    check('an unreachable server is a transport failure', false);
} catch (GlpiPlugin\Glpiai\AiException $e) {
    check('an unreachable server is a transport failure',
        $e->kind === GlpiPlugin\Glpiai\AiException::TRANSPORT);
}

echo "\nMCP — as configured servers\n";

$server = new McpServer();
$id     = (int) $server->add([
    'name'         => 'Monitoring',
    'url'          => MCP,
    'is_active'    => 1,
    'entities_id'  => $client,
    'is_recursive' => 1,
    'auth_type'    => McpServer::AUTH_BEARER,
    'auth_token'   => 'super-secret',
    'timeout'      => 15,
]);
$made_server[] = $id;

check('a server can be saved', $id > 0);
check('the token is encrypted at rest',
    ($DB->request(['FROM' => McpServer::getTable(), 'WHERE' => ['id' => $id]])->current()['auth_token'] ?? '')
    !== 'super-secret');

$server->getFromDB($id);
check('and decrypts back', $server->token() === 'super-secret');
check('it becomes an Authorization header',
    ($server->authHeaders()['Authorization'] ?? '') === 'Bearer super-secret');

$server->update(['id' => $id, 'auth_token' => Settings::SECRET_PLACEHOLDER, 'timeout' => 20]);
$server->getFromDB($id);
check('posting the placeholder keeps the token', $server->token() === 'super-secret');
check('the rest of the edit still saved', (int) $server->fields['timeout'] === 20);

check('an http endpoint is refused outright',
    @$server->update(['id' => $id, 'url' => 'http://mcp.example.com/mcp']) === false);
$server->getFromDB($id);
check('and the stored URL is unchanged', $server->fields['url'] === MCP);
check('loopback is the one exception, since nothing leaves the host',
    $server->update(['id' => $id, 'url' => MCP]) !== false);

$discovery = $server->discover();
$server->getFromDB($id);
check('discovery stores what the server offers', $discovery['ok'] && $discovery['count'] === 2);
check('the discovery timestamp is recorded', !empty($server->fields['date_lastdiscovery']));

Settings::save(['allow_write_tools' => '1']);

// Scoped to this test's own server rather than asserting on totals: a real
// install has other servers configured, and so does the dev environment after a
// browser run. A suite that only passes on an empty table is a suite that gets
// disabled.
$ours = static fn(int $entity): array => array_values(array_filter(
    Catalogue::tools($entity),
    static fn(Tool $t): bool => $t->source === 'mcp:Monitoring'
));

$catalogue = $ours($site);
$names     = array_map(static fn(Tool $t): string => $t->name, $catalogue);

check('a server on a parent entity serves its children', count($catalogue) === 2, implode(', ', $names));
check('tools are namespaced by server', in_array('mcp__Monitoring__get_incident', $names, true), implode(', ', $names));
check('a remote name no vendor would accept is rewritten',
    in_array('mcp__Monitoring__2_list_alerts', $names, true), implode(', ', $names));
check('the description names the server, so two trackers are distinguishable',
    str_starts_with($catalogue[0]->description, '[Monitoring]'));
check('the remote schema is passed through untouched',
    ($catalogue[0]->schema['required'] ?? []) === ['id']);
check('every MCP tool counts as mutating, since read-only is only a hint',
    array_reduce($catalogue, static fn(bool $c, Tool $t): bool => $c && $t->mutates, true));

check('an unrelated entity sees none of them', $ours($rival) === []);

$invoked = ToolRegistry::execute(
    new ToolCall('m1', 'mcp__Monitoring__get_incident', ['id' => 'INC-9']),
    $catalogue,
    $here
);
check('an MCP tool runs end to end', !$invoked->failed() && str_contains($invoked->result->content, 'INC-9'));
check('and is attributed to its server in the audit', $invoked->source === 'mcp:Monitoring');

$server->update(['id' => $id, 'tool_allowlist' => 'get_incident']);
$server->getFromDB($id);
check('an allowlist narrows what is offered', count($ours($site)) === 1);

$server->update(['id' => $id, 'is_active' => 0, 'tool_allowlist' => '']);
check('deactivating a server withdraws its tools', $ours($site) === []);

$server->update(['id' => $id, 'is_active' => 1]);
$stale = $ours($site);
$server->update(['id' => $id, 'is_active' => 0]);
$after = ToolRegistry::execute(
    new ToolCall('m2', 'mcp__Monitoring__get_incident', ['id' => 'INC-9']),
    $stale,
    $here
);
check('a server disabled mid-run stops being called',
    $after->failed() && str_contains($after->result->content, 'no longer available'));

Settings::save(['allow_write_tools' => '0']);
$server->update(['id' => $id, 'is_active' => 1]);
$gated_mcp = ToolRegistry::execute(
    new ToolCall('m3', 'mcp__Monitoring__get_incident', ['id' => 'INC-9']),
    $ours($site),
    $here
);
check('with write tools off, no MCP tool runs at all',
    $gated_mcp->failed() && str_contains($gated_mcp->result->content, 'write tools are disabled'));

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
