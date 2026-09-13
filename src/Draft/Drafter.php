<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Draft;

use CommonDBTM;
use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Settings;
use KnowbaseItem;
use KnowbaseItem_Item;
use Session;
use Ticket;

/**
 * Drafting a solution, and drafting a knowledge article.
 *
 * On the `quality` tier, unlike triage, and on demand rather than queued. The
 * roadmap's reasoning holds up: triage is high volume and low value per call,
 * so it is cheap and automatic; a draft is the opposite, and it happens at a
 * moment a technician chooses — when they sit down to close a ticket.
 *
 * Neither kind writes anything. A drafted solution goes into GLPI's own
 * solution editor for the technician to read, edit and submit; a drafted
 * article becomes an *unpublished* knowledge item with no visibility, which is
 * to say a document only its creator can see until somebody publishes it. The
 * distinction matters more here than anywhere else in the plugin: this is the
 * one feature whose output is prose intended to be read by someone other than
 * the technician, and prose is exactly what a model is most persuasive at
 * getting wrong.
 */
final class Drafter
{
    /**
     * Output budget.
     *
     * Larger than triage's, and for a second reason on top of reasoning
     * models: this output is genuinely long. An article with a symptom, a
     * cause, and steps runs to several hundred tokens before any thinking.
     */
    private const MAX_TOKENS = 3000;

    /**
     * Can this ticket be drafted from, and if not, why not?
     *
     * Returns null when it can. Unlike triage there is no queue and no cron, so
     * these are answered in front of a technician who just pressed a button —
     * every string here ends up on their screen.
     */
    public static function refusal(Ticket $ticket): ?string
    {
        if (!Settings::flag('enabled')) {
            return __('AI features are switched off.', 'glpiai');
        }

        if (!Settings::flag('draft_enabled')) {
            return __('Drafting is switched off.', 'glpiai');
        }

        if (!Settings::entityAllowed((int) $ticket->fields['entities_id'])) {
            return __('This entity is not permitted to use AI features.', 'glpiai');
        }

        return null;
    }

    /**
     * Draft one artefact for one ticket.
     *
     * @param string $kind Draft::SOLUTION or Draft::ARTICLE
     * @return string empty on success, otherwise why not
     */
    public static function draft(int $tickets_id, string $kind): string
    {
        if (!in_array($kind, [Draft::SOLUTION, Draft::ARTICLE], true)) {
            return __('Unknown kind of draft.', 'glpiai');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return __('That ticket no longer exists.', 'glpiai');
        }

        $refusal = self::refusal($ticket);
        if ($refusal !== null) {
            return $refusal;
        }

        $evidence = Evidence::forTicket($ticket);

        // A ticket with a title and nothing else produces a confident
        // invention, which is worse than no draft at all: a plausible paragraph
        // is more likely to be posted than an honest refusal.
        if (Evidence::isThin($evidence)) {
            return __(
                'There is nothing on this ticket to draft from yet — no followups, no tasks, '
                . 'and no procedure worked through.',
                'glpiai'
            );
        }

        $entities_id = (int) $ticket->fields['entities_id'];
        $drafts_id   = Draft::begin($tickets_id, $entities_id, $kind);

        try {
            $prompt = Prompt::make(
                self::userText($kind, $ticket, $evidence),
                self::instruction($kind, $entities_id)
            )
                ->withTier(Prompt::TIER_QUALITY)
                ->withSchema(self::schema($kind), $kind)
                ->withMaxTokens(self::MAX_TOKENS);

            $completion = Client::complete($prompt, $entities_id);
        } catch (AiException $e) {
            Draft::fail($drafts_id, $e->getMessage());

            return $e->getMessage();
        }

        $data = $completion->data;
        if (!is_array($data)) {
            $message = $completion->wasTruncated()
                ? sprintf(
                    __('The model used its whole %d-token budget before answering. That usually '
                        . 'means a reasoning model; either raise the ceiling or use one that '
                        . 'thinks less.', 'glpiai'),
                    self::MAX_TOKENS
                )
                : __('The provider did not return a usable draft.', 'glpiai');

            Draft::fail($drafts_id, $message);

            return $message;
        }

        $body = trim((string) ($kind === Draft::ARTICLE ? ($data['answer'] ?? '') : ($data['solution'] ?? '')));
        if ($body === '') {
            Draft::fail($drafts_id, __('The model returned an empty draft.', 'glpiai'));

            return __('The model returned an empty draft.', 'glpiai');
        }

        Draft::store($drafts_id, [
            'state'      => Draft::READY,
            'content'    => $body,
            'title'      => mb_substr(trim((string) ($data['title'] ?? '')), 0, 250),
            'gaps'       => mb_substr(trim((string) ($data['gaps'] ?? '')), 0, 1000),
            'confidence' => in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? (string) $data['confidence']
                : 'low',
            'evidence'   => mb_substr(Evidence::summarise($evidence), 0, 250),
            'provider'   => $completion->provider,
            'model'      => $completion->model,
        ]);

        return '';
    }

