<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Streaming: the three adapters that can, and the events the loop emits.
 *
 * The panel's whole claim is that a technician sees what is happening while it
 * happens. Two things have to be true for that, and neither is visible in a
 * finished answer — which is why they get a suite of their own.
 *
 * **The fragments have to be genuinely fragmented.** An adapter that buffers
 * the whole stream and calls the delta callback once produces an identical
 * `Completion` and an identical test result, while showing the technician
 * nothing until the end. So the mock chops its text mid-word, splits every
 * tool call's JSON arguments across frames, and the assertions here are on the
 * *number* of deltas as much as on their content.
 *
 * **The reassembly has to be exact.** A streamed answer and a whole one must
 * produce the same text, the same tool calls with the same arguments, the same
 * token counts and the same stop reason — because the agent loop cannot tell
 * which it got, and a subtly different one is a bug that only appears when
 * somebody is watching.
 *
 * The third thing asserted here is a rule rather than a mechanism: **thinking
 * never becomes the answer.** Both vendors that send reasoning send it as
 * something that looks exactly like text, and appending it would put the
 * model's private working into the reply, into the stored thread, and into the
 * next turn's prompt.
 *
 * No GLPI kernel: like tests/adapters.php this drives the adapters directly,
 * so it runs against the mock and nothing else.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/streaming.php
 */

require '/var/www/glpi/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, string $domain = 'glpi'): string
    {
        return $text;
    }
}

use GlpiPlugin\Glpiai\Completion;
use GlpiPlugin\Glpiai\Progress;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Provider\Anthropic;
use GlpiPlugin\Glpiai\Provider\AzureFoundry;
use GlpiPlugin\Glpiai\Provider\Gemini;
use GlpiPlugin\Glpiai\Provider\OpenAi;
use GlpiPlugin\Glpiai\Provider\StreamingProvider;
use GlpiPlugin\Glpiai\Tool;

$src = dirname(__DIR__) . '/src';
foreach (
    [
        'AiException', 'Azure/Entra', 'Message', 'Prompt', 'Usage', 'Completion', 'Progress',
        'Tool', 'ToolCall', 'ToolResult',
        'Provider/Field', 'Provider/Provider', 'Provider/StreamingProvider',
        'Provider/AbstractProvider', 'Provider/Anthropic', 'Provider/OpenAi',
        'Provider/Gemini', 'Provider/AzureFoundry',
    ] as $class
) {
    require_once $src . '/' . $class . '.php';
}

const MOCK = 'http://127.0.0.1:9099';

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

/** A prompt with one tool on it, so the tool-call path is exercised. */
function askingPrompt(): Prompt
{
    return Prompt::make('Any open printer tickets?')->withTools([
        new Tool(
            name: 'search_tickets',
            description: 'Search tickets.',
            schema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            handler: static fn(): array => []
        ),
    ]);
}

/**
 * Stream one prompt and hand back both the completion and what arrived on the
 * way.
 *
 * @return array{0:Completion,1:array<int,array{0:string,1:string}>}
 */
function streamed(StreamingProvider $provider, Prompt $prompt): array
{
    $deltas = [];

    $completion = $provider->stream($prompt, static function (string $kind, string $text) use (&$deltas): void {
        $deltas[] = [$kind, $text];
    });

    return [$completion, $deltas];
}

/** @param array<int,array{0:string,1:string}> $deltas */
function joined(array $deltas, string $kind): string
{
    return implode('', array_map(
        static fn(array $d): string => $d[1],
        array_filter($deltas, static fn(array $d): bool => $d[0] === $kind)
    ));
}

/** @param array<int,array{0:string,1:string}> $deltas */
function countOf(array $deltas, string $kind): int
{
    return count(array_filter($deltas, static fn(array $d): bool => $d[0] === $kind));
}

// --------------------------------------------------------------- the contract

echo "\nWho can stream\n";

check('the three that implement it do',
    (new Anthropic([])) instanceof StreamingProvider
    && (new OpenAi([])) instanceof StreamingProvider
    && (new Gemini([])) instanceof StreamingProvider);

// Azure gets it by inheriting OpenAI's chat-completions implementation, which
// is the whole reason that adapter is a subclass rather than a copy.
check('and Azure inherits it', (new AzureFoundry([])) instanceof StreamingProvider);

// ------------------------------------------------------------------ Anthropic

echo "\nAnthropic\n";

$claude = new Anthropic(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'claude-mock']);

[$completion, $deltas] = streamed($claude, Prompt::make('Say something.'));

check('the text arrives in pieces', countOf($deltas, 'text') > 1, (string) countOf($deltas, 'text'));
check('and reassembles into the completion', joined($deltas, 'text') === $completion->text,
    $completion->text);
check('the stop reason survives', $completion->finish_reason === 'end_turn',
    (string) $completion->finish_reason);
check('and so do the token counts',
    $completion->usage->input_tokens === 20 && $completion->usage->output_tokens === 3,
    $completion->usage->input_tokens . '/' . $completion->usage->output_tokens);

[$completion, $deltas] = streamed($claude, askingPrompt());

check('a tool call comes back whole', count($completion->tool_calls) === 1);
check('with its name', ($completion->tool_calls[0]->name ?? '') === 'search_tickets');
// The arguments were sent as three fragments of a JSON string. Reassembling
// them wrongly gives an empty array, which looks like a model that called a
// tool with no arguments rather than like a parser bug.
check('and the arguments the fragments spelled',
    ($completion->tool_calls[0]->arguments ?? []) === ['query' => 'printer', 'limit' => 5],
    json_encode($completion->tool_calls[0]->arguments ?? []));

$thinking = new Anthropic([
    'api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'claude-mock', 'thinking' => '1',
]);

