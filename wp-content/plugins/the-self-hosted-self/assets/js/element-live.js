"use strict";
/**
 * Runtime re-resolution for '[data-bhel-live="1"]' element wrappers: on an
 * interval, POSTs ous/v1/elements/resolve (the server-side resolver) and
 * patches each '[data-bhel-bind="<key>"]' child's text. Transport and text
 * patching only; never a second resolver.
 */
(function () {
    'use strict';
    var win = window;
    if (typeof window === 'undefined' || !win.bhElLiveConfig)
        return;
    var cfg = win.bhElLiveConfig;
    var intervalMs = cfg.intervalMs || 20000;
    function refreshOne(el) {
        var id = parseInt(el.getAttribute('data-placement-id') || '', 10);
        if (!id)
            return;
        fetch(cfg.restUrl + 'elements/resolve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ placement_id: id }),
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
            if (!data || !data.attrs)
                return;
            Object.keys(data.attrs).forEach(function (key) {
                var target = el.querySelector('[data-bhel-bind="' + key + '"]');
                // WHY: textContent, never innerHTML; values are plain scalars.
                if (target)
                    target.textContent = String(data.attrs[key]);
            });
        })
            // Silent on failure; the value from page load stays on screen.
            .catch(function () { });
    }
    function boot() {
        var nodes = document.querySelectorAll('[data-bhel-live="1"]');
        if (!nodes.length)
            return;
        nodes.forEach(function (el) {
            setInterval(function () { refreshOne(el); }, intervalMs);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    }
    else {
        boot();
    }
})();