    /**
     * The system instruction for each kind.
     *
     * Written as two genuinely different jobs rather than one with a switch,
     * because they are. The rules that matter are the negative ones: a solution
     * must not claim something was done that the evidence only suggests, and an
     * article must not carry this entity's names, addresses or internal notes
     * out of the ticket it came from.
     */
    private static function instruction(string $kind, int $entities_id): string
    {
        $shared = [
            'You are helping an IT technician write up a ticket that has been worked on.',
            'You are given the evidence from the ticket itself: what was',
            'reported, what people wrote afterwards, and what checks a documented procedure',
            'recorded.',
            '',
            'The single rule that matters: write only what the evidence supports. If the evidence',
            'does not say what fixed it, say that plainly rather than proposing something',
            'plausible. A technician can act on "the notes do not record what changed"; they',
            'cannot act on a confident invention, and they are more likely to post it.',
            '',
            'Do not repeat the whole ticket back. The person reading already has it.',
            'Write plain prose in short paragraphs. No markdown headings, no bullet characters,',
            'no preamble about what you are about to do.',
            '',
        ];

        if ($kind === Draft::SOLUTION) {
            return implode("\n", array_merge($shared, [
                'Write the SOLUTION for this ticket: what the problem turned out to be, and what',
                'was done about it. It is read by whoever opens this ticket next, including the',
                'person who raised it, so it is about this ticket and this entity.',
                '',
                '  - Two or three short paragraphs at most.',
                '  - Say what was wrong before saying what was done.',
                '  - Anything a check found and ruled out is worth one sentence: knowing what was',
                '    not the cause saves the next person repeating it.',
                '  - In "gaps", list what the evidence does not establish — an unverified fix, a',
                '    cause nobody confirmed, a step recorded as skipped. Empty if there are none.',
                '  - Confidence is about the evidence, not about your writing.',
            ]));
        }

        return implode("\n", array_merge($shared, [
            'Write a KNOWLEDGE BASE ARTICLE about the class of problem this ticket is an example',
            'of. This is a different job from writing up the ticket: it is read by a technician',
            'who has never seen this ticket, months from now, facing the same symptom somewhere',
            'else entirely.',
            '',
            '  - Generalise. The article is about the fault, not about this occurrence of it.',
            '  - Remove every detail that belongs to this occurrence: the entity, their people,',
            '    their departments and team names, hostnames, addresses, times of day, ticket',
            '    numbers, and how many machines were affected. Say "users" and "a site", not',
            '    "the finance team" and "the branch office". If a detail cannot be generalised,',
            '    leave it out rather than anonymising it badly.',
            '  - Before you finish, reread what you wrote and take out anything that would tell a',
            '    reader which entity this came from.',
            '  - Anything marked INTERNAL in the evidence is a note between colleagues. Use what',
            '    it taught you; never carry its wording across.',
            '  - Structure: the symptom as someone would report it, then what causes it, then',
            '    what to do about it.',
            '  - The title names the symptom, not the fix, because the symptom is what somebody',
            '    will search for.',
            '  - If this ticket is too specific to generalise, say so in one sentence, set',
            '    confidence to low, and do not invent a general article.',
        ]));
    }

    /** @param array<string,mixed> $evidence */
    private static function userText(string $kind, Ticket $ticket, array $evidence): string
    {
        $text = Evidence::render($evidence);

        $existing = Evidence::existingSolution($ticket);
        if ($existing !== '') {
            $text .= "\n\nA solution has already been written on this ticket:\n" . $existing;
            $text .= "\n" . ($kind === Draft::SOLUTION
                ? 'Improve on it if the evidence supports more; otherwise stay close to it.'
                : 'It is the best statement of the fix; generalise from it.');
        }

        return $text;
    }

