<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Provider configuration.
 *
 * Nothing here knows about any particular vendor. Every form control is
 * rendered from the Field descriptors a provider declares, which is what keeps
 * the cost of a fifth provider at one class rather than one class plus three
 * forgettable edits to this page.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Skill;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Mcp\Server as McpServer;
use GlpiPlugin\Glpiai\Provider\Field;
use GlpiPlugin\Glpiai\Provider\Registry;
use GlpiPlugin\Glpiai\Search\Documents;
use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Reply\Review;
use GlpiPlugin\Glpiai\Reply\Reviewer;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\ToolRegistry;
use GlpiPlugin\Glpiai\Url;

Session::checkRight('plugin_glpiai_config', READ);

/**
 * The entity ids actually chosen in the allowlist.
 *
 * Not `array_map('intval', ...)`, which is the obvious way to write this and is
 * wrong in a way that matters. GLPI renders a multi-select alongside a hidden
 * field of the same name so that an empty selection still posts something, and
 * that something is the empty string — which intval() turns into 0, the root
 * entity. Since permitting an entity permits everything beneath it, an
 * administrator who saved this page without choosing anything would silently
 * permit the entire install, and the page would look exactly as it should.
 *
 * So empties are dropped *before* the cast, and 0 survives only when somebody
 * genuinely picked the root entity.
 *
 * @return int[]
 */
function selectedEntities(): array
{
    $posted = (array) ($_POST['entities'] ?? []);
    $chosen = array_filter($posted, static fn($id): bool => is_numeric($id));

    return array_values(array_unique(array_map('intval', $chosen)));
}

/**
 * One form control for a Field descriptor.
 *
 * Shared by every provider card. A stored secret is
 * never sent back to the browser: the placeholder stands in for it, and posting
 * the placeholder unchanged means "keep what you have" — see
 * Settings::saveProvider().
 */
function renderField(Field $field, string $name, string $value): void
{
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    if ($field->isSecret()) {
        $value = $value !== '' ? Settings::SECRET_PLACEHOLDER : '';
    }

    $wide = in_array($field->type, [Field::SELECT], true) || $field->name === 'base_url';
    echo "<div class='col-md-" . ($wide ? '12' : '6') . " mb-3'>";
    echo "<label class='form-label'>" . $e($field->label);
    if ($field->required) {
        echo " <span class='text-red'>*</span>";
    }
    echo '</label>';

    switch ($field->type) {
        case Field::SELECT:
            Dropdown::showFromArray($name, $field->options, [
                'value' => $value !== '' ? $value : $field->default,
            ]);
            break;

        case Field::CHECKBOX:
            echo "<label class='form-check'><input type='checkbox' class='form-check-input' "
               . "name='" . $name . "' value='1' " . ($value === '1' ? "checked='checked'" : '') . '></label>';
            break;

        case Field::SECRET:
            echo "<input type='password' class='form-control' autocomplete='new-password' "
               . "name='" . $name . "' value='" . $e($value) . "'>";
            break;

        default:
            echo "<input type='text' class='form-control' name='" . $name . "' "
               . "value='" . $e($value) . "' placeholder='" . $e($field->placeholder) . "'>";
    }

    if ($field->help !== '') {
        echo "<div class='form-text'>" . $e($field->help) . '</div>';
    }

    echo '</div>';
}

/**
 * A posted number, clamped into range, with the clamp reported.
 *
 * Not `min`/`max` attributes on the input. Those look like validation and
 * behave like a trap: the browser refuses to submit, scrolls to the field and
 * shows a tooltip that lasts a couple of seconds — on a settings page this long
 * the field is frequently off-screen, so the whole event reads as "I pressed
 * Save and nothing happened". A silent refusal is worse than a wrong value,
 * because a wrong value can at least be seen.
 *
 * So the browser always submits, the server decides, and anything it changed is
 * named in a message afterwards.
 *
 * @param string $label what to call it if it has to be reported
 */
function boundedInt(string $name, int $min, int $max, int $default, string $label): int
{
    if (!isset($_POST[$name]) || trim((string) $_POST[$name]) === '') {
        return $default;
    }

    $posted = (int) $_POST[$name];
    $value  = max($min, min($max, $posted));

    if ($value !== $posted) {
        // $GLOBALS, not `global` + a file-scope variable: GLPI 11 requires this
        // script inside LegacyFileLoadController::__invoke(), so "file scope"
        // here is that method's locals — a bare `$clamped = []` below and
        // `global $clamped` in this function name two different variables, and
        // every clamp warning is silently dropped.
        $GLOBALS['glpiai_clamped'][] = sprintf(
            __('%1$s must be between %2$d and %3$d — %4$d was saved as %5$d.', 'glpiai'),
            $label,
            $min,
            $max,
            $posted,
            $value
        );
    }

    return $value;
}

