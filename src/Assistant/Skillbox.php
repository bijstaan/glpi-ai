<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Assistant;

use GlpiPlugin\Glpiai\Search;
use GlpiPlugin\Glpiai\Tool;
use GlpiPlugin\Glpiai\ToolContext;

/**
 * Skill search, for the skills a request cannot afford to carry.
 *
 * {@see Skill} is read into the system prompt on every request it applies to,
 * which is right for the ones that apply and is the whole problem for the rest.
 * Two things decide what gets carried, and both are guesses made before the
 * question was asked: the triggers an administrator chose, and a character
 * budget. A procedure whose triggers say "ransomware" is not carried by a
 * technician who typed "all his files have gone weird", and a procedure that is
 * seven thousand characters long is not carried at all once anything else has
 * been.
 *
 * Neither failure is visible. The model answers from general knowledge, in the
 * same confident voice, and the site's own procedure — the one with the step
 * about the shared mailbox that nobody remembers — is never mentioned. The
 * settings page lists it; the conversation never sees it.
 *
 * So the ones that are not carried are catalogued in the prompt, a name and a
 * line each, and this is how the model reads one. Exactly the trade
 * {@see \GlpiPlugin\Glpiai\Toolbox} makes for tools: a catalogue entry costs a
 * few words, the full text costs a round trip, and the round trip is only spent
 * on the conversations that need it.
 *
 * **Not a tool that does something.** A skill is text — reading one changes the
 * answer, not the world — so this needs no write switch, no right of its own,
 * and no audit trail beyond the tool log every call already leaves. It is a
 * tool for exactly one reason: a tool call is the only way a model has to ask
 * for something mid-turn.
 *
 * **No expand() step.** Its counterpart in Toolbox has one, because a tool that
 * has been *found* still has to be declared before it can be called. A skill
 * that has been found is already in the transcript: the result is the
 * instructions, and the model has read them by the time it sees them.
 */
final class Skillbox
{
    public const NAME = 'find_skills';

    /** Skills returned by one search. */
    private const MAX_MATCHES = 4;

    /**
     * Instructions one call may return, in characters.
     *
     * Larger than {@see Skill::BUDGET}, and deliberately: that budget is paid
     * on every request of a conversation, and this is paid once, by a model
     * that asked. Past it the remaining matches are named rather than cut short
     * — a procedure truncated mid-sentence is worse than one nobody opened,
     * because its last legible instruction looks like its last instruction.
     */
    private const RESULT_BUDGET = 12000;

    /**
     * The search tool, when this entity has anything to search.
     *
     * A list rather than a nullable, so the registry can splice it in without a
     * conditional: an instance with no skills written gets no tool, and an
     * administrator who has not used the feature is never shown it.
     *
     * @return Tool[]
     */
    public static function tools(int $entities_id): array
    {
        return Skill::active($entities_id) === [] ? [] : [self::tool()];
    }

    /**
     * Its description has to do two jobs: say what it is for, and say that what
     * comes back is to be *followed*. Without the second, a model that finds a
     * procedure summarises it to the technician as a thing that exists — which
     * reads as a broken feature and is really a description that never said
     * what the text is for.
     */
    public static function tool(): Tool
    {
        return new Tool(
            name: self::NAME,
            description: 'Read a written procedure from this site. An administrator here has '
                . 'written instructions for particular situations — how a suspected ransomware '
                . 'call is handled, what to check before escalating a VPN fault, the wording of '
                . 'a handover note — and the ones not already included in your instructions are '
                . 'listed there by name. Call this with a plain description of the situation '
                . '("ransomware", "a user is leaving", "the VPN is down for one person") before '
                . 'answering anything a procedure might cover. What comes back was written by '
                . 'this site for this site: follow it over your own habits, and over anything '
                . 'you know generally.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'The situation, in plain words — or the name of the '
                            . 'procedure if you have been given it.',
                    ],
                ],
                'required'   => ['query'],
            ],
            handler: [self::class, 'run'],
            // No right of its own. A skill is an instruction an administrator
            // wrote for the assistant, and every request already carries the
            // ones that trigger; there is nothing here a caller could read that
            // the prompt would not have handed them anyway.
            right: null,
            source: 'glpiai'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function run(array $arguments = [], mixed $context = null): array
    {
        // The conversation's entity, never an argument: skills are
        // entity-scoped and recursive, so letting a prompt name one would be
        // letting it read another tenant's procedures.
        $entities_id = $context instanceof ToolContext
            ? $context->entities_id
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $skills = Skill::active($entities_id);

        if ($skills === []) {
            return [
                'procedures' => [],
                'note'       => 'This site has no written procedures.',
            ];
        }

        $corpus = [];
        foreach ($skills as $index => $skill) {
            $corpus[] = [
                'key'  => $index,
                'name' => (string) $skill->fields['name'],
                // Triggers are part of the corpus, not just of the matching:
                // they are the words an administrator thought somebody would
                // use for this, which is exactly what a query is made of.
                'text' => (string) $skill->fields['triggers'] . ' '
                    . (string) $skill->fields['comment'] . ' '
                    . (string) $skill->fields['instructions'],
            ];
        }

        $matches = Search::rank($corpus, trim((string) ($arguments['query'] ?? '')), self::MAX_MATCHES);

        if ($matches === []) {
            return [
                'procedures' => [],
                'note'       => 'Nothing matched. These exist: '
                    . implode(', ', array_map(
                        static fn(Skill $s): string => (string) $s->fields['name'],
                        $skills
                    ))
                    . '. Ask for one by name, or answer without one and say that is what you '
                    . 'are doing.',
            ];
        }

        $out     = [];
        $skipped = [];
        $spent   = 0;

        foreach ($matches as $index) {
            $skill = $skills[(int) $index];
            $text  = trim((string) $skill->fields['instructions']);

            if ($spent + mb_strlen($text) > self::RESULT_BUDGET) {
                $skipped[] = (string) $skill->fields['name'];
                continue;
            }

            $spent += mb_strlen($text);
            $out[]  = [
                'name'         => (string) $skill->fields['name'],
                'instructions' => $text,
            ];
        }

        $note = 'These were written by an administrator at this site. Follow them for this '
              . 'question — they are more specific than anything you know generally, and where '
              . 'they conflict with your own habits they win.';

        if ($skipped !== []) {
            $note .= ' Also matched, and not included here for length: '
                   . implode(', ', $skipped) . '. Ask for one by name if this is not it.';
        }

        return ['procedures' => $out, 'note' => $note];
    }
}
