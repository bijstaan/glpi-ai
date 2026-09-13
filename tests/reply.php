<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Reply review: the strip, the flags that survive, and the outcome.
 *
 * Three groups of assertions, and they line up with the three ways this
 * feature could be wrong without looking wrong.
 *
 * **Where it draws.** The followup form does not hand the hook its parent —
 * the timeline includes that template with `form_mode`, `subitem` and
 * `mention_options` and nothing else — so the panel reads the itemtype and
 * items_id that core sets on the blank followup. Getting that wrong renders an
 * empty string on every ticket and says nothing about why, which is the
 * quietest possible failure. There are also checks that it draws nothing for a
 * requester, nothing on a followup that has already been sent, and no
 * `<button>` without `type="button"` — inside the reply form a bare button
 * submits it, which here means posting an unfinished reply to a requester.
 *
 * **What survives.** A flag whose quote is not in the reply is discarded. The
 * mock provider is built to make that checkable: it answers by comparing the
 * INTERNAL notes with the reply, so an internal flag coming back is evidence
 * that the notes reached the prompt — and if they had not, a canned reviewer
 * would still have returned something entirely plausible about tone.
 *
 * **The outcome.** Nothing here is applied, so there is no accept to count.
 * The measurement is whether the reply was edited between being reviewed and
 * being posted, which means a fingerprint that ignores markup and notices a
 * changed word.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/reply.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Reply\Panel;
use GlpiPlugin\Glpiai\Reply\Review;
use GlpiPlugin\Glpiai\Reply\Reviewer;
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
        $DB->delete(Review::TABLE, ['itemtype' => Ticket::class, 'items_id' => $id]);
        (new Ticket())->delete(['id' => $id], true);
    }
    foreach ($made as $itemtype => $ids) {
        foreach (array_reverse($ids) as $id) {
            (new $itemtype())->delete(['id' => $id], true);
        }
    }

    GlpiaiConfigGuard::restore($before);
});

$entity = new Entity();
$acme   = (int) $entity->add(['name' => 'glpiai-reply-co', 'entities_id' => 0]);
$made[Entity::class] = [$acme];

$ticket     = new Ticket();
$tickets_id = (int) $ticket->add([
    'name'        => 'Mailbox stopped syncing on the laptop',
    'content'     => 'Nothing has arrived since Tuesday and Outlook says disconnected.',
    'entities_id' => $acme,
]);
$tickets = [$tickets_id];

// The internal note is the whole point of the feature: a candid word between
// colleagues, and a distinctive one so a reply that carries it across is
// unambiguous.
(new ITILFollowup())->add([
    'itemtype'   => Ticket::class,
    'items_id'   => $tickets_id,
    'is_private' => 1,
    'content'    => 'Their mailbox is over quota because nobody archived it for two years. '
                  . 'Provisioning was mis-sized when we onboarded them.',
]);
(new ITILFollowup())->add([
    'itemtype'   => Ticket::class,
    'items_id'   => $tickets_id,
    'is_private' => 0,
    'content'    => 'Confirmed with the requester that other devices are fine.',
]);

$ticket->getFromDB($tickets_id);

Settings::save([
    'enabled'              => '1',
    'provider'             => 'openai',
    'entity_mode'          => 'all',
    'reply_review_enabled' => '1',
]);
Settings::saveProvider('openai', [
    'api_key' => 'sk-reply-test', 'base_url' => MOCK,
    'model_fast' => 'mock-fast', 'model_quality' => 'mock-quality',
]);

echo "\nFixtures\n";
check('a ticket with an internal note exists', $tickets_id > 0);

// ------------------------------------------------------------------ the strip

echo "\nWhere the strip draws\n";

$blank = new ITILFollowup();
$blank->getEmpty();
$blank->fields['itemtype'] = Ticket::class;
$blank->fields['items_id'] = $tickets_id;

ob_start();
Panel::render($blank);
$html = ob_get_clean();

check('it draws on a new reply', str_contains($html, 'data-glpiai-reply-check'));
check('it found the parent it was never handed',
    str_contains($html, "data-glpiai-reply-items-id='$tickets_id'"));
check('every button is type=button', !preg_match('/<button(?![^>]*type=.button.)/', $html));
check('no button carries a name', !preg_match('/<button[^>]*\sname=/', $html));

$sent = new ITILFollowup();
$sent->fields = ['id' => 1, 'itemtype' => Ticket::class, 'items_id' => $tickets_id];
ob_start();
Panel::render($sent);
check('nothing draws on a reply already sent', ob_get_clean() === '');

$was = $_SESSION['glpiactiveprofile']['interface'];
$_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
ob_start();
Panel::render($blank);
$helpdesk = ob_get_clean();
$_SESSION['glpiactiveprofile']['interface'] = $was;
check('a requester sees nothing', $helpdesk === '');

Settings::save(['reply_review_enabled' => '0']);
ob_start();
Panel::render($blank);
check('nothing draws while the feature is off', ob_get_clean() === '');
Settings::save(['reply_review_enabled' => '1']);

// ------------------------------------------------------------------ refusals

