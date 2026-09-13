<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Drafting: the evidence, the two artefacts, and what is not written.
 *
 * The assertions divide the same way the feature does.
 *
 * Most of them are about **evidence**, because that is what distinguishes this
 * from a chat window: a draft written from a ticket's title is a fluent
 * invention, and the whole value is in the followups, the tasks, the checks a
 * procedure recorded, and the tickets that were resolved the same way. The mock
 * provider answers with a census of what actually arrived in the prompt, so
 * "the procedure's answers reached the model" is a checkable fact rather than
 * an intention.
 *
 * The rest are about **what does not happen**. This is the one feature whose
 * output is prose meant to be read by somebody other than the technician, so
 * there are explicit checks that the ticket has no solution written on it after
 * a draft is produced, and that an article created from a draft is invisible to
 * everyone but its author.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/drafts.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Draft\Drafter;
use GlpiPlugin\Glpiai\Draft\Evidence;
use GlpiPlugin\Glpiai\Settings;

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

    // Not a blanket delete: purging the fixture tickets already took their
    // drafts with them, and this is an instance somebody uses.
});

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpiai-draft-co', 'entities_id' => 0]);
$made[Entity::class] = [$acme];

$ticket = new Ticket();
$worked = (int) $ticket->add([
    'name'        => 'Shared drive disappears from Explorer mid-morning',
    'content'     => 'It vanishes for the whole finance team and comes back after a reboot.',
    'entities_id' => $acme,
    'urgency'     => 3,
    'impact'      => 3,
]);
$bare = (int) $ticket->add([
    'name'        => 'Something is broken',
    'content'     => 'Please help.',
    'entities_id' => $acme,
]);
$tickets = [$worked, $bare];

// A worked ticket: two followups, one of them internal, and a task.
$followup = new ITILFollowup();
$followup->add([
    'itemtype' => Ticket::class, 'items_id' => $worked, 'is_private' => 0,
    'content'  => 'Confirmed with the requester that it affects three machines, not one.',
]);
$followup->add([
    'itemtype' => Ticket::class, 'items_id' => $worked, 'is_private' => 1,
    'content'  => 'The DFS referral is stale on the branch DC. Do not tell them we mis-sized it.',
]);
(new TicketTask())->add([
    'tickets_id' => $worked, 'is_private' => 0,
    'content'    => 'Flushed the referral cache and re-registered the namespace.',
]);

echo "\nFixtures\n";
check('a worked ticket and a bare one exist', $worked > 0 && $bare > 0);

Settings::save([
    'enabled'       => '1',
    'provider'      => 'openai',
    'entity_mode'   => 'all',
    'draft_enabled' => '1',
]);
Settings::saveProvider('openai', [
    'api_key' => 'sk-draft-test', 'base_url' => MOCK,
    'model_fast' => 'mock-fast', 'model_quality' => 'mock-quality',
]);

// ------------------------------------------------------------------ evidence

echo "\nWhat the evidence gatherer collects\n";

$t = new Ticket();
$t->getFromDB($worked);
$evidence = Evidence::forTicket($t);

check('the timeline is collected', count($evidence['timeline']) === 3,
    (string) count($evidence['timeline']));
check('followups and tasks are told apart',
    count(array_filter($evidence['timeline'], static fn(array $e): bool => $e['kind'] === 'task')) === 1);
check('a private followup is marked private',
    count(array_filter($evidence['timeline'], static fn(array $e): bool => $e['private'])) === 1);
check('entries are in the order they happened',
    $evidence['timeline'][0]['when'] <= $evidence['timeline'][2]['when']);
check('HTML is flattened to text',
    !str_contains($evidence['timeline'][0]['text'], '<'));

$rendered = Evidence::render($evidence);
check('the rendered evidence names the ticket', str_contains($rendered, 'Shared drive disappears'));
check('and marks the internal note as internal', str_contains($rendered, 'INTERNAL'));
check('a summary says what it was built from',
    str_contains(Evidence::summarise($evidence), 'followup'), Evidence::summarise($evidence));

$t->getFromDB($bare);
check('a ticket with nothing on it is recognised as thin',
    Evidence::isThin(Evidence::forTicket($t)));
$t->getFromDB($worked);
check('and a worked one is not', !Evidence::isThin($evidence));

// -------------------------------------------------------------- the refusals

echo "\nWhen it declines\n";

$bt = new Ticket();
$bt->getFromDB($bare);

check('a thin ticket is refused rather than drafted',
    str_contains(Drafter::draft($bare, Draft::SOLUTION), 'nothing on this ticket'),
    Drafter::draft($bare, Draft::SOLUTION));
check('and nothing is stored for it', Draft::forTicket($bare, Draft::SOLUTION) === null);

Settings::save(['draft_enabled' => '0']);
check('drafting off refuses', str_contains(Drafter::draft($worked, Draft::SOLUTION), 'switched off'));
Settings::save(['draft_enabled' => '1']);

