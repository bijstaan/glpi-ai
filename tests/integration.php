<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The half the adapter harness cannot reach: everything that needs GLPI itself.
 *
 * Settings persistence, secret encryption at rest, the tenant gate, and the
 * guards on Client::complete() — the four places where a mistake is not a
 * malformed request but a policy that silently does not apply. A gate that
 * fails open looks exactly like a gate that works, right up until somebody
 * audits it.
 *
 * The plugin's own configuration is snapshotted and restored, and the entities
 * it creates are purged, so a run leaves the dev environment as it found it.
 *
 * Usage, inside the GLPI container:
 *   php -S 127.0.0.1:9099 tests/mock-provider.php &
 *   php tests/integration.php
 */

require '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

require __DIR__ . '/config-guard.php';

use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Provider\Registry;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\UsageLog;

/** @var DBmysql $DB */
global $DB;

const MOCK    = 'http://127.0.0.1:9099';
const CONTEXT = PLUGIN_GLPIAI_CONFIG_CONTEXT;

// A real login, not a hand-built $_SESSION. Entity::add() silently discards its
// input without one — the tree columns never get computed — and the tenant gate
// is a claim about the entity tree, so testing it against a tree GLPI never
// actually built would prove nothing. It also gives the usage log a real user
// id to record, which is what a call from the UI would have.
if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi — is this the dev environment?\n");
    exit(1);
}

// Booting the kernel loads the plugin's classes but does not run its init hook,
// and this plugin's secrets are encrypted by GLPI on the strength of what that
// hook declares. Without this the credentials would be written in plaintext —
// which the tripwire in Settings::saveProvider() turns into a hard failure, so
// the omission would be loud rather than silent, but the tests below would be
// testing an environment no real request ever runs in.
(new Plugin())->init(true);

if (!(new GLPIKey())->isConfigSecured(CONTEXT, 'openai_api_key')) {
    fwrite(STDERR, "glpiai is not active — run: bin/console plugin:activate glpiai\n");
    exit(1);
}

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

/** Run $body and report which AiException kind, if any, came out. */
function kindOf(callable $body): string
{
    try {
        $body();

        return '(no exception)';
    } catch (AiException $e) {
        return $e->kind;
    }
}

// ------------------------------------------------------------------ snapshot

$before      = GlpiaiConfigGuard::snapshot();
$made_entity = [];

$restore = static function () use ($before, &$made_entity): void {
    // Raw rows, straight back. Restoring through the config API re-encrypts
    // anything secured, a layer per run — see tests/config-guard.php.
    GlpiaiConfigGuard::restore($before);

    // Reverse order: a parent cannot be purged while a child still points at it.
    foreach (array_reverse($made_entity) as $id) {
        (new Entity())->delete(['id' => $id], true);
    }
};

register_shutdown_function($restore);

// ------------------------------------------------------------------ entities

$entity = new Entity();

$parent = (int) $entity->add([
    'name'        => 'glpiai-test-client',
    'entities_id' => 0,
]);
$made_entity[] = $parent;

$child = (int) $entity->add([
    'name'        => 'glpiai-test-site',
    'entities_id' => $parent,
]);
$made_entity[] = $child;

$other = (int) $entity->add([
    'name'        => 'glpiai-test-other',
    'entities_id' => 0,
]);
$made_entity[] = $other;

echo "\nFixtures\n";
check('created a two-level entity tree and an unrelated entity',
    $parent > 0 && $child > 0 && $other > 0, "parent=$parent child=$child other=$other");

// ------------------------------------------------------------------ settings

echo "\nGeneral settings\n";

Settings::save(['enabled' => '1', 'timeout' => '45', 'log_prompts' => '0']);
$cfg = Settings::all();
check('a saved value reads back', (int) $cfg['enabled'] === 1 && (int) $cfg['timeout'] === 45);
check('typed defaults survive the round trip', is_int($cfg['timeout']));

