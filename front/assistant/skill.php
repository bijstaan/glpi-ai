<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The assistant skill library.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\Assistant\Skill;

Session::checkRight(Skill::$rightname, READ);

// The itemtype, not a menu key: GLPI looks the page's Add button up under
// whatever this names.
Html::header(
    Skill::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    Skill::class
);

Search::show(Skill::class);

Html::footer();
