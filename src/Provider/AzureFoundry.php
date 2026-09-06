<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Azure\Entra;
use GlpiPlugin\Glpiai\Prompt;

/**
 * Azure AI Foundry, including Azure OpenAI deployments.
 *
 * Extends {@see OpenAi} because the request *body* is the same shape; what
 * differs is everything around it — where the request goes, and how it proves
 * who it is. Those are the two methods overridden here.
 *
 * Two URL styles, because Azure has two:
 *
 *  - `azure_openai`: the model is a **deployment name in the path**, and the
 *    body's `model` field is ignored. This is what most existing Azure OpenAI
 *    resources use.
 *  - `foundry`: a single `/models/chat/completions` route where the model is
 *    named in the body, as everywhere else.
 *
 * Getting this wrong produces a 404 rather than anything descriptive, so the
 * setting is explicit rather than guessed from the URL.
 *
 * Auth is either a flat API key or a Microsoft Entra service principal. The
 * service principal is the better answer for anything long-lived — the secret
 * can be rotated centrally, access is auditable per-principal, and the token
 * that actually travels is short-lived — so it is the default here.
 */
final class AzureFoundry extends OpenAi
{


    public static function id(): string
    {
        return 'azure';
    }

    public static function label(): string
    {
        return 'Azure AI Foundry';
    }

    public static function fields(): array
    {
        return [
            new Field(
                'base_url',
                __('Resource endpoint', 'glpiai'),
                Field::TEXT,
                __('Your Azure resource URL, without a trailing path.', 'glpiai'),
                placeholder: 'https://my-resource.openai.azure.com',
                required: true
            ),
            new Field(
                'api_style',
                __('Deployment style', 'glpiai'),
                Field::SELECT,
                __('Azure OpenAI puts the deployment name in the URL; Foundry model inference '
                    . 'names the model in the request body. The wrong choice returns 404.', 'glpiai'),
                default: 'azure_openai',
                options: [
                    'azure_openai' => __('Azure OpenAI deployment (name in URL)', 'glpiai'),
                    'foundry'      => __('Foundry model inference (name in body)', 'glpiai'),
                ]
            ),
            new Field(
                'api_version',
                __('API version', 'glpiai'),
                Field::TEXT,
                __('Azure requires an explicit api-version on every request.', 'glpiai'),
                default: '2024-10-21',
                required: true
            ),
            new Field(
                'auth_mode',
                __('Authentication', 'glpiai'),
                Field::SELECT,
                __('A service principal is preferable for anything long-lived: the secret rotates '
                    . 'centrally, access is auditable per-principal, and the token that travels is '
                    . 'short-lived.', 'glpiai'),
                default: 'service_principal',
                options: [
                    'service_principal' => __('Microsoft Entra service principal', 'glpiai'),
                    'api_key'           => __('API key', 'glpiai'),
                ]
            ),
            new Field('api_key', __('API key', 'glpiai'), Field::SECRET, __('Only for API-key authentication.', 'glpiai')),
            new Field('tenant_id', __('Directory (tenant) ID', 'glpiai'), Field::TEXT),
            new Field('client_id', __('Application (client) ID', 'glpiai'), Field::TEXT),
            new Field('client_secret', __('Client secret', 'glpiai'), Field::SECRET),
            new Field(
                'scope',
                __('Token scope', 'glpiai'),
                Field::TEXT,
                __('Leave empty for the Cognitive Services data plane. Some Foundry resources need '
                    . 'https://ai.azure.com/.default instead; a wrong scope yields a valid token '
                    . 'that the endpoint then rejects with 401.', 'glpiai'),
                placeholder: Entra::DEFAULT_SCOPE
            ),
            new Field(
                'authority',
                __('Entra authority', 'glpiai'),
                Field::TEXT,
                __('Override for sovereign clouds (US Government, China).', 'glpiai'),
                placeholder: Entra::DEFAULT_AUTHORITY
            ),
            new Field(
                'model_fast',
                __('Fast model / deployment', 'glpiai'),
                Field::TEXT,
                __('Deployment name, or model name for Foundry inference.', 'glpiai'),
                required: true
            ),
            new Field(
                'model_quality',
                __('Quality model / deployment', 'glpiai'),
                Field::TEXT,
                __('Falls back to the fast one if empty.', 'glpiai')
            ),
            new Field(
                'token_param',
                __('Output-limit parameter', 'glpiai'),
                Field::SELECT,
                __('Depends on the deployed model, not on Azure. Switch if requests are rejected '
                    . 'for using the wrong one.', 'glpiai'),
                default: 'max_tokens',
                options: [
                    'max_tokens'            => 'max_tokens',
                    'max_completion_tokens' => 'max_completion_tokens',
                ]
            ),
        ];
    }

    public function isConfigured(): bool
    {
        if (!$this->hasAll(['base_url', 'api_version', 'model_fast'])) {
            return false;
        }

        return $this->usesServicePrincipal()
            ? $this->hasAll(['tenant_id', 'client_id', 'client_secret'])
            : $this->hasAll(['api_key']);
    }

    private function usesServicePrincipal(): bool
    {
        return $this->declared('auth_mode') === 'service_principal';
    }

    // ------------------------------------------------------------------ URL

    protected function endpointFor(string $model): string
    {
        $base    = $this->baseUrl('');
        $version = rawurlencode($this->declared('api_version'));

        if ($this->declared('api_style') === 'foundry') {
            return sprintf('%s/models/chat/completions?api-version=%s', $base, $version);
        }

        return sprintf(
            '%s/openai/deployments/%s/chat/completions?api-version=%s',
            $base,
            rawurlencode($model),
            $version
        );
    }

    // ----------------------------------------------------------------- auth

    protected function authHeaders(): array
    {
        if (!$this->usesServicePrincipal()) {
            // Azure's own header, not a bearer token — sending this one as
            // Authorization is a silent 401.
            return ['api-key' => $this->setting('api_key')];
        }

        return ['Authorization' => 'Bearer ' . $this->accessToken()];
    }

    /**
     * A client-credentials token for the configured service principal.
     *
     * The mechanics live in {@see Entra}: token acquisition, caching and expiry
     * are one mechanism, and keeping them out of the adapter keeps this class
     * about translating requests.
     */
    private function accessToken(): string
    {
        return Entra::token([
            'authority'     => $this->setting('authority', Entra::DEFAULT_AUTHORITY),
            'tenant_id'     => $this->setting('tenant_id'),
            'client_id'     => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'scope'         => $this->setting('scope', Entra::DEFAULT_SCOPE),
        ], self::id());
    }

    /**
     * Forget any cached token.
     *
     * Called when the settings are saved: an administrator who has just
     * corrected the tenant or rotated the secret should see the effect on the
     * next connection test, not up to an hour later.
     */
    public function forgetToken(): void
    {
        Entra::forget();
    }

    protected function buildBody(Prompt $prompt, string $model): array
    {
        $body = parent::buildBody($prompt, $model);

        // In the Azure OpenAI style the deployment in the URL selects the
        // model, and a `model` key in the body is at best ignored — at worst
        // rejected by an API-management policy in front of the resource.
        if ($this->declared('api_style') !== 'foundry') {
            unset($body['model']);
        }

        return $body;
    }
}
