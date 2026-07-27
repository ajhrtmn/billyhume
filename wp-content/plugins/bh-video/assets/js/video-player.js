(function () {
    'use strict';

    var state = { videos: [], filtered: [] };

    function el(id) { return document.getElementById(id); }

    function fetchVideos() {
        // WordPress's REST cookie-auth deliberately treats a request as
        // anonymous unless X-WP-Nonce is present (rest_cookie_check_errors()
        // sets current user to 0 with no nonce at all, precisely to stop a
        // random page from silently riding a visitor's cookie) — so a
        // logged-in listener's resumeSeconds would always read back 0
        // without this header, even though the DB value is really there.
        return fetch(BHVData.rest + 'videos', { headers: { 'X-WP-Nonce': BHVData.nonce } })
            .then(function (r) { return r.json(); })
            .then(function (res) { return (res && res.success) ? res.videos : []; })
            .catch(function () { return []; });
    }

    function populateGenreFilter(videos) {
        var select = el('bhv-genre-filter');
        var seen = {};
        videos.forEach(function (v) {
            (v.genres || []).forEach(function (g) { seen[g] = true; });
        });
        Object.keys(seen).sort().forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g; opt.textContent = g;
            select.appendChild(opt);
        });
    }

    function applyFilters() {
        var q = (el('bhv-search').value || '').toLowerCase();
        var genre = el('bhv-genre-filter').value;
        state.filtered = state.videos.filter(function (v) {
            if (q && v.title.toLowerCase().indexOf(q) === -1) return false;
            if (genre && (v.genres || []).indexOf(genre) === -1) return false;
            return true;
        });
        renderGrid();
    }

    function renderGrid() {
        var grid = el('bhv-grid');
        if (!state.filtered.length) {
            grid.innerHTML = '<div class="bhv-empty">No videos match.</div>';
            return;
        }
        grid.innerHTML = '';
        state.filtered.forEach(function (v) {
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'bhv-card';
            card.innerHTML = '<div class="bhv-card-thumb"></div>'
                + '<div class="bhv-card-title"></div>'
                + '<div class="bhv-card-meta"></div>';
            card.querySelector('.bhv-card-title').textContent = v.title;
            card.querySelector('.bhv-card-meta').textContent = (v.genres || []).join(', ');
            card.addEventListener('click', function () { playVideo(v); });
            grid.appendChild(card);
        });
    }

    var lastResumeSave = 0;
    var currentVideoId = null;

    function renderChapters(v) {
        var chaptersEl = el('bhv-chapters');
        var videoEl = el('bhv-video-el');
        var chapters = v && v.chapters;
        if (!chapters || !chapters.length) { chaptersEl.style.display = 'none'; chaptersEl.innerHTML = ''; return; }
        chaptersEl.style.display = '';
        chaptersEl.innerHTML = '';
        chapters.forEach(function (c) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bhv-chapter-marker';
            var mm = Math.floor(c.time / 60), ss = c.time % 60;
            btn.textContent = mm + ':' + (ss < 10 ? '0' : '') + ss + ' ' + c.label;
            btn.addEventListener('click', function () { videoEl.currentTime = c.time; });
            chaptersEl.appendChild(btn);
        });
    }

    // Only seeks once metadata is available, and only on the FIRST play
    // this page load — never autoplays, just picks up where a logged-in
    // listener already left off (same rule as bh-streaming's own
    // maybeResumeSeek in player.js).
    function maybeResumeSeek(v, videoEl) {
        if (!v.resumeSeconds || v.resumeSeconds < 5) return;
        function onLoaded() {
            if (v.resumeSeconds < (videoEl.duration || Infinity) - 5) videoEl.currentTime = v.resumeSeconds;
            videoEl.removeEventListener('loadedmetadata', onLoaded);
        }
        videoEl.addEventListener('loadedmetadata', onLoaded);
    }

    // Throttled to once per 10s of real playback, same as bh-streaming's
    // own maybeSaveResumePosition — a convenience, not something that
    // needs sub-second accuracy.
    function maybeSaveResumePosition(videoEl) {
        if (!window.BHVData || !BHVData.loggedIn || !currentVideoId) return;
        if (videoEl.paused) return;
        var now = Date.now();
        if (now - lastResumeSave < 10000) return;
        lastResumeSave = now;
        fetch(BHVData.rest + 'videos/' + currentVideoId + '/resume', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BHVData.nonce },
            body: JSON.stringify({ seconds: Math.floor(videoEl.currentTime) }),
        }).catch(function () {});
    }

    function playVideo(v) {
        var wrap = el('bhv-player-wrap');
        var videoEl = el('bhv-video-el');
        wrap.style.display = '';
        currentVideoId = v.id;
        videoEl.src = v.url;
        videoEl.play().catch(function () {});
        el('bhv-now-title').textContent = v.title;
        renderChapters(v);
        maybeResumeSeek(v, videoEl);
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function init() {
        if (!el('bhv-app') || typeof BHVData === 'undefined') return;
        fetchVideos().then(function (videos) {
            state.videos = videos;
            state.filtered = videos;
            populateGenreFilter(videos);
            renderGrid();
        });
        el('bhv-search').addEventListener('input', applyFilters);
        el('bhv-genre-filter').addEventListener('change', applyFilters);
        el('bhv-video-el').addEventListener('timeupdate', function () {
            maybeSaveResumePosition(el('bhv-video-el'));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
