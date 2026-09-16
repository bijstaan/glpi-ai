<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Connection test for one provider.
 *
 * Sends a fixed, trivial prompt of our own — no ticket content — and reports
 * what came back. This is the only thing that proves a configuration actually
 * works: isConfigured() checks that the fields are filled in, which is a
 * different claim entirely from the credentials being valid, the deployment
 * existing, or the endpoint being reachable through whatever proxy sits in the
 * way.
 *
 * It deliberately exercises the *whole* path — Entra token acquisition
 * included — because the failures worth catching here are exactly the ones that
 * only appear end to end: a scope that mints a valid token the data plane then
 * rejects, an api-version the resource does not offer, a deployment name that
 * differs from the model name.
 */

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Provider\Registry;
use GlpiPlugin\Glpiai\Settings;

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

// Testing a provider reveals whether a credential works, so it needs the right
// that owns those credentials — not merely the right to view the page.
if (!Session::haveRight('plugin_glpiai_config', UPDATE)) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

$provider_id = (string) ($_POST['provider'] ?? '');
$provider    = Registry::build($provider_id);

if ($provider === null) {
    $respond(['ok' => false, 'message' => __('Unknown provider.', 'glpiai')], 400);
}

if (!$provider->isConfigured()) {
    // A missing encryption key looks exactly like an unconfigured provider from
    // here — the fields are filled in, they just cannot be read back — and
    // "fill in the required fields" would send someone to re-type a key that is
    // already correct. Say which of the two it is.
    $respond([
        'ok'      => false,
        'message' => Settings::cryptKeyAvailable()
            ? __('Fill in the required fields and save before testing.', 'glpiai')
            : __('GLPI cannot read its encryption key (glpicrypt.key in the config directory), '
               . 'so no stored credential can be decrypted. This usually means the database was '
               . 'restored onto an instance without its key file. Restore the key from the '
               . 'original instance, or re-enter every secret after generating a new one.', 'glpiai'),
    ]);
}

// Two words back is enough to prove the round trip, and keeping max_tokens tiny
// keeps a misconfigured test from being an expensive one.
$prompt = Prompt::make(
    'Reply with exactly: OK',
    'You are a connectivity probe. Answer in as few words as possible.'
)->withMaxTokens(16);

$prompt->timeout = 30;

try {
    // completeAsAdmin() rather than complete(): the entity gate would otherwise
    // make the settings page untestable until an allowlist happened to be
    // filled in, and this prompt carries no entity data to gate.
    $completion = Client::completeAsAdmin($prompt, $provider);
} catch (AiException $e) {
    $respond([
        'ok'      => false,
        'kind'    => $e->kind,
        // The provider's own message is the useful part — it is what
        // distinguishes a wrong scope from a wrong deployment name — and this
        // response only ever reaches someone who already holds the config
        // right, so it is not a disclosure to them.
        'message' => $e->getMessage(),
        'hint'    => $e->userMessage(),
        'status'  => $e->status,
    ]);
} catch (\Throwable $e) {
    $respond([
        'ok'      => false,
        'message' => __('Unexpected failure: ', 'glpiai') . $e->getMessage(),
    ]);
}

$respond([
    'ok'      => true,
    'model'   => $completion->model,
    'text'    => mb_substr(trim($completion->text), 0, 200),
    'tokens'  => $completion->usage->total(),
    'message' => sprintf(
        __('Connected. %1$s answered in %2$d tokens.', 'glpiai'),
        $completion->model,
        $completion->usage->total()
    ),
]);
