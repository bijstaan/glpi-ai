<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Glpi\Form\Category as FormCategory;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Form\Section;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use Session;

/**
 * Reading the service catalog, and the forms behind it.
 *
 * The catalog is the part of GLPI a requester actually sees — the tiles on the
 * helpdesk home — and it is invisible to every other tool here. "Can people
 * request a new laptop?" is answered by a form existing, being active, and
 * sitting in a category somebody will look in, and none of those three facts
 * appears on a ticket.
 *
 * Four tools rather than one, because they answer questions asked by different
 * people:
 *
 *  - {@see catalog()} is the requester's view: what can be requested at all.
 *    Active forms only, because an inactive form is not a thing anybody can
 *    ask for and listing it would answer the question wrongly.
 *  - {@see find()} is the administrator's inventory, inactive forms included —
 *    the one that answers "do we already have a form for this?", which is the
 *    question worth asking before building another one.
 *  - {@see read()} is one form in full, down to each question and its type. It
 *    is also what makes the write tools usable: a question is added to a
 *    *section*, and the section ids only exist here.
 *  - {@see questionTypes()} lists the question types this GLPI actually has.
 *    The type is stored as a PHP class name — `Glpi\Form\QuestionType\
 *    QuestionTypeShortText` — and a model guessing at one produces a question
 *    that saves and then renders as nothing at all. It is a lookup table, not
 *    a search, and it exists so that guessing is never necessary.
 *
 * None of them writes. All four gate on the `form` right, which is also what
 * gates the form editor itself.
 */
