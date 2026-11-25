// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

(() => {
    const root = document.getElementById('novamira-confirmations');
    if (!root) return;
    const { path, strings, historyUrl, requestTtl, decisionNonce } = window.novamiraConfirmations;
    const form = document.getElementById('novamira-confirmation-settings');
    const firstChoice = document.getElementById('novamira-level-first');
    const change = document.getElementById('novamira-level-change');
    const message = document.getElementById('novamira-confirmation-message');
    const skipped = document.getElementById('novamira-approvals-skipped');

    // Choosing a level marks its column in the table, before anything is saved.
    for (const levels of root.querySelectorAll('.nm-levels')) {
        levels.addEventListener('change', () => {
            const chosen = levels.querySelector('input[name="level"]:checked');
            if (!chosen) return;
            const scope = levels.closest('form') || levels;
            for (const table of scope.querySelectorAll('.nm-level-table')) table.dataset.chosen = chosen.value;
        });
    }

    // An earlier number has no level: it is kept until another choice is made.
    function chosenLevel(container) {
        const chosen = container.querySelector('input[name="level"]:checked');
        return chosen && chosen.value ? chosen.value : undefined;
    }

    async function saveSettings(container, data) {
        const submit = container.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const runtime = await wp.apiFetch({ path: `${path}/settings`, method: 'POST', data });
            skipped.hidden = !runtime.skip_approvals;
            return true;
        } catch (error) {
            message.textContent = error.message;
            return false;
        } finally {
            submit.disabled = false;
        }
    }

    if (form) {
        // The list the AI read until now: a change is told how it reaches the AI.
        let savedRules = form.elements.instructions.value.trim();
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const saved = await saveSettings(form, {
                hold_emails: form.elements.hold_emails.checked,
                instructions: form.elements.instructions.value,
                // Unticked addresses are no longer approved; one approved since the page loaded stays.
                forget_destinations: Array.from(
                    form.querySelectorAll('input[name="destinations"]:not(:checked)'),
                    (input) => input.value,
                ),
            });
            if (!saved) return;
            const rules = form.elements.instructions.value.trim();
            message.textContent = rules === savedRules ? strings.saved : strings.rulesSaved;
            savedRules = rules;
        });
    }

    // Skipping approvals is asked once more, in a dialog; staying skipped asks nothing.
    const skipDialog = document.getElementById('novamira-skip-dialog');
    function confirmSkip(level) {
        if (level !== 'skip' || !skipped.hidden || !skipDialog || typeof skipDialog.showModal !== 'function') {
            return Promise.resolve(true);
        }
        return new Promise((resolve) => {
            const answer = (event) => {
                const button = event.target.closest('button');
                if (!button) return;
                skipDialog.removeEventListener('click', answer);
                skipDialog.close();
                resolve(button.value === 'skip');
            };
            skipDialog.addEventListener('click', answer);
            skipDialog.addEventListener('cancel', () => resolve(false), { once: true });
            skipDialog.showModal();
        });
    }

    if (firstChoice) {
        firstChoice.addEventListener('submit', async (event) => {
            event.preventDefault();
            const level = chosenLevel(firstChoice);
            if (!level || !(await confirmSkip(level))) return;
            if (await saveSettings(firstChoice, { level })) {
                firstChoice.remove();
                showLevel(level);
                message.textContent = strings.levelSaved;
            }
        });
    }

    // The line above the requests says the saved level; its choice starts from it again.
    function showLevel(value) {
        if (!change) return;
        for (const input of change.querySelectorAll('input[name="level"]')) {
            input.checked = input.defaultChecked = input.value === value;
        }
        const chosen = change.querySelector('input[name="level"]:checked');
        if (chosen) change.querySelector('.nm-asks-name').textContent = chosen.dataset.name;
        for (const node of change.querySelectorAll('.nm-asks-label')) node.hidden = value === 'skip';
        change.querySelector('.nm-levels').dispatchEvent(new Event('change'));
        change.hidden = false;
    }

    if (change) {
        const panel = change.querySelector('.nm-level-panel');
        const toggle = change.querySelector('.nm-asks-change');
        const open = (opened) => {
            panel.hidden = !opened;
            toggle.setAttribute('aria-expanded', String(opened));
        };
        const discard = () => {
            change.reset();
            change.querySelector('.nm-levels').dispatchEvent(new Event('change'));
            open(false);
        };
        toggle.addEventListener('click', () => (panel.hidden ? open(true) : discard()));
        change.querySelector('.nm-level-cancel').addEventListener('click', discard);
        change.addEventListener('submit', async (event) => {
            event.preventDefault();
            const level = chosenLevel(change);
            if (!level) {
                open(false);
                return;
            }
            if (!(await confirmSkip(level))) return;
            if (await saveSettings(change, { level })) {
                showLevel(level);
                open(false);
                message.textContent = strings.levelSaved;
            }
        });
    }

    // Approvals keeps what waits, Change History what was decided; Settings has no requests to keep up to date.
    const list = document.getElementById('novamira-confirmation-requests');
    if (!list) return;

    const connection = document.getElementById('novamira-confirmation-connection');
    const cards = new Map();
    const pagination = document.getElementById('novamira-confirmation-pagination');
    const previousPage = pagination ? pagination.querySelector('[data-page="previous"]') : null;
    const nextPage = pagination ? pagination.querySelector('[data-page="next"]') : null;
    const count = document.getElementById('novamira-confirmation-count');
    const view = list.dataset.view;
    let page = 1;
    let stopped = false;
    let timer;
    let justRan = false;
    const linked = new URLSearchParams(window.location.search).get('request');
    let linkedHandled = !linked;

    const STATE_TONE = {
        pending_confirmation: 'is-wait',
        approved: 'is-run',
        executing: 'is-run',
        completed: 'is-ok',
        failed: 'is-bad',
        rejected: 'is-bad',
        execution_unknown: 'is-bad',
        not_run: 'is-bad',
    };

    // Calls of one conversation sent while one waited are decided together: one card for all of them.
    function actionsOf(request) {
        return request.actions && request.actions.length > 1 ? request.actions : null;
    }

    function memberIds(request) {
        const actions = actionsOf(request);
        return actions ? actions.map((action) => action.request_id) : [request.request_id];
    }

    // A block is running while any of its calls still is, whatever state its first call is in.
    function cardState(request) {
        const actions = actionsOf(request);
        if (!actions || request.state === 'pending_confirmation') return request.state;
        const running = actions.find((action) => action.state === 'executing' || action.state === 'approved');
        return running ? running.state : request.state;
    }

    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    const relative = typeof Intl.RelativeTimeFormat === 'function'
        ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
        : null;

    // "3 minutes ago" or "in 23 hours" reads faster than a timestamp.
    function relativeText(time) {
        const minutes = Math.round((time - Date.now()) / 60000);
        if (!relative || Math.abs(minutes) > 60 * 24) return new Date(time).toLocaleString();
        if (Math.abs(minutes) < 60) return relative.format(minutes, 'minute');
        return relative.format(Math.round(minutes / 60), 'hour');
    }

    // The exact time stays in the tooltip.
    function when(iso) {
        const date = new Date(iso);
        const node = element('time', relativeText(date.getTime()), 'nm-meta');
        node.dateTime = iso;
        node.title = date.toLocaleString();
        return node;
    }

    // Through which connection, and whose: on a site with several administrators or AIs, whose work this is.
    function throughText(request) {
        if (request.asked_by_you) {
            return request.connection ? strings.throughYourNamed.replace('%s', request.connection) : strings.throughYou;
        }
        if (!request.asked_by) return request.connection ? strings.throughNamed.replace('%s', request.connection) : '';
        return request.connection
            ? strings.throughUserNamed.replace('%1$s', request.connection).replace('%2$s', request.asked_by)
            : strings.throughUser.replace('%s', request.asked_by);
    }

    // While a request waits: when it asked, through which connection, and until when it waits.
    function waitingMeta(request) {
        const meta = element('span', '', 'nm-meta');
        const created = new Date(request.created_at).getTime();
        const through = throughText(request);
        meta.update = () => {
            const asked = strings.askedAgo.replace('%s', relativeText(created));
            meta.textContent = `${asked}${through ? ` ${through}` : ''} · ${strings.expiresIn.replace('%s', relativeText(created + requestTtl * 1000))}`;
        };
        meta.update();
        return meta;
    }

    // The link the AI gives opens the request it is about, or the card of the calls it was sent with.
    function showLinkedRequest() {
        if (linkedHandled || view !== 'active') return;
        linkedHandled = true;
        const card = cards.get(linked) || Array.from(cards.values()).find((entry) => entry.ids.includes(linked));
        if (!card) {
            message.textContent = strings.requestGone;
            return;
        }
        card.node.classList.add('is-linked');
        card.node.scrollIntoView({ block: 'center' });
        card.approve.focus({ preventScroll: true });
    }

    function detailsPanel(hint) {
        const panel = element('details', '', 'nm-code');
        const summary = element('summary', strings.showDetails);
        if (hint) summary.append(' ', element('span', hint, 'nm-details-hint'));
        panel.append(summary);
        return panel;
    }

    // The AI's longer words and the exact code, closed: most people decide from the lines above, whoever wants more
    // finds it here.
    function codePanel(request, hint) {
        const panel = detailsPanel(hint);
        // A command's meaning, read by Novamira rather than told by the AI.
        if (request.means) {
            panel.append(element('p', strings.means, 'nm-label'), element('p', request.means, 'nm-means'));
        }
        if (request.details) {
            panel.append(element('p', strings.aiDetails, 'nm-label'), element('p', request.details, 'nm-details'));
        }
        const file = element('p', '', 'nm-code-file');
        file.hidden = true;
        const text = element('pre', strings.codeLoading);
        panel.append(file, text);
        let loaded = false;
        panel.addEventListener('toggle', async () => {
            if (!panel.open || loaded) return;
            loaded = true;
            try {
                const code = await wp.apiFetch({ path: `${path}/${request.request_id}/code` });
                text.textContent = code.text;
                if (code.path) {
                    file.textContent = strings.codeFile.replace('%s', code.path);
                    file.hidden = false;
                }
            } catch (error) {
                loaded = false;
                text.textContent = error.message || strings.offline;
            }
        });
        return panel;
    }

    // Why it asks, whether it can be undone, and what was counted: what a person reads before deciding on a call.
    function evidence(request, pending) {
        const nodes = [];
        // Looking back, why it asked, in words.
        if (!pending && request.found && request.found.length) {
            const found = element('div', '', 'nm-found is-past');
            request.found.forEach((item) => found.append(element('span', item, 'nm-chip')));
            nodes.push(found);
        }
        if (pending) {
            const facts = element('dl', '', 'nm-facts');
            // Whether the AI could undo it later from the history, decided by Novamira from what the check found.
            if (request.undo === 'history' || request.undo === 'no') {
                const no = request.undo === 'no';
                const reason = no && request.undo_reason ? request.undo_reason.charAt(0).toLowerCase() + request.undo_reason.slice(1) : '';
                const undoText = no ? (reason ? strings.undoNoBecause.replace('%s', reason) : strings.undoNo) : strings.undoHistory;
                facts.append(element('dt', strings.undo), element('dd', undoText, `nm-undo${no ? ' is-no' : ''}`));
            }
            if (facts.children.length) nodes.push(facts);
        }
        if (pending && request.measured && request.measured.length) {
            const box = element('div', '', 'nm-box');
            box.append(element('p', strings.measured.replace('%s', new Date(request.measured_at).toLocaleTimeString()), 'nm-label'));
            const sizes = element('ul');
            request.measured.forEach((line) => sizes.append(element('li', line)));
            box.append(sizes);
            nodes.push(box);
        }
        return nodes;
    }

    // A call after which the AI may not be able to come back to this site comes first, in red.
    function dangerBox(lines) {
        const danger = element('div', '', 'nm-danger');
        danger.append(element('p', strings.accessTitle, 'nm-danger-title'));
        lines.forEach((line) => danger.append(element('p', line)));
        danger.append(element('p', strings.accessUndo));
        return danger;
    }

    function actionLabel(action, position) {
        const label = action.summary || action.ability_name;
        return position ? `${position}. ${label}` : label;
    }

    // Mixed cards show a subset here, so only number their actions in the full execution sequence.
    function blockBody(actions, mixed) {
        return actions.map((action, index) => {
            const part = element('div', '', 'nm-block-action');
            part.append(element('h4', actionLabel(action, mixed ? null : index + 2), 'nm-title'), ...evidence(action, true), codePanel(action));
            return part;
        });
    }

    function executionOrder(actions, hint) {
        const panel = element('details', '', 'nm-execution');
        const summary = element('summary', strings.executionOrder.replace('%d', String(actions.length)));
        if (hint) summary.append(' ', element('span', hint, 'nm-details-hint'));
        const sequence = element('ol');
        for (const action of actions) {
            const minor = action.joined_small;
            const row = element('li');
            const line = element('div', '', 'nm-execution-line');
            line.append(
                element('span', actionLabel(action)),
                element('span', minor ? strings.executionMinor : strings.executionApproval, `nm-pill${minor ? '' : ' is-wait'}`),
            );
            row.append(line);
            // Approval-required actions already have their own details on the card.
            if (minor) row.append(codePanel(action));
            sequence.append(row);
        }
        panel.append(summary, sequence);
        return panel;
    }

    function drawCard(request) {
        const pending = request.state === 'pending_confirmation';
        const actions = actionsOf(request);
        // The block starts with a call that asked. Later calls may have joined only to wait their turn.
        const following = pending && actions ? actions.slice(1) : [];
        const additionalApprovals = following.filter((action) => !action.joined_small);
        const minor = following.filter((action) => action.joined_small);
        let minorHint = '';
        if (minor.length) {
            minorHint = minor.length === 1 ? strings.minorAction : strings.minorActions.replace('%d', String(minor.length));
        }
        const node = element('section', '', `nm-card ${pending ? 'is-pending' : 'is-done'}`);
        node.setAttribute('role', 'region');
        const pill = element('span', '', 'nm-pill');
        const head = element('div', '', 'nm-head');
        const waiting = waitingMeta(request);
        const done = element('span', '', 'nm-done-meta');
        // Who decided, in words; the attention number stays in the data, where the level counts use it.
        if (request.decided_by_you) done.append(element('span', strings.byYou, 'nm-meta'));
        else if (request.decided_by) done.append(element('span', strings.byUser.replace('%s', request.decided_by), 'nm-meta'));
        done.append(when(request.finished_at || request.created_at));
        waiting.hidden = !pending;
        done.hidden = pending;
        head.append(pill, waiting, done);
        node.append(head);

        const access = (actions || [request]).flatMap((action) => action.access || []);
        if (pending && access.length) node.append(dangerBox(access));
        if (pending) node.append(element('p', strings.says, 'nm-label'));
        const title = element('h3', actionLabel(request, actions && !minor.length ? 1 : null), 'nm-title');
        title.id = `confirmation-${request.request_id}`;
        node.setAttribute('aria-labelledby', title.id);
        const details = codePanel(request, additionalApprovals.length ? '' : minorHint);
        node.append(title, ...evidence(request, pending), details, ...blockBody(additionalApprovals, minor.length > 0));
        if (minor.length) {
            // A sequence shared by several approval actions stands alone; a single action keeps it in its details.
            if (additionalApprovals.length) node.append(executionOrder(actions, minorHint));
            else details.firstElementChild.after(executionOrder(actions));
        }

        // After the decision, where each call of the block got to.
        const progress = element('div', '', 'nm-block-progress');
        const ranLine = element('p', '', 'nm-label');
        const stateList = element('ol');
        progress.append(ranLine, stateList);
        progress.hidden = true;
        if (actions) node.append(progress);

        const approvalCount = 1 + additionalApprovals.length;
        const approveText = approvalCount === 1 ? strings.approveRun
            : (minor.length ? strings.approveActions : strings.approveAll).replace('%d', String(approvalCount));
        const actionsBar = element('div', '', 'nm-actions');
        const approve = element('button', approveText, 'button button-primary');
        const reject = element('button', approvalCount > 1 ? strings.rejectAll : strings.reject, 'button button-reject');
        approve.type = reject.type = 'button';
        const state = element('span', '', 'nm-state');
        state.setAttribute('aria-live', 'polite');
        actionsBar.append(approve, reject, state);
        if (pending) {
            node.append(actionsBar);
            node.classList.add('has-actions');
        }
        // What an approved action changed is in the history, with everything else the AI did, above the decisions.
        if (request.state === 'completed' && historyUrl) {
            const link = element('a', strings.seeChanges, 'nm-history-link');
            link.href = '#novamira-journal';
            node.append(link);
        }

        async function decide(decision) {
            approve.disabled = reject.disabled = true;
            state.textContent = decision === 'approve' ? strings.running : '';
            try {
                const decided = await wp.apiFetch({ path: `${path}/${request.request_id}/decision`, method: 'POST', data: { decision, decision_nonce: decisionNonce, members: memberIds(request) } });
                // Said once: in the empty page when nothing else waits, above the other requests when some do.
                justRan = decided.state === 'completed' && (!decided.block || decided.block.ran === decided.block.size);
                message.textContent = '';
                await refresh();
                if (justRan && cards.size) message.textContent = strings.ran;
            } catch (error) {
                // A card is drawn once: one that changed is drawn again with its new numbers or its new calls.
                if (error.code === 'novamira_card_changed') {
                    node.remove();
                    cards.delete(request.request_id);
                }
                approve.disabled = reject.disabled = false;
                state.textContent = '';
                await refresh();
                message.textContent = error.message;
            }
        }
        approve.addEventListener('click', () => decide('approve'));
        reject.addEventListener('click', () => decide('reject'));
        return { node, pill, approve, reject, actions: actionsBar, waiting, done, progress, ranLine, stateList, ids: memberIds(request) };
    }

    // Each call of a decided block, with its state, and how many ran.
    function showProgress(card, request) {
        const actions = actionsOf(request);
        if (!actions) return;
        card.progress.hidden = request.state === 'pending_confirmation';
        if (card.progress.hidden) return;
        const ran = request.block ? request.block.ran : actions.filter((action) => action.state === 'completed').length;
        card.ranLine.textContent = strings.blockRan.replace('%1$d', String(ran)).replace('%2$d', String(actions.length));
        card.stateList.replaceChildren(...actions.map((action) => {
            const item = element('li', action.summary || action.ability_name);
            item.append(' ', element('span', strings.states[action.state] || action.state, `nm-pill ${STATE_TONE[action.state] || ''}`));
            return item;
        }));
    }

    // The page is empty the first time someone opens it: that is where it says what it is for.
    function emptyState() {
        if (view !== 'active') return element('p', strings.emptyPrevious, 'nm-empty');
        const box = element('div', '', 'nm-empty');
        // With approvals skipped nothing ever waits: saying "all clear" would suggest the AI still asks.
        if (!skipped.hidden) {
            box.append(element('p', strings.emptySkipped, 'nm-empty-title'), element('p', strings.emptySkippedHelp));
            return box;
        }
        const done = justRan;
        const icon = element('span', '', 'dashicons dashicons-yes-alt nm-empty-icon');
        icon.setAttribute('aria-hidden', 'true');
        box.append(
            icon,
            element('p', done ? strings.ranTitle : strings.empty, 'nm-empty-title'),
            element('p', done ? strings.ranHelp : strings.emptyHelp),
        );
        return box;
    }

    function render(requests) {
        // A new request ends the "done" moment: the page goes back to what waits.
        if (requests.length) justRan = false;
        if (!requests.length) {
            list.replaceChildren(emptyState());
            cards.clear();
            return;
        }
        if (!cards.size) list.replaceChildren();
        const seen = new Set();
        requests.forEach((request, index) => {
            seen.add(request.request_id);
            let card = cards.get(request.request_id);
            // A call that joined the block while it waited: the card is drawn again with it.
            if (card && card.ids.join() !== memberIds(request).join() && request.state === 'pending_confirmation') {
                card.node.remove();
                card = null;
            }
            if (!card) {
                card = drawCard(request);
                cards.set(request.request_id, card);
            }
            // Keep the server's order: the oldest waiting request first.
            if (list.children[index] !== card.node) list.insertBefore(card.node, list.children[index] || null);
            const shown = cardState(request);
            card.pill.textContent = strings.states[shown] || shown;
            card.pill.className = `nm-pill ${STATE_TONE[shown] || ''}`;
            const pending = request.state === 'pending_confirmation';
            card.node.classList.toggle('is-pending', pending);
            card.actions.hidden = !pending;
            card.waiting.hidden = !pending;
            card.done.hidden = pending;
            showProgress(card, request);
            if (pending) card.waiting.update();
        });
        for (const [id, card] of cards) {
            if (!seen.has(id)) {
                card.node.remove();
                cards.delete(id);
            }
        }
    }

    // Saving another level cancels what waits: the line under the choice says how many, and only while some do.
    function showLevelWarning(waiting) {
        const warning = change ? change.querySelector('.nm-level-warning') : null;
        if (!warning) return;
        warning.hidden = waiting === 0;
        if (waiting === 0) return;
        const text = waiting === 1 ? strings.levelWarningOne : strings.levelWarningMany;
        warning.textContent = text.replace(/\d+/, String(waiting));
    }

    // The count next to Novamira and AI Activity in the admin menu follows what still waits, without a reload.
    function showMenuCount(waiting) {
        if (typeof waiting !== 'number') return;
        for (const badge of document.querySelectorAll('#adminmenu .nm-waiting-badge')) {
            badge.style.display = waiting === 0 ? 'none' : '';
            badge.textContent = String(waiting);
        }
        showAdminBarCount(waiting);
    }

    // Novamira's entry in the admin bar too: it was drawn with the page, and would keep an old count after a decision.
    function showAdminBarCount(waiting) {
        const node = document.getElementById('wp-admin-bar-novamira-mcp-status');
        const link = node ? node.querySelector(':scope > .ab-item') : null;
        if (!link) return;
        node.classList.toggle('novamira-waiting', waiting > 0);
        let bubble = link.querySelector('.novamira-waiting-count');
        if (waiting > 0 && !bubble) {
            bubble = element('span', '', 'novamira-waiting-count');
            link.append(' ', bubble);
        }
        if (bubble) {
            bubble.textContent = String(waiting);
            bubble.hidden = waiting === 0;
        }
        const item = document.getElementById('wp-admin-bar-novamira-ai-waiting');
        if (item && waiting === 0) item.hidden = true;
    }

    async function refresh() {
        const requestedPage = page;
        try {
            const data = await wp.apiFetch({ path: `${path}/runtime`, method: 'POST', data: { view, page: requestedPage } });
            if (page !== requestedPage) return;
            // Nothing to say while connected: the bar speaks only when the page lost the site.
            if (connection) {
                connection.textContent = '';
                connection.classList.remove('is-off');
            }
            render(data.requests);
            if (count) {
                const waiting = data.requests.filter((request) => request.state === 'pending_confirmation').length;
                count.textContent = waiting ? String(waiting) : '';
            }
            showLevelWarning(data.requests.filter((request) => request.state === 'pending_confirmation').length);
            showMenuCount(data.waiting);
            showLinkedRequest();
            if (pagination) {
                pagination.hidden = page === 1 && !data.has_more;
                previousPage.disabled = page === 1;
                nextPage.disabled = !data.has_more;
            }
        } catch (error) {
            if (!connection) return;
            connection.textContent = `${strings.offline} ${error.message || ''}`;
            connection.classList.add('is-off');
        }
    }

    function changePage(delta) {
        page = Math.max(1, page + delta);
        previousPage.disabled = nextPage.disabled = true;
        refresh();
    }
    if (pagination) {
        previousPage.addEventListener('click', () => changePage(-1));
        nextPage.addEventListener('click', () => changePage(1));
    }

    async function tick() {
        await refresh();
        if (!stopped) timer = setTimeout(tick, 3000);
    }

    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', (event) => { if (event.persisted) { stopped = false; tick(); } });
    tick();
})();
