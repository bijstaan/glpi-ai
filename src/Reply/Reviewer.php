<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai\Reply;

use CommonITILObject;
use GlpiPlugin\Glpiai\AiException;
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Draft\Evidence;
use GlpiPlugin\Glpiai\Prompt;
use GlpiPlugin\Glpiai\Settings;
use Ticket;

/**
 * Reviewing a reply a technician wrote, and never writing one.
 *
 * The single exception in this plugin's rule that nothing it produces reaches
 * a requester, and it is an exception because it inverts the risk rather than
 * accepting it. A model that writes to a customer is a reputational event
 * carried on a client's behalf the first time it is confidently wrong. A model
 * that *reads* what a person wrote, before that person sends it, can only ever
 * cost them the ten seconds it takes to disagree with it.
 *
 * Everything about the shape follows from that:
 *
 *  - **It returns flags, not text.** There is no rewritten version to accept,
 *    because offering one is how "review" becomes "write" over a fortnight of
 *    people clicking the easier button.
 *  - **It never blocks Save.** The reply belongs to the technician. A review
 *    that can stop a customer being answered is a review that will be switched
 *    off the first busy afternoon, and rightly.
 *  - **It is asked to say nothing.** The failure mode that kills a reviewer is
 *    not missing a leak, it is flagging four things on a fine reply until
 *    people stop reading it. The instruction says so in those words, and an
 *    empty flag list is the expected answer.
 *
 * The four kinds it may raise are fixed and deliberately narrow: internal
 * content carried across, unexplained jargon, no statement of what happens
 * next, and the wrong tone for a customer. It is *not* asked whether the reply
 * is factually right — that is a claim about the work rather than about the
 * writing, the model cannot check it, and a confident "this is wrong" about
 * something a technician just did is the fastest way to lose their attention.
 */
final class Reviewer
{
    /**
     * Output budget. A handful of flags is a few hundred tokens; the rest is
     * the usual reasoning-model allowance, on which see Triage::MAX_TOKENS.
     */
    private const MAX_TOKENS = 2500;

    /** A reply shorter than this is an acknowledgement, not a letter. */
    private const MIN_CHARS = 40;

    /** Ceiling on what is sent: the reply, and the ticket around it. */
    private const MAX_REPLY_CHARS   = 8000;
    private const MAX_CONTEXT_CHARS = 6000;

    /** The only things it may raise, and what each one means. */
    public const KINDS = ['internal', 'jargon', 'next_step', 'tone'];

    public static function available(): bool
    {
        return Settings::flag('enabled') && Settings::flag('reply_review_enabled');
    }

    /**
     * Why this reply cannot be reviewed, or null when it can.
     *
     * @param CommonITILObject $item the ticket, change or problem being answered
     */
    public static function refusal(CommonITILObject $item, string $text): ?string
    {
        if (!Settings::flag('enabled')) {
            return __('AI features are switched off.', 'glpiai');
        }

        if (!Settings::flag('reply_review_enabled')) {
            return __('Reply review is switched off.', 'glpiai');
        }

        if (!Settings::entityAllowed((int) $item->fields['entities_id'])) {
            return __('This entity is not permitted to use AI features.', 'glpiai');
        }

        if (mb_strlen(trim(strip_tags($text))) < self::MIN_CHARS) {
            return __('There is not enough written yet to review.', 'glpiai');
        }

        return null;
    }

    /**
     * Review one drafted reply.
     *
     * @return array{ok:bool,message:string,verdict:string,flags:array<int,array<string,string>>}
     */
    public static function review(CommonITILObject $item, string $text): array
    {
        $fail = static fn(string $why): array => [
            'ok'      => false,
            'message' => $why,
            'verdict' => '',
            'flags'   => [],
        ];

        $refusal = self::refusal($item, $text);
        if ($refusal !== null) {
            return $fail($refusal);
        }

        $entities_id = (int) $item->fields['entities_id'];

        try {
            $prompt = Prompt::make(self::userText($item, $text), self::instruction())
                // The quality tier, unlike triage. This is a judgement about
                // register and implication rather than a classification, it
                // happens once when somebody presses a button, and the way it
                // fails is by being wrong in a way that reads as pedantic —
                // which is exactly what a cheaper model is worse at.
                ->withTier(Prompt::TIER_QUALITY)
                ->withSchema(self::schema(), 'reply_review')
                ->withMaxTokens(self::MAX_TOKENS);

            $completion = Client::complete($prompt, $entities_id);
        } catch (AiException $e) {
            return $fail($e->userMessage());
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }

        $data = $completion->data;
        if (!is_array($data)) {
            return $fail($completion->wasTruncated()
                ? __('The model used its whole budget before answering.', 'glpiai')
                : __('The provider did not return a usable review.', 'glpiai'));
        }

        $flags   = self::validate((array) ($data['flags'] ?? []), $text);
        $verdict = $flags === [] ? 'ok' : 'check';

        Review::record(
            $item->getType(),
            (int) $item->getID(),
            $entities_id,
            $verdict,
            $flags,
            $text,
            $completion->provider,
            $completion->model
        );

        return ['ok' => true, 'message' => '', 'verdict' => $verdict, 'flags' => $flags];
    }

