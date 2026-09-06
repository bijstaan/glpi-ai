<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\Completion;
use GlpiPlugin\Glpiai\Prompt;

/**
 * A provider that can answer a token at a time.
 *
 * Separate from {@see Provider} rather than added to it, and that is the whole
 * design decision here. `Provider` is the contract the README promises a fifth
 * vendor can satisfy with "one class and one line in Registry"; adding a
 * streaming method to it would make every future adapter implement an SSE
 * parser before it could return a single answer. An adapter that does not
 * implement this simply is not streamed, and the loop still narrates its turns
 * and its tool calls — which is most of what the panel needed.
 *
 * The contract is deliberately narrow: stream the deltas *and* return the same
 * `Completion` the non-streaming call would have. Callers must be able to use
 * the two interchangeably, because the agent loop needs a whole completion —
 * finish reason, tool calls, token counts — regardless of how it arrived. In
 * practice every adapter here reassembles the frames into the shape its own
 * non-streaming parser already understands and calls that, so there is one
 * parser per vendor rather than two that can disagree.
 */
interface StreamingProvider extends Provider
{
    /**
     * Answer, calling `$onDelta` as fragments arrive.
     *
     * `$onDelta` takes the kind — `text` for the answer, `thinking` for the
     * model's own reasoning where the vendor sends it — and the fragment. It
     * is called on the request thread, so a slow listener slows the run; the
     * SSE endpoint's listener does nothing but write and flush.
     *
     * @param callable(string,string):void $onDelta
     * @throws \GlpiPlugin\Glpiai\AiException
     */
    public function stream(Prompt $prompt, callable $onDelta): Completion;
}
