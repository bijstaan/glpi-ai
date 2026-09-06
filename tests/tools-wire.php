<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Tool calling, at the wire.
 *
 * The same discipline as adapters.php and for the same reason, but the surface
 * is larger and the failure is quieter. A tool round trip is three shapes, not
 * one — the declaration going out, the call coming back, and the result going
 * out again — and the vendors disagree on all three. OpenAI passes arguments as
 * a JSON *string* inside an object; Anthropic passes them as an object; Gemini
 * has no call ids at all and matches by name. Getting any of those subtly wrong
 * produces a model that simply stops calling tools, with no error anywhere.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/tools-wire.php
 */

namespace {
    require '/var/www/glpi/vendor/autoload.php';

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string
        {
            return $text;
        }
    }
}

namespace Glpi\Cache {
    class CacheManager
    {
        /** @var array<string,string> */
        public static array $store = [];

        public function getCacheInstance(string $context): object
        {
            return new class {
                public function get(string $key)
                {
                    return CacheManager::$store[$key] ?? null;
                }

                public function set(string $key, $value, ?int $ttl = null): bool
                {
                    CacheManager::$store[$key] = $value;

                    return true;
                }

                public function clear(): bool
                {
                    CacheManager::$store = [];

                    return true;
                }
            };
        }
    }
}

namespace {

    use GlpiPlugin\Glpiai\Message;
    use GlpiPlugin\Glpiai\Prompt;
    use GlpiPlugin\Glpiai\Provider\Anthropic;
    use GlpiPlugin\Glpiai\Provider\AzureFoundry;
    use GlpiPlugin\Glpiai\Provider\Gemini;
    use GlpiPlugin\Glpiai\Provider\OpenAi;
    use GlpiPlugin\Glpiai\Tool;
    use GlpiPlugin\Glpiai\ToolCall;
    use GlpiPlugin\Glpiai\ToolResult;

    $src = dirname(__DIR__) . '/src';
    foreach (
        [
            'AiException', 'Azure/Entra', 'Message', 'Prompt', 'Usage', 'Completion',
            'Tool', 'ToolCall', 'ToolResult',
            'Provider/Field', 'Provider/Provider', 'Provider/StreamingProvider', 'Provider/AbstractProvider',
            'Provider/Anthropic', 'Provider/OpenAi', 'Provider/Gemini', 'Provider/AzureFoundry',
        ] as $class
    ) {
        require $src . '/' . $class . '.php';
    }

    const MOCK    = 'http://127.0.0.1:9099';
    const LOGFILE = '/tmp/glpiai-mock.jsonl';

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

