(function () {
    'use strict';

    function el(id) { return document.getElementById(id); }

    function renderLiveSlot(status) {
        var slot = el('bhl-live-slot');
        var chatSlot = el('bhl-chat-slot');
        if (status.online) {
            slot.innerHTML = '<span class="bhl-live-badge">LIVE</span><h3>' + escapeHtml(status.title || '') + '</h3>' + status.embed_html;
            if (status.chat_embed_html) {
                chatSlot.innerHTML = status.chat_embed_html;
                chatSlot.style.display = '';
            }
        } else {
            slot.innerHTML = '<div class="bhl-offline-notice">Not live right now — check back later, or watch a past stream below.</div>';
            chatSlot.style.display = 'none';
        }
    }

    function renderReplays(replays) {
        var heading = el('bhl-replays-heading');
        var grid = el('bhl-replay-grid');
        if (!replays.length) { heading.style.display = 'none'; grid.innerHTML = ''; return; }
        heading.style.display = '';
        grid.innerHTML = '';
        replays.forEach(function (r) {
            var card = document.createElement('div');
            card.className = 'bhl-replay-card';
            card.innerHTML = '<video src="' + escapeAttr(r.url) + '" preload="metadata"></video>'
                + '<div class="bhl-replay-title"></div><div class="bhl-replay-meta"></div>';
            card.querySelector('.bhl-replay-title').textContent = r.title;
            card.querySelector('.bhl-replay-meta').textContent = r.started_at || '';
            card.addEventListener('click', function () {
                var v = card.querySelector('video');
                v.setAttribute('controls', '');
                v.play().catch(function () {});
            });
            grid.appendChild(card);
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
    function escapeAttr(s) { return (s || '').replace(/"/g, '&quot;'); }

    function init() {
        if (!el('bhl-app') || typeof BHLData === 'undefined') return;
        fetch(BHLData.rest + 'status').then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.success) renderLiveSlot(res);
        }).catch(function () {});
        fetch(BHLData.rest + 'replays').then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.success) renderReplays(res.replays || []);
        }).catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
