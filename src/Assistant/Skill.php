<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Assistant;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Glpiai\Settings;
use Html;

/**
 * A named block of instructions an administrator writes for the assistant.
 *
 * House instructions (Settings: `assistant_instructions`) are read on every
 * request and are the place for what is always true. A skill is the other half:
 * a procedure that matters when it matters — how a suspected ransomware call
 * is handled here, what to check before escalating a VPN fault, the wording of
 * a handover note. Putting those in the always-on prompt would work for one of
 * them and get steadily worse with each one added, because every line is read
 * on every request whether it is relevant or not, and a model given six
 * procedures at once follows none of them well.
 *
 * So a skill carries triggers: words that, appearing in what the technician
 * asked, bring its instructions into that request. No triggers means always on,
 * which is the honest reading of "no condition" and keeps a simple skill simple.
 *
 * Triggers are a guess made in advance, though, and the budget is a hard edge.
 * A skill whose triggers miss the wording the technician actually used, and one
 * that would not fit in what is left of the budget, both used to be
 * *invisible*: no trace in the prompt, nothing to say the site had a procedure
 * for exactly this. So the ones that are not carried are listed instead — a
 * name and a line each — and {@see Skillbox} gives the model a way to read one.
 * That is the same trade {@see \GlpiPlugin\Glpiai\Toolbox} makes for tools,
 * for the same reason: a catalogue entry costs a few words, and a procedure
 * nobody can see is indistinguishable from one that was never written.
 *
 * Deliberately not a tool. A tool is something the model *does*, and gets a
 * schema, a right and an audit entry. A skill is something it *knows* — it
 * changes the answer, not the world — so it needs none of that, and modelling
 * it as a tool would mean the model choosing whether to read its own
 * instructions.
 */
class Skill extends CommonDBTM
{
    public static string $rightname = 'plugin_glpiai_config';

    /** How much skill text one request may carry, in characters. */
    public const BUDGET = 8000;

    public static function getTypeName($nb = 0)
    {
        return _n('Assistant skill', 'Assistant skills', $nb, 'glpiai');
    }

    public static function getIcon()
    {
        return 'ti ti-bulb';
    }

