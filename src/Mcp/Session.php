<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use GlpiPlugin\Glpiai\AiException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\TransferException;

/**
 * One conversation with an MCP server, over Streamable HTTP.
 *
 * MCP is JSON-RPC 2.0 with a handshake and a session, and this implements the
 * subset that tool use needs: `initialize`, the `initialized` notification,
 * `tools/list` and `tools/call`. Resources, prompts, sampling, roots and
 * server-initiated requests are all deliberately absent — a client that
 * advertises capabilities it does not implement is worse than one that
 * advertises none, because the server will then use them.
 *
 * **Streamable HTTP only.** The other transport MCP defines is stdio, which
 * means a plugin spawning a subprocess from inside a PHP-FPM worker, on a
 * request that a technician is waiting for, with the web server's filesystem
 * rights. That is a bad idea in a way that no amount of care fixes; the answer
 * for a stdio-only server is to put a bridge in front of it, not to spawn it
 * from GLPI.
 *
 * A server may answer either `application/json` or `text/event-stream` for the
 * same request — the spec leaves it to the server — so both are handled. Only
 * the first message of a stream is read: streaming exists for progress
 * notifications and partial results, and neither is useful when the caller is a
 * single blocking tool call.
 */
final class Session
{
    /**
     * The protocol revision this client speaks.
     *
     * Sent on the handshake and, from that point, as a header on every request.
     * Pinned rather than "latest" for the same reason the Anthropic API version
     * is: a dated contract that floats is a breaking change on someone else's
     * schedule.
     */
    public const PROTOCOL_VERSION = '2025-06-18';

    private ?string $session_id = null;

    private bool $ready = false;

    private int $next_id = 1;

    /** @var array<string,mixed> whatever the server said it could do */
    public array $capabilities = [];

    /** @var array<string,mixed> */
    public array $server_info = [];

    public function __construct(
        private readonly string $url,
        /** @var array<string,string> extra headers, typically Authorization */
        private readonly array $auth_headers = [],
        private readonly int $timeout = 30,
        private readonly string $protocol_version = self::PROTOCOL_VERSION
    ) {
    }

    /**
     * Handshake, if it has not happened yet.
     *
     * Idempotent, because every public method calls it: a caller should not have
     * to remember the protocol's ordering rules to list a server's tools.
     */
    public function connect(): void
    {
        if ($this->ready) {
            return;
        }

        $result = $this->request('initialize', [
            'protocolVersion' => $this->protocol_version,
            // Honestly empty. This client consumes tools and offers the server
            // nothing back.
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'glpi-ai', 'version' => PLUGIN_GLPIAI_VERSION],
        ]);

        $this->capabilities = (array) ($result['capabilities'] ?? []);
        $this->server_info  = (array) ($result['serverInfo'] ?? []);

        $agreed = (string) ($result['protocolVersion'] ?? '');
        if ($agreed !== '' && $agreed !== $this->protocol_version) {
            // Not fatal. Version negotiation is the server's prerogative, and
            // the parts used here have been stable across revisions — but it is
            // worth surfacing when a call later fails for no obvious reason.
            trigger_error(
                sprintf(
                    'glpiai: MCP server at %s negotiated protocol %s, not %s.',
                    $this->url,
                    $agreed,
                    $this->protocol_version
                ),
                E_USER_NOTICE
            );
        }

