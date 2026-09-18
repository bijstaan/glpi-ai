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
use GlpiPlugin\Glpiai\Usage;

/**
 * The OpenAI Responses API, as a translator rather than an adapter.
 *
 * Responses is not a newer spelling of Chat Completions; it is a different
 * conversation model. A transcript is no longer a list of messages but a list
 * of *items* — messages, function calls, function outputs and reasoning — and
 * the model's own reasoning is one of the things handed back to it. That is the
 * point of moving: from `gpt-5.6` onward, Chat Completions refuses a request
 * that carries `tools` unless `reasoning_effort` is `none`, so on that endpoint
 * a tool-using assistant is a non-reasoning one.
 *
 * This lives in its own class because two adapters need it. Azure speaks it
 * today; OpenAI's own adapter is on the same path and would otherwise grow a
 * second copy of every translation below.
 *
 * **Nothing is stored server-side.** `store` is false on every request, which
 * makes the conversation stateless: `previous_response_id` would be one field
 * instead of the replay below, and it would mean Microsoft keeping the ticket
 * content this plugin is careful about everywhere else. The cost of that choice
 * is {@see Message::$vendor} — reasoning items come back carrying
 * `encrypted_content`, and they have to be replayed verbatim or the model
 * starts each turn without the thinking that led to the tool call it is looking
 * at. Nothing errors when they are dropped; the answers just get worse.
 */
final class Responses
{
    /**
     * A request body.
     *
     * @param array{effort?:string,summary?:bool,schema?:?array} $options
     * @return array<string,mixed>
     */
    public static function body(Prompt $prompt, string $model, array $options = []): array
    {
        $body = [
            'model'             => $model,
            'input'             => self::input($prompt),
            'max_output_tokens' => $prompt->max_tokens,
            // Stateless by policy — see the class docblock.
            'store'             => false,
        ];

        if ($prompt->system !== null && $prompt->system !== '') {
            // The system prompt is a field, not an item. Sending it as a
            // `developer` message would also work and would put it back in the
            // transcript, where every replayed turn would carry it again.
            $body['instructions'] = $prompt->system;
        }

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        $reasoning = [];

        if (($options['effort'] ?? '') !== '') {
            $reasoning['effort'] = (string) $options['effort'];
        }

        if (($options['summary'] ?? false) === true) {
            $reasoning['summary'] = 'auto';
        }

        // Only once there is something to replay. `all_turns` is the default on
        // the models that understand it and an unknown value to the ones that
        // do not, and on the first turn of a conversation it decides nothing.
        if (self::hasReplay($prompt)) {
            $reasoning['context'] = 'all_turns';
        }

        if ($reasoning !== []) {
            $body['reasoning'] = $reasoning;
        }

        if ($prompt->tools !== []) {
            // Flat, unlike Chat Completions, which wraps the same four fields in
            // a `function` object. A tool definition sent in the nested shape is
            // rejected as missing `name`.
            $body['tools'] = array_map(
                static fn(Tool $tool): array => [
                    'type'        => 'function',
                    'name'        => $tool->name,
                    'description' => $tool->description,
                    'parameters'  => $tool->schema,
                ],
                $prompt->tools
            );

            $body['tool_choice'] = match ($prompt->tool_choice) {
                Prompt::TOOL_AUTO, Prompt::TOOL_NONE, Prompt::TOOL_REQUIRED => $prompt->tool_choice,
                // Named choice loses the nesting here too.
                default => ['type' => 'function', 'name' => $prompt->tool_choice],
            };
        }

        if (($options['schema'] ?? null) !== null) {
            // `response_format` on Chat Completions; a format on the text
            // channel here, because a response can carry more than one channel.
            $body['text'] = [
                'format' => [
                    'type'   => 'json_schema',
                    'name'   => $prompt->schema_name,
                    'strict' => true,
                    'schema' => $options['schema'],
                ],
            ];
        }

        return $body + $prompt->extra;
    }

