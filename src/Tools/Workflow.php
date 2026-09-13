<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use Change;
use ChangeValidation;
use CommonITILValidation;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use QueuedNotification;
use Ticket;
use TicketValidation;

/**
 * Two questions about a ticket that are really questions about the process.
 *
 * **"Who is it waiting on?"** An approval is the most common reason a ticket
 * sits still, and it is invisible to every other tool here: the status still
 * says the ticket is being processed, the timeline shows nothing, and the one
 * fact that explains the delay — that somebody was asked four days ago and
 * has not answered — lives in a table nothing else reads. The same tool
 * answers it the other way round, which is the version a technician asks
 * about themselves: what am I being asked to approve.
 *
 * **"Did they actually get the email?"** The second-most common escalation on
 * a helpdesk is a requester who says they were never told, against a
 * technician who is certain they replied. GLPI knows: every notification is
 * queued, stamped when it is sent, and counted when it fails. Nothing in the
 * interface puts that on the ticket, so the answer has always been a guess by
 * whoever was asked.
 *
 * Neither writes. Requesting an approval sends a person an email asking them
 * to make a decision, under the requester's name — that is squarely on the
 * requester-facing side of this plugin's line, and answering one on somebody's
 * behalf is not something a model should ever be able to do.
 */
final class Workflow
{
    /** Rows before the answer stops being readable. */
    private const LIMIT = 25;

    /** @return Tool[] */
    public static function tools(): array
    {
        return [self::validations(), self::notifications()];
    }

    // ---------------------------------------------------------- validations

