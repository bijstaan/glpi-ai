<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use GlpiPlugin\Glpiai\AiException;
use GLPIKey;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\TransferException;

/**
 * OAuth 2.1 for MCP servers.
 *
 * MCP's authorization spec is ordinary OAuth wearing a hat: the server is a
 * protected resource, it advertises its authorization server, and a client
 * gets a bearer token the normal way. What the spec adds is discovery — a 401
 * carries a `WWW-Authenticate` header naming the resource metadata, which names
 * the authorization server, which publishes its endpoints — so an administrator
 * can paste one URL rather than four.
 *
 * Two grants, and they answer different questions:
 *
 * **Client credentials** is the common case and the one to reach for. GLPI is a
 * server talking to another server; there is no user to consent, the token is
 * minted on demand, and nothing has to be re-authorized when the technician who
 * set it up leaves.
 *
 * **Authorization code with PKCE** exists for services that only ever issue
 * user-delegated tokens. It comes in two shapes, and which one a server needs
 * is the question `Server::$auth_identity` answers:
 *
 *  - *As the instance.* An administrator authorizes once from the server form,
 *    GLPI keeps the refresh token on the server record, and everybody's
 *    requests use it. Right for a server whose data is the same for everyone,
 *    awkward because it ties an integration to the person who set it up.
 *  - *As each person.* Every technician authorizes their own account from their
 *    preferences and their token is used only on their own requests. Right —
 *    and the only correct answer — for a server that reaches somebody's
 *    mailbox, drive or issue tracker, where one shared credential would mean
 *    either everybody using one person's account or a service account with
 *    access to everybody's.
 *
 * The flow is identical; only where the token is kept differs, which is why
 * every method here takes an optional {@see Grant}. Passing one authenticates a
 * person; passing null authenticates the instance.
 *
 * Tokens are cached with their expiry and refreshed a minute early. Refreshing
 * on a 401 instead would work and would mean every expiry costs a failed
 * request first.
 */
final class OAuth
{
    public const CLIENT_CREDENTIALS = 'client_credentials';
    public const AUTHORIZATION_CODE = 'authorization_code';

    /** Renew this long before expiry, so a token never dies in flight. */
    private const SKEW = 60;

    /** How long a pending authorization may sit unfinished. */
    private const PENDING_TTL = 600;

    private const DISCOVERY_TIMEOUT = 15;

    /**
     * A usable access token for this server, minting or refreshing as needed.
     *
     * @throws AiException
     */
    public static function token(Server $server, ?Grant $grant = null): string
    {
        $stored  = $grant !== null ? $grant->secret('access_token') : $server->secret('oauth_access_token');
        $expires = $grant !== null
            ? (string) ($grant->fields['expires_at'] ?? '')
            : (string) ($server->fields['oauth_expires_at'] ?? '');

        if ($stored !== '' && $expires !== '' && strtotime($expires) - self::SKEW > time()) {
            return $stored;
        }

        $flow = (string) ($server->fields['oauth_grant'] ?? self::CLIENT_CREDENTIALS);

        // A person's connection is always authorization code: client
        // credentials authenticates the client, which is the instance, and
        // there is no such thing as a per-user client-credentials token.
        if ($grant !== null || $flow === self::AUTHORIZATION_CODE) {
            $refresh = $grant !== null
                ? $grant->secret('refresh_token')
                : $server->secret('oauth_refresh_token');

            if ($refresh === '') {
                throw new AiException(
                    AiException::AUTH,
                    $grant !== null
                        ? sprintf(
                            'You have not connected your own account to "%s" yet. Open '
                            . 'My settings > AI connections and press Connect.',
                            (string) $server->fields['name']
                        )
                        : 'This server has not been authorized yet. Open it and press Authorize.',
                    'mcp'
                );
            }

            return self::store($server, self::post($server, [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
            ]), $grant);
        }

        return self::store($server, self::post($server, [
            'grant_type' => self::CLIENT_CREDENTIALS,
        ] + self::scopeParam($server)), null);
    }

    /**
     * Find the authorization endpoints from the MCP server's own URL.
     *
     * Three hops, each optional, because servers implement varying amounts of
     * the spec: the resource metadata names the authorization server, the
     * authorization server metadata names its endpoints, and a server that
     * publishes neither leaves the administrator to fill them in by hand.
     *
     * @return array{authorization_endpoint?:string,token_endpoint?:string,issuer?:string,scopes_supported?:array}
     */
    public static function discover(string $mcp_url): array
    {
        $base = self::origin($mcp_url);
        if ($base === '') {
            return [];
        }

        $issuer = null;

        // RFC 9728. Both spellings: the path-suffixed form is what the current
        // MCP spec asks for, and the bare form is what a lot of servers built
        // against the earlier draft actually serve.
        $path = parse_url($mcp_url, PHP_URL_PATH) ?: '';
        foreach (
            [
                $base . '/.well-known/oauth-protected-resource' . rtrim($path, '/'),
                $base . '/.well-known/oauth-protected-resource',
            ] as $url
        ) {
            $resource = self::fetch($url);

            if (isset($resource['authorization_servers'][0])) {
                $issuer = (string) $resource['authorization_servers'][0];
                break;
            }
        }

        $issuer ??= $base;

        // RFC 8414, then OpenID discovery, which many authorization servers
        // publish instead and which carries the same two endpoint names.
        foreach (
            [
                rtrim($issuer, '/') . '/.well-known/oauth-authorization-server',
                rtrim($issuer, '/') . '/.well-known/openid-configuration',
            ] as $url
        ) {
            $metadata = self::fetch($url);

            if (isset($metadata['token_endpoint'])) {
                return [
                    'issuer'                 => (string) ($metadata['issuer'] ?? $issuer),
                    'authorization_endpoint' => (string) ($metadata['authorization_endpoint'] ?? ''),
                    'token_endpoint'         => (string) $metadata['token_endpoint'],
                    'scopes_supported'       => (array) ($metadata['scopes_supported'] ?? []),
                ];
            }
        }

        return [];
    }

