// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * Settings-page behaviour: the per-provider connection test.
 *
 * The CSRF token travels in the X-Glpi-Csrf-Token header rather than the body.
 * GLPI 11 only accepts the header form when the request also declares itself as
 * XHR via X-Requested-With — fetch(), unlike jQuery, sets nothing of the sort,
 * so without that header the kernel looks for a body token, finds none, and
 * answers with the "Access denied" page instead of running the endpoint.
 */
(function () {
    'use strict';

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        triage();
        drafts();
        replies();

        var root = document.getElementById('glpiai-config-root');
        if (!root) {
            return;
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-glpiai-test]') : null;
            if (button) {
                event.preventDefault();
                test(root, button);
            }
        });
    }

    // --- the triage panel on a ticket form -------------------------------

    /**
     * Apply, dismiss and run-now, for the suggestion panel.
     *
     * One delegated listener on the document rather than listeners per chip:
     * GLPI re-renders parts of the ticket form in place, and handlers bound to
     * elements that get replaced stop working without anything looking broken.
     */
    function triage() {
        document.addEventListener('click', function (event) {
            if (!event.target.closest) {
                return;
            }

            var panel = event.target.closest('[data-glpiai-triage]');
            if (!panel) {
                return;
            }

            var apply = event.target.closest('[data-glpiai-apply]');
            var drop = event.target.closest('[data-glpiai-dismiss]');
            var run = event.target.closest('[data-glpiai-triage-run]');

            if (apply) {
                event.preventDefault();
                send(panel, 'apply', apply.getAttribute('data-glpiai-apply'), apply);
            } else if (drop) {
                event.preventDefault();
                send(panel, 'dismiss', drop.getAttribute('data-glpiai-dismiss'), drop);
            } else if (run) {
                event.preventDefault();
                send(panel, 'run', '', run);
            }
        });
    }

    // --- drafting ---------------------------------------------------------

    /**
     * The drafts tab and the strip inside GLPI's solution editor.
     *
     * Delegated from the document for the same reason as triage: GLPI loads the
     * tab over ajax and re-renders the solution form in place, so anything
     * bound to an element at load time is gone by the time it is clicked.
     */
    function drafts() {
        document.addEventListener('click', function (event) {
            if (!event.target.closest) {
                return;
            }

            var run = event.target.closest('[data-glpiai-draft-run]');
            if (run) {
                event.preventDefault();
                var card = run.closest('[data-glpiai-draft-kind]');
                var box = card.closest('[data-glpiai-draft-ticket]');
                draftPost(card, run, {
                    action: 'draft',
                    tickets_id: box.getAttribute('data-glpiai-draft-ticket'),
                    kind: run.getAttribute('data-glpiai-draft-run')
                });
                return;
            }

            var copy = event.target.closest('[data-glpiai-draft-copy]');
            if (copy) {
                event.preventDefault();
                copyDraft(copy);
                return;
            }

            var article = event.target.closest('[data-glpiai-draft-article]');
            if (article) {
                event.preventDefault();
                var artCard = article.closest('[data-glpiai-draft-kind]');
                draftPost(artCard, article, {
                    action: 'article',
                    id: artCard.getAttribute('data-glpiai-draft-id')
                });
                return;
            }

            var discard = event.target.closest('[data-glpiai-draft-discard]');
            if (discard) {
                event.preventDefault();
                var disCard = discard.closest('[data-glpiai-draft-kind]');
                draftPost(disCard, discard, {
                    action: 'discard',
                    id: disCard.getAttribute('data-glpiai-draft-id')
                });
                return;
            }

            // --- inside the solution editor ---
            var strip = event.target.closest('[data-glpiai-solution-draft]');
            if (!strip) {
                return;
            }

            var solutionRun = event.target.closest('[data-glpiai-solution-run]');
            if (solutionRun) {
                event.preventDefault();
                draftPost(strip, solutionRun, {
                    action: 'draft',
                    tickets_id: strip.getAttribute('data-glpiai-solution-draft'),
                    kind: 'solution'
                }, function (result) {
                    // Straight into the editor rather than reloading: the
                    // technician clicked this while composing, and reloading
                    // the form would close what they were doing.
                    insertSolution(strip, result.content_html || result.content,
                        !!result.content_html);
                    strip.setAttribute('data-glpiai-draft-id', result.id);
                });
                return;
            }

            var insert = event.target.closest('[data-glpiai-solution-insert]');
            if (insert) {
                event.preventDefault();
                // The rendered copy: the editor is a rich-text field, and the
                // model wrote markdown. Falls back to the plain one for a draft
                // stored before rendering existed.
                var tpl = strip.querySelector('[data-glpiai-solution-html]')
                    || strip.querySelector('[data-glpiai-solution-text]');
                insertSolution(strip, tpl ? tpl.innerHTML : '', !!strip.querySelector('[data-glpiai-solution-html]'));
                draftPost(strip, insert, {
                    action: 'used',
                    id: strip.getAttribute('data-glpiai-draft-id')
                });
            }
        });
    }

    /**
     * Put the draft into the solution editor.
     *
     * Into the editor, never into the database: the technician still reads it,
     * still edits it, and still presses Save. TinyMCE is asked first because
     * that is what the field actually is; the textarea is the fallback for a
     * form rendered without the rich editor.
     */
    function insertSolution(strip, content, isHtml) {
        var form = strip.closest('form');
        var field = form ? form.querySelector('textarea[name="content"]') : null;
        if (!field) {
            return;
        }

        // Already HTML when it came from the server's markdown renderer;
        // otherwise plain text whose line breaks are the only structure it has.
        var html = isHtml ? content : decodeEntities(content).replace(/\n/g, '<br>');

        if (window.tinymce) {
            var editor = window.tinymce.get(field.id);
            if (editor) {
                editor.setContent(html);
                editor.fire('change');
                return;
            }
        }

        // No rich editor: put something readable in the textarea rather than
        // tags, since whatever is typed there is stored as written.
        field.value = isHtml ? stripTags(content) : decodeEntities(content);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }


    // --- the reply review, in the followup editor -------------------------

    /**
     * Check this reply, and show what came back.
     *
     * Delegated, like the rest: the timeline re-renders itself after every
     * post, so a listener bound at load time is on a button that no longer
     * exists by the time anybody uses it.
     *
     * Nothing here touches the reply. The result is rendered below the strip
     * and outside the form's own fields, and Save is never disabled — the
     * moment a review can stop a technician answering a customer it stops
     * being a review and becomes an approval step.
     */
    function replies() {
        document.addEventListener('click', function (event) {
            if (!event.target.closest) {
                return;
            }

            var button = event.target.closest('[data-glpiai-reply-check]');
            if (!button) {
                return;
            }

            event.preventDefault();

            var strip = button.closest('[data-glpiai-reply-review]');
            var box = strip.querySelector('[data-glpiai-reply-result]');
            var text = replyText(strip);

            if (!text) {
                box.innerHTML = '';
                box.textContent = button.getAttribute('data-empty')
                    || 'Write the reply first.';
                box.hidden = false;
                return;
            }

            var body = new URLSearchParams();
            body.set('action', 'review');
            body.set('itemtype', strip.getAttribute('data-glpiai-reply-itemtype'));
            body.set('items_id', strip.getAttribute('data-glpiai-reply-items-id'));
            body.set('text', text);

            button.disabled = true;
            strip.classList.add('is-busy');

            fetch(strip.getAttribute('data-glpiai-reply-endpoint'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': strip.getAttribute('data-glpiai-reply-csrf')
                },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, message: 'HTTP ' + response.status };
                    });
                })
                .then(function (result) {
                    strip.classList.remove('is-busy');
                    button.disabled = false;

                    box.hidden = false;
                    if (!result.ok) {
                        box.textContent = result.message || result.error || 'failed';
                        return;
                    }

                    box.innerHTML = result.html;
                })
                .catch(function (error) {
                    strip.classList.remove('is-busy');
                    button.disabled = false;
                    box.hidden = false;
                    box.textContent = String(error);
                });
        });
    }

    /**
     * What the technician has actually written, wherever it lives.
     *
     * TinyMCE first, because the editor holds the current value and the
     * textarea behind it is only synchronised on submit — reading the textarea
     * on its own returns the reply as it was when the form was drawn, which is
     * usually empty and always stale.
     */
    function replyText(strip) {
        var form = strip.closest('form');
        var field = form ? form.querySelector('textarea[name="content"]') : null;
        if (!field) {
            return '';
        }

        if (window.tinymce) {
            var editor = window.tinymce.get(field.id);
            if (editor) {
                return editor.getContent().trim();
            }
        }

        return (field.value || '').trim();
    }

    function stripTags(html) {
        var box = document.createElement('div');
        box.innerHTML = html;
        return (box.textContent || '').trim();
    }

    function decodeEntities(value) {
        var box = document.createElement('textarea');
        box.innerHTML = value;
        return box.value;
    }

    function copyDraft(button) {
        var card = button.closest('[data-glpiai-draft-kind]');
        var body = card.querySelector('[data-glpiai-draft-text]');
        if (!body || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(body.innerText).then(function () {
            var was = button.innerHTML;
            button.innerHTML = '<i class="ti ti-check me-1"></i>' + (button.dataset.copied || 'Copied');
            window.setTimeout(function () { button.innerHTML = was; }, 1500);
        });
    }

    function draftPost(container, button, payload, onDone) {
        var root = container.querySelector('.glpiai-draft-root')
            || document.querySelector('.glpiai-draft-root');
        var box = container.querySelector('[data-glpiai-draft-error]');
        if (!root) {
            return;
        }

        var body = new URLSearchParams();
        Object.keys(payload).forEach(function (key) { body.set(key, payload[key]); });

        button.disabled = true;
        container.classList.add('is-busy');

        fetch(root.getAttribute('data-glpiai-draft-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': root.getAttribute('data-glpiai-draft-csrf')
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'HTTP ' + response.status };
                });
            })
            .then(function (result) {
                container.classList.remove('is-busy');
                button.disabled = false;

                if (!result.ok) {
                    if (box) {
                        box.textContent = result.message || result.error || 'failed';
                        box.hidden = false;
                    }
                    return;
                }

                if (box) {
                    box.hidden = true;
                }

                if (onDone) {
                    onDone(result);
                    return;
                }

                if (result.reload) {
                    window.location.reload();
                }
            })
            .catch(function (error) {
                container.classList.remove('is-busy');
                button.disabled = false;
                if (box) {
                    box.textContent = String(error);
                    box.hidden = false;
                }
            });
    }

    function send(panel, action, field, button) {
        var root = panel.querySelector('.glpiai-triage-root');
        var box = panel.querySelector('[data-glpiai-triage-error]');
        if (!root) {
            return;
        }

        var body = new URLSearchParams();
        body.set('id', panel.getAttribute('data-glpiai-triage'));
        body.set('action', action);
        body.set('field', field || '');

        button.disabled = true;
        panel.classList.add('is-busy');

        fetch(root.getAttribute('data-glpiai-triage-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': root.getAttribute('data-glpiai-triage-csrf')
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'HTTP ' + response.status };
                });
            })
            .then(function (result) {
                panel.classList.remove('is-busy');
                button.disabled = false;

                if (!result.ok) {
                    if (box) {
                        box.textContent = result.message || result.error || 'failed';
                        box.hidden = false;
                    }
                    return;
                }

                // Applying changes the ticket, so the form has to be re-read;
                // dismissing changes nothing anybody can see except the chip,
                // which is cheaper to remove than to reload the page for.
                if (result.reload) {
                    window.location.reload();
                    return;
                }

                var chip = panel.querySelector('[data-glpiai-chip="' + field + '"]');
                if (chip) {
                    chip.remove();
                }
                if (!panel.querySelector('[data-glpiai-chip]')) {
                    panel.remove();
                }
            })
            .catch(function (error) {
                panel.classList.remove('is-busy');
                button.disabled = false;
                if (box) {
                    box.textContent = String(error);
                    box.hidden = false;
                }
            });
    }

    function test(root, button) {
        var id = button.getAttribute('data-glpiai-test');
        var box = document.querySelector('[data-glpiai-result="' + id + '"]');
        if (!box) {
            return;
        }

        button.disabled = true;
        box.hidden = false;
        box.className = 'glpiai-test-result alert alert-secondary py-2 mt-2';
        box.textContent = root.getAttribute('data-testing') || 'Testing…';

        var body = new URLSearchParams();
        body.append('provider', id);

        fetch(root.getAttribute('data-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': root.getAttribute('data-csrf')
            },
            body: body
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'The test endpoint returned an unreadable response.' };
                });
            })
            .then(function (data) {
                render(box, data);
            })
            .catch(function () {
                render(box, { ok: false, message: 'The test request could not be sent.' });
            })
            .then(function () {
                button.disabled = false;
            });
    }

    function render(box, data) {
        box.className = 'glpiai-test-result alert py-2 mt-2 ' + (data.ok ? 'alert-success' : 'alert-danger');

        // textContent throughout: this renders a third party's error string,
        // which is not ours to trust as markup.
        box.textContent = '';

        var line = document.createElement('div');
        line.textContent = data.message || (data.ok ? 'Connected.' : 'Failed.');
        box.appendChild(line);

        if (!data.ok && data.hint && data.hint !== data.message) {
            var hint = document.createElement('div');
            hint.className = 'small mt-1';
            hint.textContent = data.hint;
            box.appendChild(hint);
        }
    }
})();
