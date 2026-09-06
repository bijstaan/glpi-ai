// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * HEIMDALL, the troubleshooting assistant, as a slide-over panel.
 *
 * Built entirely in JavaScript and injected on every central page, the same way
 * glpi-palette builds its overlay: GLPI has no hook that renders markup into
 * every page, and adding one PHP-rendered panel per page would put a database
 * read in front of every request for a panel most of them never open. Nothing
 * is fetched until the panel is opened for the first time.
 *
 * The CSRF token comes from GLPI's own <meta property="glpi:csrf_token">, and
 * travels in the X-Glpi-Csrf-Token header. GLPI 11 only honours the header form
 * when the request also declares itself as XHR, which fetch() does not do on
 * its own — without X-Requested-With the kernel looks for a body token, finds
 * none, and answers with the access-denied page.
 */
(function () {
    'use strict';

    var ROOT = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    var ENDPOINT = ROOT + '/plugins/glpiai/ajax/assistant.php';
    var STREAM   = ROOT + '/plugins/glpiai/ajax/assistant-stream.php';

    var els = null;
    var thread = 0;
    var opened = false;
    var busy = false;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // Central interface only, taken from the class GLPI puts on <body>:
        // 'central' or 'helpdesk'. Both interfaces have a navbar and a #page,
        // so testing for those looks like an interface check and is not one —
        // it renders the launcher on a requester's portal, where the endpoint
        // then refuses it. The endpoint refusing is the real protection; this
        // is what stops there being a button that does nothing.
        if (!document.body.classList.contains('central')) {
            return;
        }

        build();
        bindLauncher();
    }

    function csrf() {
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function post(payload) {
        var body = new URLSearchParams();
        Object.keys(payload).forEach(function (key) { body.set(key, payload[key]); });

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: 'HTTP ' + response.status };
            });
        });
    }

    // --- what the technician has open -------------------------------------

    /**
     * The record this page is about, read from the URL.
     *
     * Guessed here and verified server-side. GLPI's form URLs are uniform
     * enough that this is reliable — `front/ticket.form.php?id=42` — and a
     * wrong guess costs nothing, because Context::item() checks the type, loads
     * the record and applies rights before any of it reaches a prompt.
     */
    function pageContext() {
        var match = window.location.pathname.match(/\/front\/([a-z]+)\.form\.php$/i);
        var id = new URLSearchParams(window.location.search).get('id');

        if (!match || !id || !/^\d+$/.test(id)) {
            return { itemtype: '', items_id: 0 };
        }

        var name = match[1].toLowerCase();
        var known = {
            ticket: 'Ticket', change: 'Change', problem: 'Problem',
            computer: 'Computer', monitor: 'Monitor', printer: 'Printer',
            networkequipment: 'NetworkEquipment', phone: 'Phone',
            peripheral: 'Peripheral', software: 'Software', user: 'User',
            knowbaseitem: 'KnowbaseItem'
        };

        return { itemtype: known[name] || '', items_id: known[name] ? parseInt(id, 10) : 0 };
    }

    // --- DOM ---------------------------------------------------------------

    function build() {
        var panel = document.createElement('aside');
        panel.className = 'glpiai-assistant';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'HEIMDALL — troubleshooting assistant');
        panel.hidden = true;

        panel.innerHTML =
            '<header class="glpiai-assistant-head">'
            + '<i class="ti ti-message-2-bolt me-1"></i>'
            + '<strong>HEIMDALL</strong>'
            + '<span class="glpiai-assistant-context"></span>'
            + '<button type="button" class="glpiai-assistant-history-toggle" '
            + 'title="Past conversations" aria-expanded="false">'
            + '<i class="ti ti-history"></i></button>'
            + '<button type="button" class="glpiai-assistant-steps-mode" '
            + 'title="Show every step, or only the latest">'
            + '<i class="ti ti-list-details"></i></button>'
            + '<button type="button" class="glpiai-assistant-clear" title="Start over">'
            + '<i class="ti ti-eraser"></i></button>'
            + '<button type="button" class="glpiai-assistant-close" title="Close">'
            + '<i class="ti ti-x"></i></button>'
            + '</header>'
            + '<div class="glpiai-assistant-log" aria-live="polite"></div>'
            + '<div class="glpiai-assistant-history" hidden '
            + 'aria-label="Past conversations"></div>'
            + '<form class="glpiai-assistant-form">'
            + '<textarea class="glpiai-assistant-input" rows="2" '
            + 'placeholder="What is going on?" aria-label="Question"></textarea>'
            + '<button type="submit" class="glpiai-assistant-send">'
            + '<i class="ti ti-send"></i></button>'
            + '</form>';

        document.body.appendChild(panel);

        els = {
            panel: panel,
            context: panel.querySelector('.glpiai-assistant-context'),
            log: panel.querySelector('.glpiai-assistant-log'),
            history: panel.querySelector('.glpiai-assistant-history'),
            historyToggle: panel.querySelector('.glpiai-assistant-history-toggle'),
            form: panel.querySelector('.glpiai-assistant-form'),
            input: panel.querySelector('.glpiai-assistant-input'),
            send: panel.querySelector('.glpiai-assistant-send')
        };

        panel.querySelector('.glpiai-assistant-close').addEventListener('click', close);
        els.historyToggle.addEventListener('click', function () {
            if (els.history.hidden) {
                showHistory();
            } else {
                hideHistory();
            }
        });
        panel.querySelector('.glpiai-assistant-clear').addEventListener('click', clear);
        panel.querySelector('.glpiai-assistant-steps-mode').addEventListener('click', function () {
            setStepsMode(stepsMode() === 'all' ? 'latest' : 'all');
        });

        applyStepsMode();

        els.form.addEventListener('submit', function (event) {
            event.preventDefault();
            ask();
        });

        // Enter sends, Shift+Enter breaks the line. A troubleshooting question
        // is usually one line, and reaching for a button every time is the sort
        // of friction that stops people asking.
        els.input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                ask();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || els.panel.hidden) {
                return;
            }
            // Innermost first: Escape out of the list, then out of the panel.
            if (!els.history.hidden) {
                hideHistory();
                return;
            }
            close();
        });
    }

    /**
     * Put the launcher in the header, beside the search box.
     *
     * Not floating in a corner, which is where this started and which was
     * wrong: the bottom-right of a GLPI form is where the Save button lives, so
     * a fixed button there covers the single control on the page people press
     * most. The header is the row that already holds the other things you reach
     * for regardless of what is on screen.
     *
     * Falls back to the corner only if the header is not where it is expected —
     * a GLPI that reorganises its chrome should cost the button its position,
     * not its existence.
     */
    function bindLauncher() {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'glpiai-assistant-launch';
        button.setAttribute('aria-label', 'HEIMDALL — troubleshooting assistant');
        button.setAttribute('title', 'HEIMDALL — troubleshooting assistant');
        button.innerHTML = '<i class="ti ti-message-2-bolt"></i>';
        button.addEventListener('click', open);

        var search = document.getElementById('global-search');
        var header = document.querySelector('.header-container');

        // After the *wrapper* the search sits in, not after the input: that
        // wrapper is display-none on narrow screens, and a button inside it
        // would vanish with it.
        var anchor = null;
        if (search && header) {
            anchor = search;
            while (anchor && anchor.parentElement !== header) {
                anchor = anchor.parentElement;
            }
        }

        if (anchor) {
            anchor.insertAdjacentElement('afterend', button);
            return;
        }

        if (header) {
            header.appendChild(button);
            return;
        }

        button.classList.add('glpiai-assistant-launch--floating');
        document.body.appendChild(button);
    }

    // --- behaviour ---------------------------------------------------------

    function open() {
        els.panel.hidden = false;
        document.body.classList.add('glpiai-assistant-open');
        els.input.focus();

        if (opened) {
            return;
        }
        opened = true;

        var context = pageContext();

        post({ action: 'open', itemtype: context.itemtype, items_id: context.items_id })
            .then(function (result) {
                if (!result.ok) {
                    note(result.message || 'The assistant is not available.', 'error');
                    return;
                }

                thread = result.thread;
                els.context.textContent = result.context || '';

                // Services waiting for this person's own account. A link
                // rather than a nag: it is the only place the panel can say it,
                // and the alternative is finding out when a tool refuses.
                if (result.connections && result.connections.waiting) {
                    var hint = note('', 'hint');
                    var link = document.createElement('a');
                    link.href = result.connections.url;
                    link.textContent = result.connections.waiting === 1
                        ? '1 service is waiting for you to connect your account'
                        : result.connections.waiting
                            + ' services are waiting for you to connect your account';
                    hint.appendChild(link);
                }

                (result.messages || []).forEach(function (message) {
                    render(message.role, message.content, message.trail, null,
                        message.content_html);
                });

                if (!(result.messages || []).length) {
                    note(result.context
                        ? 'Ask about ' + result.context + ', or anything else.'
                        : 'Ask a question. I can look at tickets, assets and endpoints.', 'hint');
                }
            });
    }

    function close() {
        els.panel.hidden = true;
        document.body.classList.remove('glpiai-assistant-open');
    }

    // --- past conversations ------------------------------------------------

    /**
     * The list of what has been asked before.
     *
     * The panel resumes by *context* — open it on a ticket and you are back in
     * that ticket's conversation — which is right for the case it was built
     * for and no help at all for "what did it say about that switch on
     * Tuesday". Until this there was no way back to a thread whose page you
     * were no longer on.
     *
     * Fetched every time it is opened rather than cached: a conversation the
     * technician had in another tab five minutes ago is exactly what they are
     * looking for, and a stale list is worse than a slow one.
     */
    function showHistory() {
        els.history.hidden = false;
        els.historyToggle.setAttribute('aria-expanded', 'true');
        els.panel.classList.add('glpiai-assistant--history');
        els.history.textContent = '';

        var loading = document.createElement('p');
        loading.className = 'glpiai-assistant-history-empty';
        loading.textContent = 'Looking…';
        els.history.appendChild(loading);

        post({ action: 'history' }).then(function (result) {
            // The list may have been closed again while the request was out.
            if (els.history.hidden) {
                return;
            }

            els.history.textContent = '';

            if (!result.ok) {
                els.history.appendChild(
                    emptyLine(result.message || 'Could not read your conversations.')
                );
                return;
            }

            var threads = result.threads || [];
            if (!threads.length) {
                els.history.appendChild(
                    emptyLine('Nothing yet. Ask something, and it will be here afterwards.')
                );
                return;
            }

            var list = document.createElement('ul');
            list.className = 'glpiai-assistant-history-list';
            threads.forEach(function (row) {
                list.appendChild(historyRow(row));
            });
            els.history.appendChild(list);
        });
    }

    function hideHistory() {
        els.history.hidden = true;
        els.historyToggle.setAttribute('aria-expanded', 'false');
        els.panel.classList.remove('glpiai-assistant--history');
        els.input.focus();
    }

    function emptyLine(text) {
        var line = document.createElement('p');
        line.className = 'glpiai-assistant-history-empty';
        line.textContent = text;
        return line;
    }

    /**
     * One row: what was asked, what it was about, and when.
     *
     * A button rather than a link — nothing navigates, the panel swaps its
     * transcript in place — and built with textContent throughout, because a
     * title is the technician's own first question and goes nowhere near
     * innerHTML.
     */
    function historyRow(row) {
        var item = document.createElement('li');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'glpiai-assistant-history-row';
        if (row.id === thread) {
            button.classList.add('is-current');
            button.setAttribute('aria-current', 'true');
        }

        var title = document.createElement('span');
        title.className = 'glpiai-assistant-history-title';
        title.textContent = row.title || 'Untitled conversation';
        button.appendChild(title);

        var meta = document.createElement('span');
        meta.className = 'glpiai-assistant-history-meta';
        meta.textContent = [row.context, row.when].filter(Boolean).join(' · ');
        button.appendChild(meta);

        button.addEventListener('click', function () {
            resume(row.id);
        });

        item.appendChild(button);
        return item;
    }

    /**
     * Reopen a conversation in place.
     *
     * Refused while a question is running: the answer being streamed belongs to
     * the thread on screen, and swapping the transcript under it would file it
     * against the wrong conversation as far as the reader is concerned.
     */
    function resume(id) {
        if (busy || id === thread) {
            hideHistory();
            return;
        }

        post({ action: 'resume', thread: id }).then(function (result) {
            if (!result.ok) {
                note(result.message || 'That conversation could not be opened.', 'error');
                hideHistory();
                return;
            }

            thread = result.thread;
            els.context.textContent = result.context || '';
            els.log.textContent = '';

            (result.messages || []).forEach(function (message) {
                render(message.role, message.content, message.trail, null,
                    message.content_html);
            });

            hideHistory();
            scroll();
        });
    }

    function clear() {
        if (!thread) {
            return;
        }

        post({ action: 'clear', thread: thread }).then(function () {
            els.log.textContent = '';
            note('Started over.', 'hint');
        });
    }

    function ask() {
        var question = els.input.value.trim();
        if (!question || busy || !thread) {
            return;
        }

        busy = true;
        els.input.value = '';
        els.send.disabled = true;
        render('user', question);

        // The run's own area, built before the first byte comes back. Even an
        // empty one with a pulsing line in it says "something is happening",
        // which is the whole complaint this replaces.
        var live = liveArea();

        stream(question, live)
            .catch(function (error) {
                live.fail(String(error));
            })
            .then(function () {
                busy = false;
                els.send.disabled = false;
                els.input.focus();
            });
    }

    /**
     * Ask over server-sent events, falling back to the plain endpoint.
     *
     * fetch() with a reader rather than EventSource: EventSource is GET-only,
     * and this needs a POST with the CSRF header — GLPI 11 refuses the token in
     * a query string, correctly. The frames are parsed by hand, which is a
     * dozen lines because the format is a dozen lines.
     */
    function stream(question, live) {
        var body = new URLSearchParams();
        body.set('thread', thread);
        body.set('question', question);

        return fetch(STREAM, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok || !response.body || !window.TextDecoder) {
                // An old browser, a proxy that will not stream, or a refusal
                // before the stream opened. The JSON endpoint answers the same
                // question and the technician gets the answer without the
                // narration, which is what they had before this existed.
                return fallback(question, live);
            }

            var reader  = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer  = '';

            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        live.finish();
                        return;
                    }

                    buffer += decoder.decode(chunk.value, { stream: true });

                    // Events are separated by a blank line; a partial one stays
                    // in the buffer until the rest of it arrives, because half
                    // a JSON payload parses as nothing.
                    var split = buffer.split('\n\n');
                    buffer = split.pop();

                    split.forEach(function (frame) {
                        var name = '';
                        var data = '';

                        frame.split('\n').forEach(function (line) {
                            if (line.indexOf('event:') === 0) {
                                name = line.slice(6).trim();
                            } else if (line.indexOf('data:') === 0) {
                                data += line.slice(5).trim();
                            }
                        });

                        if (!name) {
                            return;
                        }

                        var payload = {};
                        try {
                            payload = data ? JSON.parse(data) : {};
                        } catch (e) {
                            return;
                        }

                        live.event(name, payload);
                    });

                    return pump();
                });
            }

            return pump();
        });
    }

    /** The old path: one request, one answer, no narration. */
    function fallback(question, live) {
        return post({ action: 'ask', thread: thread, question: question })
            .then(function (result) {
                if (!result.ok) {
                    live.fail(result.message || 'That did not work.');
                    return;
                }

                live.event('done', result);
                live.finish();
            });
    }

    // --- how much of the working to show -----------------------------------

    /**
     * Two ways to watch a run, and which one is a matter of taste.
     *
     * `all` keeps every tool call on screen with its arguments and its result:
     * the whole working, which is what makes an answer checkable and what this
     * plugin's other features are built around showing.
     *
     * `latest` keeps only the step that is running and folds the finished ones
     * into a line that says how many there were — closer to how the vendors'
     * own chat interfaces behave, and the right default for somebody who wants
     * the answer rather than the audit.
     *
     * Kept in localStorage rather than in the database. It is a preference
     * about one person's screen, it changes nothing anybody else sees, and a
     * round trip to store it would be a round trip to store which way somebody
     * likes their sidebar. A browser with storage disabled gets the default and
     * a toggle that works for the length of the session.
     */
    var STEPS_KEY = 'glpiai-assistant-steps';

    function stepsMode() {
        try {
            return window.localStorage.getItem(STEPS_KEY) === 'all' ? 'all' : 'latest';
        } catch (e) {
            return memoryStepsMode;
        }
    }

    var memoryStepsMode = 'latest';

    function setStepsMode(mode) {
        memoryStepsMode = mode;

        try {
            window.localStorage.setItem(STEPS_KEY, mode);
        } catch (e) {
            // Private browsing, or storage switched off. The toggle still works
            // for this session, which is the part that matters while somebody
            // is looking at it.
        }

        applyStepsMode();
    }

    function applyStepsMode() {
        if (!els.panel) {
            return;
        }

        var compact = stepsMode() !== 'all';

        // One class on the panel, and CSS decides what is visible. Switching
        // mode mid-run therefore reflows what is already on screen rather than
        // applying only to the next question.
        els.panel.classList.toggle('is-compact', compact);

        var button = els.panel.querySelector('.glpiai-assistant-steps-mode');
        button.setAttribute('aria-pressed', compact ? 'false' : 'true');
        button.title = compact
            ? 'Showing the latest step only — click to show every step'
            : 'Showing every step — click to show only the latest';
        button.innerHTML = compact
            ? '<i class="ti ti-list-details"></i>'
            : '<i class="ti ti-list"></i>';
    }

    // --- the live run ------------------------------------------------------

    /**
     * The area one question's run writes into while it happens.
     *
     * Three parts, in the order they earn their place: a status line that
     * always says what the run is doing, an activity list of tool calls with
     * their arguments and what came back, and the answer, which grows a word at
     * a time and is replaced by the server-rendered markdown at the end.
     *
     * Everything here is `textContent` except that final swap. The tool
     * arguments and results are model output and remote data — a MAC address
     * table, a ticket title somebody typed — and the one place HTML is allowed
     * is the answer, which the server has already rendered and sanitised
     * through the same CommonMark configuration every other feature uses.
     */
    function liveArea() {
        var turn = document.createElement('div');
        turn.className = 'glpiai-assistant-turn glpiai-assistant-turn--assistant';

        var status = document.createElement('div');
        status.className = 'glpiai-assistant-status';
        status.innerHTML = '<span class="glpiai-assistant-pulse"></span>'
            + '<span class="glpiai-assistant-status-text">Thinking…</span>';

        var steps = document.createElement('div');
        steps.className = 'glpiai-assistant-steps';

        // The line that stands in for the hidden steps in compact mode. It is
        // a button rather than a caption because it opens them: the working is
        // one click away rather than gone, which is the difference between a
        // quieter panel and a panel that hides its evidence.
        var summary = document.createElement('button');
        summary.type = 'button';
        summary.className = 'glpiai-assistant-steps-summary';
        summary.hidden = true;
        summary.addEventListener('click', function () {
            steps.classList.toggle('is-open');
            countSteps(steps, summary);
        });

        var thinking = document.createElement('details');
        thinking.className = 'glpiai-assistant-thinking';
        thinking.hidden = true;
        thinking.innerHTML = '<summary>Thinking</summary><div class="glpiai-assistant-thinking-text"></div>';

        var body = document.createElement('div');
        body.className = 'glpiai-assistant-text glpiai-assistant-streaming';

        turn.appendChild(status);
        turn.appendChild(summary);
        turn.appendChild(steps);
        turn.appendChild(thinking);
        turn.appendChild(body);
        els.log.appendChild(turn);
        scroll();

        var thinkingText = thinking.querySelector('.glpiai-assistant-thinking-text');
        var statusText   = status.querySelector('.glpiai-assistant-status-text');
        var answered     = false;

        function say(text) {
            statusText.textContent = text;
        }

        return {
            event: function (name, data) {
                switch (name) {
                    case 'turn':
                        // Only from the second turn on. "Step 1 of 12" on the
                        // first one reads as a progress bar with eleven steps
                        // left, when most questions are answered in one.
                        if (data.turn > 1) {
                            say('Step ' + data.turn + ' of ' + data.budget + '…');
                        }
                        break;

                    case 'tool':
                        say('Looking: ' + data.name);

                        // Whatever the model said before reaching for the tool
                        // — "let me check that" — moves out of the answer area
                        // and above the step it introduced. Left where it is,
                        // it runs straight into the next turn's prose and reads
                        // as one confused paragraph; thrown away, the panel
                        // looks like it lost something it had already shown.
                        if (body.textContent.trim()) {
                            var aside = document.createElement('div');
                            aside.className = 'glpiai-assistant-aside';
                            aside.textContent = body.textContent.trim();
                            steps.parentNode.insertBefore(aside, steps);
                            body.textContent = '';
                        }

                        // Everything before this one becomes past, which is
                        // what compact mode hides. Marked here rather than
                        // derived in CSS because "the latest" is a fact about
                        // order that CSS cannot see once results arrive out of
                        // sequence.
                        Array.prototype.forEach.call(
                            steps.querySelectorAll('.glpiai-assistant-step'),
                            function (step) { step.classList.add('is-past'); }
                        );

                        addStep(steps, data);
                        countSteps(steps, summary);
                        break;

                    case 'tool_result':
                        completeStep(steps, data);
                        countSteps(steps, summary);
                        say('Thinking…');
                        break;

                    case 'continued':
                        // Worth saying out loud: the pause here is the model
                        // being asked to carry on after hitting the output
                        // ceiling, not the model thinking.
                        say('Finishing the answer…');
                        break;

                    case 'thinking':
                        thinking.hidden = false;
                        // Open while it is being written, in full mode: the
                        // point of showing reasoning is watching it arrive. In
                        // compact mode it stays shut and is there to open.
                        if (stepsMode() === 'all') {
                            thinking.open = true;
                        }
                        thinkingText.textContent += data.text || '';
                        scroll();
                        break;

                    case 'text':
                        answered = true;
                        body.textContent += data.text || '';
                        scroll();
                        break;

                    case 'done':
                        answered = true;
                        status.remove();

                        Array.prototype.forEach.call(
                            steps.querySelectorAll('.glpiai-assistant-step'),
                            function (step) { step.classList.add('is-past'); }
                        );
                        countSteps(steps, summary);

                        // Thinking is worth watching while it happens and worth
                        // folding away once there is an answer to read.
                        thinking.open = false;
                        // The rendered markdown replaces the plain text that
                        // was streaming into place. Same words, finally with
                        // its lists and its code blocks.
                        if (data.answer_html) {
                            body.classList.add('glpiai-assistant-md');
                            body.innerHTML = data.answer_html;
                        } else if (data.answer) {
                            body.textContent = data.answer;
                        }
                        body.classList.remove('glpiai-assistant-streaming');

                        if (data.exhausted) {
                            note('I ran out of steps before I was finished — ask again to keep '
                                + 'going.', 'hint');
                        }

                        // An answer that is still short after the loop tried to
                        // continue it. Said plainly, because a truncated answer
                        // reads exactly like a finished one.
                        if (data.truncated) {
                            note('That answer hit the length ceiling and stops short. Raise '
                                + '"Answer length ceiling" in the AI settings, or ask me to carry '
                                + 'on.', 'hint');
                        }

                        scroll();
                        break;

                    case 'failed':
                        this.fail(data.message || 'That did not work.');
                        break;
                }
            },

            fail: function (message) {
                status.remove();
                body.classList.remove('glpiai-assistant-streaming');
                if (!answered) {
                    body.remove();
                }
                note(message, 'error');
            },

            finish: function () {
                if (status.parentNode) {
                    status.remove();
                }
                body.classList.remove('glpiai-assistant-streaming');
                // A run that ended without a word — the stream cut, or a
                // provider that returned nothing — must not leave an empty
                // bubble that looks like an answer.
                if (!answered && !body.textContent.trim()) {
                    body.remove();
                }
            }
        };
    }

    /**
     * Keep the "N earlier steps" line honest.
     *
     * Hidden entirely while there is nothing behind it — one tool call needs no
     * summary of itself — and it reports what it is hiding rather than what
     * exists, because "3 earlier steps" is only true if three of them are out
     * of sight.
     */
    function countSteps(steps, summary) {
        var past = steps.querySelectorAll('.glpiai-assistant-step.is-past').length;

        if (past === 0) {
            summary.hidden = true;
            return;
        }

        summary.hidden = false;
        summary.textContent = steps.classList.contains('is-open')
            ? 'Hide ' + past + (past === 1 ? ' earlier step' : ' earlier steps')
            : past + (past === 1 ? ' earlier step' : ' earlier steps');
    }

    /** One tool call, from the moment it starts. */
    function addStep(steps, data) {
        var step = document.createElement('div');
        step.className = 'glpiai-assistant-step is-running';
        step.setAttribute('data-tool', data.name);

        var head = document.createElement('div');
        head.className = 'glpiai-assistant-step-head';

        var name = document.createElement('span');
        name.className = 'glpiai-assistant-step-name';
        name.textContent = data.name;

        var args = document.createElement('span');
        args.className = 'glpiai-assistant-step-args';
        args.textContent = summarise(data.arguments);

        var time = document.createElement('span');
        time.className = 'glpiai-assistant-step-time';
        time.textContent = '…';

        head.appendChild(name);
        head.appendChild(args);
        head.appendChild(time);
        step.appendChild(head);
        steps.appendChild(step);
        scroll();
    }

    /** The same call, once it has answered. */
    function completeStep(steps, data) {
        var running = steps.querySelector('.glpiai-assistant-step.is-running[data-tool="'
            + cssEscape(data.name) + '"]');

        if (!running) {
            return;
        }

        running.classList.remove('is-running');
        if (data.error) {
            running.classList.add('is-error');
        }

        running.querySelector('.glpiai-assistant-step-time').textContent =
            data.duration_ms >= 1000
                ? (data.duration_ms / 1000).toFixed(1) + 's'
                : data.duration_ms + 'ms';

        // The result behind a disclosure rather than on the page: it is the
        // evidence, wanted when an answer looks wrong and noise the rest of the
        // time. Collapsed, but there.
        if (data.result) {
            var detail = document.createElement('details');
            detail.className = 'glpiai-assistant-step-result';

            var summary = document.createElement('summary');
            summary.textContent = data.error ? 'What went wrong' : 'What came back';

            var pre = document.createElement('pre');
            pre.textContent = data.result + (data.truncated ? '\n…' : '');

            detail.appendChild(summary);
            detail.appendChild(pre);
            running.appendChild(detail);
        }

        scroll();
    }

    /**
     * A tool's arguments, in the width of a line.
     *
     * The values, not the keys: `read_ticket {id: 4412}` says less than
     * `read_ticket 4412`, and what a technician is checking is whether the
     * model looked up the right thing.
     */
    function summarise(args) {
        if (!args || typeof args !== 'object') {
            return '';
        }

        var parts = Object.keys(args).map(function (key) {
            var value = args[key];

            if (value === null || value === undefined) {
                return '';
            }
            if (typeof value === 'object') {
                value = JSON.stringify(value);
            }

            value = String(value);

            return value.length > 60 ? value.slice(0, 59) + '…' : value;
        }).filter(Boolean);

        return parts.join(' · ');
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function scroll() {
        els.log.scrollTop = els.log.scrollHeight;
    }

    // --- rendering ---------------------------------------------------------

    function render(role, content, trail, tools, html) {
        var turn = document.createElement('div');
        turn.className = 'glpiai-assistant-turn glpiai-assistant-turn--' + role;

        var body = document.createElement('div');
        body.className = 'glpiai-assistant-text';

        // Models write markdown whether or not they are asked to, so an
        // assistant turn arrives already rendered from the server — by the same
        // CommonMark configuration every other feature uses, with raw HTML
        // escaped and unsafe links dropped. The technician's own words are
        // never rendered: they typed them, they are not a document, and running
        // them through a parser would only ever surprise somebody who typed an
        // asterisk.
        if (html) {
            body.classList.add('glpiai-assistant-md');
            body.innerHTML = html;
        } else {
            body.textContent = content;
        }

        turn.appendChild(body);

        // What it reached for, shown rather than hidden. An answer from looking
        // at the machine and an answer from general knowledge read identically,
        // and the difference decides whether to believe it.
        var used = (tools || []).length ? tools : null;
        if (used || trail) {
            var meta = document.createElement('div');
            meta.className = 'glpiai-assistant-tools';

            if (used) {
                used.forEach(function (tool) {
                    var chip = document.createElement('span');
                    chip.className = 'glpiai-assistant-tool'
                        + (tool.error ? ' glpiai-assistant-tool--error' : '');
                    chip.textContent = tool.name;
                    chip.title = tool.args || '';
                    meta.appendChild(chip);
                });
            } else if (trail) {
                meta.textContent = trail;
            }

            turn.appendChild(meta);
        }

        els.log.appendChild(turn);
        scroll();

        return turn;
    }

    function note(text, kind) {
        var line = document.createElement('div');
        line.className = 'glpiai-assistant-note glpiai-assistant-note--' + kind;
        line.textContent = text;
        els.log.appendChild(line);
        scroll();

        return line;
    }
}());
