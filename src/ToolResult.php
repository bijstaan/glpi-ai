<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * What a tool produced, on its way back to the model.
 *
 * Failures travel as results with `is_error` set, not as exceptions. That is
 * the whole point: a model that is told "no such ticket" can look up the right
 * one, while an exception thrown out of the loop abandons a conversation that
 * was one correction away from succeeding. The only failures worth aborting on
 * are the ones the model cannot do anything about — the provider being
 * unreachable, or the turn budget running out.
 */
final class ToolResult
{
    private function __construct(
        public readonly string $call_id,
        public readonly string $name,
        public readonly string $content,
        public readonly bool $is_error = false
    ) {
    }

    /**
     * A successful result.
     *
     * Arrays are JSON-encoded rather than pretty-printed. Every one of these
     * characters is a token paid for on the next turn, and none of the vendors
     * need the whitespace.
     */
    public static function of(ToolCall $call, mixed $value): self
    {
        return new self($call->id, $call->name, self::render($value));
    }

    public static function error(ToolCall $call, string $message): self
    {
        return new self($call->id, $call->name, $message, true);
    }

    /** For a call that never reached a tool — an unknown name, or a refusal. */
    public static function refused(ToolCall $call, string $reason): self
    {
        return new self($call->id, $call->name, $reason, true);
    }

    private static function render(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '(no result)';
        }

        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * The content as an object, for vendors that insist on one.
     *
     * Gemini's `functionResponse.response` must be a JSON object — a bare string
     * is rejected — so a plain-text result is wrapped rather than sent as is.
     *
     * @return array<string,mixed>
     */
    public function asObject(): array
    {
        $decoded = json_decode($this->content, true);
        if (is_array($decoded) && !array_is_list($decoded)) {
            return $decoded;
        }

        return $this->is_error
            ? ['error' => $this->content]
            : ['result' => $decoded ?? $this->content];
    }
}