echo "\nWhat it refuses to look at\n";

check('an acknowledgement is too short to review',
    Reviewer::refusal($ticket, 'Done, thanks.') !== null);
check('a real reply is not',
    Reviewer::refusal($ticket, 'We have cleared the mailbox and it is syncing again now.') === null);

Settings::save(['entity_mode' => 'allowlist', 'entities' => '']);
check('an entity outside the allowlist is refused',
    Reviewer::refusal($ticket, 'We have cleared the mailbox and it is syncing again now.') !== null);
Settings::save(['entity_mode' => 'all']);

// -------------------------------------------------------------------- review

echo "\nThe review itself\n";

// Carries "provisioning" straight out of the internal note, and never says
// what happens next. That word appears nowhere else on the ticket — not in the
// title, not in what the requester wrote — so a flag naming it is evidence
// that the *internal* notes reached the prompt rather than the public ones.
$leaky = '<p>Hello, we have looked at this. The provisioning here was mis-sized, '
       . 'so it filled up.</p>';

$result = Reviewer::review($ticket, $leaky);
check('the review ran', $result['ok'], $result['message']);
check('it says to check', $result['verdict'] === 'check');

$kinds = array_column($result['flags'], 'kind');
check('the internal notes reached the prompt', in_array('internal', $kinds, true),
    implode(',', $kinds));
check('a missing next step is raised', in_array('next_step', $kinds, true));

$internal = array_values(array_filter(
    $result['flags'],
    static fn(array $f): bool => $f['kind'] === 'internal'
))[0] ?? null;
check('the internal flag quotes the reply, verbatim',
    $internal !== null && str_contains(strtolower($leaky), strtolower($internal['quote'])),
    (string) ($internal['quote'] ?? ''));
check('and the word it caught is one only the internal note used',
    strtolower((string) ($internal['quote'] ?? '')) === 'provisioning',
    (string) ($internal['quote'] ?? ''));

// Nothing in this one is shared with an internal note, and it says what
// happens next. Deliberately free of the words those notes use.
$clean = '<p>Hello, your email is flowing again. We will keep an eye on it and '
       . 'close this tomorrow if all stays well.</p>';
$ok = Reviewer::review($ticket, $clean);
check('a reply with nothing carried across and a next step passes',
    $ok['ok'] && $ok['verdict'] === 'ok', implode(',', array_column($ok['flags'], 'kind')));
check('and the panel says so plainly',
    str_contains(Panel::result($ok['verdict'], $ok['flags']), 'glpiai-reply-clean'));

// Nothing is written to the ticket by any of this. It is the assertion that
// matters most: the reply belongs to the technician until they press Save.
$after = new Ticket();
$after->getFromDB($tickets_id);
check('the ticket has no new followup on it',
    countElementsInTable(ITILFollowup::getTable(), [
        'itemtype' => Ticket::class, 'items_id' => $tickets_id,
    ]) === 2);
check('and its status is untouched',
    (int) $after->fields['status'] === (int) $ticket->fields['status']);

// ------------------------------------------------------------------ outcomes

echo "\nWhat became of the reply\n";

check('markup alone is not an edit',
    Review::fingerprint('<p>Hello   there.</p>') === Review::fingerprint("<div>Hello\nthere.</div>"));
check('a changed word is an edit',
    Review::fingerprint('<p>Hello there.</p>') !== Review::fingerprint('<p>Hello again.</p>'));

$rows = [];
foreach (
    $DB->request([
        'FROM'  => Review::TABLE,
        'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
        'ORDER' => 'id ASC',
    ]) as $row
) {
    $rows[] = $row;
}
check('both reviews were recorded', count($rows) === 2, (string) count($rows));
check('the flagged one counted its flags', (int) $rows[0]['flag_count'] > 0);
check('the clean one recorded none', (int) $rows[1]['flag_count'] === 0);
check('no reply text was stored', !str_contains(json_encode($rows), 'mis-sized'));

// The technician takes the advice, edits, and posts.
$edited = new ITILFollowup();
$edited->add([
    'itemtype'   => Ticket::class,
    'items_id'   => $tickets_id,
    'is_private' => 0,
    'content'    => '<p>Hello, your email is flowing again. We will check it tomorrow.</p>',
]);

$closed = [];
foreach (
    $DB->request([
        'FROM'  => Review::TABLE,
        'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
        'ORDER' => 'id ASC',
    ]) as $row
) {
    $closed[] = $row;
}

// The newest open review is the one a posted reply answers, and only one.
check('the reply closed off the latest review', (int) $closed[1]['sent'] === 1);
check('it noticed the text had changed', (int) $closed[1]['changed'] === 1);
check('the earlier one is still open', (int) $closed[0]['sent'] === 0);

$summary = Review::summary($acme);
check('the summary counts the flagged review', $summary['flagged'] === 1);
check('and rates only what was actually sent', $summary['rate'] === null,
    var_export($summary['rate'], true));

echo "\n";
if ($failures === []) {
    echo "\033[32mAll checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($failures) . " failed.\033[0m\n";
exit(1);
