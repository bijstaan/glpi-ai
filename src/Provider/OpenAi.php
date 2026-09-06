<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Completion;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolCall;
use GlpiPlugin\Glpiai\Usage;

/**
 * OpenAI, via Chat Completions.
 *
 * Chat Completions rather than the newer Responses API, deliberately: this
 * adapter is also the one people point at self-hosted gateways — LiteLLM,
 * vLLM, OpenRouter, an enterprise proxy — and `/v1/chat/completions` is the
 * shape all of those actually speak. Picking the newer API would buy features
 * this layer does not expose and lose most of the compatibility that makes the
 * custom-endpoint setting worth having.
 *
 * It is also the base for {@see AzureFoundry}, whose request body is identical
 * and whose URL and auth are not.
 */
class OpenAi extends AbstractProvider implements StreamingProvider
{
    private const DEFAULT_BASE = 'https://api.openai.com';

    public static function id(): string
    {
        return 'openai';
    }

    public static function label(): string
    {
        return 'OpenAI';
    }

    public static function fields(): array
    {
        return [
            new Field('api_key', __('API key', 'glpiai'), Field::SECRET, required: true),
            new Field(
                'base_url',
                __('Endpoint', 'glpiai'),
                Field::TEXT,
                __('Override for a gateway, proxy or OpenAI-compatible server.', 'glpiai'),
                placeholder: self::DEFAULT_BASE
            ),
            new Field(
                'organization',
                __('Organization ID', 'glpiai'),
                Field::TEXT,
                __('Optional. Sent as the OpenAI-Organization header.', 'glpiai')
            ),
            new Field(
                'model_fast',
                __('Fast model', 'glpiai'),
                Field::TEXT,
                __('For high-volume work such as triage.', 'glpiai'),
                required: true
            ),
            new Field(
                'model_quality',
                __('Quality model', 'glpiai'),
                Field::TEXT,
                __('For drafting and summarising. Falls back to the fast model if empty.', 'glpiai')
            ),
            new Field(
                'token_param',
                __('Output-limit parameter', 'glpiai'),
                Field::SELECT,
                __('OpenAI\'s newer models require max_completion_tokens and reject max_tokens; '
                    . 'most third-party OpenAI-compatible gateways only understand max_tokens. '
                    . 'If requests fail complaining about one of these, switch to the other.', 'glpiai'),
                default: 'max_completion_tokens',
                options: [
                    'max_completion_tokens' => 'max_completion_tokens',
                    'max_tokens'            => 'max_tokens',
                ]
            ),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->hasAll(['api_key', 'model_fast']);
    }

    public function complete(Prompt $prompt): Completion
    {
        $model    = $this->modelFor($prompt->tier);
        $response = $this->postJson(
            $this->endpointFor($model),
            $this->authHeaders(),
            $this->buildBody($prompt, $model),
            $prompt->timeout
        );

        return $this->parse($response, $model, $prompt);
    }

    /**
     * The same answer, delivered as it is written.
     *
     * Chat Completions streams a sequence of `choices[0].delta` objects, and
     * the fiddly part is tool calls: they arrive fragmented across frames,
     * correlated by an `index`, with the function name in the first fragment
     * and the JSON arguments dribbled out a few characters at a time. They are
     * reassembled here into the shape the non-streaming response has, and the
     * ordinary parser takes it from there — so a change to how a tool call is
     * read applies to both paths at once.
     *
     * There are no reasoning summaries on this endpoint. OpenAI exposes those
     * on the Responses API, which is a different request and response shape
     * altogether; a model with hidden reasoning simply pauses here before the
     * text starts, and the panel says the turn is running rather than showing
     * what it is thinking.
     *
     * @param callable(string,string):void $onDelta
     */
    public function stream(Prompt $prompt, callable $onDelta): Completion
    {
        $model = $this->modelFor($prompt->tier);

        $body = $this->buildBody($prompt, $model) + ['stream' => true];
        // Usage is omitted from a streamed response unless it is asked for, and
        // without it every streamed call would log zero tokens — which reads as
        // a free request rather than an unmeasured one.
        $body['stream_options'] = ['include_usage' => true];

        $text   = '';
        $finish = null;
        $usage  = [];
        $named  = $model;
        $calls  = [];

        $this->postSse(
            $this->endpointFor($model),
            $this->authHeaders(),
            $body,
            $prompt->timeout,
            static function (array $frame) use (&$text, &$finish, &$usage, &$named, &$calls, $onDelta): void {
                if (isset($frame['usage']) && is_array($frame['usage'])) {
                    $usage = $frame['usage'];
                }
                if (isset($frame['model'])) {
                    $named = (string) $frame['model'];
                }

                $choice = $frame['choices'][0] ?? null;
                if (!is_array($choice)) {
                    // The final usage-only frame carries no choices at all.
                    return;
                }

                if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                    $finish = (string) $choice['finish_reason'];
                }

                $delta = $choice['delta'] ?? [];

                $chunk = (string) ($delta['content'] ?? '');
                if ($chunk !== '') {
                    $text .= $chunk;
                    $onDelta('text', $chunk);
                }

                foreach ((array) ($delta['tool_calls'] ?? []) as $fragment) {
                    if (!is_array($fragment)) {
                        continue;
                    }

                    // The index, not the position in this frame: one frame can
                    // carry a fragment of the second call and nothing of the
                    // first.
                    $index = (int) ($fragment['index'] ?? 0);

                    $calls[$index] ??= ['id' => '', 'function' => ['name' => '', 'arguments' => '']];

                    if (isset($fragment['id'])) {
                        $calls[$index]['id'] = (string) $fragment['id'];
                    }
                    if (isset($fragment['function']['name'])) {
                        $calls[$index]['function']['name'] = (string) $fragment['function']['name'];
                    }
                    if (isset($fragment['function']['arguments'])) {
                        $calls[$index]['function']['arguments'] .= (string) $fragment['function']['arguments'];
                    }
                }
            }
        );

        ksort($calls);

        return $this->parse([
            'choices' => [[
                'finish_reason' => $finish,
                'message'       => array_filter([
                    'content'    => $text,
                    'tool_calls' => array_values($calls),
                ], static fn($v): bool => $v !== '' && $v !== []),
            ]],
            'usage' => $usage,
            'model' => $named,
        ], $model, $prompt);
    }

