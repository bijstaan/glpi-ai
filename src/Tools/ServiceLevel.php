<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Tools;

use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;
use GlpiPlugin\Glpiai\ToolException;
use OLA;
use SLA;
use SLM;
use Ticket;

/**
 * The clocks on a ticket: what was promised, what is left, and what has gone.
 *
 * `read_ticket` reports the resolve-by timestamp and whether it has passed,
 * which is the answer to a question nobody asks in those words. What a
 * technician asks is "how long have I got", and the honest answer is not the
 * difference between two timestamps: an SLA runs on a calendar, so four hours
 * left at 4pm on a Friday is Monday lunchtime, and a model doing the
 * arithmetic itself will confidently say Friday evening.
 *
 * So the remaining time here is *working* time, computed by the agreement's
 * own calendar through the same method core uses to set the target in the
 * first place. Three other things are stated rather than left to be inferred,
 * each because getting it wrong is how a technician is told the wrong thing
 * with confidence:
 *
 *  - **A paused clock.** A ticket on hold is not consuming its SLA, and the
 *    target on the record is the one from before the pause. "Two hours left"
 *    on a pending ticket is meaningless until somebody takes it off hold.
 *  - **Response and resolution are different promises.** Most breaches are of
 *    the response target, which is invisible on a ticket that has been
 *    replied to and is what the customer's report will count.
 *  - **The OLA behind the SLA.** The internal target is usually tighter, and
 *    it is the one the technician is actually working to.
 */
