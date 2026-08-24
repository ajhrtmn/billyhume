/**
 * [ous_search] shortcode's live-as-you-type search box (class-search.php).
 * TypeScript pilot for this ecosystem's TS migration — compiled with plain
 * `tsc` (no bundler) to assets/js/search.js, which is what class-search.php
 * actually enqueues via wp_enqueue_script(). Run `npm run build:the-self-hosted-self`
 * after editing this file; the compiled output is committed, since the live
 * FTP-deployed site runs no build step at all.
 */

interface OusSearchConfig {
    restUrl?: string;
}

interface OusSearchResult {
    url: string;
    type: string;
    title: string;
    excerpt?: string;
}

interface OusSearchResponse {
    results?: OusSearchResult[];
}

interface OusSearchWindow extends Window {
    ousSearchConfig?: OusSearchConfig;
}

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var cfg: OusSearchConfig = (window as OusSearchWindow).ousSearchConfig || {};

        document.querySelectorAll<HTMLElement>('.ous-search').forEach(function (wrap) {
            var input = wrap.querySelector<HTMLInputElement>('.ous-search-input');
            var resultsEl = wrap.querySelector<HTMLElement>('.ous-search-results');
            if (!input || !resultsEl) return;

            var debounceTimer: ReturnType<typeof setTimeout> | null = null;
            var currentRequest: AbortController | null = null;

            // Every field below comes from the REST response — escaped
            // before insertion so a track/course/contest title an artist
            // typed can never be interpreted as markup.
            function esc(s: unknown): string {
                var d = document.createElement('div');
                d.textContent = String(s == null ? '' : s);
                return d.innerHTML;
            }

            function render(results: OusSearchResult[]): void {
                if (!results.length) {
                    resultsEl!.innerHTML = '<p class="ous-search-empty">No results.</p>';
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
                resultsEl!.innerHTML = html;
            }

            input.addEventListener('input', function () {
                var q = input!.value.trim();
                if (debounceTimer) clearTimeout(debounceTimer);
                if (q.length < 2) {
                    resultsEl!.innerHTML = '';
                    return;
                }
                debounceTimer = setTimeout(function () {
                    if (currentRequest) currentRequest.abort();
                    var controller = new AbortController();
                    currentRequest = controller;
                    resultsEl!.setAttribute('aria-busy', 'true');
                    fetch(cfg.restUrl + '?q=' + encodeURIComponent(q), { signal: controller.signal })
                        .then(function (res) { return res.json() as Promise<OusSearchResponse>; })
                        .then(function (body) {
                            resultsEl!.removeAttribute('aria-busy');
                            render(body.results || []);
                        })
                        .catch(function (err) {
                            if (err instanceof Error && err.name === 'AbortError') return;
                            resultsEl!.removeAttribute('aria-busy');
                            resultsEl!.innerHTML = '<p class="ous-search-empty">Search failed — try again.</p>';
                        });
                }, 250);
            });
        });
    });
})();