    private static function validations(): Tool
    {
        return new Tool(
            name: 'read_validations',
            description: 'Approvals on a ticket or change: who was asked, when, whether they '
                . 'have answered, what they said, and how long it has been sitting with them. '
                . 'Ask with waiting_on_me for everything currently awaiting the signed-in '
                . 'user\'s own decision. Use it whenever a ticket seems stuck for no visible '
                . 'reason, before chasing anybody, and before saying that work has been '
                . 'approved — a change with an unanswered approval is not approved.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'enum'        => ['Ticket', 'Change'],
                        'description' => 'Which kind of record. Defaults to Ticket.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to use the one the conversation is about.',
                    ],
                    'waiting_on_me' => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Instead of one record: everything waiting on the '
                            . 'signed-in user right now.',
                    ],
                ],
            ],
            handler: [self::class, 'runValidations'],
            // Gated on the record, not on a right of its own: an approval is
            // part of the ticket, and GLPI has no profile bit that means "may
            // see who was asked to approve".
            right: null,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runValidations(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        if (strtolower((string) ($arguments['waiting_on_me'] ?? 'no')) === 'yes') {
            return self::waitingOnMe();
        }

        $itemtype = ucfirst(strtolower(trim((string) ($arguments['itemtype'] ?? ''))));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context->items_id !== null && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        $itemtype = $itemtype !== '' ? $itemtype : Ticket::class;

        if (!in_array($itemtype, [Ticket::class, Change::class], true)) {
            throw new ToolException('Approvals live on tickets and changes only.');
        }

        /** @var Ticket|Change $item */
        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException(sprintf('No %s %d is visible to you.', $itemtype, $items_id));
        }

        $class = $itemtype === Ticket::class ? TicketValidation::class : ChangeValidation::class;
        $fk    = getForeignKeyFieldForItemType($itemtype);

        $rows = [];
        foreach (
            getAllDataFromTable(
                $class::getTable(),
                [$fk => $items_id],
                false,
                'submission_date'
            ) as $row
        ) {
            $rows[] = self::describe($row);
        }

        return array_filter([
            'itemtype'  => $itemtype,
            'id'        => $items_id,
            'overall'   => CommonITILValidation::getStatus(
                (int) ($item->fields['global_validation'] ?? CommonITILValidation::NONE)
            ),
            'approvals' => $rows,
            'note'      => self::advice($rows),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /** @return array<string,mixed> */
    private static function waitingOnMe(): array
    {
        $me    = (int) \Session::getLoginUserID();
        $mine  = [];
        $groups = array_map('intval', (array) ($_SESSION['glpigroups'] ?? []));

        foreach (
            [
                [TicketValidation::class, Ticket::class, 'tickets_id'],
                [ChangeValidation::class, Change::class, 'changes_id'],
            ] as [$class, $itemtype, $fk]
        ) {
            foreach (
                getAllDataFromTable(
                    $class::getTable(),
                    ['status' => CommonITILValidation::WAITING],
                    false,
                    'submission_date'
                ) as $row
            ) {
                if (!self::addressedTo($row, $me, $groups)) {
                    continue;
                }

                /** @var Ticket|Change $item */
                $item = new $itemtype();
                if (!$item->getFromDB((int) $row[$fk]) || !$item->canViewItem()) {
                    continue;
                }

                $mine[] = [
                    'itemtype' => $itemtype,
                    'id'       => (int) $row[$fk],
                    'title'    => (string) $item->fields['name'],
                    'asked_by' => self::userName((int) $row['users_id']),
                    'asked_on' => self::stamp($row['submission_date'] ?? null),
                    'waiting_for' => self::waitingFor($row['submission_date'] ?? null),
                    'question' => Lookup::plain((string) ($row['comment_submission'] ?? ''), 800),
                ];
            }
        }

        return array_filter([
            'waiting_on_you' => array_slice($mine, 0, self::LIMIT),
            'count'          => count($mine),
            'note'           => $mine === []
                ? 'Nothing is waiting on you.'
                : 'These are decisions only the signed-in user can make. Never answer one on '
                    . 'their behalf — say what is outstanding and let them decide.',
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * Is this approval addressed to me, by either route?
     *
     * GLPI 11 addresses an approval with `itemtype_target`/`items_id_target`,
     * which may be a User or a Group, and keeps `users_id_validate` for what
     * came before. Checking only the new pair misses every row written by an
     * older version; checking only the old one misses every group approval,
     * which is how a rota approves anything.
     *
     * @param array<string,mixed> $row
     * @param int[]               $groups
     */
    private static function addressedTo(array $row, int $me, array $groups): bool
    {
        if ((int) ($row['users_id_validate'] ?? 0) === $me) {
            return true;
        }

        $target = (string) ($row['itemtype_target'] ?? '');
        $id     = (int) ($row['items_id_target'] ?? 0);

        return ($target === \User::class && $id === $me)
            || ($target === \Group::class && in_array($id, $groups, true));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function describe(array $row): array
    {
        $status = (int) $row['status'];

        return array_filter([
            'asked_of'    => self::target($row),
            'asked_by'    => self::userName((int) $row['users_id']),
            'status'      => CommonITILValidation::getStatus($status),
            'asked_on'    => self::stamp($row['submission_date'] ?? null),
            'answered_on' => self::stamp($row['validation_date'] ?? null),
            'waiting_for' => $status === CommonITILValidation::WAITING
                ? self::waitingFor($row['submission_date'] ?? null)
                : null,
            'question'    => Lookup::plain((string) ($row['comment_submission'] ?? ''), 800),
            'answer'      => Lookup::plain((string) ($row['comment_validation'] ?? ''), 800),
            'reminded_on' => self::stamp($row['last_reminder_date'] ?? null),
        ], static fn($v): bool => $v !== null && $v !== '');
    }

    /** @param array<string,mixed> $row */
    private static function target(array $row): ?string
    {
        $target = (string) ($row['itemtype_target'] ?? '');
        $id     = (int) ($row['items_id_target'] ?? 0);

        if ($target === \Group::class && $id > 0) {
            $name = \Dropdown::getDropdownName('glpi_groups', $id);

            return is_string($name) && $name !== '' && $name !== '&nbsp;'
                ? $name . ' (group)'
                : null;
        }

        if ($target === \User::class && $id > 0) {
            return self::userName($id);
        }

        return self::userName((int) ($row['users_id_validate'] ?? 0));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function advice(array $rows): ?string
    {
        if ($rows === []) {
            return 'Nobody has been asked to approve anything on this. If it is stuck, the '
                . 'reason is somewhere else.';
        }

        foreach ($rows as $row) {
            if (($row['waiting_for'] ?? null) !== null) {
                return sprintf(
                    'Waiting on %s for %s. That is very likely why this is not moving.',
                    $row['asked_of'] ?? 'somebody',
                    $row['waiting_for']
                );
            }
        }

        return null;
    }

    private static function waitingFor(mixed $since): ?string
    {
        $since = self::stamp($since);
        if ($since === null) {
            return null;
        }

        $hours = (int) round((time() - strtotime($since)) / 3600);

        if ($hours < 1) {
            return 'under an hour';
        }

        return $hours < 48
            ? sprintf('%d hours', $hours)
            : sprintf('%d days', intdiv($hours, 24));
    }

    // --------------------------------------------------------- notifications

    private static function notifications(): Tool
    {
        return new Tool(
            name: 'notification_status',
            description: 'Whether GLPI actually emailed anybody about a ticket, change or '
                . 'problem: which notifications were generated, to which address, when each was '
                . 'sent, and which are still queued or have failed to send. Use it whenever '
                . 'somebody says they were never told, before insisting that a requester was '
                . 'notified, and when a reply seems to have gone nowhere. It reports what GLPI '
                . 'did with the message, not whether the person read it.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'Ticket, Change or Problem. Defaults to Ticket.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to use the one the conversation is about.',
                    ],
                ],
            ],
            handler: [self::class, 'runNotifications'],
            // Gated on the item: the rows are about that record, and the
            // `notification` right is an administrator's right that no
            // technician asking "did they get it" would hold.
            right: null,
            pinned: false
        );
    }

    /** @return array<string,mixed> */
    public static function runNotifications(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context->items_id !== null && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        $itemtype = $itemtype !== '' ? $itemtype : Ticket::class;
        $item     = $items_id > 0 ? getItemForItemtype($itemtype) : false;

        if ($item === false || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            throw new ToolException(sprintf('No %s %d is visible to you.', $itemtype, $items_id));
        }

        $rows    = [];
        $pending = 0;
        $failed  = 0;

        foreach (
            getAllDataFromTable(
                QueuedNotification::getTable(),
                ['itemtype' => $itemtype, 'items_id' => $items_id],
                false,
                'create_time DESC'
            ) as $row
        ) {
            $sent  = self::stamp($row['sent_time'] ?? null);
            $tries = (int) ($row['sent_try'] ?? 0);

            if ($sent === null) {
                $tries > 0 ? $failed++ : $pending++;
            }

            if (count($rows) >= self::LIMIT) {
                continue;
            }

            $rows[] = array_filter([
                'event'     => (string) ($row['event'] ?? ''),
                'subject'   => (string) ($row['name'] ?? ''),
                'to'        => trim(
                    (string) ($row['recipientname'] ?? '') . ' <' . (string) ($row['recipient'] ?? '') . '>'
                ),
                'queued'    => self::stamp($row['create_time'] ?? null),
                'sent'      => $sent,
                'state'     => $sent !== null
                    ? 'sent'
                    : ($tries > 0
                        ? sprintf('failed after %d attempt(s) — still in the queue', $tries)
                        : 'queued, not sent yet'),
                'by'        => (string) ($row['mode'] ?? ''),
            ], static fn($v): bool => $v !== null && $v !== '' && $v !== ' <>');
        }

        return array_filter([
            'itemtype'      => $itemtype,
            'id'            => $items_id,
            'notifications' => $rows,
            'still_queued'  => $pending ?: null,
            'failing'       => $failed ?: null,
            'note'          => self::mailAdvice($rows, $pending, $failed),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function mailAdvice(array $rows, int $pending, int $failed): string
    {
        if ($rows === []) {
            return 'GLPI has no record of notifying anybody about this. Either no notification '
                . 'is configured for what happened, or the queue has been cleaned — the '
                . 'housekeeping cron removes old entries, so an old ticket showing nothing is '
                . 'not proof that nothing was sent.';
        }

        if ($failed > 0) {
            return sprintf(
                '%d message(s) have failed to send and are still in the queue. The requester has '
                . 'not been told, whatever the ticket says.',
                $failed
            );
        }

        if ($pending > 0) {
            return sprintf(
                '%d message(s) are queued and have not gone out yet — GLPI sends them on a '
                . 'cron, so this is normal for the last few minutes and a problem after that.',
                $pending
            );
        }

        return 'Sent means GLPI handed the message to the mail server. It is not proof of '
            . 'delivery and certainly not of anybody reading it.';
    }

    // ----------------------------------------------------------------- bits

    private static function userName(int $users_id): ?string
    {
        if ($users_id <= 0) {
            return null;
        }

        $name = \getUserName($users_id);

        return is_string($name) && trim($name) !== '' ? trim($name) : "user $users_id";
    }

    private static function stamp(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }
}
