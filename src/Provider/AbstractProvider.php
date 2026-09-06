<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Provider;

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Prompt;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;

/**
 * The plumbing every adapter shares: transport, error classification, and the
 * schema translation that makes "constrained JSON" mean the same thing on four
 * APIs that each spell it differently.
 */
abstract class AbstractProvider implements Provider
{
    /** @param array<string,string> $settings already-decrypted values for this provider */
    public function __construct(protected array $settings = [])
    {
    }

    protected function setting(string $name, string $default = ''): string
    {
        $value = trim((string) ($this->settings[$name] ?? ''));

        return $value !== '' ? $value : $default;
    }

    protected function flag(string $name): bool
    {
        return $this->setting($name) === '1';
    }

    /**
     * A setting's value, falling back to the default the field itself declares.
     *
     * Worth the lookup rather than repeating the literal at the point of use.
     * A default written twice is a default that can disagree with itself, and
     * this particular disagreement is close to invisible: the settings form
     * would offer one value while an unsaved — or partially saved —
     * configuration quietly used the other, so it would work for whoever set it
     * up and fail for whoever inherited it.
     */
    protected function declared(string $name): string
    {
        foreach (static::fields() as $field) {
            if ($field->name === $name) {
                return $this->setting($name, $field->default);
            }
        }

        return $this->setting($name);
    }

    /** Base URL with any trailing slash removed, so callers can concatenate paths safely. */
    protected function baseUrl(string $default): string
    {
        return rtrim($this->setting('base_url', $default), '/');
    }

    /**
     * The base URL with a version segment appended, without doubling it.
     *
     * Every one of these APIs carries its version in the path, and every vendor
     * documents the base *including* it — Ollama's own instructions say to use
     * `http://host:11434/v1`. Paste that and a naive concatenation produces
     * `/v1/v1/chat/completions`, which is a 404 with nothing in it to suggest
     * what went wrong. So a base that already ends in the segment about to be
     * added has it trimmed first.
     *
     * @param string $path the version-prefixed path, e.g. `/v1/chat/completions`
     */
    protected function endpointUrl(string $default, string $path): string
    {
        $base    = $this->baseUrl($default);
        $version = explode('/', ltrim($path, '/'))[0];

        if ($version !== '' && str_ends_with($base, '/' . $version)) {
            $base = substr($base, 0, -strlen($version) - 1);
        }

        return $base . $path;
    }

    public function modelFor(string $tier): string
    {
        return $tier === Prompt::TIER_QUALITY
            ? $this->setting('model_quality', $this->setting('model_fast'))
            : $this->setting('model_fast');
    }

