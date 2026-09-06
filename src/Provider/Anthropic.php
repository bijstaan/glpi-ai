<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Completion;
use GlpiPlugin\Glpiai\Message;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolCall;
use GlpiPlugin\Glpiai\ToolResult;
use GlpiPlugin\Glpiai\Usage;

/**
 * Anthropic's Messages API.
 *
 * The closest of the four to this layer's own shape — system prompt as a
 * top-level field, an alternating transcript, one output cap — which is partly
 * why the neutral vocabulary looks the way it does.
 */
final class Anthropic extends AbstractProvider implements StreamingProvider
{
    private const DEFAULT_BASE = 'https://api.anthropic.com';

    /**
     * Pinned, not "latest".
     *
     * The header is a dated API contract, and letting it float would mean a
     * breaking change on Anthropic's release schedule rather than on ours.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * Below this, extended thinking is not asked for at all.
     *
     * Two constraints meet here: Anthropic's minimum budget is 1,024 tokens,
     * and the budget must be *below* `max_tokens`. So the floor is a little
     * over the minimum, which is also the right place for it on meaning — a
     * ceiling this small belongs to a short structured answer, triage or a
     * classification, where reasoning is neither wanted nor affordable.
     *
     * It has to stay under what the assistant asks for (2,000), or the one
     * feature with a panel to show thinking in would never receive any.
     */
    private const THINKING_FLOOR = 1200;

    public static function id(): string
    {
        return 'anthropic';
    }

    public static function label(): string
    {
        return 'Anthropic (Claude)';
    }

