<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

use CommonITILObject;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Api\HL\StreamedResponseWrapper;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use GlpiPlugin\Glpiai\Assistant\Assistant;
use GlpiPlugin\Glpiai\Assistant\Context;
use GlpiPlugin\Glpiai\Assistant\Thread;
use GlpiPlugin\Glpiai\Draft\Draft;
use GlpiPlugin\Glpiai\Draft\Drafter;
use GlpiPlugin\Glpiai\Reply\Reviewer;
use GlpiPlugin\Glpiai\Triage\Suggestion;
use GlpiPlugin\Glpiai\Triage\Triage;
use RuntimeException;
use Session;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Ticket;

/**
 * This plugin's features, for the technician app.
 *
 * The same four surfaces the central interface has — the assistant, the
 * drafted solution, the triage suggestion and the reply review — reachable
 * over the high-level API so glpi-mobile can offer them on a phone. Every
 * route mirrors the rights of the `ajax/` endpoint it stands in for, and the
 * mirroring is deliberate rather than incidental: an authority answered in two
 * places drifts, so where a decision already exists it is called rather than
 * repeated. Thread ownership goes through `Thread::mine()`, drafting through
 * `Drafter::refusal()`, triage through `Ticket::canUpdateItem()`.
 *
 * Central-interface only, like everything else here. A requester's phone gets
 * the service catalogue; nothing on it may put them in front of a model.
 *
 * **The streaming route is the reason this is not four thin wrappers.** An
 * agent run is four to eight vendor round trips and takes the better part of a
 * minute, and a phone showing a still spinner for that long reads as a broken
 * app — people background it, which on mobile means the request dies. So
 * `/threads/{id}/stream` speaks server-sent events exactly as
 * `ajax/assistant-stream.php` does, through the same `Progress` sink, and the
 * app renders the turns, the tools and the answer as they arrive.
 */