    /**
     * Keep the flags that are about this reply.
     *
     * A flag whose quote is not in the reply is discarded outright. It is the
     * one check worth making here and it is cheap: a quote the technician
     * cannot find in their own text is unanswerable, and a reviewer that
     * appears to be reading a different document is one nobody trusts again.
     * Comparison is on normalised text because the reply arrives as HTML and
     * the quote comes back as prose.
     *
     * @param array<int,mixed> $raw
     * @return array<int,array<string,string>>
     */
    private static function validate(array $raw, string $text): array
    {
        $haystack = self::plain($text);
        $flags    = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind  = trim((string) ($item['kind'] ?? ''));
            $quote = trim((string) ($item['quote'] ?? ''));
            $why   = trim((string) ($item['why'] ?? ''));

            if (!in_array($kind, self::KINDS, true) || $why === '') {
                continue;
            }

            // A missing-next-step flag has nothing to quote — it is about
            // what is *not* there — so it alone is allowed an empty quote.
            if ($kind !== 'next_step') {
                if ($quote === '' || !str_contains($haystack, self::plain($quote))) {
                    continue;
                }
            }

            $flags[] = [
                'kind'  => $kind,
                'quote' => mb_substr($quote, 0, 300),
                'why'   => mb_substr($why, 0, 400),
            ];
        }

        return $flags;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // --------------------------------------------------------------- prompt

    private static function instruction(): string
    {
        return implode("\n", [
            'A technician at a managed service provider has written a reply to a customer on a',
            'support ticket and has not sent it yet. Read it and say whether anything in it needs',
            'a second look before it goes.',
            '',
            'You are not writing this reply and you will not be asked to. Do not suggest wording,',
            'do not rewrite anything, and do not produce an improved version. The technician is',
            'the author; you are the last read before it is sent.',
            '',
            '**Say nothing wherever you can.** Most replies are fine and the correct answer for',
            'those is an empty list. A reviewer that finds something every time is one people',
            'stop reading, at which point it catches nothing at all. Raise a flag only where you',
            'would genuinely stop a colleague on their way to the send button.',
            '',
            'The only four things you may raise:',
            '',
            '  - internal — something in the reply came from the internal notes and should not',
            '    leave the team: a colleague\'s aside, a supplier\'s failure, a candid word about',
            '    the customer or their equipment, speculation about a cause nobody confirmed, or',
            '    a phrase lifted from an internal note. This is the one worth being sensitive',
            '    about; the rest are courtesies.',
            '  - jargon — a term, an abbreviation or a product name the customer has not used',
            '    themselves and would not be expected to know, left unexplained.',
            '  - next_step — the reply leaves the customer without knowing what happens now: who',
            '    does what, by when, or what is needed from them. Flag this only where it is',
            '    genuinely missing, not where the answer is simply "nothing, it is fixed".',
            '  - tone — wrong register for a customer: blaming them, brusqueness that will read',
            '    as annoyance, or promising something the ticket does not support.',
            '',
            'Do not flag spelling, grammar, punctuation, formatting, or brevity by itself. Do not',
            'argue with the technical content: whether the fix was right is not yours to judge',
            'and you cannot check it from here.',
            '',
            'Quote exactly. Every flag except next_step carries a short verbatim span from the',
            'reply — copied character for character, so the technician can find it. If you cannot',
            'quote it, you cannot flag it.',
            '',
            'One sentence per flag for why, addressed to the technician, plainly. No preamble, no',
            'praise, no summary of the reply.',
        ]);
    }

    /**
     * What the model is given: the ticket, the internal notes, and the reply.
     *
     * The internal notes are the point of this. Flagging a leak means knowing
     * what "internal" was — a phrase is only carried across if there is
     * somewhere it was carried from — and without them the model is guessing
     * at what sounds private, which is the same guess the technician already
     * made. They are also, unavoidably, the most sensitive thing this plugin
     * sends anywhere, which is why the whole feature has its own switch on top
     * of the entity gate.
     */
    private static function userText(CommonITILObject $item, string $text): string
    {
        $lines = ['THE TICKET: ' . (string) $item->fields['name']];

        if ($item instanceof Ticket) {
            $evidence = Evidence::forTicket($item);

            $lines[] = '';
            $lines[] = 'What the customer reported:';
            $lines[] = mb_substr((string) $evidence['ticket']['reported'], 0, 1500);

            $internal = array_values(array_filter(
                $evidence['timeline'],
                static fn(array $entry): bool => (bool) $entry['private']
            ));

            if ($internal !== []) {
                $lines[] = '';
                $lines[] = 'INTERNAL notes on this ticket. These were written between colleagues '
                         . 'and the customer has not seen them:';
                foreach ($internal as $entry) {
                    $lines[] = '  - ' . mb_substr($entry['text'], 0, 600);
                }
            }
        } else {
            $lines[] = '';
            $lines[] = mb_substr(strip_tags((string) ($item->fields['content'] ?? '')), 0, 1500);
        }

        $context = mb_substr(implode("\n", $lines), 0, self::MAX_CONTEXT_CHARS);

        return $context . "\n\n"
             . "THE REPLY, not yet sent:\n"
             . mb_substr(self::plain($text), 0, self::MAX_REPLY_CHARS);
    }

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'flags' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'kind'  => ['type' => 'string', 'enum' => self::KINDS],
                            'quote' => [
                                'type'        => 'string',
                                'description' => 'A short verbatim span of the reply. Empty only '
                                    . 'for next_step.',
                            ],
                            'why'   => [
                                'type'        => 'string',
                                'description' => 'One sentence to the technician.',
                            ],
                        ],
                        'required'   => ['kind', 'quote', 'why'],
                    ],
                ],
            ],
            'required'   => ['flags'],
        ];
    }

    /** What each kind is called on screen. */
    public static function label(string $kind): string
    {
        return match ($kind) {
            'internal'  => __('Internal content', 'glpiai'),
            'jargon'    => __('Unexplained term', 'glpiai'),
            'next_step' => __('No next step', 'glpiai'),
            'tone'      => __('Tone', 'glpiai'),
            default     => $kind,
        };
    }
}