    /**
     * The transcript, as input items.
     *
     * Four message shapes become three item shapes, and the asymmetry is the
     * interesting part: an assistant turn that asked for tools is replayed from
     * the provider's own output items when we have them, because those items
     * carry reasoning this layer cannot reconstruct. The synthesised form below
     * is the fallback for a transcript that came from somewhere else — a
     * conversation that began on another provider, or before this path existed.
     *
     * @return list<array<string,mixed>>
     */
    private static function input(Prompt $prompt): array
    {
        $items = [];

        foreach ($prompt->messages as $message) {
            if ($message->hasToolResults()) {
                foreach ($message->tool_results as $result) {
                    $items[] = [
                        'type'    => 'function_call_output',
                        'call_id' => $result->call_id,
                        'output'  => $result->content,
                    ];
                }
                continue;
            }

            if ($message->hasToolCalls()) {
                if ($message->vendor !== []) {
                    // Verbatim, every item the model produced — reasoning
                    // included. Replaying our own reading of them would drop
                    // `encrypted_content` and silently end the chain.
                    foreach ($message->vendor as $item) {
                        $items[] = $item;
                    }
                    continue;
                }

                if ($message->content !== '') {
                    $items[] = ['role' => Message::ASSISTANT, 'content' => $message->content];
                }

                foreach ($message->tool_calls as $call) {
                    $items[] = [
                        'type'      => 'function_call',
                        'call_id'   => $call->id,
                        'name'      => $call->name,
                        'arguments' => (string) json_encode($call->arguments, JSON_UNESCAPED_SLASHES),
                    ];
                }
                continue;
            }

            $items[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $items;
    }

    private static function hasReplay(Prompt $prompt): bool
    {
        foreach ($prompt->messages as $message) {
            if ($message->vendor !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * One response, normalised.
     *
     * @param array<string,mixed> $response
     * @param ?callable(string):?array $decode schema decoder, or null when the prompt carried none
     */
    public static function parse(
        array $response,
        string $provider,
        string $model,
        ?callable $decode = null
    ): Completion {
        $status = (string) ($response['status'] ?? '');
        $reason = (string) ($response['incomplete_details']['reason'] ?? '');

        // A filtered response is a 200 with an empty output, exactly as on Chat
        // Completions, so the status classifier never sees it.
        if ($reason === 'content_filter' || self::wasBlocked($response)) {
            throw new AiException(
                AiException::REFUSED,
                'The content filter blocked this request.',
                $provider
            );
        }

        if ($status === 'failed') {
            throw new AiException(
                AiException::SERVER,
                'The provider reported: ' . (string) ($response['error']['message'] ?? 'response failed'),
                $provider
            );
        }

        $text   = '';
        $calls  = [];
        $replay = [];

        foreach ((array) ($response['output'] ?? []) as $index => $item) {
            if (!is_array($item) || !isset($item['type'])) {
                continue;
            }

            // Kept before it is read, and kept whole. What has to go back is the
            // provider's item, not our understanding of it.
            $replay[] = $item;

            if ($item['type'] === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $text .= (string) $part['text'];
                    }
                }
                continue;
            }

            if ($item['type'] === 'function_call') {
                // A JSON string the model wrote, so a malformed one is an
                // ordinary event: the tool reports the missing argument back and
                // the model corrects itself on the next turn.
                $arguments = json_decode((string) ($item['arguments'] ?? ''), true);

                $calls[] = new ToolCall(
                    // `call_id` is what a function_call_output correlates
                    // against; `id` identifies the item itself.
                    (string) ($item['call_id'] ?? $item['id'] ?? 'call_' . $index),
                    (string) ($item['name'] ?? ''),
                    is_array($arguments) ? $arguments : []
                );
            }
        }

        return new Completion(
            text: $text,
            provider: $provider,
            model: (string) ($response['model'] ?? $model),
            usage: new Usage(
                (int) ($response['usage']['input_tokens'] ?? 0),
                (int) ($response['usage']['output_tokens'] ?? 0)
            ),
            // Truncation is a status and a reason here rather than a finish
            // reason — `incomplete` alone does not say why.
            finish_reason: $reason !== '' ? $reason : ($status !== '' ? $status : null),
            data: $decode !== null ? $decode($text) : null,
            raw: $response,
            tool_calls: $calls,
            vendor: $replay
        );
    }

    /** Azure attaches its own filter verdicts alongside the OpenAI shape. */
    private static function wasBlocked(array $response): bool
    {
        foreach ((array) ($response['content_filters'] ?? []) as $filter) {
            if (is_array($filter) && ($filter['blocked'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold one streamed frame into the response being assembled.
     *
     * Responses streams typed events rather than partial copies of the answer,
     * and the terminal event carries the whole finished object — so the frames
     * are read for their deltas only and the final object goes to the same
     * parser the non-streaming path uses. Two parsers for one API is two things
     * to keep in step, and the streaming one is the one that rots.
     *
     * @param array<string,mixed>          $frame
     * @param array<string,mixed>          $final  assembled response, by reference
     * @param callable(string,string):void $onDelta
     */
    public static function frame(array $frame, array &$final, callable $onDelta): void
    {
        $type = (string) ($frame['type'] ?? '');

        if ($type === 'response.output_text.delta') {
            $onDelta('text', (string) ($frame['delta'] ?? ''));
            return;
        }

        // The model's reasoning, reported and deliberately never added to the
        // text: it must not reach the reply, the thread, or the next turn as
        // though it were the answer.
        if ($type === 'response.reasoning_summary_text.delta') {
            $onDelta('thinking', (string) ($frame['delta'] ?? ''));
            return;
        }

        // `created` and `in_progress` carry a response object too, and it is an
        // empty shell. Only the terminal events describe what was produced.
        if (in_array($type, ['response.completed', 'response.incomplete', 'response.failed'], true)) {
            $final = (array) ($frame['response'] ?? []);
        }
    }
}
