<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One MCP server.
 *
 * The "Discover tools" action lives here rather than on the list, because it is
 * the thing that turns a saved row into something the model can actually use
 * and it needs to be one click from the fields you just corrected.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Mcp\OAuth;
use GlpiPlugin\Glpiai\Mcp\Server;

Session::checkRight('plugin_glpiai_config', READ);

$server = new Server();

if (!empty($_POST['add'])) {
    $server->check(-1, CREATE, $_POST);
    $newid = $server->add($_POST);

    // Back to the form on a rejection, not on to `?id=0`. A refused add has
    // already queued the reason as a session message, and redirecting to an id
    // that does not exist renders an error page that swallows it — so the
    // administrator sees "an unexpected error occurred" instead of "that URL
    // must use https".
    if ($newid === false) {
        Html::back();
    }

    Html::redirect($server->getFormURLWithID($newid));
} elseif (!empty($_POST['update'])) {
    $server->check($_POST['id'], UPDATE);
    $server->update($_POST);
    Html::back();
} elseif (!empty($_POST['purge'])) {
    $server->check($_POST['id'], PURGE);
    $server->delete($_POST, true);
    $server->redirectToList();
} elseif (!empty($_POST['discover'])) {
    // Discovery talks to a third party with stored credentials, so it takes the
    // right that owns those credentials rather than the right to view the page.
    $server->check($_POST['id'], UPDATE);
    $result = $server->discover();

    Session::addMessageAfterRedirect(
        htmlspecialchars($result['message']),
        false,
        $result['ok'] ? INFO : ERROR
    );
    Html::back();
} elseif (!empty($_POST['oauth_discover'])) {
    // Discovery is unauthenticated — it reads public metadata documents — but
    // it writes endpoints onto the record, so it takes the update right.
    $server->check($_POST['id'], UPDATE);
    $server->getFromDB((int) $_POST['id']);

    $found = OAuth::discover((string) $server->fields['url']);

    if (isset($found['token_endpoint'])) {
        $server->update([
            'id'              => (int) $server->getID(),
            'oauth_token_url' => $found['token_endpoint'],
            'oauth_auth_url'  => $found['authorization_endpoint'] ?? '',
        ]);

        Session::addMessageAfterRedirect(
            htmlspecialchars(sprintf(
                __('Found the authorization server at %s.', 'glpiai'),
                $found['issuer'] ?? $found['token_endpoint']
            )),
            false,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(
            htmlspecialchars(__('That server does not publish OAuth metadata. Enter the endpoints '
                . 'by hand.', 'glpiai')),
            false,
            WARNING
        );
    }

    Html::back();
} elseif (!empty($_POST['oauth_authorize'])) {
    $server->check($_POST['id'], UPDATE);
    $server->getFromDB((int) $_POST['id']);

    try {
        // Absolute, because it leaves this instance and comes back: the
        // authorization server has to be told somewhere it can actually reach,
        // and it must match what was registered with them exactly.
        $redirect = $CFG_GLPI['url_base'] . $CFG_GLPI['root_doc']
                  . '/plugins/glpiai/front/mcp/oauth.php';

        Html::redirect(OAuth::begin($server, $redirect));
    } catch (AiException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        Html::back();
    }
} elseif (!empty($_POST['oauth_forget'])) {
    $server->check($_POST['id'], UPDATE);
    $server->getFromDB((int) $_POST['id']);

    OAuth::forget($server);

    Session::addMessageAfterRedirect(
        htmlspecialchars(__('The stored token was discarded. The next call will get a new one.', 'glpiai')),
        false,
        INFO
    );
    Html::back();
}

// GLPI derives the itemtype from the URL as `pluginglpiaimcpserver`, which does
// not resolve to a namespaced plugin class — so forcetab is dropped and both
// GLPI's own tab links and every post-edit redirect land on the wrong tab.
// Setting it explicitly is the same fix core applies in front/knowbaseitem.php.
if (isset($_GET['forcetab'])) {
    Session::setActiveTab(Server::class, $_GET['forcetab']);
}

Html::header(
    Server::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    // The itemtype, not 'plugins'. This is what makes GLPI render its own add
    // button and breadcrumb for the list — passing a menu key instead gave a
    // list of MCP servers with no way to add one, because the button comes from
    // whatever this argument names. Every other list page across these plugins
    // already passes its itemtype here.
    Server::class
);

$server->display(['id' => (int) ($_GET['id'] ?? -1)]);

Html::footer();