Settings::save(['enabled' => '0']);
check('the master switch refuses too',
    str_contains(Drafter::draft($worked, Draft::SOLUTION), 'switched off'));
Settings::save(['enabled' => '1']);

Settings::save(['entity_mode' => 'allowlist', 'entities' => '']);
check('an entity that may not use AI is refused',
    str_contains(Drafter::draft($worked, Draft::SOLUTION), 'not permitted'));
Settings::save(['entity_mode' => 'all']);

check('an unknown kind is refused',
    str_contains(Drafter::draft($worked, 'poem'), 'Unknown kind'));

// ------------------------------------------------------- the solution draft

echo "\nDrafting a solution\n";

check('drafting succeeds', Drafter::draft($worked, Draft::SOLUTION) === '');

$solution = Draft::forTicket($worked, Draft::SOLUTION);
check('it is ready', ($solution['state'] ?? '') === Draft::READY,
    (string) ($solution['error_message'] ?? ''));

// The mock answers with a census of what reached it, so these assert the whole
// evidence pipeline rather than the model's prose.
check('every followup reached the model',
    str_contains((string) $solution['content'], '2 notes'), (string) $solution['content']);
check('the internal one arrived marked',
    str_contains((string) $solution['content'], '(1 internal)'));
check('the task reached it too', str_contains((string) $solution['content'], '1 tasks'));
check('the system instruction arrived',
    (string) $solution['gaps'] === 'The instruction arrived.', (string) $solution['gaps']);
check('it used the quality tier', ($solution['model'] ?? '') === 'gpt-mock');
check('what it was built from is recorded',
    str_contains((string) $solution['evidence'], 'followup'), (string) $solution['evidence']);

// The claim the whole feature rests on.
check('no solution has been written to the ticket',
    count(iterator_to_array($DB->request([
        'FROM'  => 'glpi_itilsolutions',
        'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $worked],
    ]))) === 0);

$t->getFromDB($worked);
check('and the ticket is not resolved', (int) $t->fields['status'] !== Ticket::SOLVED);

// -------------------------------------------------------- the article draft

echo "\nDrafting an article\n";

check('drafting an article succeeds', Drafter::draft($worked, Draft::ARTICLE) === '');

$article = Draft::forTicket($worked, Draft::ARTICLE);
check('it is ready and separate from the solution draft',
    ($article['state'] ?? '') === Draft::READY && (int) $article['id'] !== (int) $solution['id']);
check('it has a title of its own', trim((string) $article['title']) !== '');
check('drafting again replaces rather than accumulating',
    count(iterator_to_array($DB->request([
        'FROM'  => Draft::TABLE,
        'WHERE' => ['tickets_id' => $worked],
    ]))) === 2);

$before_count = count(iterator_to_array($DB->request(['FROM' => 'glpi_knowbaseitems'])));

$error = null;
$kb_id = Drafter::createArticle((int) $article['id'], $error);
check('an article is created', $kb_id > 0, (string) $error);

$kb = new KnowbaseItem();
check('with the drafted title', $kb->getFromDB($kb_id)
    && (string) $kb->fields['name'] === (string) $article['title']);
check('it is not in the FAQ', (int) $kb->fields['is_faq'] === 0);
check('and its body is rendered markdown, because an article is a document',
    str_contains((string) $kb->fields['answer'], '<')
    && !str_contains((string) $kb->fields['answer'], '**'),
    mb_substr((string) $kb->fields['answer'], 0, 120));
check('and it is linked back to the ticket it came from',
    count(iterator_to_array($DB->request([
        'FROM'  => 'glpi_knowbaseitems_items',
        'WHERE' => ['knowbaseitems_id' => $kb_id, 'itemtype' => Ticket::class, 'items_id' => $worked],
    ]))) === 1);

// The safety mechanism for this half of the feature, asserted directly against
// GLPI's own visibility tables rather than trusted.
$visibility = 0;
foreach (
    ['glpi_knowbaseitems_users', 'glpi_groups_knowbaseitems',
     'glpi_knowbaseitems_profiles', 'glpi_entities_knowbaseitems'] as $table
) {
    $visibility += count(iterator_to_array($DB->request([
        'FROM' => $table, 'WHERE' => ['knowbaseitems_id' => $kb_id],
    ])));
}
check('the article is created with no visibility at all', $visibility === 0, (string) $visibility);

check('creating it is recorded on the draft',
    (string) Draft::byId((int) $article['id'])['outcome'] === Draft::USED
    && (int) Draft::byId((int) $article['id'])['knowbaseitems_id'] === $kb_id);

$again = null;
check('and it cannot be created twice',
    Drafter::createArticle((int) $article['id'], $again) === 0
    && str_contains((string) $again, 'already been created'));

$made[KnowbaseItem::class] = [$kb_id];

