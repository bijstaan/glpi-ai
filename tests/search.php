<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The ranker both searches share, and the catalogue line a skill shows.
 *
 * {@see GlpiPlugin\Glpiai\Search} was lifted out of Toolbox when skill search
 * needed the same thing, which means a regression here is a regression in
 * `find_tools` as well — and the symptom would not be an error. Both searches
 * would keep returning something; only the ordering would quietly stop being
 * useful, and the way that reads from the outside is a model that has started
 * choosing the wrong tool.
 *
 * Needs nothing but PHP: the ranking is word overlap over text it is handed,
 * and the summary is the first legible line of an administrator's own writing.
 *
 * Usage, from the plugin directory:
 *   php tests/search.php
 */

namespace {
    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string
        {
            return $text;
        }
    }

    if (!function_exists('_n')) {
        function _n(string $one, string $many, int $nb, string $domain = 'glpi'): string
        {
            return $nb === 1 ? $one : $many;
        }
    }

    if (!class_exists('CommonDBTM')) {
        class CommonDBTM
        {
            /** @var array<string,mixed> */
            public array $fields = [];
        }
    }

    require __DIR__ . '/../src/Search.php';
    require __DIR__ . '/../src/Assistant/Skill.php';

    use GlpiPlugin\Glpiai\Assistant\Skill;
    use GlpiPlugin\Glpiai\Search;

    /** @var string[] $failures */
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

    /** @return array{key:string,name:string,text:string} */
    function entry(string $name, string $text): array
    {
        return ['key' => $name, 'name' => $name, 'text' => $text];
    }

    function skill(string $name, string $instructions, string $comment = '', string $triggers = ''): Skill
    {
        $skill         = new Skill();
        $skill->fields = [
            'name'         => $name,
            'instructions' => $instructions,
            'comment'      => $comment,
            'triggers'     => $triggers,
        ];

        return $skill;
    }

    echo "\nWords\n";

    check('a sentence of nothing but filler has no terms',
        Search::words('what are the tools you can use for this') === [],
        implode(',', Search::words('what are the tools you can use for this')));
    check('two-letter words go, three-letter words stay',
        Search::words('the vpn is down') === ['vpn', 'down'],
        implode(',', Search::words('the vpn is down')));
    check('a trailing plural is collapsed',
        Search::words('machines') === ['machine']);
    check('but not a double s',
        Search::words('access') === ['access']);
    check('and not a word too short to be sure about',
        Search::words('gas') === ['gas']);
    check('repeats are collapsed',
        Search::words('ticket ticket TICKET') === ['ticket']);

    echo "\nRanking\n";

    $corpus = [
        entry('read_ticket', 'Read one ticket and everything written on it'),
        entry('search_tickets', 'Find tickets matching a query'),
        entry('disk_space', 'Check how much room is left on a machine'),
        entry('port_status', 'Read the ports of a switch and their errors'),
    ];

    $ranked = Search::rank($corpus, 'check disk space on a machine', 8);
    check('the obvious query finds the obvious entry', ($ranked[0] ?? '') === 'disk_space',
        implode(',', array_map('strval', $ranked)));

    $ranked = Search::rank($corpus, 'read_ticket', 8);
    check('asking by name puts that entry first', ($ranked[0] ?? '') === 'read_ticket');

    // The name is worth double. Without that, an entry whose description merely
    // mentions the words beats the one the model asked for by name.
    $weighted = Search::rank([
        entry('ransomware', 'What to do when a call comes in about encrypted files'),
        entry('handover', 'Ransomware is covered elsewhere; this is about handover notes'),
    ], 'ransomware', 8);
    check('a name match outranks a passing mention', ($weighted[0] ?? '') === 'ransomware');

    check('nothing relevant returns nothing',
        Search::rank($corpus, 'payroll invoices', 8) === []);
    check('an empty query lists what there is',
        count(Search::rank($corpus, '', 8)) === 4);
    check('and the cap is honoured', count(Search::rank($corpus, '', 2)) === 2);
    check('a cap applies to a real search too',
        count(Search::rank($corpus, 'ticket machine switch', 1)) === 1);

    echo "\nCatalogue lines\n";

    check('the administrator\'s comment is the summary when there is one',
        skill('VPN', 'Long instructions here.', 'What to check before escalating a VPN fault')
            ->summary() === 'What to check before escalating a VPN fault');

    check('otherwise the first legible line of the instructions is',
        skill('VPN', "## When this applies\n\nA single user cannot connect.")->summary()
            === 'A single user cannot connect.');

    check('a heading is not the summary',
        !str_contains(skill('VPN', "# Ransomware\n\nDisconnect the machine first.")->summary(),
            'Ransomware'));

    check('a list marker is not part of it',
        skill('VPN', "- Disconnect the machine first.")->summary()
            === 'Disconnect the machine first.');

    check('whitespace is collapsed',
        skill('VPN', "Disconnect   the\tmachine.")->summary() === 'Disconnect the machine.');

    $long = skill('VPN', str_repeat('word ', 60));
    check('a long summary is cut to length and marked',
        mb_strlen($long->summary()) === 110 && str_ends_with($long->summary(), '…'),
        (string) mb_strlen($long->summary()));

    check('a skill with nothing to say says nothing', skill('VPN', '')->summary() === '');

    echo "\n" . ($failures === []
        ? "\033[32mall checks passed\033[0m\n"
        : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

    exit($failures === [] ? 0 : 1);
}
