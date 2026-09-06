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
 * Google's Gemini API.
 *
 * The furthest of the four from this layer's vocabulary, and the adapter that
 * justifies having a translation layer at all. Three renames and a dialect
 * change happen here:
 *
 *  - "assistant" is called `model`
 *  - `messages` is called `contents`, and each turn holds a `parts` array
 *    rather than a string
 *  - the system prompt is a `systemInstruction` object, not a field or a role
 *  - `max_tokens` lives inside `generationConfig` as `maxOutputTokens`
 *
 * and the JSON schema has to be reduced to Gemini's OpenAPI subset — see
 * AbstractProvider::geminiSchema().
 */
final class Gemini extends AbstractProvider implements StreamingProvider
{
    private const DEFAULT_BASE = 'https://generativelanguage.googleapis.com';

    public static function id(): string
    {
        return 'gemini';
    }

    public static function label(): string
    {
        return 'Google Gemini';
    }

    public static function fields(): array
    {
        return [
            new Field('api_key', __('API key', 'glpiai'), Field::SECRET, required: true),
            new Field(
                'base_url',
                __('Endpoint', 'glpiai'),
                Field::TEXT,
                __('Override for a proxy or a Vertex-style gateway.', 'glpiai'),
                placeholder: self::DEFAULT_BASE
            ),
            new Field(
                'api_version',
                __('API version', 'glpiai'),
                Field::SELECT,
                __('Path segment used when calling. v1beta carries newer models first.', 'glpiai'),
                default: 'v1beta',
                options: ['v1beta' => 'v1beta', 'v1' => 'v1']
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
                'thinking',
                __('Ask for thought summaries', 'glpiai'),
                Field::CHECKBOX,
                __('Shows the model\'s reasoning in the assistant panel as it works, on models '
                    . 'that think. Off by default because a model that does not rejects the '
                    . 'request outright — if answers start failing with HTTP 400 after turning '
                    . 'this on, the model is the reason.', 'glpiai')
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
            $this->endpointFor($model, false),
            ['x-goog-api-key' => $this->setting('api_key')],
            $this->buildBody($prompt, $model),
            $prompt->timeout
        );

        return $this->parse($response, $model, $prompt);
    }

    /**
     * The same answer, delivered as it is written.
     *
     * Gemini streams the ordinary `GenerateContentResponse` shape one partial
     * candidate at a time, so the frames are reassembled into exactly what the
     * non-streaming call would have returned and handed to the same parser.
     * Two parsers for one vendor is two things to keep in step, and the one
     * that would rot is the streaming one — it is only exercised when somebody
     * is watching.
     *
     * @param callable(string,string):void $onDelta
     */
    public function stream(Prompt $prompt, callable $onDelta): Completion
    {
        $model = $this->modelFor($prompt->tier);

        $text      = '';
        $calls     = [];
        $finish    = null;
        $usage     = [];
        $version   = $model;
        $feedback  = null;

        $this->postSse(
            $this->endpointFor($model, true),
            ['x-goog-api-key' => $this->setting('api_key')],
            $this->buildBody($prompt, $model),
            $prompt->timeout,
            static function (array $frame) use (&$text, &$calls, &$finish, &$usage, &$version, &$feedback, $onDelta): void {
                if (isset($frame['promptFeedback']['blockReason'])) {
                    $feedback = $frame['promptFeedback'];
                }
                if (isset($frame['usageMetadata'])) {
                    $usage = (array) $frame['usageMetadata'];
                }
                if (isset($frame['modelVersion'])) {
                    $version = (string) $frame['modelVersion'];
                }

                $candidate = $frame['candidates'][0] ?? [];

                if (isset($candidate['finishReason'])) {
                    $finish = (string) $candidate['finishReason'];
                }

                foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                    if (!is_array($part)) {
                        continue;
                    }

                    if (isset($part['text'])) {
                        // A thought part is the model reasoning, not the
                        // answer. It is reported and deliberately *not* added
                        // to the text: it must never end up in what gets shown
                        // as the reply, stored in the thread, or fed back on
                        // the next turn.
                        if (($part['thought'] ?? false) === true) {
                            $onDelta('thinking', (string) $part['text']);
                            continue;
                        }

                        $text .= (string) $part['text'];
                        $onDelta('text', (string) $part['text']);
                        continue;
                    }

                    if (isset($part['functionCall'])) {
                        $calls[] = $part;
                    }
                }
            }
        );

        $parts = [];
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }
        foreach ($calls as $part) {
            $parts[] = $part;
        }

