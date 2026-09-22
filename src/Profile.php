<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use CommonGLPI;
use Html;

/**
 * The plugin's rights, on the profile form.
 *
 * GLPI keeps a plugin's rights in glpi_profilerights alongside its own, and
 * Profile::prepareInputForUpdate() already writes back every right it finds
 * there — but core renders a form for its own rights only. Without a tab like
 * this one the rights are enforced on every page of the plugin and can be
 * changed by nothing but SQL, which from an administrator's chair is
 * indistinguishable from the plugin having no rights at all.
 *
 * The columns are the bits the enforcement code can actually test. A right
 * that names an itemtype gets that item's standard set, because CommonDBTM's
 * can* methods test each one; a right the code only ever reads directly gets
 * exactly the bits it is read with. A checkbox nothing consults is worse than
 * a missing one: it is how an administrator ends up certain they granted
 * something they did not.
 *
 * Nothing here writes to the database. The matrix posts back to core's own
 * profile form, which is what keeps the save path — history, entity checks,
 * the lot — identical to every other right in GLPI.
 */
class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('GLPI AI', 'glpiai');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof \Profile || !$item->getID() || !\Profile::canView()) {
            return '';
        }

        // Nothing to offer a helpdesk profile: every right here gates a
        // central-interface page, and a plugin right cannot gate a
        // self-service one at all.
        if (($item->fields['interface'] ?? '') === 'helpdesk') {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), 0, self::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile || !$item->getID()) {
            return false;
        }

        (new self())->showRights((int) $item->getID());

        return true;
    }

    /**
     * The rights this plugin owns, in the order they are shown.
     */
    private static function rights(): array
    {
        return [
            [
                'itemtype' => Mcp\Server::class,
                'label'    => __('Assistant, MCP servers and skills', 'glpiai'),
                'field'    => 'plugin_glpiai_config',
            ],
        ];
    }

    /**
     * The matrix itself, rendered against a freshly loaded profile.
     *
     * Profile::post_getFromDB() merges glpi_profilerights into the object's
     * fields, plugin rights included, so the current values come from the same
     * place the save writes them back to.
     */
    private function showRights(int $profiles_id): void
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = \Profile::canUpdate();

        echo "<div class='spaced'>";

        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::rights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='card-footer mx-n2 mb-n2 text-center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }

        echo "</div>";
    }
}
