<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The troubleshooting assistant, and the tools it reaches for.
 *
 * Two things are under test and they are worth separating.
 *
 * The **conversation** — that a thread persists, that prior turns are replayed
 * so a follow-up question means something, that a thread belongs to one person,
 * and that context is offered rather than asserted.
 *
 * The **tools** — and these are the interesting half, because this is the first
 * feature where what the model can *reach* matters more than what it says. The
 * mock provider is driven to call each tool in turn and the results are
 * asserted directly, which is the only way to find out whether `network_trace`
 * actually follows a cable.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/assistant.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Context;
use GlpiPlugin\Glpiai\Assistant\Thread;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\ToolRegistry;
use GlpiPlugin\Glpiai\Tools\Network;

require __DIR__ . '/config-guard.php';

/** @var DBmysql $DB */
global $DB;

const MOCK = 'http://127.0.0.1:9099';

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

$before  = GlpiaiConfigGuard::snapshot();
$made    = [];
/** Threads this suite opened, so it can remove exactly those. */
$threads = [];

register_shutdown_function(static function () use ($before, &$made, &$threads): void {
    global $DB;

    foreach ($made as $itemtype => $ids) {
        foreach (array_reverse($ids) as $id) {
            (new $itemtype())->delete(['id' => $id], true);
        }
    }

    // Raw rows, straight back. Restoring through the config API re-encrypts
    // anything secured, a layer per run — see tests/config-guard.php.
    GlpiaiConfigGuard::restore($before);

    // By id, not by table. Purging a fixture takes its thread with it, but a
    // thread opened with no item context has nothing to be purged *by* — and a
    // technician's real conversations are not this suite's to remove.
    if ($threads !== []) {
        $DB->delete(Thread::TABLE, ['id' => array_values(array_unique($threads))]);
    }
    $DB->delete('glpi_networkports_networkports', [1]);
    $DB->delete('glpi_networkports', ['name' => ['LIKE', 'glpiai-%']]);
});

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpiai-assist', 'entities_id' => 0]);
$made[Entity::class] = [$acme];

$ticket = new Ticket();
$about  = (int) $ticket->add([
    'name'        => 'Laptop keeps losing the network',
    'content'     => 'Drops every few minutes in the back office.',
    'entities_id' => $acme,
]);
$made[Ticket::class] = [$about];

// A switch, a laptop, and a cable between them — the shape network_trace walks.
$switch = (int) (new NetworkEquipment())->add([
    'name' => 'glpiai-sw-back-office', 'entities_id' => $acme,
]);
$made[NetworkEquipment::class] = [$switch];

$computer = (int) (new Computer())->add([
    'name' => 'glpiai-laptop-42', 'entities_id' => $acme,
]);
$made[Computer::class] = [$computer];

$port = new NetworkPort();
$switch_port = (int) $port->add([
    'itemtype'           => 'NetworkEquipment',
    'items_id'           => $switch,
    'entities_id'        => $acme,
    'name'               => 'glpiai-Gi1/0/7',
    'logical_number'     => 7,
    'instantiation_type' => 'NetworkPortEthernet',
    'mac'                => 'aa:bb:cc:00:00:07',
    'ifstatus'           => '1',
    'ifspeed'            => 1000000000,
    'ifalias'            => 'back office desks',
    'ifinerrors'         => 412,
    'ifouterrors'        => 3,
]);
$laptop_port = (int) $port->add([
    'itemtype'           => 'Computer',
    'items_id'           => $computer,
    'entities_id'        => $acme,
    'name'               => 'glpiai-eth0',
    'instantiation_type' => 'NetworkPortEthernet',
    'mac'                => 'de:ad:be:ef:00:42',
    'ifstatus'           => '1',
]);

$DB->insert('glpi_networkports_networkports', [
    'networkports_id_1' => $switch_port,
    'networkports_id_2' => $laptop_port,
]);

// A second switch port that is down, for the problems filter.
$dead_port = (int) $port->add([
    'itemtype'           => 'NetworkEquipment',
    'items_id'           => $switch,
    'entities_id'        => $acme,
    'name'               => 'glpiai-Gi1/0/8',
    'logical_number'     => 8,
    'instantiation_type' => 'NetworkPortEthernet',
    'ifstatus'           => '2',
]);

echo "\nFixtures\n";
check('a switch, a laptop and a cable exist',
    $switch > 0 && $computer > 0 && $switch_port > 0 && $laptop_port > 0);

Settings::save([
    'enabled'           => '1',
    'provider'          => 'openai',
    'entity_mode'       => 'all',
    'assistant_enabled' => '1',
]);
Settings::saveProvider('openai', [
    'api_key' => 'sk-assist', 'base_url' => MOCK,
    'model_fast' => 'mock-fast', 'model_quality' => 'mock-quality',
]);

// --------------------------------------------------------- the network tools

echo "\nFollowing a cable\n";

$traced = Network::runTrace(['address' => 'de:ad:be:ef:00:42']);
check('a MAC is traced to its switch port', ($traced['found'] ?? false) === true,
    json_encode($traced['note'] ?? ''));
