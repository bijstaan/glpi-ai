<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The native tools added after the first six: the deeper reads, and the writes.
 *
 * Driven through `ToolRegistry::execute()` rather than by calling the handlers,
 * because for a write tool the interesting behaviour is mostly *not* in the
 * handler: the write switch, the profile right, the missing-argument check and
 * the audit entry all live on the path a model actually takes, and a suite that
 * called the handler directly would pass with every one of them broken.
 *
 * The assertions divide three ways.
 *
 * **Reads** are checked for what they put together rather than for returning
 * something. A `read_ticket` that omits the assignee is not a failure any
 * exception will catch, and it is the difference between a model that can
 * answer "who is dealing with this" and one that cannot.
 *
 * **Writes** are checked as much for what they refuse as for what they do:
 * that a note cannot be made public, that no tool changes status or
 * assignment, that a link needs rights at both ends, and that every one of
 * them is inert while the administrator's write switch is off.
 *
 * What is *not* here is the audit trail. `ToolRegistry::execute()` runs the
 * tool and stops; the row in `glpi_plugin_glpiai_toollogs` is written by
 * `Client::run()`, one layer up, and tests/tools.php drives that loop against
 * the mock provider. A suite asserting on the audit from here would have been
 * asserting against the wrong layer and passing only by accident.
 *
 * **Rights** are checked by taking them away. The suite drops the session's
 * profile rights and asserts the refusal, because "the tool declares a right"
 * and "the tool enforces it" are different claims and only the second one
 * matters.
 *
 * The later sections cover the wider reads — the service-level clocks, the
 * counting, the customer, the estate and the people — and they are asserted
 * on the arithmetic rather than on the plumbing. Every one of those tools
 * exists because a model was previously doing the sum itself and getting it
 * wrong sometimes: counting rows a capped search returned, adding months to a
 * purchase date, reading a warranty *start* as an expiry. A suite that only
 * proved they return something would not have caught any of those.
 *
 * Usage, inside the GLPI container:
 *   php tests/native-tools.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\ToolCall;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolRegistry;

require __DIR__ . '/config-guard.php';

/** @var DBmysql $DB */
global $DB;

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

$before = GlpiaiConfigGuard::snapshot();
$made   = [];

register_shutdown_function(static function () use ($before, &$made): void {
    foreach (array_reverse($made) as [$itemtype, $id]) {
        (new $itemtype())->delete(['id' => $id], true);
    }

    GlpiaiConfigGuard::restore($before);
});

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpiai-tools-co', 'entities_id' => 0]);
$made[] = [Entity::class, $acme];

$person = new User();
$users_id = (int) $person->add([
    'name'      => 'glpiai.tools.tester',
    'realname'  => 'Tester',
    'firstname' => 'Tools',
    'password'  => 'nope-not-used-1234',
    'password2' => 'nope-not-used-1234',
    '_profiles_id' => 0,
]);
$made[] = [User::class, $users_id];

$computer   = new Computer();
$computers_id = (int) $computer->add([
    'name'        => 'glpiai-tools-laptop',
    'serial'      => 'TOOLS-0001',
    'entities_id' => $acme,
    'users_id'    => $users_id,
]);
$made[] = [Computer::class, $computers_id];

$ticket    = new Ticket();
$tickets_id = (int) $ticket->add([
    'name'        => 'Shared drive keeps dropping on the laptop',
    'content'     => 'It disappears mid-morning and comes back after a reboot.',
    'entities_id' => $acme,
    'urgency'     => 3,
    'impact'      => 3,
]);
$made[] = [Ticket::class, $tickets_id];

// The requester as its own row rather than through `_users_id_requester`.
// GLPI 11 takes actors through an `_actors` structure on the form and the old
// key is quietly ignored here, which produced a ticket with no requester and a
// test that was checking the fixture rather than the tool.
(new Ticket_User())->add([
    'tickets_id' => $tickets_id,
    'users_id'   => $users_id,
    'type'       => CommonITILActor::REQUESTER,
]);

