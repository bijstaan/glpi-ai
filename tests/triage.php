<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Triage: eligibility, the call, validation, and what a human did about it.
 *
 * The suite is organised around the two things that can go badly wrong here,
 * neither of which is "the model was wrong".
 *
 * The first is a suggestion being *written*. This is the first feature in the
 * plugin that offers to change a ticket, and the entire safety argument is that
 * nothing happens without a click. So several assertions below check that the
 * ticket is untouched at points where it would be easy for it not to be —
 * after a suggestion is produced, after one is dismissed, and after a model
 * names a category that does not exist.
 *
 * The second is the accuracy figure being flattering. A suggestion that merely
 * agreed with GLPI's defaults is not a win, and counting it as one would
 * produce an accept rate that mostly measures the defaults. That is asserted
 * explicitly rather than left to the implementation.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/triage.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Triage\Panel;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\Triage\Taxonomy;
use GlpiPlugin\Glpiai\Triage\Triage;

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

    // Not a blanket delete. Purging each fixture ticket above already removed
    // its suggestion through the purge hook, and this is an instance somebody
    // uses — a test tidying up is not entitled to their data.
});

$entity   = new Entity();
$customer = (int) $entity->add(['name' => 'glpiai-triage-co', 'entities_id' => 0]);
$made[Entity::class] = [$customer];

$category = new ITILCategory();
$vpn = (int) $category->add([
    'name'        => 'Remote access',
    'comment'     => 'VPN, MFA and anything else about getting in from outside',
    'entities_id' => $customer,
    'is_incident' => 1,
    'is_request'  => 1,
]);
$printing = (int) $category->add([
    'name'        => 'Printing',
    'entities_id' => $customer,
    'is_incident' => 1,
    'is_request'  => 1,
]);
$elsewhere = (int) $category->add([
    'name'         => 'Somebody else only',
    'entities_id'  => 0,
    'is_recursive' => 0,
    'is_incident'  => 1,
]);
$made[ITILCategory::class] = [$vpn, $printing, $elsewhere];

$mail_type = (int) ($DB->request([
    'SELECT' => ['id'], 'FROM' => 'glpi_requesttypes', 'WHERE' => ['is_mail_default' => 1],
])->current()['id'] ?? 2);
$phone_type = (int) ($DB->request([
    'SELECT' => ['id'], 'FROM' => 'glpi_requesttypes', 'WHERE' => ['name' => 'Phone'],
])->current()['id'] ?? 3);

Settings::save([
    'enabled'              => '1',
    'provider'             => 'openai',
    'entity_mode'          => 'all',
    'triage_enabled'       => '1',
    'triage_request_types' => (string) $mail_type,
    'triage_skip_central'  => '0',
    'triage_batch'         => '10',
]);
Settings::saveProvider('openai', [
    'api_key'    => 'sk-triage-test',
    'base_url'   => MOCK,
    'model_fast' => 'mock-fast',
]);
Taxonomy::forget();

/** Make a ticket without the create hook queueing it, so tests control the queue. */
$makeTicket = static function (array $fields) use (&$tickets, $customer, $mail_type): int {
    $ticket = new Ticket();
    $id = (int) $ticket->add($fields + [
        'entities_id'     => $customer,
        'requesttypes_id' => $mail_type,
        'urgency'         => 3,
        'impact'          => 3,
        '_auto_import'    => true,
    ]);
    $tickets[] = $id;

    return $id;
};

// --------------------------------------------------------------- the taxonomy

echo "\nWhat the model is offered\n";

$instruction = Taxonomy::instruction($customer);

check('the entity\'s own categories are in the system instruction',
    str_contains($instruction, 'Remote access') && str_contains($instruction, 'Printing'));
check('another entity\'s categories are not',
    !str_contains($instruction, 'Somebody else only'));
check('a category comment goes with it, because it is what disambiguates two similar names',
    str_contains($instruction, 'MFA'));
check('the urgency scale is spelled out', str_contains($instruction, Ticket::getUrgencyName(5)));
check('and the instruction says what 0 means',
    str_contains($instruction, 'Use 0 for a category'));

check('a category from this entity is accepted', Taxonomy::hasCategory($customer, $vpn));
check('one from another entity is not', !Taxonomy::hasCategory($customer, $elsewhere));
check('and an invented id certainly is not', !Taxonomy::hasCategory($customer, 999999));

// The prefix has to be byte-identical between tickets or a provider's prompt
// cache never hits, which is the whole reason it is built separately.
check('the instruction is stable across calls',
    Taxonomy::instruction($customer) === $instruction);

// -------------------------------------------------------------- eligibility

echo "\nWhich tickets get triaged\n";

$mailed = $makeTicket(['name' => 'Cannot connect', 'content' => 'It will not let me in.']);
$ticket = new Ticket();
$ticket->getFromDB($mailed);

