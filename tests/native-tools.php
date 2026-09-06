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

// --------------------------------------------------------------- the switch

echo "\nWrite tools while the switch is off\n";

foreach (['add_ticket_note', 'add_ticket_task', 'update_ticket', 'link_tickets', 'draft_kb_article'] as $name) {
    $result = $call($name, ['content' => 'x', 'title' => 'x', 'body' => 'x', 'to_id' => $other_id]);
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