$other_id = (int) (new Ticket())->add([
    'name'        => 'Shared drive gone again',
    'content'     => 'Same as the other one.',
    'entities_id' => $acme,
]);
$made[] = [Ticket::class, $other_id];

(new Item_Ticket())->add([
    'itemtype'   => Computer::class,
    'items_id'   => $computers_id,
    'tickets_id' => $tickets_id,
]);

$ticket->getFromDB($tickets_id);

$context = new ToolContext($acme, Ticket::class, $tickets_id);

/** Run a tool the way a model does, and give back the decoded result. */
$call = static function (string $name, array $arguments) use ($acme, $context): array {
    $all   = ToolRegistry::all($acme);
    $tools = array_values($all);

    $invocation = ToolRegistry::execute(
        new ToolCall('t-' . $name, $name, $arguments),
        $tools,
        $context
    );

    $decoded = json_decode($invocation->result->content, true);

    return [
        'ok'      => !$invocation->failed(),
        'message' => $invocation->result->content,
        'data'    => is_array($decoded) ? $decoded : [],
        'mutating' => $invocation->mutating,
    ];
};

echo "\nFixtures\n";
check('an entity, a person, a laptop and two tickets exist',
    $acme > 0 && $users_id > 0 && $computers_id > 0 && $tickets_id > 0 && $other_id > 0);

Settings::save(['enabled' => '1', 'entity_mode' => 'all', 'allow_write_tools' => '0']);

// ---------------------------------------------------------------- the reads

echo "\nWhat the deeper reads put together\n";

$read = $call('read_ticket', ['id' => $tickets_id]);
check('read_ticket answers', $read['ok'], $read['message']);
check('it names the requester',
    str_contains(implode(',', $read['data']['requesters'] ?? []), 'Tester'),
    json_encode($read['data']['requesters'] ?? []));
// The full path, which is what GLPI's own dropdown shows: on an MSP install
// "Acme > Manchester" and "Globex > Manchester" are different places and the
// leaf name alone does not say which.
check('it names the entity, including a non-root one',
    str_contains((string) ($read['data']['entity'] ?? ''), 'glpiai-tools-co'),
    (string) ($read['data']['entity'] ?? ''));
check('it names the asset the ticket is about',
    ($read['data']['items'][0]['name'] ?? '') === 'glpiai-tools-laptop',
    json_encode($read['data']['items'] ?? []));

$user = $call('read_user', ['id' => $users_id]);
check('read_user answers', $user['ok'], $user['message']);
check('it finds the laptop assigned to them',
    ($user['data']['assets'][0]['name'] ?? '') === 'glpiai-tools-laptop',
    json_encode($user['data']['assets'] ?? []));
check('and the ticket they have open',
    ($user['data']['open_tickets'][0]['id'] ?? 0) === $tickets_id,
    json_encode($user['data']['open_tickets'] ?? []));

$found = $call('read_user', ['query' => 'glpiai.tools.tester']);
check('read_user finds them by login', ($found['data']['id'] ?? 0) === $users_id,
    json_encode(array_slice($found['data'], 0, 3)));

$asset = $call('read_asset', ['itemtype' => 'computer', 'id' => $computers_id]);
check('read_asset answers', $asset['ok'], $asset['message']);
check('it carries the serial', ($asset['data']['serial'] ?? '') === 'TOOLS-0001');
check('it names who it is assigned to',
    str_contains((string) ($asset['data']['assigned_to'] ?? ''), 'Tester'),
    (string) ($asset['data']['assigned_to'] ?? ''));
check('and the ticket raised against it',
    ($asset['data']['tickets'][0]['id'] ?? 0) === $tickets_id,
    json_encode($asset['data']['tickets'] ?? []));

$history = $call('item_history', ['itemtype' => 'ticket', 'id' => $tickets_id]);
check('item_history answers', $history['ok'], $history['message']);
check('and returns plain text, not escaped markup',
    !str_contains($history['message'], '&quot;') && !str_contains($history['message'], '&amp;'));

