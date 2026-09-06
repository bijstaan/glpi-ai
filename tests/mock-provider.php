<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * A stand-in for the four vendor APIs, for exercising the adapters without
 * credentials or network egress.
 *
 * It records what it was sent and replies in the shape the real vendor would,
 * which is what lets the adapter tests assert on the *request* — the part this
 * plugin is actually responsible for. Asserting on responses would only test
 * this file.
 *
 * Two path prefixes select the unhappy paths, since those are the ones that
 * arrive as a successful HTTP response and are therefore easy to mishandle:
 *
 *   /refuse/...  the vendor's "200 OK, but no" shape
 *   /deny/...    Entra rejecting a service principal
 *
 * Run inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php
 */

$log_file = sys_get_temp_dir() . '/glpiai-mock.jsonl';
$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';
$body     = file_get_contents('php://input');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

file_put_contents(
    $log_file,
    json_encode([
        'path'    => $path,
        'query'   => $query,
        'headers' => $headers,
        // Decoded when it is JSON; left raw for the form-encoded token request.
        'body'    => json_decode($body, true) ?? $body,
        // Also verbatim, because decoding destroys distinctions the vendors
        // care about: `{}` and `[]` are both an empty PHP array, and a null
        // field and an absent one are indistinguishable once decoded.
        'raw'     => $body,
    ]) . "\n",
    FILE_APPEND
);



/**
 * The same answers, delivered as server-sent events.
 *
 * A mock that only ever replied in one piece would let a streaming adapter
 * pass every test while being incapable of streaming: reassembly, fragmented
 * tool-call arguments and the rule that thinking never reaches the answer are
 * all invisible unless the frames genuinely arrive separately. So the text is
 * chopped mid-word on purpose, the JSON arguments are split across frames, and
 * a thinking frame is sent whenever the request asked for one.
 */
function mock_sse(array $frames): never
{
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    foreach ($frames as $frame) {
        if (is_array($frame) && isset($frame['__event'])) {
            echo 'event: ' . $frame['__event'] . "\n";
            unset($frame['__event']);
        }

        echo 'data: ' . (is_string($frame) ? $frame : json_encode($frame)) . "\n\n";
        flush();
    }

    exit;
}

/** Split a string into pieces that do not respect word boundaries. */
function mock_chop(string $text, int $pieces = 4): array
{
    $length = max(1, (int) ceil(mb_strlen($text) / $pieces));
    $out    = [];

    for ($at = 0; $at < mb_strlen($text); $at += $length) {
        $out[] = mb_substr($text, $at, $length);
    }

    return $out === [] ? [''] : $out;
}

/**
 * A draft answer, derived from the evidence that was actually sent.
 *
 * Same discipline as mock_triage(): the interesting failure in drafting is an
 * evidence-assembly failure — followups that never reached the prompt, a
 * procedure's answers silently dropped, private notes not marked — and every
 * one of those produces a well-formed request that a canned paragraph would
 * answer beautifully. So the "draft" is a census of what arrived, which a test
 * can assert on exactly.
 *
 * One marker drives the unhappy path:
 *   MOCKEMPTY   answer with an empty draft
 */
function mock_draft(string $system, string $user, string $kind): array
{
    $counts = [
        'notes'      => preg_match_all('/^\s*\[NOTE/m', $user),
        'internal'   => preg_match_all('/INTERNAL/', $user),
        'tasks'      => preg_match_all('/^\s*\[TASK/m', $user),
        'checks'     => preg_match_all('/^\s{4}- /m', $user),
        'precedents' => preg_match_all('/^\s*resolved by: /m', $user),
    ];

    $census = sprintf(
        'Evidence seen: %d notes (%d internal), %d tasks, %d procedure checks, %d precedents.',
        $counts['notes'],
        $counts['internal'],
        $counts['tasks'],
        $counts['checks'],
        $counts['precedents']
    );

    $empty = str_contains($user, 'MOCKEMPTY');

    if ($kind === 'article') {
        return [
            'title'      => $empty ? '' : 'Symptom seen in the evidence',
            'answer'     => $empty ? '' : $census . "\n\nGeneralised from the ticket.",
            'confidence' => $counts['checks'] > 0 ? 'high' : 'medium',
        ];
    }

    return [
        'solution'   => $empty ? '' : $census . "\n\nWritten up for this ticket.",
        // Echoing whether the instruction arrived, so a system prompt that goes
        // missing is a failed assertion rather than a plausible paragraph.
        'gaps'       => str_contains($system, 'write only what the evidence supports')
            ? 'The instruction arrived.'
            : 'NO INSTRUCTION',
        'confidence' => $counts['precedents'] > 0 ? 'high' : 'low',
    ];
}

