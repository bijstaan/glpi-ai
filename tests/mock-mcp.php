<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * A stand-in MCP server, for exercising the client without a real one.
 *
 * Answers the four methods this client uses, and deliberately varies how: the
 * handshake replies as `application/json`, `tools/list` replies as an SSE
 * stream, and `tools/call` picks based on the tool name. Both framings are
 * legal for any request — the spec leaves the choice to the server — and a
 * client that only ever met one of them in testing would meet the other in
 * production.
 *
 * Behaviours selected by path:
 *   /mcp          the happy path
 *   /mcp/paged    tools/list returns two pages, to exercise the cursor
 *   /mcp/noauth   401s unless a bearer token is present
 *   /mcp/oauth    401s unless the bearer token is one this file issued
 *
 * It also plays the authorization server for the OAuth tests, which is the
 * realistic shape: an MCP server publishes its own protected-resource metadata,
 * and small deployments are frequently their own authorization server. The
 * discovery documents, /authorize and /token are all below.
 *
 * Run inside the GLPI container:
 *   php -S 127.0.0.1:9098 tests/mock-mcp.php
 */

$log_file = sys_get_temp_dir() . '/glpiai-mcp.jsonl';
$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$body     = file_get_contents('php://input');
$message  = json_decode($body, true) ?: [];

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

file_put_contents(
    $log_file,
    json_encode(['path' => $path, 'headers' => $headers, 'message' => $message]) . "\n",
    FILE_APPEND
);


// ------------------------------------------------------------------- OAuth 2.1
//
// Everything below plays the authorization server. Tokens are kept in a file
// beside the request log so they survive between requests of the PHP built-in
// server, which handles one at a time and shares nothing.

$token_store = sys_get_temp_dir() . '/glpiai-oauth.json';

$tokens = static function () use ($token_store): array {
    $raw = is_readable($token_store) ? file_get_contents($token_store) : false;
    $out = $raw !== false ? json_decode($raw, true) : null;

    return is_array($out) ? $out : ['issued' => [], 'codes' => []];
};

$save = static function (array $state) use ($token_store): void {
    file_put_contents($token_store, json_encode($state));
};

$origin = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:9098');

// RFC 9728: the protected resource says where its authorization server is.
// Served at both the bare path and the resource-suffixed one, because the spec
// moved and servers in the wild answer at either.
if (str_contains($path, '/.well-known/oauth-protected-resource')) {
    header('Content-Type: application/json');
    echo json_encode([
        'resource'              => $origin . '/mcp/oauth',
        'authorization_servers' => [$origin],
        'scopes_supported'      => ['mcp:tools'],
    ]);
    exit;
}

// RFC 8414: the authorization server says where its endpoints are.
if (
    str_contains($path, '/.well-known/oauth-authorization-server')
    || str_contains($path, '/.well-known/openid-configuration')
) {
    header('Content-Type: application/json');
    echo json_encode([
        'issuer'                                => $origin,
        'authorization_endpoint'                => $origin . '/authorize',
        'token_endpoint'                        => $origin . '/token',
        'scopes_supported'                      => ['mcp:tools'],
        'grant_types_supported'                 => ['client_credentials', 'authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_post'],
    ]);
    exit;
}

// The authorization endpoint. A real one shows a consent screen; this redirects
// straight back, which is what a test needs and what a headless browser can
// follow. The PKCE challenge is remembered so /token can verify it — a mock
// that skipped that would let a broken verifier through.
if ($path === '/authorize') {
    $state = $tokens();

    $code = 'code-' . bin2hex(random_bytes(8));
    $state['codes'][$code] = [
        'challenge' => (string) ($_GET['code_challenge'] ?? ''),
        'redirect'  => (string) ($_GET['redirect_uri'] ?? ''),
        'resource'  => (string) ($_GET['resource'] ?? ''),
        'at'        => time(),
    ];
    $save($state);

    $back = (string) ($_GET['redirect_uri'] ?? '');
    $sep  = str_contains($back, '?') ? '&' : '?';

    header('Location: ' . $back . $sep . http_build_query([
        'code'  => $code,
        'state' => (string) ($_GET['state'] ?? ''),
    ]));
    http_response_code(302);
    exit;
}

