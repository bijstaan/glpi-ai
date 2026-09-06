// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * Save and restore the glpiai configuration around a browser check.
 *
 * These checks configure the plugin to point at a mock, and several of them
 * assert on as-installed state, so they reset it first. Both are fine on a
 * throwaway instance and destructive on one somebody is actually using — this
 * development instance has a real provider configured against a real Ollama
 * host, and losing it to a test run is not an acceptable cost of running tests.
 *
 * So every check snapshots first and restores last, whatever happened between.
 *
 * The snapshot is of the raw `glpi_configs` rows, and it is restored by writing
 * those rows back directly rather than through Config::setConfigurationValues().
 * That matters for the encrypted values: going back through the config API
 * means going through whatever GLPI does to secured entries on write, and the
 * one thing a restore must do is put back exactly what was there.
 */
const { execSync } = require('child_process');

const CONTAINER = 'glpi-glpi-1';
const CONTEXT = 'plugin:glpiai';

const php = (code) =>
  execSync(`docker exec -i ${CONTAINER} php`, {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + code,
  }).trim();

/** @returns {string} an opaque snapshot to hand back to restore() */
function snapshot() {
  return php(`
    global $DB;
    $rows = [];
    foreach ($DB->request(["FROM" => "glpi_configs", "WHERE" => ["context" => "${CONTEXT}"]]) as $r) {
        $rows[$r["name"]] = $r["value"];
    }
    echo base64_encode(json_encode($rows));
  `);
}

/**
 * Put the configuration back exactly as it was.
 *
 * Keys created since the snapshot are removed rather than left behind: a test
 * that introduced a setting should not leave it configured. Everything else is
 * written back verbatim.
 */
function restore(saved) {
  if (!saved) {
    return;
  }

  php(`
    global $DB;
    $rows = json_decode(base64_decode("${saved}"), true);
    if (!is_array($rows)) { echo "no snapshot"; return; }

    $DB->delete("glpi_configs", ["context" => "${CONTEXT}"]);

    foreach ($rows as $name => $value) {
        $DB->insert("glpi_configs", [
            "context" => "${CONTEXT}",
            "name"    => $name,
            "value"   => $value,
        ]);
    }

    echo "restored " . count($rows) . " keys";
  `);
}

/**
 * Reset to as-installed, for the checks that assert on a fresh plugin.
 *
 * Only ever safe between a snapshot() and its restore().
 */
function reset() {
  php(`
    (new Plugin())->init(true);
    Config::deleteConfigurationValues("${CONTEXT}", GlpiPlugin\\Glpiai\\Settings::allKeys());
    Config::setConfigurationValues("${CONTEXT}", GlpiPlugin\\Glpiai\\Settings::DEFAULTS);
    global $DB;
    $DB->delete("glpi_plugin_glpiai_triages", [1]);
    $DB->delete("glpi_plugin_glpiai_drafts", [1]);
    $DB->delete("glpi_plugin_glpiai_threads", [1]);
    echo "reset";
  `);
}

module.exports = { snapshot, restore, reset };
