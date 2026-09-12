<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Can the model actually *find* these tools?
 *
 * Past `tool_search_threshold` registered tools a request declares the pinned
 * handful plus `find_tools`, and everything else — every estate-wide read,
 * every write, and all sixty-odd a plugin contributes — exists only if that
 * search returns it. A tool nobody can find is indistinguishable from one that
 * was never written, and nothing else in the suite would notice: the registry
 * lists it, the handler works, the rights are right, and no model ever calls
 * it.
 *
 * The ranker is `count(matching name words) * 2 + count(matching description
 * words)` over a stopword-filtered bag of words, with a trailing "s" trimmed
 * and no other stemming and no synonyms at all. That is the right amount of
 * machinery and it has one consequence worth a suite: a description is a
 * retrieval surface as well as prose, so "changed" does not match "changing",
 * "team" does not match "group", and a provider or product name has to appear
 * literally. Every miss this file has caught was a description written for a
 * reader rather than for the search.
 *
 * **The questions are the point.** Each one is phrased the way a technician
 * would ask it — not with the tool's own vocabulary, which is the test that
 * passes for free. A tool that only answers to its own name is one the model
 * will only find when it already knew what it wanted.
 *
 * Tools whose plugin is not installed are skipped rather than failed: this
 * suite runs on whatever the instance has, and an estate without glpi-major
 * should not fail on the question about incident timelines.
 *
 * Usage, inside the GLPI container:
 *   php tests/discovery.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolCall;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolRegistry;
use GlpiPlugin\Glpiai\Toolbox;

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);

$failures = [];
$skipped  = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name
       . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

/**
 * What somebody would ask, and the tool that had better come back.
 *
 * @var array<string,string>
 */
const QUESTIONS = [
    // The native reads.
    'how long have I got on this ticket'                  => 'sla_status',
    'how many tickets did this customer raise last month' => 'ticket_stats',
    'who is the customer and what is their address'       => 'read_entity',
    'is this machine still under warranty'                => 'asset_lifecycle',
    'what software is installed on this laptop'           => 'software_inventory',
    'is the work covered by a contract'                   => 'read_contract',
    'do we have any toner left'                           => 'supply_levels',
    'who is in the network team'                          => 'read_group',
    'is the technician free on Thursday'                  => 'check_availability',
    'who has to approve this change'                      => 'read_validations',
    'did the customer actually get the email'             => 'notification_status',
    'read the backout plan of this change'                => 'read_itil',
    'what changed on this machine'                        => 'item_history',

    // The native writes.
    'write a note on this problem'                        => 'add_itil_note',
    'attach the computer to the ticket'                   => 'link_asset_to_ticket',
    'record the time I spent on this ticket'              => 'add_ticket_task',
    'save this as a knowledge article'                    => 'draft_kb_article',

    // The plugins.
    'who is on call tonight'                              => 'on_call',
    'are the backups working'                             => 'backup_status',
    'why did nothing alert us'                            => 'maintenance_windows',
    'which machines are affected by this CVE'             => 'vuln_advisory',
    'are the laptops encrypted'                           => 'osquery_compliance',
    'what changed in the cloud recently'                  => 'cloud_changes',
    'what is going out in this release'                   => 'change_releases',
    'what came out of the major incident review'          => 'major_actions',
    'how did we handle the outage timeline'               => 'major_timeline',
    'is there planned maintenance for this customer'      => 'major_maintenance',
    'what recurring problems should we raise'             => 'kedb_candidates',
    'read the known error in full'                        => 'kedb_read',
    'is this project going to land on time'               => 'project_status',
    'what are the risks on this project'                  => 'project_raid',
    'who is over-booked over the next few weeks'          => 'project_workload',
    'what did we send the customer in the report'         => 'service_reviews',
    'how happy is the customer with the survey'           => 'satisfaction',
    'which machines make up this business service'        => 'service_members',
    'is the network being scanned'                        => 'network_coverage',
    'what identity provider does this customer use'       => 'identity_sources',
    'what is on the improvement register'                 => 'improvement_board',
    'export this ticket as a pdf'                         => 'export_pdf',
    'what happens if the client is out of contract'       => 'entitlement_policy',
];

