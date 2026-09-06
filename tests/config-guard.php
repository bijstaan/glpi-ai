<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Save and restore this plugin's configuration around a test suite.
 *
 * Every suite here configures the plugin to point at a mock, and used to put it
 * back with `Config::setConfigurationValues($before)` where `$before` came from
 * `Config::getConfigurationValues()`. That is wrong in a way that leaves no
 * trace until it is far too late.
 *
 * `getConfigurationValues()` does **not** decrypt. Secured entries come back as
 * ciphertext — and `setConfigurationValues()` encrypts what it is given. So
 * every "restore" wrapped the stored credential in another layer. Nothing
 * fails: the value is still a string, the page still renders, and the key just
 * quietly stops being the key. After a dozen runs the API key on this instance
 * was thirty-one kilobytes of nested ciphertext.
 *
 * So the snapshot is of the raw `glpi_configs` rows and the restore writes them
 * straight back. The one thing a restore has to do is put back exactly what was
 * there, and the config API is not a way to do that for anything encrypted.
 */

final class GlpiaiConfigGuard
{
    /** @return array<string,string> raw rows, exactly as stored */
    public static function snapshot(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_configs',
                'WHERE' => ['context' => PLUGIN_GLPIAI_CONFIG_CONTEXT],
            ]) as $row
        ) {
            $rows[(string) $row['name']] = (string) $row['value'];
        }

        return $rows;
    }

    /**
     * Put the configuration back byte for byte.
     *
     * Keys the suite introduced are dropped rather than left configured.
     *
     * @param array<string,string> $rows
     */
    public static function restore(array $rows): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($rows === []) {
            return;
        }

        $DB->delete('glpi_configs', ['context' => PLUGIN_GLPIAI_CONFIG_CONTEXT]);

        foreach ($rows as $name => $value) {
            $DB->insert('glpi_configs', [
                'context' => PLUGIN_GLPIAI_CONFIG_CONTEXT,
                'name'    => $name,
                'value'   => $value,
            ]);
        }
    }
}