final class ServiceLevel
{
    public static function tool(): Tool
    {
        return new Tool(
            name: 'sla_status',
            description: 'The service-level clocks on a ticket: which SLA and internal OLA '
                . 'apply, when the first response and the resolution were promised, how much '
                . 'working time is left against each, what has already been breached and by how '
                . 'much, whether the clock is paused because the ticket is on hold, and when the '
                . 'next escalation fires. Use it whenever somebody asks how long there is on a '
                . 'ticket, whether it is about to breach, or what to work on first — the '
                . 'remaining time is calendar working time, so do not compute it yourself from '
                . 'the due date.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'The ticket. Omit to use the one the conversation is about.',
                    ],
                ],
            ],
            handler: [self::class, 'run'],
            right: 'ticket'
        );
    }

    /** @return array<string,mixed> */
    public static function run(array $arguments, ToolContext $context): array
    {
        $refusal = Lookup::requireSession();
        if ($refusal !== null) {
            throw new ToolException($refusal);
        }

        $id = (int) ($arguments['tickets_id'] ?? 0);
        if ($id <= 0 && $context->isAbout(Ticket::class)) {
            $id = (int) $context->items_id;
        }

        $ticket = new Ticket();
        if ($id <= 0 || !$ticket->getFromDB($id) || !$ticket->canViewItem()) {
            throw new ToolException("No ticket $id is visible to you.");
        }

        $status  = (int) $ticket->fields['status'];
        $solved  = self::stamp($ticket->fields['solvedate'] ?? null)
            ?? self::stamp($ticket->fields['closedate'] ?? null);
        $waiting = $status === Ticket::WAITING;

        $out = [
            'tickets_id' => $id,
            'title'      => (string) $ticket->fields['name'],
            'status'     => Ticket::getStatus($status),
            'response'   => self::clock($ticket, SLM::TTO, $solved),
            'resolution' => self::clock($ticket, SLM::TTR, $solved),
        ];

        if ($waiting) {
            $out['paused'] = array_filter([
                'since' => self::stamp($ticket->fields['begin_waiting_date'] ?? null),
                'note'  => 'The ticket is on hold, so the clock is not running and the targets '
                    . 'below are the ones from before it was paused. They move out by however '
                    . 'long the hold lasts.',
            ]);
        }

        $paused_for = (int) ($ticket->fields['sla_waiting_duration'] ?? 0);
        if ($paused_for > 0) {
            $out['paused_total'] = self::duration($paused_for);
        }

        $out['escalation'] = self::escalation($ticket);
        $out['note']       = self::advice($out);

        return array_filter($out, static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * One promise: the agreement behind it, the target, and what is left.
     *
     * @param SLM::TTO|SLM::TTR $type
     * @return array<string,mixed>
     */
    private static function clock(Ticket $ticket, int $type, ?string $solved): array
    {
        [$date_field, $sla_field] = SLA::getFieldNames($type);
        [, $ola_field]            = OLA::getFieldNames($type);

        $target = self::stamp($ticket->fields[$date_field] ?? null);

        $sla = new SLA();
        $ola = new OLA();

        $has_sla = (int) ($ticket->fields[$sla_field] ?? 0) > 0
            && $sla->getFromDB((int) $ticket->fields[$sla_field]);
        $has_ola = (int) ($ticket->fields[$ola_field] ?? 0) > 0
            && $ola->getFromDB((int) $ticket->fields[$ola_field]);

        // The moment the promise was actually met, which differs per type:
        // a response is met when somebody first took the ticket, a resolution
        // when it was solved. Measuring both against the solve date would
        // report every late first reply on a solved ticket as met.
        $met = $type === SLM::TTO
            ? self::responded($ticket)
            : $solved;

        $out = [
            'promise'   => $type === SLM::TTO ? 'first response' : 'resolution',
            'agreement' => $has_sla ? self::describe($sla) : null,
            'internal_target' => $has_ola ? self::describe($ola) : null,
            'due'       => $target,
        ];

        if ($target === null) {
            $out['note'] = $has_sla
                ? 'The SLA is attached but no target date has been computed on this ticket.'
                : 'No service level applies to this ticket for this promise.';

            return array_filter($out, static fn($v): bool => $v !== null);
        }

        $against = $met ?? date('Y-m-d H:i:s');
        $late    = $against > $target;

        $out['breached'] = $late;
        $out['met_at']   = $met;

        // Working time, from the agreement's own calendar. Without one there
        // is no calendar to ask and wall-clock is the honest fallback — said
        // so in the note rather than passed off as the same answer.
        $agreement = $has_sla ? $sla : ($has_ola ? $ola : null);
        $seconds   = $agreement !== null
            ? ($late
                ? $agreement->getActiveTimeBetween($target, $against)
                : $agreement->getActiveTimeBetween($against, $target))
            : abs(strtotime($target) - strtotime($against));

        if ($met !== null) {
            $out[$late ? 'missed_by' : 'met_with_to_spare'] = self::duration((int) $seconds);
        } else {
            $out[$late ? 'overdue_by' : 'time_left'] = self::duration((int) $seconds);
        }

        if ($agreement === null) {
            $out['note'] = 'This target was set by hand rather than by an agreement, so the '
                . 'time above is wall-clock and not working hours.';
        }

        return array_filter($out, static fn($v): bool => $v !== null);
    }

    /**
     * When somebody first responded, as core counts it.
     *
     * `takeintoaccount_delay_stat` is seconds from opening to the first action
     * by a technician, and is 0 while nobody has touched it — which is the
     * state a response-breach question is asked in.
     */
    private static function responded(Ticket $ticket): ?string
    {
        $delay = (int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0);
        $open  = self::stamp($ticket->fields['date'] ?? null);

        if ($delay <= 0 || $open === null) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($open) + $delay);
    }

    /** "4 hours, on the Business calendar" — what the agreement actually promises. */
    private static function describe(SLA|OLA $agreement): string
    {
        $number = (int) $agreement->fields['number_time'];
        $unit   = (string) $agreement->fields['definition_time'];

        $out = sprintf(
            '%s (%d %s)',
            (string) $agreement->fields['name'],
            $number,
            $number === 1 ? $unit : $unit . 's'
        );

        $calendar = (int) ($agreement->fields['calendars_id'] ?? 0);
        if ($calendar > 0) {
            $name = \Dropdown::getDropdownName('glpi_calendars', $calendar);
            if (is_string($name) && $name !== '' && $name !== '&nbsp;') {
                return $out . ', on the ' . $name . ' calendar';
            }
        }

        return $out . ', around the clock';
    }

    /**
     * The next automatic escalation, if one is pending.
     *
     * @return array<string,mixed>|null
     */
    private static function escalation(Ticket $ticket): ?array
    {
        foreach ([SLM::TTR, SLM::TTO] as $type) {
            $sla    = new SLA();
            $action = $sla->getNextActionForTicket($ticket, $type);

            if ($action === false) {
                continue;
            }

            $level = $sla->getLevelFromAction($action);

            return array_filter([
                'level' => $level === false ? null : (string) $level->fields['name'],
                'at'    => self::stamp($action->fields['date'] ?? null),
                'for'   => $type === SLM::TTO ? 'first response' : 'resolution',
            ]);
        }

        return null;
    }

    /**
     * What to do about it, in one line.
     *
     * Said explicitly because a model handed two timestamps and a boolean will
     * report them accurately and draw no conclusion, and the conclusion is the
     * only part a technician wanted.
     *
     * @param array<string,mixed> $out
     */
    private static function advice(array $out): string
    {
        if (isset($out['paused'])) {
            return 'The clock is paused. Nothing is counting down until the ticket comes off hold.';
        }

        foreach (['response', 'resolution'] as $which) {
            if (!empty($out[$which]['breached']) && isset($out[$which]['overdue_by'])) {
                return sprintf(
                    'The %s target has already been missed by %s, and is still open. That is the '
                    . 'thing to say first.',
                    $out[$which]['promise'],
                    $out[$which]['overdue_by']
                );
            }
        }

        $left = $out['resolution']['time_left'] ?? null;
        if ($left !== null) {
            return sprintf('%s of working time left to resolve.', $left);
        }

        // A target missed on a ticket that has since been solved. Still worth
        // a sentence: it is what the customer's report will count, and a model
        // told only "nothing outstanding" will say the ticket was fine.
        foreach (['response', 'resolution'] as $which) {
            if (!empty($out[$which]['breached']) && isset($out[$which]['missed_by'])) {
                return sprintf(
                    'Nothing is still running, but the %s target was missed by %s.',
                    $out[$which]['promise'],
                    $out[$which]['missed_by']
                );
            }
        }

        return 'Nothing outstanding against a service level.';
    }

    private static function stamp(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }

    /** A duration a person would say out loud. */
    private static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return 'under a minute';
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return sprintf('%d minutes', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        if ($hours < 24) {
            return $rest > 0
                ? sprintf('%d hours %d minutes', $hours, $rest)
                : sprintf('%d hours', $hours);
        }

        $days  = intdiv($hours, 24);
        $hours %= 24;

        return $hours > 0
            ? sprintf('%d days %d hours', $days, $hours)
            : sprintf('%d days', $days);
    }
}
