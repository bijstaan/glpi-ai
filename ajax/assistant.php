<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The assistant panel's endpoint: open a thread, ask, and clear.
 *
 * Central interface only. Every AI feature in this plugin is technician-facing
 * by design, and an assistant reachable from the helpdesk portal would be a
 * model talking to a customer — the one thing the roadmap rules out.
 *
 * Thread access is by ownership rather than by right, checked in
 * Thread::mine(). A conversation is one person's working notes: half-formed
 * questions about a machine, guesses that turned out wrong. That is not
 * something a colleague should be able to read by guessing an id, and no
 * profile right expresses "may read other people's thinking".
 */

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Context;
use GlpiPlugin\Glpiai\Assistant\Thread;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Mcp\Connections;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ((int) Session::getLoginUserID() <= 0) {
    $respond(['ok' => false, 'error' => 'unauthenticated'], 401);
}

if (($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central') {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

if (!Assistant::available()) {
    $respond([
        'ok'      => false,
        'message' => __('The assistant is not available.', 'glpiai'),
    ], 503);
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'open') {
    // The itemtype and id come from JavaScript reading the URL, so neither is
    // trusted: Context::item() validates the type, loads the record and applies
    // GLPI's own rights check before any of it can reach a prompt.
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);

    $item = Context::item($itemtype, $items_id);

    if ($item === null) {
        $itemtype = '';
        $items_id = 0;
    }

    $threads_id = Thread::open($itemtype, $items_id, Context::entity($item));

    // Rendered on the way out rather than stored rendered: the transcript is
    // what was said, and keeping it as the model wrote it means a change to the
    // renderer applies to old conversations as well as new ones.
    $messages = [];
    foreach (Thread::messages($threads_id) as $message) {
        $messages[] = $message + [
            'content_html' => ($message['role'] ?? '') === 'assistant'
                ? Markdown::toHtml((string) ($message['content'] ?? ''))
                : '',
        ];
    }

    // Services that want this person's own account and have not got it. Said
    // when the panel opens rather than discovered when a tool refuses: the
    // refusal is correct and actionable, and it still arrives thirty seconds
    // into a question that was never going to work.
    $waiting = Connections::outstanding();

    $respond([
        'ok'       => true,
        'thread'   => $threads_id,
        'context'  => Context::label($item),
        'messages' => $messages,
        'connections' => $waiting > 0
            ? [
                'waiting' => $waiting,
                'url'     => Preference::getSearchURL()
                    . '?forcetab=' . urlencode(Connections::class . '$1'),
            ]
            : null,
    ]);
}

/**
 * The conversations this person has had, newest first.
 *
 * The panel resumes by context, which is right for "I am on this ticket again"
 * and useless for "what did it tell me about that switch on Tuesday" — the
 * thread is still there, and until this there was no way back to it. The app
 * grew the same list first; this is the panel catching up.
 *
 * Scoped to the caller by the query, not filtered afterwards: ownership is the
 * whole access rule here, and a list is where a forgotten filter would be least
 * visible.
 */
if ($action === 'history') {
    $threads = [];

    foreach (Thread::recent(25) as $row) {
        // Decoded from the row rather than re-read per thread: the transcript
        // is one column, and Thread::messages() would be twenty-five more
        // queries for a count.
        $turns = json_decode((string) $row['messages'], true);
        $turns = is_array($turns) ? $turns : [];

        // An empty thread is one the panel opened and nobody asked anything in
        // — every page visit makes one. They are not conversations, and a list
        // mostly made of them is a list nobody scrolls.
        if ($turns === []) {
            continue;
        }

        $item  = Context::item((string) $row['itemtype'], (int) $row['items_id']);
        $label = Context::label($item);

        $threads[] = [
            'id'       => (int) $row['id'],
            'title'    => (string) $row['title'],
            // The record it is about, named. `Context::label()` is empty when
            // the item is gone or no longer visible to this person, so the
            // fallback keeps the row identifiable rather than anonymous.
            'context'  => $label !== ''
                ? $label
                : ((string) $row['itemtype'] !== ''
                    ? $row['itemtype'] . ' #' . (int) $row['items_id']
                    : ''),
            // Formatted here rather than in the browser: GLPI hands out
            // wall-clock strings in the instance's own timezone, and a script
            // parsing them as the reader's local time is how "yesterday" turns
            // into "in three hours".
            'when'     => Html::convDateTime((string) $row['date_mod']),
            'turns'    => count($turns),
        ];
    }

    $respond(['ok' => true, 'threads' => $threads]);
}

$threads_id = (int) ($_POST['thread'] ?? 0);

if (Thread::mine($threads_id) === null) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

switch ($action) {
    case 'ask':
        try {
            $result = Assistant::ask($threads_id, (string) ($_POST['question'] ?? ''));
        } catch (AiException $e) {
            // The provider's own message, verbatim. A technician who has just
            // watched a question fail is better served by "rate limit exceeded"
            // than by a house-style apology.
            $respond(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        $respond(['ok' => true] + $result);

    case 'resume':
        // Reopening a conversation from the history list. The ownership check
        // above is the whole authorisation: a thread is one person's working
        // notes, and no profile right expresses "may read other people's
        // thinking".
        $row  = Thread::mine($threads_id);
        $item = Context::item((string) $row['itemtype'], (int) $row['items_id']);

        $messages = [];
        foreach (Thread::messages($threads_id) as $message) {
            $messages[] = $message + [
                'content_html' => ($message['role'] ?? '') === 'assistant'
                    ? Markdown::toHtml((string) ($message['content'] ?? ''))
                    : '',
            ];
        }

        $respond([
            'ok'       => true,
            'thread'   => $threads_id,
            'context'  => Context::label($item),
            'messages' => $messages,
        ]);

    case 'clear':
        Thread::clear($threads_id);
        $respond(['ok' => true]);
}

$respond(['ok' => false, 'error' => 'unknown_action'], 400);
