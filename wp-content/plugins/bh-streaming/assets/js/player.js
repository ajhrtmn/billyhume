"use strict";
// BH Streaming player: state, per-view rendering, playback/queue engine,
// Media Session, likes/playlists/volume, EQ/visualizer, and Jam.
// Edit this .ts and recompile; assets/js/player.js is generated.
// BHSData/BHCoreToast are globals from wp_localize_script/class-toast.php.
(function () {
    var app = document.getElementById('bhs-app');
    if (!app)
        return;
    // Non-null cast helper for IDs that the shortcode template always renders.
    // Genuinely optional elements use a plain getElementById + null guard.
    function byId(id) {
        return document.getElementById(id);
    }
    var rest = (BHSData && BHSData.rest) || '';
    var identityRest = (BHSData && BHSData.identity) || '';
    var nonce = (BHSData && BHSData.nonce) || '';
    var loggedIn = !!(BHSData && BHSData.loggedIn);
    var library = byId('bhs-library');
    var relatedBox = byId('bhs-related');
    var relatedList = byId('bhs-related-list');
    var nowplaying = byId('bhs-nowplaying');
    var artEl = byId('bhs-np-art');
    var titleEl = byId('bhs-np-title');
    var artistEl = byId('bhs-np-artist');
    var playPauseBtn = byId('bhs-playpause');
    var likeBtn = byId('bhs-like');
    var seek = byId('bhs-seek');
    var volume = byId('bhs-volume');
    var queuePanel = byId('bhs-queue-panel');
    var queueList = byId('bhs-queue-list');
    var playlistPicker = byId('bhs-playlist-picker');
    var playlistPickerList = byId('bhs-playlist-picker-list');
    var searchInput = byId('bhs-search');
    var genreFilter = byId('bhs-genre-filter');
    var lyricsPanel = byId('bhs-lyrics-panel');
    var lyricsBody = byId('bhs-lyrics-body');
    var lyricsToggleBtn = byId('bhs-lyrics-toggle');
    var qualityPanel = byId('bhs-quality-panel');
    var qualityList = byId('bhs-quality-list');
    var qualityToggleBtn = document.getElementById('bhs-quality-toggle');
    var eqPanel = byId('bhs-eq-panel');
    var eqToggleBtn = byId('bhs-eq-toggle');
    var vizToggleBtn = byId('bhs-viz-toggle');
    var vizCanvas = byId('bhs-visualizer');
    var importModal = byId('bhs-import-modal');
    var allTracks = [];
    var releases = [];
    var likedIds = [];
    var myPlaylists = [];
    var currentView = 'all';
    var queue = []; // the ordered list of track objects currently being played through
    var queueIndex = -1;
    // Jam state (server side: class-jam.php). While active, a non-host's
    // transport is driven by jamApplyState(), not local clicks.
    var jam = {
        active: false, isHost: false, code: null, controlMode: 'host',
        pollTimer: null, suppressNextPositionSync: false,
    };
    // WHY: the primary playback element is never passed to
    // createMediaElementSource(), so a non-CORS external host can't silence
    // playback. EQ/visualizer use a separate shadow element (see below).
    var audio = new Audio();
    var currentQuality = null; // null = whatever track.url already is (the track's own default)
    var currentTrackLyrics = null; // parsed LRC lines for the track currently playing, if any
    var visualizerOn = false;
    function esc(s) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return map[c];
        });
    }
    function authHeaders(extra) {
        var h = Object.assign({ 'X-WP-Nonce': nonce }, extra || {});
        return h;
    }
    // BHCoreToast is loaded by core on every front-end page; guard anyway.
    function notify(msg, isError) {
        if (typeof BHCoreToast !== 'undefined' && BHCoreToast) {
            BHCoreToast.show(msg, isError ? 'error' : 'success');
        }
        else {
            alert(msg);
        }
    }
    /* ---------- filtering (search + genre, applied to the "All Tracks" view) ---------- */
    function filteredTracks() {
        var q = searchInput.value.trim().toLowerCase();
        var genre = genreFilter.value;
        return allTracks.filter(function (t) {
            if (genre && t.genres.indexOf(genre) === -1)
                return false;
            if (q && t.title.toLowerCase().indexOf(q) === -1 && t.artist.toLowerCase().indexOf(q) === -1)
                return false;
            return true;
        });
    }
    function trackCardHtml(t) {
        var liked = likedIds.indexOf(t.id) !== -1;
        // External tracks depend on another site's host; show 'degraded'/'down'
        // honestly but never hide the catalog entry.
        var healthBadge = '';
        if (t.source_health === 'down')
            healthBadge = '<span class="bhs-badge bhs-badge-down">Unavailable</span>';
        else if (t.source_health === 'degraded')
            healthBadge = '<span class="bhs-badge bhs-badge-degraded">Unreliable</span>';
        return '<button type="button" class="bhs-card' + (t.locked ? ' bhs-card-locked' : '') + '" data-id="' + t.id + '">'
            + '<img src="' + esc(t.artwork) + '" alt="" class="bhs-card-art">'
            + (t.external ? '<span class="bhs-badge">Featured</span>' : '')
            + (t.locked ? '<span class="bhs-badge bhs-badge-locked">&#128274;</span>' : '')
            + healthBadge
            + '<div class="bhs-card-title">' + esc(t.title) + '</div>'
            + '<div class="bhs-card-artist">' + esc(t.artist) + '</div>'
            + '<div class="bhs-card-meta">' + (t.locked ? 'Supporters only' : ((liked ? '&#9829;' : '') + ' ' + t.plays + ' plays')) + '</div>'
            + '</button>';
    }
    /* ---------- view rendering ---------- */
    function renderView() {
        relatedBox.style.display = 'none';
        if (currentView === 'all') {
            var tracks = filteredTracks();
            // Shared empty-state component (BHSData.emptyState*); filtered vs.
            // zero-data is decided by whether a search term or genre is active.
            var isFiltered = !!(searchInput.value.trim() || genreFilter.value);
            library.innerHTML = tracks.length
                ? '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>'
                : (isFiltered ? (BHSData && BHSData.emptyStateFiltered) : (BHSData && BHSData.emptyStateZero)) || '<p class="bhs-empty">No tracks match.</p>';
            bindCardClicks(tracks);
        }
        else if (currentView === 'releases') {
            library.innerHTML = releases.length
                ? '<div class="bhs-grid">' + releases.map(function (r) {
                    return '<button type="button" class="bhs-card bhs-release-card" data-release="' + r.id + '">'
                        + '<img src="' + esc(r.artwork) + '" alt="" class="bhs-card-art">'
                        + '<div class="bhs-card-title">' + esc(r.title) + '</div>'
                        + '<div class="bhs-card-artist">' + esc(r.artist) + '</div>'
                        + '</button>';
                }).join('') + '</div>'
                : ((BHSData && BHSData.emptyStateReleases) || '<p class="bhs-empty">No releases yet.</p>');
            library.querySelectorAll('.bhs-release-card').forEach(function (card) {
                card.addEventListener('click', function () { openRelease(parseInt(card.dataset.release || '', 10)); });
            });
        }
        else if (currentView === 'liked') {
            var liked = allTracks.filter(function (t) { return likedIds.indexOf(t.id) !== -1; });
            library.innerHTML = liked.length
                ? '<div class="bhs-grid">' + liked.map(trackCardHtml).join('') + '</div>'
                : (loggedIn
                    ? ((BHSData && BHSData.emptyStateLiked) || '<p class="bhs-empty">Nothing liked yet.</p>')
                    : '<p class="bhs-empty">Log in to like tracks.</p>');
            bindCardClicks(liked);
        }
        else if (currentView === 'playlists') {
            renderPlaylistsView();
        }
    }
    function bindCardClicks(list) {
        library.querySelectorAll('.bhs-card[data-id]').forEach(function (card) {
            card.addEventListener('click', function () {
                var id = parseInt(card.dataset.id || '', 10);
                var index = list.findIndex(function (t) { return t.id === id; });
                var t = list[index];
                // Locked tracks have no playable url (class-api.php track_payload);
                // show the monetization plugin's paywall notice instead.
                if (t && t.locked) {
                    showLockNotice(t);
                    return;
                }
                playQueue(list, index);
            });
        });
    }
    function showLockNotice(t) {
        library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-from-lock">&larr; Back</button>'
            + '<div class="bhs-lock-detail">'
            + '<h2 class="bhs-release-title">' + esc(t.title) + '</h2>'
            + (t.lock_notice || '<p>This track requires supporter access.</p>')
            + '</div>';
        byId('bhs-back-from-lock').addEventListener('click', renderView);
    }
    function openRelease(releaseId) {
        var release = releases.find(function (r) { return r.id === releaseId; });
        if (!release)
            return;
        var tracks = release.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
        library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-to-releases">&larr; Releases</button>'
            + '<h2 class="bhs-release-title">' + esc(release.title) + '</h2>'
            + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
        byId('bhs-back-to-releases').addEventListener('click', renderView);
        bindCardClicks(tracks);
    }
    function renderPlaylistsView() {
        if (!loggedIn) {
            library.innerHTML = (BHSData && BHSData.emptyStatePlaylistsLoggedOut) || '<p class="bhs-empty">Log in to create playlists.</p>';
            return;
        }
        if (!myPlaylists.length) {
            library.innerHTML = (BHSData && BHSData.emptyStatePlaylistsEmpty) || '<p class="bhs-empty">No playlists yet — use the + button on a track while it\'s playing to start one.</p>';
            return;
        }
        library.innerHTML = '<div class="bhs-grid">' + myPlaylists.map(function (p) {
            return '<button type="button" class="bhs-card bhs-playlist-card" data-playlist="' + p.id + '">'
                + '<div class="bhs-card-title">' + esc(p.title) + '</div>'
                + '<div class="bhs-card-artist">' + p.track_ids.length + ' tracks</div>'
                + '</button>';
        }).join('') + '</div>';
        library.querySelectorAll('.bhs-playlist-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var playlist = myPlaylists.find(function (p) { return p.id === parseInt(card.dataset.playlist || '', 10); });
                if (playlist)
                    openOwnedPlaylist(playlist);
            });
        });
    }
    function openOwnedPlaylist(playlist) {
        var tracks = playlist.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
        library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-to-playlists">&larr; Playlists</button>'
            + '<h2 class="bhs-release-title">' + esc(playlist.title) + '</h2>'
            + '<button type="button" class="bhs-btn" id="bhs-playlist-share-toggle">' + (playlist.is_public ? 'Stop sharing' : 'Share playlist') + '</button>'
            + (playlist.is_public && playlist.share_url ? '<p><input type="text" readonly value="' + esc(playlist.share_url) + '" onclick="this.select();" class="bhs-share-link"></p>' : '')
            + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
        byId('bhs-back-to-playlists').addEventListener('click', renderView);
        byId('bhs-playlist-share-toggle').addEventListener('click', function () {
            var endpoint = playlist.is_public ? 'unshare' : 'share';
            fetch(rest + 'playlists/' + playlist.id + '/' + endpoint, { method: 'POST', headers: authHeaders() })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                var idx = myPlaylists.findIndex(function (p) { return p.id === playlist.id; });
                if (idx !== -1)
                    myPlaylists[idx] = data.playlist;
                openOwnedPlaylist(data.playlist);
            });
        });
        bindCardClicks(tracks);
    }
    /* ---------- deep-linking a single track (OUS_Search's own real destination) ---------- */
    // Tracks have no canonical per-item URL, so search results deep-link here
    // via a query param. WHY: never auto-plays (autoplay policy, and bad UX).
    function maybeOpenTrackDeepLink() {
        var params = new URLSearchParams(window.location.search);
        var trackId = parseInt(params.get('bhs_track') || '', 10);
        if (!trackId)
            return false;
        var track = allTracks.find(function (t) { return t.id === trackId; });
        if (!track) {
            library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-from-deep-link">&larr; Back to library</button><p class="bhs-empty">That track isn\'t available.</p>';
            byId('bhs-back-from-deep-link').addEventListener('click', renderView);
            return true;
        }
        library.innerHTML = '<h2 class="bhs-release-title">' + esc(track.title) + '</h2><div class="bhs-grid">' + trackCardHtml(track) + '</div>';
        bindCardClicks([track]);
        return true;
    }
    /* ---------- viewing someone else's shared playlist (read-only, no auth) ---------- */
    function maybeOpenSharedPlaylist() {
        var params = new URLSearchParams(window.location.search);
        var token = params.get('bhs_shared_playlist');
        if (!token)
            return false;
        library.innerHTML = '<p class="bhs-empty">Loading shared playlist…</p>';
        fetch(rest + 'playlists/shared/' + encodeURIComponent(token))
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
            if (!res.ok) {
                library.innerHTML = '<p class="bhs-empty">' + esc((res.data && res.data.message) || 'This shared playlist link is invalid or has been revoked.') + '</p>';
                return;
            }
            var playlist = res.data.playlist;
            // Same-site shares always resolve, since allTracks covers the catalog.
            var tracks = playlist.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
            library.innerHTML = '<h2 class="bhs-release-title">' + esc(playlist.title) + ' <span class="bhs-badge">Shared playlist</span></h2>'
                + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
            bindCardClicks(tracks);
        })
            .catch(function () { library.innerHTML = '<p class="bhs-empty">Could not load this shared playlist right now.</p>'; });
        return true;
    }
    /* ---------- playback + queue ---------- */
    // Shuffle only reorders the upcoming part of the queue (after queueIndex);
    // originalOrder lets un-shuffling restore what hasn't played yet.
    var shuffleOn = false;
    var originalOrder = null;
    function shuffleArray(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
        }
        return arr;
    }
    function playQueue(list, index) {
        queue = list.slice();
        queueIndex = index;
        originalOrder = null;
        if (shuffleOn)
            applyShuffle();
        playCurrent();
        renderQueuePanel();
    }
    function applyShuffle() {
        originalOrder = queue.slice();
        var head = queue.slice(0, queueIndex + 1);
        var tail = shuffleArray(queue.slice(queueIndex + 1));
        queue = head.concat(tail);
    }
    function toggleShuffle() {
        shuffleOn = !shuffleOn;
        if (shuffleOn) {
            applyShuffle();
        }
        else if (originalOrder) {
            var playedIds = queue.slice(0, queueIndex + 1).map(function (t) { return t.id; });
            var remaining = originalOrder.filter(function (t) { return playedIds.indexOf(t.id) === -1; });
            queue = queue.slice(0, queueIndex + 1).concat(remaining);
            originalOrder = null;
        }
        var shuffleBtn = document.getElementById('bhs-shuffle-toggle');
        if (shuffleBtn)
            shuffleBtn.classList.toggle('active', shuffleOn);
        renderQueuePanel();
        if (jam.active && jam.isHost)
            jamPushState();
    }
    function playCurrent() {
        var t = queue[queueIndex];
        if (!t)
            return;
        currentQuality = null; // a fresh track always starts from its default encode
        shadowUrlOverride = null; // don't leak a previous track's quality-URL override (see switchToQuality)
        audio.src = t.url;
        audio.play().catch(function () { });
        // 402 = a monetization plugin declined the play (e.g. empty wallet);
        // the message comes from bhs_track_play_denied_message server-side.
        fetch(rest + 'tracks/' + t.id + '/play', { method: 'POST' })
            .then(function (r) { if (r.status === 402)
            return r.json().then(function (d) { throw new Error(d.message || 'Payment required.'); }); return; })
            .catch(function (err) {
            if (err && err.message) {
                audio.pause();
                showLockNotice(Object.assign({}, t, { locked: true, lock_notice: '<p>' + esc(err.message) + '</p>' }));
            }
        });
        nowplaying.style.display = '';
        artEl.src = t.artwork;
        titleEl.textContent = t.title;
        artistEl.textContent = t.artist;
        likeBtn.classList.toggle('liked', likedIds.indexOf(t.id) !== -1);
        likeBtn.innerHTML = likedIds.indexOf(t.id) !== -1 ? '&#9829;' : '&#9825;';
        qualityToggleBtn && (qualityToggleBtn.style.display = (t.qualities && t.qualities.length) ? '' : 'none');
        renderLyricsFor(t);
        renderQualityFor(t);
        // Re-check whether this new track is safe to route through an engaged
        // EQ/visualizer graph; eligibility can't carry over between tracks.
        onTrackChangedForAudioGraph(t);
        updateMediaSession(t);
        loadRelated(t.id);
        renderQueuePanel();
        renderChapters(t);
        maybeResumeSeek(t);
        lastResumeSave = 0;
    }
    function playPrev() {
        if (jam.active && !jam.isHost)
            return; // participants don't drive transport — see jamApplyState
        if (queueIndex > 0) {
            queueIndex--;
            playCurrent();
            if (jam.active && jam.isHost)
                jamPushState();
        }
    }
    function playNext() {
        if (jam.active && !jam.isHost)
            return;
        if (queueIndex < queue.length - 1) {
            queueIndex++;
            playCurrent();
            if (jam.active && jam.isHost)
                jamPushState();
        }
    }
    // History (already played) and Up Next sections. Also renders a Jam
    // session's shared queue, which jamApplyState() keeps in sync.
    function renderQueuePanel() {
        var history = queue.slice(0, queueIndex);
        var current = queue[queueIndex];
        var upcoming = queue.slice(queueIndex + 1);
        var html = '';
        if (history.length) {
            html += '<div class="bhs-queue-heading">History</div>';
            html += history.map(function (t, i) { return queueItemHtml(t, i, false); }).join('');
        }
        if (current) {
            html += '<div class="bhs-queue-heading bhs-queue-heading--now">Now Playing</div>';
            html += queueItemHtml(current, queueIndex, true);
        }
        if (upcoming.length) {
            html += '<div class="bhs-queue-heading">Up Next' + (shuffleOn ? ' (shuffled)' : '') + '</div>';
            html += upcoming.map(function (t, i) { return queueItemHtml(t, queueIndex + 1 + i, false); }).join('');
        }
        queueList.innerHTML = html;
        queueList.querySelectorAll('.bhs-queue-item').forEach(function (item) {
            item.addEventListener('click', function () {
                // In a Jam, only the host can jump the queue.
                if (jam.active && !jam.isHost)
                    return;
                queueIndex = parseInt(item.dataset.index || '', 10);
                playCurrent();
                if (jam.active && jam.isHost)
                    jamPushState();
            });
        });
    }
    function queueItemHtml(t, i, isActive) {
        return '<div class="bhs-queue-item' + (isActive ? ' active' : '') + '" data-index="' + i + '">'
            + esc(t.title) + ' <span class="bhs-queue-artist">' + esc(t.artist) + '</span></div>';
    }
    /* ---------- related tracks ---------- */
    function loadRelated(trackId) {
        fetch(rest + 'tracks/' + trackId + '/related').then(function (r) { return r.json(); }).then(function (data) {
            var related = data.related || [];
            if (!related.length) {
                relatedBox.style.display = 'none';
                return;
            }
            relatedBox.style.display = '';
            relatedList.innerHTML = related.map(trackCardHtml).join('');
            bindCardClicks(related);
        }).catch(function () { relatedBox.style.display = 'none'; });
    }
    /* ---------- Media Session ---------- */
    function updateMediaSession(t) {
        if (!('mediaSession' in navigator))
            return;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: t.title, artist: t.artist,
            artwork: [{ src: t.artwork, sizes: '512x512', type: 'image/png' }],
        });
        navigator.mediaSession.setActionHandler('play', function () { audio.play(); });
        navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
        navigator.mediaSession.setActionHandler('previoustrack', playPrev);
        navigator.mediaSession.setActionHandler('nexttrack', playNext);
        navigator.mediaSession.setActionHandler('seekto', function (details) {
            if (details.seekTime != null)
                audio.currentTime = details.seekTime;
        });
    }
    audio.addEventListener('play', function () {
        playPauseBtn.innerHTML = '&#10074;&#10074;';
        if ('mediaSession' in navigator)
            navigator.mediaSession.playbackState = 'playing';
    });
    audio.addEventListener('pause', function () {
        playPauseBtn.innerHTML = '&#9658;';
        if ('mediaSession' in navigator)
            navigator.mediaSession.playbackState = 'paused';
    });
    audio.addEventListener('ended', playNext);
    /* ---------- stream health ---------- */
    // This listener's own playback trouble (stalls, slow starts, errors),
    // distinct from source_health, which is a server-side check of the source.
    var streamHealth = { stallTimer: null, stalling: false, errorStreak: 0 };
    var streamBadge = document.createElement('span');
    streamBadge.className = 'bhs-stream-health';
    streamBadge.style.display = 'none';
    nowplaying.insertBefore(streamBadge, nowplaying.firstChild);
    function setStreamBadge(state) {
        if (state === 'poor') {
            streamBadge.textContent = 'Poor connection…';
            streamBadge.className = 'bhs-stream-health bhs-stream-health--poor';
            streamBadge.style.display = '';
        }
        else if (state === 'error') {
            streamBadge.textContent = 'Playback error — retrying…';
            streamBadge.className = 'bhs-stream-health bhs-stream-health--error';
            streamBadge.style.display = '';
        }
        else {
            streamBadge.style.display = 'none';
        }
    }
    // A single 'waiting' blip is normal; only flag a poor connection if it
    // hasn't recovered within a couple of seconds.
    audio.addEventListener('waiting', function () {
        if (streamHealth.stallTimer)
            clearTimeout(streamHealth.stallTimer);
        streamHealth.stallTimer = window.setTimeout(function () {
            streamHealth.stalling = true;
            setStreamBadge('poor');
            maybeStepDownQuality();
        }, 2000);
    });
    audio.addEventListener('playing', function () {
        if (streamHealth.stallTimer) {
            clearTimeout(streamHealth.stallTimer);
            streamHealth.stallTimer = null;
        }
        if (streamHealth.stalling) {
            streamHealth.stalling = false;
            setStreamBadge('none');
        }
        streamHealth.errorStreak = 0;
    });
    audio.addEventListener('error', function () {
        streamHealth.errorStreak++;
        setStreamBadge('error');
        // A few retries before giving up; three matches BHS_Feeds'
        // HEALTH_DOWN_AFTER_FAILS threshold.
        if (streamHealth.errorStreak <= 3) {
            setTimeout(function () { audio.load(); audio.play().catch(function () { }); }, 1500 * streamHealth.errorStreak);
        }
        else {
            setStreamBadge('none');
            var t = queue[queueIndex];
            if (t)
                showLockNotice(Object.assign({}, t, {
                    locked: true,
                    lock_notice: '<p>This track isn’t playable right now — its source may be temporarily unavailable.</p>',
                }));
        }
    });
    // Auto-drop to a lower-bitrate encode when playback is struggling, via
    // the same switchToQuality() the manual Quality panel uses.
    function maybeStepDownQuality() {
        var t = queue[queueIndex];
        if (!t || !t.qualities || !t.qualities.length)
            return;
        var order = ['lossless', 'high', 'standard'];
        var currentIdx = order.indexOf(currentQuality || ((t.qualities[0] && t.qualities[0].label) || ''));
        for (var i = currentIdx + 1; i < order.length; i++) {
            var candidate = t.qualities.find(function (q) { return q.label === order[i]; });
            if (candidate) {
                switchToQuality(candidate.label, candidate.url);
                return;
            }
        }
    }
    audio.addEventListener('timeupdate', function () {
        if (audio.duration)
            seek.value = String((audio.currentTime / audio.duration) * 100);
        if ('mediaSession' in navigator && audio.duration && 'setPositionState' in navigator.mediaSession) {
            navigator.mediaSession.setPositionState({ duration: audio.duration, playbackRate: 1, position: audio.currentTime });
        }
        updateLyricsHighlight();
        maybeSaveResumePosition();
    });
    /* ---------- long-form audio: chapters + resume position ---------- */
    var chaptersEl = document.getElementById('bhs-np-chapters');
    var lastResumeSave = 0;
    function renderChapters(t) {
        if (!chaptersEl)
            return;
        var chapters = t && t.chapters;
        if (!chapters || !chapters.length) {
            chaptersEl.style.display = 'none';
            chaptersEl.innerHTML = '';
            return;
        }
        chaptersEl.style.display = '';
        chaptersEl.innerHTML = '';
        chapters.forEach(function (c) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bhs-chapter-marker';
            var mm = Math.floor(c.time / 60), ss = c.time % 60;
            btn.textContent = mm + ':' + (ss < 10 ? '0' : '') + ss + ' ' + c.label;
            btn.addEventListener('click', function () { audio.currentTime = c.time; });
            chaptersEl.appendChild(btn);
        });
    }
    // Resume is logged-in only, and never autoplays: it just seeks once
    // metadata is available so the first play picks up where they left off.
    function maybeResumeSeek(t) {
        if (!t || !t.resumeSeconds || t.resumeSeconds < 5)
            return; // a few seconds in isn't worth resuming into
        var resumeSeconds = t.resumeSeconds;
        function onLoaded() {
            if (resumeSeconds < (audio.duration || Infinity) - 5)
                audio.currentTime = resumeSeconds;
            audio.removeEventListener('loadedmetadata', onLoaded);
        }
        audio.addEventListener('loadedmetadata', onLoaded);
    }
    // Throttled to once per 10s of playback, not every timeupdate tick.
    function maybeSaveResumePosition() {
        if (!BHSData || !BHSData.loggedIn)
            return;
        var t = queue[queueIndex];
        if (!t || audio.paused)
            return;
        var now = Date.now();
        if (now - lastResumeSave < 10000)
            return;
        lastResumeSave = now;
        fetch(rest + 'tracks/' + t.id + '/resume', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BHSData.nonce || '' },
            body: JSON.stringify({ seconds: Math.floor(audio.currentTime) }),
        }).catch(function () { });
    }
    playPauseBtn.addEventListener('click', function () {
        if (jam.active && !jam.isHost)
            return; // a participant doesn't drive playback directly, just hears the host's
        audio.paused ? audio.play() : audio.pause();
        if (jam.active && jam.isHost)
            jamPushState();
    });
    byId('bhs-prev').addEventListener('click', playPrev);
    byId('bhs-next').addEventListener('click', playNext);
    seek.addEventListener('input', function () {
        if (jam.active && !jam.isHost)
            return;
        if (audio.duration)
            audio.currentTime = (Number(seek.value) / 100) * audio.duration;
        if (jam.active && jam.isHost)
            jamPushState();
    });
    byId('bhs-shuffle-toggle').addEventListener('click', function () {
        if (jam.active && !jam.isHost)
            return;
        toggleShuffle();
    });
    // Mobile browsers mostly ignore audio.volume (hardware buttons win).
    volume.addEventListener('input', function () { audio.volume = Number(volume.value) / 100; });
    byId('bhs-queue-toggle').addEventListener('click', function () {
        queuePanel.style.display = queuePanel.style.display === 'none' ? '' : 'none';
    });
    /* ---------- likes ---------- */
    likeBtn.addEventListener('click', function () {
        if (!loggedIn) {
            notify('Log in to like tracks.', true);
            return;
        }
        var t = queue[queueIndex];
        if (!t)
            return;
        var track = t;
        fetch(rest + 'likes/' + track.id, { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
            if (data.liked) {
                likedIds.push(track.id);
            }
            else {
                likedIds = likedIds.filter(function (id) { return id !== track.id; });
            }
            likeBtn.classList.toggle('liked', data.liked);
            likeBtn.innerHTML = data.liked ? '&#9829;' : '&#9825;';
            if (currentView === 'liked')
                renderView();
        })
            .catch(function () { notify('Could not save that — check your connection and try again.', true); });
    });
    /* ---------- playlists ---------- */
    byId('bhs-add-playlist').addEventListener('click', function () {
        if (!loggedIn) {
            notify('Log in to use playlists.', true);
            return;
        }
        renderPlaylistPicker();
        playlistPicker.style.display = '';
    });
    byId('bhs-playlist-picker-close').addEventListener('click', function () { playlistPicker.style.display = 'none'; });
    function renderPlaylistPicker() {
        playlistPickerList.innerHTML = myPlaylists.length
            ? myPlaylists.map(function (p) { return '<button type="button" class="bhs-btn bhs-playlist-option" data-id="' + p.id + '">' + esc(p.title) + '</button>'; }).join('')
            : '<p class="bhs-empty">No playlists yet.</p>';
        playlistPickerList.querySelectorAll('.bhs-playlist-option').forEach(function (btn) {
            btn.addEventListener('click', function () { addCurrentToPlaylist(parseInt(btn.dataset.id || '', 10)); });
        });
    }
    function addCurrentToPlaylist(playlistId) {
        var t = queue[queueIndex];
        if (!t)
            return;
        // Disable every row so a double-tap can't fire two adds in flight.
        playlistPickerList.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch(rest + 'playlists/' + playlistId + '/tracks', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ track_id: t.id }),
        }).then(function (r) { return r.json(); }).then(function (data) {
            var idx = myPlaylists.findIndex(function (p) { return p.id === playlistId; });
            if (idx !== -1)
                myPlaylists[idx] = data.playlist;
            playlistPicker.style.display = 'none';
            var addedName = (data.playlist && data.playlist.name) || 'playlist';
            notify('Added to "' + addedName + '".');
        }).catch(function () {
            playlistPickerList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
            notify('Could not add to playlist — check your connection and try again.', true);
        });
    }
    byId('bhs-new-playlist-create').addEventListener('click', function () {
        var nameEl = byId('bhs-new-playlist-name');
        var createBtn = byId('bhs-new-playlist-create');
        var name = nameEl.value.trim();
        if (!name)
            return;
        createBtn.disabled = true;
        var originalLabel = createBtn.textContent || '';
        createBtn.textContent = 'Creating…';
        fetch(rest + 'playlists', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ title: name }),
        }).then(function (r) { return r.json(); }).then(function (data) {
            myPlaylists.push(data.playlist);
            nameEl.value = '';
            createBtn.disabled = false;
            createBtn.textContent = originalLabel;
            addCurrentToPlaylist(data.playlist.id);
        }).catch(function () {
            createBtn.disabled = false;
            createBtn.textContent = originalLabel;
            notify('Could not create the playlist — check your connection and try again.', true);
        });
    });
    /* ---------- auth (bh-identity) ---------- */
    var authModal = byId('bhs-auth-modal');
    var authMode = 'login';
    var emailField = byId('bhs-auth-email');
    var authSubmitBtn = byId('bhs-auth-submit');
    var authError = byId('bhs-auth-error');
    var loginOpenBtn = byId('bhs-login-open');
    var accountInfo = byId('bhs-account-info');
    var accountUsername = byId('bhs-account-username');
    function refreshAccountUI() {
        loginOpenBtn.style.display = loggedIn ? 'none' : '';
        accountInfo.style.display = loggedIn ? '' : 'none';
    }
    loginOpenBtn.addEventListener('click', function () { authModal.style.display = ''; });
    byId('bhs-auth-close').addEventListener('click', function () { authModal.style.display = 'none'; });
    document.querySelectorAll('.bhs-auth-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.bhs-auth-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            authMode = tab.dataset.mode || 'login';
            emailField.style.display = authMode === 'register' ? '' : 'none';
            authSubmitBtn.textContent = authMode === 'register' ? 'Sign Up' : 'Log In';
            authError.textContent = '';
        });
    });
    // Flips the field's own type so validation/submission are unchanged.
    var passToggle = document.querySelector('.bhs-pass-toggle');
    if (passToggle) {
        passToggle.addEventListener('click', function () {
            var input = byId('bhs-auth-password');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            passToggle.setAttribute('aria-pressed', String(!showing));
            passToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            passToggle.innerHTML = showing ? '&#128065;' : '&#128683;';
        });
    }
    [document.getElementById('bhs-auth-username'), document.getElementById('bhs-auth-password'), emailField].forEach(function (el) {
        if (el)
            el.addEventListener('input', function () { el.classList.remove('bhs-field-invalid'); });
    });
    authSubmitBtn.addEventListener('click', function () {
        var usernameEl = byId('bhs-auth-username');
        var passwordEl = byId('bhs-auth-password');
        var username = usernameEl.value.trim();
        var password = passwordEl.value;
        // Mark the specific empty field before hitting the network.
        usernameEl.classList.toggle('bhs-field-invalid', !username);
        passwordEl.classList.toggle('bhs-field-invalid', !password);
        if (!username || !password) {
            authError.textContent = !username && !password ? 'Enter a username and password.' : (!username ? 'Enter a username.' : 'Enter a password.');
            return;
        }
        if (authMode === 'register' && !emailField.value.trim()) {
            emailField.classList.add('bhs-field-invalid');
            authError.textContent = 'Enter an email address.';
            return;
        }
        var body = { username: username, password: password };
        if (authMode === 'register')
            body.email = emailField.value.trim();
        // Pending state prevents a duplicate register/login POST on double-click.
        authSubmitBtn.disabled = true;
        var originalLabel = authSubmitBtn.textContent || '';
        authSubmitBtn.textContent = authMode === 'register' ? 'Creating account…' : 'Signing in…';
        authError.textContent = '';
        fetch(identityRest + authMode, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
        })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
            if (!res.ok) {
                authSubmitBtn.disabled = false;
                authSubmitBtn.textContent = originalLabel;
                authError.textContent = res.data.message || 'Something went wrong.';
                return;
            }
            loggedIn = true;
            authModal.style.display = 'none';
            authSubmitBtn.disabled = false;
            authSubmitBtn.textContent = originalLabel;
            authError.textContent = '';
            refreshAccountUI();
            reloadUserData();
        })
            .catch(function () {
            authSubmitBtn.disabled = false;
            authSubmitBtn.textContent = originalLabel;
            authError.textContent = 'Could not reach the server — check your connection and try again.';
        });
    });
    byId('bhs-logout').addEventListener('click', function () {
        fetch(identityRest + 'logout', { method: 'POST', headers: { 'X-WP-Nonce': nonce } }).then(function () {
            loggedIn = false;
            likedIds = [];
            myPlaylists = [];
            refreshAccountUI();
            if (currentView === 'liked' || currentView === 'playlists')
                renderView();
        });
    });
    // Load the account's likes/playlists without a reload, so playback continues.
    function reloadUserData() {
        fetch(identityRest + 'session').then(function (r) { return r.json(); }).then(function (s) {
            if (s.loggedIn)
                accountUsername.textContent = s.username;
        });
        Promise.all([
            fetch(rest + 'likes', { headers: authHeaders() }).then(function (r) { return r.json(); }),
            fetch(rest + 'playlists', { headers: authHeaders() }).then(function (r) { return r.json(); }),
        ]).then(function (results) {
            likedIds = results[0].track_ids || [];
            myPlaylists = results[1].playlists || [];
            if (currentView === 'liked' || currentView === 'playlists')
                renderView();
        });
    }
    refreshAccountUI();
    if (loggedIn) {
        fetch(identityRest + 'session').then(function (r) { return r.json(); }).then(function (s) {
            if (s.loggedIn)
                accountUsername.textContent = s.username;
        });
    }
    /* ---------- lyrics ---------- */
    // Parses [mm:ss.xx]text lines into {time, text}, sorted by time. Tolerant
    // of fraction-length variations; non-matching lines are skipped.
    function parseLRC(lrc) {
        var lines = [];
        (lrc || '').split(/\r?\n/).forEach(function (raw) {
            var m = raw.match(/^\[(\d+):(\d+(?:\.\d+)?)\](.*)$/);
            if (!m)
                return;
            var time = parseInt(m[1], 10) * 60 + parseFloat(m[2]);
            var text = m[3].trim();
            if (text)
                lines.push({ time: time, text: text });
        });
        lines.sort(function (a, b) { return a.time - b.time; });
        return lines;
    }
    function renderLyricsFor(t) {
        var lyrics = t.lyrics || {};
        if (lyrics.synced) {
            currentTrackLyrics = parseLRC(lyrics.synced);
            lyricsBody.innerHTML = currentTrackLyrics.map(function (line, i) {
                return '<div class="bhs-lyric-line" data-index="' + i + '">' + esc(line.text) + '</div>';
            }).join('') || '<p class="bhs-empty">No lyrics for this track.</p>';
        }
        else if (lyrics.plain) {
            currentTrackLyrics = null;
            lyricsBody.innerHTML = '<div class="bhs-lyric-plain">' + esc(lyrics.plain).replace(/\n/g, '<br>') + '</div>';
        }
        else {
            currentTrackLyrics = null;
            lyricsBody.innerHTML = '<p class="bhs-empty">No lyrics for this track.</p>';
        }
    }
    function updateLyricsHighlight() {
        if (!currentTrackLyrics || !currentTrackLyrics.length || lyricsPanel.style.display === 'none')
            return;
        var t = audio.currentTime;
        var activeIndex = -1;
        for (var i = 0; i < currentTrackLyrics.length; i++) {
            if (currentTrackLyrics[i].time <= t)
                activeIndex = i;
            else
                break;
        }
        var lines = lyricsBody.querySelectorAll('.bhs-lyric-line');
        lines.forEach(function (el, i) {
            el.classList.toggle('active', i === activeIndex);
        });
        var activeLine = activeIndex >= 0 ? lines[activeIndex] : undefined;
        if (activeLine) {
            activeLine.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }
    lyricsToggleBtn.addEventListener('click', function () {
        var open = lyricsPanel.style.display !== 'none';
        lyricsPanel.style.display = open ? 'none' : '';
        qualityPanel.style.display = 'none';
        eqPanel.style.display = 'none';
    });
    /* ---------- quality selection ---------- */
    // Switching quality preserves playback position and paused/playing state.
    function switchToQuality(label, url) {
        var wasPlaying = !audio.paused;
        var position = audio.currentTime;
        currentQuality = label;
        shadowUrlOverride = (label === 'default') ? null : url; // engageGraphForCurrentTrack() uses this instead of t.url when set
        audio.src = url;
        audio.addEventListener('loadedmetadata', function onLoaded() {
            audio.removeEventListener('loadedmetadata', onLoaded);
            audio.currentTime = position;
            // The browser paused playback, not the user; say so.
            if (wasPlaying)
                audio.play().catch(function () { notify('Playback stopped — press play to resume.', true); });
        });
        renderQualityFor(queue[queueIndex]);
        // A different encode could have different CORS behavior, so re-run the
        // graph eligibility check. graphRequestToken stops a stale probe winning.
        if (graphWanted)
            engageGraphForCurrentTrack();
    }
    function renderQualityFor(t) {
        if (!t)
            return;
        var options = [{ label: 'default', url: t.url }].concat((t.qualities || []).map(function (q) {
            return { label: q.label, url: q.url, filesize: q.filesize };
        }));
        qualityList.innerHTML = options.map(function (o) {
            var active = (currentQuality === o.label) || (!currentQuality && o.label === 'default');
            return '<button type="button" class="bhs-quality-option' + (active ? ' active' : '') + '" data-url="' + esc(o.url) + '" data-label="' + esc(o.label) + '">'
                + esc(o.label) + (o.filesize ? ' <span class="bhs-quality-size">(' + Math.round(o.filesize / 1024 / 1024 * 10) / 10 + ' MB)</span>' : '')
                + '</button>';
        }).join('');
        qualityList.querySelectorAll('.bhs-quality-option').forEach(function (btn) {
            btn.addEventListener('click', function () { switchToQuality(btn.dataset.label || '', btn.dataset.url || ''); });
        });
    }
    if (qualityToggleBtn) {
        qualityToggleBtn.addEventListener('click', function () {
            var open = qualityPanel.style.display !== 'none';
            qualityPanel.style.display = open ? 'none' : '';
            lyricsPanel.style.display = 'none';
            eqPanel.style.display = 'none';
        });
    }
    /* ---------- EQ + visualizer (Web Audio API) ----------
     *
     * WHY: once an <audio> element is wired into createMediaElementSource(),
     * a non-CORS cross-origin resource is silently muted (no error to catch).
     * External/aggregated tracks often lack CORS headers, so EQ/visualizer run
     * through a hidden `shadowAudio` element that only becomes the audible
     * output once the current track is confirmed safe; otherwise the primary
     * `audio` stays unmuted. Worst case: no EQ for that track, never silence.
     */
    var EQ_BANDS = [60, 250, 1000, 4000, 8000, 14000]; // fixed, musically-spread peaking filters
    var audioCtx = null;
    var shadowAudio = null;
    var sourceNode = null;
    var analyserNode = null;
    var eqFilters = null;
    var graphBuilt = false;
    var graphEngaged = false; // true only while shadowAudio is the actual audible output
    var graphWanted = false; // true once the listener has opened EQ or Visualizer at least once this session
    var eqBandsEl = byId('bhs-eq-bands');
    var graphNotice = null; // lazily-created "unavailable for this track" message
    function ensureGraphNotice() {
        if (graphNotice)
            return graphNotice;
        graphNotice = document.createElement('p');
        graphNotice.className = 'bhs-empty';
        graphNotice.textContent = 'EQ and the visualizer aren’t available for this track — it’s hosted on a server that doesn’t grant cross-origin audio access. Playback itself is unaffected.';
        return graphNotice;
    }
    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        }
        catch (e) {
            return false;
        }
    }
    // Ranged GET in mode:'cors': a missing Access-Control-Allow-Origin makes
    // the fetch promise reject, which (unlike the silent mute) is catchable.
    function probeCors(url) {
        if (isSameOrigin(url))
            return Promise.resolve(true);
        return fetch(url, { method: 'GET', mode: 'cors', headers: { 'Range': 'bytes=0-0' } })
            .then(function (r) { return r.ok || r.status === 206; })
            .catch(function () { return false; });
    }
    // Built lazily on first use: AudioContext needs a user gesture, and
    // createMediaElementSource() can only be called once per element.
    function ensureAudioGraph() {
        if (graphBuilt)
            return;
        var win = window;
        var Ctx = (typeof AudioContext !== 'undefined' ? AudioContext : undefined) || win.webkitAudioContext;
        if (!Ctx)
            return; // ancient-browser fallback: EQ/visualizer simply unavailable, playback itself is unaffected
        graphBuilt = true;
        shadowAudio = new Audio();
        shadowAudio.crossOrigin = 'anonymous';
        var ctx = new Ctx();
        audioCtx = ctx;
        sourceNode = ctx.createMediaElementSource(shadowAudio);
        eqFilters = EQ_BANDS.map(function (freq) {
            var f = ctx.createBiquadFilter();
            f.type = 'peaking';
            f.frequency.value = freq;
            f.Q.value = 1;
            f.gain.value = 0;
            return f;
        });
        analyserNode = ctx.createAnalyser();
        analyserNode.fftSize = 128;
        var node = sourceNode;
        eqFilters.forEach(function (f) { node.connect(f); node = f; });
        node.connect(analyserNode);
        analyserNode.connect(ctx.destination);
        renderEqBands();
    }
    // Monotonic token: a stale probe/play promise (earlier track, or a
    // superseded quality URL on the same track) can never act on a newer request.
    var graphRequestToken = 0;
    // Make shadowAudio the audible output for the current track. WHY:
    // audio.muted is only set after shadowAudio's 'playing' event, never on
    // probe success alone, so a failed decode/play can't leave silence.
    function engageGraphForCurrentTrack() {
        var t = queue[queueIndex];
        if (!t || !t.url)
            return;
        var url = (currentQuality && shadowUrlOverride) || t.url;
        var myToken = ++graphRequestToken;
        ensureAudioGraph();
        if (!audioCtx) {
            showGraphNotice(true);
            return;
        } // no Web Audio support at all in this browser
        probeCors(url).then(function (eligible) {
            if (myToken !== graphRequestToken)
                return; // superseded by a newer track/quality change — ignore
            if (!eligible) {
                disengageGraph();
                showGraphNotice(true);
                return;
            }
            showGraphNotice(false);
            var sa = shadowAudio;
            sa.src = url;
            sa.currentTime = audio.currentTime;
            // Don't mute the primary yet; wait for shadowAudio's 'playing' event.
            var onPlaying = function () {
                sa.removeEventListener('playing', onPlaying);
                if (myToken !== graphRequestToken)
                    return;
                audio.muted = true;
                graphEngaged = true;
            };
            sa.addEventListener('playing', onPlaying);
            sa.addEventListener('error', function onErr() {
                sa.removeEventListener('error', onErr);
                if (myToken !== graphRequestToken)
                    return;
                disengageGraph();
                showGraphNotice(true);
            }, { once: true });
            if (!audio.paused) {
                sa.play().catch(function () {
                    if (myToken !== graphRequestToken)
                        return;
                    disengageGraph();
                    showGraphNotice(true);
                });
            }
            // If the primary is paused, shadowAudio stays paused and nothing is muted.
        });
    }
    var shadowUrlOverride = null; // set by switchToQuality() when the active quality differs from the track's default URL
    function disengageGraph() {
        if (shadowAudio)
            shadowAudio.pause();
        audio.muted = false;
        graphEngaged = false;
    }
    function showGraphNotice(show) {
        var notice = ensureGraphNotice();
        if (show) {
            if (!eqPanel.contains(notice))
                eqPanel.appendChild(notice);
            vizCanvas.style.visibility = 'hidden';
        }
        else {
            if (eqPanel.contains(notice))
                eqPanel.removeChild(notice);
            vizCanvas.style.visibility = '';
        }
    }
    // Periodic bounded resync of shadow to primary; the primary element still
    // owns seek/duration/MediaSession.
    setInterval(function () {
        if (!graphEngaged || !shadowAudio)
            return;
        if (Math.abs(shadowAudio.currentTime - audio.currentTime) > 0.35) {
            shadowAudio.currentTime = audio.currentTime;
        }
        if (audio.paused && !shadowAudio.paused)
            shadowAudio.pause();
        if (!audio.paused && shadowAudio.paused)
            shadowAudio.play().catch(function () { });
    }, 2000);
    // Called on every track change; a no-op unless the listener has opened
    // EQ/Visualizer at least once. The track argument is unused.
    function onTrackChangedForAudioGraph(_t) {
        if (!graphWanted)
            return;
        engageGraphForCurrentTrack();
    }
    function renderEqBands() {
        eqBandsEl.innerHTML = EQ_BANDS.map(function (freq, i) {
            var label = freq >= 1000 ? (freq / 1000) + 'kHz' : freq + 'Hz';
            return '<div class="bhs-eq-band">'
                + '<input type="range" min="-12" max="12" value="0" step="1" data-index="' + i + '" orient="vertical">'
                + '<span>' + label + '</span></div>';
        }).join('');
        eqBandsEl.querySelectorAll('input[type=range]').forEach(function (slider) {
            slider.addEventListener('input', function () {
                var i = parseInt(slider.dataset.index || '', 10);
                if (eqFilters && eqFilters[i])
                    eqFilters[i].gain.value = parseFloat(slider.value);
            });
        });
    }
    // Disengage once neither EQ nor visualizer wants the graph, so shadowAudio
    // doesn't keep playing (and the primary stay muted) in the background.
    function disengageGraphIfUnwanted() {
        var eqOpen = eqPanel.style.display !== 'none';
        if (!eqOpen && !visualizerOn)
            disengageGraph();
    }
    eqToggleBtn.addEventListener('click', function () {
        var wasOpen = eqPanel.style.display !== 'none';
        eqPanel.style.display = wasOpen ? 'none' : '';
        lyricsPanel.style.display = 'none';
        qualityPanel.style.display = 'none';
        if (wasOpen) {
            disengageGraphIfUnwanted();
        }
        else {
            graphWanted = true;
            engageGraphForCurrentTrack();
        }
    });
    byId('bhs-eq-reset').addEventListener('click', function () {
        if (!eqFilters)
            return;
        eqFilters.forEach(function (f) { f.gain.value = 0; });
        eqBandsEl.querySelectorAll('input[type=range]').forEach(function (s) { s.value = '0'; });
    });
    // Visualizer bars read the same shadow-graph analyser as the EQ, so they
    // share its eligibility gating.
    var vizCtx = vizCanvas.getContext('2d');
    function drawVisualizer() {
        if (!visualizerOn)
            return;
        requestAnimationFrame(drawVisualizer);
        if (!analyserNode || !graphEngaged || audio.paused)
            return;
        var data = new Uint8Array(analyserNode.frequencyBinCount);
        analyserNode.getByteFrequencyData(data);
        var w = vizCanvas.width, h = vizCanvas.height;
        vizCtx.clearRect(0, 0, w, h);
        var barWidth = w / data.length;
        for (var i = 0; i < data.length; i++) {
            var barHeight = (data[i] / 255) * h;
            vizCtx.fillRect(i * barWidth, h - barHeight, Math.max(1, barWidth - 1), barHeight);
        }
    }
    vizCtx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--bh-accent') || '#C1503A';
    vizToggleBtn.addEventListener('click', function () {
        visualizerOn = !visualizerOn;
        vizCanvas.style.display = visualizerOn ? '' : 'none';
        if (visualizerOn) {
            graphWanted = true;
            engageGraphForCurrentTrack();
            drawVisualizer();
        }
        else {
            disengageGraphIfUnwanted();
        }
    });
    /* ---------- local file import ---------- */
    byId('bhs-import-open').addEventListener('click', function () {
        if (!loggedIn) {
            notify('Log in to import your own music.', true);
            return;
        }
        importModal.style.display = 'flex';
    });
    // Empty-state CTA links are same-page anchors re-rendered via innerHTML,
    // so the click handler is delegated on the stable `library` container.
    library.addEventListener('click', function (e) {
        var target = e.target;
        if (target.closest('a[href="#bhs-import"]')) {
            e.preventDefault();
            byId('bhs-import-open').click();
        }
        else if (target.closest('a[href="#bhs-clear-filters"]')) {
            e.preventDefault();
            searchInput.value = '';
            genreFilter.value = '';
            if (currentView === 'all')
                renderView();
        }
    });
    byId('bhs-import-close').addEventListener('click', function () { importModal.style.display = 'none'; });
    byId('bhs-import-submit').addEventListener('click', function () {
        var fileInput = byId('bhs-import-file');
        var errorBox = byId('bhs-import-error');
        errorBox.textContent = '';
        if (!fileInput.files || !fileInput.files.length) {
            errorBox.textContent = 'Choose a file first.';
            return;
        }
        var form = new FormData();
        form.append('audio', fileInput.files[0]);
        var title = byId('bhs-import-title').value.trim();
        var artist = byId('bhs-import-artist').value.trim();
        if (title)
            form.append('title', title);
        if (artist)
            form.append('artist', artist);
        // WHY: no Content-Type; the browser must set the multipart boundary itself.
        fetch(rest + 'import', { method: 'POST', headers: authHeaders(), body: form })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
            if (!res.ok) {
                errorBox.textContent = (res.data && res.data.message) || 'Import failed.';
                return;
            }
            allTracks.push(res.data.track);
            importModal.style.display = 'none';
            fileInput.value = '';
            if (currentView === 'all')
                renderView();
        })
            .catch(function () { errorBox.textContent = 'Could not reach the server — check your connection and try again.'; });
    });
    /* ---------- tabs + filters ---------- */
    byId('bhs-tabs').querySelectorAll('.bhs-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.bhs-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentView = tab.dataset.view || 'all';
            renderView();
        });
    });
    searchInput.addEventListener('input', function () { if (currentView === 'all')
        renderView(); });
    genreFilter.addEventListener('change', function () { if (currentView === 'all')
        renderView(); });
    /* ---------- initial load ---------- */
    function loadGenreOptions() {
        var genres = {};
        allTracks.forEach(function (t) { t.genres.forEach(function (g) { genres[g] = true; }); });
        Object.keys(genres).sort().forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g;
            opt.textContent = g;
            genreFilter.appendChild(opt);
        });
    }
    /* ---------- Jam (shared listening) ---------- */
    // Polling-first: non-hosts poll /jam/{code}/state every 2s and apply it
    // via jamApplyState(); the host pushes its state on each local action.
    var jamModal = byId('bhs-jam-modal');
    var jamBanner = byId('bhs-jam-banner');
    var jamErrorEl = document.getElementById('bhs-jam-error');
    function jamShowError(msg) { if (jamErrorEl)
        jamErrorEl.textContent = msg || ''; }
    byId('bhs-jam-toggle').addEventListener('click', function () {
        if (jam.active) {
            jamRenderBanner();
            jamModal.style.display = '';
            return;
        }
        jamModal.style.display = jamModal.style.display === 'none' ? '' : 'none';
        jamShowError('');
    });
    byId('bhs-jam-close').addEventListener('click', function () { jamModal.style.display = 'none'; });
    byId('bhs-jam-create').addEventListener('click', function () {
        if (!loggedIn) {
            jamShowError('Log in to start a Jam.');
            return;
        }
        if (!queue.length) {
            jamShowError('Play something first — Jam starts from your current queue.');
            return;
        }
        var voteMode = byId('bhs-jam-vote-mode').checked;
        var approvalMode = byId('bhs-jam-approval-mode').checked;
        var maxParticipants = parseInt(byId('bhs-jam-max-participants').value, 10) || 0;
        fetch(rest + 'jam', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                track_ids: queue.map(function (t) { return t.id; }),
                start_index: queueIndex,
                control_mode: voteMode ? 'vote_skip' : 'host',
                require_approval: approvalMode,
                max_participants: maxParticipants,
            }),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
            if (!res.ok) {
                jamShowError(res.d.message || 'Could not start a Jam.');
                return;
            }
            jamStart(res.d, true);
        }).catch(function () { jamShowError('Could not start a Jam right now.'); });
    });
    byId('bhs-jam-join').addEventListener('click', function () {
        if (!loggedIn) {
            jamShowError('Log in to join a Jam.');
            return;
        }
        var code = (byId('bhs-jam-code-input').value || '').trim().toUpperCase();
        if (!code)
            return;
        fetch(rest + 'jam/' + encodeURIComponent(code) + '/join', { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
            .then(function (res) {
            if (res.status === 202 && res.d.pending) {
                // Approval-required mode: keep polling this join endpoint
                // until approved; a denial surfaces as a plain join failure.
                jamShowError(res.d.message || 'Waiting for the host…');
                jamAwaitApproval(code);
                return;
            }
            if (res.status >= 400) {
                jamShowError((res.d && res.d.message) || 'Could not join that Jam.');
                return;
            }
            jamStart(res.d, false);
        }).catch(function () { jamShowError('Could not join that Jam right now.'); });
    });
    var jamApprovalPoll = null;
    function jamAwaitApproval(code) {
        if (jamApprovalPoll)
            clearInterval(jamApprovalPoll);
        jamApprovalPoll = window.setInterval(function () {
            fetch(rest + 'jam/' + encodeURIComponent(code) + '/join', { method: 'POST', headers: authHeaders() })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                .then(function (res) {
                if (res.status === 202)
                    return; // still waiting
                if (jamApprovalPoll)
                    clearInterval(jamApprovalPoll);
                jamApprovalPoll = null;
                if (res.status >= 400) {
                    jamShowError((res.d && res.d.message) || 'The host didn’t let you in.');
                    return;
                }
                jamStart(res.d, false);
            }).catch(function () { });
        }, 3000);
    }
    function jamStart(state, isHost) {
        jam.active = true;
        jam.isHost = isHost;
        jam.code = state.code;
        jam.controlMode = state.control_mode;
        jamModal.style.display = 'none';
        jamShowError('');
        jamApplyState(state);
        jamRenderBanner();
        if (jam.pollTimer)
            clearInterval(jam.pollTimer);
        // The host polls slower, only for vote-skip results and participants;
        // its own transport state is never overwritten (see jamApplyState).
        jam.pollTimer = window.setInterval(jamPoll, isHost ? 4000 : 2000);
    }
    function jamPoll() {
        if (!jam.active || !jam.code)
            return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/state', { headers: authHeaders() })
            .then(function (r) {
            if (r.status === 404) {
                jamEnd('This Jam has ended.');
                throw new Error('ended');
            }
            // 403 means the host removed us; there's no separate "kicked" signal.
            if (r.status === 403) {
                jamEnd('You were removed from this Jam by the host.');
                throw new Error('kicked');
            }
            return r.json();
        })
            .then(jamApplyState)
            .catch(function () { });
    }
    // Turns server state into playback. For a non-host this is the transport.
    // For the host, only participants, vote-skip tally, and any vote-forced
    // index/position are applied; local play/pause/seek is authoritative.
    function jamApplyState(state) {
        jam.controlMode = state.control_mode;
        jamRenderParticipants(state.participants);
        jamRenderSkipVotes(state);
        jamRenderPending(state.pending);
        if (jam.isHost)
            return; // the host doesn't take transport dictation from its own poll
        var incomingIds = state.queue.map(function (t) { return t.id; });
        var currentIds = queue.map(function (t) { return t.id; });
        var queueChanged = incomingIds.length !== currentIds.length || incomingIds.some(function (id, i) { return id !== currentIds[i]; });
        if (queueChanged)
            queue = state.queue;
        var trackChanged = queueIndex !== state.index || queueChanged;
        queueIndex = state.index;
        if (trackChanged) {
            playCurrent();
        }
        // Server sends "position P as of server-time T"; project forward by
        // elapsed wall-clock time so participants don't stutter backward.
        if (state.playing) {
            var elapsed = (Date.now() / 1000) - state.position_updated_at;
            var projected = state.position + Math.max(0, elapsed);
            if (Math.abs(audio.currentTime - projected) > 1.5)
                audio.currentTime = projected;
            if (audio.paused)
                audio.play().catch(function () { });
        }
        else {
            if (Math.abs(audio.currentTime - state.position) > 0.75)
                audio.currentTime = state.position;
            if (!audio.paused)
                audio.pause();
        }
        renderQueuePanel();
    }
    // Called only right after a guarded local transport action, never on a timer.
    function jamPushState() {
        if (!jam.active || !jam.isHost || !jam.code)
            return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/host-state', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                queue: queue.map(function (t) { return t.id; }),
                index: queueIndex,
                playing: !audio.paused,
                position: audio.currentTime || 0,
            }),
        }).catch(function () { });
    }
    function jamVoteSkip() {
        if (!jam.active || !jam.code || jam.controlMode !== 'vote_skip')
            return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/vote-skip', { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json(); }).then(jamApplyState).catch(function () {
            notify('Could not reach the server — check your connection and try again.', true);
        });
    }
    function jamLeave() {
        if (!jam.active || !jam.code)
            return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/leave', { method: 'POST', headers: authHeaders() }).catch(function () { });
        jamEnd(null);
    }
    function jamEnd(notice) {
        jam.active = false;
        jam.isHost = false;
        jam.code = null;
        if (jam.pollTimer)
            clearInterval(jam.pollTimer);
        jam.pollTimer = null;
        jamBanner.style.display = 'none';
        jamBanner.innerHTML = '';
        if (jamParticipantsPanel) {
            jamParticipantsPanel.remove();
            jamParticipantsPanel = null;
        }
        if (jamPendingPanel) {
            jamPendingPanel.remove();
            jamPendingPanel = null;
        }
        if (jamApprovalPoll) {
            clearInterval(jamApprovalPoll);
            jamApprovalPoll = null;
        }
        if (notice)
            notify(notice, true);
    }
    function jamRenderBanner() {
        jamBanner.style.display = '';
        var roleLabel = jam.isHost ? 'You\'re hosting' : 'Listening along';
        var skipHtml = (!jam.isHost && jam.controlMode === 'vote_skip')
            ? ' <button type="button" class="bhs-link-btn" id="bhs-jam-vote-skip-btn">Vote to skip</button>'
            : '';
        jamBanner.innerHTML = '<span>&#127925; Jam &middot; code <strong>' + esc(jam.code) + '</strong> &middot; ' + esc(roleLabel) + '</span>'
            + skipHtml
            + ' <button type="button" class="bhs-link-btn" id="bhs-jam-leave-btn">Leave</button>';
        var voteBtn = document.getElementById('bhs-jam-vote-skip-btn');
        if (voteBtn)
            voteBtn.addEventListener('click', jamVoteSkip);
        byId('bhs-jam-leave-btn').addEventListener('click', jamLeave);
    }
    var jamParticipantsPanel = null;
    // Purely local mute: hides a participant's name from your own list (audio
    // is shared, nothing else is muted). Scoped per Jam code.
    function mutedKey() { return 'bhs_jam_muted_' + jam.code; }
    function getMuted() {
        try {
            return JSON.parse(localStorage.getItem(mutedKey()) || '[]');
        }
        catch (e) {
            return [];
        }
    }
    function isMuted(uid) { return getMuted().indexOf(uid) !== -1; }
    function toggleMute(uid) {
        var list = getMuted();
        var i = list.indexOf(uid);
        if (i === -1)
            list.push(uid);
        else
            list.splice(i, 1);
        localStorage.setItem(mutedKey(), JSON.stringify(list));
    }
    function jamRenderParticipants(list) {
        if (!jam.active)
            return;
        list = list || [];
        var muted = getMuted();
        var visibleNames = list
            .filter(function (p) { return muted.indexOf(p.user_id) === -1; })
            .map(function (p) { return p.display_name || ('User #' + p.user_id); });
        jamBanner.title = visibleNames.length ? ('In this Jam: ' + visibleNames.join(', ')) : '';
        if (!jamParticipantsPanel) {
            jamParticipantsPanel = document.createElement('div');
            jamParticipantsPanel.className = 'bhs-jam-participants';
            jamBanner.parentNode.insertBefore(jamParticipantsPanel, jamBanner.nextSibling);
        }
        // Everyone gets mute; the host also gets remove (the server rejects
        // a self-kick, so the host's own row needs no special case).
        jamParticipantsPanel.innerHTML = list.map(function (p) {
            var name = esc(p.display_name || ('User #' + p.user_id));
            var mutedNow = isMuted(p.user_id);
            var html = '<span class="bhs-jam-participant' + (mutedNow ? ' bhs-jam-participant--muted' : '') + '">' + name
                + ' <button type="button" class="bhs-link-btn" data-mute-uid="' + p.user_id + '">' + (mutedNow ? 'unmute' : 'mute') + '</button>';
            if (jam.isHost)
                html += ' <button type="button" class="bhs-link-btn" data-kick-uid="' + p.user_id + '">remove</button>';
            html += '</span>';
            return html;
        }).join('');
        jamParticipantsPanel.querySelectorAll('button[data-mute-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                toggleMute(parseInt(btn.dataset.muteUid || '', 10));
                jamRenderParticipants(list); // re-render locally, no server round-trip
            });
        });
        jamParticipantsPanel.querySelectorAll('button[data-kick-uid]').forEach(function (btn) {
            // Arm/disarm instead of confirm(): first click arms for 3s, second kicks.
            btn.addEventListener('click', function () {
                if (!btn.classList.contains('is-armed')) {
                    btn.classList.add('is-armed');
                    btn.textContent = 'confirm remove?';
                    btn._armTimer = window.setTimeout(function () {
                        btn.classList.remove('is-armed');
                        btn.textContent = 'remove';
                    }, 3000);
                    return;
                }
                clearTimeout(btn._armTimer);
                fetch(rest + 'jam/' + encodeURIComponent(jam.code || '') + '/kick', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.kickUid || '', 10) }),
                }).then(function (r) { return r.json(); }).then(jamApplyState)
                    .catch(function () { notify('Could not remove that listener — check your connection and try again.', true); });
            });
        });
    }
    var jamPendingPanel = null;
    // Host-only: `state.pending` is only present in the host's response.
    function jamRenderPending(pending) {
        if (!jam.active || !jam.isHost)
            return;
        if (!pending || !pending.length) {
            if (jamPendingPanel) {
                jamPendingPanel.remove();
                jamPendingPanel = null;
            }
            return;
        }
        if (!jamPendingPanel) {
            jamPendingPanel = document.createElement('div');
            jamPendingPanel.className = 'bhs-jam-pending';
            jamBanner.parentNode.insertBefore(jamPendingPanel, (jamParticipantsPanel || jamBanner).nextSibling);
        }
        jamPendingPanel.innerHTML = '<strong>Waiting to join:</strong> ' + pending.map(function (p) {
            return '<span class="bhs-jam-participant">' + esc(p.display_name)
                + ' <button type="button" class="bhs-link-btn" data-approve-uid="' + p.user_id + '">let in</button>'
                + ' <button type="button" class="bhs-link-btn" data-deny-uid="' + p.user_id + '">deny</button></span>';
        }).join('');
        jamPendingPanel.querySelectorAll('button[data-approve-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                fetch(rest + 'jam/' + encodeURIComponent(jam.code || '') + '/approve', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.approveUid || '', 10) }),
                }).then(function (r) { return r.json(); }).then(jamApplyState).catch(function () { });
            });
        });
        jamPendingPanel.querySelectorAll('button[data-deny-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                fetch(rest + 'jam/' + encodeURIComponent(jam.code || '') + '/deny', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.denyUid || '', 10) }),
                }).then(function (r) { return r.json(); }).then(jamApplyState).catch(function () { });
            });
        });
    }
    function jamRenderSkipVotes(state) {
        if (!jam.active || jam.controlMode !== 'vote_skip')
            return;
        var btn = document.getElementById('bhs-jam-vote-skip-btn');
        if (btn)
            btn.textContent = state.i_voted_skip
                ? ('Voted (' + state.skip_votes_count + '/' + state.skip_votes_needed + ')')
                : ('Vote to skip (' + state.skip_votes_count + '/' + state.skip_votes_needed + ')');
    }
    Promise.all([
        // WHY: without X-WP-Nonce, WP REST cookie auth treats the request as
        // anonymous, so resumeSeconds would always read back 0.
        fetch(rest + 'tracks', { headers: authHeaders() }).then(function (r) { return r.json(); }),
        fetch(rest + 'releases', { headers: authHeaders() }).then(function (r) { return r.json(); }),
        loggedIn ? fetch(rest + 'likes', { headers: authHeaders() }).then(function (r) { return r.json(); }) : Promise.resolve({ track_ids: [] }),
        loggedIn ? fetch(rest + 'playlists', { headers: authHeaders() }).then(function (r) { return r.json(); }) : Promise.resolve({ playlists: [] }),
    ]).then(function (results) {
        allTracks = results[0].tracks || [];
        releases = results[1].releases || [];
        likedIds = results[2].track_ids || [];
        myPlaylists = results[3].playlists || [];
        loadGenreOptions();
        // A shared-playlist link takes over the initial view; it loads after
        // allTracks because its track lookups depend on it.
        if (!maybeOpenSharedPlaylist() && !maybeOpenTrackDeepLink())
            renderView();
    }).catch(function () {
        library.innerHTML = '<p class="bhs-empty">Could not load the library right now.</p>';
    });
})();