    // ------------------------------------------------------- authorization code

    /**
     * Begin a user-delegated authorization.
     *
     * The verifier and state are held server-side against the server record
     * rather than in a cookie. GLPI's own session cookie is SameSite=Strict and
     * is therefore not sent on the cross-site hop back from the authorization
     * server — a lesson this monorepo learned the hard way in glpi-identity —
     * and unlike a login there is no need for the callback to be anonymous.
     *
     * @return string the URL to send the administrator to
     * @throws AiException
     */
    public static function begin(Server $server, string $redirect_uri, ?Grant $grant = null): string
    {
        $endpoint = (string) ($server->fields['oauth_auth_url'] ?? '');
        if ($endpoint === '') {
            throw new AiException(
                AiException::INVALID,
                'This server has no authorization endpoint. Discover or enter one first.',
                'mcp'
            );
        }

        $verifier  = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state     = bin2hex(random_bytes(16));

        $pending = [
            'state'    => $state,
            'verifier' => $verifier,
            'redirect' => $redirect_uri,
        ];

        // On the grant when a person is connecting, so two technicians can be
        // half-way through at once. A single slot on the server record would
        // mean the second one to press Connect quietly taking over the first
        // one's callback.
        if ($grant !== null) {
            $grant->beginPending($pending);
        } else {
            (new Server())->update([
                'id'            => (int) $server->getID(),
                'oauth_pending' => (new GLPIKey())->encrypt(
                    json_encode($pending + ['at' => time()]) ?: ''
                ),
            ]);
        }

        $params = [
            'response_type'         => 'code',
            'client_id'             => (string) $server->fields['oauth_client_id'],
            'redirect_uri'          => $redirect_uri,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ] + self::scopeParam($server);

        // The resource indicator (RFC 8707). MCP requires it so the token is
        // issued *for this server* — without it an authorization server that
        // protects several resources may mint something the MCP server will
        // refuse, and the failure surfaces as an opaque 401 much later.
        $resource = trim((string) $server->fields['url']);
        if ($resource !== '') {
            $params['resource'] = $resource;
        }

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Finish an authorization begun by begin().
     *
     * @throws AiException
     */
    public static function complete(Server $server, string $code, string $state, ?Grant $grant = null): void
    {
        if ($grant !== null) {
            // Reads and clears in one go, with its own expiry check.
            $pending = $grant->takePending();
        } else {
            $raw = (string) ($server->fields['oauth_pending'] ?? '');
            if ($raw === '') {
                throw new AiException(AiException::INVALID, 'There is no authorization in progress.', 'mcp');
            }

            $pending = json_decode((string) (new GLPIKey())->decrypt($raw), true);

            // Cleared whatever happens next: a pending authorization is
            // single-use, and leaving it behind lets a replayed callback be
            // presented twice.
            (new Server())->update(['id' => (int) $server->getID(), 'oauth_pending' => '']);

            if (is_array($pending) && time() - (int) ($pending['at'] ?? 0) > self::PENDING_TTL) {
                throw new AiException(
                    AiException::INVALID,
                    'That authorization took too long. Start again.',
                    'mcp'
                );
            }
        }

        if (!is_array($pending) || !isset($pending['state'], $pending['verifier'])) {
            throw new AiException(
                AiException::INVALID,
                'There is no authorization in progress, or it took too long. Start again.',
                'mcp'
            );
        }

        // Constant-time, because this is the CSRF defence for the callback and
        // a timing-distinguishable comparison is the one bug worth avoiding
        // here even though the window is small.
        if (!hash_equals((string) $pending['state'], $state)) {
            throw new AiException(AiException::AUTH, 'That authorization did not come from us.', 'mcp');
        }

        self::store($server, self::post($server, [
            'grant_type'    => self::AUTHORIZATION_CODE,
            'code'          => $code,
            'redirect_uri'  => (string) $pending['redirect'],
            'code_verifier' => (string) $pending['verifier'],
        ]), $grant);
    }

    // ------------------------------------------------------------------ shared

    /**
     * One call to the token endpoint.
     *
     * The client secret goes in the body rather than in a Basic header. Both
     * are permitted; the body form is what every authorization server accepts,
     * whereas `client_secret_basic` support varies — and a secret in a header
     * is no better protected than one in a POST body over TLS.
     *
     * @param array<string,string> $params
     * @return array<string,mixed>
     * @throws AiException
     */
    private static function post(Server $server, array $params): array
    {
        $endpoint = (string) ($server->fields['oauth_token_url'] ?? '');
        if ($endpoint === '') {
            throw new AiException(
                AiException::INVALID,
                'This server has no token endpoint configured.',
                'mcp'
            );
        }

        $params['client_id'] = (string) $server->fields['oauth_client_id'];

        $secret = $server->secret('oauth_client_secret');
        if ($secret !== '') {
            $params['client_secret'] = $secret;
        }

        try {
            $response = (new HttpClient())->post($endpoint, [
                'form_params'     => $params,
                'headers'         => ['Accept' => 'application/json'],
                'timeout'         => max(5, (int) $server->fields['timeout']),
                'http_errors'     => false,
                'allow_redirects' => false,
            ]);
        } catch (TransferException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                'Could not reach the token endpoint: ' . $e->getMessage(),
                'mcp'
            );
        }

        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $status  = $response->getStatusCode();

        if ($status >= 400 || !is_array($decoded)) {
            // OAuth's own error shape, when there is one. "invalid_client" is
            // an answer; "HTTP 400" is a puzzle.
            $detail = is_array($decoded)
                ? trim(($decoded['error'] ?? '') . ' ' . ($decoded['error_description'] ?? ''))
                : mb_substr($body, 0, 200);

            throw new AiException(
                $status === 401 || $status === 400 ? AiException::AUTH : AiException::SERVER,
                'The authorization server refused: ' . ($detail !== '' ? $detail : 'HTTP ' . $status),
                'mcp'
            );
        }

        if (trim((string) ($decoded['access_token'] ?? '')) === '') {
            throw new AiException(
                AiException::AUTH,
                'The authorization server returned no access token.',
                'mcp'
            );
        }

        return $decoded;
    }

