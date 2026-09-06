<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * The model asking for a tool to be run.
 *
 * The id matters more than it looks. Anthropic and OpenAI both correlate a
 * result back to its request by id and will reject a transcript where one is
 * missing or unmatched; Gemini has no ids at all and matches by function name,
 * which means it cannot express two concurrent calls to the *same* tool. So the
 * id is generated here when the vendor did not supply one, and the Gemini
 * adapter simply ignores it — the neutral shape carries the union of what the
 * two conventions need, because dropping to the intersection would mean losing
 * parallel calls everywhere to accommodate one vendor.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** @var array<string,mixed> decoded arguments — never the raw JSON string */
        public readonly array $arguments = [],
        /**
         * Opaque per-vendor data that must be echoed back verbatim.
         *
         * The escape hatch, and deliberately typed as "whatever that provider
         * said" rather than as anything this layer understands. It exists
         * because at least one vendor's transcript is not fully described by
         * name-and-arguments: Gemini's reasoning models attach a
         * `thoughtSignature` to a function call, and refuse to work properly
         * when the call is replayed to them without it — the API says so
         * outright, and the symptom otherwise is degraded tool use rather than
         * an error.
         *
         * Only the adapter that produced an entry reads it back. Nothing else
         * in the plugin looks inside.
         *
         * @var array<string,mixed>
         */
        public readonly array $vendor = []
    ) {
    }

    /** A short, readable rendering for logs and the audit trail. */
    public function summary(int $length = 200): string
    {
        return $this->name . '(' . mb_substr(
            (string) json_encode($this->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0,
            $length
        ) . ')';
    }
}
