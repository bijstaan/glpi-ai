<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use CommonDBTM;
use CronTask;
use Dropdown;
use GlpiPlugin\Glpiai\AiException;
use GLPIKey;
use Html;

/**
 * One configured MCP server.
 *
 * A GLPI item rather than a block of settings, because these are per-entity by
 * nature: connecting one monitoring server for one entity and a different one
 * for another needs those to be separate records with separate
 * credentials, not a list in a config field. Being an item also means the
 * entity restriction, the history, and the rights all come from GLPI.
 *
 * The discovered tool list is stored on the row rather than only in the cache.
 * A cached list vanishes on a cache flush and is refetched on whatever request
 * happens to be unlucky, which puts a third party's availability on the latency
 * path of an ordinary page load. Stored, it is refreshed deliberately — by the
 * button on the form, or by the cron task — and a server being down means
 * yesterday's tool list rather than a stalled request.
 */
class Server extends CommonDBTM
{
    public static $rightname = 'plugin_glpiai_config';

    /** Per-tool overrides. Absence of either means "use the annotations". */
    public const TOOL_READ  = 'read';
    public const TOOL_WRITE = 'write';

    public $dohistory = true;

    public const AUTH_NONE   = 'none';
    public const AUTH_BEARER = 'bearer';
    public const AUTH_HEADER = 'header';
    public const AUTH_OAUTH  = 'oauth2';

    /**
     * Whose credential this server is called with.
     *
     * `instance` is the historical behaviour and the right one for a server
     * whose data is the same for everybody: one credential, configured once,
     * used for every request.
     *
     * `user` is for a server that reaches something personal — a mailbox, a
     * drive, an issue tracker. Each technician connects their own account from
     * their preferences and their token is used only on their own requests.
     * There is no fallback between the two: a per-user server with no
     * connection refuses, rather than quietly using somebody else's.
     */
    public const IDENTITY_INSTANCE = 'instance';
    public const IDENTITY_USER     = 'user';

    public static function getTypeName($nb = 0)
    {
        return _n('MCP server', 'MCP servers', $nb, 'glpiai');
    }

    /**
     * The Setup-menu entry, and the Add button that comes with it.
     *
     * GLPI reads a list page's action links from the menu registry — see
     * templates/layout/parts/context_links.html.twig — so an itemtype with no
     * entry here gets a list it is impossible to add to. Naming the itemtype in
     * Html::header() is necessary but not sufficient: that argument only says
     * which entry to look up.
     */
    public static function getMenuContent()
    {
        // Backslashed: unqualified `Session` in this namespace is Mcp\Session,
        // the protocol client. Importing GLPI's global one to save four
        // characters silently repoints every other `Session::` in this file,
        // including `Session::PROTOCOL_VERSION`.
        if (!\Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/glpiai/front/mcp/server.php',
            'icon'  => self::getIcon(),
            'links' => [
                'search' => '/plugins/glpiai/front/mcp/server.php',
                'add'    => '/plugins/glpiai/front/mcp/server.form.php',
            ],
        ];
    }

    public static function getIcon()
    {
        return 'ti ti-plug';
    }

