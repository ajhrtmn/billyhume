/*
 * Admin Skin — The Self-Hosted Self. Vanilla JS, no build step, no
 * dependency on jQuery or any admin script this doesn't explicitly
 * enqueue itself (matches this whole ecosystem's own "no build step"
 * convention, even though this plugin is otherwise fully standalone/
 * portable) — two independent features:
 *
 *  1. Light/dark toggle (admin-bar button, top of every screen).
 *  2. A Cmd/Ctrl+K command palette over the real, capability-filtered
 *     admin menu PHP already localized (shsasMenu.items) — genuinely
 *     useful on a big multi-plugin dashboard like this one, not just
 *     decoration.
 */
(function () {
    'use strict';

    /* ---------------- theme toggle ---------------- */

    var STORAGE_KEY = 'shsas-theme';
    var root = document.documentElement;

    function applyStoredTheme() {
        var stored = null;
        try { stored = window.localStorage.getItem(STORAGE_KEY); } catch (e) { /* localStorage blocked — fall through to prefers-color-scheme, no crash */ }
        if (stored === 'light' || stored === 'dark') {
            root.setAttribute('data-shsas-theme', stored);
        }
    }
    applyStoredTheme();

    function currentTheme() {
        var attr = root.getAttribute('data-shsas-theme');
        if (attr) return attr;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    function toggleTheme() {
        var next = currentTheme() === 'light' ? 'dark' : 'light';
        root.setAttribute('data-shsas-theme', next);
        try { window.localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* non-fatal — toggle still works for this page view */ }
        var icon = document.querySelector('#wp-admin-bar-shsas-theme-toggle .shsas-toggle-icon');
        if (icon) {
            // A real transition instead of an instant swap: spin the icon
            // out and let it spin back in. The sun/moon ARTWORK itself is
            // now a CSS mask keyed off :root[data-shsas-theme] (set on the
            // line above), not a text glyph this function writes — so the
            // icon swap happens via CSS the moment the attribute flips,
            // and this function only owns the spin timing. Previously it
            // wrote a unicode character here; that would now fight the
            // mask (a stray glyph rendering underneath it), so it's
            // deliberately gone rather than left as dead-but-harmless.
            icon.classList.add('shsas-spin');
            setTimeout(function () { icon.classList.remove('shsas-spin'); }, 320);
        }
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest && e.target.closest('#wp-admin-bar-shsas-theme-toggle');
        if (!toggle) return;
        e.preventDefault();
        toggleTheme();
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('#wp-admin-bar-shsas-palette-trigger');
        if (!trigger) return;
        e.preventDefault();
        openPalette();
    });

    /* ---------------- command palette ---------------- */

    var items = (window.shsasMenu && window.shsasMenu.items) || [];
    var backdrop = null;
    var input = null;
    var list = null;
    var selectedIndex = 0;
    var filtered = [];

    function fuzzyScore(query, label) {
        // Cheap, real-enough fuzzy match: every character of the query
        // must appear in order somewhere in the label — not a full
        // Levenshtein/weighted algorithm, but genuinely useful for a
        // menu list this size (dozens of items, not thousands), and
        // zero dependencies to get there.
        query = query.toLowerCase();
        label = label.toLowerCase();
        if (query === '') return 0;
        var qi = 0;
        var score = 0;
        var lastMatch = -1;
        for (var i = 0; i < label.length && qi < query.length; i++) {
            if (label[i] === query[qi]) {
                score += (lastMatch === i - 1) ? 3 : 1; // reward consecutive matches
                lastMatch = i;
                qi++;
            }
        }
        if (qi < query.length) return -1; // not all query characters found — no match
        // Prefix matches rank highest of all.
        if (label.indexOf(query) === 0) score += 20;
        return score;
    }

    function render() {
        var query = input.value;
        filtered = items
            .map(function (item) { return { item: item, score: fuzzyScore(query, item.label + ' ' + item.parent) }; })
            .filter(function (r) { return query === '' || r.score >= 0; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, 30)
            .map(function (r) { return r.item; });

        selectedIndex = 0;
        list.innerHTML = '';

        if (!filtered.length) {
            var empty = document.createElement('li');
            empty.className = 'shsas-palette-empty';
            empty.textContent = 'No matching page.';
            list.appendChild(empty);
            return;
        }

        filtered.forEach(function (item, i) {
            var li = document.createElement('li');
            li.className = 'shsas-palette-item';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', i === selectedIndex ? 'true' : 'false');
            li.dataset.index = String(i);

            var labelSpan = document.createElement('span');
            labelSpan.textContent = item.label;
            li.appendChild(labelSpan);

            if (item.parent) {
                var parentSpan = document.createElement('span');
                parentSpan.className = 'shsas-palette-parent';
                parentSpan.textContent = item.parent;
                li.appendChild(parentSpan);
            }

            li.addEventListener('mouseenter', function () { setSelected(i); });
            li.addEventListener('click', function () { go(item); });
            list.appendChild(li);
        });
    }

    function setSelected(i) {
        selectedIndex = i;
        Array.prototype.forEach.call(list.children, function (li, idx) {
            li.setAttribute('aria-selected', idx === i ? 'true' : 'false');
            if (idx === i) li.scrollIntoView({ block: 'nearest' });
        });
    }

    function go(item) {
        if (item && item.url) window.location.href = item.url;
        closePalette();
    }

    function openPalette() {
        if (backdrop || !items.length) return;
        backdrop = document.createElement('div');
        backdrop.className = 'shsas-palette-backdrop';

        var palette = document.createElement('div');
        palette.className = 'shsas-palette';
        palette.setAttribute('role', 'dialog');
        palette.setAttribute('aria-label', 'Jump to admin page');

        input = document.createElement('input');
        input.className = 'shsas-palette-input';
        input.type = 'text';
        input.placeholder = 'Jump to…';
        input.setAttribute('aria-label', 'Search admin pages');
        input.autocomplete = 'off';

        list = document.createElement('ul');
        list.className = 'shsas-palette-list';
        list.setAttribute('role', 'listbox');

        var hint = document.createElement('div');
        hint.className = 'shsas-palette-hint';
        hint.innerHTML = '<span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span><span><kbd>&crarr;</kbd> open</span><span><kbd>Esc</kbd> close</span>';

        palette.appendChild(input);
        palette.appendChild(list);
        palette.appendChild(hint);
        backdrop.appendChild(palette);
        document.body.appendChild(backdrop);

        backdrop.addEventListener('mousedown', function (e) {
            if (e.target === backdrop) closePalette();
        });
        input.addEventListener('input', render);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (filtered.length) setSelected((selectedIndex + 1) % filtered.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (filtered.length) setSelected((selectedIndex - 1 + filtered.length) % filtered.length); }
            else if (e.key === 'Enter') { e.preventDefault(); if (filtered[selectedIndex]) go(filtered[selectedIndex]); }
            else if (e.key === 'Escape') { e.preventDefault(); closePalette(); }
        });

        render();
        input.focus();
    }

    function closePalette() {
        if (!backdrop) return;
        backdrop.remove();
        backdrop = null; input = null; list = null;
    }

    document.addEventListener('keydown', function (e) {
        var isCmdK = (e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K');
        if (isCmdK) {
            e.preventDefault();
            backdrop ? closePalette() : openPalette();
        }
    });
})();