check('and the switch it lands on is named',
    str_contains(json_encode($traced), 'glpiai-sw-back-office'), json_encode($traced));
check('with the port number', str_contains(json_encode($traced['links'][0]['connected'] ?? []), '"number":7'));
check('the port description comes through, because it is what people label ports with',
    str_contains(json_encode($traced), 'back office desks'));
check('and its error counters, which is why the link keeps dropping',
    str_contains(json_encode($traced), '"in":412'));

// MACs get pasted in three notations and the inventory stores one.
foreach (['DE-AD-BE-EF-00-42', 'deadbeef0042', 'de:ad:be:ef:00:42'] as $notation) {
    check(
        sprintf('a MAC written as %s still matches', $notation),
        (Network::runTrace(['address' => $notation])['found'] ?? false) === true
    );
}

$by_name = Network::runTrace(['address' => 'glpiai-laptop-42']);
check('a hostname works as well as a MAC', ($by_name['found'] ?? false) === true);

$nothing = Network::runTrace(['address' => '00:00:00:00:00:99']);
check('an address nobody has is answered honestly rather than emptily',
    ($nothing['found'] ?? true) === false && str_contains((string) $nothing['note'], 'never been inventoried'));

echo "\nLooking at a switch\n";

$ports = Network::runPorts(['device' => 'glpiai-sw-back-office']);
check('the switch lists its ports', count($ports['ports'] ?? []) === 2, json_encode($ports));
check('a down port is described in words, not as the number 2',
    str_contains(json_encode($ports), '"status":"down"'), json_encode($ports['ports'] ?? []));
check('speed is converted to something readable',
    str_contains(json_encode($ports), '"speed_mbps":1000'));

$problems = Network::runPorts(['device' => 'glpiai-sw-back-office', 'only_problems' => 'yes']);
check('filtering to problems keeps the erroring and the down one',
    count($problems['ports'] ?? []) === 2, json_encode(array_column($problems['ports'], 'number')));

try {
    Network::runPorts(['device' => 'no-such-switch-anywhere']);
    check('an unknown device raises rather than returning nothing', false);
} catch (Throwable $e) {
    check('an unknown device raises rather than returning nothing',
        str_contains($e->getMessage(), 'visible to you'));
}

// ------------------------------------------------------------- the registry

echo "\nWhat the assistant can reach\n";

$tools = ToolRegistry::all($acme);

foreach (['search_tickets', 'find_asset', 'network_trace', 'network_ports'] as $name) {
    check("$name is registered", isset($tools[$name]));
}
check('osquery contributes its tools when installed',
    !Plugin::isPluginActive('glpiosquery') || isset($tools['osquery_live']));
check('and live query is gated on the console\'s own free-form SQL right',
    !isset($tools['osquery_live'])
    || $tools['osquery_live']->right === 'plugin_glpiosquery_rawsql');
check('at the UPDATE level, which is what the console checks',
    !isset($tools['osquery_live']) || $tools['osquery_live']->right_level === UPDATE);

// ------------------------------------------------------- the live query guards

// The riskiest tool in the plugin: it reaches real endpoints. Its guards are
// asserted directly rather than through the model, because a guard that only
// holds when a model happens to behave is not a guard.
if (Plugin::isPluginActive('glpiosquery') && class_exists('GlpiPlugin\\Glpiosquery\\AiTools')) {
    echo "\nWhat a live query will not do\n";

    $live = static fn(array $args): array => call_user_func(
        ['GlpiPlugin\\Glpiosquery\\AiTools', 'runLive'],
        $args
    );

    foreach (
        [
            'DELETE FROM processes'            => 'only select',
            'DROP TABLE users'                 => 'only select',
            'SELECT 1; SELECT 2'               => 'one statement',
            ''                                 => 'empty',
        ] as $sql => $why
    ) {
        $result = $live(['sql' => $sql, 'agent_ids' => '1']);
        check(
            sprintf('"%s" is refused', mb_substr($sql, 0, 28) ?: '(empty)'),
            isset($result['error']),
            json_encode($result)
        );
    }

    $no_agents = $live(['sql' => 'SELECT 1 AS n', 'agent_ids' => '']);
    check('a query with no agent named is refused',
        str_contains((string) ($no_agents['error'] ?? ''), 'at least one agent'));

    // The id is invented, so this also proves an agent cannot be reached by
    // guessing a number.
    $unknown = $live(['sql' => 'SELECT 1 AS n', 'agent_ids' => '987654']);
    check('an agent id that does not exist reaches nothing',
        str_contains((string) ($unknown['error'] ?? ''), 'entity you can see'),
        json_encode($unknown));
}

// --------------------------------------------------------------- the thread

echo "\nThe conversation\n";

check('the assistant reports itself available', Assistant::available());

$threads_id = Thread::open(Ticket::class, $about, $acme);
$threads[]  = $threads_id;

check('a thread opens', $threads_id > 0);
check('opening again resumes the same one',
    Thread::open(Ticket::class, $about, $acme) === $threads_id);

$other_context = Thread::open(Computer::class, $computer, $acme);
$threads[]     = $other_context;
check('a different context gets its own', $other_context !== $threads_id);