    public static function fields(): array
    {
        return [
            new Field('api_key', __('API key', 'glpiai'), Field::SECRET, required: true),
            new Field(
                'base_url',
                __('Endpoint', 'glpiai'),
                Field::TEXT,
                __('Override for a gateway or proxy. Leave empty for Anthropic directly.', 'glpiai'),
                placeholder: self::DEFAULT_BASE
            ),
            new Field(
                'model_fast',
                __('Fast model', 'glpiai'),
                Field::TEXT,
                __('For high-volume work such as triage.', 'glpiai'),
                placeholder: 'claude-haiku-4-5',
                required: true
            ),
            new Field(
                'thinking',
                __('Ask for extended thinking', 'glpiai'),
                Field::CHECKBOX,
                __('Shows Claude\'s reasoning in the assistant panel as it works. It is charged '
                    . 'as output, and roughly half of each answer\'s token ceiling is set aside '
                    . 'for it — so this costs money on every call, not only the ones somebody is '
                    . 'watching.', 'glpiai')
            ),
            new Field(
                'model_quality',
                __('Quality model', 'glpiai'),
                Field::TEXT,
                __('For drafting and summarising. Falls back to the fast model if empty.', 'glpiai'),
                placeholder: 'claude-opus-5'
            ),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->hasAll(['api_key', 'model_fast']);
    }

    public function complete(Prompt $prompt): Completion
    {
        $model = $this->modelFor($prompt->tier);

        $response = $this->postJson(
            $this->endpointUrl(self::DEFAULT_BASE, '/v1/messages'),
            [
                'x-api-key'         => $this->setting('api_key'),
                'anthropic-version' => self::API_VERSION,
            ],
            $this->buildBody($prompt, $model),
            $prompt->timeout
        );

        return $this->parse($response, $model, $prompt);
    }

    /**
     * The same answer, delivered as it is written.
     *
     * Anthropic's stream is a sequence of named events rather than partial
     * copies of the response: blocks start, receive deltas and stop, and the
     * message ends with a `message_delta` carrying the stop reason and the
     * output token count. The blocks are reassembled here into the shape the
     * non-streaming response has and handed to the same reader, so refusals,
     * tool calls and token counts are all interpreted once.
     *
     * `thinking_delta` is where extended thinking arrives. It is reported and
     * never appended to the text: the thinking is the model's working, not its
     * answer, and Anthropic is explicit that it must not be presented as one.
     *
     * @param callable(string,string):void $onDelta
     */
    public function stream(Prompt $prompt, callable $onDelta): Completion
    {
        $model = $this->modelFor($prompt->tier);

        $blocks = [];
        $usage  = [];
        $stop   = null;
        $named  = $model;

        $this->postSse(
            $this->endpointUrl(self::DEFAULT_BASE, '/v1/messages'),
            [
                'x-api-key'         => $this->setting('api_key'),
                'anthropic-version' => self::API_VERSION,
            ],
            $this->buildBody($prompt, $model) + ['stream' => true],
            $prompt->timeout,
            static function (array $frame) use (&$blocks, &$usage, &$stop, &$named, $onDelta): void {
                $type  = (string) ($frame['type'] ?? '');
                $index = (int) ($frame['index'] ?? 0);

                switch ($type) {
                    case 'message_start':
                        $message = (array) ($frame['message'] ?? []);
                        $named   = (string) ($message['model'] ?? $named);
                        $usage   = (array) ($message['usage'] ?? $usage);
                        break;

                    case 'content_block_start':
                        $block           = (array) ($frame['content_block'] ?? []);
                        $blocks[$index]  = $block + ['text' => '', 'input' => [], '_json' => ''];
                        break;

                    case 'content_block_delta':
                        $delta = (array) ($frame['delta'] ?? []);

                        switch ((string) ($delta['type'] ?? '')) {
                            case 'text_delta':
                                $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '')
                                    . (string) ($delta['text'] ?? '');
                                $onDelta('text', (string) ($delta['text'] ?? ''));
                                break;

                            case 'thinking_delta':
                                $onDelta('thinking', (string) ($delta['thinking'] ?? ''));
                                break;

                            case 'input_json_delta':
                                // A tool call's arguments arrive as a JSON
                                // string in pieces and are only valid once the
                                // block stops.
                                $blocks[$index]['_json'] = ($blocks[$index]['_json'] ?? '')
                                    . (string) ($delta['partial_json'] ?? '');
                                break;
                        }
                        break;

                    case 'content_block_stop':
                        $json = (string) ($blocks[$index]['_json'] ?? '');
                        if ($json !== '') {
                            $decoded                  = json_decode($json, true);
                            $blocks[$index]['input']  = is_array($decoded) ? $decoded : [];
                        }
                        break;

                    case 'message_delta':
                        $stop  = (string) (($frame['delta']['stop_reason'] ?? null) ?? $stop);
                        $usage = (array) ($frame['usage'] ?? []) + $usage;
                        break;
                }
            }
        );

        ksort($blocks);

        $content = [];
        foreach ($blocks as $block) {
            unset($block['_json']);

            // A thinking block is dropped rather than carried into the
            // response: it was reported live, and the parser would otherwise
            // have to know about a block type that never appears in a
            // non-streamed answer this plugin asks for.
            if (($block['type'] ?? '') === 'thinking' || ($block['type'] ?? '') === 'redacted_thinking') {
                continue;
            }

            $content[] = $block;
        }

        return $this->parse([
            'content'     => $content,
            'model'       => $named,
            'stop_reason' => $stop,
            'usage'       => $usage,
        ], $model, $prompt);
    }

    /**
     * The request body, shared by both call styles.
     *
     * @return array<string,mixed>
     */
    private function buildBody(Prompt $prompt, string $model): array
    {
        $body = [
            'model'      => $model,
            'max_tokens' => $prompt->max_tokens,
            'messages'   => array_map([$this, 'encodeMessage'], $prompt->messages),
        ];

        if ($prompt->system !== null && $prompt->system !== '') {
            $body['system'] = $prompt->system;
        }

        if ($prompt->tools !== []) {
            $body['tools'] = array_map(
                static fn(Tool $tool): array => [
                    'name'         => $tool->name,
                    'description'  => $tool->description,
                    'input_schema' => $tool->schema,
                ],
                $prompt->tools
            );

            $body['tool_choice'] = match ($prompt->tool_choice) {
                Prompt::TOOL_AUTO     => ['type' => 'auto'],
                Prompt::TOOL_NONE     => ['type' => 'none'],
                Prompt::TOOL_REQUIRED => ['type' => 'any'],
                default               => ['type' => 'tool', 'name' => $prompt->tool_choice],
            };
        }

        if ($prompt->schema !== null) {
            $body['output_config'] = [
                'format' => ['type' => 'json_schema', 'schema' => $this->strictSchema($prompt->schema)],
            ];
        }

        // Temperature is deliberately not forwarded: the current models reject
        // it with a 400 rather than ignoring it, so passing a caller's value
        // would turn an advisory hint into a hard failure. See the note on
        // Prompt::$temperature.

        // Extended thinking, when an administrator has asked for it. The budget
        // is derived from max_tokens rather than configured separately because
        // Anthropic rejects a budget that is not below the ceiling — two
        // numbers to keep in step is a 400 waiting for whoever changes one.
        if ($this->flag('thinking') && $prompt->max_tokens > self::THINKING_FLOOR) {
            $body['thinking'] = [
                'type'          => 'enabled',
                'budget_tokens' => max(1024, (int) floor($prompt->max_tokens / 2)),
            ];
        }

        $body += $prompt->extra;

        return $body;
    }

