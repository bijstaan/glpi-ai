<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Draft;

use CommonGLPI;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Settings;
use GlpiPlugin\Glpiai\Url;
use KnowbaseItem;
use Session;
use Ticket;

/**
 * The drafting tab on a ticket.
 *
 * A tab rather than a panel on the form, and the reason is the difference
 * between this feature and triage. Triage is a decision made in passing, so it
 * belongs where the fields are, as chips; a draft is a *document*, and it needs
 * room to be read, the evidence it was built from shown next to it so the
 * claims can be checked, and enough distance from the timeline that nobody
 * mistakes it for something that happened.
 *
 * The solution half also appears inside GLPI's own solution editor — see
 * {@see SolutionPanel} — because that is where it is used. This tab is where
 * both are produced and read.
 */
class Tab extends CommonGLPI
{
    public static $rightname = 'ticket';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !self::visible($item)) {
            return '';
        }

        $count = 0;
        foreach ([Draft::SOLUTION, Draft::ARTICLE] as $kind) {
            $draft = Draft::forTicket((int) $item->getID(), $kind);
            $count += ($draft !== null && (string) $draft['state'] === Draft::READY) ? 1 : 0;
        }

        return self::createTabEntry(__('Drafts', 'glpiai'), $count, $item::class, 'ti ti-pencil-bolt');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Ticket && self::visible($item)) {
            self::render($item);
        }

        return true;
    }

    /**
     * Technician interface only, and only for somebody who could edit the
     * ticket. Reading a draft is harmless; producing one spends money at a
     * provider, and every button on this tab does one or the other.
     */
    private static function visible(Ticket $ticket): bool
    {
        return ($_SESSION['glpiactiveprofile']['interface'] ?? '') === 'central'
            && Settings::flag('draft_enabled')
            && $ticket->canViewItem();
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function render(Ticket $ticket): void
    {
        $tickets_id = (int) $ticket->getID();
        $can_draft  = $ticket->canUpdateItem();
        $refusal    = Drafter::refusal($ticket);

        echo "<div class='glpiai-drafts' data-glpiai-draft-ticket='$tickets_id'>";

        echo "<span class='glpiai-draft-root' data-glpiai-draft-endpoint='"
           . self::e(Url::to('ajax/draft.php')) . "' data-glpiai-draft-csrf='"
           . self::e(Session::getNewCSRFToken()) . "'></span>";

        if ($refusal !== null) {
            echo "<div class='alert alert-secondary'>" . self::e($refusal) . '</div>';
        }

        echo "<p class='text-muted'>"
           . __s('Written from what is on the ticket — the followups, the tasks, and the checks a '
               . 'procedure recorded. Read it before you use it: nothing here has been verified '
               . 'against anything.', 'glpiai')
           . '</p>';

        echo "<div class='row'>";
        self::panel($ticket, Draft::SOLUTION, $can_draft && $refusal === null);
        self::panel($ticket, Draft::ARTICLE, $can_draft && $refusal === null);
        echo '</div>';

        echo '</div>';
    }

    private static function panel(Ticket $ticket, string $kind, bool $can_draft): void
    {
        $draft = Draft::forTicket((int) $ticket->getID(), $kind);
        $ready = $draft !== null && (string) $draft['state'] === Draft::READY;

        $heading = $kind === Draft::SOLUTION
            ? __s('Solution for this ticket', 'glpiai')
            : __s('Knowledge article', 'glpiai');

        $blurb = $kind === Draft::SOLUTION
            ? __s('About this ticket and this entity. Whoever opens the ticket next reads it, '
                . 'including the person who raised it.', 'glpiai')
            : __s('About the class of problem, for a technician who has never seen this ticket. '
                . 'Created unpublished — only you can see it until somebody publishes it.', 'glpiai');

        echo "<div class='col-12 col-xl-6 mb-3'>";
        echo "<div class='card h-100 glpiai-draft' data-glpiai-draft-kind='" . self::e($kind) . "'"
           . ($draft !== null ? " data-glpiai-draft-id='" . (int) $draft['id'] . "'" : '') . '>';

        echo "<div class='card-header d-flex align-items-center gap-2'>";
        echo "<h3 class='card-title mb-0'>" . $heading . '</h3>';

        if ($ready) {
            $confidence = (string) $draft['confidence'];
            echo "<span class='glpiai-draft-confidence glpiai-draft-confidence--"
               . self::e($confidence) . "'>" . self::e(self::confidenceLabel($confidence)) . '</span>';
        }

        if ($can_draft) {
            echo "<button type='button' class='btn btn-sm btn-outline-secondary ms-auto' "
               . "data-glpiai-draft-run='" . self::e($kind) . "'>"
               . "<i class='ti ti-sparkles me-1'></i>"
               . ($ready ? __s('Draft again', 'glpiai') : __s('Draft it', 'glpiai')) . '</button>';
        }

        echo '</div>';
        echo "<div class='card-body'>";
        echo "<div class='form-text mb-2'>" . $blurb . '</div>';

        if ($draft !== null && (string) $draft['state'] === Draft::FAILED) {
            echo "<div class='alert alert-warning mb-2'>"
               . self::e($draft['error_message'] ?: __('Drafting did not produce anything.', 'glpiai'))
               . '</div>';
        }

        if (!$ready) {
            echo "<div class='text-muted'>" . __s('Nothing drafted yet.', 'glpiai') . '</div>';
        } else {
            self::body($draft, $kind);
        }

        echo "<span class='glpiai-draft-error' data-glpiai-draft-error hidden></span>";
        echo '</div></div></div>';
    }

    /** @param array<string,mixed> $draft */
    private static function body(array $draft, string $kind): void
    {
        if ($kind === Draft::ARTICLE && (string) $draft['title'] !== '') {
            echo "<div class='glpiai-draft-title'>" . self::e($draft['title']) . '</div>';
        }

        // Rendered, because a model writes markdown whether or not the prompt
        // asked for one — an article arrives with headings, numbered steps and
        // inline code, and showing the asterisks is worse than useless. The
        // renderer escapes raw HTML and drops unsafe links; see Markdown.
        echo "<div class='glpiai-draft-body glpiai-md' data-glpiai-draft-text>"
           . Markdown::toHtml((string) $draft['content']) . '</div>';

        if ((string) $draft['gaps'] !== '') {
            echo "<div class='glpiai-draft-gaps'>";
            echo "<i class='ti ti-alert-circle me-1'></i>";
            echo "<strong>" . __s('What the evidence does not establish:', 'glpiai') . '</strong> ';
            echo self::e($draft['gaps']);
            echo '</div>';
        }

        echo "<div class='glpiai-draft-meta'>";
        echo sprintf(
            __s('Built from %s.', 'glpiai'),
            self::e($draft['evidence'] ?: __('the ticket description only', 'glpiai'))
        );
        if ((string) $draft['model'] !== '') {
            echo ' <span class="text-muted">' . self::e($draft['model']) . '</span>';
        }
        echo '</div>';

        echo "<div class='glpiai-draft-actions'>";

        if ($kind === Draft::SOLUTION) {
            echo "<button type='button' class='btn btn-sm btn-outline-primary' data-glpiai-draft-copy>"
               . "<i class='ti ti-copy me-1'></i>" . __s('Copy', 'glpiai') . '</button>';
            echo "<span class='form-text ms-2'>"
               . __s('The solution editor offers to insert this directly.', 'glpiai') . '</span>';
        } else {
            $article = (int) $draft['knowbaseitems_id'];

            if ($article > 0) {
                echo "<a class='btn btn-sm btn-outline-primary' href='"
                   . self::e(KnowbaseItem::getFormURLWithID($article, false)) . "'>"
                   . "<i class='ti ti-external-link me-1'></i>"
                   . __s('Open the article', 'glpiai') . '</a>';
                echo "<span class='form-text ms-2'>"
                   . __s('Created unpublished. Give it visibility when you are happy with it.', 'glpiai')
                   . '</span>';
            } elseif (KnowbaseItem::canCreate()) {
                echo "<button type='button' class='btn btn-sm btn-outline-primary' data-glpiai-draft-article>"
                   . "<i class='ti ti-book me-1'></i>"
                   . __s('Create it, unpublished', 'glpiai') . '</button>';
            }
        }

        if ((string) $draft['outcome'] === Draft::OPEN) {
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary ms-auto' "
               . "data-glpiai-draft-discard>" . __s('Not useful', 'glpiai') . '</button>';
        }

        echo '</div>';
    }

    private static function confidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'high'   => __('the evidence supports this', 'glpiai'),
            'medium' => __('partly supported', 'glpiai'),
            default  => __('thin evidence', 'glpiai'),
        };
    }
}
