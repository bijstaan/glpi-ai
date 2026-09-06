<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * Token counts for one call.
 *
 * Only input and output are modelled, because they are the only two every
 * provider reports. Cache-read and cache-write counts — which matter a great
 * deal to what a workload actually costs — are reported by some vendors and not
 * others, and under different names, so they stay in the raw response rather
 * than being half-populated here.
 *
 * These numbers are not comparable across providers, and nothing in this class
 * pretends otherwise: the same text tokenises differently on each, so summing
 * them across providers gives a number with no meaning. Cost has to be computed
 * per provider, from that provider's own counts and its own rates.
 */
final class Usage
{
    public function __construct(
        public readonly int $input_tokens = 0,
        public readonly int $output_tokens = 0
    ) {
    }

    public function total(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }
}