/** @var string[] Ranges corrected on this save, reported once at the end. */
$GLOBALS['glpiai_clamped'] = [];

if (!empty($_POST['update'])) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
    // consumed the token before this page ran, so a second check always fails.
    Session::checkRight('plugin_glpiai_config', UPDATE);

    Settings::save([
        'enabled'     => !empty($_POST['enabled']) ? '1' : '0',
        'provider'    => (string) ($_POST['provider'] ?? ''),
        'entity_mode' => ($_POST['entity_mode'] ?? 'allowlist') === 'all' ? 'all' : 'allowlist',
        'entities'    => implode(',', selectedEntities()),
        'log_prompts' => !empty($_POST['log_prompts']) ? '1' : '0',
        'timeout'     => boundedInt('timeout', 5, 300, 60, __('Request timeout', 'glpiai')),

        'allow_write_tools' => !empty($_POST['allow_write_tools']) ? '1' : '0',
        'max_tool_turns'    => boundedInt('max_tool_turns', 1, 60, 12,
            __('Maximum tool turns', 'glpiai')),
        'tool_search_threshold' => boundedInt('tool_search_threshold', 2, 200, 8,
            __('Search for tools past this many', 'glpiai')),


        'triage_enabled'       => !empty($_POST['triage_enabled']) ? '1' : '0',
        'triage_request_types' => implode(',', array_filter(
            (array) ($_POST['triage_request_types'] ?? []),
            static fn($id): bool => is_numeric($id)
        )),
        'triage_skip_central'  => !empty($_POST['triage_skip_central']) ? '1' : '0',
        'triage_batch'         => boundedInt('triage_batch', 1, 500, 20,
            __('Tickets triaged per run', 'glpiai')),

        'draft_enabled'        => !empty($_POST['draft_enabled']) ? '1' : '0',
        'reply_review_enabled' => !empty($_POST['reply_review_enabled']) ? '1' : '0',

        'assistant_enabled'    => !empty($_POST['assistant_enabled']) ? '1' : '0',
        // Trimmed but otherwise untouched. It is prose written by an
        // administrator for a model to read; there is nothing here to validate
        // that would not also be a way of quietly changing what they wrote.
        'assistant_instructions' => trim((string) ($_POST['assistant_instructions'] ?? '')),
        'assistant_retention'  => boundedInt('assistant_retention', 0, 3650, 90,
            __('Keep conversations for', 'glpiai')),
        'assistant_max_tokens' => boundedInt('assistant_max_tokens', 500, 32000, 8000,
            __('Answer length ceiling', 'glpiai')),
    ]);

    // Every provider's fields are submitted together, so an administrator can
    // fill in a second vendor without losing the first's configuration by
    // switching the active one.
    foreach (Registry::all() as $id => $class) {
        Settings::saveProvider($id, (array) ($_POST['p'][$id] ?? []));
    }

    // Drop any cached Entra token: an administrator who has just corrected the
    // tenant or rotated the secret should see the effect on the next test, not
    // up to an hour later.
    $azure = Registry::build('azure');
    if ($azure instanceof GlpiPlugin\Glpiai\Provider\AzureFoundry) {
        $azure->forgetToken();
    }

    Session::addMessageAfterRedirect(__s('Settings saved.', 'glpiai'));

    // Said out loud. A value quietly corrected is a value the administrator
    // still believes they set.
    foreach ($GLOBALS['glpiai_clamped'] as $note) {
        Session::addMessageAfterRedirect(htmlspecialchars($note), false, WARNING);
    }

    Html::back();
}