/**
 * Reviewing a reply, answered from what actually arrived.
 *
 * Same discipline again, and here the assembly failure it is built to catch is
 * a specific one: the internal notes not reaching the prompt. A reviewer that
 * cannot see what was internal cannot tell that a phrase was carried across —
 * and it would still return a perfectly well-formed, entirely plausible list
 * of flags about tone and jargon, which is exactly the answer a canned
 * response would give.
 *
 * So the mock compares: any long word that appears both in an INTERNAL note
 * and in the reply is flagged as carried across, quoting the word as it
 * appears in the reply. If the notes never arrived there is nothing to
 * compare, no internal flag comes back, and the suite fails.
 *
 * The second flag is deterministic: a reply that never says what happens next
 * gets a next_step, which is the one kind allowed to carry no quote.
 *
 * @return array{flags:array<int,array<string,string>>}
 */
function mock_review(string $system, string $user): array
{
    $flags = [];

    $split = explode('THE REPLY, not yet sent:', $user, 2);
    $context = $split[0] ?? '';
    $reply   = trim($split[1] ?? '');

    $internal = '';
    if (preg_match('/INTERNAL notes on this ticket.*$/s', $context, $m) === 1) {
        $internal = $m[0];
    }

    $words = static function (string $text): array {
        preg_match_all('/[A-Za-z]{7,}/', $text, $found);

        return array_unique(array_map('strtolower', $found[0]));
    };

    $shared = array_values(array_intersect($words($internal), $words($reply)));
    if ($shared !== []) {
        // Quoted as it appears in the reply, because the plugin discards a
        // flag whose quote it cannot find there — which is a rule worth
        // exercising rather than working around.
        foreach ($shared as $word) {
            if (preg_match('/[A-Za-z]*' . preg_quote($word, '/') . '[A-Za-z]*/i', $reply, $m) === 1) {
                $flags[] = [
                    'kind'  => 'internal',
                    'quote' => $m[0],
                    'why'   => 'That word appears in an internal note on this ticket.',
                ];
                break;
            }
        }
    }

    if (!preg_match('/\b(will|tomorrow|next|shortly|once)\b/i', $reply)) {
        $flags[] = [
            'kind'  => 'next_step',
            'quote' => '',
            // Echoing the instruction back, so a system prompt that never
            // arrived is a failed assertion rather than a plausible flag.
            'why'   => str_contains($system, 'You are not writing this reply')
                ? 'It does not say what happens now.'
                : 'NO INSTRUCTION',
        ];
    }

    return ['flags' => $flags];
}

/**
 * The system instruction, wherever this vendor puts it.
 *
 * Three places, because the three vendors genuinely disagree: Anthropic takes a
 * top-level string, OpenAI a message with a system role, Gemini a separate
 * systemInstruction object. Reading all three is what lets one mock stand in
 * for all of them — and a plugin that assembled the prompt correctly for only
 * two of the three would be caught here rather than in production.
 */
function mock_system(array $body): string
{
    if (isset($body['system'])) {
        return is_array($body['system'])
            ? implode("\n", array_column($body['system'], 'text'))
            : (string) $body['system'];
    }

    if (isset($body['systemInstruction']['parts'])) {
        return implode("\n", array_column($body['systemInstruction']['parts'], 'text'));
    }

    foreach ($body['messages'] ?? [] as $message) {
        if (in_array($message['role'] ?? '', ['system', 'developer'], true)) {
            return (string) ($message['content'] ?? '');
        }
    }

    return '';
}

