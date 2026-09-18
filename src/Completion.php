<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * One answer, normalised.
 *
 * Carries `provider` and `model` alongside the text because the roadmap calls
 * for storing them with every suggestion: when a feature's accuracy moves, the
 * first question is whether *we* changed the prompt or the vendor changed the
 * model underneath us, and that is unanswerable after the fact unless it was
 * written down at the time.
 */
final class Completion
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly Usage $usage,
        /** Provider-specific stop reason, passed through unmapped — see below. */
        public readonly ?string $finish_reason = null,
        /** The decoded object when the prompt carried a schema, else null. */
        public readonly ?array $data = null,
        /** The raw decoded response body, for debugging. */
        public readonly array $raw = [],
        /** @var ToolCall[] tools the model wants run before it can answer */
        public readonly array $tool_calls = [],
        /**
         * Opaque per-vendor data that must be echoed back verbatim.
         *
         * The same escape hatch {@see ToolCall::$vendor} provides, one level up,
         * because not every vendor's extra state belongs to a single call. The
         * Responses API returns reasoning items alongside the calls, carrying
         * `encrypted_content`; replaying the calls without them leaves the model
         * starting each turn blind to its own thinking. Nothing here looks
         * inside — {@see Message::toolCalls()} carries it back to the adapter
         * that produced it.
         *
         * @var array<int,mixed>
         */
        public readonly array $vendor = []
    ) {
    }

    /**
     * Is the model waiting on a tool?
     *
     * Keyed off the calls themselves rather than the stop reason, which the
     * three vendors spell `tool_use`, `tool_calls` and — for Gemini — not at
     * all, since it reports a perfectly ordinary `STOP` and simply includes a
     * `functionCall` part.
     */
    public function wantsTools(): bool
    {
        return $this->tool_calls !== [];
    }

    /**
     * Did the provider stop because it hit the token ceiling?
     *
     * The one stop reason worth normalising, because it is the one that changes
     * what a caller should *do*: a truncated answer is not a short answer, and
     * a caller that treats it as one will store half a draft as if it were
     * whole. The rest of the reasons are left as the vendor's own string —
     * mapping them all would invent a taxonomy none of the four actually share.
     */
    public function wasTruncated(): bool
    {
        return in_array(
            $this->finish_reason,
            // Anthropic | OpenAI | Gemini | OpenAI Responses
            ['max_tokens', 'length', 'MAX_TOKENS', 'max_output_tokens'],
            true
        );
    }
}
