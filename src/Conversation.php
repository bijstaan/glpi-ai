<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * The record of one agent run: every turn, every tool call, and the answer.
 *
 * A tool-using request is not one call and one answer, so it does not return a
 * {@see Completion}. Returning only the last one would throw away the two
 * things a caller most often needs — what the model actually did, and what the
 * whole exchange cost, which is the sum over turns and not the last turn's
 * usage.
 */
final class Conversation
{
    /** @var Completion[] one per provider round trip, in order */
    public array $turns = [];

    /** @var ToolInvocation[] every tool the model ran, in order */
    public array $invocations = [];

    /**
     * Did the model still want to keep going when the turn budget ran out?
     *
     * Not an error. It means the answer below came from a final turn made with
     * the tools taken away — the loop always produces prose rather than
     * returning a completion that is nothing but unanswered tool calls — so the
     * answer is real, just less informed than the model wanted it to be.
     */
    public bool $exhausted = false;

    /**
     * How many of the trailing turns are one answer, split by a token ceiling.
     *
     * A model that runs out of output budget stops mid-sentence and reports it
     * as an ordinary finish reason, so the loop asks it to carry on and the
     * answer arrives in two or three pieces. They are separate turns —
     * separately billed, separately logged — and one answer, which is why
     * {@see text()} joins them and nothing else has to know.
     */
    public int $continued = 0;

    /**
     * Was the answer *still* cut short after the loop tried to continue it?
     *
     * Worth asking, because a truncated answer reads exactly like a finished
     * one: it is fluent, it stops at a plausible place, and nothing about it
     * says the last third is missing.
     */
    public function truncated(): bool
    {
        return $this->final()->wasTruncated();
    }

    public function final(): Completion
    {
        $last = end($this->turns);

        if (!$last instanceof Completion) {
            throw new \LogicException('Conversation has no turns.');
        }

        return $last;
    }

    public function text(): string
    {
        if ($this->continued === 0) {
            return $this->final()->text;
        }

        // The continuation turns, in order, joined with nothing between them:
        // the model was asked to carry on from where it stopped, and it stopped
        // mid-sentence, so anything inserted here lands in the middle of a
        // word.
        $pieces = array_map(
            static fn(Completion $turn): string => $turn->text,
            array_slice($this->turns, -($this->continued + 1))
        );

        return implode('', $pieces);
    }

    public function data(): ?array
    {
        return $this->final()->data;
    }

    /** Every token the run spent, across all turns. */
    public function usage(): Usage
    {
        $input = $output = 0;
        foreach ($this->turns as $turn) {
            $input  += $turn->usage->input_tokens;
            $output += $turn->usage->output_tokens;
        }

        return new Usage($input, $output);
    }

    /** @return ToolInvocation[] */
    public function failures(): array
    {
        return array_values(array_filter($this->invocations, static fn(ToolInvocation $i): bool => $i->failed()));
    }

    /**
     * A one-line account of what the model did, for a log or a debug panel.
     */
    public function trail(): string
    {
        if ($this->invocations === []) {
            return 'no tools used';
        }

        return implode(' → ', array_map(
            static fn(ToolInvocation $i): string => $i->call->name . ($i->failed() ? ' (failed)' : ''),
            $this->invocations
        ));
    }
}
