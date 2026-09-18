<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The service-catalog and form-building tools.
 *
 * Writes are exercised against real rows inside a transaction that is rolled
 * back, because the things worth asserting here only exist in GLPI's own
 * plumbing: `Form::post_addItem()` creates the first section, the ticket
 * destination and the access policy, and `Question::prepareInput()` validates a
 * question's config against its type and refuses rather than storing something
 * the editor cannot render. A suite with a mock in front of either would assert
 * that this file's own arithmetic is self-consistent and nothing else. Every
 * form table is InnoDB, so the rollback genuinely undoes it; the last check
 * proves that rather than assuming it.
 *
 * The policy assertions are the point of the rest. A form is created INACTIVE
 * and nothing may switch it on — activating one puts it in front of every
 * requester in the entity, which is the most requester-facing act in this
 * plugin — so the refusal is tested in each of the three argument names a model
 * plausibly invents. `is_draft` is asserted separately and for the opposite
 * reason: it looks like the flag for "not published yet" and is in fact the
 * builder's autosave marker, which `Form::cronPurgeDraftForms()` permanently
 * purges on a schedule.
 *
 * Handlers are called directly rather than through `ToolRegistry::execute()`:
 * the write switch gates that path, and this suite should not need an
 * instance's configuration changed to run. tests/tools.php covers the switch.
 *
 * Usage, inside the GLPI container:
 *   php tests/forms.php
 */

require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use GlpiPlugin\Glpiai\ToolRegistry;
use GlpiPlugin\Glpiai\Tools\ReadForms;
use GlpiPlugin\Glpiai\Tools\WriteForms;

if (!(new Auth())->login('glpi', 'glpi', true)) {
    exit("cannot log in\n");
}

global $DB;
$fail = [];
function check(string $n, bool $ok, string $d = ''): void {
    global $fail;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $n . ($d !== '' ? " :: $d" : '') . "\n";
    if (!$ok) { $fail[] = $n; }
}

$names = [];
foreach (ToolRegistry::all(0) as $t) { $names[$t->name] = $t; }

echo "\nRegistration and policy\n";
foreach (['list_service_catalog','find_forms','read_form','list_question_types',
          'draft_form','update_form','add_form_question','update_form_question'] as $n) {
    check("$n is registered as a Tool instance", isset($names[$n]) && $names[$n] instanceof Tool);
}
$writes = ['draft_form','update_form','add_form_question','update_form_question'];
foreach ($writes as $n) {
    $t = $names[$n] ?? null;
    check("$n declares mutates + a right above READ", $t && $t->mutates && $t->right === 'form' && $t->right_level > READ,
        $t ? ('right_level=' . $t->right_level) : 'missing');
}
check('no form tool is pinned', !array_filter(
    array_intersect_key($names, array_flip(array_merge($writes, ['list_service_catalog','find_forms','read_form','list_question_types']))),
    static fn(Tool $t): bool => $t->pinned));
check('reads do not declare mutates', !($names['read_form']->mutates ?? true) && !($names['list_service_catalog']->mutates ?? true));

$ctx = new ToolContext(entities_id: 0);

echo "\nReads\n";
$types = ReadForms::runQuestionTypes([], $ctx);
$short = 'Glpi\Form\QuestionType\QuestionTypeShortText';
$typeNames = array_column($types['question_types'], 'type');
check('list_question_types returns real class names', in_array($short, $typeNames, true), count($typeNames) . ' types');
check('selectable types are flagged as taking options',
    (bool) array_filter($types['question_types'], static fn(array $t): bool =>
        ($t['type'] ?? '') === 'Glpi\Form\QuestionType\QuestionTypeDropdown' && ($t['takes_options'] ?? false)));

$cat = ReadForms::runCatalog([], $ctx);
check('list_service_catalog groups active forms under categories', isset($cat['catalog']) && $cat['catalog'] !== [],
    count($cat['catalog']) . ' categories');
$found = ReadForms::runFind(['only' => 'all'], $ctx);
check('find_forms lists forms with their active state',
    $found['count'] > 0 && array_key_exists('active', $found['forms'][0]));

