<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use CommonDBTM;
use GLPIKey;
use Session;

/**
 * One person's credential for one MCP server.
 *
 * The existing OAuth support authenticates *the instance*: one token on the
 * server record, minted by an administrator, used for everybody. That is right
 * for a server that exposes shared data — a documentation index, a status
 * feed — and wrong for the interesting half of what MCP is being used for.
 *
 * A server that reaches somebody's mailbox, their drive, their issue tracker or
 * their notes cannot be authenticated once for the whole instance without
 * either giving every technician access to one person's account, or asking for
 * a service account with access to everyone's. Both are the wrong answer to a
 * question the protocol already answers properly: OAuth 2.1 authorization code
 * issues a token *for the person who consented*, and this is where those tokens
 * live.
 *
 * One row per (server, person). Nothing about a grant is visible to anybody
 * else: not the token, not the refresh token, not that it exists. There is no
 * "connected as" admin view of other people's grants and no right that grants
 * one, because a list of which technicians have connected their own mailbox is
 * not something an administrator needs and is something a person would
 * reasonably object to.
 *
 * **Both secrets are encrypted with GLPI's key**, and both columns are named in
 * `SECURED_FIELDS` so `glpi:security:changekey` re-encrypts them with
 * everything else. Encryption happens on write, explicitly: that hook governs
 * rotation, not storage, and a token written in plain text reads back as empty
 * rather than loudly wrong.
 */
class Grant extends CommonDBTM
{
    /**
     * Nobody administers these.
     *
     * A grant is created by the person it belongs to, from their own
     * preferences, and used only on their own requests. Every method here
     * derives the user from the session rather than taking one, so there is no
     * call that could read somebody else's — which is a stronger guarantee than
     * a right, and does not require inventing one.
     */
    public static $rightname = '';

    /** How long an in-flight authorization may sit unfinished. */
    public const PENDING_TTL = 600;

    public static function getTypeName($nb = 0)
    {
        return _n('AI connection', 'AI connections', $nb, 'glpiai');
    }

    public static function getIcon()
    {
        return 'ti ti-plug-connected';
    }

    /**
     * The signed-in person's grant for this server, or null.
     *
     * Null when nobody is signed in, deliberately and importantly: background
     * work — cron, the mail collector, a queue worker — has no person, and a
     * per-user server must be unavailable there rather than quietly borrowing
     * whichever grant happens to exist.
     */
    public static function mine(int $servers_id): ?self
    {
        $users_id = (int) Session::getLoginUserID();

        if ($users_id <= 0 || $servers_id <= 0) {
            return null;
        }

        $grant = new self();

        return $grant->getFromDBByCrit([
            'plugin_glpiai_mcps_servers_id' => $servers_id,
            'users_id'                      => $users_id,
        ]) ? $grant : null;
    }

    /** The signed-in person's grant, created empty if they have none yet. */
    public static function open(int $servers_id): ?self
    {
        $existing = self::mine($servers_id);
        if ($existing !== null) {
            return $existing;
        }

        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0) {
            return null;
        }

        $grant = new self();
        $id    = (int) $grant->add([
            'plugin_glpiai_mcps_servers_id' => $servers_id,
            'users_id'                      => $users_id,
            'date_creation'                 => date('Y-m-d H:i:s'),
        ]);