$itil = $call('search_itil', ['query' => 'nothing-matches-this-xyzzy']);
check('search_itil answers an empty search without failing', $itil['ok'], $itil['message']);
check('and says so rather than returning nothing at all',
    ($itil['data']['count'] ?? -1) === 0 && ($itil['data']['note'] ?? '') !== '');

// ---------------------------------------------------------- the wider reads

echo "\nThe record, the promise, the customer, the estate and the people\n";

$problem     = new Problem();
$problems_id = (int) $problem->add([
    'name'           => 'Shared drive drops across the branch',
    'content'        => 'Several people, same symptom.',
    'entities_id'    => $acme,
    'symptomcontent' => 'The mapped drive vanishes mid-morning.',
    'causecontent'   => 'The branch DC holds a stale DFS referral.',
]);
$made[] = [Problem::class, $problems_id];

$itil = $call('read_itil', ['itemtype' => 'Problem', 'id' => $problems_id]);
check('read_itil reads a problem', $itil['ok'], $itil['message']);
// The whole reason this tool exists: search_itil stops at the title, and the
// cause is the only field that says what was actually wrong.
check('and returns the cause somebody worked out',
    str_contains((string) ($itil['data']['cause'] ?? ''), 'stale DFS referral'),
    (string) ($itil['data']['cause'] ?? ''));
check('and the symptom, separately',
    str_contains((string) ($itil['data']['symptom'] ?? ''), 'vanishes'),
    (string) ($itil['data']['symptom'] ?? ''));

$wrong = $call('read_itil', ['itemtype' => 'Ticket', 'id' => $tickets_id]);
check('it refuses a ticket and names the tool that does them',
    !$wrong['ok'] && str_contains($wrong['message'], 'read_ticket'), $wrong['message']);

$sla = $call('sla_status', ['tickets_id' => $tickets_id]);
check('sla_status answers on a ticket with no agreement', $sla['ok'], $sla['message']);
check('and says no service level applies rather than implying time is left',
    str_contains(json_encode($sla['data']['response'] ?? []) ?: '', 'No service level'),
    json_encode($sla['data']['response'] ?? []));

$stats = $call('ticket_stats', ['days' => 30, 'group_by' => 'status']);
check('ticket_stats counts', $stats['ok'], $stats['message']);
check('it counts both fixture tickets', ($stats['data']['opened'] ?? 0) >= 2,
    json_encode($stats['data']['opened'] ?? null));
// The union-operator bug this caught in development: `$scope + [...]` keeps
// the left-hand value on a numeric key collision, which silently dropped the
// breach conditions and reported every ticket in the window as breached.
check('and the breach count is a subset of the opened count, not a copy of it',
    (int) ($stats['data']['breached_resolution'] ?? -1) <= (int) ($stats['data']['opened'] ?? 0),
    json_encode([$stats['data']['breached_resolution'] ?? null, $stats['data']['opened'] ?? null]));
check('a breakdown by an actor names the unassigned rather than dropping them',
    (function (array $rows): bool {
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === 'nobody') {
                return true;
            }
        }
        return false;
    })($call('ticket_stats', ['days' => 30, 'group_by' => 'technician'])['data']['breakdown'] ?? []));
$bad_dimension = $call('ticket_stats', ['group_by' => 'phase-of-the-moon']);
check('and an unknown dimension is refused with the list of real ones',
    !$bad_dimension['ok'] && str_contains($bad_dimension['message'], 'category'),
    $bad_dimension['message']);

$customer = $call('read_entity', ['entities_id' => $acme]);
check('read_entity reads the customer', $customer['ok'], $customer['message']);
check('it names them', ($customer['data']['name'] ?? '') === 'glpiai-tools-co');
check('and counts what they have',
    ($customer['data']['tickets']['open_now'] ?? 0) >= 2
    && ($customer['data']['assets']['Computers'] ?? 0) >= 1,
    json_encode([$customer['data']['tickets'] ?? null, $customer['data']['assets'] ?? null]));

