<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Glpi\Form\Category as FormCategory;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionTypeSelectable;
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Form\Section;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;

/**
 * Building and amending request forms.
 *
 * Writing a form is the rare case where a model is genuinely better placed than
 * the person asking: the technician knows which six questions would have saved
 * them the three emails they just sent, and does not know that GLPI spells a
 * dropdown `Glpi\Form\QuestionType\QuestionTypeDropdown`. So the plugin's write
 * policy applies as it does everywhere else, with one addition that matters
 * more here than anywhere.
 *
 * **A form is created inactive and nothing here can activate it.** `is_active`
 * is what puts a form on the helpdesk home page in front of every requester in
 * the entity, which makes switching it on the most requester-facing act
 * available anywhere in this plugin — squarely on the far side of the line the
 * rest of the tools hold. There is no `active` argument to set and no tool that
 * sets one; a person opens the form editor and turns it on. That is the same
 * rule {@see WriteKnowledge} follows for publishing an article, for the same
 * reason.
 *
 * **`is_draft` is not that flag, and must stay 0.** It looks like the right one
 * and is the opposite of harmless: it marks a form still open in the builder,
 * and `Form::cronPurgeDraftForms()` *permanently purges* any form carrying it
 * once it is older than the retention period. A form drafted here with
 * `is_draft = 1` would quietly disappear days later, so it is set explicitly
 * rather than left to a default that might change.
 *
 * Nothing here deletes. A question that turned out wrong is edited; a form that
 * turned out wrong stays inactive, which is where it already was.
 */
