<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * One request, in the only vocabulary every provider shares.
 *
 * This surface is deliberately the *intersection* of what Anthropic, OpenAI,
 * Gemini and Azure can all do — not the union. That is the whole discipline of
 * a vendor-neutral layer: the moment it exposes something only one vendor
 * supports, every other adapter has to fake it, and a faked capability fails
 * quietly and differently on each provider. Anything genuinely vendor-specific
 * belongs in {@see self::$extra}, where it is visibly not portable.
 *
 * The fields here are the ones all four genuinely have: a system instruction,
 * an alternating user/assistant transcript, a cap on output length, an optional
 * sampling temperature, and an optional JSON schema for the answer.
 */
final class Prompt
{
    /**
     * Which of the configured models to use.
     *
     * Every provider is configured with two: a cheap fast one and an expensive
     * capable one. The split exists because the workloads genuinely differ —
     * triage is high-volume and low-value-per-call, drafting a resolution is
     * the opposite — and pinning both to one model means overpaying for one of
     * them. Callers state the shape of their work; the administrator decides
     * which model that maps to.
     */
    public const TIER_FAST    = 'fast';
    public const TIER_QUALITY = 'quality';

    /** @var Message[] */
    public array $messages = [];

    public ?string $system = null;

    public string $tier = self::TIER_FAST;

    /** Hard ceiling on the response. Every provider has one; none default it the same way. */
    public int $max_tokens = 1024;

    /**
     * Sampling temperature, or null to let the provider decide.
     *
     * Nullable, and null is the default, because it is not universally
     * accepted: the current Anthropic models reject `temperature` outright with
     * a 400 rather than ignoring it. A layer that always sent a temperature
     * would be broken on one vendor out of four, so the neutral position is to
     * send nothing unless a caller asks for it — and {@see Provider\Anthropic}
     * drops it even then.
     */
    public ?float $temperature = null;

    /**
     * JSON Schema the answer must satisfy, or null for free text.
     *
     * All four support constrained JSON output, but through four different
     * request shapes and — more awkwardly — three different schema dialects:
     * OpenAI's strict mode demands keywords Gemini rejects outright. See
     * Provider\AbstractProvider::strictSchema() and ::geminiSchema() for the two
     * translations, and keep schemas to plain types, plain nesting and `enum` —
     * anything more exotic survives on some providers and not others.
     */
    public ?array $schema = null;

    /** Names the schema. OpenAI requires one; the others ignore it. */
    public string $schema_name = 'response';

    /**
     * How freely the model may reach for a tool.
     *
     * The portable set is these three plus a specific tool name. All four
     * vendors express it differently — Anthropic `{type: any}`, OpenAI
     * `"required"`, Gemini `functionCallingConfig.mode: ANY` — but they agree on
     * the four meanings, which is the test for whether something belongs on this
     * class at all.
     */
    public const TOOL_AUTO     = 'auto';
    public const TOOL_NONE     = 'none';
    public const TOOL_REQUIRED = 'required';

    /**
     * The tools offered for this request.
     *
     * Offered, not granted: this is what the model is told exists. Whether a
     * call is actually allowed to run is decided at execution time by
     * {@see Tool::refusalReason()}, against the session's rights — a model that
     * is told about a tool it may not use should be refused politely, not
     * quietly handed the result.
     *
     * @var Tool[]
     */
    public array $tools = [];

    /** One of the TOOL_* constants, or the name of a specific tool to force. */
    public string $tool_choice = self::TOOL_AUTO;

    /**
     * Vendor-specific parameters, merged into the request body verbatim.
     *
     * The escape hatch, and deliberately an ugly one: anything in here is by
     * definition not portable, so a caller reaching for it is opting out of the
     * abstraction for that call and should know it.
     *
     * @var array<string,mixed>
     */
    public array $extra = [];

    /** Seconds to wait before giving up on the provider. */
    public int $timeout = 60;

    public static function make(string $user_text, ?string $system = null): self
    {
        $prompt           = new self();
        $prompt->system   = $system;
        $prompt->messages = [Message::user($user_text)];

        return $prompt;
    }

    public function withTier(string $tier): self
    {
        $this->tier = $tier === self::TIER_QUALITY ? self::TIER_QUALITY : self::TIER_FAST;

        return $this;
    }

    public function withSchema(array $schema, string $name = 'response'): self
    {
        $this->schema      = $schema;
        $this->schema_name = $name;

        return $this;
    }

    public function withMaxTokens(int $max): self
    {
        $this->max_tokens = max(1, $max);

        return $this;
    }

    public function add(Message $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    /** @param Tool[] $tools */
    public function withTools(array $tools, string $choice = self::TOOL_AUTO): self
    {
        $this->tools       = array_values($tools);
        $this->tool_choice = $choice;

        return $this;
    }

    /** A copy of this prompt with the tools taken away — used for the loop's final turn. */
    public function withoutTools(): self
    {
        $copy              = clone $this;
        $copy->tools       = [];
        $copy->tool_choice = self::TOOL_NONE;

        return $copy;
    }

    public function tool(string $name): ?Tool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * A stable fingerprint of the request, for logging and de-duplication.
     *
     * Deliberately excludes nothing: two calls that differ only in temperature
     * really are different calls, and a fingerprint that collapsed them would
     * make an A/B of the same prompt look like a cache hit.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->system,
            array_map(
                static fn(Message $m): array => [
                    $m->role,
                    $m->content,
                    array_map(static fn(ToolCall $c): array => [$c->name, $c->arguments], $m->tool_calls),
                    array_map(static fn(ToolResult $r): array => [$r->name, $r->content], $m->tool_results),
                ],
                $this->messages
            ),
            $this->tier,
            $this->max_tokens,
            $this->temperature,
            $this->schema,
            // Names only: two runs offering the same tools are the same request,
            // even if a description was reworded between them.
            array_map(static fn(Tool $t): string => $t->name, $this->tools),
            $this->tool_choice,
            $this->extra,
        ], JSON_THROW_ON_ERROR));
    }
}