    // ------------------------------------------------------- request shaping

    protected function endpointFor(string $model): string
    {
        return $this->endpointUrl(self::DEFAULT_BASE, '/v1/chat/completions');
    }

    /** @return array<string,string> */
    protected function authHeaders(): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->setting('api_key')];

        if ($this->setting('organization') !== '') {
            $headers['OpenAI-Organization'] = $this->setting('organization');
        }

        return $headers;
    }

    /** @return array<string,mixed> */
    protected function buildBody(Prompt $prompt, string $model): array
    {
        $messages = [];

        // The system instruction is a message here, unlike Anthropic and
        // Gemini. Role "system" rather than the newer "developer" because the
        // gateways this adapter also serves have not universally followed that
        // rename, and "system" is still accepted everywhere.
        if ($prompt->system !== null && $prompt->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $prompt->system];
        }

        foreach ($prompt->messages as $message) {
            // One message per result, not one per turn: OpenAI is the only one
            // of the four that flattens the tool round trip this way, and a
            // single message carrying several results is silently ignored.
            if ($message->hasToolResults()) {
                foreach ($message->tool_results as $result) {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $result->call_id,
                        'content'      => $result->content,
                    ];
                }
                continue;
            }

            if ($message->hasToolCalls()) {
                $messages[] = [
                    'role'       => $message->role,
                    // Explicitly null rather than '': the API treats an empty
                    // string as a real (empty) answer and rejects it alongside
                    // tool_calls.
                    'content'    => $message->content !== '' ? $message->content : null,
                    'tool_calls' => array_map(
                        static fn(ToolCall $call): array => [
                            'id'       => $call->id,
                            'type'     => 'function',
                            'function' => [
                                'name' => $call->name,
                                // A JSON *string*, not an object. This is the
                                // asymmetry that catches everyone: it comes back
                                // as a string too, and has to be decoded.
                                'arguments' => (string) json_encode(
                                    $call->arguments === [] ? new \stdClass() : $call->arguments
                                ),
                            ],
                        ],
                        $message->tool_calls
                    ),
                ];
                continue;
            }

            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $body = [
            'model'    => $model,
            'messages' => $messages,
            $this->declared('token_param') => $prompt->max_tokens,
        ];

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        if ($prompt->tools !== []) {
            $body['tools'] = array_map(
                static fn(Tool $tool): array => [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $tool->name,
                        'description' => $tool->description,
                        'parameters'  => $tool->schema,
                    ],
                ],
                $prompt->tools
            );

            // Note the absence of `strict: true`, which is available here and is
            // not used. Strict mode would require running the argument schema
            // through strictSchema(), and that marks every property required —
            // which is right for a response shape and wrong for a tool, where
            // optional arguments are the norm. Leaving it off also keeps this
            // adapter working against the OpenAI-compatible gateways that do
            // not implement it.
            $body['tool_choice'] = match ($prompt->tool_choice) {
                Prompt::TOOL_AUTO, Prompt::TOOL_NONE, Prompt::TOOL_REQUIRED => $prompt->tool_choice,
                default => ['type' => 'function', 'function' => ['name' => $prompt->tool_choice]],
            };
        }

        if ($prompt->schema !== null) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => $prompt->schema_name,
                    'strict' => true,
                    'schema' => $this->strictSchema($prompt->schema),
                ],
            ];
        }

        return $body + $prompt->extra;
    }

    // ------------------------------------------------------ response parsing

    protected function parse(array $response, string $model, Prompt $prompt): Completion
    {
        $choice = $response['choices'][0] ?? [];
        $finish = $choice['finish_reason'] ?? null;

        // A content-filter stop is a 200 with an empty message, so it has to be
        // caught here rather than by the HTTP status classifier.
        if ($finish === 'content_filter') {
            throw new AiException(
                AiException::REFUSED,
                'The content filter blocked this request.',
                static::id()
            );
        }

        $text  = (string) ($choice['message']['content'] ?? '');
        $calls = [];

        foreach ((array) ($choice['message']['tool_calls'] ?? []) as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // `arguments` is a JSON string, and one the model generated — so a
            // malformed one is a normal event, not an exception. An unparseable
            // argument list becomes an empty one, and the tool reports the
            // missing argument back to the model, which can then correct it.
            $arguments = json_decode((string) ($raw['function']['arguments'] ?? ''), true);

            $calls[] = new ToolCall(
                (string) ($raw['id'] ?? 'call_' . $index),
                (string) ($raw['function']['name'] ?? ''),
                is_array($arguments) ? $arguments : []
            );
        }

        return new Completion(
            text: $text,
            provider: static::id(),
            model: (string) ($response['model'] ?? $model),
            usage: new Usage(
                (int) ($response['usage']['prompt_tokens'] ?? 0),
                (int) ($response['usage']['completion_tokens'] ?? 0)
            ),
            finish_reason: $finish,
            data: $prompt->schema !== null ? $this->decodeJson($text) : null,
            raw: $response,
            tool_calls: $calls
        );
    }
}