final class WriteForms
{
    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::draft(), self::update(), self::addQuestion(), self::updateQuestion()];
    }

    // ---------------------------------------------------------------- draft

    private static function draft(): Tool
    {
        return new Tool(
            name: 'draft_form',
            description: 'Build a new request form for the service catalog — a self-service form '
                . 'a user fills in to raise a ticket, such as new starter, access request, or '
                . 'hardware order. Create it with the questions that would stop the back-and-forth '
                . 'on this kind of request. The form is created INACTIVE: it does not appear in '
                . 'the catalog and nobody can submit it until a person reviews it and switches it '
                . 'on, so say that rather than implying it is live. Check find_forms first — the '
                . 'form may already exist and simply be switched off.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'What a requester will see on the tile. Name the request, '
                            . 'not the process: "Request a new laptop", not "Hardware workflow".',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'One or two lines under the tile, saying when to use this '
                            . 'form and what happens next.',
                    ],
                    'header'      => [
                        'type'        => 'string',
                        'description' => 'Optional. Shown at the top of the form itself — anything '
                            . 'the requester should read before filling it in.',
                    ],
                    'category'    => [
                        'type'        => 'string',
                        'description' => 'The catalog category it belongs under, by name. Omit to '
                            . 'leave it uncategorised. An unknown name is refused with the list of '
                            . 'real ones.',
                    ],
                    'questions'   => [
                        'type'        => 'array',
                        'description' => 'The questions, in the order they should be asked. Call '
                            . 'list_question_types first — type is an exact class name.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'label'       => ['type' => 'string', 'description' => 'The question as the requester reads it.'],
                                'type'        => ['type' => 'string', 'description' => 'Exact type from list_question_types.'],
                                'mandatory'   => ['type' => 'boolean', 'description' => 'Defaults to false.'],
                                'description' => ['type' => 'string', 'description' => 'Optional help text under the field.'],
                                'options'     => [
                                    'type'        => 'array',
                                    'items'       => ['type' => 'string'],
                                    'description' => 'The choices, for dropdown, radio and checkbox '
                                        . 'questions only.',
                                ],
                            ],
                            'required'   => ['label', 'type'],
                        ],
                    ],
                ],
                'required'   => ['name'],
            ],
            handler: [self::class, 'runDraft'],
            mutates: true,
            right: 'form',
            right_level: CREATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runDraft(array $arguments, ToolContext $context): array
    {
        ReadForms::requireForms();
        self::refuseActivation($arguments);

        if (!Form::canCreate()) {
            throw new ToolException('You may not create request forms.');
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new ToolException('A form needs a name.');
        }

        $form = new Form();
        $id   = (int) $form->add([
            'name'                 => mb_substr($name, 0, 250),
            'description'          => trim((string) ($arguments['description'] ?? '')),
            'header'               => Markdown::toHtml(trim((string) ($arguments['header'] ?? ''))),
            'forms_categories_id'  => self::resolveCategory((string) ($arguments['category'] ?? '')),
            'entities_id'          => $context->entities_id,
            'is_recursive'         => 0,
            // The two flags this class exists to be careful about.
            'is_active'            => 0,
            'is_draft'             => 0,
            // What the editor itself writes for a hand-built form. Left to the
            // column defaults these are empty strings, which render as neither
            // layout and a submit button with no visibility rule.
            'render_layout'                     => 'step_by_step',
            'submit_button_visibility_strategy' => 'always_visible',
        ]);

        if ($id <= 0) {
            throw new ToolException('GLPI would not create the form.');
        }

        // post_addItem() has already created the first section, the default
        // ticket destination and the access policy, so the form is complete
        // apart from being switched off.
        $section = self::firstSection($id);
        $added   = [];
        $refused = [];

        foreach ((array) ($arguments['questions'] ?? []) as $rank => $question) {
            if (!is_array($question)) {
                continue;
            }

            try {
                $added[] = self::createQuestion($section, $question, (int) $rank);
            } catch (ToolException $e) {
                // One bad question does not throw the other five away: the form
                // exists either way, and a refusal the model can read is what
                // lets it fix that question on the next turn.
                $refused[] = ['label' => (string) ($question['label'] ?? '?'), 'reason' => $e->getMessage()];
            }
        }

        return array_filter([
            'created'   => [
                'id'   => $id,
                'name' => $name,
                'url'  => Form::getFormURLWithID($id),
            ],
            'active'    => false,
            'questions' => $added,
            'refused'   => $refused !== [] ? $refused : null,
            'note'      => 'Created INACTIVE. It is not in the service catalog and cannot be '
                . 'submitted until somebody opens it and switches it on. Give the technician the '
                . 'link and say it needs reviewing first.',
        ], static fn($v): bool => $v !== null);
    }

    // --------------------------------------------------------------- update

    private static function update(): Tool
    {
        return new Tool(
            name: 'update_form',
            description: 'Change an existing request form\'s wording or where it sits in the '
                . 'service catalog: its name, the description under its tile, the header shown '
                . 'above the questions, or its category. Cannot switch a form on or off and '
                . 'cannot move it between entities. To change the questions, use '
                . 'add_form_question and update_form_question.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'forms_id'    => ['type' => 'integer', 'description' => 'The form to change.'],
                    'name'        => ['type' => 'string', 'description' => 'New name. Omit to leave it.'],
                    'description' => ['type' => 'string', 'description' => 'New catalog description.'],
                    'header'      => ['type' => 'string', 'description' => 'New header above the questions.'],
                    'category'    => ['type' => 'string', 'description' => 'Move it to this catalog category, by name.'],
                ],
                'required'   => ['forms_id'],
            ],
            handler: [self::class, 'runUpdate'],
            mutates: true,
            right: 'form',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runUpdate(array $arguments, ToolContext $context): array
    {
        ReadForms::requireForms();
        self::refuseActivation($arguments);

        $form  = ReadForms::load((int) ($arguments['forms_id'] ?? 0), UPDATE);
        $input = ['id' => $form->getID()];

        if (isset($arguments['name']) && trim((string) $arguments['name']) !== '') {
            $input['name'] = mb_substr(trim((string) $arguments['name']), 0, 250);
        }

        if (isset($arguments['description'])) {
            $input['description'] = trim((string) $arguments['description']);
        }

        if (isset($arguments['header'])) {
            $input['header'] = Markdown::toHtml(trim((string) $arguments['header']));
        }

        if (isset($arguments['category']) && trim((string) $arguments['category']) !== '') {
            $input['forms_categories_id'] = self::resolveCategory((string) $arguments['category']);
        }

        if (count($input) === 1) {
            throw new ToolException('Nothing to change: give at least one of name, description, header or category.');
        }

        if (!$form->update($input)) {
            throw new ToolException('GLPI would not save the change.');
        }

        return [
            'updated' => array_keys(array_diff_key($input, ['id' => null])),
            'form'    => ['id' => $form->getID(), 'url' => Form::getFormURLWithID($form->getID())],
            'active'  => (bool) $form->fields['is_active'],
        ];
    }

    // --------------------------------------------------------- add question

    private static function addQuestion(): Tool
    {
        return new Tool(
            name: 'add_form_question',
            description: 'Add a question to an existing request form — another field the '
                . 'requester has to fill in. Call list_question_types for the exact type name and '
                . 'read_form for the section to put it in. The question is added at the end of '
                . 'the section.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'forms_id'          => [
                        'type'        => 'integer',
                        'description' => 'The form. Its first section is used unless '
                            . 'forms_sections_id says otherwise.',
                    ],
                    'forms_sections_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional. A specific section, from read_form.',
                    ],
                    'label'             => ['type' => 'string', 'description' => 'The question as the requester reads it.'],
                    'type'              => ['type' => 'string', 'description' => 'Exact type from list_question_types.'],
                    'mandatory'         => ['type' => 'boolean', 'description' => 'Defaults to false.'],
                    'description'       => ['type' => 'string', 'description' => 'Optional help text under the field.'],
                    'options'           => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'The choices, for dropdown, radio and checkbox questions only.',
                    ],
                ],
                'required'   => ['forms_id', 'label', 'type'],
            ],
            handler: [self::class, 'runAddQuestion'],
            mutates: true,
            right: 'form',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runAddQuestion(array $arguments, ToolContext $context): array
    {
        ReadForms::requireForms();

        $form = ReadForms::load((int) ($arguments['forms_id'] ?? 0), UPDATE);

        $sections_id = (int) ($arguments['forms_sections_id'] ?? 0);
        if ($sections_id > 0) {
            // Checked against this form rather than trusted: a section id from
            // another form would otherwise edit a form the right was never
            // tested against.
            $section = new Section();
            if (
                !$section->getFromDB($sections_id)
                || (int) $section->fields[Form::getForeignKeyField()] !== $form->getID()
            ) {
                throw new ToolException("Section $sections_id does not belong to form " . $form->getID() . '.');
            }
        } else {
            $sections_id = self::firstSection($form->getID());
        }

        $created = self::createQuestion($sections_id, $arguments, self::nextRank($sections_id));

        return [
            'added'  => $created,
            'form'   => ['id' => $form->getID(), 'url' => Form::getFormURLWithID($form->getID())],
            'active' => (bool) $form->fields['is_active'],
            'note'   => (bool) $form->fields['is_active']
                ? 'This form is live, so the new question is being asked of requesters now.'
                : 'This form is still inactive; the question will be asked once somebody switches it on.',
        ];
    }

    // ------------------------------------------------------ update question

    private static function updateQuestion(): Tool
    {
        return new Tool(
            name: 'update_form_question',
            description: 'Change a question on a request form: its wording, its help text, '
                . 'whether it is mandatory, or the list of choices on a dropdown, radio or '
                . 'checkbox. Use read_form for the question id. Cannot change a question\'s type '
                . 'and cannot remove one.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'questions_id' => ['type' => 'integer', 'description' => 'The question, from read_form.'],
                    'label'        => ['type' => 'string', 'description' => 'New wording.'],
                    'description'  => ['type' => 'string', 'description' => 'New help text.'],
                    'mandatory'    => ['type' => 'boolean', 'description' => 'Whether it must be answered.'],
                    'options'      => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Replaces the choice list, on a question type that has one.',
                    ],
                ],
                'required'   => ['questions_id'],
            ],
            handler: [self::class, 'runUpdateQuestion'],
            mutates: true,
            right: 'form',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runUpdateQuestion(array $arguments, ToolContext $context): array
    {
        ReadForms::requireForms();

        $questions_id = (int) ($arguments['questions_id'] ?? 0);
        $question     = new Question();

        if ($questions_id <= 0 || !$question->getFromDB($questions_id)) {
            throw new ToolException("No question with id $questions_id.");
        }

        // The right lives on the form, not the question: reaching the parent is
        // what tests the entity restriction.
        $section = new Section();
        if (!$section->getFromDB((int) $question->fields[Section::getForeignKeyField()])) {
            throw new ToolException('That question has no section.');
        }

        $form = ReadForms::load((int) $section->fields[Form::getForeignKeyField()], UPDATE);

        $input = ['id' => $questions_id];

        if (isset($arguments['label']) && trim((string) $arguments['label']) !== '') {
            $input['name'] = mb_substr(trim((string) $arguments['label']), 0, 250);
        }

        if (isset($arguments['description'])) {
            $input['description'] = trim((string) $arguments['description']);
        }

        if (isset($arguments['mandatory'])) {
            $input['is_mandatory'] = self::boolean($arguments['mandatory']) ? 1 : 0;
        }

        if (isset($arguments['options'])) {
            $type = (string) $question->fields['type'];

            if (!self::takesOptions($type)) {
                throw new ToolException("A $type question has no choice list.");
            }

            $input['extra_data'] = self::options((array) $arguments['options']);
        }

        if (count($input) === 1) {
            throw new ToolException('Nothing to change: give at least one of label, description, mandatory or options.');
        }

        if (!$question->update($input)) {
            throw new ToolException('GLPI would not save the change — check the choices are valid for this question type.');
        }

        return [
            'updated' => array_keys(array_diff_key($input, ['id' => null])),
            'form'    => ['id' => $form->getID(), 'url' => Form::getFormURLWithID($form->getID())],
        ];
    }

    // -------------------------------------------------------------- helpers

    /** Does this question type carry a list of choices? */
    public static function takesOptions(string $type): bool
    {
        return is_a($type, AbstractQuestionTypeSelectable::class, true);
    }

    /**
     * One question, created and reported.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private static function createQuestion(int $sections_id, array $spec, int $rank): array
    {
        $label = trim((string) ($spec['label'] ?? ''));
        $type  = trim((string) ($spec['type'] ?? ''));

        if ($label === '') {
            throw new ToolException('A question needs a label.');
        }

        self::requireKnownType($type);

        $input = [
            Section::getForeignKeyField() => $sections_id,
            'name'                        => mb_substr($label, 0, 250),
            'type'                        => $type,
            'is_mandatory'                => self::boolean($spec['mandatory'] ?? false) ? 1 : 0,
            'description'                 => trim((string) ($spec['description'] ?? '')),
            'vertical_rank'               => $rank,
        ];

        if (isset($spec['options'])) {
            if (!self::takesOptions($type)) {
                throw new ToolException("A $type question has no choice list — leave options out.");
            }

            $input['extra_data'] = self::options((array) $spec['options']);
        }

        $question = new Question();
        $id       = (int) $question->add($input);

        if ($id <= 0) {
            // GLPI validates extra_data against the question type and refuses
            // rather than storing something the editor cannot render, so this is
            // the usual landing place for a type that needed options and got none.
            throw new ToolException(
                "GLPI refused the question \"$label\" — check the type is right and that a "
                . 'question type needing choices was given some.'
            );
        }

        return ['id' => $id, 'label' => $label, 'type' => $type];
    }

    /**
     * The choice list, in the shape the selectable types read.
     *
     * Stored as a 1-indexed map rather than a list: the stored answer is the
     * key, and a 0-indexed one collides with "nothing selected".
     *
     * @param list<mixed> $options
     * @return array<string,mixed>
     */
    private static function options(array $options): array
    {
        $values = [];

        foreach (array_values($options) as $index => $option) {
            $value = trim((string) $option);
            if ($value !== '') {
                $values[$index + 1] = $value;
            }
        }

        if ($values === []) {
            throw new ToolException('A choice list needs at least one option.');
        }

        return ['options' => $values];
    }

    private static function requireKnownType(string $type): void
    {
        foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $known) {
            if ($known::class === $type) {
                return;
            }
        }

        throw new ToolException(
            "\"$type\" is not a question type in this GLPI. Call list_question_types and use one "
            . 'of the values it returns, exactly as written.'
        );
    }

    /**
     * The form's first section.
     *
     * Every form has one — GLPI creates it on save — so an absence here means
     * the form was built by something that skipped `post_addItem()`.
     */
    private static function firstSection(int $forms_id): int
    {
        $sections = (new Section())->find([Form::getForeignKeyField() => $forms_id], ['rank ASC'], 1);
        $section  = reset($sections);

        if ($section === false) {
            throw new ToolException("Form $forms_id has no section to put a question in.");
        }

        return (int) $section['id'];
    }

    private static function nextRank(int $sections_id): int
    {
        return count((new Question())->find([Section::getForeignKeyField() => $sections_id]));
    }

    /**
     * Refuse to publish, in the argument the model most plausibly invents.
     *
     * The schemas above declare no such field, so this only ever fires on a
     * model that tried anyway — and answering it with a plain refusal that says
     * who does switch a form on is more useful than ignoring the argument and
     * letting the answer claim the form is live.
     *
     * @param array<string,mixed> $arguments
     */
    private static function refuseActivation(array $arguments): void
    {
        foreach (['is_active', 'active', 'publish', 'published', 'enable', 'enabled'] as $key) {
            if (array_key_exists($key, $arguments)) {
                throw new ToolException(
                    'A form cannot be switched on from here. Activating a form puts it in front '
                    . 'of every requester in the entity, so it is done by a person in the form '
                    . 'editor. Create or amend the form and say it needs switching on.'
                );
            }
        }
    }

    private static function resolveCategory(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            return 0;
        }

        $category   = new FormCategory();
        $candidates = [];

        foreach ($category->find([], ['completename ASC']) as $row) {
            $candidates[] = (string) $row['completename'];

            foreach ([(string) $row['name'], (string) $row['completename']] as $match) {
                if (mb_strtolower($match) === mb_strtolower($name)) {
                    return (int) $row['id'];
                }
            }
        }

        throw new ToolException(
            "There is no form category called \"$name\". The categories are: "
            . ($candidates === [] ? '(none has been created yet)' : implode('; ', $candidates))
            . '. Use one of those, or leave the category out.'
        );
    }

    private static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }
}
