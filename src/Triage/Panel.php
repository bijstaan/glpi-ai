<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Triage;

use Dropdown;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Url;
use Session;
use Ticket;

/**
 * The suggestion panel, at the top of a ticket form.
 *
 * Chips rather than prefilled fields, and the distinction is the whole design.
 * A prefilled field is a decision that has already been made and now has to be
 * noticed and undone; a chip is an offer that costs nothing to ignore. The
 * roadmap's rule — the model proposes, a technician disposes — is not enforced
 * by policy here, it is enforced by there being no code path that writes to the
 * ticket without a click.
 *
 * The panel removes itself once every chip has been dealt with. A suggestion
 * that stays on screen after it has been actioned trains people to scroll past
 * the region it lives in, which costs more than it saves the first time
 * something important appears there.
 */
final class Panel
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function render(Ticket $ticket): void
    {
        $row = Suggestion::forTicket((int) $ticket->getID());
        if ($row === null) {
            return;
        }

        $state = (string) $row['state'];

        if ($state === Suggestion::PENDING) {
            self::renderNotice(
                $row,
                'ti-clock',
                __s('Triage suggestions are queued for this ticket.', 'glpiai'),
                __s('Suggest now', 'glpiai')
            );

            return;
        }

        if ($state === Suggestion::FAILED) {
            self::renderNotice(
                $row,
                'ti-alert-triangle',
                self::e($row['error_message'] ?: __('Triage did not produce a suggestion.', 'glpiai')),
                __s('Try again', 'glpiai')
            );

            return;
        }

        $chips = self::chips($row, $ticket);
        if ($chips === []) {
            return;
        }

        $can_apply  = $ticket->canUpdateItem();
        $confidence = (string) $row['confidence'];

        // The dashed offer box keeps its own grammar — glpi-kedb's known-error
        // offers deliberately match it — and gains an outer section so the
        // block sits flush in the ITIL field panel instead of floating in the
        // field grid.
        echo "<div class='accordion-item glpiai-triage-section'>";
        echo "<div class='glpiai-triage' data-glpiai-triage='" . (int) $row['id'] . "'>";
        echo "<div class='glpiai-triage-head'>";
        echo "<i class='ti ti-sparkles me-1'></i>";
        echo "<span class='glpiai-triage-label'>" . __s('Suggested triage', 'glpiai') . '</span>';
        echo "<span class='glpiai-triage-confidence glpiai-triage-confidence--"
           . self::e($confidence) . "'>" . self::e(self::confidenceLabel($confidence)) . '</span>';

        if ((string) $row['reasoning'] !== '') {
            // Inline, not block: this is one sentence beside a row of chips,
            // and a paragraph wrapper would put a margin in the middle of it.
            echo "<span class='glpiai-triage-why'>"
               . Markdown::toInline((string) $row['reasoning']) . '</span>';
        }

        echo '</div>';

        echo "<div class='glpiai-triage-chips'>";
        foreach ($chips as $chip) {
            echo "<span class='glpiai-triage-chip' data-glpiai-chip='" . self::e($chip['field']) . "'>";
            echo "<span class='glpiai-triage-chip-label'>" . self::e($chip['label']) . '</span>';
            echo "<span class='glpiai-triage-chip-value'>" . self::e($chip['value']) . '</span>';

            if ($can_apply) {
                echo "<button type='button' class='glpiai-triage-apply' data-glpiai-apply='"
                   . self::e($chip['field']) . "' title='" . __s('Apply', 'glpiai') . "'>"
                   . "<i class='ti ti-check'></i></button>";
            }

            echo "<button type='button' class='glpiai-triage-dismiss' data-glpiai-dismiss='"
               . self::e($chip['field']) . "' title='" . __s('Dismiss', 'glpiai') . "'>"
               . "<i class='ti ti-x'></i></button>";
            echo '</span>';
        }
        echo '</div>';

        echo "<span class='glpiai-triage-error' data-glpiai-triage-error hidden></span>";
        echo self::root();
        echo '</div>';
        echo '</div>';
    }

    /**
     * The one-line form: queued, or failed.
     *
     * The "run it now" button exists because the queue is drained by cron, and
     * a technician who opens a ticket thirty seconds after it arrived would
     * otherwise be told to come back later by a piece of software that could
     * simply do it.
     *
     * @param array<string,mixed> $row
     */
    private static function renderNotice(array $row, string $icon, string $message, string $action): void
    {
        // Whether this ticket may be updated, not whether tickets in general
        // may be: running a suggestion spends money at a provider, and the
        // endpoint answers the same question per ticket.
        $ticket = new Ticket();
        $can_run = $ticket->getFromDB((int) $row['tickets_id']) && $ticket->canUpdateItem();

        echo "<div class='accordion-item glpiai-triage-section'>";
        echo "<div class='glpiai-triage glpiai-triage--notice' data-glpiai-triage='"
           . (int) $row['id'] . "'>";
        echo "<div class='glpiai-triage-head'>";
        echo "<i class='ti " . self::e($icon) . " me-1'></i>";
        echo "<span class='glpiai-triage-why'>" . $message . '</span>';

        if ($can_run) {
            echo "<button type='button' class='glpiai-triage-run' data-glpiai-triage-run>"
               . self::e($action) . '</button>';
        }

        echo '</div>';
        echo "<span class='glpiai-triage-error' data-glpiai-triage-error hidden></span>";
        echo self::root();
        echo '</div>';
        echo '</div>';
    }

    /** Endpoint and token for the panel's buttons. */
    private static function root(): string
    {
        return "<span class='glpiai-triage-root' data-glpiai-triage-endpoint='"
             . self::e(Url::to('ajax/triage.php')) . "' data-glpiai-triage-csrf='"
             . self::e(Session::getNewCSRFToken()) . "'></span>";
    }

    /**
     * The chips still worth showing.
     *
     * A field is dropped once it has an outcome — accepted, dismissed, or
     * matched — because all three mean there is nothing left to decide. It is
     * also dropped if the ticket has since moved to what was suggested: someone
     * may have edited the ticket directly, and a chip offering to set a value
     * that is already set is noise that looks like a bug.
     *
     * @param array<string,mixed> $row
     * @return array<int,array{field:string,label:string,value:string}>
     */
    private static function chips(array $row, Ticket $ticket): array
    {
        $out = [];

        foreach (Suggestion::FIELDS as $field => $column) {
            if ((string) $row[$column] !== Suggestion::OPEN) {
                continue;
            }

            $value = (int) $row[$field];
            if ($value <= 0 || $value === (int) ($ticket->fields[$field] ?? 0)) {
                continue;
            }

            $label = self::describe($field, $value);
            if ($label === null) {
                continue;
            }

            $out[] = ['field' => $field] + $label;
        }

        return $out;
    }

    /**
     * A chip's two strings, or null if the thing it names has since gone away.
     *
     * @return array{label:string,value:string}|null
     */
    private static function describe(string $field, int $value): ?array
    {
        switch ($field) {
            case 'itilcategories_id':
                $name = Dropdown::getDropdownName('glpi_itilcategories', $value);

                return $name !== '' && $name !== '&nbsp;'
                    ? ['label' => __('Category', 'glpiai'), 'value' => $name]
                    : null;

            case 'urgency':
                return ['label' => __('Urgency', 'glpiai'), 'value' => Ticket::getUrgencyName($value)];

            case 'impact':
                return ['label' => __('Impact', 'glpiai'), 'value' => Ticket::getImpactName($value)];

            case 'plugin_glpisop_sops_id':
                $name = self::sopName($value);

                return $name !== null
                    ? ['label' => __('Procedure', 'glpiai'), 'value' => $name]
                    : null;
        }

        return null;
    }

    /** Null when glpi-sop is absent or the procedure has been deleted. */
    private static function sopName(int $sops_id): ?string
    {
        if (!\Plugin::isPluginActive('glpisop') || !class_exists('GlpiPlugin\\Glpisop\\Sop')) {
            return null;
        }

        $sop = new ('GlpiPlugin\\Glpisop\\Sop')();

        return $sop->getFromDB($sops_id) ? (string) $sop->fields['name'] : null;
    }

    private static function confidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'high'   => __('confident', 'glpiai'),
            'medium' => __('fairly sure', 'glpiai'),
            default  => __('unsure', 'glpiai'),
        };
    }
}
