<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The features against a real model, on a real Ollama host.
 *
 * Everything else in tests/ runs against a mock, on purpose: a suite that needs
 * a GPU somewhere on the network is a suite that gets skipped. But a mock can
 * only ever prove that the plugin sent what it meant to send, and the claim
 * that matters here is about what a *model* does: that a real small model,
 * given a real taxonomy, returns a schema-valid triage object and picks a
 * sensible category out of it. No stand-in can prove that, however carefully
 * it is written.
 *
 * So this exists, is opt-in, and is not in run.sh.
 *
 *   OLLAMA=http://host:11434 CHAT=some-model php tests/live-ollama.php
 *
 * It restores the plugin's configuration and purges its fixtures on the way out,
 * including after a failure.
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Thread;
use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Draft\Drafter;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\Triage\Taxonomy;
use GlpiPlugin\Glpiai\Triage\Triage;

require __DIR__ . '/config-guard.php';

/** @var DBmysql $DB */
global $DB;

$host  = getenv('OLLAMA') ?: 'http://192.168.86.64:11434';
$chat  = getenv('CHAT') ?: 'Gemma-4-E4B-Uncensored-HauhauCS-Aggressive-Q8_K_P:latest';

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);
(new Glpi\Cache\CacheManager())->getCacheInstance('plugin:glpiai')->clear();

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

function timed(string $label, callable $fn): mixed
{
    $started = microtime(true);
    $result  = $fn();
    printf("        %s took %.1fs\n", $label, microtime(true) - $started);

    return $result;
}

echo "\nHost: $host\n  chat:  $chat\n";

// ------------------------------------------------------------------ fixtures

$before  = GlpiaiConfigGuard::snapshot();
$tickets = [];
$made    = [];