$contract    = new Contract();
$contracts_id = (int) $contract->add([
    'name'        => 'glpiai-tools support',
    'entities_id' => $acme,
    'begin_date'  => date('Y-m-d', strtotime('-2 months')),
    // Two years, three months' notice: the notice deadline is the number
    // nobody has in mind and the reason this tool computes it.
    'duration'    => 24,
    'notice'      => 3,
]);
$made[] = [Contract::class, $contracts_id];

$contracts = $call('read_contract', ['entities_id' => $acme]);
check('read_contract finds the customer\'s contract', $contracts['ok'], $contracts['message']);
$first = $contracts['data']['contracts'][0] ?? [];
check('it works out the end date from the duration',
    ($first['expires'] ?? '') === date('Y-m-d', strtotime(date('Y-m-d', strtotime('-2 months')) . ' +24 months')),
    (string) ($first['expires'] ?? ''));
check('and the date notice actually has to be given by',
    ($first['give_notice_by'] ?? '') === date('Y-m-d', strtotime((string) $first['expires'] . ' -3 months')),
    json_encode([$first['expires'] ?? null, $first['give_notice_by'] ?? null]));
check('an expiry window that excludes it returns nothing rather than everything',
    ($call('read_contract', ['entities_id' => $acme, 'expiring_within_days' => 1])['data']['contracts'] ?? []) === []);

// Updated rather than added. GLPI creates an empty Infocom row with the asset
// when the "financial information" autofill is on, and a second `add()` for
// the same item is refused — which leaves the tool reading the empty row and a
// suite asserting against a fixture that was never written.
$infocom = new Infocom();
if (!$infocom->getFromDBforDevice(Computer::class, $computers_id)) {
    $infocom_id = (int) $infocom->add([
        'itemtype'    => Computer::class,
        'items_id'    => $computers_id,
        'entities_id' => $acme,
    ]);
    $infocom->getFromDB($infocom_id);
}
$infocom->update([
    'id'                => (int) $infocom->getID(),
    'buy_date'          => date('Y-m-d', strtotime('-6 months')),
    'warranty_date'     => date('Y-m-d', strtotime('-6 months')),
    'warranty_duration' => 36,
]);
$made[] = [Infocom::class, (int) $infocom->getID()];

$life = $call('asset_lifecycle', ['itemtype' => 'Computer', 'items_id' => $computers_id]);
check('asset_lifecycle reads the purchase record', $life['ok'], $life['message']);
// GLPI's `warranty_date` is when the warranty *starts* — its own autofill
// copies the purchase date into it — so reading that column as the expiry
// reports every machine as out of warranty on the day it was bought.
check('the warranty expires three years after it started, not on the day it did',
    ($life['data']['warranty']['expires'] ?? '')
        === date('Y-m-d', strtotime(date('Y-m-d', strtotime('-6 months')) . ' +36 months')),
    json_encode($life['data']['warranty'] ?? []));
check('and it is reported as still in warranty',
    ($life['data']['warranty']['in_warranty'] ?? false) === true);
check('the estate-wide form finds it too',
    (function (array $rows) use ($computers_id): bool {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $computers_id) {
                return true;
            }
        }
        return false;
    })($call('asset_lifecycle', ['expiring_within_days' => 1200])['data']['expiring'] ?? []));

$software    = new Software();
$softwares_id = (int) $software->add(['name' => 'glpiai-tools-suite', 'entities_id' => $acme]);
$made[] = [Software::class, $softwares_id];

$version    = new SoftwareVersion();
$versions_id = (int) $version->add([
    'name'         => '4.2',
    'softwares_id' => $softwares_id,
    'entities_id'  => $acme,
]);
$made[] = [SoftwareVersion::class, $versions_id];

(new Item_SoftwareVersion())->add([
    'itemtype'            => Computer::class,
    'items_id'            => $computers_id,
    'softwareversions_id' => $versions_id,
    'entities_id'         => $acme,
]);

$installed = $call('software_inventory', ['itemtype' => 'Computer', 'items_id' => $computers_id]);
check('software_inventory lists what is on the machine', $installed['ok'], $installed['message']);
check('with the version',
    ($installed['data']['installed'][0]['version'] ?? '') === '4.2',
    json_encode($installed['data']['installed'] ?? []));