    /**
     * Persist a token grant.
     *
     * A refresh token is only overwritten when one was returned. Servers that
     * rotate them send a new one every time; servers that do not send nothing
     * on a refresh, and blanking the stored one there would break the
     * integration on its second renewal — a fortnight after anyone was
     * watching.
     *
     * @param array<string,mixed> $grant
     */
    private static function store(Server $server, array $token, ?Grant $grant = null): string
    {
        $access  = (string) $token['access_token'];
        $expires = (int) ($token['expires_in'] ?? 3600);

        // A person's token never touches the server record. That is the whole
        // separation: one row per person, readable by nothing that does not
        // already have their session.
        if ($grant !== null) {
            $grant->keep(
                $access,
                isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
                $expires,
                (string) ($token['scope'] ?? '')
            );

            return $access;
        }

        // Encrypted here, explicitly. SECURED_FIELDS tells GLPI which columns
        // to re-encrypt when the key is rotated; it does not encrypt anything
        // on write, and a token stored in plain text reads back as empty
        // because secret() cannot decrypt it — so the symptom is not a leak in
        // the logs but a cache that never hits and mints a token per request.
        $key = new GLPIKey();

        $values = [
            'id'                 => (int) $server->getID(),
            'oauth_access_token' => $key->encrypt($access),
            'oauth_expires_at'   => date('Y-m-d H:i:s', time() + max(30, $expires)),
        ];

        if (trim((string) ($token['refresh_token'] ?? '')) !== '') {
            $values['oauth_refresh_token'] = $key->encrypt((string) $token['refresh_token']);
        }

        (new Server())->update($values);

        // The in-memory copy is updated too, so a caller holding this object
        // sees what was just written rather than having to re-read the row.
        $server->fields['oauth_access_token'] = $values['oauth_access_token'];
        $server->fields['oauth_expires_at']   = $values['oauth_expires_at'];

        if (isset($values['oauth_refresh_token'])) {
            $server->fields['oauth_refresh_token'] = $values['oauth_refresh_token'];
        }

        return $access;
    }

    /** Drop the cached token, so the next call mints a fresh one. */
    public static function forget(Server $server, ?Grant $grant = null): void
    {
        if ($grant !== null) {
            $grant->stale();

            return;
        }

        (new Server())->update([
            'id'                 => (int) $server->getID(),
            'oauth_access_token' => '',
            'oauth_expires_at'   => null,
        ]);

        $server->fields['oauth_access_token'] = '';
        $server->fields['oauth_expires_at']   = null;
    }

    /** @return array<string,string> */
    private static function scopeParam(Server $server): array
    {
        $scope = trim((string) ($server->fields['oauth_scope'] ?? ''));

        return $scope === '' ? [] : ['scope' => $scope];
    }

    /** @return array<string,mixed> */
    private static function fetch(string $url): array
    {
        try {
            $response = (new HttpClient())->get($url, [
                'headers'         => ['Accept' => 'application/json'],
                'timeout'         => self::DISCOVERY_TIMEOUT,
                'http_errors'     => false,
                'allow_redirects' => true,
            ]);
        } catch (TransferException) {
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host']
             . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