echo "\nWrites (inside a transaction that is rolled back)\n";
$DB->beginTransaction();
try {
    $made = WriteForms::runDraft([
        'name'        => 'ZZ AI check — new starter',
        'description' => 'Raised when somebody joins.',
        'category'    => 'General',
        'questions'   => [
            ['label' => 'Full name', 'type' => $short, 'mandatory' => true],
            ['label' => 'Start date', 'type' => 'Glpi\Form\QuestionType\QuestionTypeDateTime'],
            ['label' => 'Which team?', 'type' => 'Glpi\Form\QuestionType\QuestionTypeDropdown',
             'options' => ['Support', 'Finance', 'Field']],
            ['label' => 'Bad one', 'type' => 'Glpi\Form\QuestionType\QuestionTypeNope'],
        ],
    ], $ctx);

    $id = $made['created']['id'];
    check('draft_form creates the form', $id > 0);
    check('created INACTIVE', $made['active'] === false);
    check('three good questions added, the invented type refused',
        count($made['questions']) === 3 && count($made['refused'] ?? []) === 1,
        ($made['refused'][0]['reason'] ?? ''));

    $form = new Glpi\Form\Form();
    $form->getFromDB($id);
    check('is_active is 0 in the database', (int) $form->fields['is_active'] === 0);
    // The trap: is_draft marks a form the purge cron deletes.
    check('is_draft is 0, so the purge cron will not eat it', (int) $form->fields['is_draft'] === 0);
    check('placed in the named category', (int) $form->fields['forms_categories_id'] > 0);

    $read = ReadForms::runRead(['forms_id' => $id], $ctx);
    check('read_form reports it as inactive with a note', ($read['form']['active'] ?? true) === false && isset($read['note']));
    check('read_form reports what submitting it creates',
        str_contains(implode(',', $read['creates']), 'Ticket'), implode(',', $read['creates']));
    $dropdown = null;
    foreach ($read['sections'][0]['questions'] as $q) { if ($q['label'] === 'Which team?') { $dropdown = $q; } }
    check('dropdown choices stored 1-indexed and read back',
        ($dropdown['config']['options'] ?? []) == [1 => 'Support', 2 => 'Finance', 3 => 'Field'],
        json_encode($dropdown['config'] ?? null));

    $added = WriteForms::runAddQuestion(['forms_id' => $id, 'label' => 'Manager', 'type' => $short], $ctx);
    check('add_form_question appends to the first section', ($added['added']['id'] ?? 0) > 0);

    $upd = WriteForms::runUpdateQuestion(['questions_id' => $dropdown['id'], 'options' => ['Support', 'Finance']], $ctx);
    check('update_form_question replaces the choice list', in_array('extra_data', $upd['updated'], true));

    $renamed = WriteForms::runUpdate(['forms_id' => $id, 'name' => 'ZZ renamed'], $ctx);
    check('update_form renames it', in_array('name', $renamed['updated'], true));

    foreach ([['is_active' => 1], ['publish' => true], ['enabled' => 'yes']] as $sneaky) {
        try {
            WriteForms::runUpdate(['forms_id' => $id] + $sneaky, $ctx);
            check('activation refused: ' . json_encode($sneaky), false, 'it went through');
        } catch (ToolException $e) {
            check('activation refused: ' . json_encode($sneaky), str_contains($e->getMessage(), 'switched on'));
        }
    }

    try {
        WriteForms::runAddQuestion(['forms_id' => $id, 'label' => 'x', 'type' => $short, 'options' => ['a']], $ctx);
        check('options on a non-selectable type refused', false, 'accepted');
    } catch (ToolException $e) {
        check('options on a non-selectable type refused', str_contains($e->getMessage(), 'no choice list'));
    }

    try {
        WriteForms::runDraft(['name' => 'x', 'category' => 'Nonexistent'], $ctx);
        check('an unknown category is refused with the real list', false, 'accepted');
    } catch (ToolException $e) {
        check('an unknown category is refused with the real list', str_contains($e->getMessage(), 'General'));
    }
} finally {
    $DB->rollBack();
}

$left = $DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['name' => ['LIKE', 'ZZ%']]]);
check('nothing survived the rollback', count(iterator_to_array($left)) === 0);

echo "\n" . ($fail === [] ? "\033[32mAll checks passed\033[0m\n" : "\033[31m" . count($fail) . " failed: " . implode(', ', $fail) . "\033[0m\n");
