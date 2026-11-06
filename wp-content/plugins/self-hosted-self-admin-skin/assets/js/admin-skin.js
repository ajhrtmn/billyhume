/*
 * Admin Skin — The Self-Hosted Self. Hand-written vanilla JS, no jQuery.
 *  1. Light/dark toggle (admin-bar button).
 *  2. Cmd/Ctrl+K command palette over the capability-filtered admin menu
 *     localized from PHP (shsasMenu.items).
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

    // View Transitions circular wipe from the click point. Falls back to an
    // instant swap when unsupported or under prefers-reduced-motion.
    function toggleTheme(originEvent) {
        var next = currentTheme() === 'light' ? 'dark' : 'light';
        function applyThemeChange() {
            root.setAttribute('data-shsas-theme', next);
            try { window.localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* non-fatal — toggle still works for this page view */ }
        }
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var canWipe = typeof document.startViewTransition === 'function' && originEvent && !reducedMotion;
        if (canWipe) {
            var x = originEvent.clientX, y = originEvent.clientY;
            // Radius covering the farthest corner from the click.
            var endRadius = Math.hypot(
                Math.max(x, window.innerWidth - x),
                Math.max(y, window.innerHeight - y)
            );
            var transition = document.startViewTransition(applyThemeChange);
            transition.ready.then(function () {
                document.documentElement.animate(
                    { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + endRadius + 'px at ' + x + 'px ' + y + 'px)'] },
                    { duration: 520, easing: 'ease-in-out', pseudoElement: '::view-transition-new(root)' }
                );
            });
        } else {
            applyThemeChange();
        }
        var icon = document.querySelector('#wp-admin-bar-shsas-theme-toggle .shsas-toggle-icon');
        if (icon) {
            // Spin timing only: the sun/moon artwork is a CSS mask keyed off
            // data-shsas-theme, so writing a glyph here would fight it.
            icon.classList.add('shsas-spin');
            setTimeout(function () { icon.classList.remove('shsas-spin'); }, 320);
        }
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest && e.target.closest('#wp-admin-bar-shsas-theme-toggle');
        if (!toggle) return;
        e.preventDefault();
        toggleTheme(e);
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
        // Subsequence match: every query character appears in order.
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

    /* ---------------- Wallet-stack default collapse ----------------
     * On a visitor's first mobile-width load only (localStorage-gated),
     * collapse every postbox but the first per column by clicking core's
     * .handlediv, so the closed state persists via WP's own user-meta save.
     */
    (function applyWalletDefault() {
        function isMobileWidth() { return window.innerWidth <= 782; }
        var APPLIED_KEY = 'shsas-wallet-default-applied';
        var applied;
        try { applied = window.localStorage.getItem(APPLIED_KEY); } catch (e) { applied = '1'; /* storage blocked — don't repeat every load */ }
        if (applied === '1' || !isMobileWidth()) return;

        // WHY 'load', not DOMContentLoaded: core's postboxes.js may not
        // have bound its .handlediv handler yet, and the click would no-op.
        window.addEventListener('load', function () {
            try { window.localStorage.setItem(APPLIED_KEY, '1'); } catch (e) { /* non-fatal — worst case this runs again next load */ }
            document.querySelectorAll('.postbox-container').forEach(function (container) {
                var boxes = container.querySelectorAll('.postbox');
                boxes.forEach(function (box, i) {
                    if (i === 0 || box.classList.contains('closed')) return;
                    var handle = box.querySelector('.handlediv');
                    if (handle) handle.click();
                });
            });
        });
    })();

    /* The 'pagereveal' listener lives inline in <head>
     * (shsas_print_nav_depth_script()); this footer script runs too late. */
})();