$estate = $call('software_inventory', ['name' => 'glpiai-tools-suite']);
check('and finds every machine running it the other way round',
    ($estate['data']['matched'][0]['installed_on'] ?? 0) === 1,
    json_encode($estate['data']['matched'] ?? []));
// Licences are counted separately from installations on purpose: an estate
// with none recorded is short, and reporting a shortfall of zero there would
// be the flattering answer rather than the true one.
check('and reports the shortfall when no licence is recorded',
    ($estate['data']['matched'][0]['shortfall'] ?? 0) === 1,
    json_encode($estate['data']['matched'][0] ?? []));

$group    = new Group();
$groups_id = (int) $group->add(['name' => 'glpiai-tools-team', 'entities_id' => $acme, 'is_assign' => 1]);
$made[] = [Group::class, $groups_id];
(new Group_User())->add(['groups_id' => $groups_id, 'users_id' => $users_id]);

$team = $call('read_group', ['name' => 'glpiai-tools-team']);
check('read_group finds a group by name', $team['ok'], $team['message']);
// Matched on the surname alone: whether a friendly name reads "Tools Tester"
// or "Tester Tools" is a display setting, and asserting on the order would
// make this suite fail on an instance configured the other way.
check('and expands it into people',
    str_contains(implode(',', $team['data']['members'] ?? []), 'Tester'),
    json_encode($team['data']['members'] ?? []));
check('and says it can be assigned work',
    in_array('assignment', $team['data']['used_for'] ?? [], true),
    json_encode($team['data']['used_for'] ?? []));

$diary = $call('check_availability', ['days' => 7]);
check('check_availability answers for the signed-in user', $diary['ok'], $diary['message']);
// An empty week must not read as "they are free": the note is the answer.
check('and an empty week says what it does not know',
    str_contains((string) ($diary['data']['note'] ?? ''), 'not evidence'),
    (string) ($diary['data']['note'] ?? ''));

$approvals = $call('read_validations', ['items_id' => $tickets_id]);
check('read_validations answers on a ticket with none', $approvals['ok'], $approvals['message']);
check('and says the delay is somewhere else rather than staying silent',
    str_contains((string) ($approvals['data']['note'] ?? ''), 'somewhere else'),
    (string) ($approvals['data']['note'] ?? ''));
check('asking what is waiting on you answers too',
    $call('read_validations', ['waiting_on_me' => 'yes'])['ok']);

$mail = $call('notification_status', ['items_id' => $tickets_id]);
check('notification_status answers', $mail['ok'], $mail['message']);
// Creating the ticket queued its own notifications, which is the useful
// fixture: every row must carry a state, because "GLPI generated a message"
// and "the customer was told" are different claims and the state is the only
// thing separating them.
check('every message carries a state',
    ($mail['data']['notifications'] ?? []) !== []
    && array_reduce(
        $mail['data']['notifications'],
        static fn(bool $c, array $row): bool => $c && ($row['state'] ?? '') !== '',
        true
    ),
    json_encode($mail['data']['notifications'] ?? []));
check('and what has not gone out yet is counted rather than implied',
    ($mail['data']['still_queued'] ?? 0) >= 1, json_encode($mail['data']['still_queued'] ?? null));

// The other half of the same claim, on a record nothing has ever notified
// about — an asset, which raises no notifications of its own. An empty queue
// must not read as proof that nothing was sent.
$quiet = $call('notification_status', ['itemtype' => 'Computer', 'items_id' => $computers_id]);
check('an empty queue is not reported as proof nothing was sent',
    str_contains((string) ($quiet['data']['note'] ?? ''), 'cleaned'),
    (string) ($quiet['data']['note'] ?? ''));

$stock = $call('supply_levels', ['low_only' => 'no']);
check('supply_levels answers', $stock['ok'], $stock['message']);

// --------------------------------------------------------------- the switch

echo "\nWrite tools while the switch is off\n";

