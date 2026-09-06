<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Reply review: read what a technician has written, before they send it.
 *
 * One action, and it writes nothing to the item. The right is answered against
 * the ITIL object rather than against a profile flag — being able to see the
 * ticket is what it takes to be looking at this form at all, and this endpoint
 * returns nothing the caller could not read on the page they are on.
 *
 * The reply text arrives in the POST body because it does not exist anywhere
 * else yet: it is in an editor, unsaved, which is the entire point of
 * reviewing it here.
 */

use GlpiPlugin\Glpiai\Reply\Panel;
use GlpiPlugin\Glpiai\Reply\Reviewer;

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

if ((string) ($_POST['action'] ?? '') !== 'review') {
    $respond(['ok' => false, 'error' => 'unknown action'], 400);
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
$text     = (string) ($_POST['text'] ?? '');

if (!is_a($itemtype, CommonITILObject::class, true)) {
    $respond(['ok' => false, 'error' => 'bad itemtype'], 400);
}

/** @var CommonITILObject $item */
$item = new $itemtype();
if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

$result = Reviewer::review($item, $text);

if (!$result['ok']) {
    $respond(['ok' => false, 'message' => $result['message']], 502);
}

$respond([
    'ok'      => true,
    'verdict' => $result['verdict'],
    'flags'   => count($result['flags']),
    // Rendered here rather than in JavaScript: one copy of the wording, one
    // copy of the escaping, and no second rendering path to drift.
    'html'    => Panel::result($result['verdict'], $result['flags']),
]);