check('a ticket raised by email qualifies', Triage::skipReason($ticket) === null);

Settings::save(['triage_request_types' => (string) $phone_type]);
check('one whose request type is not listed does not',
    Triage::skipReason($ticket) === 'request type is not triaged');
Settings::save(['triage_request_types' => (string) $mail_type]);

Settings::save(['triage_skip_central' => '1']);
check('nor one raised from the technician interface',
    Triage::skipReason($ticket) === 'raised from the technician interface',
    (string) Triage::skipReason($ticket));
Settings::save(['triage_skip_central' => '0']);

Settings::save(['entity_mode' => 'allowlist', 'entities' => '']);
check('nor one in an entity that may not use AI at all',
    str_contains((string) Triage::skipReason($ticket), 'not permitted'));
Settings::save(['entity_mode' => 'all']);

Settings::save(['triage_enabled' => '0']);
check('nor any, with triage switched off',
    Triage::skipReason($ticket) === 'triage is switched off');
Settings::save(['triage_enabled' => '1']);

Settings::save(['enabled' => '0']);
check('nor any, with the master switch off',
    Triage::skipReason($ticket) === 'AI features are switched off');
Settings::save(['enabled' => '1']);

// ------------------------------------------------------------------ queueing

echo "\nQueueing, and not calling anybody\n";

Triage::ticketCreated($ticket);
$row = Suggestion::forTicket($mailed);

check('a qualifying ticket is queued', $row !== null && $row['state'] === Suggestion::PENDING);
check('creating it made no provider call', (int) ($row['provider'] ?? 0) === 0);

Triage::ticketCreated($ticket);
check('queueing twice leaves one row',
    count(iterator_to_array($DB->request([
        'FROM' => Suggestion::TABLE, 'WHERE' => ['tickets_id' => $mailed],
    ]))) === 1);

// ------------------------------------------------------------ the suggestion

echo "\nAsking the model\n";

$vpn_ticket = $makeTicket([
    'name'    => 'Remote access broken since this morning',
    'content' => 'Nobody can get in. This is urgent, everyone is affected.',
]);
$t = new Ticket();
$t->getFromDB($vpn_ticket);
Triage::ticketCreated($t);

$result = Triage::run();
check('the run reports what it did', $result['done'] >= 2, json_encode($result));

$suggestion = Suggestion::forTicket($vpn_ticket);
check('the suggestion is ready', ($suggestion['state'] ?? '') === Suggestion::READY,
    json_encode($suggestion['error_message'] ?? ''));
check('it chose the category the words point at',
    (int) $suggestion['itilcategories_id'] === $vpn,
    (string) $suggestion['itilcategories_id']);
check('urgency reflects the ticket, not the default',
    (int) $suggestion['urgency'] === 4, (string) $suggestion['urgency']);
check('so does impact', (int) $suggestion['impact'] === 4, (string) $suggestion['impact']);
// The model recorded is the one the *response* named, not the one asked for.
// That is the useful half: vendors route silently, and a regression can only be
// attributed to a model change if what actually answered was written down.
check('it recorded which provider and model answered',
    ($suggestion['provider'] ?? '') === 'openai' && ($suggestion['model'] ?? '') === 'gpt-mock',
    ($suggestion['provider'] ?? '') . '/' . ($suggestion['model'] ?? ''));
check('and a sentence for the technician', trim((string) $suggestion['reasoning']) !== '');

$t->getFromDB($vpn_ticket);
check('the ticket itself is untouched',
    (int) $t->fields['itilcategories_id'] === 0
    && (int) $t->fields['urgency'] === 3
    && (int) $t->fields['impact'] === 3);

// --------------------------------------------------------------- validation

echo "\nNot trusting the answer\n";

$bad = $makeTicket(['name' => 'MOCKBADCAT printing trouble', 'content' => 'MOCKBADCAT']);
$bt  = new Ticket();
$bt->getFromDB($bad);
Triage::ticketCreated($bt);
Triage::run();

$bad_row = Suggestion::forTicket($bad);
check('a category id that was never offered is discarded',
    (int) $bad_row['itilcategories_id'] === 0, (string) $bad_row['itilcategories_id']);
check('and the rest of the suggestion still stands',
    (int) $bad_row['urgency'] > 0 && ($bad_row['state'] === Suggestion::READY));

$none = $makeTicket(['name' => 'MOCKNOCAT something unusual', 'content' => 'MOCKNOCAT']);
$nt   = new Ticket();
$nt->getFromDB($none);
Triage::ticketCreated($nt);
Triage::run();

$none_row = Suggestion::forTicket($none);
check('a model saying "nothing fits" is recorded as such',
    (int) $none_row['itilcategories_id'] === 0);
