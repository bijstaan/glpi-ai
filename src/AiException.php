<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * A provider failure, classified into the handful of kinds a caller can act on.
 *
 * Four vendors return four different error envelopes with four different status
 * conventions, and a feature that has to `str_contains` its way through them is
 * a feature that breaks whenever one of them rewords a message. What a caller
 * actually needs to decide is narrow: retry now, retry later, fix the config,
 * or give up — so that is what the classification carries.
 */
final class AiException extends \RuntimeException
{
    /** Credentials are wrong, expired, or absent. Retrying will not help. */
    public const AUTH = 'auth';

    /** Throttled or out of quota. Retrying later will help. */
    public const RATE_LIMIT = 'rate_limit';

    /** We built a bad request — wrong model name, malformed schema. A bug here, not there. */
    public const INVALID = 'invalid_request';

    /** The provider broke or is overloaded. Retrying may help. */
    public const SERVER = 'server';

    /** Never reached the provider: DNS, TLS, timeout, proxy. */
    public const TRANSPORT = 'transport';

    /** The model declined on policy grounds. Retrying the same prompt will not help. */
    public const REFUSED = 'refused';

    /** Our own configuration says this call may not be made at all. */
    public const DISABLED = 'disabled';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?string $provider = null,
        public readonly ?int $status = null,
        public readonly ?string $body = null
    ) {
        parent::__construct($message);
    }

    /**
     * Is another attempt worth making?
     *
     * Deliberately excludes AUTH and INVALID: both are configuration or code
     * defects, and retrying them just multiplies the same failure — while
     * looking, in a dashboard, like a flaky provider rather than a bug.
     */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::RATE_LIMIT, self::SERVER, self::TRANSPORT], true);
    }

    /** A message safe to show a technician: no keys, no tokens, no request body. */
    public function userMessage(): string
    {
        return match ($this->kind) {
            self::AUTH       => __('The AI provider rejected our credentials.', 'glpiai'),
            self::RATE_LIMIT => __('The AI provider is rate-limiting us. Try again shortly.', 'glpiai'),
            self::INVALID    => __('The AI provider rejected the request as malformed.', 'glpiai'),
            self::SERVER     => __('The AI provider is unavailable. Try again shortly.', 'glpiai'),
            self::TRANSPORT  => __('The AI provider could not be reached.', 'glpiai'),
            self::REFUSED    => __('The model declined to answer this request.', 'glpiai'),
            self::DISABLED   => __('AI features are not enabled here.', 'glpiai'),
            default          => __('The AI request failed.', 'glpiai'),
        };
    }
}