// -------------------------------------------------------------- markdown

echo "\nRendering what the model wrote\n";

$rendered = GlpiPlugin\Glpiai\Markdown::toHtml(
    "It is the **DFS referral**.\n\n"
    . "*   Check `site link costs`\n"
    . "*   Flush the cache\n\n"
    . "1. First\n2. Second\n"
);

check('emphasis becomes markup rather than asterisks',
    str_contains($rendered, '<strong>DFS referral</strong>'), $rendered);
check('inline code is rendered', str_contains($rendered, '<code>site link costs</code>'));
check('bullet lists survive', str_contains($rendered, '<ul>') && str_contains($rendered, '<li>'));
check('so do numbered ones', str_contains($rendered, '<ol>'));

// The part that must never regress. This is model output about entity data,
// and it is put into the DOM directly — so raw HTML has to come back escaped
// and an unsafe scheme has to lose its href.
$hostile = GlpiPlugin\Glpiai\Markdown::toHtml(
    "<script>alert(1)</script>\n\n"
    . "<img src=x onerror=alert(1)>\n\n"
    . "[click](javascript:alert(1))\n\n"
    . "[fine](https://example.com)"
);

check('a script tag is escaped, not passed through',
    !str_contains($hostile, '<script>') && str_contains($hostile, '&lt;script&gt;'), $hostile);
check('an inline event handler cannot arrive as markup',
    !str_contains($hostile, '<img'), $hostile);
check('a javascript: link loses its href',
    !str_contains($hostile, 'javascript:'), $hostile);
check('while an ordinary link keeps one',
    str_contains($hostile, 'href="https://example.com"'));

check('an empty draft renders to nothing rather than an empty paragraph',
    GlpiPlugin\Glpiai\Markdown::toHtml('   ') === '');

// --------------------------------------------------------------- the metric

echo "\nThe use rate\n";

check('marking a solution used is recorded', Drafter::markUsed((int) $solution['id']));
check('as used', (string) Draft::byId((int) $solution['id'])['outcome'] === Draft::USED);
check('with the person who decided',
    (int) Draft::byId((int) $solution['id'])['users_id_decided'] === (int) Session::getLoginUserID());

$usage = Draft::usage();
check('the solution counts as used', $usage[Draft::SOLUTION]['used'] === 1,
    json_encode($usage[Draft::SOLUTION]));
check('so does the article', $usage[Draft::ARTICLE]['used'] === 1);
check('the rate is over decisions', $usage[Draft::SOLUTION]['rate'] === 1.0);

Drafter::discard((int) $solution['id']);
check('discarding overwrites the outcome',
    (string) Draft::byId((int) $solution['id'])['outcome'] === Draft::DISCARDED);
check('and the rate follows', Draft::usage()[Draft::SOLUTION]['rate'] === 0.0);

// ------------------------------------------------------------ when it breaks

echo "\nWhen the provider will not answer\n";

Settings::saveProvider('openai', [
    'api_key' => 'sk-draft-test', 'base_url' => 'http://127.0.0.1:9',
    'model_fast' => 'mock-fast', 'model_quality' => 'mock-quality',
]);

$broken = Drafter::draft($worked, Draft::SOLUTION);
check('the caller is told why', $broken !== '' && str_contains($broken, 'Could not reach'), $broken);
check('and the draft is marked failed',
    (string) Draft::forTicket($worked, Draft::SOLUTION)['state'] === Draft::FAILED);

Settings::saveProvider('openai', [
    'api_key' => 'sk-draft-test', 'base_url' => MOCK,
    'model_fast' => 'mock-fast', 'model_quality' => 'mock-quality',
]);

// An empty answer is a failure, not an empty draft: a blank solution editor
// that claims to have been drafted is worse than an error.
$followup->add([
    'itemtype' => Ticket::class, 'items_id' => $worked, 'is_private' => 0,
    'content'  => 'MOCKEMPTY',
]);
$empty = Drafter::draft($worked, Draft::SOLUTION);
check('an empty answer is refused rather than stored',
    str_contains($empty, 'empty draft'), $empty);
check('and recorded as a failure',
    (string) Draft::forTicket($worked, Draft::SOLUTION)['state'] === Draft::FAILED);

// -------------------------------------------------------------------- purge

echo "\nWhen the ticket goes\n";

Settings::save(['draft_enabled' => '0']);
(new Ticket())->delete(['id' => $worked], true);
check('a purged ticket loses its drafts even with the feature off',
    Draft::forTicket($worked, Draft::SOLUTION) === null
    && Draft::forTicket($worked, Draft::ARTICLE) === null);
$tickets = array_values(array_diff($tickets, [$worked]));
Settings::save(['draft_enabled' => '1']);

check('but the article it produced survives, because it is a document now',
    (new KnowbaseItem())->getFromDB($kb_id));

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
