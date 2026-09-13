<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Markdown;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use KnowbaseItem;
use KnowbaseItem_Item;
use Session;
use Ticket;

/**
 * Write down what was just worked out, before it is lost.
 *
 * The knowledge base is written when somebody has ten spare minutes, which is
 * never, and the moment the article is worth writing is the moment the fix is
 * still in the technician's head. "Save that as a KB article" is one sentence,
 * and this is what makes it work from inside the conversation that produced
 * the answer.
 *
 * Two rules, and they are the same two the drafting feature settled on:
 *
 *  - **The article is created unpublished.** No visibility rows, which in GLPI
 *    means only its author can see it until somebody publishes it. Not
 *    configurable, deliberately: an option to publish on creation would be one
 *    argument between a model's prose and a requester-facing knowledge base.
 *  - **It is linked to the ticket it came from** when there is one, so the
 *    article and the evidence behind it stay findable from each other. An
 *    article nobody can trace back is one nobody dares edit.
 *
 * `Drafter::createArticle()` does the same job from a stored draft and stays
 * separate: that path carries a draft's outcome and its measurement, and
 * folding the two together would make one of them lie about the other.
 */
final class WriteKnowledge
{
    public static function tool(): Tool
    {
        return new Tool(
            name: 'draft_kb_article',
            description: 'Create a knowledge base article from what has just been worked out. '
                . 'The article is created UNPUBLISHED — only its author can see it until '
                . 'somebody reviews and publishes it — so say that rather than implying it is '
                . 'live. Write it for a technician who has never seen this ticket: the symptom '
                . 'as it would be reported, then the cause, then what to do. Leave this '
                . 'requester\'s names, hostnames and ticket numbers out of it.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'title'      => [
                        'type'        => 'string',
                        'description' => 'Names the symptom, not the fix — the symptom is what '
                            . 'somebody will search for.',
                    ],
                    'body'       => [
                        'type'        => 'string',
                        'description' => 'The article. Markdown headings and numbered steps are '
                            . 'rendered.',
                    ],
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket this came from, to link them. Omit to use '
                            . 'the one the conversation is about.',
                    ],
                ],
                'required'   => ['title', 'body'],
            ],
            handler: [self::class, 'run'],
            mutates: true,
            right: 'knowbase',
            right_level: CREATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $title = trim((string) ($arguments['title'] ?? ''));
        $body  = trim((string) ($arguments['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new ToolException('An article needs both a title and a body.');
        }

        if (!KnowbaseItem::canCreate()) {
            throw new ToolException('You may not create knowledge articles.');
        }

        $article = new KnowbaseItem();
        $id      = (int) $article->add([
            'name'        => mb_substr($title, 0, 250),
            // Rendered markdown rather than escaped text: this is model output
            // becoming a stored document, and an article that arrives as one
            // paragraph of asterisks is one nobody will publish.
            'answer'      => Markdown::toHtml($body),
            'users_id'    => (int) Session::getLoginUserID(),
            'entities_id' => $context->entities_id,
            'is_faq'      => 0,
        ]);

        if ($id <= 0) {
            throw new ToolException('GLPI would not create the article.');
        }

        $tickets_id = (int) ($arguments['tickets_id'] ?? 0);
        if ($tickets_id <= 0 && $context->isAbout(Ticket::class)) {
            $tickets_id = (int) $context->items_id;
        }

        $linked = false;
        if ($tickets_id > 0) {
            $ticket = new Ticket();
            if ($ticket->getFromDB($tickets_id) && $ticket->canViewItem()) {
                (new KnowbaseItem_Item())->add([
                    'knowbaseitems_id' => $id,
                    'itemtype'         => Ticket::class,
                    'items_id'         => $tickets_id,
                ]);
                $linked = true;
            }
        }

        return array_filter([
            'created'    => ['id' => $id, 'title' => $title, 'url' => KnowbaseItem::getFormURLWithID($id)],
            'published'  => false,
            'linked_to_ticket' => $linked ? $tickets_id : null,
            'note'       => 'Created unpublished: it has no visibility, so nobody but you can '
                . 'see it until somebody publishes it. Tell the technician that, and give them '
                . 'the link.',
        ], static fn($v): bool => $v !== null);
    }
}
