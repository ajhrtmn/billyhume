(function () {
    'use strict';

    var state = { videos: [], filtered: [] };

    function el(id) { return document.getElementById(id); }

    function fetchVideos() {
        return fetch(BHVData.rest + 'videos')
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

    function playVideo(v) {
        var wrap = el('bhv-player-wrap');
        var videoEl = el('bhv-video-el');
        wrap.style.display = '';
        videoEl.src = v.url;
        videoEl.play().catch(function () {});
        el('bhv-now-title').textContent = v.title;
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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