    /**
     * The Setup-menu entry, and with it the Add button.
     *
     * GLPI takes a list page's action links from the menu registry, so an
     * itemtype absent from it gets a list that cannot be added to — with
     * nothing to say why.
     */
    public static function getMenuContent()
    {
        if (!\Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        // Derived, not written out. GLPI builds a plugin itemtype's URLs from
        // its namespace — `Assistant\\Skill` becomes `front/assistant/skill.php`
        // — so a hardcoded path here agrees with the menu and disagrees with
        // every link the search list renders.
        return [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::getFormURL(false),
            ],
        ];
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    public function post_getEmpty()
    {
        $this->fields['is_active']    = 1;
        $this->fields['is_recursive'] = 1;
    }

    /**
     * Every active skill in an entity, in name order.
     *
     * @return self[]
     */
    public static function active(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['is_active' => 1]
                    + getEntitiesRestrictCriteria(self::getTable(), '', $entities_id, true),
                'ORDER' => ['name'],
            ]) as $row
        ) {
            if (trim((string) $row['instructions']) === '') {
                continue;
            }

            $skill         = new self();
            $skill->fields = $row;
            $out[]         = $skill;
        }

        return $out;
    }

    /**
     * The skills that apply to one question, and the ones that did not.
     *
     * Matching is on whole words, case-insensitively, against the technician's
     * own text. Substring matching was the first attempt and is wrong in a way
     * that is hard to see: a skill triggered by "vpn" also fires on "vpns" —
     * fine — and one triggered by "ad" fires on "add", "bad" and "already",
     * which quietly attaches domain-controller instructions to half the
     * conversations in the estate.
     *
     * A skill that triggered but does not fit the budget goes in `rest` rather
     * than being dropped: it is the most relevant thing the model cannot see,
     * and it is precisely what search exists for.
     *
     * @return array{applied:self[],rest:self[]}
     */
    public static function select(string $question, int $entities_id): array
    {
        $applied = [];
        $rest    = [];
        $spent   = 0;

        foreach (self::active($entities_id) as $skill) {
            if (!$skill->triggeredBy($question)) {
                $rest[] = $skill;
                continue;
            }

            $text = trim((string) $skill->fields['instructions']);

            // A budget rather than a count. Ten one-line skills are cheaper
            // than one that pastes a runbook, and the thing that actually costs
            // money and dilutes attention is the characters.
            if ($spent + mb_strlen($text) > self::BUDGET) {
                $rest[] = $skill;
                continue;
            }

            $spent    += mb_strlen($text);
            $applied[] = $skill;
        }

        return ['applied' => $applied, 'rest' => $rest];
    }

    /**
     * The skills that apply to one question, in the entity it is asked in.
     *
     * @return self[]
     */
    public static function matching(string $question, int $entities_id): array
    {
        return self::select($question, $entities_id)['applied'];
    }

    /**
     * One line saying what this skill is for, for a catalogue entry.
     *
     * The administrator's own comment first, because that is the field whose
     * whole job is to say what something is. Failing that, the opening of the
     * instructions — which are written for the model and start, nearly always,
     * by saying when they apply.
     */
    public function summary(int $length = 110): string
    {
        $comment = trim((string) ($this->fields['comment'] ?? ''));

        if ($comment === '') {
            $lines = preg_split('/\R/', (string) $this->fields['instructions']) ?: [];

            // Headings are skipped rather than stripped. Nearly every skill
            // opens with one, and "When this applies" as a catalogue entry
            // tells a model nothing at all — the sentence under it is the one
            // that says what the procedure is for. A skill that is nothing but
            // headings falls back to the first of them on the second pass,
            // because a heading beats a blank line.
            foreach ([true, false] as $skip_headings) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);

                    if ($skip_headings && str_starts_with($line, '#')) {
                        continue;
                    }

                    $line = trim(ltrim($line, '#-*> '));

                    if ($line !== '') {
                        $comment = $line;
                        break 2;
                    }
                }
            }
        }

        $comment = preg_replace('/\s+/', ' ', $comment) ?? '';

        return mb_strlen($comment) > $length
            ? mb_substr($comment, 0, $length - 1) . '…'
            : $comment;
    }

    /** Does this skill apply to what was asked? */
    public function triggeredBy(string $question): bool
    {
        $triggers = self::triggerList((string) ($this->fields['triggers'] ?? ''));

        // No triggers is not "never" — it is an administrator who wrote a skill
        // and did not narrow it.
        if ($triggers === []) {
            return true;
        }

        foreach ($triggers as $trigger) {
            if (preg_match('/\b' . preg_quote($trigger, '/') . '/iu', $question) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    public static function triggerList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $word) {
            $word = trim($word);
            if ($word !== '') {
                $out[] = $word;
            }
        }

        return $out;
    }

    /** Catalogue entries listed for skills that were not carried. */
    public const MAX_CATALOGUE = 25;

    /**
     * The skills section of a system prompt, or '' when there are none.
     *
     * Two parts, and the second is the one that changed: what applies, in full,
     * followed by a line each for what exists and does not. A model that has
     * been told "there is a procedure here called Suspected ransomware" will
     * reach for {@see Skillbox} when a call comes in about encrypted files; one
     * that has been told nothing answers from general knowledge and sounds just
     * as confident doing it.
     */
    public static function instructionsFor(string $question, int $entities_id): string
    {
        $selected = self::select($question, $entities_id);

        if ($selected['applied'] === [] && $selected['rest'] === []) {
            return '';
        }

        $lines = [];

        if ($selected['applied'] !== []) {
            $lines[] = '';
            $lines[] = 'The following apply to this instance. They were written by an administrator here,';
            $lines[] = 'they are more specific than anything you know generally, and where they conflict';
            $lines[] = 'with your own habits they win:';

            foreach ($selected['applied'] as $skill) {
                $lines[] = '';
                $lines[] = '## ' . (string) $skill->fields['name'];
                $lines[] = trim((string) $skill->fields['instructions']);
            }
        }

        if ($selected['rest'] !== []) {
            $lines[] = '';
            $lines[] = 'This instance has other written procedures. They are not reproduced here —';
            $lines[] = 'read one with ' . Skillbox::NAME . ' before answering a question it covers,';
            $lines[] = 'and follow it over your own habits:';
            $lines[] = '';

            foreach (array_slice($selected['rest'], 0, self::MAX_CATALOGUE) as $skill) {
                $summary = $skill->summary();

                $lines[] = '  - ' . (string) $skill->fields['name']
                    . ($summary !== '' ? ' — ' . $summary : '');
            }

            $spare = count($selected['rest']) - self::MAX_CATALOGUE;
            if ($spare > 0) {
                $lines[] = sprintf('  - and %d more; %s finds those too.', $spare, Skillbox::NAME);
            }
        }

        return implode("\n", $lines);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        // Scope wrapper: this form is core-rendered, so without a plugin-owned
        // container the shipped dark-theme CSS could never reach its helper
        // text (see the dark section of the plugin stylesheet).
        echo "<div class='glpiai-scope'>";
        $this->showFormHeader($options);

        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        echo "<tr class='tab_bg_1'><td>" . __s('Name') . " <span class='text-red'>*</span></td><td>";
        echo Html::input('name', ['value' => $this->fields['name'], 'required' => 'required']);
        echo '</td>';
        echo '<td>' . __s('Active') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Triggers', 'glpiai') . '</td><td colspan="3">';
        echo Html::input('triggers', ['value' => $this->fields['triggers'], 'size' => 60]);
        echo "<div class='form-text'>"
           . __s('Comma-separated words. The skill is used when the technician\'s question '
               . 'contains one of them. Leave empty to apply it to every question — which is '
               . 'right for a short skill and expensive for a long one, since it is then read '
               . 'on every request.', 'glpiai')
           . '</div></td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Instructions', 'glpiai') . '</td><td colspan="3">';
        echo "<textarea class='form-control font-monospace' name='instructions' rows='12'>"
           . $e($this->fields['instructions']) . '</textarea>';
        echo "<div class='form-text'>"
           . sprintf(
               __s('Written for the model, not for a person: say what to do, in what order, and '
                 . 'what not to do. At most %s characters of skills are carried on any one '
                 . 'request; past that the rest are left out rather than truncated mid-sentence.',
                   'glpiai'),
               number_format(self::BUDGET)
           )
           . '</div></td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __s('Comments') . '</td><td colspan="3">';
        echo "<textarea class='form-control' name='comment' rows='2'>"
           . $e($this->fields['comment']) . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);
        echo '</div>';

        return true;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id'            => '1',
                'table'         => self::getTable(),
                'field'         => 'name',
                'name'          => __('Name'),
                'datatype'      => 'itemlink',
                'massiveaction' => false,
            ],
            [
                'id'       => '2',
                'table'    => self::getTable(),
                'field'    => 'triggers',
                'name'     => __('Triggers', 'glpiai'),
                'datatype' => 'text',
            ],
            [
                'id'       => '3',
                'table'    => self::getTable(),
                'field'    => 'is_active',
                'name'     => __('Active'),
                'datatype' => 'bool',
            ],
            [
                'id'       => '4',
                'table'    => 'glpi_entities',
                'field'    => 'completename',
                'name'     => \Entity::getTypeName(1),
                'datatype' => 'dropdown',
            ],
            [
                'id'       => '5',
                'table'    => self::getTable(),
                'field'    => 'comment',
                'name'     => __('Comments'),
                'datatype' => 'text',
            ],
        ];
    }
}