final class ReadForms
{
    /** Rows before the answer stops being readable. */
    private const LIMIT = 30;

    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::catalog(), self::find(), self::read(), self::questionTypes()];
    }

    // -------------------------------------------------------------- catalog

    private static function catalog(): Tool
    {
        return new Tool(
            name: 'list_service_catalog',
            description: 'The service catalog: every request form a user can actually submit, '
                . 'grouped by the category it appears under on the helpdesk home page. Use it to '
                . 'answer what can be requested, what self-service already exists, or which form '
                . 'somebody should have used — and before suggesting a new form is needed, '
                . 'because the usual answer is that one exists in a category nobody looks in. '
                . 'Only active forms appear, which is exactly what a requester sees.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Optional. Narrow to forms or categories matching these '
                            . 'words.',
                    ],
                ],
            ],
            handler: [self::class, 'runCatalog'],
            right: 'form',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runCatalog(array $arguments, ToolContext $context): array
    {
        self::requireForms();

        $search = trim((string) ($arguments['search'] ?? ''));

        $criteria = ['is_active' => 1, 'is_deleted' => 0, 'is_draft' => 0];
        if ($search !== '') {
            $criteria[] = ['OR' => [
                ['name' => ['LIKE', '%' . $search . '%']],
                ['description' => ['LIKE', '%' . $search . '%']],
            ]];
        }

        $grouped    = [];
        $uncategorised = [];

        foreach ((new Form())->find($criteria, ['name ASC'], self::LIMIT) as $row) {
            $entry = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                // The catalog tile's blurb, not the form header — this is what a
                // requester reads when choosing.
                'description' => Lookup::plain((string) $row['description'], 300),
                'submissions' => (int) $row['usage_count'],
            ];

            $category_id = (int) $row['forms_categories_id'];
            if ($category_id <= 0) {
                $uncategorised[] = $entry;
                continue;
            }

            $grouped[$category_id]['forms'][] = $entry;
        }

        $categories = [];
        foreach ($grouped as $category_id => $bucket) {
            $category = new FormCategory();
            $categories[] = [
                // completename, so a nested category reads as the path a
                // requester navigates rather than a leaf name with no context.
                'category' => $category->getFromDB($category_id)
                    ? (string) ($category->fields['completename'] ?: $category->fields['name'])
                    : 'Category ' . $category_id,
                'forms'    => $bucket['forms'],
            ];
        }

        usort($categories, static fn(array $a, array $b): int => strcmp($a['category'], $b['category']));

        if ($uncategorised !== []) {
            // Worth naming rather than folding into the list: a form with no
            // category still appears in the catalog, and it is nearly always an
            // oversight rather than a decision.
            $categories[] = ['category' => '(no category)', 'forms' => $uncategorised];
        }

        return [
            'catalog' => $categories,
            'note'    => $categories === []
                ? 'No active forms. Either none has been built, or they exist but are inactive — '
                    . 'find_forms shows those.'
                : null,
        ];
    }

    // ----------------------------------------------------------------- find

    private static function find(): Tool
    {
        return new Tool(
            name: 'find_forms',
            description: 'Search every request form, including the inactive ones a requester '
                . 'cannot see. Use it before building a form — a form for this may already exist '
                . 'and simply never have been switched on — and to check what state a form is in. '
                . 'Reports whether each form is active, which category it sits in, and how many '
                . 'questions it has.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Words from the form name or description.',
                    ],
                    'only'   => [
                        'type'        => 'string',
                        'enum'        => ['all', 'active', 'inactive'],
                        'description' => 'Defaults to all.',
                    ],
                ],
            ],
            handler: [self::class, 'runFind'],
            right: 'form',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runFind(array $arguments, ToolContext $context): array
    {
        self::requireForms();

        $search = trim((string) ($arguments['search'] ?? ''));
        $only   = (string) ($arguments['only'] ?? 'all');

        $criteria = ['is_deleted' => 0];

        if ($only === 'active') {
            $criteria['is_active'] = 1;
        } elseif ($only === 'inactive') {
            $criteria['is_active'] = 0;
        }

        if ($search !== '') {
            $criteria[] = ['OR' => [
                ['name' => ['LIKE', '%' . $search . '%']],
                ['description' => ['LIKE', '%' . $search . '%']],
            ]];
        }

        $forms = [];
        foreach ((new Form())->find($criteria, ['name ASC'], self::LIMIT) as $row) {
            $forms[] = array_filter([
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'active'      => (bool) $row['is_active'],
                'category'    => self::categoryName((int) $row['forms_categories_id']),
                'entity'      => Lookup::entityName((int) $row['entities_id']),
                'questions'   => self::countQuestions((int) $row['id']),
                'submissions' => (int) $row['usage_count'],
                'url'         => Form::getFormURLWithID((int) $row['id']),
                // A form still being edited in the builder. Named because it is
                // not a state anybody chose: GLPI purges these on a schedule.
                'unsaved_builder_draft' => ((int) $row['is_draft']) === 1 ? true : null,
            ], static fn($v): bool => $v !== null);
        }

        return ['forms' => $forms, 'count' => count($forms)];
    }

    // ----------------------------------------------------------------- read

    private static function read(): Tool
    {
        return new Tool(
            name: 'read_form',
            description: 'One request form in full: its sections, every question with its type '
                . 'and whether it is mandatory, the category it appears under, what it creates '
                . 'when submitted, and whether it is active. Read a form before changing it — '
                . 'adding a question needs the id of the section to put it in, and those ids are '
                . 'only here.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'forms_id' => [
                        'type'        => 'integer',
                        'description' => 'The form id, from find_forms or list_service_catalog.',
                    ],
                ],
                'required'   => ['forms_id'],
            ],
            handler: [self::class, 'runRead'],
            right: 'form',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runRead(array $arguments, ToolContext $context): array
    {
        self::requireForms();

        $form = self::load((int) ($arguments['forms_id'] ?? 0));

        $sections = [];
        foreach ((new Section())->find([Form::getForeignKeyField() => $form->getID()], ['rank ASC']) as $section) {
            $questions = [];

            foreach (
                (new Question())->find(
                    [Section::getForeignKeyField() => (int) $section['id']],
                    ['vertical_rank ASC', 'horizontal_rank ASC']
                ) as $question
            ) {
                $questions[] = array_filter([
                    'id'          => (int) $question['id'],
                    'label'       => (string) $question['name'],
                    'type'        => (string) $question['type'],
                    'mandatory'   => (bool) $question['is_mandatory'],
                    'description' => Lookup::plain((string) $question['description'], 300) ?: null,
                    // Decoded rather than passed through as a JSON string: the
                    // choices on a dropdown are the part anybody asks about.
                    'config'      => self::decode((string) ($question['extra_data'] ?? '')),
                ], static fn($v): bool => $v !== null && $v !== '');
            }

            $sections[] = array_filter([
                'id'          => (int) $section['id'],
                'name'        => (string) $section['name'],
                'description' => Lookup::plain((string) $section['description'], 300) ?: null,
                'questions'   => $questions,
            ], static fn($v): bool => $v !== null);
        }

        return array_filter([
            'form' => array_filter([
                'id'          => $form->getID(),
                'name'        => (string) $form->fields['name'],
                'active'      => (bool) $form->fields['is_active'],
                'category'    => self::categoryName((int) $form->fields['forms_categories_id']),
                'entity'      => Lookup::entityName((int) $form->fields['entities_id']),
                'header'      => Lookup::plain((string) $form->fields['header'], 600) ?: null,
                'description' => Lookup::plain((string) $form->fields['description'], 600) ?: null,
                'submissions' => (int) $form->fields['usage_count'],
                'url'         => Form::getFormURLWithID($form->getID()),
            ], static fn($v): bool => $v !== null),
            'sections'  => $sections,
            'creates'   => self::destinations($form->getID()),
            'note'      => (bool) $form->fields['is_active']
                ? null
                : 'This form is INACTIVE: it does not appear in the service catalog and nobody '
                    . 'can submit it. Switching it on is done by a person in the form editor.',
        ], static fn($v): bool => $v !== null);
    }

    // -------------------------------------------------------- question types

    private static function questionTypes(): Tool
    {
        return new Tool(
            name: 'list_question_types',
            description: 'The question types this GLPI offers for building a request form — '
                . 'short text, long text, number, email, date, dropdown, checkbox, radio, '
                . 'urgency, requester, observer, assignee, file upload and the rest — each with '
                . 'the exact type name to use. Call this before adding a question to a form: the '
                . 'type is a class name, and an invented one saves without error and then renders '
                . 'as an empty field.',
            schema: ['type' => 'object', 'properties' => []],
            handler: [self::class, 'runQuestionTypes'],
            right: 'form',
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runQuestionTypes(array $arguments, ToolContext $context): array
    {
        self::requireForms();

        $types = [];

        foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $type) {
            $class = $type::class;

            $types[] = array_filter([
                'type'  => $class,
                'label' => method_exists($type, 'getName') ? (string) $type->getName() : $class,
                // Only the selectable types take a choice list, and a model that
                // sends one to a short-text question gets a field that ignores
                // it silently. Saying which take options is cheaper than a
                // paragraph explaining when they are read.
                'takes_options' => WriteForms::takesOptions($class) ? true : null,
            ], static fn($v): bool => $v !== null);
        }

        return [
            'question_types' => $types,
            'note'           => 'Use the `type` value exactly as written. Types marked '
                . 'takes_options need an `options` list; the rest ignore one.',
        ];
    }

    // -------------------------------------------------------------- helpers

    /**
     * The form, if this person may read it.
     *
     * `can()` rather than `canViewItem()`: the right says "may use the form
     * editor" and `can()` says "may use it on this form", which is where the
     * entity restriction lives.
     */
    public static function load(int $id, int $right = READ): Form
    {
        if ($id <= 0) {
            throw new ToolException('A form id is required.');
        }

        $form = new Form();

        if (!$form->getFromDB($id)) {
            throw new ToolException("No form with id $id.");
        }

        if (!$form->can($id, $right)) {
            throw new ToolException("You may not " . ($right === READ ? 'read' : 'change') . " form $id.");
        }

        return $form;
    }

    public static function requireForms(): void
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        if (!Session::haveRight('form', READ)) {
            throw new ToolException('You may not read request forms.');
        }
    }

    private static function categoryName(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $category = new FormCategory();

        return $category->getFromDB($id)
            ? (string) ($category->fields['completename'] ?: $category->fields['name'])
            : null;
    }

    private static function countQuestions(int $forms_id): int
    {
        $count = 0;

        foreach ((new Section())->find([Form::getForeignKeyField() => $forms_id]) as $section) {
            $count += count((new Question())->find([Section::getForeignKeyField() => (int) $section['id']]));
        }

        return $count;
    }

    /**
     * What submitting this form produces.
     *
     * A form with no destination is a form that collects answers and does
     * nothing with them, which looks identical to a working one until somebody
     * submits it.
     *
     * @return list<string>
     */
    private static function destinations(int $forms_id): array
    {
        global $DB;

        $out = [];

        foreach (
            $DB->request([
                'FROM'  => 'glpi_forms_destinations_formdestinations',
                'WHERE' => [Form::getForeignKeyField() => $forms_id],
            ]) as $row
        ) {
            $out[] = (string) ($row['name'] ?: $row['itemtype']);
        }

        return $out === [] ? ['(nothing — this form creates no ticket when submitted)'] : $out;
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
