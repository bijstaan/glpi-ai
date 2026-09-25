<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Mcp;

use CommonGLPI;
use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Url;
use Preference;
use Session;

/**
 * "AI connections", on a technician's own preferences page.
 *
 * A per-user MCP server needs each person to authorise their own account, and
 * the question is where they do it. Not the server form — that is
 * administrative, gated on the AI configuration right, and the people who need
 * to connect are precisely the ones who do not hold it. Not a menu entry of its
 * own either: a new item in Setup or Tools for something each person does once
 * is a permanent piece of furniture for an occasional act.
 *
 * GLPI already has the right place. *My settings* is where a person's own
 * preferences live, plugins may add a tab to it, and "which of my accounts is
 * GLPI allowed to use" belongs next to their language and their password.
 *
 * The tab lists only servers that ask for a personal credential, only in
 * entities the person can see, and only their own state. There is deliberately
 * no administrative view of who has connected: it would be a list of which
 * technicians have linked their own mailbox, which nobody needs and which
 * people would reasonably object to.
 */
class Connections extends CommonGLPI
{
    public static string $rightname = '';

    public static function getTypeName($nb = 0)
    {
        return _n('AI connection', 'AI connections', $nb, 'glpiai');
    }

    public static function getIcon()
    {
        return 'ti ti-plug-connected';
    }

    /**
     * The tab, when there is anything to put in it.
     *
     * Hidden entirely when no server asks for a personal credential — an empty
     * tab on everybody's preferences, on every instance that never configures
     * one, is clutter that has to be explained.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Preference || !Assistant::available()) {
            return '';
        }

        $servers = self::forMe();

        if ($servers === []) {
            return '';
        }

        $waiting = 0;
        foreach ($servers as $entry) {
            if (!$entry['connected']) {
                $waiting++;
            }
        }

        // The count is of what is *not* connected, because that is the only
        // number that asks somebody to do something.
        return $waiting > 0
            ? self::createTabEntry(self::getTypeName(2), $waiting)
            : self::createTabEntry(self::getTypeName(2));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            self::show();
        }

        return true;
    }

    /**
     * Servers that want this person's own credential, with their state.
     *
     * Entity-scoped the same way the tool catalogue is, so somebody only sees
     * the connections that would actually be used for the work they can see.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forMe(): array
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return [];
        }

        $seen = [];
        $out  = [];

        foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $entities_id) {
            foreach (Catalogue::serversFor((int) $entities_id) as $server) {
                $id = (int) $server->getID();

                if (isset($seen[$id]) || !$server->wantsUserAuth()) {
                    continue;
                }

                $seen[$id] = true;

                $grant = Grant::mine($id);

                $out[] = [
                    'server'     => $server,
                    'grant'      => $grant,
                    'connected'  => $grant !== null && $grant->isConnected(),
                    // OAuth sends them to the provider; anything else means
                    // pasting a token they generated themselves.
                    'oauth'      => (string) $server->fields['auth_type'] === Server::AUTH_OAUTH,
                ];
            }
        }

        usort($out, static fn(array $a, array $b): int
            => strcmp((string) $a['server']->fields['name'], (string) $b['server']->fields['name']));

        return $out;
    }

    /** How many of this person's connections are still waiting to be made. */
    public static function outstanding(): int
    {
        return count(array_filter(self::forMe(), static fn(array $e): bool => !$e['connected']));
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function show(): void
    {
        $servers = self::forMe();

        echo "<div class='glpiai-scope container-fluid' style='max-width:960px'>";

        echo '<p class="text-muted">'
           . __s('These services are called with your own account rather than a shared one. '
               . 'Connecting authorises GLPI to reach only what you can already reach, and only '
               . 'while you are the one asking — nothing here runs in the background, and no '
               . 'other technician\'s questions use your access.', 'glpiai')
           . '</p>';

        if ($servers === []) {
            echo "<div class='alert alert-info'>"
               . __s('Nothing here needs your account.', 'glpiai') . '</div></div>';

            return;
        }

        echo "<table class='table'><thead><tr>";
        echo '<th>' . __s('Service', 'glpiai') . '</th>';
        echo '<th>' . __s('Status') . '</th>';
        echo '<th>' . __s('Last used', 'glpiai') . '</th>';
        echo "<th class='text-end'></th>";
        echo '</tr></thead><tbody>';

        $csrf = Session::getNewCSRFToken();

        foreach ($servers as $entry) {
            /** @var Server $server */
            $server = $entry['server'];
            $grant  = $entry['grant'];
            $id     = (int) $server->getID();

            echo '<tr>';
            echo '<td><strong>' . self::e($server->fields['name']) . '</strong>';
            $comment = trim((string) ($server->fields['comment'] ?? ''));
            if ($comment !== '') {
                echo "<div class='form-text'>" . self::e($comment) . '</div>';
            }
            echo '</td>';

            echo '<td>';
            if ($entry['connected']) {
                echo "<span class='badge bg-green-lt'>" . __s('Connected', 'glpiai') . '</span>';

                // The expiry is deliberately not presented as something to act
                // on: an expired access token renews itself from the refresh
                // token on the next call, and telling somebody their connection
                // expires in nine minutes invites them to reconnect for no
                // reason.
                if ($grant !== null && (string) ($grant->fields['scope'] ?? '') !== '') {
                    echo "<div class='form-text'>" . self::e($grant->fields['scope']) . '</div>';
                }
            } else {
                echo "<span class='badge bg-secondary-lt'>" . __s('Not connected', 'glpiai') . '</span>';
                echo "<div class='form-text'>"
                   . __s('Its tools will refuse until you connect.', 'glpiai') . '</div>';
            }
            echo '</td>';

            echo '<td>';
            $used = (string) ($grant->fields['last_used_at'] ?? '');
            echo $used !== ''
                ? self::e(\Html::convDateTime($used))
                : "<span class='text-muted'>—</span>";
            echo '</td>';

            echo "<td class='text-end'>";
            echo "<form method='post' action='" . self::e(Url::to('front/mcp/connect.php')) . "' "
               . "class='d-inline-flex gap-2 align-items-center'>";
            echo \Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo \Html::hidden('servers_id', ['value' => $id]);

            if ($entry['oauth']) {
                echo "<button type='submit' name='connect' value='1' class='btn btn-sm "
                   . ($entry['connected'] ? 'btn-outline-secondary' : 'btn-primary') . "'>"
                   . ($entry['connected'] ? __s('Reconnect', 'glpiai') : __s('Connect', 'glpiai'))
                   . '</button>';
            } else {
                // A server that authenticates people without speaking OAuth:
                // the technician generates a token at the other end and pastes
                // it. Same storage, same scoping, no redirect.
                echo "<input type='password' class='form-control form-control-sm' name='token' "
                   . "autocomplete='new-password' placeholder='"
                   . self::e(__('Your own token', 'glpiai')) . "' style='width:14rem'>";
                echo "<button type='submit' name='save_token' value='1' class='btn btn-sm btn-primary'>"
                   . __s('Save') . '</button>';
            }

            if ($entry['connected']) {
                echo "<button type='submit' name='disconnect' value='1' "
                   . "class='btn btn-sm btn-outline-danger'>"
                   . __s('Disconnect', 'glpiai') . '</button>';
            }

            echo '</form></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p class="form-text">'
           . __s('Disconnecting removes the token GLPI holds. It does not revoke anything at the '
               . 'other end — do that with the service itself if you need the access gone '
               . 'entirely.', 'glpiai')
           . '</p>';

        echo '</div>';
    }
}
