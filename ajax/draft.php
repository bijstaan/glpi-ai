<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Drafting: generate, mark used, discard, and create the article.
 *
 * Rights are answered per ticket rather than per profile, the same as
 * ajax/triage.php: `Ticket::canUpdateItem()` is what a technician needed to
 * write the solution by hand, and it is what they need to have one drafted.
 * Creating an article additionally needs GLPI's own knowledge-base create
 * right, which `Drafter::createArticle()` checks — a technician who may resolve
 * tickets is not automatically somebody who may write the knowledge base.
 *
 * `used` and `discard` are recorded even though neither changes anything a user
 * can see. They are the whole measurement: without them the only available
 * statement about this feature is that the drafts seem decent.
 */

use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Draft\Drafter;
use GlpiPlugin\Glpiai\Markdown;

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

$action = (string) ($_POST['action'] ?? '');

if ($action === 'draft') {
    $tickets_id = (int) ($_POST['tickets_id'] ?? 0);
    $kind       = (string) ($_POST['kind'] ?? '');

    $ticket = new Ticket();
    if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
        $respond(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $error = Drafter::draft($tickets_id, $kind);
    if ($error !== '') {
        $respond(['ok' => false, 'message' => $error], 502);
    }

    $draft = Draft::forTicket($tickets_id, $kind);

    $respond([
        'ok'           => true,
        'id'           => (int) ($draft['id'] ?? 0),
        'content'      => (string) ($draft['content'] ?? ''),
        // Rendered here so the solution editor gets the same HTML it would have
        // got from the panel, rather than a second rendering path in JS.
        'content_html' => Markdown::toHtml((string) ($draft['content'] ?? '')),
        'reload'       => true,
    ]);
}

// Everything else acts on an existing draft, so the ticket is resolved from it
// rather than trusted from the request.
$drafts_id = (int) ($_POST['id'] ?? 0);
$row       = Draft::byId($drafts_id);

if ($row === null) {
    $respond(['ok' => false, 'message' => __('That draft no longer exists.', 'glpiai')], 404);
}

$ticket = new Ticket();
if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canUpdateItem()) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

switch ($action) {
    case 'used':
        $respond(['ok' => Drafter::markUsed($drafts_id)]);

    case 'discard':
        $respond(['ok' => Drafter::discard($drafts_id), 'reload' => true]);

    case 'article':
        $error = null;
        $id    = Drafter::createArticle($drafts_id, $error);

        if ($id <= 0) {
            $respond(['ok' => false, 'message' => (string) $error], 400);
        }

        $respond(['ok' => true, 'knowbaseitems_id' => $id, 'reload' => true]);
}

$respond(['ok' => false, 'error' => 'unknown_action'], 400);