Html::header(__('AI', 'glpiai'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$cfg    = Settings::all();
$e      = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$active = (string) $cfg['provider'];

// Read-only visitors keep the page but lose the button.
//
// READ opens this page and UPDATE saves it, and the two are separately
// grantable — so a profile can legitimately arrive here unable to change
// anything. Rendering the form as though they could, and answering Save with an
// access-denied page, wastes the work they just did explaining nothing.
$can_edit = Session::haveRight('plugin_glpiai_config', UPDATE);

echo "<div class='container-fluid glpiai-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see these settings but not change them.', 'glpiai')
       . '</div>';
}
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// ------------------------------------------------------------------- status
$ready = Client::isReady();

// The entity policy is part of "is this working", and saying so here is the
// whole point of the banner. It used to report success on an instance whose
// allowlist was empty — everything switched on, a provider configured, and not
// one request able to run. The first anyone knew was a refusal naming an entity
// id, from a panel that had looked perfectly healthy.
$permits = Settings::allowedEntities() !== [] || $cfg['entity_mode'] === 'all';
$live    = $ready && $permits;

echo "<div class='alert " . ($live ? 'alert-success' : ($ready ? 'alert-warning' : 'alert-secondary'))
   . " d-flex align-items-center'>";
echo "<i class='ti " . ($live ? 'ti-circle-check' : ($ready ? 'ti-alert-triangle' : 'ti-circle-dashed'))
   . " me-2 fs-3'></i><div>";

if ($live) {
    echo '<strong>' . __s('AI features are enabled and a provider is configured.', 'glpiai') . '</strong>';
    echo "<div class='small'>"
       . __s('Nothing is sent to a provider until this is switched on, a provider is configured, '
           . 'and the entity the data belongs to is permitted below.', 'glpiai')
       . '</div>';
} elseif ($ready) {
    echo '<strong>' . __s('Configured, but no entity may use it yet.', 'glpiai') . '</strong>';
    echo "<div class='small'>"
       . __s('The switch is on and the provider works, and every request will still be refused: '
           . 'the allowlist below is empty, and an empty allowlist permits nothing. Choose the '
           . 'entities that may use AI, or switch the policy to every entity.', 'glpiai')
       . ' <a href=\'#glpiai-access\'>' . __s('Go to that setting', 'glpiai') . '</a>'
       . '</div>';
} else {
    echo '<strong>' . __s('AI features are not active.', 'glpiai') . '</strong>';
    echo "<div class='small'>"
       . __s('Nothing is sent to a provider until this is switched on, a provider is configured, '
           . 'and the entity the data belongs to is permitted below.', 'glpiai')
       . '</div>';
}

echo '</div></div>';

// --------------------------------------------------------------- section nav
//
// This page configures four independent features plus the vendor connection
// behind them, and it grew past the point where scrolling was a reasonable way
// to find anything. The jump list is the cheapest fix that does not split the
// settings across several pages — which would be worse, because most of these
// only make sense next to the provider that answers them.
$sections = [
    'connection' => __s('Connection', 'glpiai'),
    'access'     => __s('Access', 'glpiai'),
    'assistant'  => __s('HEIMDALL', 'glpiai'),
    'tools'      => __s('Tool calling', 'glpiai'),
    'triage'     => __s('Triage', 'glpiai'),
    'drafts'     => __s('Drafting', 'glpiai'),
    'reply'      => __s('Reply review', 'glpiai'),
];

echo "<div class='glpiai-nav mb-3'>";
foreach ($sections as $anchor => $label) {
    echo "<a class='btn btn-sm btn-outline-secondary' href='#glpiai-" . $e($anchor) . "'>"
       . $label . '</a>';
}
echo '</div>';

// ------------------------------------------------------------------ general
echo "<div id='glpiai-connection' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('General', 'glpiai') . '</h3></div><div class="card-body">';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='enabled' value='1' "
   . (((int) $cfg['enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Enable AI features', 'glpiai') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The master switch. Off, no request is made to any provider for any reason.', 'glpiai')
   . '</div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Active provider', 'glpiai') . '</label>';
Dropdown::showFromArray('provider', ['' => __('— none —', 'glpiai')] + Registry::labels(), [
    'value' => $active,
]);
echo "<div class='form-text'>"
   . __s('One at a time. A fallback chain sounds appealing and means a request can land at a '
       . 'different vendor than the entity policy was written for.', 'glpiai')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Request timeout (seconds)', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='timeout' value='" . $e($cfg['timeout']) . "'>";
echo '</div>';
echo '</div>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='log_prompts' value='1' "
   . (((int) $cfg['log_prompts']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Record prompt text in the usage log', 'glpiai') . '</span></label>';
echo "<div class='form-text'>"
   . __s('Useful while tuning a feature. Off by default because prompts contain ticket content, '
       . 'and the usage log is not the place to duplicate it.', 'glpiai')
   . '</div>';

echo '</div></div>';
// ----------------------------------------------------------------- providers
//
// Placed directly under the provider dropdown that selects between them, and
// only the chosen one is opened. Four vendors declare twenty-eight fields
// between them — Azure alone has thirteen — so rendering them all expanded put
// the rest of the page, and the save button, several screens below settings an
// administrator had just changed.
//
// A closed <details> still posts the inputs inside it, so a provider configured
// earlier keeps its values whether or not anybody opens its card again. That is
// the property this depends on: hiding the fields must not mean dropping them.
foreach (Registry::all() as $id => $class) {
    $values     = Settings::forProvider($id);
    $provider   = Registry::build($id);
    $configured = $provider !== null && $provider->isConfigured();
    $is_active  = $id === $active;

    echo "<details class='card mb-3" . ($is_active ? ' border-primary' : '') . "'"
       . ($is_active ? ' open' : '') . '>';
    echo "<summary class='card-header d-flex align-items-center gap-2'>";
    echo "<h3 class='card-title mb-0'>" . $e($class::label()) . '</h3>';
    if ($is_active) {
        echo "<span class='badge bg-blue text-white'>" . __s('active', 'glpiai') . '</span>';
    }
    echo "<span class='badge " . ($configured ? 'bg-green-lt' : 'bg-secondary-lt') . "'>"
       . ($configured ? __s('configured', 'glpiai') : __s('incomplete', 'glpiai')) . '</span>';

    // Outside the <summary>: a button inside one toggles the disclosure as well
    // as firing, so testing a connection would collapse the card it reports in.
    echo '</summary>';

    echo "<div class='card-body'>";
    echo "<div class='d-flex justify-content-end mb-2'>";
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
       . "data-glpiai-test='" . $e($id) . "'>"
       . "<i class='ti ti-plug-connected me-1'></i>" . __s('Test connection', 'glpiai') . '</button>';
    echo '</div>';

    echo "<div class='row'>";

    foreach ($class::fields() as $field) {
        renderField(
            $field,
            'p[' . $e($id) . '][' . $e($field->name) . ']',
            (string) ($values[$field->name] ?? '')
        );
    }

    echo '</div>';
    echo "<div class='glpiai-test-result' data-glpiai-result='" . $e($id) . "' hidden></div>";
    echo '</div></details>';
}
// ------------------------------------------------------------- entity policy
echo "<div id='glpiai-access' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Which entities may use AI', 'glpiai') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('This plugin sends ticket content to a third party. That is a contractual question '
       . 'before it is a technical one, and some organisations will forbid it outright — so it is a '
       . 'setting rather than an assumption. Permitting an entity permits the entities beneath it.', 'glpiai')
   . '</p>';

foreach (
    [
        'allowlist' => __s('Only the entities selected below', 'glpiai'),
        'all'       => __s('Every entity', 'glpiai'),
    ] as $mode => $label
) {
    echo "<label class='form-check'>";
    echo "<input type='radio' class='form-check-input' name='entity_mode' value='" . $e($mode) . "' "
       . ($cfg['entity_mode'] === $mode ? "checked='checked'" : '') . '>';
    echo "<span class='form-check-label'>$label</span></label>";
}

echo "<div class='mt-3' style='max-width:520px'>";
// `value`, not `values`, even though this is a multi-select: Dropdown::show()
// overwrites `values` with `value` when `multiple` is set, and Entity defaults
// `value` to the session's active entity — so passing the selection as `values`
// gets it silently replaced by an integer, which then fatals in array_diff().
Entity::dropdown([
    'name'     => 'entities[]',
    'multiple' => true,
    'value'    => Settings::allowedEntities(),
    'width'    => '100%',
]);
echo "<div class='form-text'>"
   . __s('An empty allowlist permits nothing. That is the intended direction: the case this '
       . 'guards against is somebody not having considered it yet.', 'glpiai')
   . '</div></div>';

echo '</div></div>';
// --------------------------------------------------------------- assistant
echo "<div id='glpiai-assistant' class='card mb-3'><div class='card-header d-flex align-items-center'>";
echo "<h3 class='card-title mb-0'>" . __s('HEIMDALL', 'glpiai') . '</h3>';
echo "<span class='text-muted ms-2 small'>"
   . __s('Helpdesk Endpoint Inspection, Monitoring, Diagnostics And Live Lookup', 'glpiai')
   . '</span>';
echo "<span class='badge " . (Assistant::available() ? 'bg-green-lt' : 'bg-secondary-lt') . " ms-2'>"
   . (Assistant::available() ? __s('available', 'glpiai') : __s('off', 'glpiai')) . '</span>';
echo "<a class='btn btn-sm btn-outline-secondary ms-auto' href='" . $e(Skill::getSearchURL(false)) . "'>"
   . "<i class='ti ti-bulb me-1'></i>" . __s('Skills', 'glpiai') . '</a>';
echo '</div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('The watchman: a panel a technician can open from any page, which knows what they have '
       . 'open and can use every tool registered below. This is the one feature that offers the '
       . 'model the whole tool set rather than a chosen few — including tools other plugins have '
       . 'added.', 'glpiai')
   . '</p>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='assistant_enabled' value='1' "
   . (((int) $cfg['assistant_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Enable HEIMDALL', 'glpiai') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('Technician interface only. Every tool still checks the signed-in user\'s own rights and '
       . 'entity, so it can reach exactly what the technician driving it could have '
       . 'found by hand — and every call it makes is in the tool log under their name.', 'glpiai')
   . '</div>';

echo "<div class='mb-3'>";
echo "<label class='form-label'>" . __s('House instructions', 'glpiai') . '</label>';
echo "<textarea class='form-control font-monospace' name='assistant_instructions' rows='6' "
   . "placeholder='" . $e(__('e.g. Northwind and NW Ltd are the same organisation. Never propose '
       . 'rebooting a server without saying so explicitly.', 'glpiai')) . "'>"
   . $e($cfg['assistant_instructions']) . '</textarea>';
echo "<div class='form-text'>"
   . __s('Added to HEIMDALL\'s own instructions, not instead of them — the shipped prompt is what '
       . 'makes it look things up before answering rather than guessing, and replacing it would '
       . 'undo that. Put what is true about this instance here: which names mean the same '
       . 'organisation, what is never done here without asking, house style for a handover note. '
       . 'It is read on every request, so keep it to things that are always relevant; anything '
       . 'situational belongs in a skill — see the Skills button at the top of this card.',
         'glpiai')
   . '</div></div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Keep conversations for (days)', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='assistant_retention' value='"
   . $e($cfg['assistant_retention']) . "'>";
echo "<div class='form-text'>"
   . __s('A conversation is one technician\'s working notes and is readable only by them. Zero '
       . 'keeps them for ever.', 'glpiai')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Answer length ceiling (tokens)', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='assistant_max_tokens' min='500' max='32000' "
   . "value='" . $e($cfg['assistant_max_tokens']) . "'>";
echo "<div class='form-text'>"
   . __s('Not the length of the answer — the point at which the model is cut off mid-sentence. '
       . 'A reasoning model spends this budget <em>thinking</em> before it writes anything, so a '
       . 'ceiling sized for the prose produces half a sentence or nothing at all. Raise it if '
       . 'answers still stop short. Nothing is charged for output that is not produced.', 'glpiai')
   . '</div></div>';
echo '</div>';

echo '</div></div>';
// ------------------------------------------------------------- tool calling
echo "<div id='glpiai-tools' class='card mb-3'><div class='card-header d-flex align-items-center'>";
echo "<h3 class='card-title mb-0'>" . __s('Tool calling', 'glpiai') . '</h3>';
echo "<a class='btn btn-sm btn-outline-secondary ms-auto' href='" . $e(McpServer::getSearchURL(false)) . "'>"
   . "<i class='ti ti-plug me-1'></i>" . __s('MCP servers', 'glpiai') . '</a>';
echo '</div><div class="card-body">';

$tools = ToolRegistry::all((int) Session::getActiveEntity());

echo '<p class="text-muted">'
   . __s('Tools let a model look something up before answering rather than guessing. They run with '
       . 'the rights and entity restriction of the signed-in user, so a model can only ever reach '
       . 'what the technician driving it could have found by hand.', 'glpiai')
   . '</p>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Maximum tool turns', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='max_tool_turns' value='"
   . $e($cfg['max_tool_turns']) . "'>";
echo "<div class='form-text'>"
   . __s('How many times a single request may go back to the provider while working through '
       . 'tools. The ceiling that keeps a confused model from costing real money — and the '
       . 'setting the assistant runs out of when it says it ran out of steps. A troubleshooting '
       . 'question that has to find a machine, look at it, and then read a similar ticket spends '
       . 'four turns before it writes anything, so raise this if you see that message often. '
       . '1 to 60.', 'glpiai')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'>";
echo "<label class='form-check mt-4'>";
echo "<input type='checkbox' class='form-check-input' name='allow_write_tools' value='1' "
   . (((int) $cfg['allow_write_tools']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Allow tools that change data', 'glpiai') . '</span></label>';
echo "<div class='form-text'>"
   . __s('Off by default, and separate from the master switch on purpose. A wrong read costs a few '
       . 'hundred tokens; a wrong write is in a ticket\'s history under a technician\'s name. Every '
       . 'MCP tool counts as a write, since a remote server\'s claim to be read-only is not '
       . 'something this end can verify.', 'glpiai')
   . '</div></div>';
echo '</div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Search for tools past this many', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='tool_search_threshold' value='"
   . $e($cfg['tool_search_threshold']) . "'>";
echo "<div class='form-text'>"
   . __s('Every declared tool costs its schema on every turn, and a model choosing between twenty '
       . 'chooses worse than one choosing between eight. Past this count a request declares this '
       . 'plugin\'s own tools plus a search tool, and the rest are found by asking — the model '
       . 'searches, and what it finds becomes callable immediately.', 'glpiai')
   . '</div></div>';
echo '</div>';

echo "<div class='small text-muted'>" . __s('Available right now, in this entity:', 'glpiai') . '</div>';
if ($tools === []) {
    echo "<div class='text-muted'>" . __s('No tools are registered.', 'glpiai') . '</div>';
} else {
    echo "<div class='mt-1'>";
    foreach ($tools as $tool) {
        $class = $tool->mutates ? 'bg-orange-lt' : 'bg-blue-lt';
        echo "<span class='badge $class me-1 mb-1' title='" . $e($tool->description) . "'>"
           . $e($tool->name)
           . ($tool->source !== 'native' ? " <span class='opacity-75'>· " . $e($tool->source) . '</span>' : '')
           . '</span>';
    }
    echo '</div>';
}

echo '</div></div>';
// ------------------------------------------------------------------ triage
/** @var DBmysql $DB */
global $DB;

$triage_on = ((int) $cfg['triage_enabled']) === 1;
$accuracy  = Suggestion::accuracy();
$queued    = Suggestion::countPending();

echo "<div id='glpiai-triage' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Triage suggestions', 'glpiai') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('A new ticket gets a proposed category, urgency, impact and procedure, shown to a '
       . 'technician as chips above the ticket fields. Nothing is applied until somebody clicks. '
       . 'The suggestion is queued at creation and produced by cron, so a requester pressing '
       . 'submit never waits on a provider.', 'glpiai')
   . '</p>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='triage_enabled' value='1' "
   . ($triage_on ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Suggest triage for new tickets', 'glpiai') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('Uses the fast model tier. One call per ticket that qualifies below.', 'glpiai')
   . '</div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Triage tickets raised as', 'glpiai') . '</label>';

$chosen_types = Settings::triageRequestTypes();
$request_types = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_requesttypes',
        'WHERE'  => ['is_active' => 1],
        'ORDER'  => 'name',
    ]) as $type
) {
    $request_types[(int) $type['id']] = (string) $type['name'];
}

Dropdown::showFromArray('triage_request_types', $request_types, [
    'values'   => $chosen_types,
    'multiple' => true,
    'width'    => '100%',
]);
echo "<div class='form-text'>"
   . __s('Defaults to the two GLPI marks itself — the helpdesk default and the mail default — '
       . 'which is to say tickets somebody wrote in prose. A ticket a technician filled a form in '
       . 'for already has a category, and asking a model to second-guess it spends money to be '
       . 'told what is on the screen.', 'glpiai')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Tickets triaged per run', 'glpiai') . '</label>';
echo "<input type='number' class='form-control' name='triage_batch' value='"
   . $e($cfg['triage_batch']) . "'>";
echo "<div class='form-text'>"
   . __s('The cron task runs every five minutes and does this many.', 'glpiai') . '</div>';

echo "<label class='form-check mt-2'>";
echo "<input type='checkbox' class='form-check-input' name='triage_skip_central' value='1' "
   . (((int) $cfg['triage_skip_central']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Skip tickets raised from the technician interface', 'glpiai') . '</span></label>';
echo '</div>';
echo '</div>';

// ------------------------------------------------------------ accept rate
echo "<div class='border-top pt-3'>";
echo "<div class='small text-muted mb-2'>"
   . __s('How often a technician took the suggestion. This is the accuracy figure — it counts '
       . 'decisions only, so a suggestion that merely agreed with what the ticket already said is '
       . 'in neither column.', 'glpiai')
   . '</div>';

$labels = [
    'itilcategories_id'      => __s('Category', 'glpiai'),
    'urgency'                => __s('Urgency', 'glpiai'),
    'impact'                 => __s('Impact', 'glpiai'),
    'plugin_glpisop_sops_id' => __s('Procedure', 'glpiai'),
];

echo "<table class='table table-sm mb-2'><thead><tr>";
echo '<th>' . __s('Field', 'glpiai') . '</th><th>' . __s('Accepted', 'glpiai') . '</th>';
echo '<th>' . __s('Dismissed', 'glpiai') . '</th><th>' . __s('Already right', 'glpiai') . '</th>';
echo '<th>' . __s('Accept rate', 'glpiai') . '</th></tr></thead><tbody>';

foreach ($accuracy as $field => $stats) {
    echo '<tr>';
    echo '<td>' . ($labels[$field] ?? $e($field)) . '</td>';
    echo '<td>' . (int) $stats['accepted'] . '</td>';
    echo '<td>' . (int) $stats['dismissed'] . '</td>';
    echo '<td>' . (int) $stats['matched'] . '</td>';
    echo '<td>' . ($stats['rate'] === null
        ? "<span class='text-muted'>" . __s('no decisions yet', 'glpiai') . '</span>'
        : '<strong>' . round($stats['rate'] * 100) . '%</strong>') . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

if ($queued > 0) {
    echo "<span class='badge bg-blue-lt'>"
       . sprintf(__s('%s queued', 'glpiai'), number_format($queued)) . '</span>';
}
if ($triage_on && ((int) $cfg['enabled']) !== 1) {
    echo "<span class='text-orange small'>"
       . __s('Nothing will be triaged while the master switch at the top of this page is off.', 'glpiai')
       . '</span>';
}
echo '</div>';

echo '</div></div>';
// ------------------------------------------------------------------ drafts
$drafts = Draft::usage();

echo "<div id='glpiai-drafts' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Solution and article drafting', 'glpiai') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('On a worked ticket, a technician can have the solution drafted from what actually '
       . 'happened — the followups, the tasks, the checks a procedure recorded, and how similar '
       . 'tickets were resolved — and a knowledge article drafted from the same evidence. On '
       . 'demand, on the quality model tier, and never automatically.', 'glpiai')
   . '</p>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='draft_enabled' value='1' "
   . (((int) $cfg['draft_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Offer drafts on tickets', 'glpiai') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('A drafted solution goes into GLPI\'s own solution editor for the technician to read and '
       . 'submit — it is never written to the ticket. A drafted article is created '
       . '<strong>unpublished</strong>, with no visibility, which means only its author can see it '
       . 'until somebody publishes it. Neither of those is configurable, deliberately: this is the '
       . 'one feature whose output is prose meant for somebody other than the technician.', 'glpiai')
   . '</div>';

echo "<div class='border-top pt-3'>";
echo "<div class='small text-muted mb-2'>"
   . __s('How often a draft was good enough to use. Counts decisions only — a draft nobody has '
       . 'acted on yet is not evidence either way.', 'glpiai')
   . '</div>';

$kinds = [
    Draft::SOLUTION => __s('Solutions', 'glpiai'),
    Draft::ARTICLE  => __s('Articles', 'glpiai'),
];

echo "<table class='table table-sm mb-0'><thead><tr>";
echo '<th>' . __s('Kind', 'glpiai') . '</th><th>' . __s('Used', 'glpiai') . '</th>';
echo '<th>' . __s('Discarded', 'glpiai') . '</th><th>' . __s('Undecided', 'glpiai') . '</th>';
echo '<th>' . __s('Use rate', 'glpiai') . '</th></tr></thead><tbody>';

foreach ($kinds as $kind => $label) {
    $stats = $drafts[$kind];
    echo '<tr>';
    echo '<td>' . $label . '</td>';
    echo '<td>' . (int) $stats['used'] . '</td>';
    echo '<td>' . (int) $stats['discarded'] . '</td>';
    echo '<td>' . (int) $stats['open'] . '</td>';
    echo '<td>' . ($stats['rate'] === null
        ? "<span class='text-muted'>" . __s('no decisions yet', 'glpiai') . '</span>'
        : '<strong>' . round($stats['rate'] * 100) . '%</strong>') . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

if (((int) $cfg['draft_enabled']) === 1 && ((int) $cfg['enabled']) !== 1) {
    echo "<div class='text-orange small mt-2'>"
       . __s('Nothing will be drafted while the master switch at the top of this page is off.', 'glpiai')
       . '</div>';
}
echo '</div>';

echo '</div></div>';

// --------------------------------------------------------------- reply review

$reviews = Review::summary();

echo "<div id='glpiai-reply' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Reply review', 'glpiai') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('The one place a model here touches requester-facing text, and it touches it by '
       . 'reading. A technician writing a reply can ask for a second read before sending: '
       . 'internal content that has been carried across, an unexplained term, no statement of '
       . 'what happens next, the wrong tone. It returns remarks, never a rewrite, and Save is '
       . 'never blocked — the reply belongs to the person who wrote it.', 'glpiai')
   . '</p>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='reply_review_enabled' value='1' "
   . (((int) $cfg['reply_review_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Offer a review of a reply before it is sent', 'glpiai')
   . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('To notice internal content leaking into a reply, the model is given the ticket\'s '
       . '<strong>internal notes</strong> to compare it against — the most sensitive thing this '
       . 'plugin sends anywhere. That is why this has a switch of its own on top of the entity '
       . 'allowlist, and why it is off until somebody decides otherwise.', 'glpiai')
   . '</div>';

echo "<div class='border-top pt-3'>";
echo "<div class='small text-muted mb-2'>"
   . __s('There is nothing to accept here, so the outcome is read from the reply: of the '
       . 'reviews that raised something and whose reply was then sent, how many had been '
       . 'edited in between. A review nobody acts on is one to switch off.', 'glpiai')
   . '</div>';

echo "<table class='table table-sm mb-0'><thead><tr>";
echo '<th>' . __s('Reviews', 'glpiai') . '</th><th>' . __s('Raised something', 'glpiai') . '</th>';
echo '<th>' . __s('Replies sent', 'glpiai') . '</th><th>' . __s('Edited after a flag', 'glpiai') . '</th>';
echo '<th>' . __s('Acted on', 'glpiai') . '</th></tr></thead><tbody><tr>';
echo '<td>' . (int) $reviews['reviews'] . '</td>';
echo '<td>' . (int) $reviews['flagged'] . '</td>';
echo '<td>' . (int) $reviews['sent'] . '</td>';
echo '<td>' . (int) $reviews['edited'] . '</td>';
echo '<td>' . ($reviews['rate'] === null
    ? "<span class='text-muted'>" . __s('nothing sent yet', 'glpiai') . '</span>'
    : '<strong>' . round($reviews['rate'] * 100) . '%</strong>') . '</td>';
echo '</tr></tbody></table>';

if ($reviews['kinds'] !== []) {
    arsort($reviews['kinds']);
    $bits = [];
    foreach ($reviews['kinds'] as $kind => $count) {
        $bits[] = $e(Reviewer::label($kind)) . ' × ' . (int) $count;
    }
    echo "<div class='small text-muted mt-2'>" . __s('Most raised:', 'glpiai') . ' '
       . implode(', ', $bits) . '</div>';
}

if (((int) $cfg['reply_review_enabled']) === 1 && ((int) $cfg['enabled']) !== 1) {
    echo "<div class='text-orange small mt-2'>"
       . __s('Nothing will be reviewed while the master switch at the top of this page is off.', 'glpiai')
       . '</div>';
}
echo '</div>';

echo '</div></div>';

// ----------------------------------------------------------------- save bar
//
// Pinned rather than sitting at the bottom of a page this long. Every control
// above posts in one form, so an administrator who changes the master switch at
// the top has to reach the button to commit it — and used to do that by
// scrolling past four provider cards they had no interest in.
if ($can_edit) {
    echo "<div class='glpiai-savebar'>";
    echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . __s('Save') . '</button>';
    echo '</div>';
}

echo '</form>';

// Endpoint + token for the test buttons.
echo "<div id='glpiai-config-root' data-endpoint='" . $e(Url::to('ajax/test.php'))
   . "' data-csrf='" . $e(Session::getNewCSRFToken()) . "' "
   . "data-testing='" . __s('Testing…', 'glpiai') . "'></div>";

echo '</div>';

Html::footer();
