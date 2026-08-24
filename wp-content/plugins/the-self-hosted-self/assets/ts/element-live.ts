/**
 * element-live.ts — DESIGN-SUITE-UNIFICATION-PLAN.md §3.2 v1, "runtime
 * re-resolution." This is deliberately a small transport + DOM-patch
 * layer, NOT a second resolver: it only ever calls POST ous/v1/elements/
 * resolve, which re-runs the real, single-authority BH_Element_Data::
 * resolve() server-side (class-element.php's rest_resolve()) and hands
 * back already-resolved, already-sanitized values. This file never
 * reads or reasons about a binding descriptor itself.
 *
 * Scope, honestly: finds every '[data-bhel-live="1"]' wrapper already
 * on the page (BH_Element::wrap_placement_html()'s new opt-in marker —
 * only types that set 'live' => true in register_type() get it), and
 * on an interval, re-fetches that placement's bound attrs and patches
 * the matching '[data-bhel-bind="<key>"]' child node's text content in
 * place. Nothing here handles insertion/removal of whole elements,
 * nested live elements inside a live element's own children, or any
 * data-binding kind other than a plain scalar text patch — those are
 * all real gaps, left for a later pass once a real consumer needs them
 * (mirrors this whole ecosystem's "don't build unused surface area"
 * convention).
 *
 * TypeScript pilot, compiled with plain `tsc` to assets/js/element-live.js
 * — see search.ts's docblock for the general TS-pilot conventions this
 * follows (no bundler, one file in/one file out, compiled output
 * committed).
 */

interface BhElLiveConfig {
    restUrl: string;
    nonce: string;
    intervalMs?: number;
}

interface BhElLiveWindow extends Window {
    bhElLiveConfig?: BhElLiveConfig;
}

interface BhElResolveResponse {
    attrs?: Record<string, unknown>;
}

(function () {
    'use strict';
    var win = window as BhElLiveWindow;
    if (typeof window === 'undefined' || !win.bhElLiveConfig) return;

    var cfg = win.bhElLiveConfig;
    var intervalMs = cfg.intervalMs || 20000;

    function refreshOne(el: Element): void {
        var id = parseInt(el.getAttribute('data-placement-id') || '', 10);
        if (!id) return;

        fetch(cfg.restUrl + 'elements/resolve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ placement_id: id }),
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? (r.json() as Promise<BhElResolveResponse>) : null; })
            .then(function (data) {
                if (!data || !data.attrs) return;
                Object.keys(data.attrs).forEach(function (key) {
                    var target = el.querySelector('[data-bhel-bind="' + key + '"]');
                    // textContent, never innerHTML — the resolved value is a
                    // plain scalar (per the 'scalar' source kind this pass's
                    // only live consumer uses), and this keeps the same
                    // "server escapes for its context, this file only ever
                    // writes to a text node" boundary BH_Element_Data's own
                    // docblock describes for render_placement().
                    if (target) target.textContent = String((data.attrs as Record<string, unknown>)[key]);
                });
            })
            // Silent on failure — a stale-but-correct-at-load value staying
            // on screen is a better failure mode than a visible error for a
            // background refresh nobody explicitly asked for right now.
            .catch(function () {});
    }

    function boot(): void {
        var nodes = document.querySelectorAll('[data-bhel-live="1"]');
        if (!nodes.length) return;
        nodes.forEach(function (el) {
            setInterval(function () { refreshOne(el); }, intervalMs);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