#[Route(path: '/GlpiAi', tags: ['GlpiAi'])]
final class MobileController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** Optional-parameter read: core's getParameter() warns on absent keys. */
    private static function param(Request $request, string $name, mixed $default = null): mixed
    {
        return $request->hasParameter($name) ? $request->getParameter($name) : $default;
    }

    /**
     * What this instance will actually answer for the caller, right now.
     *
     * The capability map says which features are switched on and which rights
     * this person holds; it cannot say whether *this entity* may send data to a
     * provider, because the entity is chosen after the session starts and can
     * be changed from the app. Availability is therefore asked again here, in
     * the entity the request is being made in — which is the difference between
     * a control that is absent and a control that takes a question and then
     * refuses it.
     */
    #[Route(path: '/status', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function status(Request $request): Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $entities_id = (int) Session::getActiveEntity();
        $on          = Settings::flag('enabled') && Settings::entityAllowed($entities_id);

        return new JSONResponse([
            'enabled'      => Settings::flag('enabled'),
            'entity_allowed' => Settings::entityAllowed($entities_id),
            'assistant'    => Assistant::available($entities_id),
            'draft'        => $on && Settings::flag('draft_enabled'),
            'reply_review' => $on && Settings::flag('reply_review_enabled'),
            'triage'       => $on && Settings::flag('triage_enabled'),
        ], 200);
    }

    // ------------------------------------------------------------ assistant

    /**
     * This technician's recent conversations, newest first.
     *
     * The app's history list. Ownership is the whole access rule — see
     * `Thread::mine()` — so the query is scoped to the caller rather than
     * filtered afterwards.
     */
    #[Route(path: '/threads', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function listThreads(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $rows = [];
        foreach (Thread::recent(30) as $row) {
            $rows[] = self::threadRow($row);
        }

        return new JSONResponse(['threads' => $rows], 200);
    }

    /**
     * Open or resume the conversation for a context. Body `{itemtype, items_id}`.
     *
     * Both are optional: a thread with no context is the general one, which is
     * what the drawer entry opens. When they are given they are not trusted —
     * `Context::item()` validates the type, loads the record and applies GLPI's
     * own rights check, and an item that fails any of that degrades to no
     * context rather than to an error, exactly as the web panel does.
     */
    #[Route(path: '/threads', methods: ['POST'])]
    #[RouteVersion(introduced: '2.0')]
    public function openThread(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $itemtype = (string) self::param($request, 'itemtype', '');
        $items_id = (int) self::param($request, 'items_id', 0);

        $item = Context::item($itemtype, $items_id);
        if ($item === null) {
            $itemtype = '';
            $items_id = 0;
        }

        $threads_id = Thread::open($itemtype, $items_id, Context::entity($item));

        $row = Thread::byId($threads_id) ?? [];

        return new JSONResponse(
            self::threadRow($row) + [
                'context'  => Context::label($item),
                'messages' => self::messages($threads_id),
            ],
            200
        );
    }

    /** One conversation, with its transcript. */
    #[Route(path: '/threads/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getThread(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $threads_id = (int) $request->getAttribute('id');
        $row        = Thread::mine($threads_id);
        if ($row === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $item = Context::item((string) $row['itemtype'], (int) $row['items_id']);

        return new JSONResponse(
            self::threadRow($row) + [
                'context'  => Context::label($item),
                'messages' => self::messages($threads_id),
            ],
            200
        );
    }

    /**
     * Ask, and wait for the whole answer. Body `{question}`.
     *
     * The fallback for a client that cannot stream — and the honest one to
     * call from a background task, since nothing here depends on a live
     * connection. `Assistant::ask()` does all of it: the transcript, the tool
     * loop and the persistence.
     */
    #[Route(path: '/threads/{id}/ask', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function ask(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $threads_id = (int) $request->getAttribute('id');
        if (Thread::mine($threads_id) === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        try {
            $answer = Assistant::ask($threads_id, (string) self::param($request, 'question', ''));
        } catch (AiException $e) {
            // The provider's own words. A technician who has just watched a
            // question fail is better served by "rate limit exceeded" than by
            // a house-style apology.
            return new JSONResponse(['error' => 'provider', 'message' => $e->getMessage()], 502);
        }

        return new JSONResponse($answer, 200);
    }

    /**
     * Ask, out loud. Body `{question}`; the answer arrives as server-sent
     * events on the same connection.
     *
     * Event names are `Progress`'s own constants — `turn`, `tool`,
     * `tool_result`, `thinking`, `text`, `continued`, `done`, `failed` — plus
     * an `open` first frame carrying the thread id, so a client that connects
     * before the first turn has something to render.
     *
     * Everything that can refuse refuses *before* the response is handed back
     * for streaming: once the first `data:` line is out the status code is
     * already 200, and an error after that point is a line in a conversation
     * rather than an HTTP failure.
     */
    #[Route(path: '/threads/{id}/stream', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function stream(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $threads_id = (int) $request->getAttribute('id');
        if (Thread::mine($threads_id) === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $question = (string) self::param($request, 'question', '');

        $stream = new StreamedResponse(
            static function () use ($threads_id, $question): void {
                self::run($threads_id, $question);
            },
            200,
            [
                'Content-Type'  => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Connection'    => 'keep-alive',
                // Nginx buffers proxied responses by default, which holds every
                // event until the run is over and turns this back into the
                // route above. Meaningless to Apache, harmless everywhere else.
                'X-Accel-Buffering' => 'no',
            ]
        );

        // Wrapped so the HL API can carry it as a PSR-7 response and send the
        // Symfony one at the end. Without this the headers go out too early and
        // the router stringifies a body that does not exist yet.
        return new StreamedResponseWrapper($stream);
    }

    /**
     * The run itself, narrating as it goes.
     *
     * Split out of the route so the closure the streamer holds is small and
     * the guards above are unmistakably before it.
     */
    private static function run(int $threads_id, string $question): void
    {
        @set_time_limit(0);
        ignore_user_abort(false);

        // The session lock, released before the run starts. PHP holds an
        // exclusive lock on the session file for the life of a request, and
        // this request lives for the length of a model's answer — anything
        // else arriving on the same session would queue behind it for a
        // minute. `$_SESSION` stays readable in memory, so every rights check
        // already made, and every one a tool makes, behaves as it did.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Whatever buffering the stack arrived with, off: a buffer between here
        // and the socket is indistinguishable from a model that has not said
        // anything yet.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $send = static function (string $type, array $data = []): void {
            echo 'event: ' . $type . "\n";
            echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n\n";

            // Both, in this order. flush() alone leaves the data in PHP's own
            // buffer on some SAPIs, and connection_aborted() only becomes true
            // after a write has been attempted — so this is also how a phone
            // that went into a tunnel gets noticed.
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        $send('open', ['thread' => $threads_id]);

        try {
            $answer = Progress::watch(
                static function (string $type, array $data) use ($send): void {
                    if (connection_aborted()) {
                        throw new RuntimeException('The client went away.');
                    }

                    $send($type, $data);
                },
                static fn(): array => Assistant::ask($threads_id, $question)
            );

            // The finished answer, whole. The app has been assembling streamed
            // fragments, but only this carries the tool summary and the
            // truncation flags — and on a provider that does not stream it is
            // the first text the app has seen at all.
            $send(Progress::DONE, $answer);
        } catch (AiException $e) {
            $send(Progress::FAILED, ['message' => $e->userMessage()]);
        } catch (Throwable $e) {
            // Deliberately not the exception's message: what lands here is a
            // defect rather than something a technician can act on.
            trigger_error('glpiai: assistant stream failed: ' . $e->getMessage(), E_USER_WARNING);
            $send(Progress::FAILED, ['message' => __('Something went wrong answering that.', 'glpiai')]);
        }
    }

    /** Forget a conversation's transcript, keeping the thread itself. */
    #[Route(path: '/threads/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function clearThread(Request $request): Response
    {
        $denied = self::requireAssistant();
        if ($denied !== null) {
            return $denied;
        }

        $threads_id = (int) $request->getAttribute('id');
        if (Thread::mine($threads_id) === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        Thread::clear($threads_id);

        return new JSONResponse(['ok' => true], 200);
    }

    // --------------------------------------------------------------- drafts

    /**
     * The draft this ticket already has, or null.
     *
     * `kind` is `solution` (the default) or `article`. Reading one takes the
     * same right as writing the solution by hand, because it is the same text.
     */
    #[Route(path: '/tickets/{tickets_id}/draft', methods: ['GET'], requirements: ['tickets_id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getDraft(Request $request): Response
    {
        $ticket = self::draftableTicket($request);
        if ($ticket instanceof Response) {
            return $ticket;
        }

        $kind = self::kind($request);

        return new JSONResponse([
            'available' => Drafter::refusal($ticket) === null,
            'refusal'   => Drafter::refusal($ticket),
            'draft'     => self::draftRow(Draft::forTicket((int) $ticket->getID(), $kind)),
        ], 200);
    }

    /**
     * Draft one now. Body `{kind}`.
     *
     * Synchronous, like the button in the web UI: somebody is waiting on it,
     * and a draft that arrives after they have written the solution by hand is
     * not worth the round trip.
     */
    #[Route(path: '/tickets/{tickets_id}/draft', methods: ['POST'], requirements: ['tickets_id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function makeDraft(Request $request): Response
    {
        $ticket = self::draftableTicket($request);
        if ($ticket instanceof Response) {
            return $ticket;
        }

        $kind  = self::kind($request);
        $error = Drafter::draft((int) $ticket->getID(), $kind);
        if ($error !== '') {
            return new JSONResponse(['error' => 'provider', 'message' => $error], 502);
        }

        return new JSONResponse([
            'draft' => self::draftRow(Draft::forTicket((int) $ticket->getID(), $kind)),
        ], 200);
    }

    /**
     * Record what became of a draft: `used` or `discard`.
     *
     * Neither changes anything a user can see, and both are the entire
     * measurement of the feature — without them the only available statement
     * about drafting is that the drafts seem decent. The ticket is resolved
     * from the draft rather than trusted from the caller.
     */
    #[Route(
        path: '/drafts/{id}/{decision}',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'decision' => 'used|discard']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function decideDraft(Request $request): Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $drafts_id = (int) $request->getAttribute('id');
        $row       = Draft::byId($drafts_id);
        if ($row === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $ok = (string) $request->getAttribute('decision') === 'used'
            ? Drafter::markUsed($drafts_id)
            : Drafter::discard($drafts_id);

        return new JSONResponse(['ok' => $ok], $ok ? 200 : 400);
    }

    // --------------------------------------------------------------- triage

    /**
     * The triage suggestion for a ticket, with both sides of every proposal.
     *
     * The app needs the current value as well as the proposed one: "Category →
     * Email" is meaningless without knowing what it is now, and a suggestion
     * that matches the ticket is not worth a chip at all.
     */
    #[Route(path: '/tickets/{tickets_id}/triage', methods: ['GET'], requirements: ['tickets_id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getTriage(Request $request): Response
    {
        $ticket = self::viewableTicket($request);
        if ($ticket instanceof Response) {
            return $ticket;
        }

        return new JSONResponse([
            'can_apply'  => $ticket->canUpdateItem(),
            'suggestion' => self::triageRow(Suggestion::forTicket((int) $ticket->getID()), $ticket),
        ], 200);
    }

    /**
     * Run triage on this ticket now.
     *
     * Spends money at a provider, so it takes the right to change the ticket
     * rather than merely to look at it — `ajax/triage.php`'s `run`, exactly.
     */
    #[Route(path: '/tickets/{tickets_id}/triage', methods: ['POST'], requirements: ['tickets_id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function runTriage(Request $request): Response
    {
        $ticket = self::viewableTicket($request);
        if ($ticket instanceof Response) {
            return $ticket;
        }
        if (!$ticket->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        if (!Settings::flag('enabled') || !Settings::flag('triage_enabled')) {
            return new JSONResponse(['error' => 'disabled'], 400);
        }

        $row = Suggestion::forTicket((int) $ticket->getID());
        if ($row === null) {
            // Nothing queued this ticket — it arrived before the feature was
            // switched on, or through a route triage skips. Queue it here so
            // "run it now" means what it says.
            Suggestion::queue((int) $ticket->getID(), (int) $ticket->fields['entities_id']);
            $row = Suggestion::forTicket((int) $ticket->getID());
        }

        if ($row === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        // Re-queue first: a row that previously failed is in a terminal state,
        // and "try again" has to mean try again rather than re-read the error.
        Suggestion::store((int) $row['id'], ['state' => Suggestion::PENDING, 'error_message' => null]);

        if (!Triage::suggest((int) $row['id'])) {
            $fresh = Suggestion::byId((int) $row['id']);

            return new JSONResponse([
                'error'   => 'provider',
                'message' => (string) ($fresh['error_message'] ?? __('Triage failed.', 'glpiai')),
            ], 502);
        }

        $ticket->getFromDB((int) $ticket->getID());

        return new JSONResponse([
            'can_apply'  => $ticket->canUpdateItem(),
            'suggestion' => self::triageRow(Suggestion::forTicket((int) $ticket->getID()), $ticket),
        ], 200);
    }

    /**
     * Accept or reject one proposed field. Body `{field}`.
     *
     * Dismissing deliberately needs less than applying: recording that somebody
     * said no changes nothing about the ticket, and requiring update rights to
     * reject a suggestion would mean read-only technicians silently poison the
     * accept rate by leaving everything undecided.
     */
    #[Route(
        path: '/triage/{id}/{decision}',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'decision' => 'apply|dismiss']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function decideTriage(Request $request): Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $suggestions_id = (int) $request->getAttribute('id');
        $row            = Suggestion::byId($suggestions_id);
        if ($row === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $row['tickets_id']) || !$ticket->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $field = (string) self::param($request, 'field', '');

        if ((string) $request->getAttribute('decision') === 'apply') {
            if (!$ticket->canUpdateItem()) {
                return new JSONResponse(['error' => 'forbidden'], 403);
            }

            $error = Triage::apply($suggestions_id, $field);
            if ($error !== '') {
                return new JSONResponse(['error' => 'refused', 'message' => $error], 400);
            }
        } elseif (!Triage::dismiss($suggestions_id, $field)) {
            return new JSONResponse(['error' => 'refused'], 400);
        }

        $ticket->getFromDB((int) $ticket->getID());

        return new JSONResponse([
            'can_apply'  => $ticket->canUpdateItem(),
            'suggestion' => self::triageRow(Suggestion::byId($suggestions_id), $ticket),
        ], 200);
    }

    // --------------------------------------------------------- reply review

    /**
     * Read a reply before it is sent. Body `{itemtype, items_id, text}`.
     *
     * Writes nothing to the item. The right is answered against the ITIL object
     * rather than a profile flag — being able to see the ticket is what it takes
     * to be typing a reply on it, and this returns nothing the caller could not
     * already read.
     *
     * The flags come back structured rather than as the panel's HTML: the app
     * renders them itself, and a phone has no use for a desktop stylesheet.
     */
    #[Route(path: '/reply/review', methods: ['POST'])]
    #[RouteVersion(introduced: '2.0')]
    public function review(Request $request): Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $itemtype = (string) self::param($request, 'itemtype', '');
        $items_id = (int) self::param($request, 'items_id', 0);
        $text     = (string) self::param($request, 'text', '');

        if (!is_a($itemtype, CommonITILObject::class, true)) {
            return new JSONResponse(['error' => 'bad_itemtype'], 400);
        }

        /** @var CommonITILObject $item */
        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $result = Reviewer::review($item, $text);

        if (!$result['ok']) {
            // A refusal here is usually a rule rather than a failure — the
            // feature is off, the entity is not permitted, or there is not
            // enough text yet — so it is a 400 with the reason, not a 502.
            return new JSONResponse(['error' => 'refused', 'message' => $result['message']], 400);
        }

        $flags = [];
        foreach ($result['flags'] as $flag) {
            $flags[] = [
                'kind'  => (string) $flag['kind'],
                'label' => Reviewer::label((string) $flag['kind']),
                'quote' => (string) $flag['quote'],
                'why'   => (string) $flag['why'],
            ];
        }

        return new JSONResponse(['verdict' => $result['verdict'], 'flags' => $flags], 200);
    }

    // -------------------------------------------------------------- helpers

    /** 401/403 unless this is a central-interface session. */
    private static function requireCentral(): ?Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        // Every AI feature in this plugin is technician-facing by design; a
        // requester reaching one would be a model talking to a requester.
        if (Session::getCurrentInterface() !== 'central') {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        return null;
    }

    /** As above, plus the assistant being usable in the active entity. */
    private static function requireAssistant(): ?Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        if (!Assistant::available()) {
            return new JSONResponse(['error' => 'unavailable'], 503);
        }

        return null;
    }

    /** The ticket for a draft route, or the response explaining why not. */
    private static function draftableTicket(Request $request): Ticket|Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $request->getAttribute('tickets_id'))) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        // Drafting writes the solution a technician would otherwise write by
        // hand, so it takes exactly that right — per ticket, not per profile.
        if (!$ticket->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        return $ticket;
    }

    /** The ticket for a triage route, readable by this caller. */
    private static function viewableTicket(Request $request): Ticket|Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $request->getAttribute('tickets_id')) || !$ticket->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        return $ticket;
    }

    /** `solution` unless the caller asked for the other one. */
    private static function kind(Request $request): string
    {
        return (string) self::param($request, 'kind', Draft::SOLUTION) === Draft::ARTICLE
            ? Draft::ARTICLE
            : Draft::SOLUTION;
    }

    /**
     * The transcript, rendered on the way out.
     *
     * Markdown becomes HTML here rather than in the app for the same reason it
     * does in the web panel: one renderer, one place for the escaping to be
     * right. The app shows the markdown source when it prefers to style it
     * itself, which is why both are sent.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function messages(int $threads_id): array
    {
        $out = [];
        foreach (Thread::messages($threads_id) as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $out[] = [
                'role'    => $role,
                'content' => (string) ($message['content'] ?? ''),
                'content_html' => $role === 'assistant'
                    ? Markdown::toHtml((string) ($message['content'] ?? ''))
                    : '',
                'trail'   => (string) ($message['trail'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * One conversation in the app's vocabulary.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function threadRow(array $row): array
    {
        return [
            'id'       => (int) ($row['id'] ?? 0),
            'title'    => (string) ($row['title'] ?? ''),
            'itemtype' => (string) ($row['itemtype'] ?? ''),
            'items_id' => (int) ($row['items_id'] ?? 0),
            'date_mod' => (string) ($row['date_mod'] ?? ''),
        ];
    }

    /**
     * One draft, or null when the ticket has none of that kind.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private static function draftRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $content = (string) ($row['content'] ?? '');

        return [
            'id'           => (int) $row['id'],
            'kind'         => (string) $row['kind'],
            'state'        => (string) $row['state'],
            'outcome'      => (string) ($row['outcome'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'confidence'   => (string) ($row['confidence'] ?? ''),
            // Set once an article draft has been filed, so the app can offer to
            // open the article instead of offering to create it twice.
            'knowbaseitems_id' => (int) ($row['knowbaseitems_id'] ?? 0),
            'content'      => $content,
            'content_html' => Markdown::toHtml($content),
            'message'      => (string) ($row['error_message'] ?? ''),
            'date_mod'     => (string) ($row['date_mod'] ?? ''),
        ];
    }

    /**
     * One triage suggestion, with each proposed field resolved to a label and
     * paired with what the ticket says now.
     *
     * A proposal equal to the current value is reported with `matches: true`
     * rather than dropped, so the app can show that triage agreed instead of
     * showing nothing and looking like it never ran.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private static function triageRow(?array $row, Ticket $ticket): ?array
    {
        if ($row === null) {
            return null;
        }

        $fields = [];
        foreach (array_keys(Suggestion::FIELDS) as $field) {
            $value = (int) ($row[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $current = $field === 'plugin_glpisop_sops_id'
                ? 0
                : (int) ($ticket->fields[$field] ?? 0);

            $fields[] = [
                'field'         => $field,
                'value'         => $value,
                'label'         => self::fieldLabel($field, $value),
                'current'       => $current,
                'current_label' => $current > 0 ? self::fieldLabel($field, $current) : '',
                'matches'       => $current > 0 && $current === $value,
                'outcome'       => (string) ($row[Suggestion::FIELDS[$field]] ?? ''),
            ];
        }

        return [
            'id'         => (int) $row['id'],
            'state'      => (string) $row['state'],
            'confidence' => (string) ($row['confidence'] ?? ''),
            'reasoning'  => (string) ($row['reasoning'] ?? ''),
            'message'    => (string) ($row['error_message'] ?? ''),
            'fields'     => $fields,
        ];
    }

    /**
     * What a suggested value is called.
     *
     * Urgency and impact are core's own scale, so their words come from core;
     * the two dropdowns are named by their tables. A procedure whose plugin has
     * since been removed reads as an id rather than as an empty chip.
     */
    private static function fieldLabel(string $field, int $value): string
    {
        return match ($field) {
            'urgency' => \CommonITILObject::getUrgencyName($value),
            'impact'  => \CommonITILObject::getImpactName($value),
            'itilcategories_id' => (string) \Dropdown::getDropdownName('glpi_itilcategories', $value),
            'plugin_glpisop_sops_id' => \Plugin::isPluginActive('glpisop')
                ? (string) \Dropdown::getDropdownName('glpi_plugin_glpisop_sops', $value)
                : (string) $value,
            default => (string) $value,
        };
    }
}