if ($path === '/token') {
    header('Content-Type: application/json');

    parse_str($body, $form);
    $grant = (string) ($form['grant_type'] ?? '');
    $state = $tokens();

    // Credentials are checked, because "the mock accepted anything" is how a
    // client that never sends its secret passes every test it has.
    if (($form['client_id'] ?? '') !== 'mcp-client') {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client', 'error_description' => 'Unknown client id.']);
        exit;
    }

    if ($grant !== 'refresh_token' && ($form['client_secret'] ?? '') !== 'mcp-secret') {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client', 'error_description' => 'Bad client secret.']);
        exit;
    }

    if ($grant === 'authorization_code') {
        $code = (string) ($form['code'] ?? '');
        $held = $state['codes'][$code] ?? null;

        if ($held === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Unknown code.']);
            exit;
        }

        // A code is single-use. Leaving it usable is the flaw that makes an
        // intercepted redirect worth intercepting.
        unset($state['codes'][$code]);

        $verifier  = (string) ($form['code_verifier'] ?? '');
        $expected  = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($held['challenge'] !== '' && !hash_equals($held['challenge'], $expected)) {
            $save($state);
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verifier does not match.']);
            exit;
        }
    } elseif ($grant === 'refresh_token') {
        if (!str_starts_with((string) ($form['refresh_token'] ?? ''), 'refresh-')) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Unknown refresh token.']);
            exit;
        }
    } elseif ($grant !== 'client_credentials') {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        exit;
    }

    $access = 'access-' . bin2hex(random_bytes(8));

    $state['issued'][$access] = time();
    $save($state);

    $payload = [
        'access_token' => $access,
        'token_type'   => 'Bearer',
        // Short on purpose: a test that wants to watch a refresh should not
        // have to wait an hour, and ?ttl= lets one ask for a token that has
        // already expired.
        'expires_in'   => (int) ($_GET['ttl'] ?? 3600),
    ];

    // Only the authorization-code grant gets one, as real servers do: a
    // client-credentials client can just ask again.
    if ($grant === 'authorization_code') {
        $payload['refresh_token'] = 'refresh-' . bin2hex(random_bytes(8));
    }

    echo json_encode($payload);
    exit;
}

// The MCP endpoint that actually enforces it.
if (str_starts_with($path, '/mcp/oauth')) {
    $presented = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $headers['authorization'] ?? '', $m) === 1) {
        $presented = trim($m[1]);
    }

    if ($presented === '' || !isset($tokens()['issued'][$presented])) {
        http_response_code(401);
        // The header MCP's discovery keys off.
        header('WWW-Authenticate: Bearer resource_metadata="' . $origin
            . '/.well-known/oauth-protected-resource"');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }
}

$method = (string) ($message['method'] ?? '');
$id     = $message['id'] ?? null;

/** JSON framing. */
$json = static function (array $payload): never {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

/** SSE framing — the same message, wrapped as a one-event stream. */
$sse = static function (array $payload): never {
    header('Content-Type: text/event-stream');
    echo "event: message\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    // A trailing comment line, as a real stream would send as a keep-alive:
    // the client must stop at the first complete event and ignore this.
    echo ": keep-alive\n\n";
    exit;
};

$result = static fn(mixed $value): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $value];
$error  = static fn(int $code, string $text): array => [
    'jsonrpc' => '2.0',
    'id'      => $id,
    'error'   => ['code' => $code, 'message' => $text],
];

if (str_starts_with($path, '/mcp/noauth') && !isset($headers['authorization'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// A notification carries no id and gets no body back.
if ($id === null) {
    http_response_code(202);
    exit;
}

if ($method === 'initialize') {
    header('Mcp-Session-Id: sess-mock-1');
    $json($result([
        'protocolVersion' => (string) ($message['params']['protocolVersion'] ?? '2025-06-18'),
        'capabilities'    => ['tools' => ['listChanged' => true]],
        'serverInfo'      => ['name' => 'mock-mcp', 'version' => '1.0.0'],
    ]));
}

if ($method === 'tools/list') {
    $paged  = str_starts_with($path, '/mcp/paged');
    $cursor = (string) ($message['params']['cursor'] ?? '');

    $page_one = [
        [
            'name'        => 'get_incident',
            'description' => 'Fetch an incident from the monitoring system.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'string']],
                'required'   => ['id'],
            ],
        ],
        [
            // A name no vendor would accept as a function name — dots and a
            // leading digit — so the client's rewriting is exercised.
            'name'        => '2.list.alerts',
            'description' => 'List currently firing alerts.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ],
    ];

    $page_two = [[
        'name'        => 'acknowledge',
        'description' => 'Acknowledge an alert.',
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
    ]];

    if (!$paged) {
        $sse($result(['tools' => $page_one]));
    }

    $sse($cursor === ''
        ? $result(['tools' => $page_one, 'nextCursor' => 'page2'])
        : $result(['tools' => $page_two]));
}

if ($method === 'tools/call') {
    $name      = (string) ($message['params']['name'] ?? '');
    $arguments = (array) ($message['params']['arguments'] ?? []);

    if ($name === 'unknown_tool') {
        $json($error(-32602, 'Unknown tool: unknown_tool'));
    }

    if ($name === 'acknowledge') {
        // The other failure channel: the call was well-formed, the tool ran and
        // did not like it. A successful response carrying isError.
        $json($result([
            'content'  => [['type' => 'text', 'text' => 'Alert is already acknowledged.']],
            'isError'  => true,
        ]));
    }

    if ($name === '2.list.alerts') {
        $json($result([
            'content' => [
                ['type' => 'text', 'text' => 'ALERT-1 cpu high'],
                ['type' => 'image', 'data' => 'iVBORw0K', 'mimeType' => 'image/png'],
                ['type' => 'text', 'text' => 'ALERT-2 disk full'],
            ],
        ]));
    }

    $json($result([
        'content'           => [['type' => 'text', 'text' => 'rendered for humans']],
        'structuredContent' => ['id' => (string) ($arguments['id'] ?? ''), 'state' => 'open'],
    ]));
}

$json($error(-32601, "Method not found: $method"));
