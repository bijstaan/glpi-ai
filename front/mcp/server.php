<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The list of configured MCP servers.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\Mcp\Server;

Session::checkRight('plugin_glpiai_config', READ);

Html::header(
    Server::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    // The itemtype, not 'plugins'. This is what makes GLPI render its own add
    // button and breadcrumb for the list — passing a menu key instead gave a
    // list of MCP servers with no way to add one, because the button comes from
    // whatever this argument names. Every other list page across these plugins
    // already passes its itemtype here.
    Server::class
);

Search::show(Server::class);

Html::footer();