foreach ([
    'add_ticket_note', 'add_ticket_task', 'update_ticket', 'link_tickets', 'draft_kb_article',
    'add_itil_note', 'link_asset_to_ticket',
] as $name) {
    $result = $call($name, [
        'content'  => 'x',
        'title'    => 'x',
        'body'     => 'y',
        'to_id'    => $other_id,
        'itemtype' => 'Problem',
        'items_id' => $problems_id,
    ]);
    check("$name is refused", !$result['ok'] && str_contains($result['message'], 'write tools are disabled'),
        $result['message']);
}

check('and nothing was written',
    countElementsInTable(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $tickets_id]) === 0);

Settings::save(['allow_write_tools' => '1']);

// ---------------------------------------------------------------- the writes

echo "\nWhat the writes do\n";

$note = $call('add_ticket_note', [
    'content' => "Checked the DFS referral: stale on the branch DC.\n\nFlushed and re-registered.",
]);
check('add_ticket_note writes a note', $note['ok'], $note['message']);
check('it used the ticket from the conversation',
    ($note['data']['tickets_id'] ?? 0) === $tickets_id);

$rows = getAllDataFromTable(ITILFollowup::getTable(),
    ['itemtype' => Ticket::class, 'items_id' => $tickets_id]);
$written = array_values($rows)[0] ?? [];
check('exactly one followup exists', count($rows) === 1, (string) count($rows));
// The assertion this tool exists to satisfy. A public followup is a reply to
// the customer, and nothing here may write one.
check('and it is PRIVATE', (int) ($written['is_private'] ?? 0) === 1);
check('the paragraphs survived as HTML',
    substr_count((string) ($written['content'] ?? ''), '<p>') === 2,
    (string) ($written['content'] ?? ''));
check('there is no argument that would make it public',
    !array_key_exists('is_private', ToolRegistry::all($acme)['add_ticket_note']->schema['properties']));

$public = $call('add_ticket_note', ['content' => 'try it', 'is_private' => 0]);
check('and passing one anyway changes nothing', $public['ok']);
$rows = getAllDataFromTable(ITILFollowup::getTable(),
    ['itemtype' => Ticket::class, 'items_id' => $tickets_id, 'is_private' => 0]);
check('no public followup was created', $rows === [], json_encode($rows));

$task = $call('add_ticket_task', ['content' => 'Re-registered the namespace', 'minutes' => 25]);
check('add_ticket_task writes a task', $task['ok'], $task['message']);
check('with the time on it', ($task['data']['minutes'] ?? 0) === 25);
$tasks = getAllDataFromTable(TicketTask::getTable(), ['tickets_id' => $tickets_id]);
check('and GLPI stored it in seconds',
    (int) (array_values($tasks)[0]['actiontime'] ?? 0) === 1500,
    (string) (array_values($tasks)[0]['actiontime'] ?? 0));

$category = new ITILCategory();
$categories_id = (int) $category->add(['name' => 'glpiai-tools-category', 'entities_id' => $acme]);
$made[] = [ITILCategory::class, $categories_id];

$update = $call('update_ticket', ['category' => $categories_id, 'urgency' => 5]);
check('update_ticket changes the filing', $update['ok'], $update['message']);
$ticket->getFromDB($tickets_id);
check('the category was set', (int) $ticket->fields['itilcategories_id'] === $categories_id);
check('the urgency was set', (int) $ticket->fields['urgency'] === 5);
check('and it reports the priority core recomputed',
    ($update['data']['priority'] ?? 0) === (int) $ticket->fields['priority'],
    json_encode($update['data']));

$was_status = (int) $ticket->fields['status'];
$sneaky = $call('update_ticket', ['status' => 6, 'urgency' => 2]);
$ticket->getFromDB($tickets_id);
check('a status it was never offered is ignored', (int) $ticket->fields['status'] === $was_status,
    (string) $ticket->fields['status']);
check('while the field it was offered still applies', (int) $ticket->fields['urgency'] === 2);
check('no write tool offers status or assignment', (function (array $tools): bool {
    foreach (['update_ticket', 'add_ticket_note', 'add_ticket_task', 'link_tickets'] as $name) {
        $keys = array_keys($tools[$name]->schema['properties'] ?? []);
        if (array_intersect($keys, ['status', 'assign', 'users_id_assign', 'groups_id_assign'])) {
            return false;
        }
    }
    return true;
})(ToolRegistry::all($acme)));

