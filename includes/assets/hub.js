/*
 * Abilities Hub: enable/disable a single row over AJAX so toggling never
 * reloads the page and the open sections stay open. Progressive enhancement:
 * if the script never runs, the row's plain <form> POST still works.
 *
 * On a failed request (network error, or a nonce that expired after hours on
 * the page) we reload instead of retrying the form — the rendered form carries
 * the same stale nonce, so only a fresh page yields a usable one. This is rare;
 * the cost is losing the open sections, which beats a dead "link expired" wall.
 */
(function () {
    'use strict';

    var hub = document.querySelector('.novamira-hub');
    if (!hub || typeof window.ajaxurl !== 'string') {
        return;
    }

    hub.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.novamira-hub-actions form')) {
            return;
        }

        var actionInput = form.querySelector('input[name="novamira_ability_hub_action"]');
        if (!actionInput || actionInput.value !== 'toggle_disabled') {
            return;
        }

        var row = form.closest('.novamira-hub-row');
        var button = form.querySelector('button');
        var nonce = form.querySelector('input[name="_wpnonce"]');
        var abilityName = form.querySelector('input[name="ability_name"]');
        if (!row || !button || !nonce || !abilityName) {
            return; // Let the native submit handle it.
        }

        event.preventDefault();

        var body = new URLSearchParams();
        body.set('action', 'novamira_toggle_ability');
        body.set('_wpnonce', nonce.value);
        body.set('ability_name', abilityName.value);

        button.disabled = true;
        row.classList.add('is-busy');

        fetch(window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (payload) {
                if (!payload || payload.success !== true || !payload.data) {
                    throw new Error('request refused');
                }
                applyToggle(row, button, payload.data);
                button.disabled = false;
                row.classList.remove('is-busy');
            })
            .catch(function () {
                // See the file header: reload to recover with a fresh nonce.
                window.location.reload();
            });
    });

    // The management link lives inside a <summary>; following it must not also
    // expand or collapse that section.
    hub.addEventListener('click', function (event) {
        if (!(event.target instanceof HTMLElement)) {
            return;
        }
        if (event.target.closest('.novamira-hub-group-actions')) {
            event.stopPropagation();
        }
    });

    function applyToggle(row, button, data) {
        var disabled = data.disabled === true;
        row.classList.toggle('is-off', disabled);
        row.classList.toggle('is-on', !disabled);

        var state = row.querySelector('.novamira-list-inline-state');
        if (disabled && typeof data.status === 'string') {
            if (!state) {
                var slug = row.querySelector('.novamira-hub-main .slug');
                if (slug) {
                    state = document.createElement('span');
                    state.className = 'novamira-list-inline-state';
                    slug.insertAdjacentElement('afterend', state);
                }
            }
            if (state) {
                state.textContent = '— ' + data.status;
            }
        } else if (state) {
            state.remove();
        }
        if (typeof data.button === 'string') {
            button.textContent = data.button;
        }

        // Keep the enclosing category and provider headers in sync.
        var subsection = row.closest('.novamira-hub-subsection');
        if (subsection) {
            refreshSectionMeta(subsection);
        }
        var section = row.closest('.novamira-hub-section');
        if (section) {
            refreshSectionMeta(section);
        }
    }

    // Recompute a section header's `enabled / total` count and toggle its
    // "All disabled" pill, mirroring novamira_render_ability_header_meta() in PHP.
    function refreshSectionMeta(section) {
        var summary = section.querySelector(':scope > summary');
        if (!summary) {
            return;
        }
        var rows = section.querySelectorAll('.novamira-hub-row');
        var total = rows.length;
        var enabled = 0;
        rows.forEach(function (r) {
            if (!r.classList.contains('is-off')) {
                enabled++;
            }
        });

        var count = summary.querySelector('.count');
        if (count) {
            count.textContent = enabled === total ? String(total) : enabled + ' / ' + total;
        }

        var heading = summary.querySelector('h2, h3');
        var pill = summary.querySelector('.novamira-hub-alloff');
        if (enabled === 0 && total > 0) {
            if (!pill && heading) {
                pill = document.createElement('span');
                pill.className = 'pill status is-disabled novamira-hub-alloff';
                pill.textContent = hub.getAttribute('data-alloff-label') || 'All disabled';
                heading.appendChild(pill);
            }
        } else if (pill) {
            pill.remove();
        }
    }
})();
