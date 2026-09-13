<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * One tool call, and what came of it.
 *
 * Kept as a record rather than discarded once the result is back on the
 * transcript, because "what did the model actually do" is a question that gets
 * asked after the fact — by a technician wondering where a suggestion came
 * from, and by whoever has to answer somebody asking what an AI feature touched
 * on their tenant. Neither question can be answered from the conversation text.
 */
final class ToolInvocation
{
    public function __construct(
        public readonly ToolCall $call,
        public readonly ToolResult $result,
        public readonly int $duration_ms,
        /** Where the tool came from: `native`, a plugin key, or `mcp:<server>`. */
        public readonly string $source = 'native',
        public readonly bool $mutating = false
    ) {
    }

    public function failed(): bool
    {
        return $this->result->is_error;
    }
}