Settings::save(['timeout' => '9000']);
check('an out-of-range timeout is clamped rather than rejected', Settings::get('timeout') === 300,
    (string) Settings::get('timeout'));
Settings::save(['timeout' => '1']);
check('a too-short timeout is clamped up', Settings::get('timeout') === 5);
Settings::save(['timeout' => '60']);

Settings::save(['nonsense' => 'x']);
check('unknown keys are ignored, not persisted',
    !array_key_exists('nonsense', Config::getConfigurationValues(CONTEXT)));

// -------------------------------------------------------------- secrets

echo "\nProvider secrets\n";

Settings::saveProvider('openai', [
    'api_key'     => 'sk-secret-value',
    'base_url'    => MOCK,
    'model_fast'  => 'mock-fast',
    'token_param' => 'max_tokens',
]);

$stored = Config::getConfigurationValues(CONTEXT, ['openai_api_key']);
$raw    = (string) ($stored['openai_api_key'] ?? '');

check('the key is not stored in plaintext', $raw !== '' && $raw !== 'sk-secret-value',
    mb_substr($raw, 0, 24) . '…');
check('the ciphertext decrypts back to the key',
    (Settings::forProvider('openai')['api_key'] ?? '') === 'sk-secret-value');
check('non-secret fields are stored as given',
    (Settings::forProvider('openai')['model_fast'] ?? '') === 'mock-fast');

// The form never renders a stored secret; posting the placeholder back has to
// mean "unchanged", or every unrelated save on the page would wipe every key.
Settings::saveProvider('openai', [
    'api_key'     => Settings::SECRET_PLACEHOLDER,
    'base_url'    => MOCK,
    'model_fast'  => 'renamed-model',
    'token_param' => 'max_tokens',
]);
check('posting the placeholder preserves the stored secret',
    (Settings::forProvider('openai')['api_key'] ?? '') === 'sk-secret-value');
check('the rest of the form still saved',
    (Settings::forProvider('openai')['model_fast'] ?? '') === 'renamed-model');

check('a field default fills in for a value never saved',
    (Settings::forProvider('gemini')['api_version'] ?? '') === 'v1beta');

// A key that cannot be decrypted must read as absent, not as ciphertext: GLPI's
// key can be regenerated, and forwarding the blob as a credential would produce
// a baffling 401 instead of an obvious "not configured".
// Written straight to the table: going through Config would encrypt it, which
// is the very thing being simulated as missing. Deleted first because the key
// may already exist as an empty row — saving the settings page writes every
// provider's fields, not just the active one's.
$DB->delete(Config::getTable(), ['context' => CONTEXT, 'name' => 'anthropic_api_key']);
$DB->insert(Config::getTable(), [
    'context' => CONTEXT,
    'name'    => 'anthropic_api_key',
    'value'   => 'not-actually-encrypted',
]);
check('an undecryptable secret reads as empty, not as ciphertext',
    @(Settings::forProvider('anthropic')['api_key'] ?? 'x') === '');
$DB->delete(Config::getTable(), ['context' => CONTEXT, 'name' => 'anthropic_api_key']);

Settings::saveProvider('openai', ['api_key' => '', 'base_url' => MOCK, 'model_fast' => 'renamed-model']);
check('an emptied secret field clears the stored secret',
    (Settings::forProvider('openai')['api_key'] ?? 'x') === '');

Settings::saveProvider('openai', [
    'api_key'     => 'sk-secret-value',
    'base_url'    => MOCK,
    'model_fast'  => 'mock-fast',
    'token_param' => 'max_tokens',
]);