$bad = $call('update_ticket', ['urgency' => 9]);
check('an urgency out of range is refused', !$bad['ok'], $bad['message']);
$nothing = $call('update_ticket', []);
check('and asking for nothing is refused rather than silently succeeding', !$nothing['ok']);

$link = $call('link_tickets', ['to_id' => $other_id, 'how' => 'duplicate_of']);
check('link_tickets links them', $link['ok'], $link['message']);
check('the link is visible from the other end', (function (int $a, int $b): bool {
    $rows = getAllDataFromTable('glpi_tickets_tickets', [
        'OR' => [['tickets_id_1' => $a, 'tickets_id_2' => $b], ['tickets_id_1' => $b, 'tickets_id_2' => $a]],
    ]);
    return count($rows) === 1;
})($tickets_id, $other_id));
$again = $call('link_tickets', ['to_id' => $other_id]);
check('linking twice fails with something the model can act on',
    !$again['ok'] && str_contains($again['message'], 'already be linked'), $again['message']);
$self = $call('link_tickets', ['to_id' => $tickets_id]);
check('a ticket cannot be linked to itself', !$self['ok']);

$read = $call('read_ticket', ['id' => $tickets_id]);
check('read_ticket reports the link, from the right end',
    ($read['data']['linked'][0]['how'] ?? '') === 'duplicate',
    json_encode($read['data']['linked'] ?? []));

$article = $call('draft_kb_article', [
    'title' => 'Shared drive disappears mid-morning',
    'body'  => "## Symptom\n\nThe mapped drive vanishes.\n\n## Fix\n\nFlush the referral cache.",
]);
check('draft_kb_article creates one', $article['ok'], $article['message']);
$kb_id = (int) ($article['data']['created']['id'] ?? 0);
if ($kb_id > 0) {
    $made[] = [KnowbaseItem::class, $kb_id];
    check('it says it is unpublished', ($article['data']['published'] ?? true) === false);
    // The assertion that matters: no visibility rows means nobody but the
    // author can see it, which is what "unpublished" means in GLPI.
    // The visibility tables GLPI 11 actually has. There is no
    // `glpi_knowbaseitems_groups` and no `..._entities`: group and entity
    // visibility live in `glpi_knowbaseitems_profiles` and on the item, which
    // is worth knowing before writing an assertion against the obvious names.
    check('and it really has no visibility',
        countElementsInTable('glpi_knowbaseitems_users', ['knowbaseitems_id' => $kb_id]) === 0
        && countElementsInTable('glpi_knowbaseitems_profiles', ['knowbaseitems_id' => $kb_id]) === 0);
    check('the markdown was rendered', (function (int $id): bool {
        $kb = new KnowbaseItem();
        $kb->getFromDB($id);
        return str_contains((string) $kb->fields['answer'], '<h2');
    })($kb_id));
    check('and it is linked to the ticket it came from',
        countElementsInTable('glpi_knowbaseitems_items', [
            'knowbaseitems_id' => $kb_id, 'itemtype' => Ticket::class, 'items_id' => $tickets_id,
        ]) === 1);
}

echo "\nThe two writes beyond the ticket\n";

$pnote = $call('add_itil_note', [
    'itemtype' => 'Problem',
    'items_id' => $problems_id,
    'content'  => 'Confirmed on three machines at the branch.',
]);
check('add_itil_note writes on a problem', $pnote['ok'], $pnote['message']);
$prows = getAllDataFromTable(ITILFollowup::getTable(),
    ['itemtype' => Problem::class, 'items_id' => $problems_id]);
check('exactly one followup landed on it', count($prows) === 1, (string) count($prows));
check('and it is PRIVATE, like the ticket one',
    (int) (array_values($prows)[0]['is_private'] ?? 0) === 1);