$all   = ToolRegistry::all(0);
$box   = Toolbox::tool($all);
$there = new ToolContext(0);

echo "\nThe registry\n";
check('there are enough tools for the search to engage at all',
    Toolbox::engages($all), (string) count($all));

$offered = Toolbox::offer($all);
check('and the request declares a subset rather than all of them',
    count($offered) < count($all),
    sprintf('%d declared of %d registered', count($offered), count($all)));

// The reason the search exists. A pinned set that grows with every plugin is
// the cost problem it was built to solve, coming back.
check('the declared set stays small as the registry grows',
    count($offered) <= 40, (string) count($offered));

check('find_tools itself is always declared',
    (function (array $offered): bool {
        foreach ($offered as $tool) {
            if ($tool->name === Toolbox::NAME) {
                return true;
            }
        }
        return false;
    })($offered));

echo "\nWhat a technician would ask, and what comes back\n";

foreach (QUESTIONS as $question => $wanted) {
    if (!isset($all[$wanted])) {
        $skipped++;
        continue;
    }

    // Through `execute()` rather than by calling the search directly: the
    // model reaches it as a tool call, and a refusal or a bad argument on
    // that path is exactly the failure this is checking for.
    $invocation = ToolRegistry::execute(
        new ToolCall('q', Toolbox::NAME, ['query' => $question]),
        [$box],
        $there
    );

    $body  = json_decode($invocation->result->content, true);
    $names = array_column($body['tools'] ?? [], 'name');
    $rank  = array_search($wanted, $names, true);

    check(
        sprintf('"%s" finds %s', $question, $wanted),
        $rank !== false,
        $rank === false
            ? 'got: ' . (implode(', ', array_slice($names, 0, 5)) ?: 'nothing')
            : sprintf('at #%d', $rank + 1)
    );
}

echo "\nWhat the search says about what it found\n";

$invocation = ToolRegistry::execute(
    new ToolCall('q', Toolbox::NAME, ['query' => 'write a note on this problem']),
    [$box],
    $there
);
$body = json_decode($invocation->result->content, true);

// A model that finds a tool and reports the name back as a suggestion is a
// model that was never told what happens next.
check('it says the tools it found are now callable',
    str_contains((string) ($body['note'] ?? ''), 'callable'),
    (string) ($body['note'] ?? ''));

check('and says which of them the caller may actually use',
    array_reduce(
        (array) ($body['tools'] ?? []),
        static fn(bool $c, array $row): bool => $c && array_key_exists('usable', $row),
        true
    ));

$expanded = Toolbox::expand($offered, [$invocation], $all);
check('a search actually widens the next turn\'s offer',
    count($expanded) > count($offered),
    sprintf('%d then %d', count($offered), count($expanded)));

echo "\nNames and descriptions\n";

check('every registered name is portable across all four vendors',
    array_reduce($all, static fn(bool $c, Tool $t): bool => $c && Tool::isValidName($t->name), true));

// Short descriptions are the ones the ranker cannot find and the model cannot
// choose between. The floor is low; anything under it is an oversight.
$thin = array_values(array_filter(
    $all,
    static fn(Tool $t): bool => str_word_count($t->description) < 12 && !str_starts_with($t->name, 'mcp__')
));
check('every tool this suite ships describes itself in more than a phrase',
    $thin === [],
    implode(', ', array_map(static fn(Tool $t): string => $t->name, $thin)));

echo "\n";
if ($skipped > 0) {
    echo "$skipped question(s) skipped — their plugin is not installed here.\n";
}

if ($failures === []) {
    echo "\033[32mAll checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($failures) . " failed.\033[0m\n";
exit(1);
