/**
 * The native (non-iframe) chat widget for BHL_PollingChat — mounted by
 * live-player.js's own renderLiveSlot() whenever the REST status
 * response says chat_type === 'native' (an iframe-based chat, like
 * Owncast's own bundled one, needs nothing further; this is the one
 * that does). Same 2-4s polling interval as bh-streaming's own Jam
 * sessions — proven low-risk on ordinary shared hosting, not a
 * WebSocket/SSE relay this ecosystem has never needed before.
 */
window.BHLChatWidget = (function () {
    'use strict';

    var POLL_MS = 3000;

    function mount(container, streamId) {
        if (container.dataset.bhlChatMounted) return; // never double-mount if renderLiveSlot() re-runs
        container.dataset.bhlChatMounted = '1';

        container.innerHTML =
            '<div class="bhl-chat-log" id="bhl-chat-log"></div>' +
            (BHLData.loggedIn
                ? '<form class="bhl-chat-form" id="bhl-chat-form"><input type="text" id="bhl-chat-input" maxlength="300" placeholder="Say something…"><button type="submit">Send</button></form>'
                : '<p class="bhl-chat-login-notice">Log in to chat.</p>');

        var log = container.querySelector('#bhl-chat-log');
        var lastId = 0;
        var seenIds = {}; // belt-and-suspenders against a message ever rendering twice, regardless of cause
        var fetchInFlight = false;

        function appendMessages(messages) {
            messages.forEach(function (m) {
                if (seenIds[m.id]) return;
                seenIds[m.id] = true;
                var row = document.createElement('div');
                row.className = 'bhl-chat-message';
                row.innerHTML = '<strong></strong> <span></span>';
                row.querySelector('strong').textContent = m.display_name + ':';
                row.querySelector('span').textContent = m.message;
                log.appendChild(row);
                lastId = Math.max(lastId, m.id);
            });
            if (messages.length) log.scrollTop = log.scrollHeight;
        }

        // Two poll() calls can genuinely overlap in practice — the
        // interval's own scheduled tick and an explicit poll() right
        // after sending a message can both be in flight at once, both
        // reading the SAME lastId before either response lands and
        // updates it. fetchInFlight (a simple mutex, not a queue —
        // skipping an overlapping call is fine, the next interval tick
        // catches up) plus the seenIds guard above are the two
        // independent safeguards against the same message ever
        // rendering twice, found and fixed via a real browser test
        // that showed a duplicate on the very first message sent.
        function poll() {
            if (fetchInFlight) return;
            fetchInFlight = true;
            fetch(BHLData.rest + 'chat/' + streamId + '/messages?after_id=' + lastId)
                .then(function (r) { return r.json(); })
                .then(function (res) { if (res && res.success) appendMessages(res.messages || []); })
                .catch(function () {})
                .finally(function () { fetchInFlight = false; });
        }

        poll();
        var interval = setInterval(poll, POLL_MS);
        // Stop polling once the container is removed from the page
        // (e.g. the stream goes offline and renderLiveSlot() swaps the
        // slot back to the offline notice) — a plain MutationObserver
        // rather than anything heavier, since this only ever needs to
        // notice "am I still attached to the document."
        new MutationObserver(function () {
            if (!document.body.contains(container)) clearInterval(interval);
        }).observe(document.body, { childList: true, subtree: true });

        var form = container.querySelector('#bhl-chat-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = container.querySelector('#bhl-chat-input');
                var message = input.value.trim();
                if (!message) return;
                input.disabled = true;
                fetch(BHLData.rest + 'chat/' + streamId + '/messages', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BHLData.nonce },
                    body: JSON.stringify({ message: message }),
                }).then(function (r) { return r.json(); }).then(function () {
                    input.value = '';
                    poll();
                }).catch(function () {}).finally(function () { input.disabled = false; input.focus(); });
            });
        }
    }

    return { mount: mount };
})();
