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
 * a procedure that matters when it matters — how this MSP handles a suspected
 * ransomware call, what to check before escalating a VPN fault, the wording of
 * a handover note. Putting those in the always-on prompt would work for one of
 * them and get steadily worse with each one added, because every line is read
 * on every request whether it is relevant or not, and a model given six
 * procedures at once follows none of them well.
 *
 * So a skill carries triggers: words that, appearing in what the technician
 * asked, bring its instructions into that request. No triggers means always on,
 * which is the honest reading of "no condition" and keeps a simple skill simple.
 *
 * Deliberately not a tool. A tool is something the model *does*, and gets a
 * schema, a right and an audit entry. A skill is something it *knows* — it
 * changes the answer, not the world — so it needs none of that, and modelling
 * it as a tool would mean the model choosing whether to read its own
 * instructions.
 */
class Skill extends CommonDBTM
{
    public static $rightname = 'plugin_glpiai_config';

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
     * The skills that apply to one question, in the entity it is asked in.
     *
     * Matching is on whole words, case-insensitively, against the technician's
     * own text. Substring matching was the first attempt and is wrong in a way
     * that is hard to see: a skill triggered by "vpn" also fires on "vpns" —
     * fine — and one triggered by "ad" fires on "add", "bad" and "already",
     * which quietly attaches domain-controller instructions to half the
     * conversations in the estate.
     *
     * @return self[]
     */
    public static function matching(string $question, int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out   = [];
        $spent = 0;

        $criteria = [
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1] + getEntitiesRestrictCriteria(self::getTable(), '', $entities_id, true),
            'ORDER' => ['name'],
        ];

        foreach ($DB->request($criteria) as $row) {
            $skill = new self();
            $skill->fields = $row;

            if (!$skill->triggeredBy($question)) {
                continue;
            }

            $text = trim((string) $row['instructions']);
            if ($text === '') {
                continue;
            }

            // A budget rather than a count. Ten one-line skills are cheaper
            // than one that pastes a runbook, and the thing that actually costs
            // money and dilutes attention is the characters.
            if ($spent + mb_strlen($text) > self::BUDGET) {
                continue;
            }

            $spent += mb_strlen($text);
            $out[]  = $skill;
        }

        return $out;
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

    /**
     * The skills section of a system prompt, or '' when nothing applies.
     */
    public static function instructionsFor(string $question, int $entities_id): string
    {
        $skills = self::matching($question, $entities_id);
        if ($skills === []) {
            return '';
        }

        $lines = [
            '',
            'The following apply to this instance. They were written by an administrator here,',
            'they are more specific than anything you know generally, and where they conflict',
            'with your own habits they win:',
        ];

        foreach ($skills as $skill) {
            $lines[] = '';
            $lines[] = '## ' . (string) $skill->fields['name'];
            $lines[] = trim((string) $skill->fields['instructions']);
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
