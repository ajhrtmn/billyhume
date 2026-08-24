"use strict";
/**
 * [ous_search] shortcode's live-as-you-type search box (class-search.php).
 * TypeScript pilot for this ecosystem's TS migration — compiled with plain
 * `tsc` (no bundler) to assets/js/search.js, which is what class-search.php
 * actually enqueues via wp_enqueue_script(). Run `npm run build:the-self-hosted-self`
 * after editing this file; the compiled output is committed, since the live
 * FTP-deployed site runs no build step at all.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.ousSearchConfig || {};
        document.querySelectorAll('.ous-search').forEach(function (wrap) {
            var input = wrap.querySelector('.ous-search-input');
            var resultsEl = wrap.querySelector('.ous-search-results');
            if (!input || !resultsEl)
                return;
            var debounceTimer = null;
            var currentRequest = null;
            // Every field below comes from the REST response — escaped
            // before insertion so a track/course/contest title an artist
            // typed can never be interpreted as markup.
            function esc(s) {
                var d = document.createElement('div');
                d.textContent = String(s == null ? '' : s);
                return d.innerHTML;
            }
            function render(results) {
                if (!results.length) {
                    resultsEl.innerHTML = '<p class="ous-search-empty">No results.</p>';
                    return;
                }
                var html = '<ul class="ous-search-list">';
                results.forEach(function (r) {
                    html += '<li class="ous-search-item">'
                        + '<a href="' + esc(r.url) + '">'
                        + '<span class="ous-search-item-type">' + esc(r.type) + '</span>'
                        + '<span class="ous-search-item-title">' + esc(r.title) + '</span>'
                        + (r.excerpt ? '<span class="ous-search-item-excerpt">' + esc(r.excerpt) + '</span>' : '')
                        + '</a></li>';
                });
                html += '</ul>';
                resultsEl.innerHTML = html;
            }
            input.addEventListener('input', function () {
                var q = input.value.trim();
                if (debounceTimer)
                    clearTimeout(debounceTimer);
                if (q.length < 2) {
                    resultsEl.innerHTML = '';
                    return;
                }
                debounceTimer = setTimeout(function () {
                    if (currentRequest)
                        currentRequest.abort();
                    var controller = new AbortController();
                    currentRequest = controller;
                    resultsEl.setAttribute('aria-busy', 'true');
                    fetch(cfg.restUrl + '?q=' + encodeURIComponent(q), { signal: controller.signal })
                        .then(function (res) { return res.json(); })
                        .then(function (body) {
                        resultsEl.removeAttribute('aria-busy');
                        render(body.results || []);
                    })
                        .catch(function (err) {
                        if (err instanceof Error && err.name === 'AbortError')
                            return;
                        resultsEl.removeAttribute('aria-busy');
                        resultsEl.innerHTML = '<p class="ous-search-empty">Search failed — try again.</p>';
                    });
                }, 250);
            });
        });
    });
})();