// Counted from the registry rather than against a fixed number, so adding a
// vendor cannot make this pass by being forgotten *and* cannot make it fail by
// being remembered. What is actually under test is that nothing declared secret
// anywhere escapes the encrypted list.
$expected_secrets = 0;
foreach (Registry::all() as $class) {
    foreach ($class::fields() as $field) {
        $expected_secrets += $field->isSecret() ? 1 : 0;
    }
}
check('every secret field is declared to GLPI as a secured config',
    array_reduce(
        Settings::secretKeys(),
        static fn(bool $carry, string $key): bool
            => $carry && (new GLPIKey())->isConfigSecured(CONTEXT, $key),
        true
    ) && count(Settings::secretKeys()) === $expected_secrets,
    count(Settings::secretKeys()) . ' of ' . $expected_secrets . ': '
    . implode(', ', Settings::secretKeys()));

check('no non-secret field is declared secured',
    !(new GLPIKey())->isConfigSecured(CONTEXT, 'openai_base_url'));

// Config history records every change; a credential must not be legible there.
$history = $DB->request([
    'FROM'  => 'glpi_logs',
    'WHERE' => ['itemtype' => 'Config'],
    'ORDER' => 'id DESC',
    'LIMIT' => 20,
])->current();
check('the credential is masked in the change history',
    !str_contains(json_encode($history) ?: '', 'sk-secret-value'));

check('allKeys() covers every provider field, for a clean uninstall',
    in_array('azure_client_secret', Settings::allKeys(), true)
    && in_array('gemini_model_quality', Settings::allKeys(), true)
    && in_array('enabled', Settings::allKeys(), true));

// ----------------------------------------------------------------- the gate

echo "\nTenant gate\n";

Settings::save(['entity_mode' => 'allowlist', 'entities' => '']);
check('an empty allowlist permits nothing — including the root entity',
    !Settings::entityAllowed(0) && !Settings::entityAllowed($parent));

Settings::save(['entities' => (string) $parent]);
check('a permitted entity is permitted', Settings::entityAllowed($parent));
check('permitting a parent permits an entity beneath it', Settings::entityAllowed($child));
check('an unrelated entity stays denied', !Settings::entityAllowed($other));
check('the root entity is not implicitly permitted', !Settings::entityAllowed(0));

Settings::save(['entities' => (string) $child]);
check('permitting a child does not permit its parent', !Settings::entityAllowed($parent));

Settings::save(['entities' => " $parent , , $other "]);
check('a whitespace-and-empties list still parses',
    Settings::entityAllowed($parent) && Settings::entityAllowed($other));

Settings::save(['entity_mode' => 'all']);
check('"every entity" mode permits an entity that is on no list',
    Settings::entityAllowed($other) && Settings::entityAllowed(0));

Settings::save(['entity_mode' => 'allowlist', 'entities' => (string) $parent]);

// ------------------------------------------------------------------ the door

echo "\nClient guards\n";

Settings::save(['enabled' => '0', 'provider' => 'openai']);
check('the master switch is checked first',
    kindOf(static fn() => Client::complete(Prompt::make('hi'), $parent)) === AiException::DISABLED);

Settings::save(['enabled' => '1']);
check('a denied entity cannot call out',
    kindOf(static fn() => Client::complete(Prompt::make('hi'), $other)) === AiException::DISABLED);

Settings::save(['provider' => '']);
check('no configured provider is a clean refusal, not a fatal',
    kindOf(static fn() => Client::complete(Prompt::make('hi'), $parent)) === AiException::DISABLED);

Settings::save(['provider' => 'anthropic']);
check('a selected but unconfigured provider is refused',
    kindOf(static fn() => Client::complete(Prompt::make('hi'), $parent)) === AiException::DISABLED);

Settings::save(['provider' => 'openai']);
check('isReady() reports a usable configuration', Client::isReady());
Settings::save(['enabled' => '0']);
check('isReady() is false while the master switch is off', !Client::isReady());
Settings::save(['enabled' => '1']);

// ------------------------------------------------------------- the happy path

echo "\nA permitted call\n";

$DB->delete(UsageLog::TABLE, [1]);

$prompt          = Prompt::make('Classify this.', 'You are a probe.');
$prompt->timeout = 900;
$completion      = Client::complete($prompt, $child);