$item = Context::item(Ticket::class, $about);
check('context resolves the ticket', $item instanceof Ticket);
check('and describes it for the prompt',
    str_contains(Context::describe($item), 'Laptop keeps losing the network'));
check('a context the user may not see resolves to nothing',
    Context::item('Ticket', 999999) === null);
check('and so does an itemtype nobody should be pasting in',
    Context::item('Config', 1) === null);

$answer = Assistant::ask($threads_id, 'Why does that laptop keep dropping?');
check('asking returns an answer', trim($answer['answer']) !== '', json_encode($answer));
check('and the transcript now holds both turns', count(Thread::messages($threads_id)) === 2);
check('the thread is named after the first question',
    str_contains((string) Thread::byId($threads_id)['title'], 'laptop'));

Assistant::ask($threads_id, 'And the switch it is on?');
check('a follow-up appends rather than replacing',
    count(Thread::messages($threads_id)) === 4);

Thread::clear($threads_id);
check('clearing empties the transcript', Thread::messages($threads_id) === []);

// ------------------------------------------------------------------ history

echo "\nGoing back to one\n";

// The list both the panel and the app read. Two properties matter and neither
// is obvious from the query: it is this person's threads only, and it is
// newest first — a history that quietly leaks somebody else's working notes,
// or that buries this morning's conversation, is worse than none.
$recent = Thread::recent(25);
$ids    = array_map(static fn(array $row): int => (int) $row['id'], $recent);

check('the history lists this person\'s conversations',
    in_array($threads_id, $ids, true) && in_array($other_context, $ids, true));

$mine_only = true;
foreach ($recent as $row) {
    $mine_only = $mine_only && (int) $row['users_id'] === (int) Session::getLoginUserID();
}
check('and nobody else\'s', $mine_only);

$ordered = true;
for ($i = 1, $n = count($recent); $i < $n; $i++) {
    $ordered = $ordered
        && strtotime((string) $recent[$i - 1]['date_mod'])
           >= strtotime((string) $recent[$i]['date_mod']);
}
check('newest first', $ordered);

// Someone else's thread, seen from here: absent from the list, and refused by
// the ownership check the resume endpoint goes through.
//
// Inserted rather than opened. Thread::open() is keyed by (user, itemtype,
// items_id) and would hand back the thread this suite is already using, which
// the next few checks then rely on.
$DB->insert(Thread::TABLE, [
    'users_id'      => 999999,
    'entities_id'   => $acme,
    'itemtype'      => Ticket::class,
    'items_id'      => $about,
    'messages'      => '[]',
    'date_creation' => date('Y-m-d H:i:s'),
    'date_mod'      => date('Y-m-d H:i:s'),
]);
$stranger = (int) $DB->insertId();
$after    = array_map(static fn(array $row): int => (int) $row['id'], Thread::recent(25));
check('a thread that is not mine never appears in it',
    !in_array($stranger, $after, true) && Thread::mine($stranger) === null);
$DB->delete(Thread::TABLE, ['id' => $stranger]);

check('the cap is honoured and sane', count(Thread::recent(1)) <= 1);

// ------------------------------------------------------------------ privacy

echo "\nWhose conversation it is\n";

$DB->update(Thread::TABLE, ['users_id' => 999999], ['id' => $threads_id]);
check('a thread belonging to somebody else is not mine', Thread::mine($threads_id) === null);

try {
    Assistant::ask($threads_id, 'anything');
    check('and cannot be asked in', false);
} catch (Throwable $e) {
    check('and cannot be asked in', str_contains($e->getMessage(), 'not yours'));
}
$DB->update(Thread::TABLE, ['users_id' => (int) Session::getLoginUserID()], ['id' => $threads_id]);

// ------------------------------------------------------------------ retention

echo "\nHousekeeping\n";

$DB->update(Thread::TABLE, ['date_mod' => date('Y-m-d H:i:s', strtotime('-200 days'))],
    ['id' => $threads_id]);
check('old conversations are pruned', Thread::prune(90) >= 1);
check('and a retention of zero keeps everything', Thread::prune(0) === 0);

// A conversation about a machine must not outlive the machine either. Computer
// is not an indexed type, so it reaches the purge hook only because Context's
// supported list is registered for it — which is exactly the sort of thing that
// gets forgotten when a new itemtype is added.
$machine_thread = Thread::open(Computer::class, $computer, $acme);
$threads[]      = $machine_thread;
Thread::append($machine_thread, ['role' => 'user', 'content' => 'about this machine']);

(new Computer())->delete(['id' => $computer], true);
check('a purged computer takes its conversation with it', Thread::byId($machine_thread) === null);
$made[Computer::class] = [];

$fresh     = Thread::open(Ticket::class, $about, $acme);
$threads[] = $fresh;
Thread::append($fresh, ['role' => 'user', 'content' => 'still here']);
(new Ticket())->delete(['id' => $about], true);
check('a purged ticket takes its conversation with it', Thread::byId($fresh) === null);
$made[Ticket::class] = [];

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
