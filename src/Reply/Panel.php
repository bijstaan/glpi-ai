<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Reply;

use CommonITILObject;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Url;
use ITILFollowup;
use Session;

/**
 * The strip inside GLPI's own reply editor.
 *
 * Same placement decision as the solution draft, for the opposite reason. That
 * one offers text and puts it in the editor on a click; this one takes the
 * text that is already there and says nothing unless it has something to say.
 * Neither writes to the database, and in both cases the technician's Save is
 * still the only thing that publishes anything.
 *
 * **Where the parent comes from, and where it does not.** `SolutionPanel`
 * reads its ticket from `$params['options']['item']`, which the solution form
 * provides. The followup form does not: the timeline includes its template
 * with `form_mode`, `subitem` and `mention_options` and nothing else, so those
 * options are empty here and reading them renders an empty strip on every
 * ticket, silently. What is populated is the blank followup itself — core sets
 * `itemtype` and `items_id` on it before rendering — and that is what this
 * reads. Verified against GLPI 11.0.8's
 * `templates/components/itilobject/timeline/timeline.html.twig`.
 *
 * Buttons are `type="button"` and carry no `name`, because this renders inside
 * the followup's own `<form>`: a bare `<button>` submits it, which here would
 * post an unfinished reply to a requester.
 */
final class Panel
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Draw the strip above the reply editor, or nothing at all. */
    public static function render(ITILFollowup $followup): void
    {
        // Central interface only, the same as every other feature here: an
        // AI review of a reply is technician workflow, and the requester's own
        // form must show no trace of it.
        if (
            !Reviewer::available()
            || ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central'
        ) {
            return;
        }

        // The new-reply form only. The same template renders a saved followup
        // being edited, where there is nothing to review before sending —
        // it has been sent.
        if (!$followup->isNewItem()) {
            return;
        }

        $itemtype = (string) ($followup->fields['itemtype'] ?? '');
        $items_id = (int) ($followup->fields['items_id'] ?? 0);

        if ($items_id <= 0 || !is_a($itemtype, CommonITILObject::class, true)) {
            return;
        }

        /** @var CommonITILObject $item */
        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return;
        }

        // The entity gate, answered here rather than at the endpoint. A button
        // that is drawn and then refuses reads as broken rather than as
        // switched off, and there is nothing on screen to say which.
        if (!Settings::entityAllowed((int) $item->fields['entities_id'])) {
            return;
        }

        echo "<div class='glpiai-reply-review' data-glpiai-reply-review"
           . " data-glpiai-reply-itemtype='" . self::e($itemtype) . "'"
           . " data-glpiai-reply-items-id='" . $items_id . "'"
           . " data-glpiai-reply-endpoint='" . self::e(Url::to('ajax/reply.php')) . "'"
           . " data-glpiai-reply-csrf='" . self::e(Session::getNewCSRFToken()) . "'>";

        echo "<div class='glpiai-reply-bar'>";
        echo "<i class='ti ti-eyeglass me-1'></i>";
        echo "<span class='glpiai-reply-label'>"
           . __s('A second read before this goes to the requester — internal content, jargon, '
               . 'whether it says what happens next.', 'glpiai')
           . '</span>';
        echo "<button type='button' class='btn btn-sm btn-outline-secondary ms-auto' "
           . "data-glpiai-reply-check>" . __s('Check this reply', 'glpiai') . '</button>';
        echo '</div>';

        // Filled in by the endpoint's answer. Kept out of the form's own
        // markup so nothing here is ever posted with the reply.
        echo "<div class='glpiai-reply-result' data-glpiai-reply-result hidden></div>";

        echo '</div>';
    }

    /**
     * One answer, as markup.
     *
     * Rendered server-side and handed to the page as HTML, the same way the
     * solution draft is: the alternative is a second copy of the wording and
     * the escaping rules living in JavaScript, and the two then disagree the
     * first time either is edited.
     *
     * @param array<int,array<string,string>> $flags
     */
    public static function result(string $verdict, array $flags): string
    {
        if ($flags === []) {
            return "<div class='glpiai-reply-clean'><i class='ti ti-check me-1'></i>"
                 . __s('Nothing worth stopping for. It is your reply either way.', 'glpiai')
                 . '</div>';
        }

        $html = "<ul class='glpiai-reply-flags'>";
        foreach ($flags as $flag) {
            $kind = (string) $flag['kind'];

            $html .= "<li class='glpiai-reply-flag glpiai-reply-flag-" . self::e($kind) . "'>";
            $html .= "<span class='glpiai-reply-kind'>" . self::e(Reviewer::label($kind)) . '</span>';

            if ((string) $flag['quote'] !== '') {
                $html .= "<q class='glpiai-reply-quote'>" . self::e($flag['quote']) . '</q>';
            }

            $html .= "<span class='glpiai-reply-why'>" . self::e($flag['why']) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        $html .= "<div class='glpiai-reply-footnote'>"
               . __s('Suggestions, not corrections. Send it as it stands if you disagree.', 'glpiai')
               . '</div>';

        return $html;
    }
}
