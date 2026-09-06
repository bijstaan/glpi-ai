<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * One turn of the transcript.
 *
 * Only two roles exist here. A system instruction is *not* a message — it is a
 * field on {@see Prompt} — because the four providers disagree about what it
 * is: Anthropic takes a top-level `system` string, OpenAI takes a message with
 * a `system` (or `developer`) role inside the array, and Gemini takes a
 * separate `systemInstruction` object. Modelling it as a role would force three
 * of the four adapters to pick it back out of the array again.
 *
 * Tool use adds two turn shapes rather than two more roles, for the same
 * reason. An assistant turn may carry tool calls alongside (or instead of) its
 * text; the answers come back on a user turn. The vendors spell that third turn
 * three different ways — Anthropic puts `tool_result` blocks in a user message,
 * OpenAI invents a `tool` role with one message per result, Gemini uses
 * `functionResponse` parts — and calling it a role here would mean each adapter
 * translating a role it does not have.
 *
 * Note also that "assistant" is this layer's word. Gemini calls the same role
 * `model`; the translation happens in that adapter, not here.
 */
final class Message
{
    public const USER      = 'user';
    public const ASSISTANT = 'assistant';

    /**
     * @param ToolCall[]   $tool_calls   set on an assistant turn that asked for tools
     * @param ToolResult[] $tool_results set on the user turn answering them
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly array $tool_calls = [],
        public readonly array $tool_results = []
    ) {
    }

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ASSISTANT, $content);
    }

    /**
     * The assistant's turn, replayed back to it.
     *
     * This has to go into the transcript verbatim before the results do. All
     * three conventions correlate a result to the call that asked for it, and a
     * transcript holding results whose request is missing is rejected outright
     * rather than tolerated.
     *
     * @param ToolCall[] $calls
     */
    public static function toolCalls(string $content, array $calls): self
    {
        return new self(self::ASSISTANT, $content, $calls);
    }

    /** @param ToolResult[] $results */
    public static function toolResults(array $results): self
    {
        return new self(self::USER, '', [], $results);
    }

    public function isUser(): bool
    {
        return $this->role === self::USER;
    }

    public function hasToolCalls(): bool
    {
        return $this->tool_calls !== [];
    }

    public function hasToolResults(): bool
    {
        return $this->tool_results !== [];
    }
}
