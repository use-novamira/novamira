// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

(() => {
    const root = document.getElementById('novamira-journal');
    const heldRoot = document.getElementById('novamira-held-emails');
    if ((!root && !heldRoot) || !window.novamiraJournal) return;
    const { path, strings, me } = window.novamiraJournal;
    // The same person is always named the same way: "you" for whoever is reading.
    const who = (name) => (name && name === me ? strings.you : name);

    const STATE_TONE = { completed: 'is-ok', running: 'is-run', halted: 'is-bad', failed: 'is-bad', interrupted: 'is-bad' };

    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    function button(label, onClick, className) {
        const node = element('button', label, className || 'button');
        node.type = 'button';
        node.addEventListener('click', onClick);
        return node;
    }

    function time(iso) {
        return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function dayLabel(iso) {
        const date = new Date(iso);
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        if (date.toDateString() === today.toDateString()) return strings.today;
        if (date.toDateString() === yesterday.toDateString()) return strings.yesterday;
        return date.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    }

    // The plugin cannot write into the AI's chat, so this only prepares the message. It holds the operation's codes and
    // nothing from the site: a title could otherwise put words in the person's mouth.
    function copyButton(operation) {
        const box = element('div', '', 'nm-op-copy');
        const text = strings.prompt.replace('%1$s', operation.execution_id).replace('%2$s', operation.group_id);
        const note = element('span', '', 'nm-ok');
        note.setAttribute('aria-live', 'polite');
        const shown = element('div', '', 'nm-copied');
        shown.hidden = true;
        const area = element('textarea');
        area.readOnly = true;
        area.value = text;
        area.addEventListener('focus', () => area.select());
        shown.append(area);
        box.append(button(strings.copyPrompt, async () => {
            try {
                await navigator.clipboard.writeText(text);
                note.textContent = `✓ ${strings.copied}`;
            } catch (error) {
                // Browsers allow the clipboard only on HTTPS: the text is shown to copy by hand.
                note.textContent = strings.copyByHand;
                shown.hidden = false;
                area.focus();
            }
        }, 'button button-small'), note, shown);
        return box;
    }

    function renderHeldEmail(mail) {
        const card = element('section', '', 'nm-card is-pending');
        const head = element('div', '', 'nm-head');
        head.append(element('span', strings.onHold, 'nm-pill is-wait'), element('span', `${strings.to}: ${[].concat(mail.to).join(', ')}`, 'nm-meta'));
        card.append(head, element('h3', mail.subject, 'nm-title'));
        if (mail.message) {
            const text = element('details', '', 'nm-code');
            text.append(element('summary', strings.showEmail), element('pre', mail.message));
            card.append(text);
        }
        const decide = (action) => async () => {
            await wp.apiFetch({ path: `${path}/mail/${mail.id}`, method: 'POST', data: { action } });
            loadHeld();
        };
        const actions = element('div', '', 'nm-actions');
        if (mail.status === 'sending') {
            card.append(element('div', strings.sendingUnknown, 'nm-warn'));
            actions.append(button(strings.closeEmail, decide('discard')));
        } else if (mail.attachments_changed) {
            card.append(element('div', strings.attachmentsChanged, 'nm-warn'));
            actions.append(button(strings.discard, decide('discard'), 'button button-reject'));
        } else {
            actions.append(button(strings.send, decide('release'), 'button button-primary'), button(strings.discard, decide('discard'), 'button button-reject'));
        }
        card.append(actions);
        return card;
    }

    async function loadHeld() {
        const { emails } = await wp.apiFetch({ path: `${path}/held` });
        document.getElementById('novamira-held-emails-count').textContent = emails.length ? String(emails.length) : '';
        heldRoot.replaceChildren();
        // Only shown while an email waits: an empty section is one more thing to read.
        document.getElementById('novamira-held-emails-section').hidden = !emails.length;
        emails.forEach((mail) => heldRoot.append(renderHeldEmail(mail)));
    }

    // What the history cannot bring back, in one place so it is not missed.
    function notCovered(operation) {
        const lines = [];
        if (operation.not_restorable) lines.push(operation.not_restorable === 1 ? strings.notSavedOne : strings.notSavedMany.replace('%d', String(operation.not_restorable)));
        if (operation.sent_emails) lines.push(`${strings.sentEmails}: ${operation.sent_emails}`);
        if (operation.sent_requests) lines.push(`${strings.sentRequests}: ${operation.sent_requests}`);
        (operation.emails || []).filter((mail) => mail.status !== 'held').forEach((mail) => lines.push(`${strings.emails}: ${[].concat(mail.to).join(', ')}: ${mail.subject} (${mail.status})`));
        (operation.messages || []).forEach((line) => lines.push(line));
        if (!lines.length) return null;
        const box = element('div', '', 'nm-warn');
        box.append(element('p', strings.notCovered, 'nm-label'));
        const list = element('ul');
        lines.forEach((line) => list.append(element('li', line)));
        box.append(list);
        return box;
    }

    // Emails still on hold are not lost: they wait under Approvals.
    function onHold(operation) {
        const held = (operation.emails || []).filter((mail) => mail.status === 'held');
        if (!held.length) return null;
        const box = element('div', '', 'nm-info');
        box.append(element('p', strings.heldHere, 'nm-label'));
        const list = element('ul');
        held.forEach((mail) => list.append(element('li', `${[].concat(mail.to).join(', ')}: ${mail.subject}`)));
        box.append(list);
        return box;
    }

    function changesLine(operation) {
        if (operation.changes.length) return operation.changes.join(' · ');
        if (operation.technical_changes) return `${operation.technical_changes} ${strings.technical}`;
        if (operation.file) return strings.fileChanged.replace('%s', operation.file);
        return operation.files ? strings.filesChanged : strings.noChanges;
    }

    // One operation on one line; what it could not keep, held emails and the full list open under Details.
    function renderOperation(operation) {
        const row = element('div', '', 'nm-op');
        const line = element('div', '', 'nm-op-line');
        line.append(element('span', time(operation.finished_at), 'nm-op-time'));
        // The AI's words for the call come first; what Novamira saw it change follows under Details.
        const what = element('span', operation.says || changesLine(operation), 'nm-op-what');
        if (operation.says) what.title = strings.aiWords;
        line.append(what, operationBadge(operation));
        const warning = notCovered(operation);
        const held = onHold(operation);
        const restorable = canRestore(operation);
        // Said in words, not with a sign: what the history cannot put back is often ordinary work.
        if (warning) line.append(element('span', restorable ? strings.partlyRestorable : strings.notRestorable, 'nm-op-flag'));
        if (restorable && operation.state !== 'running') line.append(copyButton(operation));
        row.append(line);
        const changesHidden = Boolean(operation.says);
        if (warning || held || operation.changes.length > 1 || changesHidden || operation.files) {
            const more = element('details', '', 'nm-op-more');
            more.append(element('summary', strings.details));
            if (changesHidden) more.append(element('p', changesLine(operation), 'nm-meta'));
            // Said plainly, not as a warning: changing a file is ordinary work.
            if (operation.files) more.append(element('p', strings.filesNote, 'nm-meta'));
            if (operation.changes.length > 1) {
                const facts = element('ul', '', 'nm-list');
                operation.changes.forEach((change) => facts.append(element('li', change)));
                if (operation.technical_changes) facts.append(element('li', `+ ${operation.technical_changes} ${strings.technical}`, 'nm-meta'));
                more.append(facts);
            }
            if (held) more.append(held);
            if (warning) more.append(warning);
            row.append(more);
        }
        return row;
    }

    // Whether anything of this operation can be put back from the history: a saved previous value, or an interrupted
    // run whose saved rows the AI can read.
    function canRestore(operation) {
        if (operation.state === 'interrupted') return true;
        return (operation.changed_rows || 0) > (operation.not_restorable || 0);
    }

    // One badge, always in the same place: how the work ran, and who approved it when it waited.
    function operationBadge(operation) {
        if (operation.state !== 'completed') {
            return element('span', strings[`state_${operation.state}`] || operation.state, `nm-pill ${STATE_TONE[operation.state] || ''}`);
        }
        if (operation.approved_by) return element('span', strings.approvedBy.replace('%s', who(operation.approved_by)), 'nm-pill is-ok');
        return element('span', strings.ran, 'nm-pill');
    }

    // A request of the user, closed: its time, the AI's words about it and how many operations it took.
    function renderRequest(operations) {
        const newest = operations[0];
        const oldest = operations[operations.length - 1];
        if (operations.length === 1) {
            const single = element('section', '', 'nm-request is-single');
            single.append(renderOperation(newest));
            return single;
        }
        const box = element('details', '', 'nm-request');
        const summary = element('summary');
        const span = time(oldest.finished_at) === time(newest.finished_at) ? time(newest.finished_at) : `${time(oldest.finished_at)}–${time(newest.finished_at)}`;
        const approved = operations.find((operation) => operation.approved_by);
        const failed = operations.find((operation) => operation.state !== 'completed');
        // The request as it started, and where it got to when its steps went on.
        const first = oldest.description || oldest.says || changesLine(oldest);
        const last = newest.says || changesLine(newest);
        const title = element('span', first, 'nm-request-title');
        if (last && last !== first) title.append(element('span', strings.lastStep.replace('%s', last), 'nm-request-last'));
        summary.append(element('span', span, 'nm-op-time'), title, operationBadge(failed || approved || newest));
        box.append(summary);
        const list = element('div', '', 'nm-ops');
        const facts = [strings.operations.replace('%d', String(operations.length))];
        if (newest.user_name) facts.push(strings.askedBy.replace('%s', who(newest.user_name)));
        list.append(element('p', facts.join(' · '), 'nm-meta'));
        operations.forEach((operation) => list.append(renderOperation(operation)));
        box.append(list);
        return box;
    }

    // Consecutive operations of the same request belong together.
    function byRequest(operations) {
        const requests = [];
        operations.forEach((operation) => {
            const last = requests[requests.length - 1];
            if (last && last[0].group_id && last[0].group_id === operation.group_id) last.push(operation);
            else requests.push([operation]);
        });
        return requests;
    }

    // Decisions on what the AI asked come from the approvals, with their words.
    const decisions = window.novamiraConfirmations || null;
    const decisionWords = decisions ? decisions.strings : {};
    const DECISION_TONE = { completed: 'is-ok', approved: 'is-run', executing: 'is-run', failed: 'is-bad', rejected: 'is-bad', canceled: 'is-bad', expired: 'is-bad', execution_unknown: 'is-bad', not_run: 'is-bad' };

    // Two lists, each page by page, shown as one: nothing older than what both have loaded is shown yet.
    const sources = {
        operations: { page: 0, items: [], more: true, fetch: (page) => wp.apiFetch({ path: `${path}?page=${page}` }).then((data) => ({ items: data.operations, more: data.has_more })) },
        decisions: { page: 0, items: [], more: Boolean(decisions), fetch: (page) => wp.apiFetch({ path: `${decisions.path}/runtime`, method: 'POST', data: { view: 'previous', page } }).then((data) => ({ items: data.requests, more: data.has_more })) },
    };

    async function loadPage(source) {
        source.page++;
        const { items, more } = await source.fetch(source.page);
        source.items.push(...items);
        source.more = more;
    }

    // A decision, closed like a piece of work: what was decided and by whom, then why it asked and the exact code.
    function renderDecision(request) {
        const box = element('details', '', 'nm-request nm-decision');
        const summary = element('summary');
        const decidedBy = request.decided_by_you ? decisionWords.byYou : request.decided_by ? decisionWords.byUser.replace('%s', request.decided_by) : '';
        const state = (decisionWords.states || {})[request.state] || request.state;
        summary.append(
            element('strong', time(request.finished_at || request.created_at)),
            element('span', request.summary || request.ability_name, 'nm-request-title'),
            element('span', decidedBy ? `${state} ${decidedBy}` : state, `nm-pill ${DECISION_TONE[request.state] || ''}`),
        );
        box.append(summary);
        const body = element('div', '', 'nm-ops nm-decision-body');
        if (request.found && request.found.length) {
            const found = element('div', '', 'nm-found');
            request.found.forEach((item) => found.append(element('span', item, 'nm-chip')));
            body.append(element('p', decisionWords.whyAsks, 'nm-label'), found);
        }
        if (request.details) body.append(element('p', request.details, 'nm-details'));
        body.append(decisionCode(request));
        box.append(body);
        return box;
    }

    // The exact code, loaded only when opened.
    function decisionCode(request) {
        const panel = element('details', '', 'nm-code');
        panel.append(element('summary', decisionWords.showCode));
        const text = element('pre', decisionWords.codeLoading);
        panel.append(text);
        let loaded = false;
        panel.addEventListener('toggle', async () => {
            if (!panel.open || loaded) return;
            loaded = true;
            try {
                const code = await wp.apiFetch({ path: `${decisions.path}/${request.request_id}/code` });
                text.textContent = code.path ? `${code.path}\n\n${code.text}` : code.text;
            } catch (error) {
                loaded = false;
                text.textContent = error.message || '';
            }
        });
        return panel;
    }

    // Where both lists reach: the older of the two would show a gap if the other has more to load.
    function shownFrom() {
        let from = 0;
        Object.values(sources).forEach((source) => {
            if (!source.more || !source.items.length) return;
            const last = source.items[source.items.length - 1];
            from = Math.max(from, new Date(last.finished_at || last.created_at).getTime());
        });
        return from;
    }

    // What the search and the day filter look at: the words a person reads on the row.
    function operationText(operations) {
        return operations.map((operation) => [operation.says, operation.description, operation.ability, operation.file, operation.approved_by, operation.user_name, ...(operation.changes || [])].join(' ')).join(' ').toLowerCase();
    }

    function localDay(iso) {
        const date = new Date(iso);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    // Requests that ran nothing are not changes: hidden unless asked for.
    const filters = { text: '', day: '', decisions: false };

    function render() {
        const entries = [];
        byRequest(sources.operations.items).forEach((operations) => entries.push({ at: operations[0].finished_at, text: operationText(operations), node: () => renderRequest(operations) }));
        // An approved call that ran is already shown as the operation it became, with who approved it: not twice. Older
        // runs carry no request id, so a completed decision is left out too.
        const ran = new Set(sources.operations.items.flatMap((operation) => operation.approval_requests || []));
        sources.decisions.items
            .filter((request) => filters.decisions && !ran.has(request.request_id) && request.state !== 'completed')
            .forEach((request) => entries.push({ at: request.finished_at || request.created_at, text: [request.summary, request.ability_name, request.details, request.decided_by].join(' ').toLowerCase(), node: () => renderDecision(request) }));
        const from = shownFrom();
        const words = filters.text.toLowerCase().split(/\s+/).filter(Boolean);
        const shown = entries
            .filter((entry) => new Date(entry.at).getTime() >= from)
            .filter((entry) => !filters.day || localDay(entry.at) === filters.day)
            .filter((entry) => words.every((word) => entry.text.includes(word)))
            .sort((a, b) => new Date(b.at) - new Date(a.at));
        root.replaceChildren();
        if (!shown.length && !sources.operations.more && !sources.decisions.more) {
            root.append(element('p', filters.text || filters.day ? strings.noMatch : strings.empty, 'nm-empty'));
            return;
        }
        if (!shown.length && (filters.text || filters.day)) root.append(element('p', strings.noMatch, 'nm-empty'));
        let heading = '';
        shown.forEach((entry) => {
            const label = dayLabel(entry.at);
            if (label !== heading) {
                heading = label;
                root.append(element('h3', label, 'nm-day'));
            }
            root.append(entry.node());
        });
        if (sources.operations.more || sources.decisions.more) {
            root.append(button(strings.loadMore, async () => {
                // The list that sets the limit loads its next page.
                const from = shownFrom();
                await Promise.all(Object.values(sources).filter((source) => {
                    if (!source.more || !source.items.length) return source.more;
                    const last = source.items[source.items.length - 1];
                    return new Date(last.finished_at || last.created_at).getTime() >= from;
                }).map(loadPage));
                render();
            }));
        }
    }

    async function load() {
        Object.values(sources).forEach((source) => {
            source.page = 0;
            source.items = [];
            source.more = source === sources.decisions ? Boolean(decisions) : true;
        });
        await Promise.all(Object.values(sources).filter((source) => source.more).map(loadPage));
        render();
    }

    if (heldRoot) {
        loadHeld();
        setInterval(() => {
            if (!document.hidden) loadHeld();
        }, 10000);
    }
    if (!root) return;

    // Search and day filter work on what is loaded; a day further back loads older pages until it is reached.
    const toolbar = element('div', '', 'nm-history-filters');
    const search = element('input');
    search.type = 'search';
    search.placeholder = strings.search;
    search.setAttribute('aria-label', strings.search);
    const day = element('input');
    day.type = 'date';
    day.setAttribute('aria-label', strings.day);
    // Shown only while a search or a day is set: there is nothing to clear otherwise.
    const clear = button(strings.clearFilters, () => {
        search.value = '';
        day.value = '';
        filters.text = '';
        filters.day = '';
        clear.hidden = true;
        render();
    }, 'button');
    clear.hidden = true;
    const showClear = () => {
        clear.hidden = search.value.trim() === '' && day.value === '';
    };
    search.addEventListener('input', showClear);
    day.addEventListener('change', showClear);
    const decisionsBox = element('label', '', 'nm-history-decisions');
    const decisionsToggle = element('input');
    decisionsToggle.type = 'checkbox';
    decisionsToggle.addEventListener('change', () => {
        filters.decisions = decisionsToggle.checked;
        render();
    });
    decisionsBox.append(decisionsToggle, ` ${strings.showDecisions}`);
    toolbar.append(search, day, clear, decisionsBox);
    root.before(toolbar);
    let typing = 0;
    search.addEventListener('input', () => {
        window.clearTimeout(typing);
        typing = window.setTimeout(() => {
            filters.text = search.value.trim();
            render();
        }, 200);
    });
    day.addEventListener('change', async () => {
        filters.day = day.value;
        if (filters.day) {
            const start = new Date(`${filters.day}T00:00:00`).getTime();
            while (Object.values(sources).some((source) => source.more) && shownFrom() > start) {
                await Promise.all(Object.values(sources).filter((source) => source.more).map(loadPage));
            }
        }
        render();
    });

    const clearButton = document.getElementById('novamira-journal-clear');
    if (clearButton) {
        clearButton.addEventListener('click', async () => {
            if (!window.confirm(strings.confirmClear)) return;
            await wp.apiFetch({ path: `${path}/clear`, method: 'POST' });
            load();
        });
    }

    load();
})();