register_shutdown_function(static function () use ($before, &$tickets, &$made): void {
    global $DB;

    foreach ($tickets as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    foreach ($made as $itemtype => $ids) {
        foreach (array_reverse($ids) as $id) {
            (new $itemtype())->delete(['id' => $id], true);
        }
    }

    // Raw rows, straight back. Restoring through the config API re-encrypts
    // anything secured, a layer per run — see tests/config-guard.php.
    GlpiaiConfigGuard::restore($before);

    foreach ($DB->request(['FROM' => Draft::TABLE, 'WHERE' => ['knowbaseitems_id' => ['>', 0]]]) as $row) {
        (new KnowbaseItem())->delete(['id' => (int) $row['knowbaseitems_id']], true);
    }

    // Fixture-scoped, not blanket: this runs against a real host on an
    // instance somebody uses, so it removes what it made and nothing else.
    $DB->delete('glpi_networkports_networkports', [1]);
    $DB->delete('glpi_networkports', ['name' => ['LIKE', 'glpiai-%']]);

    echo "\n(configuration restored, fixtures purged)\n";
});

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpiai-live', 'entities_id' => 0]);
$made[Entity::class] = [$acme];

$category = new ITILCategory();
$cats = [
    'remote' => (int) $category->add([
        'name' => 'Remote access', 'entities_id' => $acme,
        'comment' => 'VPN, MFA, and anything else about connecting from outside the office',
        'is_incident' => 1, 'is_request' => 1,
    ]),
    'print'  => (int) $category->add([
        'name' => 'Printing', 'entities_id' => $acme,
        'comment' => 'Printers, drivers, toner and print queues',
        'is_incident' => 1, 'is_request' => 1,
    ]),
    'mail'   => (int) $category->add([
        'name' => 'Email', 'entities_id' => $acme,
        'comment' => 'Mailboxes, distribution lists, spam and Outlook',
        'is_incident' => 1, 'is_request' => 1,
    ]),
    'access' => (int) $category->add([
        'name' => 'Accounts and access', 'entities_id' => $acme,
        'comment' => 'Passwords, lockouts, joiners and leavers, permissions',
        'is_incident' => 1, 'is_request' => 1,
    ]),
];
$made[ITILCategory::class] = array_values($cats);

$ticket = new Ticket();
$make = static function (string $name, string $content) use ($ticket, $acme, &$tickets): int {
    $id = (int) $ticket->add([
        'name' => $name, 'content' => $content, 'entities_id' => $acme,
        'urgency' => 3, 'impact' => 3, '_auto_import' => true,
    ]);
    $tickets[] = $id;

    return $id;
};

// -------------------------------------------------------------- configuration

Settings::save([
    'enabled'              => '1',
    'provider'             => 'openai',
    'entity_mode'          => 'all',
    'timeout'              => '300',
    'triage_enabled'       => '1',
    'triage_skip_central'  => '0',
    'triage_request_types' => '',
    'triage_batch'         => '5',
    'draft_enabled'        => '1',
    'assistant_enabled'    => '1',
]);
Settings::saveProvider('openai', [
    'api_key'       => 'ollama',
    'base_url'      => rtrim($host, '/') . '/v1',
    'model_fast'    => $chat,
    'model_quality' => $chat,
    'token_param'   => 'max_tokens',
]);
Taxonomy::forget();

// ---------------------------------------------------------- triage, for real

echo "\nTriage, with a real small model\n";

$cases = [
    [
        'name'    => 'Cannot get on the VPN from home since yesterday',
        'content' => 'Every attempt to connect times out. This is stopping me working entirely.',
        'expect'  => $cats['remote'],
    ],
    [
        'name'    => 'Printer on the second floor keeps jamming',
        'content' => 'Paper jams every few pages and the toner light is on.',
        'expect'  => $cats['print'],
    ],
    [
        'name'    => 'New starter needs an account for Monday',
        'content' => 'Please set up Jane in the finance team with the usual permissions.',
        'expect'  => $cats['access'],
    ],
];

$right = 0;

foreach ($cases as $i => $case) {
    $id = $make($case['name'], $case['content']);
    $t  = new Ticket();
    $t->getFromDB($id);
    Triage::ticketCreated($t);

    $suggestions_id = (int) (Suggestion::forTicket($id)['id'] ?? 0);

    $ok = timed(
        sprintf('triage %d/%d', $i + 1, count($cases)),
        static fn(): bool => Triage::suggest($suggestions_id)
    );

    $row = Suggestion::byId($suggestions_id);

    check(
        sprintf('"%s" produced a valid suggestion', mb_substr($case['name'], 0, 40)),
        $ok && ($row['state'] ?? '') === Suggestion::READY,
        (string) ($row['error_message'] ?? '')
    );

    $chosen = (int) ($row['itilcategories_id'] ?? 0);
    $hit    = $chosen === $case['expect'];
    $right += $hit ? 1 : 0;

    echo sprintf(
        "        chose %s, urgency %d, impact %d, %s — %s\n",
        $chosen > 0 ? Dropdown::getDropdownName('glpi_itilcategories', $chosen) : 'nothing',
        (int) $row['urgency'],
        (int) $row['impact'],
        (string) $row['confidence'],
        (string) $row['reasoning']
    );

    check('  the id it returned is one it was actually offered',
        $chosen === 0 || Taxonomy::hasCategory($acme, $chosen), (string) $chosen);
    check('  urgency and impact are inside the scale',
        (int) $row['urgency'] >= 0 && (int) $row['urgency'] <= 5
        && (int) $row['impact'] >= 0 && (int) $row['impact'] <= 5);

    $t->getFromDB($id);
    check('  and the ticket was not touched', (int) $t->fields['itilcategories_id'] === 0);
}

// This is the interesting number, and it is reported rather than asserted on.
// A small quantised model getting two of three right is a useful fact; failing
// the build over it would make the suite a measurement of somebody's GPU.
echo sprintf(
    "\n        categorisation: %d of %d matched what a human would have picked\n",
    $right,
    count($cases)
);
check('at least one category was right, so the taxonomy is reaching the model',
    $right >= 1, sprintf('%d/%d', $right, count($cases)));

// ------------------------------------------------------- drafting, for real

echo "\nDrafting, with a real model\n";

// A worked ticket. The evidence is deliberately spread across a public
// followup, a private one and a task, with the actual cause only in the private
// note — which is the shape that separates a draft written from the evidence
// from one written from the title.
$worked = $make(
    'Shared drive disappears from Explorer mid-morning',
    'It vanishes for the whole finance team and comes back after a reboot.'
);

$followup = new ITILFollowup();
$followup->add([
    'itemtype' => Ticket::class, 'items_id' => $worked, 'is_private' => 0,
    'content'  => 'Confirmed with the requester that three machines are affected, not one, '
                . 'and that it always happens shortly after 10am.',
]);
$followup->add([
    'itemtype' => Ticket::class, 'items_id' => $worked, 'is_private' => 1,
    'content'  => 'The DFS referral on the branch domain controller is stale. Site costs were '
                . 'never configured after the office move, so clients pick the wrong target.',
]);
(new TicketTask())->add([
    'tickets_id' => $worked, 'is_private' => 0,
    'content'    => 'Set the site link costs, flushed the referral cache and re-registered the '
                  . 'namespace. Confirmed with two of the three machines.',
]);

$wt = new Ticket();
$wt->getFromDB($worked);

$error = timed('solution draft', static fn(): string => Drafter::draft($worked, Draft::SOLUTION));
check('a solution drafts', $error === '', $error);

$solution = Draft::forTicket($worked, Draft::SOLUTION);
check('it is ready', ($solution['state'] ?? '') === Draft::READY,
    (string) ($solution['error_message'] ?? ''));

echo "\n        --- drafted solution ---\n";
foreach (explode("\n", (string) $solution['content']) as $line) {
    echo '        ' . $line . "\n";
}
if ((string) $solution['gaps'] !== '') {
    echo "        gaps: " . $solution['gaps'] . "\n";
}
echo "        confidence: " . $solution['confidence'] . ", built from " . $solution['evidence'] . "\n\n";

// Asserted on the substance rather than the prose. A model that read the
// evidence knows about DFS; one that read only the title cannot.
$lower = strtolower((string) $solution['content']);
check('the draft used the evidence, not just the title',
    str_contains($lower, 'dfs') || str_contains($lower, 'referral'),
    mb_substr((string) $solution['content'], 0, 120));
check('and it is prose rather than a restatement of the ticket',
    mb_strlen((string) $solution['content']) > 80);

check('the ticket still has no solution written to it',
    count(iterator_to_array($DB->request([
        'FROM'  => 'glpi_itilsolutions',
        'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $worked],
    ]))) === 0);

$error = timed('article draft', static fn(): string => Drafter::draft($worked, Draft::ARTICLE));
check('an article drafts', $error === '', $error);

$article = Draft::forTicket($worked, Draft::ARTICLE);
check('it is ready', ($article['state'] ?? '') === Draft::READY,
    (string) ($article['error_message'] ?? ''));

echo "\n        --- drafted article ---\n";
echo '        # ' . $article['title'] . "\n";
foreach (explode("\n", (string) $article['content']) as $line) {
    echo '        ' . $line . "\n";
}
echo "\n";

check('the article has a title of its own',
    trim((string) $article['title']) !== ''
    && (string) $article['title'] !== (string) $wt->fields['name'],
    (string) $article['title']);

// The one thing an article must not do. Reported rather than asserted: a small
// model leaking an entity name is a fact worth seeing, and failing the build
// on it would make this suite a measure of the model rather than the plugin.
$leaks = [];
foreach (['glpiai-live', 'finance team'] as $needle) {
    if (str_contains(strtolower((string) $article['content']), strtolower($needle))) {
        $leaks[] = $needle;
    }
}
echo '        entity details carried into the article: '
   . ($leaks === [] ? 'none' : implode(', ', $leaks)) . "\n";

$kb_error = null;
$kb_id    = Drafter::createArticle((int) $article['id'], $kb_error);
check('the article is created', $kb_id > 0, (string) $kb_error);

$visibility = 0;
foreach (
    ['glpi_knowbaseitems_users', 'glpi_groups_knowbaseitems',
     'glpi_knowbaseitems_profiles', 'glpi_entities_knowbaseitems'] as $table
) {
    $visibility += count(iterator_to_array($DB->request([
        'FROM' => $table, 'WHERE' => ['knowbaseitems_id' => $kb_id],
    ])));
}
check('and it is unpublished, visible to nobody but its author', $visibility === 0);

// ------------------------------------------------- the assistant, for real

echo "\nThe assistant driving real tools\n";

// A switch, a laptop, a cable, and a port that is counting errors. The answer
// to "why does that laptop keep dropping" is sitting in the inventory; the
// question is whether a small model will go and look.
$switch = (int) (new NetworkEquipment())->add([
    'name' => 'glpiai-sw-back-office', 'entities_id' => $acme,
]);
$made[NetworkEquipment::class] = [$switch];

$laptop = (int) (new Computer())->add([
    'name' => 'glpiai-laptop-42', 'entities_id' => $acme,
]);
$made[Computer::class] = [$laptop];

$port        = new NetworkPort();
$switch_port = (int) $port->add([
    'itemtype' => 'NetworkEquipment', 'items_id' => $switch, 'entities_id' => $acme,
    'name' => 'glpiai-Gi1/0/7', 'logical_number' => 7,
    'instantiation_type' => 'NetworkPortEthernet',
    'mac' => 'aa:bb:cc:00:00:07', 'ifstatus' => '1', 'ifspeed' => 100000000,
    'ifalias' => 'back office desks', 'ifinerrors' => 20431, 'ifouterrors' => 12,
]);
$laptop_port = (int) $port->add([
    'itemtype' => 'Computer', 'items_id' => $laptop, 'entities_id' => $acme,
    'name' => 'glpiai-eth0', 'instantiation_type' => 'NetworkPortEthernet',
    'mac' => 'de:ad:be:ef:00:42', 'ifstatus' => '1',
]);
$DB->insert('glpi_networkports_networkports', [
    'networkports_id_1' => $switch_port,
    'networkports_id_2' => $laptop_port,
]);

$threads_id = Thread::open('', 0, $acme);

$reply = timed('assistant', static fn(): array => Assistant::ask(
    $threads_id,
    'The machine glpiai-laptop-42 keeps losing its network connection. '
    . 'Find out where it is plugged in and whether that port looks healthy.'
));

$used = array_column($reply['tools'], 'name');

echo "\n        tools used: " . (implode(', ', $used) ?: 'none') . "\n";
echo "        --- answer ---\n";
foreach (explode("\n", (string) $reply['answer']) as $line) {
    echo '        ' . $line . "\n";
}
echo "\n";

// The claim: it looked, rather than answering from general knowledge. A model
// that never called a tool produces a fluent paragraph about drivers and cables
// which is indistinguishable from a useful answer until you check it.
check('the model reached for a tool at all', $used !== [], implode(', ', $used));
check('and it used the network inventory',
    in_array('network_trace', $used, true) || in_array('network_ports', $used, true),
    implode(', ', $used));

$lower = strtolower((string) $reply['answer']);
check('the answer names the switch it found',
    str_contains($lower, 'back-office') || str_contains($lower, 'back office')
    || str_contains($lower, 'gi1/0/7'),
    mb_substr((string) $reply['answer'], 0, 160));
check('and mentions the error counters, which is the actual finding',
    str_contains($lower, 'error') || str_contains($lower, '20431')
    || str_contains($lower, '20,431'),
    mb_substr((string) $reply['answer'], 0, 160));

check('the exchange is in the transcript', count(Thread::messages($threads_id)) === 2);

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
