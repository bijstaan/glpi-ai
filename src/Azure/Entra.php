<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Azure;

use Glpi\Cache\CacheManager;
use GlpiPlugin\Glpiai\AiException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\TransferException;

/**
 * A client-credentials token for a Microsoft Entra service principal.
 *
 * Kept apart from the provider that uses it rather than folded into it. Entra
 * authentication is one mechanism regardless of how many things here speak it,
 * and a second copy would be a second place to fix a scope, a second cache key,
 * and eventually a subtly different opinion about token expiry.
 */
final class Entra
{
    public const DEFAULT_AUTHORITY = 'https://login.microsoftonline.com';

    /**
     * Entra scope for the Azure OpenAI / Cognitive Services data plane.
     *
     * Configurable because Foundry resources sometimes want
     * `https://ai.azure.com/.default` instead, and because sovereign clouds use
     * entirely different audiences. Getting it wrong yields a token that
     * authenticates fine and is then rejected by the data plane with a 401,
     * which is a genuinely confusing failure to debug.
     */
    public const DEFAULT_SCOPE = 'https://cognitiveservices.azure.com/.default';

    /**
     * Fetch a token, or return the cached one.
     *
     * Cached until shortly before it expires. Not an optimisation to skip: a
     * token request per call would double the latency of everything, and Entra
     * throttles aggressively enough that a busy instance would start failing on
     * token acquisition rather than on anything to do with AI.
     *
     * The 60-second safety margin exists because the expiry is evaluated here
     * and enforced at Azure — any clock skew between the two lands inside that
     * window instead of producing an intermittent 401.
     *
     * @param array{authority?:string,tenant_id:string,client_id:string,client_secret:string,scope?:string} $config
     * @param string $reporter the id to attribute failures to, for the caller's error messages
     */
    public static function token(array $config, string $reporter): string
    {
        $authority = rtrim(($config['authority'] ?? '') ?: self::DEFAULT_AUTHORITY, '/');
        $tenant    = (string) ($config['tenant_id'] ?? '');
        $scope     = ($config['scope'] ?? '') ?: self::DEFAULT_SCOPE;

        // Keyed by everything that changes which principal we are: rotating the
        // secret or repointing the tenant must not serve the previous token.
        $cache_key = 'azure_token_' . hash('sha256', implode('|', [
            $authority,
            $tenant,
            (string) ($config['client_id'] ?? ''),
            $scope,
            (string) ($config['client_secret'] ?? ''),
        ]));

        $cache  = (new CacheManager())->getCacheInstance('plugin:glpiai');
        $cached = $cache->get($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = (new HttpClient())->post(
                sprintf('%s/%s/oauth2/v2.0/token', $authority, rawurlencode($tenant)),
                [
                    'form_params' => [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => (string) ($config['client_id'] ?? ''),
                        'client_secret' => (string) ($config['client_secret'] ?? ''),
                        'scope'         => $scope,
                    ],
                    'timeout'     => 20,
                    'http_errors' => false,
                ]
            );
        } catch (TransferException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                'Could not reach Microsoft Entra: ' . $e->getMessage(),
                $reporter
            );
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);

        if ($status >= 400 || !is_array($decoded) || !isset($decoded['access_token'])) {
            // Entra's own error text is the useful part here — it distinguishes
            // a wrong secret from an unknown app from a tenant mismatch, none of
            // which look different from the outside.
            $detail = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '')
                : '';

            throw new AiException(
                AiException::AUTH,
                'Entra rejected the service principal. ' . mb_substr($detail, 0, 400),
                $reporter,
                $status
            );
        }

        $token = (string) $decoded['access_token'];
        $ttl   = max(60, (int) ($decoded['expires_in'] ?? 3600) - 60);
        $cache->set($cache_key, $token, $ttl);

        return $token;
    }

    /** Drop every cached token. Called when Azure settings are saved. */
    public static function forget(): void
    {
        (new CacheManager())->getCacheInstance('plugin:glpiai')->clear();
    }
}
