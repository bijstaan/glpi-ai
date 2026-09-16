<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use Config;
use GLPIKey;
use GlpiPlugin\Glpiai\Provider\Field;
use GlpiPlugin\Glpiai\Provider\Registry;

/**
 * Plugin configuration, including provider credentials.
 *
 * Two things here are load-bearing rather than routine.
 *
 * **Secrets are encrypted at rest** with GLPI's own key, the same way core
 * stores the SMTP password. A provider key sitting in a config table in
 * plaintext is a key in every database backup, and backups travel.
 *
 * The encryption itself is core's, not ours: {@see secretKeys()} is declared
 * through the SECURED_CONFIGS hook, and Config::setConfigurationValues() then
 * encrypts those entries on the way in and masks them in the history log.
 * Doing it by hand here would work equally well right up until an administrator
 * rotated the key — `glpi:security:changekey` only re-encrypts what has been
 * declared, so a hand-rolled secret would be quietly orphaned. Reading is still
 * ours to do: core encrypts on write but hands back the ciphertext on read.
 *
 * **The entity gate is a first-class setting, not a feature flag.** This plugin
 * sends ticket data to a third party under terms we did not write, and some
 * organisations will forbid that outright. Expressing "which tenants may this touch"
 * as configuration from the first commit is much easier than retrofitting it
 * once three features already call the provider directly.
 */
final class Settings
{
    public const DEFAULTS = [
        // Master switch. Off on install: a plugin that started talking to a
        // third party the moment it was enabled would be a nasty surprise.
        'enabled'      => 0,

        // Which provider id is live. Only one at a time — a fallback chain
        // sounds appealing and means a request can silently land at a different
        // vendor than the entity policy was written for.
        'provider'     => '',

        // 'all' | 'allowlist' — see entityAllowed().
        'entity_mode'  => 'allowlist',

        // CSV of entity ids permitted when entity_mode is allowlist.
        'entities'     => '',

        // Record the rendered prompt alongside each call. Useful while tuning,
        // and off by default because prompts contain ticket content.
        'log_prompts'  => 0,

        // Seconds before a provider call is abandoned.
        'timeout'      => 60,

        // May the model run tools that change something? Off, and separate from
        // the master switch, because the two decisions are genuinely different:
        // "may this data be sent to a vendor" is a contractual question, and
        // "may a model write to a ticket" is an operational one.
        'allow_write_tools' => 0,

        // How many provider round trips one tool-using request may make before
        // the loop gives up and asks for a final answer — the ceiling that
        // keeps a confused model costing a few cents rather than a few hours.
        //
        // Twelve rather than six, because the assistant is the feature that
        // actually spends this: a troubleshooting question is routinely "find
        // the machine, look at its disks, check whether anyone else reported
        // this, read that ticket" before a word of the answer, and six hands
        // back a half-investigated guess.
        'max_tool_turns'    => 12,

        // ---------------------------------------------------------------- triage

        // Off separately, like everything else. A site that wants retrieval but
        // not suggestions should not have to choose between them.
        'triage_enabled'       => 0,

        // Which request types get triaged, as a CSV of glpi_requesttypes ids.
        // Empty means the two GLPI marks itself: the helpdesk default and the
        // mail default — which is to say tickets people wrote in prose, rather
        // than tickets a technician filled a form in for.
        'triage_request_types' => '',

        // Skip tickets raised from the technician interface. On by default: a
        // technician who opened the form already chose a category, and asking a
        // model to second-guess that is spending money to be told what is
        // already on the screen.
        'triage_skip_central'  => 1,

        // How many queued tickets one cron run suggests for.
        'triage_batch'         => 20,

        // -------------------------------------------------------------- drafting

        // Solution and knowledge-article drafting, on demand. Off separately
        // again: it is the one feature whose output is prose meant to be read
        // by somebody other than the technician, which is a bigger decision
        // than the rest.
        'draft_enabled'        => 0,

        // ---------------------------------------------------------- reply review

        // Reading a reply a technician wrote to a requester, before they send
        // it. Off separately, and this is the switch that most deserves to be:
        // it is the only feature here that touches requester-facing text at
        // all, and to spot internal content leaking into a reply it has to be
        // given the internal notes to compare against — the most sensitive
        // thing this plugin sends anywhere. It still never writes the reply.
        'reply_review_enabled' => 0,

        // ------------------------------------------------------------- assistant

        // The troubleshooting panel. Off separately again, because it is the
        // one feature that offers the model *every* registered tool rather than
        // a chosen few — including whatever other plugins have added.
        'assistant_enabled'    => 0,

        // Days a conversation is kept. Zero keeps them for ever.
        'assistant_retention'  => 90,

        // The output ceiling for one assistant answer.
        //
        // Eight thousand for a few hundred words of prose, and that is not
        // waste. A reasoning model spends this budget *thinking* before it
        // writes anything, and the whole allowance can go on reasoning: the
        // symptom is an answer that stops mid-sentence, or no answer at all,
        // with an ordinary finish reason and nothing to say why. The same trap
        // is documented on triage (1,500 tokens for an ~80-token answer) and on
        // glpi-report's narrative (4,000 for ~500 words).
        //
        // A ceiling is not a cost: nothing is charged for output that is not
        // produced. What it costs is the worst case of a model that will not
        // stop, which is what the ceiling is for.
        'assistant_max_tokens' => 8000,

        // House instructions, appended to the assistant's own system prompt.
        //
        // The shipped prompt is about how to use tools well and is not the
        // place for anything site-specific — which organisation names mean
        // what, which of two spellings of a supplier is right, that a server is
        // never rebooted here without asking. Those belong to the instance, and
        // an administrator should not have to edit a PHP file to say them.
        //
        // Appended rather than replacing: the shipped instruction is what makes
        // the assistant look before answering, and an administrator overwriting
        // it by accident would leave a model that confidently guesses.
        'assistant_instructions' => '',

        // Tools past this many are searched for rather than all declared up
        // front. Every declared tool costs its schema on every turn, and a
        // model choosing between twenty chooses worse than one choosing
        // between eight. See Toolbox.
        'tool_search_threshold' => 8,
    ];