    /** Shown on GLPI's automatic-actions page, so the discovery job explains itself. */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'mcpdiscovery' => ['description' => __('Refresh MCP server tool lists', 'glpiai')],
            default        => [],
        };
    }

    /**
     * Refresh what each active server says it can do.
     *
     * Discovery is a cron job rather than something done lazily on the request
     * that needs it: a third party's availability has no business on the
     * latency path of a technician's page load, and doing it inside a tool loop
     * would repeat it several times over for one answer.
     *
     * The body lives on the class rather than in hook.php because GLPI loads a
     * plugin's hook file lazily and does not load it to run a cron task — a
     * global function here would work in the browser and fail from cron.
     */
    public static function cronMcpdiscovery(CronTask $task): int
    {
        $changed = 0;

        foreach (getAllDataFromTable(self::getTable(), ['is_active' => 1]) as $row) {
            $server = new self();
            if (!$server->getFromDB($row['id'])) {
                continue;
            }

            $before = $server->fields['tools_cache'];
            $result = $server->discover();

            $task->addVolume(1);
            $task->log(sprintf('%s: %s', $row['name'], $result['message']));

            // Only a change is interesting. A server answering the same list
            // every night is the normal case.
            if ($server->fields['tools_cache'] !== $before) {
                $changed++;
            }
        }

        return $changed > 0 ? 1 : 0;
    }

    /**
     * Sensible values on a blank form.
     *
     * Needed explicitly because the table is created by the install hook rather
     * than through GLPI's migration API, so `getEmpty()` has no column defaults
     * to read and every field would render empty — which for `timeout` means
     * posting an empty string into an integer column and a hard SQL error on
     * save.
     */
    public function post_getEmpty()
    {
        $this->fields['timeout']          = 30;
        $this->fields['is_active']        = 0;
        $this->fields['is_recursive']     = 1;
        $this->fields['auth_type']        = self::AUTH_NONE;
        $this->fields['protocol_version'] = '';
        // Offered by default: an administrator adding a server has decided they
        // want it used, and a server whose tools are never declared is
        // indistinguishable from one that does not work.
        $this->fields['always_offer']      = 1;
        $this->fields['trust_annotations'] = 0;
        $this->fields['oauth_grant']      = OAuth::CLIENT_CREDENTIALS;
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    /**
     * The headers this server's credentials produce.
     *
     * @return array<string,string>
     */
    public function authHeaders(): array
    {
        $grant = $this->grant();

        // OAuth first, because it mints its token rather than reading a stored
        // one — the early return below would otherwise send an unauthenticated
        // request for every server that has never been given a static token.
        if ((string) $this->fields['auth_type'] === self::AUTH_OAUTH) {
            return ['Authorization' => 'Bearer ' . OAuth::token($this, $grant)];
        }

        // A personal token, for a server that authenticates people without
        // speaking OAuth — an API key each technician generates for
        // themselves. Same rule as the OAuth case: theirs, or nothing.
        $token = $grant !== null ? $grant->secret('access_token') : $this->token();

        if ($token === '' && $grant !== null) {
            throw new AiException(
                AiException::AUTH,
                sprintf(
                    'You have not connected your own account to "%s" yet. Open My settings > '
                    . 'AI connections and add your token.',
                    (string) $this->fields['name']
                ),
                'mcp'
            );
        }

        if ($token === '') {
            return [];
        }

        return match ((string) $this->fields['auth_type']) {
            self::AUTH_BEARER => ['Authorization' => 'Bearer ' . $token],
            self::AUTH_HEADER => [((string) $this->fields['auth_header']) ?: 'X-Api-Key' => $token],
            default           => [],
        };
    }

    /**
     * Deleting a server takes every technician's credential for it.
     *
     * A grant whose server is gone is a stored token that nothing can use and
     * nobody can see in order to revoke it.
     */
    public function cleanDBonPurge()
    {
        Grant::forgetServer((int) $this->getID());
    }

    /** Does this server authenticate the person asking rather than the instance? */
    public function wantsUserAuth(): bool
    {
        return (string) ($this->fields['auth_identity'] ?? self::IDENTITY_INSTANCE) === self::IDENTITY_USER
            && (string) $this->fields['auth_type'] !== self::AUTH_NONE;
    }

    /**
     * The signed-in person's credential for this server, when it wants one.
     *
     * Null for an instance-authenticated server, which is the ordinary case and
     * means "use the server's own credential". For a per-user server it throws
     * rather than returning null when there is nobody signed in — that is the
     * cron path, and a background job silently using the last technician's
     * mailbox token is the exact failure this whole feature exists to prevent.
     *
     * @throws AiException
     */
    public function grant(): ?Grant
    {
        if (!$this->wantsUserAuth()) {
            return null;
        }

        if ((int) \Session::getLoginUserID() <= 0) {
            throw new AiException(
                AiException::AUTH,
                sprintf(
                    '"%s" is called with each person\'s own credentials, and this request has no '
                    . 'signed-in person — background work cannot use it.',
                    (string) $this->fields['name']
                ),
                'mcp'
            );
        }

        $grant = Grant::mine((int) $this->getID());

        if ($grant === null) {
            throw new AiException(
                AiException::AUTH,
                sprintf(
                    'You have not connected your own account to "%s" yet. Open My settings > '
                    . 'AI connections and press Connect.',
                    (string) $this->fields['name']
                ),
                'mcp'
            );
        }

        return $grant;
    }

    /**
     * The stored token, decrypted.
     *
     * A token that will not decrypt is treated as absent rather than passed
     * through: GLPI's key can be regenerated, and sending the ciphertext as a
     * bearer token produces a baffling 401 instead of an obvious "not
     * configured".
     */
    public function token(): string
    {
        return $this->secret('auth_token');
    }

    /**
     * Any of this record's encrypted columns, decrypted.
     *
     * A value that will not decrypt is treated as absent rather than passed
     * through: GLPI's key can be regenerated, and sending ciphertext as a
     * bearer token produces a baffling 401 instead of an obvious "not
     * configured".
     */
    public function secret(string $field): string
    {
        $raw = (string) ($this->fields[$field] ?? '');
        if ($raw === '') {
            return '';
        }

        $plain = (new GLPIKey())->decrypt($raw);

        return is_string($plain) ? $plain : '';
    }

    public function session(): Session
    {
        return new Session(
            (string) $this->fields['url'],
            $this->authHeaders(),
            max(5, (int) $this->fields['timeout']),
            ((string) $this->fields['protocol_version']) ?: Session::PROTOCOL_VERSION
        );
    }

    /**
     * Run something against this server, retrying once if the token was stale.
     *
     * OAuth::token() renews a minute before expiry, which covers the ordinary
     * case and not the interesting one: a token revoked or rotated at the other
     * end is still inside its stated lifetime and still refused. Without this,
     * every such server fails every request until its expiry passes — and the
     * symptom is a 401 from a credential the settings page reports as valid.
     *
     * Only for OAuth, and only once. A static credential that is refused will
     * be refused again, and retrying it would double every failed call.
     *
     * @template T
     * @param callable(Session):T $work
     * @return T
     * @throws AiException
     */
    public function call(callable $work): mixed
    {
        try {
            return $work($this->session());
        } catch (AiException $e) {
            if ($e->kind !== AiException::AUTH || (string) $this->fields['auth_type'] !== self::AUTH_OAUTH) {
                throw $e;
            }
        }

        OAuth::forget($this, $this->grant());

        return $work($this->session());
    }

    /**
     * The tools this server offered when it was last asked.
     *
     * @return array<int,array<string,mixed>>
     */
    public function discovered(): array
    {
        $decoded = json_decode((string) ($this->fields['tools_cache'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Which of the offered tools this server is permitted to expose.
     *
     * An empty allowlist means all of them. That is the opposite default to the
     * entity gate, and deliberately so: the entity gate is about data leaving,
     * where the unconsidered case must fail closed, while this is about a
     * server an administrator has already chosen to connect. Making them tick
     * every tool by hand would mean a server whose tools were quietly dropped
     * on its next release.
     *
     * @return string[] lowercase names, empty for "everything"
     */
    public function allowlist(): array
    {
        $raw = trim((string) ($this->fields['tool_allowlist'] ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Does this discovered tool count as a read on this server?
     *
     * The one place this is decided. Catalogue calls it when it builds the
     * tool and the form calls it to label what it shows, so what an
     * administrator reads on the page is what the runtime will actually do,
     * rather than a second description of the same rule that can drift.
     *
     * @param array<string,mixed> $declared one entry of the discovery cache
     */
    public function toolReadsOnly(array $declared): bool
    {
        $remote = (string) ($declared['name'] ?? '');

        // An explicit decision wins over everything below it, in both
        // directions. Annotations are optional in MCP and plenty of servers
        // ship none — Kagi is a search engine and says nothing about itself,
        // which under the annotation rule alone makes a search permanently a
        // write. Somebody who knows what the tool does can say so. The reverse
        // matters too: a tool that claims to be read-only can be pinned to
        // write by an administrator who does not believe it, without having to
        // distrust the whole server.
        $override = $this->overrideFor($remote);
        if ($override !== null) {
            return $override === self::TOOL_READ;
        }

        if (empty($this->fields['trust_annotations'])) {
            return false;
        }

        $annotations = $declared['annotations'] ?? null;
        if (!is_array($annotations) || !array_key_exists('readOnlyHint', $annotations)) {
            return false;
        }

        if (!empty($annotations['destructiveHint'])) {
            return false;
        }

        return $annotations['readOnlyHint'] === true;
    }

    /** The stored decision for one tool, or null to fall through to its annotations. */
    public function overrideFor(string $remote): ?string
    {
        $value = $this->overrides()[$remote] ?? null;

        return in_array($value, [self::TOOL_READ, self::TOOL_WRITE], true) ? $value : null;
    }

    /** @return array<string,string> remote tool name => TOOL_READ|TOOL_WRITE */
    public function overrides(): array
    {
        $decoded = json_decode((string) ($this->fields['tool_overrides'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function allows(string $tool): bool
    {
        $allowed = $this->allowlist();

        return $allowed === [] || in_array($tool, $allowed, true);
    }

    /**
     * Ask the server what it can do, and remember the answer.
     *
     * Records the failure on the row rather than throwing, because the two
     * callers — a settings page and a cron task — both want to show what went
     * wrong rather than stop.
     *
     * @return array{ok:bool,count:int,message:string}
     */
    public function discover(): array
    {
        try {
            $tools = $this->call(static fn(Session $s): array => $s->listTools());
        } catch (\Throwable $e) {
            $this->update([
                'id'         => $this->getID(),
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return ['ok' => false, 'count' => 0, 'message' => $e->getMessage()];
        }

        $this->update([
            'id'                 => $this->getID(),
            'tools_cache'        => json_encode(array_map(
                static fn(array $t): array => [
                    'name'        => (string) ($t['name'] ?? ''),
                    'description' => (string) ($t['description'] ?? $t['title'] ?? ''),
                    // Object, not `[]` — see Catalogue::forServer(). This one
                    // is cached to the database, so a wrong shape here outlives
                    // the request that wrote it.
                    'inputSchema' => $t['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                    // The tool's own behavioural hints, kept rather than
                    // dropped. `readOnlyHint` is what lets a server be trusted
                    // per tool instead of wholesale — without it the only
                    // choice was to call every tool on a server a write or none
                    // of them, and neither is true of a real server that mixes
                    // a search with a submit.
                    'annotations' => is_array($t['annotations'] ?? null) ? $t['annotations'] : null,
                ],
                $tools
            ), JSON_UNESCAPED_SLASHES),
            'date_lastdiscovery' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'last_error'         => '',
        ]);

        return [
            'ok'      => true,
            'count'   => count($tools),
            'message' => sprintf(_n('%d tool discovered.', '%d tools discovered.', count($tools), 'glpiai'), count($tools)),
        ];
    }

    /**
     * Encrypt the token on the way in, and treat the placeholder as "unchanged".
     *
     * Same contract as the provider credentials on the settings page, for the
     * same reason: the form never renders a stored secret, so an ordinary edit
     * posts the placeholder back and must not be read as "clear it".
     */
    public function prepareInputForUpdate($input)
    {
        return $this->prepareToken($input, true);
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareToken($input, false);
    }

    private function prepareToken(array $input, bool $updating): array|false
    {
        // Coerced rather than trusted. The form can post an empty string for a
        // number — a blank field on a new record does exactly that — and an
        // empty string into an INT column is a hard SQL error rather than a
        // validation message.
        if (array_key_exists('timeout', $input)) {
            $input['timeout'] = max(5, min(300, (int) $input['timeout'] ?: 30));
        }

        // Every secret column takes the same treatment: the placeholder means
        // "keep what you have", empty means "clear it", anything else is
        // encrypted on the way in. Written as a loop rather than four copies
        // because the copies are where one gets forgotten and a credential
        // lands in the table in plain text.
        foreach (['auth_token', 'oauth_client_secret'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $posted = trim((string) $input[$field]);

            if ($updating && $posted === \GlpiPlugin\Glpiai\Settings::SECRET_PLACEHOLDER) {
                unset($input[$field]);
            } elseif ($posted === '') {
                $input[$field] = '';
            } else {
                $input[$field] = (new GLPIKey())->encrypt($posted);
            }
        }

        // The per-tool decisions arrive as `overrides[<remote name>]`, one
        // select per row of the discovered-tools table. Only the two real
        // values are stored: anything else, including the blank "use the
        // annotations" option, means there is nothing to record — and recording
        // it would make an absent decision indistinguishable from a decision to
        // defer, which is the same thing said twice.
        if (array_key_exists('overrides', $input)) {
            $kept = [];
            foreach ((array) $input['overrides'] as $remote => $choice) {
                if (in_array($choice, [self::TOOL_READ, self::TOOL_WRITE], true)) {
                    $kept[(string) $remote] = (string) $choice;
                }
            }

            $input['tool_overrides'] = $kept === [] ? null : json_encode($kept, JSON_UNESCAPED_SLASHES);
            unset($input['overrides']);
        }

        // Anything that changes who we authenticate as invalidates the token we
        // are holding. Without this, correcting a client id leaves the old
        // token in place until it expires — and the correction appears to have
        // done nothing.
        if ($updating) {
            foreach (['oauth_client_id', 'oauth_client_secret', 'oauth_token_url', 'oauth_scope'] as $field) {
                if (array_key_exists($field, $input)) {
                    $input['oauth_access_token'] = '';
                    $input['oauth_expires_at']   = null;
                    break;
                }
            }
        }

        // Checked here rather than left to the unique index. A duplicate would
        // otherwise surface as an uncaught SQL exception — a 500 page, with the
        // whole INSERT including the encrypted token written to the error log.
        if (isset($input['name'])) {
            $clash = getAllDataFromTable(self::getTable(), [
                'name'        => $input['name'],
                'entities_id' => (int) ($input['entities_id'] ?? $this->fields['entities_id'] ?? 0),
            ]);

            foreach ($clash as $row) {
                if ((int) $row['id'] !== (int) ($input['id'] ?? 0)) {
                    \Session::addMessageAfterRedirect(
                        __s('Another MCP server in this entity already has that name.', 'glpiai'),
                        false,
                        ERROR
                    );

                    return false;
                }
            }
        }

        if (isset($input['url'])) {
            $url = trim((string) $input['url']);

            // http:// is refused rather than warned about. A bearer token on a
            // plaintext connection is a token that has been given away, and
            // "it is only the internal network" is how that ends up being true
            // of a VPN link a year later.
            if ($url !== '' && !str_starts_with(strtolower($url), 'https://')) {
                if (!self::isLoopback($url)) {
                    \Session::addMessageAfterRedirect(
                        __s('An MCP server URL must use https. Credentials sent over http are readable in transit.', 'glpiai'),
                        false,
                        ERROR
                    );

                    return false;
                }
            }

            $input['url'] = $url;
        }

        return $input;
    }

    /** Loopback is the one exception: nothing leaves the host, and it is how a bridge is run. */
    private static function isLoopback(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        // Scope wrapper: this form is core-rendered, so without a plugin-owned
        // container the shipped dark-theme CSS could never reach its helper
        // text (see the dark section of the plugin stylesheet).
        echo "<div class='glpiai-scope'>";
        $this->showFormHeader($options);

        $e         = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $row       = static fn(): string => "<tr class='tab_bg_1'>";
        $has_token = (string) ($this->fields['auth_token'] ?? '') !== '';

        echo $row() . '<td>' . __s('Name') . " <span class='text-red'>*</span></td><td>";
        echo Html::input('name', ['value' => $this->fields['name'], 'required' => 'required']);
        echo '</td>';
        echo '<td>' . __s('Active') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo '</td></tr>';

        echo $row() . '<td>' . __s('Endpoint', 'glpiai') . " <span class='text-red'>*</span></td><td colspan='3'>";
        echo Html::input('url', [
            'value'       => $this->fields['url'],
            'size'        => 80,
            'placeholder' => 'https://mcp.example.com/mcp',
            'required'    => 'required',
        ]);
        echo "<div class='form-text'>"
           . __s('The Streamable HTTP endpoint. stdio servers are not supported — put an HTTP bridge '
               . 'in front of one rather than having GLPI spawn a subprocess. https is required '
               . 'except on loopback.', 'glpiai')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Authentication', 'glpiai') . '</td><td>';
        Dropdown::showFromArray('auth_type', [
            self::AUTH_NONE   => __('None'),
            self::AUTH_BEARER => __('Bearer token', 'glpiai'),
            self::AUTH_HEADER => __('Custom header', 'glpiai'),
            self::AUTH_OAUTH  => __('OAuth 2.1', 'glpiai'),
        ], ['value' => $this->fields['auth_type']]);
        echo '</td>';
        echo '<td>' . __s('Header name', 'glpiai') . '</td><td>';
        echo Html::input('auth_header', [
            'value'       => $this->fields['auth_header'],
            'placeholder' => 'X-Api-Key',
        ]);
        echo "<div class='form-text'>" . __s('Only for custom-header authentication.', 'glpiai') . '</div>';
        echo '</td></tr>';

        echo $row() . '<td>' . __s('Token', 'glpiai') . '</td><td>';
        // The stored token is never rendered back; the placeholder stands in
        // for it, and posting it unchanged means "keep what you have".
        echo "<input type='password' class='form-control' name='auth_token' autocomplete='new-password' value='"
           . ($has_token ? $e(\GlpiPlugin\Glpiai\Settings::SECRET_PLACEHOLDER) : '') . "'>";
        echo "<div class='form-text'>" . __s('Stored encrypted with GLPI\'s key.', 'glpiai') . '</div>';
        echo '</td>';
        echo '<td>' . __s('Timeout (seconds)', 'glpiai') . '</td><td>';
        echo "<input type='number' min='5' max='300' class='form-control' name='timeout' value='"
           . $e($this->fields['timeout']) . "'>";
        echo '</td></tr>';

        // ------------------------------------------------------- whose account
        echo $row() . '<td>' . __s('Authenticate as', 'glpiai') . '</td><td>';
        Dropdown::showFromArray('auth_identity', [
            self::IDENTITY_INSTANCE => __('This GLPI instance', 'glpiai'),
            self::IDENTITY_USER     => __('Each technician, their own account', 'glpiai'),
        ], ['value' => $this->fields['auth_identity'] ?: self::IDENTITY_INSTANCE]);
        echo "<div class='form-text'>"
           . __s('Choose the second where the server reaches something personal — a mailbox, a '
               . 'drive, an issue tracker. Each technician then connects their own account from '
               . 'My settings > AI connections, their token is used only on their own requests, '
               . 'and nobody is asked to share one. There is no fallback: a technician who has '
               . 'not connected is told to, rather than quietly using somebody else\'s access.',
               'glpiai')
           . '</div></td>';
        echo '<td>' . __s('Who may use its tools', 'glpiai') . '</td><td>';
        echo "<span class='form-text'>"
           . ((string) ($this->fields['auth_identity'] ?? self::IDENTITY_INSTANCE) === self::IDENTITY_USER
               ? __s('Anyone who has connected their own account. Their own authorisation is the '
                   . 'permission — there is nothing shared to protect with a right.', 'glpiai')
               : __s('Holders of the AI configuration right, because every call uses this '
                   . 'instance\'s single shared credential.', 'glpiai'))
           . '</span></td></tr>';

        // ------------------------------------------------------------ OAuth
        //
        // Rendered whatever the chosen authentication is, rather than shown and
        // hidden by JavaScript. This form is a plain GLPI table; a block that
        // appears conditionally would be a block that is empty and confusing
        // when the page is reached with JavaScript half-loaded, and an
        // administrator who has already filled it in should be able to see it
        // while they decide whether to switch back.
        $is_oauth = (string) $this->fields['auth_type'] === self::AUTH_OAUTH;

        echo $row() . "<td colspan='4' class='" . ($is_oauth ? '' : 'text-muted') . "'>";
        echo '<strong>' . __s('OAuth 2.1', 'glpiai') . '</strong> ';
        echo "<span class='form-text'>"
           . ($is_oauth
               ? __s('Used for this server.', 'glpiai')
               : __s('Filled in but not used, because another authentication method is selected.',
                   'glpiai'))
           . '</span></td></tr>';

        echo $row() . '<td>' . __s('Grant', 'glpiai') . '</td><td>';
        Dropdown::showFromArray('oauth_grant', [
            OAuth::CLIENT_CREDENTIALS => __('Client credentials', 'glpiai'),
            OAuth::AUTHORIZATION_CODE => __('Authorization code', 'glpiai'),
        ], ['value' => $this->fields['oauth_grant'] ?: OAuth::CLIENT_CREDENTIALS]);
        echo "<div class='form-text'>"
           . __s('Client credentials is the one to want when GLPI is a server talking to another '
               . 'server: nothing has to be re-authorized when somebody leaves. Authorization '
               . 'code is for services that only issue user-delegated tokens — and is implied '
               . 'when this server authenticates as each technician, since there is no such '
               . 'thing as a per-person client-credentials token.', 'glpiai')
           . '</div></td>';
        echo '<td>' . __s('Scope', 'glpiai') . '</td><td>';
        echo Html::input('oauth_scope', [
            'value'       => $this->fields['oauth_scope'] ?? '',
            'placeholder' => 'mcp:tools',
            'size'        => 40,
        ]);
        echo '</td></tr>';

        echo $row() . '<td>' . __s('Client ID', 'glpiai') . '</td><td>';
        echo Html::input('oauth_client_id', ['value' => $this->fields['oauth_client_id'] ?? '', 'size' => 40]);
        echo '</td>';
        echo '<td>' . __s('Client secret', 'glpiai') . '</td><td>';
        echo "<input type='password' class='form-control' name='oauth_client_secret' "
           . "autocomplete='new-password' value='"
           . (trim((string) ($this->fields['oauth_client_secret'] ?? '')) !== ''
               ? $e(\GlpiPlugin\Glpiai\Settings::SECRET_PLACEHOLDER) : '') . "'>";
        echo '</td></tr>';

        echo $row() . '<td>' . __s('Authorization endpoint', 'glpiai') . '</td><td>';
        echo Html::input('oauth_auth_url', ['value' => $this->fields['oauth_auth_url'] ?? '', 'size' => 40]);
        echo "<div class='form-text'>"
           . __s('Only needed for the authorization-code grant.', 'glpiai') . '</div></td>';
        echo '<td>' . __s('Token endpoint', 'glpiai') . '</td><td>';
        echo Html::input('oauth_token_url', ['value' => $this->fields['oauth_token_url'] ?? '', 'size' => 40]);
        echo "<div class='form-text'>"
           . __s('Leave both empty and press Discover: an MCP server that follows the spec '
               . 'publishes where its authorization server is.', 'glpiai')
           . '</div></td></tr>';

        if (!$this->isNewItem()) {
            $expires = (string) ($this->fields['oauth_expires_at'] ?? '');
            $has_rt  = trim((string) ($this->fields['oauth_refresh_token'] ?? '')) !== '';

            echo $row() . '<td>' . __s('Token status', 'glpiai') . '</td><td colspan="3">';

            if ($expires !== '' && strtotime($expires) > time()) {
                echo "<span class='badge bg-green-lt'>"
                   . sprintf(__s('valid until %s', 'glpiai'), $e(Html::convDateTime($expires)))
                   . '</span> ';
            } elseif ($has_rt) {
                echo "<span class='badge bg-blue-lt'>"
                   . __s('authorized, will renew on next use', 'glpiai') . '</span> ';
            } else {
                echo "<span class='badge bg-secondary-lt'>" . __s('no token yet', 'glpiai') . '</span> ';
            }

            echo "<button type='submit' name='oauth_discover' value='1' "
               . "class='btn btn-sm btn-outline-secondary ms-1'>"
               . "<i class='ti ti-radar me-1'></i>" . __s('Discover endpoints', 'glpiai') . '</button> ';

            if ((string) ($this->fields['oauth_grant'] ?? '') === OAuth::AUTHORIZATION_CODE) {
                echo "<button type='submit' name='oauth_authorize' value='1' "
                   . "class='btn btn-sm btn-outline-primary ms-1'>"
                   . "<i class='ti ti-external-link me-1'></i>" . __s('Authorize', 'glpiai') . '</button> ';
            }

            echo "<button type='submit' name='oauth_forget' value='1' "
               . "class='btn btn-sm btn-ghost-secondary ms-1'>"
               . __s('Forget token', 'glpiai') . '</button>';

            echo "<div class='form-text'>"
               . __s('Tokens are refreshed a minute before they expire, so one never dies '
                   . 'mid-request.', 'glpiai')
               . '</div></td></tr>';
        }

        echo $row() . '<td>' . __s('Protocol version', 'glpiai') . '</td><td>';
        echo Html::input('protocol_version', [
            'value'       => $this->fields['protocol_version'],
            'placeholder' => Session::PROTOCOL_VERSION,
        ]);
        echo "<div class='form-text'>"
           . sprintf(__s('Leave empty for %s.', 'glpiai'), $e(Session::PROTOCOL_VERSION)) . '</div>';
        echo '</td>';
        echo '<td>' . __s('Tool allowlist', 'glpiai') . '</td><td>';
        echo Html::input('tool_allowlist', ['value' => $this->fields['tool_allowlist'], 'size' => 40]);
        echo "<div class='form-text'>"
           . __s('Comma-separated remote tool names. Empty offers every tool the server advertises.', 'glpiai')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Trust this server\'s tool annotations', 'glpiai') . '</td><td>';
        Dropdown::showYesNo('trust_annotations', $this->fields['trust_annotations']);
        echo "<div class='form-text'>"
           . __s('MCP tools declare whether they only read. The declaration comes from the server '
               . 'being asked about, so it is a claim and not proof — but it is made per tool, '
               . 'which means a server offering both a search and a submit can have the search '
               . 'used and the submit still held back. Say yes for a source you would take the '
               . 'word of. Say no and every tool here counts as a write, which is to say none of '
               . 'them run unless the instance-wide "Allow tools that change data" is on. What '
               . 'each tool claims is listed below.', 'glpiai')
           . '</div></td>';

        echo '<td>' . __s('Always offer these tools', 'glpiai') . '</td><td>';
        Dropdown::showYesNo('always_offer', $this->fields['always_offer']);
        echo "<div class='form-text'>"
           . __s('Yes declares this server\'s tools on every request. No leaves the model to '
               . 'find them by searching, which keeps the prompt small when many servers are '
               . 'connected — at the cost of the model never reaching for them unprompted.',
                 'glpiai')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Comments') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='comment' rows='2'>" . $e($this->fields['comment']) . '</textarea>';
        echo '</td></tr>';

        if (!$this->isNewID($ID)) {
            $this->showDiscovery();
        }

        $this->showFormButtons($options);
        echo '</div>';

        return true;
    }

    /**
     * What the server last told us it could do, and the button to ask again.
     *
     * Shown rather than hidden behind a tab because it is the only evidence
     * that a configuration works. Every other field on this form can be filled
     * in perfectly and still describe a server that is unreachable.
     */
    private function showDiscovery(): void
    {
        $e     = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $tools = $this->discovered();

        echo "<tr class='tab_bg_1'><td>" . __s('Tools', 'glpiai') . "</td><td colspan='3'>";

        echo "<button type='submit' name='discover' value='1' class='btn btn-sm btn-outline-secondary mb-2'>"
           . "<i class='ti ti-refresh me-1'></i>" . __s('Discover tools', 'glpiai') . '</button>';

        if (!empty($this->fields['last_error'])) {
            echo "<div class='alert alert-danger py-2'>" . $e($this->fields['last_error']) . '</div>';
        }

        if ($tools === []) {
            echo "<div class='text-muted'>"
               . __s('Nothing discovered yet. Save the server, then press Discover tools.', 'glpiai')
               . '</div>';
        } else {
            echo "<div class='small text-muted mb-2'>"
               . sprintf(
                   __s('%1$d offered, last checked %2$s. The model sees the prefixed names.', 'glpiai'),
                   count($tools),
                   $e(Html::convDateTime($this->fields['date_lastdiscovery']))
               )
               . '</div>';

            echo "<div class='small text-muted mb-2'>"
               . __s('Treat as decides whether a tool needs the instance-wide "Allow tools that '
                   . 'change data" switch. Left alone it follows what the tool declares, which '
                   . 'needs the trust setting above and only works for a server that annotates '
                   . 'at all — plenty do not. Set it yourself where you know what the tool does: '
                   . 'a search engine that says nothing about itself is still a search. These '
                   . 'survive re-discovery.', 'glpiai')
               . '</div>';

            echo "<table class='table table-sm'><thead><tr><th>" . __s('Remote name', 'glpiai')
               . '</th><th>' . __s('Name the model sees', 'glpiai') . '</th><th>'
               . __s('Declares', 'glpiai') . '</th><th>' . __s('Treat as', 'glpiai')
               . '</th><th>' . __s('Description') . '</th></tr></thead><tbody>';

            foreach ($tools as $tool) {
                $remote  = (string) ($tool['name'] ?? '');
                $allowed = $this->allows($remote);

                echo '<tr' . ($allowed ? '' : " class='text-muted'") . '>';
                echo '<td><code>' . $e($remote) . '</code>'
                   . ($allowed ? '' : " <span class='badge bg-secondary-lt'>" . __s('excluded', 'glpiai') . '</span>')
                   . '</td>';
                echo '<td><code>' . $e(Catalogue::localName($this, $remote)) . '</code></td>';

                // What the tool says about itself, and what this instance does
                // with that. Shown together on purpose: an administrator
                // deciding whether to trust a server is deciding about these
                // specific claims, and they should be able to read them first.
                echo '<td>';
                $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];

                if (!array_key_exists('readOnlyHint', $annotations)) {
                    echo "<span class='badge bg-secondary-lt'>" . __s('no annotation', 'glpiai') . '</span>';
                } elseif (!empty($annotations['destructiveHint'])) {
                    echo "<span class='badge bg-red-lt'>" . __s('destructive', 'glpiai') . '</span>';
                } elseif ($annotations['readOnlyHint'] === true) {
                    echo "<span class='badge bg-green-lt'>" . __s('read-only', 'glpiai') . '</span>';
                } else {
                    echo "<span class='badge bg-orange-lt'>" . __s('writes', 'glpiai') . '</span>';
                }

                if (!empty($annotations['idempotentHint'])) {
                    echo " <span class='badge bg-blue-lt'>" . __s('idempotent', 'glpiai') . '</span>';
                }

                echo '</td>';

                // The decision, and what it currently resolves to. A select
                // rather than a checkbox because there are three answers, and
                // "use whatever the server said" is the one most rows should
                // keep — collapsing it into an unticked box would make deferring
                // and forcing-a-write look identical.
                echo '<td>';
                Dropdown::showFromArray(
                    'overrides[' . $remote . ']',
                    [
                        ''               => __('Whatever it declares', 'glpiai'),
                        self::TOOL_READ  => __('A read', 'glpiai'),
                        self::TOOL_WRITE => __('A write', 'glpiai'),
                    ],
                    ['value' => (string) ($this->overrideFor($remote) ?? ''), 'width' => '160px']
                );

                $reads = $this->toolReadsOnly($tool);
                echo "<div class='form-text'>"
                   . ($reads
                       ? __s('runs without the instance-wide write switch', 'glpiai')
                       : __s('needs the instance-wide write switch', 'glpiai'))
                   . '</div>';
                echo '</td>';

                echo '<td>' . $e(mb_substr((string) ($tool['description'] ?? ''), 0, 160)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</td></tr>';
    }

    public function rawSearchOptions()
    {
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id'            => '1',
                'table'         => self::getTable(),
                'field'         => 'name',
                'name'          => __('Name'),
                'datatype'      => 'itemlink',
                'massiveaction' => false,
            ],
            [
                'id'       => '2',
                'table'    => self::getTable(),
                'field'    => 'url',
                'name'     => __('Endpoint', 'glpiai'),
                'datatype' => 'string',
            ],
            [
                'id'       => '3',
                'table'    => self::getTable(),
                'field'    => 'is_active',
                'name'     => __('Active'),
                'datatype' => 'bool',
            ],
            [
                'id'       => '4',
                'table'    => self::getTable(),
                'field'    => 'date_lastdiscovery',
                'name'     => __('Last discovery', 'glpiai'),
                'datatype' => 'datetime',
            ],
            [
                'id'       => '5',
                'table'    => self::getTable(),
                'field'    => 'last_error',
                'name'     => __('Last error', 'glpiai'),
                'datatype' => 'text',
            ],
            [
                'id'       => '80',
                'table'    => 'glpi_entities',
                'field'    => 'completename',
                'name'     => \Entity::getTypeName(1),
                'datatype' => 'dropdown',
            ],
        ];

        return $options;
    }
}