    /** @param string[] $names */
    protected function hasAll(array $names): bool
    {
        foreach ($names as $name) {
            if ($this->setting($name) === '') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------- transport

    /**
     * POST JSON and return the decoded body.
     *
     * `http_errors` is off deliberately: Guzzle's own exception hierarchy is
     * organised around HTTP semantics, and what a caller here needs is the
     * *vendor's* classification, which only exists in the response body. Taking
     * the status and body ourselves is what lets one error type cover all four.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body
     * @return array<string,mixed>
     */
    protected function postJson(string $url, array $headers, array $body, int $timeout): array
    {
        try {
            $response = $this->http()->post($url, [
                'headers'     => $headers + ['Content-Type' => 'application/json'],
                'body'        => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'timeout'     => $timeout,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                'Could not reach ' . static::label() . ': ' . $e->getMessage(),
                static::id()
            );
        } catch (TransferException $e) {
            // Covers read timeouts, which are TransferException rather than
            // ConnectException — a slow model is the normal cause, so it is
            // reported as transport (retryable) and not as a provider fault.
            throw new AiException(
                AiException::TRANSPORT,
                static::label() . ' request failed: ' . $e->getMessage(),
                static::id()
            );
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status >= 400) {
            throw new AiException(
                $this->classify($status, $raw),
                $this->extractErrorMessage($raw) ?? (static::label() . ' returned HTTP ' . $status),
                static::id(),
                $status,
                mb_substr($raw, 0, 2000)
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new AiException(
                AiException::SERVER,
                static::label() . ' returned a non-JSON body.',
                static::id(),
                $status,
                mb_substr($raw, 0, 2000)
            );
        }

        return $decoded;
    }

    /**
     * POST JSON and read the answer back as server-sent events.
     *
     * The vendors' streaming endpoints all speak SSE, and all three that this
     * plugin streams from send one JSON object per `data:` line. What differs
     * is only what those objects mean, so the framing lives here and the
     * meaning stays in each adapter.
     *
     * Three details are worth naming, because each of them is a bug that took
     * a while to see:
     *
     *  - **`stream => true` on the Guzzle call, not just on the body.** Without
     *    it Guzzle buffers the whole response before returning, and the code
     *    below then "streams" a complete answer in one go — which works, passes
     *    a test, and shows the technician nothing until the end.
     *  - **`read_timeout`, not `timeout`.** Guzzle's `timeout` covers the whole
     *    transfer, and a streamed answer legitimately takes as long as the
     *    model does. What must not hang is a *silent* connection, which is what
     *    read_timeout bounds.
     *  - **A frame can be split across reads.** The buffer is carried between
     *    iterations and only whole lines are consumed, because a `data:` line
     *    cut in half is invalid JSON and dropping it loses a word of the answer
     *    at random.
     *
     * `[DONE]` is OpenAI's sentinel; the others simply end the stream. It is
     * recognised here rather than in each adapter so nobody has to remember.
     *
     * @param array<string,string>             $headers
     * @param array<string,mixed>              $body
     * @param callable(array<string,mixed>):void $onFrame decoded `data:` payloads
     */
    protected function postSse(
        string $url,
        array $headers,
        array $body,
        int $timeout,
        callable $onFrame
    ): void {
        try {
            $response = $this->http()->post($url, [
                'headers' => $headers + [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'text/event-stream',
                ],
                'body'         => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'stream'       => true,
                'read_timeout' => $timeout,
                'http_errors'  => false,
            ]);
        } catch (ConnectException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                'Could not reach ' . static::label() . ': ' . $e->getMessage(),
                static::id()
            );
        } catch (TransferException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                static::label() . ' request failed: ' . $e->getMessage(),
                static::id()
            );
        }

        $status = $response->getStatusCode();

        // An error arrives as an ordinary JSON body with an error status, not
        // as an event stream, so it is read whole and classified exactly as the
        // non-streaming path would classify it.
        if ($status >= 400) {
            $raw = (string) $response->getBody();

            throw new AiException(
                $this->classify($status, $raw),
                $this->extractErrorMessage($raw) ?? (static::label() . ' returned HTTP ' . $status),
                static::id(),
                $status,
                mb_substr($raw, 0, 2000)
            );
        }

        $stream = $response->getBody();
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                // Nothing yet. Guzzle returns an empty string on a stream that
                // is open but idle, and spinning on it burns a core for the
                // length of the answer.
                usleep(10000);
                continue;
            }

            $buffer .= $chunk;

            while (($break = strpos($buffer, "
")) !== false) {
                $line   = rtrim(substr($buffer, 0, $break), "
");
                $buffer = substr($buffer, $break + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    // Blank lines separate events; `event:` and `id:` lines
                    // carry framing this layer does not need, because every
                    // vendor also names the event inside the JSON.
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $onFrame($decoded);
                }
            }
        }
    }

    protected function http(): HttpClient
    {
        // GLPI ships Guzzle 7, so there is no dependency to vendor here. A
        // plugin that pulled its own HTTP stack into a GLPI install would be
        // risking a version clash with core for no benefit.
        return new HttpClient();
    }

    /**
     * Map an HTTP status onto the kind a caller can act on.
     *
     * Status alone is enough for every case that matters, which is fortunate:
     * the vendors' own error `type` strings share no vocabulary at all. The one
     * body inspection is for 400s, because "you sent nonsense" and "the model
     * refused you" are both 400 at some providers and mean opposite things —
     * one is our bug, the other is not retryable at all.
     */
    protected function classify(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return AiException::AUTH;
        }
        if ($status === 429) {
            return AiException::RATE_LIMIT;
        }
        if ($status >= 500) {
            return AiException::SERVER;
        }
        if ($status === 400 && preg_match('/content[_ ]filter|safety|blocked|refus/i', $body)) {
            return AiException::REFUSED;
        }

        return AiException::INVALID;
    }

    /** Dig the human-readable message out of whichever envelope this vendor uses. */
    protected function extractErrorMessage(string $raw): ?string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Anthropic/OpenAI: {"error": {"message": "..."}}. Gemini nests the same
        // way but one level deeper under a numeric code. Azure sometimes returns
        // {"error": {"code": "...", "message": "..."}} and sometimes a bare
        // {"message": "..."} from the API-management layer in front of it.
        foreach ([['error', 'message'], ['error', 'error', 'message'], ['message']] as $path) {
            $node = $decoded;
            foreach ($path as $key) {
                if (!is_array($node) || !isset($node[$key])) {
                    continue 2;
                }
                $node = $node[$key];
            }
            if (is_string($node) && $node !== '') {
                return mb_substr($node, 0, 500);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- schema

    /**
     * A schema in the strict dialect OpenAI (and Azure's OpenAI deployments)
     * require.
     *
     * Strict mode is not "JSON Schema plus a flag": it demands that every
     * object sets `additionalProperties: false` and lists *every* property in
     * `required` — optionality is expressed by making the type nullable
     * instead. Callers write ordinary schemas, so the conversion happens here
     * rather than in every caller, where it would be forgotten once and then
     * fail only for the one feature that forgot.
     */
    protected function strictSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;
            $properties                     = $schema['properties'] ?? [];
            if (is_array($properties)) {
                $schema['required'] = array_keys($properties);
                foreach ($properties as $name => $definition) {
                    if (is_array($definition)) {
                        $schema['properties'][$name] = $this->strictSchema($definition);
                    }
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->strictSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * A schema Gemini will accept.
     *
     * Gemini's `responseSchema` is an OpenAPI 3 subset, not JSON Schema: it
     * rejects `additionalProperties`, `$schema`, `$ref`, `const` and several
     * other perfectly ordinary keywords rather than ignoring them, and it wants
     * `type` upper-cased. So the same author-written schema has to be stripped
     * down here — the opposite transformation to strictSchema() above.
     *
     * This asymmetry is the honest cost of vendor-neutral structured output,
     * and the reason schemas used with this layer should stay simple: plain
     * types, plain nesting, `enum` where a value is constrained. Anything
     * exotic will survive on some providers and be rejected by others.
     */
    protected function geminiSchema(array $schema): array
    {
        $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'properties', 'items', 'required'];
        $out     = [];

        foreach ($schema as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $out[$key] = match ($key) {
                'type'       => strtoupper((string) $value),
                'properties' => array_map(
                    fn($definition) => is_array($definition) ? $this->geminiSchema($definition) : $definition,
                    (array) $value
                ),
                'items'      => is_array($value) ? $this->geminiSchema($value) : $value,
                default      => $value,
            };
        }

        return $out;
    }

    /**
     * Pull an object out of a text response.
     *
     * Even in constrained-output modes a provider occasionally wraps the JSON in
     * a markdown fence or a sentence of preamble, so the tolerant parse is not
     * defensive clutter — it is the difference between a feature that works and
     * one that fails on a minority of calls for reasons nobody can reproduce.
     */
    protected function decodeJson(string $text): ?array
    {
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}|\[.*\]/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
