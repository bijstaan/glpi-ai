<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Adapter conformance: does each provider turn one neutral Prompt into the
 * request its vendor actually expects?
 *
 * This is the test the vendor-neutral layer needs most. Translation is the only
 * thing this code is responsible for — the vendors are responsible for their own
 * behaviour — and every mistake in it has the same shape: a request that looks
 * entirely plausible and is accepted by nobody.
 *
 * It runs without GLPI's kernel. Providers take their settings as a constructor
 * array, so nothing here needs a database, a session, or a real credential; the
 * two GLPI symbols the Azure adapter reaches for are stubbed below, which has
 * the side benefit of making cache hits directly observable.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/adapters.php
 */

// --------------------------------------------------------------- GLPI stubs
//
// Braced namespaces throughout, because the Azure adapter type-hints a GLPI
// class that only exists behind the kernel.

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
    /**
     * Stands in for GLPI's cache manager, which would otherwise drag in the
     * whole Symfony kernel. Counting reads and writes here is what lets the
     * Azure section assert that a token is fetched once and reused — the thing
     * that actually matters about that code path.
     */
    class CacheManager
    {
        /** @var array<string,string> */
        public static array $store  = [];
        public static int   $writes = 0;

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
                    CacheManager::$writes++;

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

    use Glpi\Cache\CacheManager;
    use GlpiPlugin\Glpiai\AiException;
    use GlpiPlugin\Glpiai\Message;
    use GlpiPlugin\Glpiai\Prompt;
    use GlpiPlugin\Glpiai\Provider\Anthropic;
    use GlpiPlugin\Glpiai\Provider\AzureFoundry;
    use GlpiPlugin\Glpiai\Provider\Gemini;
    use GlpiPlugin\Glpiai\Provider\OpenAi;

    $src = dirname(__DIR__) . '/src';
    foreach (
        [
            'AiException', 'Azure/Entra', 'Message', 'Prompt', 'Usage', 'Completion',
            'Provider/Field', 'Provider/Provider', 'Provider/StreamingProvider',
            'Provider/AbstractProvider',
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

    /** @return array<int,array<string,mixed>> every request the mock has seen since the last reset */
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
        CacheManager::$store  = [];
        CacheManager::$writes = 0;
    }

    /** One prompt, reused by every adapter — the point is that it is identical. */
    function samplePrompt(): Prompt
    {
        $prompt = Prompt::make('Classify this ticket.', 'You are a triage assistant.');
        $prompt->add(Message::assistant('Understood.'));
        $prompt->add(Message::user('Printer offline in Accounts.'));

        return $prompt->withMaxTokens(256)->withSchema([
            'type'       => 'object',
            'properties' => [
                'category' => ['type' => 'string'],
                'urgency'  => ['type' => 'integer', 'description' => 'one to five'],
            ],
            // Deliberately present: OpenAI needs it, Gemini rejects it.
            'additionalProperties' => true,
        ], 'triage');
    }

    // ---------------------------------------------------------------- Anthropic

    echo "\nAnthropic\n";
    reset_log();
    $prompt              = samplePrompt();
    $prompt->temperature = 0.7; // must be dropped: current models 400 on it
    $completion          = (new Anthropic([
        'api_key'    => 'sk-test',
        'base_url'   => MOCK . '/',   // trailing slash, to prove it is trimmed
        'model_fast' => 'claude-fast',
    ]))->complete($prompt);
    $req = lastRequest();

    check('posts to /v1/messages', ($req['path'] ?? '') === '/v1/messages', $req['path'] ?? '(none)');
    check('authenticates with x-api-key, not a bearer token',
        ($req['headers']['x-api-key'] ?? '') === 'sk-test' && !isset($req['headers']['authorization']));
    check('pins the dated API version', ($req['headers']['anthropic-version'] ?? '') === '2023-06-01');
    check('sends system as a top-level field', ($req['body']['system'] ?? '') === 'You are a triage assistant.');
    check('does not also send system as a message', ($req['body']['messages'][0]['role'] ?? '') === 'user');
    check('preserves the transcript', count($req['body']['messages'] ?? []) === 3
        && ($req['body']['messages'][1]['role'] ?? '') === 'assistant');
    check('drops temperature', !array_key_exists('temperature', $req['body'] ?? []));
    check('caps output with max_tokens', ($req['body']['max_tokens'] ?? null) === 256);
    check('puts the schema in output_config.format',
        ($req['body']['output_config']['format']['type'] ?? '') === 'json_schema');
    check('tightens the schema',
        ($req['body']['output_config']['format']['schema']['additionalProperties'] ?? null) === false
        && ($req['body']['output_config']['format']['schema']['required'] ?? []) === ['category', 'urgency']);
    check('reads usage', $completion->usage->input_tokens === 11 && $completion->usage->output_tokens === 3);
    check('decodes the constrained answer', ($completion->data['ok'] ?? null) === true);

    // ------------------------------------------------------------------- OpenAI

    echo "\nOpenAI\n";
    reset_log();
    $prompt              = samplePrompt();
    $prompt->temperature = 0.7; // must be kept: OpenAI accepts it
    $completion          = (new OpenAi([
        'api_key'      => 'sk-test',
        'base_url'     => MOCK,
        'model_fast'   => 'gpt-fast',
        'organization' => 'org-123',
    ]))->complete($prompt);
    $req = lastRequest();

    check('posts to /v1/chat/completions', ($req['path'] ?? '') === '/v1/chat/completions', $req['path'] ?? '(none)');
    check('authenticates with a bearer token',
        ($req['headers']['authorization'] ?? '') === 'Bearer sk-test');
    check('sends the organization header', ($req['headers']['openai-organization'] ?? '') === 'org-123');
    check('names the model in the body', ($req['body']['model'] ?? '') === 'gpt-fast');
    check('turns system into the first message', ($req['body']['messages'][0]['role'] ?? '') === 'system');
    check('keeps the transcript after it', count($req['body']['messages'] ?? []) === 4
        && ($req['body']['messages'][3]['content'] ?? '') === 'Printer offline in Accounts.');
    check('keeps temperature', ($req['body']['temperature'] ?? null) === 0.7);
    check('defaults to max_completion_tokens', ($req['body']['max_completion_tokens'] ?? null) === 256);
    check('puts the schema in response_format', ($req['body']['response_format']['type'] ?? '') === 'json_schema');
    check('names the schema', ($req['body']['response_format']['json_schema']['name'] ?? '') === 'triage');
    check('asks for strict mode', ($req['body']['response_format']['json_schema']['strict'] ?? null) === true);
    check('reads usage', $completion->usage->input_tokens === 10 && $completion->usage->output_tokens === 2);

    echo "\nOpenAI-compatible gateway\n";
    reset_log();
    (new OpenAi([
        'api_key'     => 'sk-test',
        'base_url'    => MOCK,
        'model_fast'  => 'llama',
        'token_param' => 'max_tokens',
    ]))->complete(samplePrompt());
    $req = lastRequest();
    check('honours the output-limit override',
        array_key_exists('max_tokens', $req['body'] ?? [])
        && !array_key_exists('max_completion_tokens', $req['body'] ?? []));
    check('omits the organization header when unset', !isset($req['headers']['openai-organization']));

    // ------------------------------------------------------------------- Gemini

    echo "\nGemini\n";
    reset_log();
    $completion = (new Gemini([
        'api_key'    => 'g-test',
        'base_url'   => MOCK,
        'model_fast' => 'gemini-fast',
    ]))->complete(samplePrompt());
    $req = lastRequest();

    check('posts to the model-scoped generateContent path',
        ($req['path'] ?? '') === '/v1beta/models/gemini-fast:generateContent', $req['path'] ?? '(none)');
    check('sends the key as a header, never in the query string',
        ($req['headers']['x-goog-api-key'] ?? '') === 'g-test' && ($req['query'] ?? '') === '');
    check('renames system to systemInstruction',
        ($req['body']['systemInstruction']['parts'][0]['text'] ?? '') === 'You are a triage assistant.');
    check('renames assistant to model', ($req['body']['contents'][1]['role'] ?? '') === 'model');
    check('wraps each turn in parts',
        ($req['body']['contents'][0]['parts'][0]['text'] ?? '') === 'Classify this ticket.');
    check('moves the output cap into generationConfig',
        ($req['body']['generationConfig']['maxOutputTokens'] ?? null) === 256);
    check('asks for a JSON mime type',
        ($req['body']['generationConfig']['responseMimeType'] ?? '') === 'application/json');
    check('upper-cases schema types',
        ($req['body']['generationConfig']['responseSchema']['type'] ?? '') === 'OBJECT'
        && ($req['body']['generationConfig']['responseSchema']['properties']['urgency']['type'] ?? '') === 'INTEGER');
    check('strips keywords Gemini rejects',
        !array_key_exists('additionalProperties', $req['body']['generationConfig']['responseSchema'] ?? []));
    check('keeps keywords Gemini accepts',
        ($req['body']['generationConfig']['responseSchema']['properties']['urgency']['description'] ?? '')
        === 'one to five');
    check('reads usage', $completion->usage->input_tokens === 12 && $completion->usage->output_tokens === 4);

    // ------------------------------------------------------- Azure AI Foundry

    $azure_common = [
        'base_url'      => MOCK,
        'authority'     => MOCK,
        'api_version'   => '2024-10-21',
        'tenant_id'     => 'tenant-abc',
        'client_id'     => 'client-abc',
        'client_secret' => 'shhh',
        'model_fast'    => 'my-deployment',
    ];

    echo "\nAzure AI Foundry — Azure OpenAI style, service principal\n";
    reset_log();
    $azure      = new AzureFoundry($azure_common);
    $completion = $azure->complete(samplePrompt());
    $all        = requests();
    [$token_req, $chat_req] = [$all[0] ?? [], $all[1] ?? []];

    check('acquires a token before the completion', count($all) === 2, count($all) . ' request(s)');
    check('uses the v2.0 client-credentials endpoint',
        ($token_req['path'] ?? '') === '/tenant-abc/oauth2/v2.0/token', $token_req['path'] ?? '(none)');
    check('sends client credentials as a form post',
        str_contains((string) ($token_req['body'] ?? ''), 'grant_type=client_credentials')
        && str_contains((string) ($token_req['body'] ?? ''), 'client_secret=shhh'));
    check('requests the data-plane scope by default',
        str_contains(rawurldecode((string) ($token_req['body'] ?? '')),
            'scope=https://cognitiveservices.azure.com/.default'));
    check('puts the deployment in the URL',
        ($chat_req['path'] ?? '') === '/openai/deployments/my-deployment/chat/completions',
        $chat_req['path'] ?? '(none)');
    check('sends an explicit api-version', ($chat_req['query'] ?? '') === 'api-version=2024-10-21');
    check('presents the Entra token as a bearer token',
        ($chat_req['headers']['authorization'] ?? '') === 'Bearer mock-entra-token');
    check('omits model from the body — the deployment already chose it',
        !array_key_exists('model', $chat_req['body'] ?? []));
    check('defaults to max_tokens, as most Azure deployments require',
        array_key_exists('max_tokens', $chat_req['body'] ?? []));
    check('inherits the OpenAI body shape',
        ($chat_req['body']['messages'][0]['role'] ?? '') === 'system'
        && ($chat_req['body']['response_format']['type'] ?? '') === 'json_schema');
    check('reads usage', $completion->usage->input_tokens === 10);

    echo "\nAzure AI Foundry — token caching\n";
    $azure->complete(samplePrompt());
    check('reuses the cached token instead of re-authenticating', count(requests()) === 3,
        count(requests()) . ' request(s) after a second completion');
    check('wrote the token to cache exactly once', CacheManager::$writes === 1);

    $rotated = new AzureFoundry(['client_secret' => 'rotated'] + $azure_common);
    $rotated->complete(samplePrompt());
    check('a rotated secret does not reuse the old token', count(requests()) === 5,
        count(requests()) . ' request(s) after rotating the secret');

    $azure->forgetToken();
    check('forgetToken() empties the cache', CacheManager::$store === []);

    echo "\nAzure AI Foundry — Foundry style, API key\n";
    reset_log();
    (new AzureFoundry([
        'api_style' => 'foundry',
        'auth_mode' => 'api_key',
        'api_key'   => 'azure-key',
    ] + $azure_common))->complete(samplePrompt());
    $req = lastRequest();

    check('does not contact Entra when using an API key', count(requests()) === 1);
    check('posts to the model-inference route',
        ($req['path'] ?? '') === '/models/chat/completions', $req['path'] ?? '(none)');
    check('names the model in the body for this style', ($req['body']['model'] ?? '') === 'my-deployment');
    check("sends Azure's api-key header, not Authorization",
        ($req['headers']['api-key'] ?? '') === 'azure-key' && !isset($req['headers']['authorization']));

    // ------------------------------------------------------------- model tiers

    echo "\nModel tiers\n";
    reset_log();
    $tiered = new OpenAi([
        'api_key'       => 'k',
        'base_url'      => MOCK,
        'model_fast'    => 'cheap',
        'model_quality' => 'dear',
    ]);
    $tiered->complete(Prompt::make('hi')->withTier(Prompt::TIER_QUALITY));
    check('the quality tier selects the quality model', (lastRequest()['body']['model'] ?? '') === 'dear');
    $tiered->complete(Prompt::make('hi'));
    check('the default tier selects the fast model', (lastRequest()['body']['model'] ?? '') === 'cheap');

    (new OpenAi(['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'only']))
        ->complete(Prompt::make('hi')->withTier(Prompt::TIER_QUALITY));
    check('the quality tier falls back when no quality model is configured',
        (lastRequest()['body']['model'] ?? '') === 'only');

    // ------------------------------------------------------------- endpoints

    // Every one of these APIs carries its version in the path, and every vendor
    // documents the base URL *including* it — Ollama's own instructions say
    // `http://host:11434/v1`. Paste that and a naive concatenation produces
    // `/v1/v1/chat/completions`: a 404 with nothing in it to suggest the cause,
    // which is exactly how this was found.
    echo "\nEndpoint construction\n";

    $urls = new class (['api_key' => 'k', 'base_url' => 'http://h:1/v1', 'model_fast' => 'm']) extends OpenAi {
        public function url(): string
        {
            return $this->endpointFor('m');
        }
    };
    check('a base that already ends in /v1 is not doubled',
        $urls->url() === 'http://h:1/v1/chat/completions', $urls->url());

    $plain = new class (['api_key' => 'k', 'base_url' => 'http://h:1', 'model_fast' => 'm']) extends OpenAi {
        public function url(): string
        {
            return $this->endpointFor('m');
        }
    };
    check('and one without it still gets it',
        $plain->url() === 'http://h:1/v1/chat/completions', $plain->url());

    $slashed = new class (['api_key' => 'k', 'base_url' => 'http://h:1/v1/', 'model_fast' => 'm']) extends OpenAi {
        public function url(): string
        {
            return $this->endpointFor('m');
        }
    };
    check('a trailing slash does not defeat it',
        $slashed->url() === 'http://h:1/v1/chat/completions', $slashed->url());

    // A path segment that merely *looks* like the version must survive: an
    // enterprise proxy at /ai/v1beta is not the same thing as Gemini's version.
    $nested = new class (['api_key' => 'k', 'base_url' => 'http://h:1/gateway/openai', 'model_fast' => 'm']) extends OpenAi {
        public function url(): string
        {
            return $this->endpointFor('m');
        }
    };
    check('a gateway path underneath is left alone',
        $nested->url() === 'http://h:1/gateway/openai/v1/chat/completions', $nested->url());

    // --------------------------------------------------------- error handling

    echo "\nError classification\n";
    $probe = new class (['api_key' => 'k', 'base_url' => MOCK, 'model_fast' => 'm']) extends OpenAi {
        public function classifyFor(int $status, string $body): string
        {
            return $this->classify($status, $body);
        }

        public function messageFor(string $body): ?string
        {
            return $this->extractErrorMessage($body);
        }
    };

    foreach (
        [
            [401, AiException::AUTH],
            [403, AiException::AUTH],
            [429, AiException::RATE_LIMIT],
            [500, AiException::SERVER],
            [503, AiException::SERVER],
            [400, AiException::INVALID],
            [404, AiException::INVALID],
        ] as [$status, $expected]
    ) {
        check("HTTP $status is $expected",
            $probe->classifyFor($status, '{"error":{"message":"nope"}}') === $expected);
    }
    check('a 400 naming a content filter is a refusal, not a bad request',
        $probe->classifyFor(400, '{"error":{"message":"blocked by the content filter"}}') === AiException::REFUSED);

    check('reads the OpenAI/Anthropic error envelope',
        $probe->messageFor('{"error":{"message":"bad key"}}') === 'bad key');
    check('reads the Gemini error envelope',
        $probe->messageFor('{"error":{"error":{"message":"quota"}}}') === 'quota'
        || $probe->messageFor('{"error":{"message":"quota"}}') === 'quota');
    check('reads a bare API-management envelope',
        $probe->messageFor('{"message":"Access denied due to invalid subscription key"}')
        === 'Access denied due to invalid subscription key');
    check('gives up quietly on an HTML error page', $probe->messageFor('<html>502</html>') === null);

    echo "\nProvider-side refusals\n";
    foreach (
        [
            ['anthropic', new Anthropic(['api_key' => 'k', 'base_url' => MOCK . '/refuse', 'model_fast' => 'm'])],
            ['openai', new OpenAi(['api_key' => 'k', 'base_url' => MOCK . '/refuse', 'model_fast' => 'm'])],
            ['gemini', new Gemini(['api_key' => 'k', 'base_url' => MOCK . '/refuse', 'model_fast' => 'm'])],
        ] as [$id, $provider]
    ) {
        try {
            $provider->complete(Prompt::make('something disallowed'));
            check("$id: a 200-with-refusal is raised, not returned as an empty answer", false);
        } catch (AiException $e) {
            check("$id: a 200-with-refusal is raised, not returned as an empty answer",
                $e->kind === AiException::REFUSED, $e->kind);
            check("$id: a refusal is not retryable", !$e->isRetryable());
        }
    }

    echo "\nTransport failures\n";
    try {
        (new OpenAi(['api_key' => 'k', 'base_url' => 'http://127.0.0.1:9', 'model_fast' => 'm']))
            ->complete(Prompt::make('hi'));
        check('an unreachable endpoint raises', false);
    } catch (AiException $e) {
        check('an unreachable endpoint is a transport failure', $e->kind === AiException::TRANSPORT, $e->kind);
        check('transport failures are retryable', $e->isRetryable());
        check('the message names the provider', str_contains($e->getMessage(), 'OpenAI'));
    }

    try {
        (new AzureFoundry(['authority' => 'http://127.0.0.1:9'] + $azure_common))->complete(Prompt::make('hi'));
        check('an unreachable Entra raises', false);
    } catch (AiException $e) {
        check('an unreachable Entra is a transport failure', $e->kind === AiException::TRANSPORT, $e->kind);
    }

    reset_log();
    try {
        (new AzureFoundry(['authority' => MOCK . '/deny'] + $azure_common))->complete(Prompt::make('hi'));
        check('a rejected service principal raises', false);
    } catch (AiException $e) {
        check('a rejected service principal is an auth failure', $e->kind === AiException::AUTH, $e->kind);
        check("Entra's own explanation survives", str_contains($e->getMessage(), 'AADSTS7000215'));
        check('no completion is attempted without a token', count(requests()) === 1);
    }

    echo "\n" . ($failures === []
        ? "\033[32mall " . 'checks passed' . "\033[0m\n"
        : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

    exit($failures === [] ? 0 : 1);
}
