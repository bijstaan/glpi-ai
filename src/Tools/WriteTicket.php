<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use ITILFollowup;
use Ticket;
use Ticket_Ticket;
use TicketTask;

/**
 * The four things a model may write onto a ticket.
 *
 * This plugin's rule is that AI proposes and a technician disposes, and these
 * do not break it — they are the technician's own instruction, typed at the
 * assistant instead of clicked in a form, recorded in the tool audit under
 * their name and gated on the rights they already hold. What the rule actually
 * forbids is *unattended* output and anything a requester reads, and that is
 * where the hard lines here are drawn:
 *
 *  - **A note is always private.** `add_ticket_note` cannot write a public
 *    followup, and there is no argument that would let it. A public followup is
 *    a reply to the requester, it leaves the building the moment it is saved,
 *    and the roadmap's one requester-facing feature is a model *reviewing* a
 *    reply a person wrote. Making this configurable would put an auto-reply one
 *    checkbox away, and that checkbox would be ticked on some instance.
 *  - **Nothing here changes status.** Not solved, not closed, not pending. A
 *    status change fires notifications, stops SLA clocks and closes work the
 *    requester may not agree is finished, and none of that is recoverable by
 *    editing a field back.
 *  - **Nothing here assigns.** Who owns a ticket depends on rota, skills and
 *    load, which is exactly the claim triage was cut back from making. It is
 *    also the change most likely to reach a requester by email.
 *  - **Nothing here writes to `content`.** The description is what the person
 *    reported. Editing it rewrites the record of what somebody said.
 *
 * What is left is the work a technician does twenty times a day and resents:
 * write down what was found, log the time, put the ticket in the right
 * category, and link the six copies of the same outage together.
 *
 * Every one of them re-checks the ticket with `can()` rather than trusting the
 * right alone — the right says this user may update tickets, and `can()` says
 * whether they may update *this* one, which is where the entity restriction
 * lives.
 */