// Above THINKING_FLOOR on purpose: the budget has to fit under max_tokens, so
// a short prompt does not get extended thinking at all. The assistant asks for
// 4,000, which is what this mirrors.
[$completion, $deltas] = streamed($thinking, Prompt::make('Say something.')->withMaxTokens(4000));

check('thinking arrives when it is asked for', countOf($deltas, 'thinking') > 0);
check('and is not asked for on a short ceiling', (function (Anthropic $p): bool {
    [, $deltas] = streamed($p, Prompt::make('Say something.')->withMaxTokens(1024));

    return countOf($deltas, 'thinking') === 0;
})($thinking));
check('and never lands in the answer',
    !str_contains($completion->text, 'Weighing'), $completion->text);
check('the answer is still the answer', joined($deltas, 'text') === $completion->text);

// --------------------------------------------------------------------- OpenAI

echo "\nOpenAI\n";

$openai = new OpenAi(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'gpt-mock']);

[$completion, $deltas] = streamed($openai, Prompt::make('Say something.'));

check('the text arrives in pieces', countOf($deltas, 'text') > 1, (string) countOf($deltas, 'text'));
check('and reassembles', joined($deltas, 'text') === $completion->text);
check('the finish reason survives', $completion->finish_reason === 'stop');
// The usage-only frame has no `choices` at all. A reader that assumes one
// crashes on the last frame of every streamed answer.
check('the usage-only final frame is read, not tripped over',
    $completion->usage->input_tokens === 42 && $completion->usage->output_tokens === 9,
    $completion->usage->input_tokens . '/' . $completion->usage->output_tokens);

[$completion, $deltas] = streamed($openai, askingPrompt());

check('a fragmented tool call is reassembled', count($completion->tool_calls) === 1);
check('with the id from the first fragment',
    ($completion->tool_calls[0]->id ?? '') === 'call_mock1');
check('and arguments joined across frames',
    ($completion->tool_calls[0]->arguments ?? []) === ['query' => 'printer', 'limit' => 5],
    json_encode($completion->tool_calls[0]->arguments ?? []));

// --------------------------------------------------------------------- Gemini

echo "\nGemini\n";

$gemini = new Gemini(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'gemini-mock']);

[$completion, $deltas] = streamed($gemini, Prompt::make('Say something.'));

check('the text arrives in pieces', countOf($deltas, 'text') > 1, (string) countOf($deltas, 'text'));
check('and reassembles', joined($deltas, 'text') === $completion->text);
check('the usage metadata survives',
    $completion->usage->input_tokens === 31 && $completion->usage->output_tokens === 7);

[$completion, $deltas] = streamed($gemini, askingPrompt());

check('a function call survives the stream', count($completion->tool_calls) === 1);
check('with its arguments',
    ($completion->tool_calls[0]->arguments ?? []) === ['query' => 'printer', 'limit' => 5]);
// The signature is opaque and has to be replayed on the next turn, so losing
// it in the streaming path would degrade reasoning on every multi-turn
// conversation and never fail outright.
check('and its thought signature, which has to be replayed',
    ($completion->tool_calls[0]->vendor['thoughtSignature'] ?? '') === 'mock-signature',
    json_encode($completion->tool_calls[0]->vendor ?? []));

$thinkingGemini = new Gemini([
    'api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'gemini-mock', 'thinking' => '1',
]);

[$completion, $deltas] = streamed($thinkingGemini, Prompt::make('Say something.'));

check('thought parts are reported', countOf($deltas, 'thinking') > 0);
check('and are not the answer',
    !str_contains($completion->text, 'Considering'), $completion->text);

// -------------------------------------------------------------------- Progress

echo "\nThe event channel\n";

$seen = [];
$sink = static function (string $type, array $data) use (&$seen): void {
    $seen[] = [$type, $data];
};

check('nothing is watching by default', !Progress::watched());

$returned = Progress::watch($sink, static function (): string {
    Progress::emit(Progress::TURN, ['turn' => 1]);

    return 'answer';
});

check('the body\'s return value comes back', $returned === 'answer');
check('and its events were seen', count($seen) === 1 && $seen[0][0] === Progress::TURN);
check('the sink is put back afterwards', !Progress::watched());

$seen = [];
Progress::emit(Progress::TURN, ['turn' => 99]);
check('an event outside a watch goes nowhere', $seen === []);

// A nested watch is what a feature calling another feature would produce.
$outer = [];
$inner = [];
Progress::watch(
    static function (string $t, array $d) use (&$outer): void { $outer[] = $t; },
    static function () use (&$inner): void {
        Progress::emit('a');
        Progress::watch(
            static function (string $t, array $d) use (&$inner): void { $inner[] = $t; },
            static function (): void { Progress::emit('b'); }
        );
        Progress::emit('c');
    }
);
check('a nested watch takes over and hands back', $outer === ['a', 'c'] && $inner === ['b'],
    json_encode([$outer, $inner]));

// A browser that closed mid-run makes the sink throw. The run must survive it,
// and must not keep writing into a socket that is gone.
$writes = 0;
$result = Progress::watch(
    static function () use (&$writes): void {
        $writes++;
        throw new RuntimeException('the browser went away');
    },
    static function (): string {
        Progress::emit('a');
        Progress::emit('b');
        Progress::emit('c');

        return 'finished anyway';
    }
);
check('a sink that throws does not take the run with it', $result === 'finished anyway');
check('and is not written to again', $writes === 1, (string) $writes);

// A watch whose body throws must still restore, or the next request writes
// into the last one's response.
try {
    Progress::watch($sink, static function (): void {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
    // expected
}
check('an exception in the body still restores the sink', !Progress::watched());

echo "\n";
if ($failures === []) {
    echo "\033[32mAll checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($failures) . " failed.\033[0m\n";
exit(1);