        // Required by the spec before any other request, and it is a
        // notification: no id, and no response to wait for.
        $this->notify('notifications/initialized');
        $this->ready = true;
    }

    /**
     * Every tool the server offers.
     *
     * Follows `nextCursor` to the end. The page limit is a guard against a
     * server that returns a cursor pointing at itself, which would otherwise
     * spin until the PHP time limit rather than failing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listTools(int $max_pages = 20): array
    {
        $this->connect();

        $tools  = [];
        $cursor = null;

        for ($page = 0; $page < $max_pages; $page++) {
            $result = $this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);

            foreach ((array) ($result['tools'] ?? []) as $tool) {
                if (is_array($tool) && ($tool['name'] ?? '') !== '') {
                    $tools[] = $tool;
                }
            }

            $next = $result['nextCursor'] ?? null;
            if (!is_string($next) || $next === '' || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $tools;
    }

    /**
     * Call a tool.
     *
     * Returns the flattened text, and a flag for whether the server considered
     * it a failure. Note that MCP has two distinct failure channels: a
     * JSON-RPC error means the *call* was malformed and is raised here, while
     * `isError` on a successful response means the tool ran and did not like
     * what it was asked. The second is a result the model should see and
     * respond to, not an exception.
     *
     * @param array<string,mixed> $arguments
     * @return array{text:string,is_error:bool,raw:array}
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->connect();

        $result = $this->request('tools/call', [
            'name'      => $name,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ]);

        return [
            'text'     => $this->flatten($result),
            'is_error' => (bool) ($result['isError'] ?? false),
            'raw'      => $result,
        ];
    }

    /**
     * Content blocks as one string.
     *
     * Only text survives. MCP content can also be images, audio, or embedded
     * resources, and a tool result is being fed to a text completion — so the
     * honest thing is to say what was dropped rather than silently return an
     * empty string for a server that answered entirely in images.
     */
    private function flatten(array $result): string
    {
        // Servers implementing the newer structured output put the useful
        // payload here and leave `content` as a rendering of it.
        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return (string) json_encode($result['structuredContent'], JSON_UNESCAPED_SLASHES);
        }

        $parts   = [];
        $skipped = [];

        foreach ((array) ($result['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
                continue;
            }

            if (($block['type'] ?? '') === 'resource' && isset($block['resource']['text'])) {
                $parts[] = (string) $block['resource']['text'];
                continue;
            }

            $skipped[] = (string) ($block['type'] ?? 'unknown');
        }

        if ($skipped !== []) {
            $parts[] = sprintf('[%s content omitted: this client is text-only]', implode(', ', array_unique($skipped)));
        }

        return trim(implode("\n", $parts));
    }

    // ------------------------------------------------------------ JSON-RPC

    /**
     * One request/response exchange.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed> the `result` object
     */
    private function request(string $method, array $params): array
    {
        $id = $this->next_id++;

        // `params` must encode as a JSON *object*. PHP's empty array encodes as
        // `[]`, and a server deserialising that into a parameter struct fails —
        // Microsoft Learn's server answers `tools/list` with `{"params":[]}` as
        // -32603 "An error occurred.", which reads like a fault at their end and
        // is not. Verified against https://learn.microsoft.com/api/mcp: the same
        // request with `{}` returns the tool list.
        //
        // callTool guards its nested `arguments` for the same reason; this
        // guards every method's params, including the no-argument ones that made
        // discovery fail against a perfectly healthy server.
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params === [] ? new \stdClass() : $params,
        ];

        $response = $this->post($payload);
        $decoded  = $this->decode($response, $method);

        if (isset($decoded['error'])) {
            throw new AiException(
                AiException::INVALID,
                sprintf(
                    'MCP server rejected %s: %s (code %s)',
                    $method,
                    (string) ($decoded['error']['message'] ?? 'unknown error'),
                    (string) ($decoded['error']['code'] ?? '?')
                ),
                'mcp'
            );
        }

        return (array) ($decoded['result'] ?? []);
    }

    /** A notification: no id, and the server answers 202 with no body. */
    private function notify(string $method): void
    {
        $this->post(['jsonrpc' => '2.0', 'method' => $method], true);
    }

    private function post(array $payload, bool $notification = false): \Psr\Http\Message\ResponseInterface
    {
        $headers = $this->auth_headers + [
            'Content-Type'        => 'application/json',
            // Both, because the server chooses which to answer with.
            'Accept'              => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $this->protocol_version,
        ];

        if ($this->session_id !== null) {
            $headers['Mcp-Session-Id'] = $this->session_id;
        }

        try {
            $response = (new HttpClient())->post($this->url, [
                'headers'         => $headers,
                'body'            => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'timeout'         => $this->timeout,
                'http_errors'     => false,
                // A server that redirects is a server whose URL is wrong. Left
                // on would silently POST credentials to wherever it points.
                'allow_redirects' => false,
            ]);
        } catch (TransferException $e) {
            throw new AiException(
                AiException::TRANSPORT,
                'Could not reach the MCP server: ' . $e->getMessage(),
                'mcp'
            );
        }

        // The server assigns a session on initialize and expects it back on
        // everything after.
        $assigned = $response->getHeaderLine('Mcp-Session-Id');
        if ($assigned !== '') {
            $this->session_id = $assigned;
        }

        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AiException(
                AiException::AUTH,
                'The MCP server rejected our credentials.',
                'mcp',
                $status
            );
        }

        if ($status >= 400) {
            throw new AiException(
                AiException::SERVER,
                sprintf('MCP server returned HTTP %d: %s', $status, mb_substr((string) $response->getBody(), 0, 300)),
                'mcp',
                $status
            );
        }

        if ($notification && $status !== 202 && $status !== 200) {
            trigger_error(
                sprintf('glpiai: MCP server answered %d to a notification.', $status),
                E_USER_NOTICE
            );
        }

        return $response;
    }

    /**
     * The JSON-RPC message out of whichever framing the server chose.
     *
     * @return array<string,mixed>
     */
    private function decode(\Psr\Http\Message\ResponseInterface $response, string $method): array
    {
        $body = (string) $response->getBody();

        if (str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $body = $this->firstEvent($body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new AiException(
                AiException::SERVER,
                sprintf('MCP server sent an unreadable response to %s.', $method),
                'mcp',
                $response->getStatusCode(),
                mb_substr($body, 0, 1000)
            );
        }

        // A batch response — legal in JSON-RPC, and this client never sends
        // batches, so the first element is the answer.
        if (array_is_list($decoded)) {
            $decoded = (array) ($decoded[0] ?? []);
        }

        return $decoded;
    }

    /**
     * The first `data:` payload in an SSE body.
     *
     * Only the first: the stream may continue with progress notifications, and
     * a blocking tool call has nothing to do with them. Multi-line data fields
     * are joined with newlines, as the SSE format requires.
     */
    private function firstEvent(string $body): string
    {
        $data = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
                continue;
            }

            // A blank line ends the event; anything collected so far is it.
            if (trim($line) === '' && $data !== []) {
                break;
            }
        }

        return implode("\n", $data);
    }
}