final class WriteTicket
{
    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::note(), self::task(), self::fields(), self::link()];
    }

    // ----------------------------------------------------------------- note

    private static function note(): Tool
    {
        return new Tool(
            name: 'add_ticket_note',
            description: 'Write an internal note on a ticket — what you checked, what you found, '
                . 'what the next person needs to know. The note is always private: it is visible '
                . 'to technicians and never to the requester, and there is no way to make it '
                . 'public. Use it when the technician asks you to write something down, or to '
                . 'record the result of a diagnosis you have just run. Do not use it to answer '
                . 'the requester.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket. Omit to use the one the conversation is '
                            . 'about.',
                    ],
                    'content'    => [
                        'type'        => 'string',
                        'description' => 'The note, as plain prose. Say what you did and what you '
                            . 'found, not what you are about to do.',
                    ],
                ],
                'required'   => ['content'],
            ],
            handler: [self::class, 'runNote'],
            mutates: true,
            right: 'followup',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runNote(array $arguments, ToolContext $context): array
    {
        $ticket  = self::ticket($arguments, $context);
        $content = trim((string) ($arguments['content'] ?? ''));

        if ($content === '') {
            throw new ToolException('There is nothing to write.');
        }

        $followup = new ITILFollowup();
        $input    = [
            'itemtype'   => Ticket::class,
            'items_id'   => (int) $ticket->getID(),
            'content'    => self::html($content),
            // Not a default, and not an argument. See the class comment.
            'is_private' => 1,
        ];

        if (!$followup->can(-1, CREATE, $input)) {
            throw new ToolException('You cannot add a note to that ticket.');
        }

        $id = (int) $followup->add($input);
        if ($id <= 0) {
            throw new ToolException('The note could not be added.');
        }

        return [
            'added'      => 'private note',
            'id'         => $id,
            'tickets_id' => (int) $ticket->getID(),
            'note'       => 'Written as an internal note. The requester cannot see it.',
        ];
    }

    // ----------------------------------------------------------------- task

    private static function task(): Tool
    {
        return new Tool(
            name: 'add_ticket_task',
            description: 'Record a task on a ticket: a piece of work done or to be done, and '
                . 'the time spent on it in minutes. Use this rather than a note whenever there '
                . 'is time to log or something someone still has to do — tasks are what '
                . 'reporting and billing count, and a note is not. Private by default.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket. Omit to use the one the conversation is '
                            . 'about.',
                    ],
                    'content'    => ['type' => 'string', 'description' => 'What the task is.'],
                    'minutes'    => [
                        'type'        => 'integer',
                        'description' => 'Actual duration in minutes, when the work is done. Omit '
                            . 'if it is still to do.',
                    ],
                    'done'       => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Whether the work is finished. Defaults to yes.',
                    ],
                ],
                'required'   => ['content'],
            ],
            handler: [self::class, 'runTask'],
            mutates: true,
            right: 'task',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runTask(array $arguments, ToolContext $context): array
    {
        $ticket  = self::ticket($arguments, $context);
        $content = trim((string) ($arguments['content'] ?? ''));

        if ($content === '') {
            throw new ToolException('There is nothing to record.');
        }

        $minutes = max(0, (int) ($arguments['minutes'] ?? 0));
        $done    = mb_strtolower(trim((string) ($arguments['done'] ?? 'yes'))) !== 'no';

        $task  = new TicketTask();
        $input = [
            'tickets_id'    => (int) $ticket->getID(),
            'content'       => self::html($content),
            'is_private'    => 1,
            'state'         => $done ? \Planning::DONE : \Planning::TODO,
            'actiontime'    => $minutes * 60,
        ];

        if (!$task->can(-1, CREATE, $input)) {
            throw new ToolException('You cannot add a task to that ticket.');
        }

        $id = (int) $task->add($input);
        if ($id <= 0) {
            throw new ToolException('The task could not be added.');
        }

        return array_filter([
            'added'      => 'task',
            'id'         => $id,
            'tickets_id' => (int) $ticket->getID(),
            'minutes'    => $minutes > 0 ? $minutes : null,
            'state'      => $done ? 'done' : 'to do',
        ], static fn($v): bool => $v !== null);
    }

    // --------------------------------------------------------------- fields

    /**
     * The fields a model may set, and what each is called in the database.
     *
     * A deliberate allowlist rather than a denylist. A denylist would be one
     * forgotten column away from letting a model set `status`, and the column
     * it forgot would be whichever one core added last.
     */
    private const FIELDS = [
        'category' => 'itilcategories_id',
        'urgency'  => 'urgency',
        'impact'   => 'impact',
        'location' => 'locations_id',
    ];

    private static function fields(): Tool
    {
        return new Tool(
            name: 'update_ticket',
            description: 'Correct a ticket\'s filing: its category, urgency, impact or location. '
                . 'Use it when the technician says the ticket is in the wrong place — better '
                . 'categorisation is what makes the right procedures attach and the reporting '
                . 'mean anything. It cannot change the status, the assignment, the title or the '
                . 'description; say so rather than trying, and note that priority is computed '
                . 'from urgency and impact rather than set directly.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket. Omit to use the one the conversation is '
                            . 'about.',
                    ],
                    'category'   => [
                        'type'        => 'integer',
                        'description' => 'ITIL category id. Categories come from triage '
                            . 'suggestions or from the ticket itself; do not guess an id.',
                    ],
                    'urgency'    => [
                        'type'        => 'integer',
                        'description' => 'How urgent for the requester, 1 (very low) to 5 (very '
                            . 'high).',
                    ],
                    'impact'     => [
                        'type'        => 'integer',
                        'description' => 'How widely it hurts, 1 (very low) to 5 (very high).',
                    ],
                    'location'   => ['type' => 'integer', 'description' => 'Location id.'],
                ],
            ],
            handler: [self::class, 'runFields'],
            mutates: true,
            right: 'ticket',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runFields(array $arguments, ToolContext $context): array
    {
        $ticket = self::ticket($arguments, $context);
        $id     = (int) $ticket->getID();

        if (!$ticket->can($id, UPDATE)) {
            throw new ToolException("You cannot change ticket $id.");
        }

        $input   = ['id' => $id];
        $changed = [];

        foreach (self::FIELDS as $name => $column) {
            if (!array_key_exists($name, $arguments) || $arguments[$name] === null) {
                continue;
            }

            $value = (int) $arguments[$name];

            if (in_array($name, ['urgency', 'impact'], true) && ($value < 1 || $value > 5)) {
                throw new ToolException("$name must be between 1 and 5.");
            }

            if ($name === 'category' && $value > 0 && !self::exists('ITILCategory', $value)) {
                throw new ToolException("There is no ITIL category $value.");
            }

            if ($name === 'location' && $value > 0 && !self::exists('Location', $value)) {
                throw new ToolException("There is no location $value.");
            }

            $input[$column] = $value;
            $changed[]      = $name;
        }

        if ($changed === []) {
            throw new ToolException('Nothing was asked for, so nothing changed.');
        }

        if (!$ticket->update($input)) {
            throw new ToolException('That ticket could not be changed.');
        }

        $ticket->getFromDB($id);

        return [
            'tickets_id' => $id,
            'changed'    => $changed,
            // Recomputed by core from urgency and impact, so it is worth
            // reporting: a model that set urgency and reported the old priority
            // would be telling the technician the opposite of what happened.
            'priority'   => (int) $ticket->fields['priority'],
        ];
    }

    // ----------------------------------------------------------------- link

    /** @var array<string,int> */
    private const LINKS = [
        'related'      => Ticket_Ticket::LINK_TO,
        'duplicate_of' => Ticket_Ticket::DUPLICATE_WITH,
        'child_of'     => Ticket_Ticket::SON_OF,
        'parent_of'    => Ticket_Ticket::PARENT_OF,
    ];

    private static function link(): Tool
    {
        return new Tool(
            name: 'link_tickets',
            description: 'Link two tickets: related, duplicate of, child of or parent of. Use it '
                . 'when several tickets are the same outage or the same request — linking them '
                . 'is what lets one investigation answer all of them, and duplicates that are '
                . 'not linked get worked twice. It links only; it never closes or merges '
                . 'anything.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket to link from. Omit to use the one the '
                            . 'conversation is about.',
                    ],
                    'to_id'      => ['type' => 'integer', 'description' => 'The ticket to link to.'],
                    'how'        => [
                        'type'        => 'string',
                        'enum'        => ['related', 'duplicate_of', 'child_of', 'parent_of'],
                        'description' => 'How the first relates to the second. Defaults to '
                            . 'related.',
                    ],
                ],
                'required'   => ['to_id'],
            ],
            handler: [self::class, 'runLink'],
            mutates: true,
            right: 'ticket',
            right_level: UPDATE,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runLink(array $arguments, ToolContext $context): array
    {
        $from = self::ticket($arguments, $context);
        $to   = new Ticket();
        $toId = (int) ($arguments['to_id'] ?? 0);

        if ($toId <= 0 || !$to->getFromDB($toId) || !$to->canViewItem()) {
            throw new ToolException("No ticket $toId is visible to you.");
        }

        if ((int) $from->getID() === $toId) {
            throw new ToolException('A ticket cannot be linked to itself.');
        }

        // Both ends. Linking is a change to both tickets — it shows on both
        // timelines — so being allowed to edit one of them is not enough.
        if (!$from->can((int) $from->getID(), UPDATE) || !$to->can($toId, UPDATE)) {
            throw new ToolException('You cannot change both of those tickets.');
        }

        $how  = (string) ($arguments['how'] ?? 'related');
        $type = self::LINKS[$how] ?? Ticket_Ticket::LINK_TO;

        $link = new Ticket_Ticket();
        $id   = (int) $link->add([
            'tickets_id_1' => (int) $from->getID(),
            'tickets_id_2' => $toId,
            'link'         => $type,
        ]);

        if ($id <= 0) {
            // The most likely cause by far, and worth saying: core refuses a
            // duplicate link rather than adding a second one, and a model told
            // only "it failed" will try again with the arguments reversed.
            throw new ToolException(
                'The link was not created. Those tickets may already be linked.'
            );
        }

        return [
            'linked'     => [(int) $from->getID(), $toId],
            'how'        => array_search($type, self::LINKS, true) ?: 'related',
            'note'       => 'Linked only. Neither ticket was closed, merged or changed otherwise.',
        ];
    }

    // --------------------------------------------------------------- shared

    /**
     * The ticket being written to, from the argument or the conversation.
     *
     * Falling back to the context is what makes "write that down" work without
     * the model having to repeat an id it was already given — and it is safe
     * because the context's item is the record the technician has open, not
     * something a prompt chose.
     */
    private static function ticket(array $arguments, ToolContext $context): Ticket
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $id = (int) ($arguments['tickets_id'] ?? 0);

        if ($id <= 0 && $context->isAbout(Ticket::class)) {
            $id = (int) $context->items_id;
        }

        if ($id <= 0) {
            throw new ToolException('Which ticket? Give tickets_id.');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($id) || !$ticket->canViewItem()) {
            throw new ToolException("No ticket $id is visible to you.");
        }

        return $ticket;
    }

    private static function exists(string $itemtype, int $id): bool
    {
        $item = getItemForItemtype($itemtype);

        return $item !== false && $item->getFromDB($id);
    }

    /**
     * Prose as the HTML the timeline expects.
     *
     * GLPI stores followup and task bodies as HTML and renders them as HTML. A
     * model's plain text with blank lines between paragraphs arrives as one
     * run-on block, and anything with a `<` in it — a command line, a comparison
     * — silently loses the rest of the sentence to the parser.
     */
    private static function html(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $paragraphs = preg_split('/\n{2,}/', trim($escaped)) ?: [];

        return implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(trim($p)) . '</p>',
            $paragraphs
        ));
    }
}
