<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * What is happening right now, for whoever is watching.
 *
 * An agent run is four to eight round trips to a vendor with tool calls in
 * between, and it takes fifteen to sixty seconds. Until this existed the panel
 * sent a question and waited, and the honest description of that experience is
 * that it looks broken: a spinner that never moves is indistinguishable from a
 * request that died, and people press the button again.
 *
 * So the loop narrates itself. Everything that happens between the question
 * and the answer — a turn starting, a tool being called and what it was asked,
 * what came back, the model's own thinking where the vendor will part with it,
 * and the answer arriving word by word — is emitted here, and
 * `ajax/assistant-stream.php` pushes it to the browser as it happens.
 *
 * **A static sink, deliberately.** The alternative is threading a callback from
 * the endpoint through `Assistant::ask()` into `Client::run()`, into
 * `Client::complete()` and on into every provider adapter — five signatures,
 * four of them on a public contract that other plugins implement, all to carry
 * something no behaviour depends on. This is a request-scoped observer: it is a
 * no-op unless somebody is listening, nothing branches on it, and a run with no
 * listener behaves exactly as it did before.
 *
 * It is scoped rather than global. `watch()` takes the body it applies to,
 * restores whatever was listening before — including nothing — and does so on
 * the way out of an exception as well, because a sink left installed after a
 * failed request would write into a response that has already been sent.
 */
final class Progress
{
    /** A turn is about to be asked of the provider. */
    public const TURN = 'turn';

    /** A tool is about to run, with the arguments the model chose. */
    public const TOOL = 'tool';

    /** That tool finished: how long it took, and what it gave back. */
    public const TOOL_RESULT = 'tool_result';

    /** A fragment of the answer, as the vendor streams it. */
    public const TEXT = 'text';

    /** A fragment of the model's own reasoning, where the vendor sends it. */
    public const THINKING = 'thinking';

    /** The answer hit the output ceiling and is being asked to carry on. */
    public const CONTINUED = 'continued';

    /** The run is over; the payload is the finished answer. */
    public const DONE = 'done';

    /** It failed, and this is what to show. */
    public const FAILED = 'failed';

    /** @var null|callable(string,array<string,mixed>):void */
    private static $sink = null;

    /**
     * Run `$body` with `$emit` receiving every event it produces.
     *
     * @template T
     * @param callable(string,array<string,mixed>):void $emit
     * @param callable():T                              $body
     * @return T
     */
    public static function watch(callable $emit, callable $body): mixed
    {
        $previous   = self::$sink;
        self::$sink = $emit;

        try {
            return $body();
        } finally {
            self::$sink = $previous;
        }
    }

    /** Is anybody listening? Lets a caller skip work nobody will see. */
    public static function watched(): bool
    {
        return self::$sink !== null;
    }

    /**
     * Report something.
     *
     * Never throws. A listener that fails — a browser that closed, a broken
     * pipe — must not take down the run it was only observing, and the run is
     * the part somebody is paying for. The sink is dropped on the way out so a
     * dead connection is not written to again on every subsequent event.
     *
     * @param array<string,mixed> $data
     */
    public static function emit(string $type, array $data = []): void
    {
        if (self::$sink === null) {
            return;
        }

        try {
            (self::$sink)($type, $data);
        } catch (\Throwable) {
            self::$sink = null;
        }
    }
}