/** The last user turn, wherever this vendor puts it. */
function mock_user(array $body): string
{
    foreach (array_reverse($body['contents'] ?? []) as $content) {
        if (($content['role'] ?? '') === 'user') {
            return implode("\n", array_column($content['parts'] ?? [], 'text'));
        }
    }

    foreach (array_reverse($body['messages'] ?? []) as $message) {
        if (($message['role'] ?? '') !== 'user') {
            continue;
        }

        $content = $message['content'] ?? '';

        return is_array($content)
            ? implode("\n", array_column($content, 'text'))
            : (string) $content;
    }

    return '';
}

/**
 * A triage answer, derived from the prompt that was actually sent.
 *
 * Deliberately not a canned object. The interesting failure in triage is an
 * assembly failure — the taxonomy not reaching the system instruction, the
 * ticket text not reaching the user turn, the schema not being declared — and
 * every one of those produces a well-formed request that a canned responder
 * would answer perfectly. So this parses the categories back out of the system
 * text and picks one by word overlap with the ticket. It can only be right if
 * the prompt was assembled correctly, which is the whole point.
 *
 * Two markers in the ticket text drive the unhappy paths:
 *   MOCKBADCAT  answer with a category id that was never offered
 *   MOCKNOCAT   answer with 0, meaning "nothing here fits"
 */
function mock_triage(string $system, string $user): array
{
    $categories = [];
    foreach (explode("\n", $system) as $line) {
        if (preg_match('/^\s{2}(\d+)\.\s+(.+?)(?:\s+—\s+.*)?$/u', $line, $m)) {
            $categories[(int) $m[1]] = $m[2];
        }
    }

    // The procedure list follows the same shape, so it is separated by where it
    // starts rather than by pattern.
    $sops = [];
    $in_sops = false;
    foreach (explode("\n", $system) as $line) {
        if (str_contains($line, 'documented way to handle')) {
            $in_sops = true;
            continue;
        }
        if ($in_sops && preg_match('/^\s{2}(\d+)\.\s+(.+)$/u', $line, $m)) {
            $sops[(int) $m[1]] = $m[2];
            unset($categories[(int) $m[1]]);
        } elseif ($in_sops && trim($line) === '') {
            $in_sops = false;
        }
    }

    $words = static fn(string $text): array => array_filter(
        preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [],
        static fn(string $w): bool => strlen($w) > 2
    );

    $ticket = $words($user);

    $pick = static function (array $haystack) use ($words, $ticket): int {
        $best  = 0;
        $score = 0;
        foreach ($haystack as $id => $name) {
            $overlap = count(array_intersect($words($name), $ticket));
            if ($overlap > $score) {
                $score = $overlap;
                $best  = $id;
            }
        }

        return $best;
    };

    $category = $pick($categories);

    if (str_contains($user, 'MOCKBADCAT')) {
        $category = 999999;
    } elseif (str_contains($user, 'MOCKNOCAT')) {
        $category = 0;
    }

    $lower = strtolower($user);
    $wide  = str_contains($lower, 'everyone') || str_contains($lower, 'whole office')
          || str_contains($lower, 'all users');
    $now   = str_contains($lower, 'urgent') || str_contains($lower, 'asap')
          || str_contains($lower, 'cannot work');

    return [
        'itilcategories_id' => $category,
        'urgency'           => $now ? 4 : 2,
        'impact'            => $wide ? 4 : 2,
        'sops_id'           => $pick($sops),
        'confidence'        => $category > 0 ? 'high' : 'low',
        'reasoning'         => $category > 0
            ? 'Matched on the words in the ticket.'
            : 'Nothing in the list fits what this ticket describes.',
    ];
}

header('Content-Type: application/json');

$refuse = str_starts_with($path, '/refuse');

// Whether to answer with tool calls is decided by what was asked for: a request
// that declares tools and has not yet been given any results gets a call back.
// That is enough to drive a real agent loop against this file — the second turn
// carries results, so the mock answers with prose and the loop terminates.
$decoded  = is_array($body_decoded = json_decode($body, true)) ? $body_decoded : [];
$declared = isset($decoded['tools']) && $decoded['tools'] !== [];

