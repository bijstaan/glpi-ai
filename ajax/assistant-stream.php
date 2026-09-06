<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The assistant, answering out loud.
 *
 * The same question `ajax/assistant.php` answers, over server-sent events
 * instead of one JSON response at the end. Nothing about the run differs: the
 * same `Assistant::ask()`, the same entity gate, the same tools, the same
 * thread. What differs is that the panel is told what is happening while it
 * happens — which turn is running, what tool is being called and with what,
 * what came back, and the answer as the words arrive.
 *
 * That is not decoration. An agent run is four to eight vendor round trips with
 * tool calls between them, and it takes long enough that a still panel reads as
 * a broken one; people press the button again, which starts a second run.
 *
 * **A separate endpoint rather than a mode of the existing one.** The two speak
 * different protocols — one JSON response versus a long-lived event stream —
 * and they fail differently: this one can only report an error *inside* the
 * stream once the headers are out, so every guard it shares with the JSON
 * endpoint has to run before the first byte. Keeping them apart is what lets
 * the JSON endpoint stay the simple thing that anything else can call, and it
 * is the fallback the panel uses where streaming is not available.
 */

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Progress;

// Everything that can refuse must refuse before the stream opens: after the
// first `data:` line the status code is already 200 and a browser will treat
// whatever follows as a conversation.
if ((int) Session::getLoginUserID() <= 0) {
    http_response_code(401);
    exit;
}

if (($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central') {
    http_response_code(403);
    exit;
}

if (!Assistant::available()) {
    http_response_code(503);
    exit;
}

$threads_id = (int) ($_POST['thread'] ?? 0);
$question   = (string) ($_POST['question'] ?? '');

@set_time_limit(0);
ignore_user_abort(false);

// The session lock, released before the run starts — and this is not an
// optimisation, it is the difference between a panel that streams and a
// browser that freezes. PHP holds an exclusive lock on the session file for
// the life of a request, and this request lives for the length of a model's
// answer: every other request from the same browser, including ordinary page
// loads, would queue behind it for a minute.
//
// `$_SESSION` stays readable in memory, so every rights check below still
// works exactly as it did. What is lost is writes: a tool handler calling
// `Session::addMessageAfterRedirect()` on a failure path will find its message
// discarded — which is right here anyway, since there is no redirect and
// nothing would ever render it.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
// Nginx buffers proxied responses by default, which holds every event until
// the run is over and turns this endpoint back into the one it replaces. The
// header is meaningless to Apache and harmless everywhere else.
header('X-Accel-Buffering: no');

// Whatever output buffering the stack arrived with, off. GLPI's front
// controller may have started one, and a buffer between here and the socket is
// indistinguishable from a model that has not said anything yet.
while (ob_get_level() > 0) {
    ob_end_flush();
}

/** One event, out of the door immediately. */
$send = static function (string $type, array $data = []): void {
    echo 'event: ' . $type . "\n";
    echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n\n";

    // Both, and in this order. flush() alone leaves the data in PHP's own
    // buffer on some SAPIs, and connection_aborted() only becomes true after a
    // write has actually been attempted — so this is also how a closed browser
    // gets noticed.
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

$send('open', ['thread' => $threads_id]);

try {
    $answer = Progress::watch(
        static function (string $type, array $data) use ($send): void {
            // A dead connection stops the run rather than filling a log with
            // writes nobody reads. Progress::emit() drops the sink when this
            // throws, and the exception below ends the request.
            if (connection_aborted()) {
                throw new RuntimeException('The browser went away.');
            }

            $send($type, $data);
        },
        static fn(): array => Assistant::ask($threads_id, $question)
    );

    // The finished answer, whole. The panel has been assembling the streamed
    // fragments as they arrived, but only this carries the rendered markdown,
    // the tool summary and the exhausted flag — and on a provider that does not
    // stream it is the first text the panel has seen at all.
    $send(Progress::DONE, [
        'answer'      => $answer['answer'],
        'answer_html' => $answer['answer_html'],
        'trail'       => $answer['trail'],
        'tools'       => $answer['tools'],
        'exhausted'   => $answer['exhausted'],
        'truncated'   => $answer['truncated'],
        'continued'   => $answer['continued'],
    ]);
} catch (AiException $e) {
    $send(Progress::FAILED, ['message' => $e->userMessage()]);
} catch (Throwable $e) {
    // Deliberately not the exception's own message: this is the catch-all, and
    // what lands here is a defect rather than something the technician can act
    // on. The detail goes to the log, where it belongs.
    trigger_error('glpiai: assistant stream failed: ' . $e->getMessage(), E_USER_WARNING);
    $send(Progress::FAILED, ['message' => __('Something went wrong answering that.', 'glpiai')]);
}