    function requests(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(LOGFILE))));

        return array_map(static fn(string $l): array => json_decode($l, true) ?: [], $lines);
    }

    function lastRequest(): array
    {
        $all = requests();

        return $all === [] ? [] : end($all);
    }

    function reset_log(): void
    {
        file_put_contents(LOGFILE, '');
    }

    /** Two tools: one with required and optional arguments, one with none at all. */
    function tools(): array
    {
        return [
            new Tool(
                'search_tickets',
                'Find tickets matching a free-text query.',
                [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'what to look for'],
                        'limit' => ['type' => 'integer'],
                    ],
                    'required'   => ['query'],
                    // Present deliberately: OpenAI tolerates it, Gemini rejects it.
                    'additionalProperties' => false,
                ]
            ),
            new Tool('whoami', 'Return the signed-in user.'),
        ];
    }

    /**
     * A prompt mid-conversation: the model asked for two tools at once and both
     * answered, one of them with an error.
     *
     * Two concurrent calls is the case worth carrying through every adapter —
     * it is where the id-versus-name correlation actually bites.
     */
    function midConversation(): Prompt
    {
        $prompt = Prompt::make('Any open printer tickets for Accounts?', 'You triage tickets.');

        // The first call carries vendor data, the second does not — so the
        // replay is checked both for carrying a signature through and for not
        // inventing one where there was none.
        $one = new ToolCall(
            'call_a',
            'search_tickets',
            ['query' => 'printer', 'limit' => 5],
            ['thoughtSignature' => 'sig-replayed']
        );
        $two = new ToolCall('call_b', 'whoami', []);

        $prompt->add(Message::toolCalls('Let me look.', [$one, $two]));
        $prompt->add(Message::toolResults([
            ToolResult::of($one, ['count' => 2, 'ids' => [11, 12]]),
            ToolResult::error($two, 'Permission denied.'),
        ]));

        return $prompt->withTools(tools());
    }

    // ---------------------------------------------------------------- Anthropic

    echo "\nAnthropic — declaring tools\n";
    reset_log();
    $anthropic = new Anthropic(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'claude']);
    $completion = $anthropic->complete(Prompt::make('Find printer tickets.')->withTools(tools()));
    $req = lastRequest();

    check('declares tools with input_schema',
        ($req['body']['tools'][0]['name'] ?? '') === 'search_tickets'
        && isset($req['body']['tools'][0]['input_schema']['properties']['query']));
    check('passes the argument schema through untouched',
        ($req['body']['tools'][0]['input_schema']['additionalProperties'] ?? null) === false
        && ($req['body']['tools'][0]['input_schema']['required'] ?? []) === ['query']);
    check('declares a tool that takes no arguments', ($req['body']['tools'][1]['name'] ?? '') === 'whoami');
    check('defaults tool_choice to auto', ($req['body']['tool_choice'] ?? []) === ['type' => 'auto']);

    echo "\nAnthropic — reading calls back\n";
    check('surfaces the tool call', $completion->wantsTools() && count($completion->tool_calls) === 1);
    check('keeps the vendor call id', ($completion->tool_calls[0]->id ?? '') === 'toolu_mock1');
    check('decodes the arguments as an object',
        ($completion->tool_calls[0]->arguments['query'] ?? '') === 'printer');
    check('still surfaces the text alongside', str_contains($completion->text, 'Let me look'));

    echo "\nAnthropic — replaying the round trip\n";
    reset_log();
    $anthropic->complete(midConversation());
    $req = lastRequest();
    $turns = $req['body']['messages'] ?? [];

    check('the assistant turn becomes content blocks', is_array($turns[1]['content'] ?? null));
    check('text and tool_use travel in one assistant turn',
        ($turns[1]['content'][0]['type'] ?? '') === 'text'
        && ($turns[1]['content'][1]['type'] ?? '') === 'tool_use');
    check('both concurrent calls survive', count($turns[1]['content'] ?? []) === 3);
    // Asserted against the raw body: `{}` and `[]` both decode to an empty PHP
    // array, and Anthropic rejects the list form.
    check('an empty argument object is sent as {} and not []',
        str_contains((string) ($req['raw'] ?? ''), '"name":"whoami","input":{}'),
        (string) ($req['raw'] ?? ''));
    check('results come back on a single user turn',
        ($turns[2]['role'] ?? '') === 'user' && count($turns[2]['content'] ?? []) === 2);
    check('each result is correlated by tool_use_id',
        ($turns[2]['content'][0]['tool_use_id'] ?? '') === 'call_a'
        && ($turns[2]['content'][1]['tool_use_id'] ?? '') === 'call_b');
    check('a failed tool is flagged rather than silently returned',
        ($turns[2]['content'][1]['is_error'] ?? null) === true
        && !array_key_exists('is_error', $turns[2]['content'][0]));

    echo "\nAnthropic — tool_choice\n";
    foreach (
        [
            [Prompt::TOOL_NONE, ['type' => 'none']],
            [Prompt::TOOL_REQUIRED, ['type' => 'any']],
            ['whoami', ['type' => 'tool', 'name' => 'whoami']],
        ] as [$choice, $expected]
    ) {
        reset_log();
        $anthropic->complete(Prompt::make('x')->withTools(tools(), $choice));
        check("maps '$choice'", (lastRequest()['body']['tool_choice'] ?? []) === $expected,
            json_encode(lastRequest()['body']['tool_choice'] ?? null));
    }

    // ------------------------------------------------------------------- OpenAI

    echo "\nOpenAI — declaring tools\n";
    reset_log();
    $openai     = new OpenAi(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'gpt']);
    $completion = $openai->complete(Prompt::make('Find printer tickets.')->withTools(tools()));
    $req = lastRequest();

    check('wraps each tool in a function envelope',
        ($req['body']['tools'][0]['type'] ?? '') === 'function'
        && ($req['body']['tools'][0]['function']['name'] ?? '') === 'search_tickets');
    check('calls the argument schema "parameters"',
        isset($req['body']['tools'][0]['function']['parameters']['properties']['query']));
    check('does not request strict mode for tool arguments',
        !array_key_exists('strict', $req['body']['tools'][0]['function'] ?? []));
    check('sends tool_choice as a bare string', ($req['body']['tool_choice'] ?? null) === 'auto');

    echo "\nOpenAI — reading calls back\n";
    check('surfaces both concurrent calls', count($completion->tool_calls) === 2);
    check('decodes the JSON-string arguments into an array',
        ($completion->tool_calls[0]->arguments['query'] ?? '') === 'printer'
        && ($completion->tool_calls[0]->arguments['limit'] ?? null) === 5);
    check('an argument-less call decodes to an empty array', $completion->tool_calls[1]->arguments === []);
    check('keeps the vendor call ids', $completion->tool_calls[1]->id === 'call_mock2');

    echo "\nOpenAI — replaying the round trip\n";
    reset_log();
    $openai->complete(midConversation());
    $turns = lastRequest()['body']['messages'] ?? [];

    check('the assistant turn carries tool_calls', isset($turns[2]['tool_calls']));
    check('text survives alongside tool calls', ($turns[2]['content'] ?? '') === 'Let me look.');
    reset_log();
    $openai->complete(
        Prompt::make('x')->withTools(tools())
            ->add(Message::toolCalls('', [new ToolCall('c1', 'whoami', [])]))
            ->add(Message::toolResults([ToolResult::of(new ToolCall('c1', 'whoami', []), 'glpi')]))
    );
    check('a wordless assistant turn sends content null, not an empty string',
        str_contains((string) (lastRequest()['raw'] ?? ''), '"content":null'),
        (string) (lastRequest()['raw'] ?? ''));
    reset_log();
    $openai->complete(midConversation());
    $turns = lastRequest()['body']['messages'] ?? [];
    check('arguments are re-encoded as a JSON string',
        is_string($turns[2]['tool_calls'][0]['function']['arguments'] ?? null)
        && str_contains($turns[2]['tool_calls'][0]['function']['arguments'], '"query":"printer"'));
    check('an empty argument list re-encodes as {}',
        ($turns[2]['tool_calls'][1]['function']['arguments'] ?? '') === '{}');
    check('each result becomes its own message with the tool role',
        ($turns[3]['role'] ?? '') === 'tool' && ($turns[4]['role'] ?? '') === 'tool');
    check('results are correlated by tool_call_id',
        ($turns[3]['tool_call_id'] ?? '') === 'call_a' && ($turns[4]['tool_call_id'] ?? '') === 'call_b');
    check('the transcript is otherwise unchanged', count($turns) === 5);

    echo "\nOpenAI — tool_choice\n";
    foreach (
        [
            [Prompt::TOOL_NONE, 'none'],
            [Prompt::TOOL_REQUIRED, 'required'],
            ['whoami', ['type' => 'function', 'function' => ['name' => 'whoami']]],
        ] as [$choice, $expected]
    ) {
        reset_log();
        $openai->complete(Prompt::make('x')->withTools(tools(), $choice));
        check("maps '$choice'", (lastRequest()['body']['tool_choice'] ?? null) === $expected);
    }

    // ------------------------------------------------------------------- Gemini

    echo "\nGemini — declaring tools\n";
    reset_log();
    $gemini     = new Gemini(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'gemini']);
    $completion = $gemini->complete(Prompt::make('Find printer tickets.')->withTools(tools()));
    $req = lastRequest();

    check('groups every declaration under one tools entry',
        count($req['body']['tools'] ?? []) === 1
        && count($req['body']['tools'][0]['functionDeclarations'] ?? []) === 2);
    check('names the schema field "parameters"',
        isset($req['body']['tools'][0]['functionDeclarations'][0]['parameters']['properties']['query']));
    check('reduces the argument schema to the OpenAPI subset',
        ($req['body']['tools'][0]['functionDeclarations'][0]['parameters']['type'] ?? '') === 'OBJECT'
        && !array_key_exists(
            'additionalProperties',
            $req['body']['tools'][0]['functionDeclarations'][0]['parameters'] ?? []
        ));
    check('omits parameters entirely for an argument-less tool',
        !array_key_exists('parameters', $req['body']['tools'][0]['functionDeclarations'][1] ?? []));
    check('sends the calling mode in toolConfig',
        ($req['body']['toolConfig']['functionCallingConfig']['mode'] ?? '') === 'AUTO');

    echo "\nGemini — reading calls back\n";
    check('surfaces the functionCall part as a tool call', count($completion->tool_calls) === 1);
    check('reads args, not arguments', ($completion->tool_calls[0]->arguments['query'] ?? '') === 'printer');
    check('synthesises a call id for a vendor that has none',
        ($completion->tool_calls[0]->id ?? '') !== '');
    check('a functionCall alongside text keeps both',
        str_contains($completion->text, 'Looking'));

    // A reasoning model attaches a thought signature to the part carrying the
    // function call, and the API requires it back verbatim when that call is
    // replayed. It complains rather than failing when it is missing, so the
    // symptom is degraded tool use — which is exactly the kind of defect that
    // survives a test suite unless something asserts on it.
    check('the thought signature is kept off the functionCall part',
        ($completion->tool_calls[0]->vendor['thoughtSignature'] ?? '') === 'sig-abc123',
        json_encode($completion->tool_calls[0]->vendor ?? []));

    echo "\nGemini — replaying the round trip\n";
    reset_log();
    $gemini->complete(midConversation());
    $contents = lastRequest()['body']['contents'] ?? [];

    check('the assistant turn stays role model', ($contents[1]['role'] ?? '') === 'model');
    check('text and functionCall parts travel together',
        isset($contents[1]['parts'][0]['text'], $contents[1]['parts'][1]['functionCall']));
    check('results go back on a user turn, since Gemini has no tool role',
        ($contents[2]['role'] ?? '') === 'user');
    check('each result is a functionResponse correlated by name',
        ($contents[2]['parts'][0]['functionResponse']['name'] ?? '') === 'search_tickets'
        && ($contents[2]['parts'][1]['functionResponse']['name'] ?? '') === 'whoami');
    check('a structured result stays an object',
        ($contents[2]['parts'][0]['functionResponse']['response']['count'] ?? null) === 2);
    check('a plain-text failure is wrapped, since a bare string is rejected',
        ($contents[2]['parts'][1]['functionResponse']['response']['error'] ?? '') === 'Permission denied.');
    check('and the thought signature goes back where it came from',
        ($contents[1]['parts'][1]['thoughtSignature'] ?? '') === 'sig-replayed',
        json_encode($contents[1]['parts'][1] ?? []));
    check('a call that never had one does not acquire one',
        !array_key_exists('thoughtSignature', $contents[1]['parts'][2] ?? []),
        json_encode($contents[1]['parts'][2] ?? []));

    echo "\nGemini — tool_choice\n";
    foreach (
        [
            [Prompt::TOOL_NONE, 'NONE'],
            [Prompt::TOOL_REQUIRED, 'ANY'],
            ['whoami', 'ANY'],
        ] as [$choice, $expected]
    ) {
        reset_log();
        $gemini->complete(Prompt::make('x')->withTools(tools(), $choice));
        check("maps '$choice'",
            (lastRequest()['body']['toolConfig']['functionCallingConfig']['mode'] ?? '') === $expected);
    }
    check('a specific tool is named in allowedFunctionNames',
        (lastRequest()['body']['toolConfig']['functionCallingConfig']['allowedFunctionNames'] ?? []) === ['whoami']);

    // -------------------------------------------------------------------- Azure

    echo "\nAzure — inherits the OpenAI tool shape\n";
    reset_log();
    (new AzureFoundry([
        'base_url' => MOCK, 'authority' => MOCK, 'api_version' => '2024-10-21',
        'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'model_fast' => 'dep',
    ]))->complete(midConversation());
    $req = lastRequest();

    check('declares function tools', ($req['body']['tools'][0]['type'] ?? '') === 'function');
    check('replays the tool role', ($req['body']['messages'][3]['role'] ?? '') === 'tool');
    check('still omits model in the deployment style', !array_key_exists('model', $req['body'] ?? []));

    // ------------------------------------------------------- neutral behaviour

    echo "\nNeutral vocabulary\n";
    $prompt = Prompt::make('x')->withTools(tools());
    check('a prompt can find one of its tools by name', $prompt->tool('whoami') instanceof Tool);
    check('an unknown name resolves to null', $prompt->tool('nope') === null);
    check('withoutTools() strips them and forbids more',
        $prompt->withoutTools()->tools === [] && $prompt->withoutTools()->tool_choice === Prompt::TOOL_NONE);
    check('withoutTools() leaves the original alone', count($prompt->tools) === 2);
    check('the fingerprint reflects the tools offered',
        Prompt::make('x')->withTools(tools())->fingerprint() !== Prompt::make('x')->fingerprint());
    check('the fingerprint reflects the tool choice',
        Prompt::make('x')->withTools(tools(), Prompt::TOOL_REQUIRED)->fingerprint()
        !== Prompt::make('x')->withTools(tools())->fingerprint());

    check('tool names are validated against what all four accept',
        Tool::isValidName('search_tickets') && Tool::isValidName('mcp__jira__create-issue')
        && !Tool::isValidName('9lives') && !Tool::isValidName('has spaces')
        && !Tool::isValidName('dots.are.risky') && !Tool::isValidName(str_repeat('a', 65)));

    $call = new ToolCall('id', 'search_tickets', ['query' => 'vpn']);
    check('a structured result serialises compactly',
        ToolResult::of($call, ['a' => 1])->content === '{"a":1}');
    check('a string result is passed through unquoted',
        ToolResult::of($call, 'plain text')->content === 'plain text');
    check('a list result is wrapped for the vendors that need an object',
        ToolResult::of($call, [1, 2, 3])->asObject() === ['result' => [1, 2, 3]]);
    check('an object result is not double-wrapped',
        ToolResult::of($call, ['count' => 1])->asObject() === ['count' => 1]);
    check('a call summarises for the audit log',
        str_contains($call->summary(), 'search_tickets({"query":"vpn"})'));

    echo "\n" . ($failures === []
        ? "\033[32mall checks passed\033[0m\n"
        : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

    exit($failures === [] ? 0 : 1);
}