    /** Shown in place of a stored secret. Posting it back means "unchanged". */
    public const SECRET_PLACEHOLDER = '••••••••';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIAI_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value     = $stored[$key] ?? null;
            $out[$key] = ($value === null || $value === '')
                ? $default
                : (is_int($default) ? (int) $value : (string) $value);
        }

        $out['timeout'] = max(5, min(300, (int) $out['timeout']));

        return $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function flag(string $key): bool
    {
        return ((int) self::get($key)) === 1;
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        $filtered = array_intersect_key($values, self::DEFAULTS);
        if ($filtered !== []) {
            Config::setConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, $filtered);
        }
    }

    // ------------------------------------------------------ provider settings

    /** Config key for one provider field. */
    private static function key(string $provider_id, string $field): string
    {
        return $provider_id . '_' . $field;
    }

    /**
     * The request-type ids that get triaged.
     *
     * Empty configuration resolves to the two types GLPI marks itself rather
     * than to "none" or "everything". Both of those would be defensible
     * defaults and both would be wrong: "none" makes switching the feature on
     * do nothing at all, and "everything" spends money on tickets a technician
     * already categorised while filling in the form.
     *
     * @return int[]
     */
    public static function triageRequestTypes(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $raw = array_values(array_filter(
            array_map('trim', explode(',', (string) self::get('triage_request_types'))),
            static fn(string $id): bool => $id !== '' && ctype_digit($id)
        ));

        if ($raw !== []) {
            return array_map('intval', $raw);
        }

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_requesttypes',
                'WHERE'  => ['OR' => ['is_helpdesk_default' => 1, 'is_mail_default' => 1]],
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Every setting for one provider, with secrets decrypted.
     *
     * @return array<string,string>
     */
    public static function forProvider(string $provider_id): array
    {
        $class = Registry::classFor($provider_id);
        if ($class === null) {
            return [];
        }

        $keys = [];
        foreach ($class::fields() as $field) {
            $keys[] = self::key($provider_id, $field->name);
        }

        $stored = Config::getConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, $keys);
        $out    = [];

        foreach ($class::fields() as $field) {
            $raw = (string) ($stored[self::key($provider_id, $field->name)] ?? '');

            if ($raw === '') {
                $out[$field->name] = $field->default;
                continue;
            }

            if ($field->isSecret()) {
                $out[$field->name] = self::decryptSecret($raw);
                continue;
            }

            $out[$field->name] = $raw;
        }

        return $out;
    }

    /**
     * A stored secret in the clear, or an empty string if it cannot be read.
     *
     * `GLPIKey::decrypt()` has three failure modes and does not signal them
     * alike. A wrong key and a value that was never encrypted both come back as
     * `''`. But a **missing, unreadable or wrong-length `glpicrypt.key`** makes
     * it return *its own input* — the ciphertext — because with no key there is
     * nothing it can usefully say. `is_string()` does not catch that: the blob
     * is a perfectly good string.
     *
     * Passed on, it travels as the credential, and the vendor answers with an
     * authentication error describing neither the cause nor anything the
     * administrator changed. Google's is the worst of them: an unrecognised
     * `x-goog-api-key` is reported as *"Expected OAuth 2 access token, login
     * cookie or other valid authentication credential"*, which sends people
     * looking for an OAuth setting that was never involved.
     *
     * So a value that comes back unchanged is treated as absent. "Not
     * configured" is true and actionable; a base64 blob posing as an API key is
     * neither. The usual cause is a database restored onto another instance
     * without its key file — see cryptKeyAvailable(), which names that directly.
     */
    public static function decryptSecret(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $plain = (new GLPIKey())->decrypt($raw);

        if (!is_string($plain) || $plain === '' || $plain === $raw) {
            return '';
        }

        return $plain;
    }

    /**
     * Can GLPI read its own encryption key at all?
     *
     * Without it every stored secret in the instance is unrecoverable, which is
     * worth saying plainly rather than letting each provider report itself as
     * merely unconfigured. `keyExists()` rather than `get()` because `get()`
     * raises a warning on every call when the file is absent.
     */
    public static function cryptKeyAvailable(): bool
    {
        return (bool) (new GLPIKey())->keyExists();
    }

    /**
     * Persist one provider's settings.
     *
     * A secret arriving as the placeholder means the form was submitted without
     * the administrator retyping it — keep what is stored. Without this, every
     * unrelated settings save would wipe every key on the page.
     *
     * @param array<string,string> $input raw posted values
     */
    public static function saveProvider(string $provider_id, array $input): void
    {
        $class = Registry::classFor($provider_id);
        if ($class === null) {
            return;
        }

        $existing = self::forProvider($provider_id);
        $values   = [];

        foreach ($class::fields() as $field) {
            $posted = trim((string) ($input[$field->name] ?? ''));

            if ($field->isSecret()) {
                if ($posted === self::SECRET_PLACEHOLDER) {
                    continue; // unchanged — leave the stored ciphertext alone
                }

                $name = self::key($provider_id, $field->name);

                // A tripwire, not a routine check. The value is handed to
                // Config in plaintext and encrypted there, which is only true
                // while this key is declared through SECURED_CONFIGS — so if
                // that declaration is ever lost, this must fail loudly rather
                // than write a working credential to disk in the clear.
                if ($posted !== '' && !(new GLPIKey())->isConfigSecured(PLUGIN_GLPIAI_CONFIG_CONTEXT, $name)) {
                    throw new \RuntimeException(
                        "glpiai: refusing to store $name — it is not declared in SECURED_CONFIGS, "
                        . 'so it would be written unencrypted.'
                    );
                }

                $values[$name] = $posted; // '' is an explicit clear
                continue;
            }

            if ($field->type === Field::CHECKBOX) {
                $values[self::key($provider_id, $field->name)] = $posted !== '' ? '1' : '0';
                continue;
            }

            $values[self::key($provider_id, $field->name)] = $posted;
        }

        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPIAI_CONFIG_CONTEXT, $values);
        }
    }

    /** Is a secret already stored for this field? Used to decide what the form shows. */
    public static function hasSecret(string $provider_id, string $field): bool
    {
        return (self::forProvider($provider_id)[$field] ?? '') !== '';
    }

    /**
     * The config keys that hold credentials.
     *
     * Derived from the providers' own field declarations rather than listed by
     * hand, because this list is what makes encryption happen: a new provider
     * whose key was forgotten here would store its credential in plaintext and
     * work perfectly, which is the worst way for that to fail.
     *
     * @return string[]
     */
    public static function secretKeys(): array
    {
        $keys = [];

        foreach (Registry::all() as $class) {
            foreach ($class::fields() as $field) {
                if ($field->isSecret()) {
                    $keys[] = self::key($class::id(), $field->name);
                }
            }
        }

        return $keys;
    }

    /** Every config key this plugin owns, for a clean uninstall. */
    public static function allKeys(): array
    {
        $keys = array_keys(self::DEFAULTS);

        foreach (Registry::all() as $class) {
            foreach ($class::fields() as $field) {
                $keys[] = self::key($class::id(), $field->name);
            }
        }

        return $keys;
    }

    // ----------------------------------------------------------- entity gate

    /**
     * May data from this entity be sent to the provider?
     *
     * Allowlist by default, and an empty allowlist means nothing is permitted —
     * the fail-closed direction. A tenant gate that defaults to "everything"
     * protects nobody, because the failure it guards against is precisely
     * somebody not having thought about it yet.
     *
     * The check is on the entity tree: permitting a parent permits the entities
     * beneath it, which is how anyone actually thinks about an organisation
     * that has sub-entities per site.
     */
    public static function entityAllowed(int $entities_id): bool
    {
        if (self::get('entity_mode') === 'all') {
            return true;
        }

        $allowed = self::allowedEntities();
        if ($allowed === []) {
            return false;
        }

        if (in_array($entities_id, $allowed, true)) {
            return true;
        }

        foreach (getAncestorsOf('glpi_entities', $entities_id) as $ancestor) {
            if (in_array((int) $ancestor, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] */
    public static function allowedEntities(): array
    {
        $out = [];
        foreach (explode(',', (string) self::get('entities')) as $id) {
            $id = trim($id);
            if ($id !== '' && is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }
}