        return $this->parse(array_filter([
            'candidates'     => [array_filter([
                'content'      => ['parts' => $parts],
                'finishReason' => $finish,
            ], static fn($v): bool => $v !== null)],
            'usageMetadata'  => $usage,
            'modelVersion'   => $version,
            'promptFeedback' => $feedback,
        ], static fn($v): bool => $v !== null && $v !== []), $model, $prompt);
    }

    /**
     * The request body, shared by both call styles.
     *
     * @return array<string,mixed>
     */
    private function buildBody(Prompt $prompt, string $model): array
    {
        $contents = [];
        foreach ($prompt->messages as $message) {
            $contents[] = [
                'role'  => $message->isUser() ? 'user' : 'model',
                'parts' => $this->partsFor($message),
            ];
        }

        $generation = ['maxOutputTokens' => $prompt->max_tokens];
        if ($prompt->temperature !== null) {
            $generation['temperature'] = $prompt->temperature;
        }
        if ($prompt->schema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseSchema']   = $this->geminiSchema($prompt->schema);
        }

        $body = ['contents' => $contents];

        if ($prompt->system !== null && $prompt->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $prompt->system]]];
        }

        if ($prompt->tools !== []) {
            // One `tools` entry holding every declaration, not one entry per
            // tool: Gemini groups by tool *type*, and function calling is a
            // single type.
            $body['tools'] = [[
                'functionDeclarations' => array_map(
                    fn(Tool $tool): array => array_filter([
                        'name'        => $tool->name,
                        'description' => $tool->description,
                        // Same OpenAPI-subset reduction the response schema
                        // needs — an argument schema carrying
                        // `additionalProperties` is rejected outright.
                        'parameters'  => ($tool->schema['properties'] ?? []) === []
                            ? null
                            : $this->geminiSchema($tool->schema),
                    ], static fn($v): bool => $v !== null),
                    $prompt->tools
                ),
            ]];

            $mode = match ($prompt->tool_choice) {
                Prompt::TOOL_AUTO     => 'AUTO',
                Prompt::TOOL_NONE     => 'NONE',
                Prompt::TOOL_REQUIRED => 'ANY',
                default               => 'ANY',
            };

            $config = ['mode' => $mode];
            if (!in_array($prompt->tool_choice, [Prompt::TOOL_AUTO, Prompt::TOOL_NONE, Prompt::TOOL_REQUIRED], true)) {
                $config['allowedFunctionNames'] = [$prompt->tool_choice];
            }

            $body['toolConfig'] = ['functionCallingConfig' => $config];
        }

        // Thought summaries, when an administrator has asked for them. Off by
        // default and a setting rather than always-on, because a model that
        // does not think rejects the field outright — an instance pointed at an
        // older or a non-reasoning model would get HTTP 400 on every request,
        // which is a worse failure than not seeing the reasoning.
        if ($this->flag('thinking')) {
            $generation['thinkingConfig'] = ['includeThoughts' => true];
        }

        $body['generationConfig'] = $generation;

        $body += $prompt->extra;

        return $body;
    }

    /**
     * The endpoint, streaming or not.
     *
     * The key goes in a header rather than the `?key=` query parameter that
     * most Gemini examples use: query strings end up in proxy logs, access logs
     * and error reports, and a credential that leaks that way leaks everywhere
     * at once. `alt=sse` is what makes the streaming endpoint send server-sent
     * events rather than a JSON array of responses — without it the body is one
     * enormous array that only parses once it is complete, which is streaming
     * in name only.
     */
    private function endpointFor(string $model, bool $streaming): string
    {
        $url = $this->endpointUrl(self::DEFAULT_BASE, sprintf(
            '/%s/models/%s:%s',
            $this->declared('api_version'),
            rawurlencode($model),
            $streaming ? 'streamGenerateContent' : 'generateContent'
        ));

        return $streaming ? $url . '?alt=sse' : $url;
    }

    /**
     * One response — whole, or reassembled from frames — as a Completion.
     *
     * @param array<string,mixed> $response
     */
    private function parse(array $response, string $model, Prompt $prompt): Completion
    {
        // A safety block is a 200 with no candidates at all, and the reason sits
        // in promptFeedback rather than anywhere near the (absent) answer.
        $blocked = $response['promptFeedback']['blockReason'] ?? null;
        if ($blocked !== null) {
            throw new AiException(
                AiException::REFUSED,
                'Gemini blocked this request: ' . $blocked,
                self::id()
            );
        }

        $candidate = $response['candidates'][0] ?? [];
        $finish    = $candidate['finishReason'] ?? null;

        if ($finish === 'SAFETY' || $finish === 'PROHIBITED_CONTENT') {
            throw new AiException(AiException::REFUSED, 'Gemini declined this request.', self::id());
        }

        $text  = '';
        $calls = [];

        foreach ((array) ($candidate['content']['parts'] ?? []) as $index => $part) {
            if (!is_array($part)) {
                continue;
            }

            if (isset($part['text'])) {
                // Never the thinking. With `includeThoughts` on, a
                // non-streaming call returns the reasoning as ordinary text
                // parts flagged `thought`, and appending those would put the
                // model's private working into the answer, the thread and the
                // next turn's prompt.
                if (($part['thought'] ?? false) !== true) {
                    $text .= (string) $part['text'];
                }
                continue;
            }

            if (isset($part['functionCall'])) {
                // Gemini issues no call id — it correlates a response to a
                // request by function name. One is synthesised here so the
                // neutral shape is the same on all four; the encoder below
                // discards it again on the way back.
                //
                // The thought signature, however, is kept and replayed. A
                // reasoning model attaches one to the part carrying the
                // function call, and sending that call back without it makes
                // the API complain that the signature is missing and the model
                // reason worse on the following turns. It is opaque — a token
                // of the model's own thinking — so it is carried through
                // untouched rather than interpreted.
                $vendor = [];
                if (isset($part['thoughtSignature'])) {
                    $vendor['thoughtSignature'] = (string) $part['thoughtSignature'];
                }

                $calls[] = new ToolCall(
                    'gemini_' . $index . '_' . (string) ($part['functionCall']['name'] ?? ''),
                    (string) ($part['functionCall']['name'] ?? ''),
                    (array) ($part['functionCall']['args'] ?? []),
                    $vendor
                );
            }
        }

        return new Completion(
            text: $text,
            provider: self::id(),
            model: (string) ($response['modelVersion'] ?? $model),
            usage: new Usage(
                (int) ($response['usageMetadata']['promptTokenCount'] ?? 0),
                (int) ($response['usageMetadata']['candidatesTokenCount'] ?? 0)
            ),
            finish_reason: $finish,
            data: $prompt->schema !== null ? $this->decodeJson($text) : null,
            raw: $response,
            tool_calls: $calls
        );
    }

    /**
     * One turn's parts.
     *
     * Tool results go back on a `user` turn rather than a role of their own —
     * Gemini has no tool role, and the `functionResponse` part is what marks
     * them. The response payload must be a JSON *object*; a bare string is
     * rejected, which is why ToolResult::asObject() exists.
     *
     * @return array<int,array<string,mixed>>
     */
    private function partsFor(Message $message): array
    {
        if ($message->hasToolResults()) {
            return array_map(
                static fn(ToolResult $result): array => [
                    'functionResponse' => [
                        'name'     => $result->name,
                        'response' => $result->asObject(),
                    ],
                ],
                $message->tool_results
            );
        }

        $parts = [];
        if ($message->content !== '') {
            $parts[] = ['text' => $message->content];
        }

        foreach ($message->tool_calls as $call) {
            $part = [
                'functionCall' => [
                    'name' => $call->name,
                    'args' => $call->arguments === [] ? new \stdClass() : $call->arguments,
                ],
            ];

            // Straight back where it came from: a sibling of functionCall on
            // the same part, which is where the API puts it and where it looks
            // for it.
            if (isset($call->vendor['thoughtSignature'])) {
                $part['thoughtSignature'] = (string) $call->vendor['thoughtSignature'];
            }

            $parts[] = $part;
        }

        // A turn with no parts at all is rejected; an empty assistant turn
        // alongside tool calls is the case that produces one.
        return $parts === [] ? [['text' => '']] : $parts;
    }
}
