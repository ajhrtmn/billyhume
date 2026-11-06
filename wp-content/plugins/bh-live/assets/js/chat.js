/**
 * Native polling chat widget for BHL_PollingChat, mounted by live-player.js when
 * chat_type === 'native'.
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
                // Only relayed (non-'ecosystem') messages get a source tag.
                var tag = '';
                if (m.source && m.source !== 'ecosystem') {
                    row.classList.add('bhl-chat-message--' + m.source);
                    tag = '<span class="bhl-chat-source-tag"></span> ';
                }
                row.innerHTML = tag + '<strong></strong> <span class="bhl-chat-text"></span>';
                if (tag) row.querySelector('.bhl-chat-source-tag').textContent = m.source === 'twitch' ? 'Twitch' : 'YouTube';
                row.querySelector('strong').textContent = m.display_name + ':';
                row.querySelector('.bhl-chat-text').textContent = m.message;
                log.appendChild(row);
                lastId = Math.max(lastId, m.id);
            });
            if (messages.length) log.scrollTop = log.scrollHeight;
        }

        // WHY: an interval tick and a post-send poll() can overlap and read the same lastId;
        // fetchInFlight plus seenIds prevent duplicate rendering.
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
        // Stop polling once the container leaves the document.
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