check('a permitted entity gets a completion', $completion->text === '{"ok":true}', $completion->text);
check('the configured timeout is a ceiling on the caller\'s', $prompt->timeout === 60,
    (string) $prompt->timeout);

$short          = Prompt::make('hi');
$short->timeout = 5;
Client::complete($short, $child);
check('a caller asking for less than the ceiling keeps its own budget', $short->timeout === 5);

$row = $DB->request(['FROM' => UsageLog::TABLE, 'ORDER' => 'id ASC'])->current();
check('the call was recorded', is_array($row) && $row['provider'] === 'openai', (string) ($row['provider'] ?? ''));
check('usage was recorded against the calling entity', (int) ($row['entities_id'] ?? -1) === $child);
check('token counts were recorded',
    (int) ($row['input_tokens'] ?? 0) === 10 && (int) ($row['output_tokens'] ?? 0) === 2);
check('the model that answered was recorded', ($row['model'] ?? '') === 'gpt-mock');
check('a duration was recorded', (int) ($row['duration_ms'] ?? -1) >= 0);
check('prompt text is not logged by default', ($row['prompt_text'] ?? null) === null);

Settings::save(['log_prompts' => '1']);
Client::complete(Prompt::make('Sensitive ticket text.', 'sys'), $child);
$logged = $DB->request(['FROM' => UsageLog::TABLE, 'ORDER' => 'id DESC'])->current();
check('prompt text is logged once asked for',
    str_contains((string) ($logged['prompt_text'] ?? ''), 'Sensitive ticket text.'));
check('the system instruction is logged with it',
    str_contains((string) ($logged['prompt_text'] ?? ''), '[system] sys'));
Settings::save(['log_prompts' => '0']);

echo "\nUsage log\n";

$summary = UsageLog::summary(date('Y-m-d', time() - 86400));
check('usage summarises by provider and model',
    count($summary) === 1 && (int) $summary[0]['calls'] === 3 && (int) $summary[0]['input'] === 30,
    json_encode($summary));

check('summarising by entity narrows the result',
    UsageLog::summary(date('Y-m-d', time() - 86400), $other) === []);

check('pruning with no retention window deletes nothing', UsageLog::prune(0) === 0);
check('pruning keeps rows inside the window', UsageLog::prune(30) === 0);

$DB->update(UsageLog::TABLE, ['date_creation' => date('Y-m-d H:i:s', time() - (90 * 86400))], [1]);
check('pruning drops rows outside it', UsageLog::prune(30) === 3);

$DB->delete(UsageLog::TABLE, [1]);

// A provider failure must not be swallowed into a successful-looking answer.
echo "\nProvider failure through the door\n";
Settings::saveProvider('openai', [
    'api_key'    => 'sk-secret-value',
    'base_url'   => 'http://127.0.0.1:9',
    'model_fast' => 'mock-fast',
]);
check('a transport failure propagates to the caller',
    kindOf(static fn() => Client::complete(Prompt::make('hi'), $child)) === AiException::TRANSPORT);
check('a failed call writes no usage row',
    (int) ($DB->request(['SELECT' => ['COUNT' => 'id AS n'], 'FROM' => UsageLog::TABLE])->current()['n'] ?? -1) === 0);

// ------------------------------------------------------------------- registry

echo "\nRegistry\n";
check('every provider declares a unique id', count(Registry::all()) === 4);
check('an unknown provider id degrades to null rather than fataling',
    Registry::build('some-vendor-we-dropped') === null);
check('every provider declares at least a key or an endpoint and a model',
    array_reduce(
        array_values(Registry::all()),
        static fn(bool $carry, string $class): bool => $carry
            && count(array_filter($class::fields(), static fn($f) => $f->required)) >= 2,
        true
    ));

echo "\n" . ($failures === []
    ? "\033[32mall checks passed\033[0m\n"
    : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

exit($failures === [] ? 0 : 1);