    /**
     * One response — whole, or reassembled from a stream — as a Completion.
     *
     * @param array<string,mixed> $response
     */
    private function parse(array $response, string $model, Prompt $prompt): Completion
    {
        // A policy decline arrives as a successful response with a refusal stop
        // reason, not as an error status — so it has to be checked before the
        // content is read, or an empty answer looks like a working call that
        // happened to return nothing.
        if (($response['stop_reason'] ?? null) === 'refusal') {
            throw new AiException(
                AiException::REFUSED,
                'Claude declined this request.',
                self::id()
            );
        }

        $text  = '';
        $calls = [];

        foreach ((array) ($response['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            match ($block['type'] ?? '') {
                'text'     => $text .= (string) ($block['text'] ?? ''),
                // The fallback id is only ever a local label: it pairs this call
                // with its result inside one request, is never persisted, and is
                // never trusted as coming from anywhere. Uniqueness is all that
                // is wanted from it, so uniqid() is not a weak token here — it
                // is not a token.
                'tool_use' => $calls[] = new ToolCall(
                    (string) ($block['id'] ?? uniqid('call_', true)),
                    (string) ($block['name'] ?? ''),
                    (array) ($block['input'] ?? [])
                ),
                default    => null,
            };
        }

        return new Completion(
            text: $text,
            provider: self::id(),
            model: (string) ($response['model'] ?? $model),
            usage: new Usage(
                (int) ($response['usage']['input_tokens'] ?? 0),
                (int) ($response['usage']['output_tokens'] ?? 0)
            ),
            finish_reason: $response['stop_reason'] ?? null,
            data: $prompt->schema !== null ? $this->decodeJson($text) : null,
            raw: $response,
            tool_calls: $calls
        );
    }

    /**
     * One turn, as content blocks.
     *
     * Anthropic accepts a plain string for an ordinary turn, and this keeps
     * using one — a transcript full of single-element arrays is harder to read
     * in a log for no benefit. Only the two tool shapes need the block form.
     *
     * @return array<string,mixed>
     */
    private function encodeMessage(Message $message): array
    {
        if ($message->hasToolResults()) {
            return [
                'role'    => 'user',
                'content' => array_map(
                    static fn(ToolResult $result): array => array_filter([
                        'type'        => 'tool_result',
                        'tool_use_id' => $result->call_id,
                        'content'     => $result->content,
                        'is_error'    => $result->is_error ?: null,
                    ], static fn($v): bool => $v !== null),
                    $message->tool_results
                ),
            ];
        }

        if ($message->hasToolCalls()) {
            $blocks = [];
            if ($message->content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $message->content];
            }
            foreach ($message->tool_calls as $call) {
                $blocks[] = [
                    'type'  => 'tool_use',
                    'id'    => $call->id,
                    'name'  => $call->name,
                    // An empty argument object must serialise as {} and not [],
                    // which is what PHP does with an empty array unless told.
                    'input' => $call->arguments === [] ? new \stdClass() : $call->arguments,
                ];
            }

            return ['role' => $message->role, 'content' => $blocks];
        }

        return ['role' => $message->role, 'content' => $message->content];
    }
}