check('and says so with low confidence',
    (string) $none_row['confidence'] === 'low', (string) $none_row['confidence']);

// -------------------------------------------------------- already-right case

echo "\nSuggestions that are not decisions\n";

$already = $makeTicket([
    'name'              => 'Printing is broken',
    'content'           => 'The printer will not print.',
    'itilcategories_id' => $printing,
]);
$at = new Ticket();
$at->getFromDB($already);
Triage::ticketCreated($at);
Triage::run();

$already_row = Suggestion::forTicket($already);
check('a suggestion matching what the ticket already says is marked matched',
    (string) $already_row['category_outcome'] === Suggestion::MATCHED,
    (string) $already_row['category_outcome']);
check('and no chip is offered for it',
    !str_contains(capture(static fn() => Panel::render($at)), 'itilcategories_id'));

// ---------------------------------------------------------------- deciding

echo "\nWhat a technician does about it\n";

$suggestion = Suggestion::forTicket($vpn_ticket);
$sid        = (int) $suggestion['id'];

check('applying a category writes it to the ticket', Triage::apply($sid, 'itilcategories_id') === '');
$t->getFromDB($vpn_ticket);
check('the ticket now has it', (int) $t->fields['itilcategories_id'] === $vpn);
check('and the outcome is recorded as accepted',
    (string) Suggestion::byId($sid)['category_outcome'] === Suggestion::ACCEPTED);
check('with the person who decided',
    (int) Suggestion::byId($sid)['users_id_decided'] === (int) Session::getLoginUserID());

check('dismissing is recorded', Triage::dismiss($sid, 'urgency'));
check('as dismissed', (string) Suggestion::byId($sid)['urgency_outcome'] === Suggestion::DISMISSED);
$t->getFromDB($vpn_ticket);
check('and changes nothing on the ticket', (int) $t->fields['urgency'] === 3);

check('an unknown field is refused', Triage::apply($sid, 'status') === 'unknown field');
check('so is a field with nothing suggested',
    Triage::apply((int) $none_row['id'], 'itilcategories_id') === 'nothing suggested');

// --------------------------------------------------------------- the metric

echo "\nThe accuracy figure\n";

$accuracy = Suggestion::accuracy();

check('an accepted field counts as accepted',
    $accuracy['itilcategories_id']['accepted'] >= 1, json_encode($accuracy['itilcategories_id']));
check('a dismissed one counts against',
    $accuracy['urgency']['dismissed'] >= 1, json_encode($accuracy['urgency']));
check('the already-right ones are counted separately',
    $accuracy['itilcategories_id']['matched'] >= 1);
check('and are excluded from the rate, so it measures decisions rather than defaults',
    abs($accuracy['itilcategories_id']['rate'] - (
        $accuracy['itilcategories_id']['accepted']
        / ($accuracy['itilcategories_id']['accepted'] + $accuracy['itilcategories_id']['dismissed'])
    )) < 0.0001);
check('a field nobody has decided has no rate at all, rather than zero',
    $accuracy['plugin_glpisop_sops_id']['rate'] === null,
    json_encode($accuracy['plugin_glpisop_sops_id']));

// ------------------------------------------------------------ when it breaks

echo "\nWhen the provider will not answer\n";

Settings::saveProvider('openai', [
    'api_key'    => 'sk-triage-test',
    'base_url'   => 'http://127.0.0.1:9',
    'model_fast' => 'mock-fast',
]);

$broken = $makeTicket(['name' => 'Something else', 'content' => 'Anything.']);
$brt = new Ticket();
$brt->getFromDB($broken);
Triage::ticketCreated($brt);

$failed_run = Triage::run();
check('the run does not throw', is_array($failed_run));
check('the ticket is marked failed rather than left queued',
    (string) Suggestion::forTicket($broken)['state'] === Suggestion::FAILED);
check('with something a human can act on',
    trim((string) Suggestion::forTicket($broken)['error_message']) !== '',
    (string) Suggestion::forTicket($broken)['error_message']);
check('and the panel says so instead of showing nothing',
    str_contains(capture(static fn() => Panel::render($brt)), 'glpiai-triage'));

Settings::saveProvider('openai', [
    'api_key'    => 'sk-triage-test',
    'base_url'   => MOCK,
    'model_fast' => 'mock-fast',
]);

// -------------------------------------------------------------------- purge

echo "\nWhen the ticket goes\n";

Settings::save(['triage_enabled' => '0']);
(new Ticket())->delete(['id' => $broken], true);
check('a purged ticket loses its suggestion even with triage off',
    Suggestion::forTicket($broken) === null);
$tickets = array_values(array_diff($tickets, [$broken]));
Settings::save(['triage_enabled' => '1']);

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);

/** Run something that echoes and return what it echoed. */
function capture(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}