        return $id > 0 && $grant->getFromDB($id) ? $grant : null;
    }

    /** Is there a credential on this that could be used right now? */
    public function isConnected(): bool
    {
        return $this->secret('access_token') !== '' || $this->secret('refresh_token') !== '';
    }

    /** Has the access token passed its stated life? */
    public function isExpired(int $skew = 0): bool
    {
        $at = (string) ($this->fields['expires_at'] ?? '');

        return $at === '' || strtotime($at) - $skew <= time();
    }

    /**
     * One of this row's encrypted columns, decrypted.
     *
     * A value that will not decrypt is treated as absent, the same as on the
     * server record: GLPI's key can be regenerated, and sending ciphertext as a
     * bearer token produces a baffling 401 rather than an obvious "connect your
     * account".
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

    /**
     * Write a token onto this grant.
     *
     * A refresh token is only overwritten when one was returned — the same rule
     * the server-level store follows, and for the same reason: servers that
     * rotate refresh tokens send a new one every time, servers that do not send
     * nothing on a renewal, and blanking the stored one there breaks the
     * connection on its *second* renewal, a fortnight after anybody was
     * watching.
     */
    public function keep(string $access, ?string $refresh, int $expires_in, string $scope = ''): void
    {
        $key    = new GLPIKey();
        $values = [
            'id'           => (int) $this->getID(),
            'access_token' => $key->encrypt($access),
            'expires_at'   => date('Y-m-d H:i:s', time() + max(30, $expires_in)),
            'date_mod'     => date('Y-m-d H:i:s'),
        ];

        if ($refresh !== null && trim($refresh) !== '') {
            $values['refresh_token'] = $key->encrypt($refresh);
        }

        if ($scope !== '') {
            $values['scope'] = mb_substr($scope, 0, 500);
        }

        $this->update($values);
        $this->getFromDB((int) $this->getID());
    }

    /** Note that this connection was used, for the person's own list. */
    public function touch(): void
    {
        $this->update(['id' => (int) $this->getID(), 'last_used_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Stash an authorization in flight.
     *
     * On the grant rather than on the server, which is what makes two people
     * able to authorize at the same time. The server-level flow keeps its
     * pending state on the server row and can only ever have one; per-user, a
     * shared slot would mean the second technician to press Connect silently
     * taking over the first one's callback.
     *
     * @param array<string,mixed> $pending
     */
    public function beginPending(array $pending): void
    {
        $this->update([
            'id'      => (int) $this->getID(),
            'pending' => (new GLPIKey())->encrypt(json_encode($pending + ['at' => time()]) ?: ''),
        ]);
    }

    /**
     * Read and clear the pending authorization.
     *
     * Single-use: cleared whatever happens next, because a pending
     * authorization left in place lets a replayed callback be presented twice.
     *
     * @return array<string,mixed>|null
     */
    public function takePending(): ?array
    {
        $raw = (string) ($this->fields['pending'] ?? '');

        $this->update(['id' => (int) $this->getID(), 'pending' => '']);
        $this->fields['pending'] = '';

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode((string) (new GLPIKey())->decrypt($raw), true);

        if (!is_array($decoded) || time() - (int) ($decoded['at'] ?? 0) > self::PENDING_TTL) {
            return null;
        }

        return $decoded;
    }

    /**
     * Disconnect: forget the tokens, keep the row.
     *
     * The row stays so the list can still say "not connected" against a server
     * somebody has used before, and so `last_used_at` survives a reconnection.
     * Nothing usable survives it.
     */
    public function disconnect(): void
    {
        $this->update([
            'id'            => (int) $this->getID(),
            'access_token'  => '',
            'refresh_token' => '',
            'pending'       => '',
            'expires_at'    => null,
            'scope'         => '',
        ]);
    }

    /** Drop the access token only, so the next call renews it. */
    public function stale(): void
    {
        $this->update(['id' => (int) $this->getID(), 'access_token' => '', 'expires_at' => null]);
        $this->fields['access_token'] = '';
        $this->fields['expires_at']   = null;
    }

    /**
     * Everything belonging to a server that is being deleted.
     *
     * Called from the server's own purge. A grant whose server is gone is a
     * stored credential nothing can use and nobody can see to revoke.
     */
    public static function forgetServer(int $servers_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['plugin_glpiai_mcps_servers_id' => $servers_id]);
    }

    /**
     * Everything belonging to a person who is being deleted.
     *
     * The same argument from the other direction, and the one a data-protection
     * question is actually about: when somebody leaves, their delegated tokens
     * go with them.
     */
    public static function forgetUser(int $users_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['users_id' => $users_id]);
    }
}