check('there is no argument that would make it public',
    !array_key_exists('is_private', ToolRegistry::all($acme)['add_itil_note']->schema['properties']));
$on_ticket = $call('add_itil_note', ['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'content' => 'x']);
check('it refuses a ticket and names the tool that does them',
    !$on_ticket['ok'] && str_contains($on_ticket['message'], 'add_ticket_note'), $on_ticket['message']);

$attach = $call('link_asset_to_ticket', [
    'tickets_id' => $other_id,
    'itemtype'   => 'Computer',
    'items_id'   => $computers_id,
]);
check('link_asset_to_ticket attaches the machine', $attach['ok'], $attach['message']);
check('the link exists',
    countElementsInTable(Item_Ticket::getTable(), [
        'tickets_id' => $other_id, 'itemtype' => Computer::class, 'items_id' => $computers_id,
    ]) === 1);
$twice = $call('link_asset_to_ticket', [
    'tickets_id' => $other_id,
    'itemtype'   => 'Computer',
    'items_id'   => $computers_id,
]);
// Not an error: the state the caller wanted is the state it is in, and a
// model told "that failed" would go and try something else.
check('attaching it twice says so without failing or duplicating',
    $twice['ok'] && ($twice['data']['linked'] ?? '') === 'already', $twice['message']);
check('and there is still one link',
    countElementsInTable(Item_Ticket::getTable(), [
        'tickets_id' => $other_id, 'itemtype' => Computer::class, 'items_id' => $computers_id,
    ]) === 1);
check('no tool can take a link away again', (function (array $tools): bool {
    foreach ($tools as $tool) {
        if (preg_match('/^(unlink|detach|remove|delete)_/', $tool->name) === 1) {
            return false;
        }
    }
    return true;
})(ToolRegistry::all($acme)));

// ----------------------------------------------------------------- the rights

echo "\nWith the rights taken away\n";

$profile = $_SESSION['glpiactiveprofile'];

$_SESSION['glpiactiveprofile']['followup'] = READ;
$denied = $call('add_ticket_note', ['content' => 'should not land']);
check('no followup right, no note', !$denied['ok'], $denied['message']);

$_SESSION['glpiactiveprofile']['ticket'] = READ;
$denied = $call('update_ticket', ['urgency' => 1]);
check('no ticket update right, no field change', !$denied['ok'], $denied['message']);

$_SESSION['glpiactiveprofile']['knowbase'] = READ;
$denied = $call('draft_kb_article', ['title' => 'x', 'body' => 'y']);
check('no knowbase create right, no article', !$denied['ok'], $denied['message']);

$_SESSION['glpiactiveprofile']['ticket'] = READ;
$denied = $call('link_asset_to_ticket', [
    'tickets_id' => $tickets_id,
    'itemtype'   => 'Computer',
    'items_id'   => $computers_id,
]);
check('no ticket update right, no asset attached', !$denied['ok'], $denied['message']);

// A read gated on a right it does not hold, to prove the reads are gated at
// all: the write tools would pass this suite with every read wide open.
$_SESSION['glpiactiveprofile']['contract'] = 0;
$denied = $call('read_contract', ['entities_id' => $acme]);
check('no contract right, no contracts', !$denied['ok'], $denied['message']);

$_SESSION['glpiactiveprofile']['ticket'] = READ;
$denied = $call('ticket_stats', ['days' => 7]);
check('counting needs more than READ of a ticket', !$denied['ok'], $denied['message']);

$_SESSION['glpiactiveprofile'] = $profile;

// Two notes: the real one, and the one whose `is_private => 0` was ignored.
// Nothing was added by the three refused calls above.
check('nothing landed while the rights were gone',
    countElementsInTable(ITILFollowup::getTable(),
        ['itemtype' => Ticket::class, 'items_id' => $tickets_id]) === 2,
    (string) countElementsInTable(ITILFollowup::getTable(),
        ['itemtype' => Ticket::class, 'items_id' => $tickets_id]));

echo "\n";
if ($failures === []) {
    echo "\033[32mAll checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($failures) . " failed.\033[0m\n";
exit(1);
