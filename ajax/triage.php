<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The triage panel's three buttons: apply, dismiss, and run it now.
 *
 * Rights are checked twice on purpose, and they are different checks. The
 * session must hold `plugin_glpiai_config`'s read right to see anything at all,
 * and then the *ticket* has to be one this user could have edited by hand —
 * `Ticket::canUpdateItem()`, evaluated per item, not a blanket profile right.
 * Without the second check this endpoint would be a way to edit any ticket in
 * the instance by guessing a suggestion id.
 *
 * `dismiss` deliberately needs less than `apply`: recording that somebody said
 * no changes nothing about the ticket, and requiring update rights to reject a
 * suggestion would mean read-only technicians silently poison the accept rate
 * by leaving everything undecided.
 */

use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\Triage\Triage;

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

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$field  = (string) ($_POST['field'] ?? '');

$row = Suggestion::byId($id);
if ($row === null) {
    $respond(['ok' => false, 'message' => __('That suggestion no longer exists.', 'glpiai')], 404);
}

$ticket = new Ticket();
if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canViewItem()) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

switch ($action) {
    case 'apply':
        if (!$ticket->canUpdateItem()) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $error = Triage::apply($id, $field);
        if ($error !== '') {
            $respond(['ok' => false, 'message' => $error], 400);
        }

        $respond(['ok' => true, 'field' => $field, 'reload' => true]);

    case 'dismiss':
        if (!Triage::dismiss($id, $field)) {
            $respond(['ok' => false, 'message' => __('Could not record that.', 'glpiai')], 400);
        }

        $respond(['ok' => true, 'field' => $field, 'reload' => false]);

    case 'run':
        // Running a suggestion spends money at a provider, so it takes the
        // right to change the ticket rather than merely to look at it.
        if (!$ticket->canUpdateItem()) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        if (!Settings::flag('enabled') || !Settings::flag('triage_enabled')) {
            $respond(['ok' => false, 'message' => __('Triage is switched off.', 'glpiai')], 400);
        }

        // Re-queue first: a row that previously failed is in a terminal state,
        // and "try again" has to mean try again rather than re-read the error.
        Suggestion::store($id, ['state' => Suggestion::PENDING, 'error_message' => null]);

        if (!Triage::suggest($id)) {
            $fresh = Suggestion::byId($id);
            $respond([
                'ok'      => false,
                'message' => (string) ($fresh['error_message'] ?? __('Triage failed.', 'glpiai')),
            ], 502);
        }

        $respond(['ok' => true, 'reload' => true]);
}

$respond(['ok' => false, 'error' => 'unknown_action'], 400);
