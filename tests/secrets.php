<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Reading a stored secret back when GLPI's encryption key is not what it was.
 *
 * `GLPIKey::decrypt()` has three failure modes and signals two of them alike. A
 * wrong key, and a value that was never encrypted, both come back as `''`. But a
 * **missing, unreadable or wrong-length `glpicrypt.key`** makes it return *its
 * own input* — the ciphertext — because with no key there is nothing useful it
 * can say. An `is_string()` guard does not catch that; the blob is a perfectly
 * good string.
 *
 * Passed on, it travels as the credential. Google reports an unrecognised
 * `x-goog-api-key` as "Expected OAuth 2 access token, login cookie or other
 * valid authentication credential", which sends an administrator looking for an
 * OAuth setting that was never involved, on an instance whose configuration
 * nobody touched. The cause is nearly always a database restored onto another
 * instance without its key file.
 *
 * This asserts the reading is closed against that, using the real GLPIKey
 * pointed at a directory with no key in it. Nothing here touches the instance's
 * own key or configuration.
 *
 * Usage, inside the GLPI container:
 *   php tests/secrets.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Settings;

(new Plugin())->init(true);

$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name
       . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

$secret = 'AIzaSyD-EXAMPLE-not-a-real-key-000000000';
$cipher = (new GLPIKey())->encrypt($secret);

echo "\nGLPIKey behaviour this depends on\n";

check(
    'a secret encrypted and read back with the real key survives the round trip',
    Settings::decryptSecret($cipher) === $secret
);

// The instance this runs on has a key, so the failure has to be staged: an
// empty directory is what a restored database without its key file looks like.
$nokey = sys_get_temp_dir() . '/glpiai-nokey-' . getmypid();
mkdir($nokey, 0700, true);

$passthrough = @(new GLPIKey($nokey))->decrypt($cipher);
check(
    'decrypt() returns its input unchanged when the key file is missing',
    $passthrough === $cipher,
    'this is the behaviour the guard exists for — if it ever changes, simplify the guard'
);
check(
    'so an is_string() guard alone would pass the ciphertext through',
    is_string($passthrough) && $passthrough !== '',
    'the bug this test is here to prevent coming back'
);

rmdir($nokey);

echo "\nSettings::decryptSecret()\n";

check(
    'a value that comes back unchanged is treated as absent',
    Settings::decryptSecret($cipher) !== $cipher
);
check(
    'a value that was never encrypted is treated as absent',
    Settings::decryptSecret('plain-text-leftover') === ''
);
check(
    'an empty value is treated as absent',
    Settings::decryptSecret('') === ''
);
check(
    'a real secret is still returned in the clear',
    Settings::decryptSecret($cipher) === $secret
);

echo "\nSettings::cryptKeyAvailable()\n";

check(
    'reports true on an instance whose key is readable',
    Settings::cryptKeyAvailable() === true,
    'if this fails, this instance has lost its key and every stored secret with it'
);

echo "\n" . ($failures === []
    ? "\033[32mAll secret-reading checks passed.\033[0m\n"
    : "\033[31m" . count($failures) . " failed:\033[0m " . implode(', ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