/**
 * The answer that ran out of room, and the one that finishes it.
 *
 * `MOCKCUT` in the question makes the first answer stop mid-sentence with the
 * vendor's own "hit the ceiling" finish reason — the shape that used to reach
 * a technician as a complete-looking answer with its last third missing. The
 * continuation is recognised by the instruction the loop sends back, so the
 * second call answers normally and the two halves can be checked for having
 * been joined without a seam.
 */
$whole_body   = json_encode($decoded);
$continuing   = str_contains($whole_body, 'Continue from exactly where you stopped');
$cut_short    = str_contains($whole_body, 'MOCKCUT') && !$continuing;

if ($continuing) {
    $answer_text_override = 'and this is the rest of it.';
}
$answered = str_contains($body, 'tool_result')
    || str_contains($body, '"role":"tool"')
    || str_contains($body, 'functionResponse');

// /insatiable never takes yes for an answer: it keeps asking for tools however
// many results it is given, which is what a real model stuck in a loop does and
// is the only way to exercise the turn budget.
$call_tools = $declared && (!$answered || str_starts_with($path, '/insatiable')) && !$refuse
    && !$cut_short && !$continuing;

// A request that declared a schema gets an object that satisfies it, not the
// generic {"ok":true}. Only triage's schema is recognised; anything else keeps
// the old behaviour, so the existing suites are unaffected.
$wants_triage = str_contains($body, 'itilcategories_id');
// "next_step" appears only in the reply-review schema's kind enum.
$wants_review = str_contains($body, 'next_step');
$wants_draft  = str_contains($body, '"gaps"') ? 'solution'
    : (str_contains($body, '"answer"') ? 'article' : '');

if ($wants_triage) {
    $answer_text = json_encode(mock_triage(mock_system($decoded), mock_user($decoded)));
} elseif ($wants_review) {
    $answer_text = json_encode(mock_review(mock_system($decoded), mock_user($decoded)));
} elseif ($wants_draft !== '') {
    $answer_text = json_encode(mock_draft(mock_system($decoded), mock_user($decoded), $wants_draft));
} else {
    $answer_text = '{"ok":true}';
}

if (isset($answer_text_override)) {
    $answer_text = $answer_text_override;
} elseif ($cut_short) {
    $answer_text = 'This answer begins and then ';
}

// -------------------------------------------------- Microsoft Entra (token)

