// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
(function () {
    'use strict';

    function frontMatter(raw) {
        var text = raw.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n').replace(/^---[ \t]+$/gm, '---');
        if (text.indexOf('---\n') !== 0) { return []; }
        var end = text.indexOf('\n---\n', 4);
        if (end === -1 && text.slice(-4) === '\n---') { end = text.length - 4; }
        if (end === -1) { return []; }
        return text.slice(4, end).split('\n');
    }

    function unquote(v) {
        if (v.length >= 2) {
            if ((v[0] === '"' && v.slice(-1) === '"') || (v[0] === "'" && v.slice(-1) === "'")) {
                return v.slice(1, -1);
            }
        }
        return v;
    }

    function cssValue(v) {
        return v.replace(/[;{}<>]/g, '').replace(/\b(?:url|image-set|image|cross-fade|element|src)\s*\(/gi, '(').trim();
    }

    function cssLength(v) {
        return /^\d+(\.\d+)?$/.test(v) ? v + 'px' : v;
    }

    function extract(raw) {
        var colors = {}, typography = {}, spacing = {}, rounded = {};
        var section = null, token = null;
        frontMatter(raw).forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed || trimmed[0] === '#') { return; }
            var colon = trimmed.indexOf(':');
            if (colon === -1) { return; }
            var indent = line.length - line.replace(/^[ \t]+/, '').length;
            var key = unquote(trimmed.slice(0, colon).trim());
            var value = unquote(trimmed.slice(colon + 1).trim());
            if (indent === 0) {
                key = key.toLowerCase();
                if (value !== '' && (key === 'rounded' || key === 'spacing')) {
                    if (key === 'rounded') { rounded.md = value; } else { spacing.md = value; }
                    section = null;
                    token = null;
                    return;
                }
                section = (value === '' && ['colors', 'typography', 'spacing', 'rounded'].indexOf(key) !== -1) ? key : null;
                token = null;
                return;
            }
            if (section === 'colors' && value !== '') { colors[key] = value; }
            else if (section === 'spacing' && value !== '') { spacing[key] = value; }
            else if (section === 'rounded' && value !== '') { rounded[key] = value; }
            else if (section === 'typography') {
                if (indent <= 2 && value === '') { token = key; typography[key] = {}; }
                else if (indent <= 2 && value !== '') {
                    token = null;
                    var fw = value.match(/^(.*\S)\s+([1-9]00)$/);
                    typography[key] = fw ? { fontFamily: fw[1], fontWeight: fw[2] } : { fontFamily: value };
                }
                else if (indent >= 4 && token && value !== '') { typography[token][key] = value; }
            }
        });
        return { colors: colors, typography: typography, spacing: spacing, rounded: rounded };
    }

    function lowerKeys(map) {
        var out = {};
        Object.keys(map).forEach(function (k) {
            var lk = k.toLowerCase();
            if (!(lk in out)) { out[lk] = map[k]; }
        });
        return out;
    }
    function pick(map, keys) {
        var lower = lowerKeys(map);
        for (var i = 0; i < keys.length; i++) {
            if (lower[keys[i]]) { return cssValue(lower[keys[i]]); }
        }
        return '';
    }
    function pickType(typo, roles, prop) {
        var lower = lowerKeys(typo);
        for (var i = 0; i < roles.length; i++) {
            if (lower[roles[i]] && lower[roles[i]][prop]) { return cssValue(lower[roles[i]][prop]); }
        }
        // Scale naming (display-hero, headline-xl, body-md): match the leading segment.
        var keys = Object.keys(lower);
        for (var r = 0; r < roles.length; r++) {
            for (var k = 0; k < keys.length; k++) {
                if (keys[k].indexOf(roles[r]) === 0 && lower[keys[k]][prop]) {
                    return cssValue(lower[keys[k]][prop]);
                }
            }
        }
        return '';
    }
    function firstVal(map) {
        var ks = Object.keys(map);
        for (var i = 0; i < ks.length; i++) { if (map[ks[i]]) { return map[ks[i]]; } }
        return '';
    }

    function cssVars(t) {
        var v = {};
        var bg = pick(t.colors, ['bg', 'background', 'ground', 'surface', 'base', 'paper']);
        var ink = pick(t.colors, ['ink', 'text', 'foreground', 'fg', 'body', 'on-background', 'on-surface']);
        var accent = pick(t.colors, ['accent', 'primary', 'brand', 'action']);
        if (bg) { v['--nd-bg'] = bg; }
        if (ink) { v['--nd-ink'] = ink; }
        if (accent) { v['--nd-accent'] = accent; }
        var h = pickType(t.typography, ['heading', 'display', 'headline', 'title', 'hero'], 'fontFamily');
        var b = pickType(t.typography, ['body', 'text', 'paragraph'], 'fontFamily');
        var hw = pickType(t.typography, ['heading', 'display', 'headline', 'title', 'hero'], 'fontWeight');
        if (h) { v['--nd-font-heading'] = h; }
        if (b) { v['--nd-font-body'] = b; }
        if (hw) { v['--nd-weight-heading'] = hw; }
        var r = t.rounded.md || firstVal(t.rounded);
        if (r) { v['--nd-radius'] = cssLength(cssValue(r)); }
        var s = t.spacing.md || firstVal(t.spacing);
        if (s) { v['--nd-space'] = cssLength(cssValue(s)); }
        return v;
    }

    var NAMES = ['--nd-bg', '--nd-ink', '--nd-accent', '--nd-font-heading', '--nd-font-body', '--nd-weight-heading', '--nd-radius', '--nd-space'];

    function apply(raw) {
        var el = document.querySelector('[data-nd-preview]');
        if (!el) { return; }
        var v = cssVars(extract(raw));
        NAMES.forEach(function (name) {
            if (v[name]) { el.style.setProperty(name, v[name]); }
            else { el.style.removeProperty(name); }
        });
    }

    function wire(cm) {
        if (!cm) { return; }
        apply(cm.getValue());
        cm.on('change', function () { apply(cm.getValue()); });
    }

    if (window.novamiraDesignEditor) {
        wire(window.novamiraDesignEditor);
    } else {
        window.addEventListener('novamira-design-editor-ready', function () {
            wire(window.novamiraDesignEditor);
        });
        // Fallback: plain textarea if CodeMirror failed to load.
        var ta = document.getElementById('novamira-design-content');
        if (ta) {
            apply(ta.value);
            ta.addEventListener('input', function () { apply(ta.value); });
        }
    }
})();

