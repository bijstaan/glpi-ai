<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Draft;

use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Url;
use ITILSolution;
use Session;
use Ticket;

/**
 * A one-line strip inside GLPI's own solution editor.
 *
 * This is where the placement decision for this feature actually lands. The
 * alternatives were to prefill the solution field, or to leave the draft in its
 * tab and make people copy and paste. Prefilling puts generated prose one Save
 * away from a customer-visible field on a form the technician did not ask to be
 * written for them; copy and paste is safe and tedious enough that people stop
 * bothering.
 *
 * So: the editor opens empty, as it always did, with a line above it offering
 * the draft. Clicking Insert puts the text *in the editor* — not in the
 * database — and the technician still reads it, still edits it, and still
 * presses Save. Every one of those steps is a place to notice the model was
 * wrong, and none of them has been removed.
 *
 * GLPI fires PRE_ITEM_FORM inside the solution `<form>`, which is also why
 * everything here is `type="button"` and carries no `name`: a bare `<button>`
 * inside a form submits it, and a nested `<form>` is dropped by the parser
 * without comment.
 */
final class SolutionPanel
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render for the solution being composed on a ticket.
     *
     * The parent is passed in rather than read off the solution. GLPI hands
     * this hook a *blank* ITILSolution — the form is being drawn, so there is
     * nothing in its fields yet, including which item it belongs to — and puts
     * the real subject in the hook's own options. Reading `items_id` off the
     * solution looks correct and silently renders nothing at all.
     */
    public static function render(ITILSolution $solution, mixed $parent = null): void
    {
        if (!Settings::flag('draft_enabled')) {
            return;
        }

        $ticket = $parent instanceof Ticket ? $parent : null;

        if ($ticket === null) {
            // The fallback, for a caller that has a saved solution in hand.
            if ((string) ($solution->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) ($solution->fields['items_id'] ?? 0))) {
                return;
            }
        }

        $tickets_id = (int) $ticket->getID();
        if ($tickets_id <= 0 || !$ticket->canUpdateItem()) {
            return;
        }

        if (Drafter::refusal($ticket) !== null) {
            return;
        }

        $draft = Draft::forTicket($tickets_id, Draft::SOLUTION);
        $ready = $draft !== null && (string) $draft['state'] === Draft::READY;

        echo "<div class='glpiai-solution-draft' data-glpiai-solution-draft='$tickets_id'"
           . ($ready ? " data-glpiai-draft-id='" . (int) $draft['id'] . "'" : '') . '>';

        echo "<i class='ti ti-sparkles me-1'></i>";

        if ($ready) {
            echo "<span class='glpiai-solution-draft-label'>"
               . __s('A draft is ready, written from what happened on this ticket.', 'glpiai')
               . '</span>';
            echo "<button type='button' class='btn btn-sm btn-outline-primary ms-auto' "
               . "data-glpiai-solution-insert>"
               . __s('Insert it', 'glpiai') . '</button>';

            // Held on the page rather than fetched on click: it is already
            // here, and a round trip to re-read text the server has already
            // sent buys nothing.
            //
            // Two copies, because the editor and the clipboard want different
            // things — TinyMCE takes the rendered HTML, and somebody pasting
            // into a terminal or an email wants what the model actually wrote.
            echo "<template data-glpiai-solution-html>"
               . Markdown::toHtml((string) $draft['content']) . '</template>';
            echo "<template data-glpiai-solution-text>" . self::e($draft['content']) . '</template>';
        } else {
            echo "<span class='glpiai-solution-draft-label'>"
               . __s('Draft this from the followups, tasks and checks on this ticket?', 'glpiai')
               . '</span>';
            echo "<button type='button' class='btn btn-sm btn-outline-secondary ms-auto' "
               . "data-glpiai-solution-run>"
               . __s('Draft it', 'glpiai') . '</button>';
        }

        echo "<span class='glpiai-draft-error' data-glpiai-draft-error hidden></span>";

        echo "<span class='glpiai-draft-root' data-glpiai-draft-endpoint='"
           . self::e(Url::to('ajax/draft.php')) . "' data-glpiai-draft-csrf='"
           . self::e(Session::getNewCSRFToken()) . "'></span>";

        echo '</div>';
    }
}