    /**
     * The answer shapes.
     *
     * Plain types and `enum` only, per Prompt::withSchema() — the three dialects
     * behind it agree on that much and diverge past it.
     *
     * @return array<string,mixed>
     */
    private static function schema(string $kind): array
    {
        if ($kind === Draft::SOLUTION) {
            return [
                'type'       => 'object',
                'properties' => [
                    'solution'   => ['type' => 'string', 'description' => 'The solution text.'],
                    'gaps'       => [
                        'type'        => 'string',
                        'description' => 'What the evidence does not establish. Empty if nothing.',
                    ],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                ],
                'required'   => ['solution', 'gaps', 'confidence'],
            ];
        }

        return [
            'type'       => 'object',
            'properties' => [
                'title'      => ['type' => 'string', 'description' => 'Names the symptom.'],
                'answer'     => ['type' => 'string', 'description' => 'The article body.'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required'   => ['title', 'answer', 'confidence'],
        ];
    }

    // ------------------------------------------------------------- outcomes

    /** Record that a solution draft was pulled into the editor. */
    public static function markUsed(int $drafts_id): bool
    {
        $row = Draft::byId($drafts_id);
        if ($row === null || (string) $row['state'] !== Draft::READY) {
            return false;
        }

        Draft::decide($drafts_id, Draft::USED);

        return true;
    }

    public static function discard(int $drafts_id): bool
    {
        if (Draft::byId($drafts_id) === null) {
            return false;
        }

        Draft::decide($drafts_id, Draft::DISCARDED);

        return true;
    }

    /**
     * Turn an article draft into a knowledge item nobody can see yet.
     *
     * No visibility rows are created, which in GLPI means the article is
     * readable only by its author. That is the entire safety mechanism for this
     * half of the feature and it is deliberately not configurable: an option to
     * publish on creation would be one checkbox between a model's prose and a
     * requester-facing knowledge base, and somebody would tick it.
     *
     * @return int the new article's id, or 0 with $error set
     */
    public static function createArticle(int $drafts_id, ?string &$error = null): int
    {
        $row = Draft::byId($drafts_id);

        if ($row === null || (string) $row['kind'] !== Draft::ARTICLE) {
            $error = __('That draft no longer exists.', 'glpiai');

            return 0;
        }

        if ((string) $row['state'] !== Draft::READY) {
            $error = __('That draft is not ready.', 'glpiai');

            return 0;
        }

        if ((int) $row['knowbaseitems_id'] > 0) {
            $error = __('An article has already been created from this draft.', 'glpiai');

            return 0;
        }

        if (!KnowbaseItem::canCreate()) {
            $error = __('You may not create knowledge articles.', 'glpiai');

            return 0;
        }

        $article = new KnowbaseItem();
        $id      = (int) $article->add([
            'name'        => (string) $row['title'] !== ''
                ? (string) $row['title']
                : __('Untitled draft', 'glpiai'),
            // Rendered markdown, not escaped plain text. This is the one
            // place model output becomes a stored *document* rather than
            // something shown once, so it is worth getting right: the model
            // writes headings and numbered steps, and an article that arrives
            // as one paragraph of asterisks is one nobody will publish.
            'answer'      => Markdown::toHtml((string) $row['content']),
            'users_id'    => (int) Session::getLoginUserID(),
            'entities_id' => (int) $row['entities_id'],
            'is_faq'      => 0,
        ]);

        if ($id <= 0) {
            $error = __('GLPI would not create the article.', 'glpiai');

            return 0;
        }

        // Linked to the ticket it came from, so the article and its evidence
        // stay findable from each other.
        (new KnowbaseItem_Item())->add([
            'knowbaseitems_id' => $id,
            'itemtype'         => Ticket::class,
            'items_id'         => (int) $row['tickets_id'],
        ]);

        Draft::decide($drafts_id, Draft::USED, $id);

        return $id;
    }

    /** A purged ticket takes its drafts with it, whatever the settings say. */
    public static function ticketPurged(CommonDBTM $item): void
    {
        if ($item instanceof Ticket) {
            Draft::forget((int) $item->getID());
        }
    }
}