if (str_contains($path, '/oauth2/v2.0/token')) {
    if (str_starts_with($path, '/deny')) {
        http_response_code(401);
        echo json_encode([
            'error'             => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided.',
        ]);
        exit;
    }

    echo json_encode(['access_token' => 'mock-entra-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    exit;
}

// --------------------------------------------------------- Anthropic Messages

if (str_contains($path, '/v1/messages')) {
    if (($decoded['stream'] ?? false) === true) {
        $frames = [
            ['__event' => 'message_start', 'type' => 'message_start',
             'message' => ['model' => 'claude-mock', 'usage' => ['input_tokens' => 20, 'output_tokens' => 0]]],
        ];

        // Extended thinking, when the request enabled it. Its own block, and
        // the suite asserts that none of it reaches the answer.
        if (isset($decoded['thinking'])) {
            $frames[] = ['__event' => 'content_block_start', 'type' => 'content_block_start',
                'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']];
            foreach (mock_chop('Weighing the printer options.', 2) as $piece) {
                $frames[] = ['__event' => 'content_block_delta', 'type' => 'content_block_delta',
                    'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => $piece]];
            }
            $frames[] = ['__event' => 'content_block_stop', 'type' => 'content_block_stop', 'index' => 0];
        }

        $index = isset($decoded['thinking']) ? 1 : 0;

        if ($call_tools) {
            $frames[] = ['__event' => 'content_block_start', 'type' => 'content_block_start',
                'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
            foreach (mock_chop('Let me look that up.') as $piece) {
                $frames[] = ['__event' => 'content_block_delta', 'type' => 'content_block_delta',
                    'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $piece]];
            }
            $frames[] = ['__event' => 'content_block_stop', 'type' => 'content_block_stop', 'index' => $index];

            $frames[] = ['__event' => 'content_block_start', 'type' => 'content_block_start',
                'index' => $index + 1,
                'content_block' => ['type' => 'tool_use', 'id' => 'toolu_mock1', 'name' => 'search_tickets']];
            foreach (mock_chop('{"query":"printer","limit":5}', 3) as $piece) {
                $frames[] = ['__event' => 'content_block_delta', 'type' => 'content_block_delta',
                    'index' => $index + 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $piece]];
            }
            $frames[] = ['__event' => 'content_block_stop', 'type' => 'content_block_stop', 'index' => $index + 1];

            $frames[] = ['__event' => 'message_delta', 'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 8]];
        } else {
            $frames[] = ['__event' => 'content_block_start', 'type' => 'content_block_start',
                'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
            foreach (mock_chop($answer_text) as $piece) {
                $frames[] = ['__event' => 'content_block_delta', 'type' => 'content_block_delta',
                    'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $piece]];
            }
            $frames[] = ['__event' => 'content_block_stop', 'type' => 'content_block_stop', 'index' => $index];
            $frames[] = ['__event' => 'message_delta', 'type' => 'message_delta',
                'delta' => ['stop_reason' => $cut_short ? 'max_tokens' : 'end_turn'],
                'usage' => ['output_tokens' => 3]];
        }

        mock_sse($frames);
    }

    if ($refuse) {
        echo json_encode(['model' => 'claude-mock', 'stop_reason' => 'refusal', 'content' => []]);
        exit;
    }

    echo json_encode($call_tools
        ? [
            'model'       => 'claude-mock',
            'stop_reason' => 'tool_use',
            'content'     => [
                ['type' => 'text', 'text' => 'Let me look that up.'],
                [
                    'type'  => 'tool_use',
                    'id'    => 'toolu_mock1',
                    'name'  => 'search_tickets',
                    'input' => ['query' => 'printer', 'limit' => 5],
                ],
            ],
            'usage'       => ['input_tokens' => 20, 'output_tokens' => 8],
        ]
        : [
            'model'       => 'claude-mock',
            'stop_reason' => $cut_short ? 'max_tokens' : 'end_turn',
            'content'     => [['type' => 'text', 'text' => $answer_text]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 3],
        ]);
    exit;
}

// ------------------------------------------------------ Gemini generateContent

if (str_contains($path, ':streamGenerateContent')) {
    $frames = [];

    // Thought parts, when the request asked for them. Flagged `thought`, which
    // is the only thing distinguishing them from the answer.
    if (($decoded['generationConfig']['thinkingConfig']['includeThoughts'] ?? false) === true) {
        foreach (mock_chop('Considering the printer queue.', 2) as $piece) {
            $frames[] = ['candidates' => [['content' => ['parts' => [
                ['text' => $piece, 'thought' => true],
            ]]]]];
        }
    }

    if ($call_tools) {
        foreach (mock_chop('Looking that up.') as $piece) {
            $frames[] = ['candidates' => [['content' => ['parts' => [['text' => $piece]]]]]];
        }
        $frames[] = ['candidates' => [['content' => ['parts' => [[
            'functionCall'     => ['name' => 'search_tickets', 'args' => ['query' => 'printer', 'limit' => 5]],
            'thoughtSignature' => 'mock-signature',
        ]]], 'finishReason' => 'STOP']]];
    } else {
        foreach (mock_chop($answer_text) as $piece) {
            $frames[] = ['candidates' => [['content' => ['parts' => [['text' => $piece]]]]]];
        }
        $frames[] = ['candidates' => [[
            'finishReason' => $cut_short ? 'MAX_TOKENS' : 'STOP',
            'content'      => ['parts' => []],
        ]]];
    }

    $frames[] = [
        'modelVersion'  => 'gemini-mock',
        'usageMetadata' => ['promptTokenCount' => 31, 'candidatesTokenCount' => 7],
        'candidates'    => [['content' => ['parts' => []]]],
    ];

    mock_sse($frames);
}

if (str_contains($path, ':generateContent')) {
    if ($refuse) {
        echo json_encode(['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []]);
        exit;
    }

    echo json_encode($call_tools
        ? [
            'modelVersion'  => 'gemini-mock',
            'candidates'    => [[
                // Note the ordinary STOP: Gemini reports no distinct stop reason
                // for a function call, which is why the neutral layer keys off
                // the calls themselves.
                'finishReason' => 'STOP',
                'content'      => ['parts' => [
                    ['text' => 'Looking that up.'],
                    [
                        'functionCall' => [
                            'name' => 'search_tickets',
                            'args' => ['query' => 'printer', 'limit' => 5],
                        ],
                        // What a reasoning model attaches to a function call.
                        // The API requires it back verbatim on the replayed
                        // turn, and complains — rather than failing — when it
                        // is missing, so a mock that never sent one would let
                        // an adapter that drops it pass every test it has.
                        'thoughtSignature' => 'sig-abc123',
                    ],
                ]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 21, 'candidatesTokenCount' => 9],
        ]
        : [
            'modelVersion'  => 'gemini-mock',
            'candidates'    => [[
                'finishReason' => 'STOP',
                'content'      => ['parts' => [['text' => $answer_text]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 4],
        ]);
    exit;
}

// ------------------------------------------------ OpenAI / Azure chat completions

if (($decoded['stream'] ?? false) === true) {
    $frames = [];

    if ($call_tools) {
        foreach (mock_chop('Let me check.') as $piece) {
            $frames[] = ['model' => 'gpt-mock', 'choices' => [['index' => 0, 'delta' => ['content' => $piece]]]];
        }

        // The name in the first fragment, the arguments dribbled out after it,
        // correlated only by `index` — which is the part an adapter gets wrong.
        $frames[] = ['model' => 'gpt-mock', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
            'index' => 0, 'id' => 'call_mock1', 'type' => 'function',
            'function' => ['name' => 'search_tickets', 'arguments' => ''],
        ]]]]]];
        foreach (mock_chop('{"query":"printer","limit":5}', 3) as $piece) {
            $frames[] = ['model' => 'gpt-mock', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'function' => ['arguments' => $piece],
            ]]]]]];
        }
        $frames[] = ['model' => 'gpt-mock', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]];
    } else {
        foreach (mock_chop($answer_text) as $piece) {
            $frames[] = ['model' => 'gpt-mock', 'choices' => [['index' => 0, 'delta' => ['content' => $piece]]]];
        }
        $frames[] = ['model' => 'gpt-mock', 'choices' => [[
            'index' => 0, 'delta' => [], 'finish_reason' => $cut_short ? 'length' : 'stop',
        ]]];
    }

    // The usage-only frame OpenAI sends last when stream_options asked for it,
    // with no choices at all — the shape that makes a naive reader crash.
    $frames[] = ['model' => 'gpt-mock', 'choices' => [],
        'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 9]];
    $frames[] = '[DONE]';

    mock_sse($frames);
}

if ($refuse) {
    echo json_encode([
        'model'   => 'gpt-mock',
        'choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => '']]],
    ]);
    exit;
}

echo json_encode($call_tools
    ? [
        'model'   => 'gpt-mock',
        'choices' => [[
            'finish_reason' => 'tool_calls',
            'message'       => [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [
                    [
                        'id'       => 'call_mock1',
                        'type'     => 'function',
                        'function' => [
                            'name' => 'search_tickets',
                            // A string, as the API really returns it.
                            'arguments' => '{"query":"printer","limit":5}',
                        ],
                    ],
                    [
                        'id'       => 'call_mock2',
                        'type'     => 'function',
                        'function' => ['name' => 'whoami', 'arguments' => '{}'],
                    ],
                ],
            ],
        ]],
        'usage'   => ['prompt_tokens' => 22, 'completion_tokens' => 10],
    ]
    : [
        'model'   => 'gpt-mock',
        'choices' => [[
            'finish_reason' => $cut_short ? 'length' : 'stop',
            'message'       => ['role' => 'assistant', 'content' => $answer_text],
        ]],
        'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2],
    ]);
