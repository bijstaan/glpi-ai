<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One assistant skill.
 */

require_once(__DIR__ . '/../../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\Assistant\Skill;

Session::checkRight(Skill::$rightname, READ);

$skill = new Skill();

if (!empty($_POST['add'])) {
    $skill->check(-1, CREATE, $_POST);
    $newid = $skill->add($_POST);

    // Back to the form on a rejection rather than on to an id that does not
    // exist, which renders an error page and swallows the reason.
    if ($newid === false) {
        Html::back();
    }

    Html::redirect($skill->getFormURLWithID($newid));
} elseif (!empty($_POST['update'])) {
    $skill->check($_POST['id'], UPDATE);
    $skill->update($_POST);
    Html::back();
} elseif (!empty($_POST['purge'])) {
    $skill->check($_POST['id'], PURGE);
    $skill->delete($_POST, true);
    $skill->redirectToList();
}

Html::header(
    Skill::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    Skill::class
);

$skill->display(['id' => (int) ($_GET['id'] ?? -1)]);

Html::footer();