// Previews declare the design's true fonts, but the dashboard only renders
// what the browser has. When a declared family is missing, say so on the
// preview and offer an explicit, user-initiated Google Fonts load.
(function () {
    'use strict';

    var l10n = window.novamiraDesignFontNote || {};
    var GENERIC = ['sans-serif', 'serif', 'monospace', 'system-ui', 'ui-sans-serif', 'ui-serif',
        'ui-monospace', 'ui-rounded', 'cursive', 'fantasy', '-apple-system', 'blinkmacsystemfont',
        'inherit', 'initial', 'unset'];
    var requested = {};

    function firstFamily(list) {
        if (!list) { return ''; }
        return list.split(',')[0].trim().replace(/^["']|["']$/g, '');
    }

    function isGeneric(name) {
        return GENERIC.indexOf(name.toLowerCase()) !== -1;
    }

    var measureCtx = null;

    // document.fonts.check() reports true for unknown families in Chrome, so
    // availability is detected by metrics: a family is present when text
    // measured as `"family", fallback` differs from the bare fallback for at
    // least one generic fallback.
    function available(name) {
        try {
            if (!measureCtx) {
                measureCtx = document.createElement('canvas').getContext('2d');
            }
            var probe = 'mmmmmmmmmmlliWWW@#0123';
            var differs = ['monospace', 'serif'].some(function (generic) {
                measureCtx.font = '32px ' + generic;
                var base = measureCtx.measureText(probe).width;
                measureCtx.font = '32px "' + name + '", ' + generic;
                return measureCtx.measureText(probe).width !== base;
            });
            return differs;
        } catch (e) {
            return true;
        }
    }

    function missingFamilies(preview) {
        var names = [];
        ['--nd-font-heading', '--nd-font-body'].forEach(function (prop) {
            var name = firstFamily(preview.style.getPropertyValue(prop));
            if (name && !isGeneric(name) && !available(name) && names.indexOf(name) === -1) {
                names.push(name);
            }
        });
        return names;
    }

    function loadFromGoogle(name) {
        if (!requested[name]) {
            requested[name] = new Promise(function (resolve) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://fonts.googleapis.com/css2?family='
                    + encodeURIComponent(name).replace(/%20/g, '+') + ':wght@400;600;700&display=swap';
                link.onload = function () {
                    document.fonts.load('16px "' + name + '"').then(
                        function () { resolve(available(name)); },
                        function () { resolve(false); }
                    );
                };
                link.onerror = function () { resolve(false); };
                document.head.appendChild(link);
            });
        }
        return requested[name];
    }

    function attachNote(preview, names) {
        var box = document.createElement('div');
        box.className = 'nd-preview-fontnote';
        var text = document.createElement('span');
        text.textContent = (l10n.missing
            || "This preview is not showing the design's real fonts (%s). The site itself displays them normally. To see them here, press:"
        ).replace('%s', names.join(', '));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button-link';
        btn.textContent = l10n.load || 'Preview with Google Fonts';
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            btn.disabled = true;
            Promise.all(names.map(loadFromGoogle)).then(function (results) {
                var still = names.filter(function (name, i) { return !results[i]; });
                if (still.length === 0) { box.remove(); return; }
                btn.remove();
                text.textContent = (l10n.notOnGoogle || 'Google Fonts does not have %s.').replace('%s', still.join(', '));
            });
        });
        box.appendChild(text);
        box.appendChild(btn);
        preview.appendChild(box);
    }

    function init() {
        if (!document.fonts || !document.fonts.check) { return; }
        document.fonts.ready.then(function () {
            Array.prototype.forEach.call(document.querySelectorAll('[data-nd-preview]'), function (preview) {
                var names = missingFamilies(preview);
                if (names.length) { attachNote(preview, names); }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// Copy the raw DESIGN.md from the detail view.
(function () {
    'use strict';

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* best effort */ }
        ta.remove();
    }

    function init() {
        var btn = document.querySelector('[data-nd-copy-design]');
        var source = document.querySelector('.nd-detail-md');
        if (!btn || !source) { return; }
        var label = btn.textContent;
        btn.addEventListener('click', function () {
            var text = source.textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
            } else {
                fallbackCopy(text);
            }
            var l10n = window.novamiraDesignFontNote || {};
            btn.textContent = l10n.copied || 'Copied';
            window.setTimeout(function () { btn.textContent = label; }, 1500);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
