// BH Streaming player — full feature set. Organized into clearly
// separated sections since this file covers a lot of ground: state,
// rendering per view, the playback/queue engine, Media Session
// (unchanged in spirit from the original MVP — see the note there about
// the current iOS background-audio limitation, still true here), and
// likes/playlists/volume/related-tracks as additive layers on top.
(function () {
    var app = document.getElementById('bhs-app');
    if (!app) return;

    var rest = (window.BHSData && window.BHSData.rest) || '';
    var identityRest = (window.BHSData && window.BHSData.identity) || '';
    var nonce = (window.BHSData && window.BHSData.nonce) || '';
    var loggedIn = !!(window.BHSData && window.BHSData.loggedIn);

    var library = document.getElementById('bhs-library');
    var relatedBox = document.getElementById('bhs-related');
    var relatedList = document.getElementById('bhs-related-list');
    var nowplaying = document.getElementById('bhs-nowplaying');
    var artEl = document.getElementById('bhs-np-art');
    var titleEl = document.getElementById('bhs-np-title');
    var artistEl = document.getElementById('bhs-np-artist');
    var playPauseBtn = document.getElementById('bhs-playpause');
    var likeBtn = document.getElementById('bhs-like');
    var seek = document.getElementById('bhs-seek');
    var volume = document.getElementById('bhs-volume');
    var queuePanel = document.getElementById('bhs-queue-panel');
    var queueList = document.getElementById('bhs-queue-list');
    var playlistPicker = document.getElementById('bhs-playlist-picker');
    var playlistPickerList = document.getElementById('bhs-playlist-picker-list');
    var searchInput = document.getElementById('bhs-search');
    var genreFilter = document.getElementById('bhs-genre-filter');

    var lyricsPanel = document.getElementById('bhs-lyrics-panel');
    var lyricsBody = document.getElementById('bhs-lyrics-body');
    var lyricsToggleBtn = document.getElementById('bhs-lyrics-toggle');
    var qualityPanel = document.getElementById('bhs-quality-panel');
    var qualityList = document.getElementById('bhs-quality-list');
    var qualityToggleBtn = document.getElementById('bhs-quality-toggle');
    var eqPanel = document.getElementById('bhs-eq-panel');
    var eqToggleBtn = document.getElementById('bhs-eq-toggle');
    var vizToggleBtn = document.getElementById('bhs-viz-toggle');
    var vizCanvas = document.getElementById('bhs-visualizer');
    var importModal = document.getElementById('bhs-import-modal');

    var allTracks = [];
    var releases = [];
    var likedIds = [];
    var myPlaylists = [];
    var currentView = 'all';
    var queue = [];          // the ordered list of track objects currently being played through
    var queueIndex = -1;

    // Jam state — see class-jam.php for the server side of this.
    // `jam.active` gates most local input (shuffle/prev/next/seek/queue
    // clicks) to host-only while a session is running; a non-host
    // participant's UI becomes a mirror driven by jamApplyState(),
    // not local clicks.
    var jam = {
        active: false, isHost: false, code: null, controlMode: 'host',
        pollTimer: null, suppressNextPositionSync: false,
    };
    // The one and only playback element — always plays every track
    // directly and unrouted, regardless of CORS. Deliberately NEVER
    // passed to createMediaElementSource(); see the EQ/visualizer
    // section below for why a SEPARATE shadow element carries that risk
    // instead, so a mis-configured or third-party host (common for
    // aggregated/external tracks — see class-feeds.php) can never cause
    // actual playback to go silent.
    var audio = new Audio();
    var currentQuality = null;   // null = whatever track.url already is (the track's own default)
    var currentTrackLyrics = null; // parsed LRC lines for the track currently playing, if any
    var visualizerOn = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function authHeaders(extra) {
        var h = Object.assign({ 'X-WP-Nonce': nonce }, extra || {});
        return h;
    }

    /* ---------- filtering (search + genre, applied to the "All Tracks" view) ---------- */

    function filteredTracks() {
        var q = searchInput.value.trim().toLowerCase();
        var genre = genreFilter.value;
        return allTracks.filter(function (t) {
            if (genre && t.genres.indexOf(genre) === -1) return false;
            if (q && t.title.toLowerCase().indexOf(q) === -1 && t.artist.toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
    }

    function trackCardHtml(t) {
        var liked = likedIds.indexOf(t.id) !== -1;
        // A featured/external track's playability depends on ANOTHER
        // site's host staying up (bh-streaming never re-hosts it — see
        // BHS_Feeds) — 'degraded'/'down' surface that honestly here
        // rather than the listener only finding out by hitting play and
        // getting silence. Never hidden outright even when 'down': the
        // catalog entry (and its metadata) staying visible is the point,
        // it's just clearly labeled as currently unreachable.
        var healthBadge = '';
        if (t.source_health === 'down') healthBadge = '<span class="bhs-badge bhs-badge-down">Unavailable</span>';
        else if (t.source_health === 'degraded') healthBadge = '<span class="bhs-badge bhs-badge-degraded">Unreliable</span>';

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
            // UX-AUDIT-2026-07.md's cited finding — this bare
            // "No tracks match." replaced with the shared, server-
            // rendered empty-state component (BHSData.emptyState*,
            // BHY_Style::empty_state_html()), one source of truth with
            // every other front-end empty state in the ecosystem
            // instead of a second, bespoke JS string. Filtered vs.
            // zero-data is derivable right here (a search term or genre
            // is active) — same distinction bh-courses' catalog already
            // makes server-side.
            var isFiltered = !!(searchInput.value.trim() || genreFilter.value);
            library.innerHTML = tracks.length
                ? '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>'
                : (isFiltered ? (window.BHSData && BHSData.emptyStateFiltered) : (window.BHSData && BHSData.emptyStateZero)) || '<p class="bhs-empty">No tracks match.</p>';
            bindCardClicks(tracks);
        } else if (currentView === 'releases') {
            library.innerHTML = releases.length
                ? '<div class="bhs-grid">' + releases.map(function (r) {
                    return '<button type="button" class="bhs-card bhs-release-card" data-release="' + r.id + '">'
                        + '<img src="' + esc(r.artwork) + '" alt="" class="bhs-card-art">'
                        + '<div class="bhs-card-title">' + esc(r.title) + '</div>'
                        + '<div class="bhs-card-artist">' + esc(r.artist) + '</div>'
                        + '</button>';
                }).join('') + '</div>'
                : '<p class="bhs-empty">No releases yet.</p>';
            library.querySelectorAll('.bhs-release-card').forEach(function (card) {
                card.addEventListener('click', function () { openRelease(parseInt(card.dataset.release, 10)); });
            });
        } else if (currentView === 'liked') {
            var liked = allTracks.filter(function (t) { return likedIds.indexOf(t.id) !== -1; });
            library.innerHTML = liked.length
                ? '<div class="bhs-grid">' + liked.map(trackCardHtml).join('') + '</div>'
                : '<p class="bhs-empty">' + (loggedIn ? 'Nothing liked yet.' : 'Log in to like tracks.') + '</p>';
            bindCardClicks(liked);
        } else if (currentView === 'playlists') {
            renderPlaylistsView();
        }
    }

    function bindCardClicks(list) {
        library.querySelectorAll('.bhs-card[data-id]').forEach(function (card) {
            card.addEventListener('click', function () {
                var id = parseInt(card.dataset.id, 10);
                var index = list.findIndex(function (t) { return t.id === id; });
                var t = list[index];
                // A locked track never has a playable url at all (see
                // class-api.php's track_payload) — clicking it shows
                // whatever paywall notice the monetization plugin
                // supplied (bh-monetization-woo's own "become a
                // supporter" markup) instead of attempting playback.
                if (t && t.locked) { showLockNotice(t); return; }
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
        document.getElementById('bhs-back-from-lock').addEventListener('click', renderView);
    }

    function openRelease(releaseId) {
        var release = releases.find(function (r) { return r.id === releaseId; });
        if (!release) return;
        var tracks = release.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
        library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-to-releases">&larr; Releases</button>'
            + '<h2 class="bhs-release-title">' + esc(release.title) + '</h2>'
            + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
        document.getElementById('bhs-back-to-releases').addEventListener('click', renderView);
        bindCardClicks(tracks);
    }

    function renderPlaylistsView() {
        if (!loggedIn) { library.innerHTML = '<p class="bhs-empty">Log in to create playlists.</p>'; return; }
        if (!myPlaylists.length) { library.innerHTML = '<p class="bhs-empty">No playlists yet — use the + button on a track while it\'s playing to start one.</p>'; return; }

        library.innerHTML = '<div class="bhs-grid">' + myPlaylists.map(function (p) {
            return '<button type="button" class="bhs-card bhs-playlist-card" data-playlist="' + p.id + '">'
                + '<div class="bhs-card-title">' + esc(p.title) + '</div>'
                + '<div class="bhs-card-artist">' + p.track_ids.length + ' tracks</div>'
                + '</button>';
        }).join('') + '</div>';

        library.querySelectorAll('.bhs-playlist-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var playlist = myPlaylists.find(function (p) { return p.id === parseInt(card.dataset.playlist, 10); });
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
        document.getElementById('bhs-back-to-playlists').addEventListener('click', renderView);
        document.getElementById('bhs-playlist-share-toggle').addEventListener('click', function () {
            var endpoint = playlist.is_public ? 'unshare' : 'share';
            fetch(rest + 'playlists/' + playlist.id + '/' + endpoint, { method: 'POST', headers: authHeaders() })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var idx = myPlaylists.findIndex(function (p) { return p.id === playlist.id; });
                    if (idx !== -1) myPlaylists[idx] = data.playlist;
                    openOwnedPlaylist(data.playlist);
                });
        });
        bindCardClicks(tracks);
    }

    /* ---------- viewing someone else's shared playlist (read-only, no auth) ---------- */

    function maybeOpenSharedPlaylist() {
        var params = new URLSearchParams(window.location.search);
        var token = params.get('bhs_shared_playlist');
        if (!token) return false;

        library.innerHTML = '<p class="bhs-empty">Loading shared playlist…</p>';
        fetch(rest + 'playlists/shared/' + encodeURIComponent(token))
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!res.ok) {
                    library.innerHTML = '<p class="bhs-empty">' + esc((res.data && res.data.message) || 'This shared playlist link is invalid or has been revoked.') + '</p>';
                    return;
                }
                var playlist = res.data.playlist;
                // Shared tracks might reference tracks not in this
                // viewer's own /tracks list only if this were a
                // cross-site share — same-site shares always resolve
                // fully since allTracks already covers the whole catalog.
                var tracks = playlist.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
                library.innerHTML = '<h2 class="bhs-release-title">' + esc(playlist.title) + ' <span class="bhs-badge">Shared playlist</span></h2>'
                    + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
                bindCardClicks(tracks);
            })
            .catch(function () { library.innerHTML = '<p class="bhs-empty">Could not load this shared playlist right now.</p>'; });
        return true;
    }

    /* ---------- playback + queue ---------- */

    // Shuffle only ever reorders the UPCOMING portion of the queue
    // (indices after queueIndex) — history (what's already played,
    // indices before queueIndex) stays exactly as played, Apple-Music
    // style. originalOrder remembers the pre-shuffle sequence so turning
    // shuffle back off restores it for whatever hasn't played yet,
    // rather than re-randomizing again or losing the original order.
    var shuffleOn = false;
    var originalOrder = null;

    function shuffleArray(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
        }
        return arr;
    }

    function playQueue(list, index) {
        queue = list.slice();
        queueIndex = index;
        originalOrder = null;
        if (shuffleOn) applyShuffle();
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
        } else if (originalOrder) {
            // Restore original relative order for whatever's still ahead
            // of us; already-played history and the current track stay
            // exactly where they are — shuffle never rewrites the past.
            var playedIds = queue.slice(0, queueIndex + 1).map(function (t) { return t.id; });
            var remaining = originalOrder.filter(function (t) { return playedIds.indexOf(t.id) === -1; });
            queue = queue.slice(0, queueIndex + 1).concat(remaining);
            originalOrder = null;
        }
        var shuffleBtn = document.getElementById('bhs-shuffle-toggle');
        if (shuffleBtn) shuffleBtn.classList.toggle('active', shuffleOn);
        renderQueuePanel();
        if (jam.active && jam.isHost) jamPushState();
    }

    function playCurrent() {
        var t = queue[queueIndex];
        if (!t) return;

        currentQuality = null; // a fresh track always starts from its default encode
        shadowUrlOverride = null; // don't let a previous track's quality-URL override leak into this one (see switchToQuality)
        audio.src = t.url;
        audio.play().catch(function () { /* needs a user gesture first — the click that got us here counts */ });
        // A 402 here means a monetization plugin (if any) declined the
        // play — e.g. pay-per-play with an empty wallet. bh-streaming
        // itself has no idea what that means beyond "stop and say so";
        // the actual reason/message comes from whatever's hooked into
        // bhs_track_play_denied_message server-side.
        fetch(rest + 'tracks/' + t.id + '/play', { method: 'POST' })
            .then(function (r) { if (r.status === 402) return r.json().then(function (d) { throw new Error(d.message || 'Payment required.'); }); })
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

        qualityToggleBtn.style.display = (t.qualities && t.qualities.length) ? '' : 'none';
        renderLyricsFor(t);
        renderQualityFor(t);
        // If the EQ/visualizer graph is already engaged (the listener
        // turned it on for a previous track), re-evaluate whether THIS
        // new track is safe to route through it — see the EQ/visualizer
        // section below for why this can't just carry over blindly.
        onTrackChangedForAudioGraph(t);

        updateMediaSession(t);
        loadRelated(t.id);
        renderQueuePanel();
    }

    function playPrev() {
        if (jam.active && !jam.isHost) return; // participants don't drive transport — see jamApplyState
        if (queueIndex > 0) { queueIndex--; playCurrent(); if (jam.active && jam.isHost) jamPushState(); }
    }
    function playNext() {
        if (jam.active && !jam.isHost) return;
        if (queueIndex < queue.length - 1) { queueIndex++; playCurrent(); if (jam.active && jam.isHost) jamPushState(); }
    }

    // Apple-Music-style split: a "History" section (already played,
    // oldest first, most-recent nearest the now-playing divider) and an
    // "Up Next" section (what's coming, respecting shuffle if it's on).
    // Same rendering is reused as-is for a Jam session's shared queue —
    // Jam just keeps `queue`/`queueIndex` in sync with the host instead
    // of the local click handlers driving them (see jamApplyState()).
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
                // In a Jam session, only the host can jump the queue —
                // a participant's queue view is a read-only mirror of
                // whatever the host is actually playing (see jamApplyState).
                if (jam.active && !jam.isHost) return;
                queueIndex = parseInt(item.dataset.index, 10);
                playCurrent();
                if (jam.active && jam.isHost) jamPushState();
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
            if (!related.length) { relatedBox.style.display = 'none'; return; }
            relatedBox.style.display = '';
            relatedList.innerHTML = related.map(trackCardHtml).join('');
            bindCardClicks(related);
        }).catch(function () { relatedBox.style.display = 'none'; });
    }

    /* ---------- Media Session (unchanged approach from the original MVP) ---------- */

    function updateMediaSession(t) {
        if (!('mediaSession' in navigator)) return;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: t.title, artist: t.artist,
            artwork: [{ src: t.artwork, sizes: '512x512', type: 'image/png' }],
        });
        navigator.mediaSession.setActionHandler('play', function () { audio.play(); });
        navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
        navigator.mediaSession.setActionHandler('previoustrack', playPrev);
        navigator.mediaSession.setActionHandler('nexttrack', playNext);
        navigator.mediaSession.setActionHandler('seekto', function (details) {
            if (details.seekTime != null) audio.currentTime = details.seekTime;
        });
    }

    audio.addEventListener('play', function () {
        playPauseBtn.innerHTML = '&#10074;&#10074;';
        if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing';
    });
    audio.addEventListener('pause', function () {
        playPauseBtn.innerHTML = '&#9658;';
        if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
    });
    audio.addEventListener('ended', playNext);

    /* ---------- stream health (task 40) ---------- */
    // Shared hosting means bandwidth/uptime is genuinely variable —
    // this surfaces THIS session's actual playback trouble (stalls,
    // slow starts, hard errors) in real time, distinct from
    // source_health above (which is a periodic server-side check of the
    // SOURCE, not of what this one listener is experiencing right now).
    // A listener on a bad connection to an otherwise-healthy source
    // still deserves to know it's their stream, not just a dead track.
    var streamHealth = { stallTimer: null, stalling: false, errorStreak: 0 };
    var streamBadge = document.createElement('span');
    streamBadge.className = 'bhs-stream-health';
    streamBadge.style.display = 'none';
    nowplaying.insertBefore(streamBadge, nowplaying.firstChild);

    function setStreamBadge(state) {
        if (state === 'poor') { streamBadge.textContent = 'Poor connection…'; streamBadge.className = 'bhs-stream-health bhs-stream-health--poor'; streamBadge.style.display = ''; }
        else if (state === 'error') { streamBadge.textContent = 'Playback error — retrying…'; streamBadge.className = 'bhs-stream-health bhs-stream-health--error'; streamBadge.style.display = ''; }
        else { streamBadge.style.display = 'none'; }
    }

    // 'waiting' fires when playback has to pause for more data — a
    // single blip is normal (any buffered stream does this briefly);
    // only flag it as a real "poor connection" if it hasn't recovered
    // within a couple seconds, so a listener isn't shown an alarming
    // badge for every ordinary micro-buffer.
    audio.addEventListener('waiting', function () {
        if (streamHealth.stallTimer) clearTimeout(streamHealth.stallTimer);
        streamHealth.stallTimer = setTimeout(function () {
            streamHealth.stalling = true;
            setStreamBadge('poor');
            maybeStepDownQuality();
        }, 2000);
    });
    audio.addEventListener('playing', function () {
        if (streamHealth.stallTimer) { clearTimeout(streamHealth.stallTimer); streamHealth.stallTimer = null; }
        if (streamHealth.stalling) { streamHealth.stalling = false; setStreamBadge('none'); }
        streamHealth.errorStreak = 0;
    });
    audio.addEventListener('error', function () {
        streamHealth.errorStreak++;
        setStreamBadge('error');
        // A couple of quick retries (the source might just have had a
        // momentary blip) before giving up and telling the listener
        // plainly rather than looping forever on a genuinely dead
        // source — three tries mirrors BHS_Feeds' own
        // HEALTH_DOWN_AFTER_FAILS threshold for the same reasoning.
        if (streamHealth.errorStreak <= 3) {
            setTimeout(function () { audio.load(); audio.play().catch(function () {}); }, 1500 * streamHealth.errorStreak);
        } else {
            setStreamBadge('none');
            var t = queue[queueIndex];
            if (t) showLockNotice(Object.assign({}, t, {
                locked: true,
                lock_notice: '<p>This track isn’t playable right now — its source may be temporarily unavailable.</p>',
            }));
        }
    });

    // If this track offers a lower-bitrate encode and playback is
    // visibly struggling, drop to it automatically rather than just
    // showing a badge and hoping — the same switchToQuality() the
    // manual Quality panel uses, so position/playing-state preservation
    // is identical either way.
    function maybeStepDownQuality() {
        var t = queue[queueIndex];
        if (!t || !t.qualities || !t.qualities.length) return;
        var order = ['lossless', 'high', 'standard'];
        var currentIdx = order.indexOf(currentQuality || (t.qualities[0] && t.qualities[0].label));
        for (var i = currentIdx + 1; i < order.length; i++) {
            var candidate = t.qualities.find(function (q) { return q.label === order[i]; });
            if (candidate) { switchToQuality(candidate.label, candidate.url); return; }
        }
    }
    audio.addEventListener('timeupdate', function () {
        if (audio.duration) seek.value = (audio.currentTime / audio.duration) * 100;
        if ('mediaSession' in navigator && audio.duration && 'setPositionState' in navigator.mediaSession) {
            navigator.mediaSession.setPositionState({ duration: audio.duration, playbackRate: 1, position: audio.currentTime });
        }
        updateLyricsHighlight();
    });

    playPauseBtn.addEventListener('click', function () {
        if (jam.active && !jam.isHost) return; // a participant doesn't drive playback directly, just hears the host's
        audio.paused ? audio.play() : audio.pause();
        if (jam.active && jam.isHost) jamPushState();
    });
    document.getElementById('bhs-prev').addEventListener('click', playPrev);
    document.getElementById('bhs-next').addEventListener('click', playNext);
    seek.addEventListener('input', function () {
        if (jam.active && !jam.isHost) return;
        if (audio.duration) audio.currentTime = (seek.value / 100) * audio.duration;
        if (jam.active && jam.isHost) jamPushState();
    });
    document.getElementById('bhs-shuffle-toggle').addEventListener('click', function () {
        if (jam.active && !jam.isHost) return;
        toggleShuffle();
    });

    // Desktop-only in practice: mobile browsers largely ignore
    // audio.volume and defer to the hardware volume buttons instead —
    // this still doesn't hurt to have, it's just a no-op there rather
    // than something actively broken.
    volume.addEventListener('input', function () { audio.volume = volume.value / 100; });

    document.getElementById('bhs-queue-toggle').addEventListener('click', function () {
        queuePanel.style.display = queuePanel.style.display === 'none' ? '' : 'none';
    });

    /* ---------- likes ---------- */

    likeBtn.addEventListener('click', function () {
        if (!loggedIn) { alert('Log in to like tracks.'); return; }
        var t = queue[queueIndex];
        if (!t) return;
        fetch(rest + 'likes/' + t.id, { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.liked) { likedIds.push(t.id); } else { likedIds = likedIds.filter(function (id) { return id !== t.id; }); }
                likeBtn.classList.toggle('liked', data.liked);
                likeBtn.innerHTML = data.liked ? '&#9829;' : '&#9825;';
                if (currentView === 'liked') renderView();
            });
    });

    /* ---------- playlists ---------- */

    document.getElementById('bhs-add-playlist').addEventListener('click', function () {
        if (!loggedIn) { alert('Log in to use playlists.'); return; }
        renderPlaylistPicker();
        playlistPicker.style.display = '';
    });
    document.getElementById('bhs-playlist-picker-close').addEventListener('click', function () { playlistPicker.style.display = 'none'; });

    function renderPlaylistPicker() {
        playlistPickerList.innerHTML = myPlaylists.length
            ? myPlaylists.map(function (p) { return '<button type="button" class="bhs-btn bhs-playlist-option" data-id="' + p.id + '">' + esc(p.title) + '</button>'; }).join('')
            : '<p class="bhs-empty">No playlists yet.</p>';
        playlistPickerList.querySelectorAll('.bhs-playlist-option').forEach(function (btn) {
            btn.addEventListener('click', function () { addCurrentToPlaylist(parseInt(btn.dataset.id, 10)); });
        });
    }

    function addCurrentToPlaylist(playlistId) {
        var t = queue[queueIndex];
        if (!t) return;
        fetch(rest + 'playlists/' + playlistId + '/tracks', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ track_id: t.id }),
        }).then(function (r) { return r.json(); }).then(function (data) {
            var idx = myPlaylists.findIndex(function (p) { return p.id === playlistId; });
            if (idx !== -1) myPlaylists[idx] = data.playlist;
            playlistPicker.style.display = 'none';
        });
    }

    document.getElementById('bhs-new-playlist-create').addEventListener('click', function () {
        var nameEl = document.getElementById('bhs-new-playlist-name');
        var name = nameEl.value.trim();
        if (!name) return;
        fetch(rest + 'playlists', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ title: name }),
        }).then(function (r) { return r.json(); }).then(function (data) {
            myPlaylists.push(data.playlist);
            nameEl.value = '';
            addCurrentToPlaylist(data.playlist.id);
        });
    });

    /* ---------- auth (bh-identity) ---------- */

    var authModal = document.getElementById('bhs-auth-modal');
    var authMode = 'login';
    var emailField = document.getElementById('bhs-auth-email');
    var authSubmitBtn = document.getElementById('bhs-auth-submit');
    var authError = document.getElementById('bhs-auth-error');
    var loginOpenBtn = document.getElementById('bhs-login-open');
    var accountInfo = document.getElementById('bhs-account-info');
    var accountUsername = document.getElementById('bhs-account-username');

    function refreshAccountUI() {
        loginOpenBtn.style.display = loggedIn ? 'none' : '';
        accountInfo.style.display = loggedIn ? '' : 'none';
    }

    loginOpenBtn.addEventListener('click', function () { authModal.style.display = ''; });
    document.getElementById('bhs-auth-close').addEventListener('click', function () { authModal.style.display = 'none'; });

    document.querySelectorAll('.bhs-auth-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.bhs-auth-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            authMode = tab.dataset.mode;
            emailField.style.display = authMode === 'register' ? '' : 'none';
            authSubmitBtn.textContent = authMode === 'register' ? 'Sign Up' : 'Log In';
            authError.textContent = '';
        });
    });

    authSubmitBtn.addEventListener('click', function () {
        var username = document.getElementById('bhs-auth-username').value.trim();
        var password = document.getElementById('bhs-auth-password').value;
        var body = { username: username, password: password };
        if (authMode === 'register') body.email = emailField.value.trim();

        fetch(identityRest + authMode, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
        })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!res.ok) { authError.textContent = res.data.message || 'Something went wrong.'; return; }
                loggedIn = true;
                authModal.style.display = 'none';
                authError.textContent = '';
                refreshAccountUI();
                reloadUserData();
            });
    });

    document.getElementById('bhs-logout').addEventListener('click', function () {
        fetch(identityRest + 'logout', { method: 'POST', headers: { 'X-WP-Nonce': nonce } }).then(function () {
            loggedIn = false;
            likedIds = [];
            myPlaylists = [];
            refreshAccountUI();
            if (currentView === 'liked' || currentView === 'playlists') renderView();
        });
    });

    // After logging in mid-session, the account's own likes/playlists
    // need to actually load — a plain page reload would also work, but
    // this keeps whatever they were listening to playing uninterrupted.
    function reloadUserData() {
        fetch(identityRest + 'session').then(function (r) { return r.json(); }).then(function (s) {
            if (s.loggedIn) accountUsername.textContent = s.username;
        });
        Promise.all([
            fetch(rest + 'likes', { headers: authHeaders() }).then(function (r) { return r.json(); }),
            fetch(rest + 'playlists', { headers: authHeaders() }).then(function (r) { return r.json(); }),
        ]).then(function (results) {
            likedIds = results[0].track_ids || [];
            myPlaylists = results[1].playlists || [];
            if (currentView === 'liked' || currentView === 'playlists') renderView();
        });
    }

    refreshAccountUI();
    if (loggedIn) {
        fetch(identityRest + 'session').then(function (r) { return r.json(); }).then(function (s) {
            if (s.loggedIn) accountUsername.textContent = s.username;
        });
    }

    /* ---------- lyrics ---------- */

    // Parses [mm:ss.xx]text lines into {time, text} objects sorted by
    // time. Deliberately tolerant of missing/extra fields (a two-digit
    // vs three-digit fraction, no fraction at all) since LRC exports
    // vary slightly between tools — a line that doesn't match the
    // timestamp pattern at all is just skipped rather than aborting the
    // whole parse.
    function parseLRC(lrc) {
        var lines = [];
        (lrc || '').split(/\r?\n/).forEach(function (raw) {
            var m = raw.match(/^\[(\d+):(\d+(?:\.\d+)?)\](.*)$/);
            if (!m) return;
            var time = parseInt(m[1], 10) * 60 + parseFloat(m[2]);
            var text = m[3].trim();
            if (text) lines.push({ time: time, text: text });
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
        } else if (lyrics.plain) {
            currentTrackLyrics = null;
            lyricsBody.innerHTML = '<div class="bhs-lyric-plain">' + esc(lyrics.plain).replace(/\n/g, '<br>') + '</div>';
        } else {
            currentTrackLyrics = null;
            lyricsBody.innerHTML = '<p class="bhs-empty">No lyrics for this track.</p>';
        }
    }

    function updateLyricsHighlight() {
        if (!currentTrackLyrics || !currentTrackLyrics.length || lyricsPanel.style.display === 'none') return;
        var t = audio.currentTime;
        var activeIndex = -1;
        for (var i = 0; i < currentTrackLyrics.length; i++) {
            if (currentTrackLyrics[i].time <= t) activeIndex = i; else break;
        }
        var lines = lyricsBody.querySelectorAll('.bhs-lyric-line');
        lines.forEach(function (el, i) {
            el.classList.toggle('active', i === activeIndex);
        });
        if (activeIndex >= 0 && lines[activeIndex]) {
            lines[activeIndex].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    lyricsToggleBtn.addEventListener('click', function () {
        var open = lyricsPanel.style.display !== 'none';
        lyricsPanel.style.display = open ? 'none' : '';
        qualityPanel.style.display = 'none';
        eqPanel.style.display = 'none';
    });

    /* ---------- quality selection ---------- */

    // Switching quality mid-track preserves playback position and
    // paused/playing state — this is a listener preference change, not
    // a "start over" action.
    function switchToQuality(label, url) {
        var wasPlaying = !audio.paused;
        var position = audio.currentTime;
        currentQuality = label;
        shadowUrlOverride = (label === 'default') ? null : url; // engageGraphForCurrentTrack() uses this instead of t.url when set
        audio.src = url;
        audio.addEventListener('loadedmetadata', function onLoaded() {
            audio.removeEventListener('loadedmetadata', onLoaded);
            audio.currentTime = position;
            if (wasPlaying) audio.play().catch(function () {});
        });
        renderQualityFor(queue[queueIndex]);
        // A quality switch is effectively a new URL for the same logical
        // track — re-run the same eligibility check the EQ/visualizer
        // graph does for any track change, since a different encode
        // could in principle be hosted somewhere with different CORS
        // behavior than the default. graphRequestToken (incremented
        // inside engageGraphForCurrentTrack) makes sure a stale probe
        // from the PREVIOUS quality can't win a race against this one.
        if (graphWanted) engageGraphForCurrentTrack();
    }

    function renderQualityFor(t) {
        if (!t) return;
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
            btn.addEventListener('click', function () { switchToQuality(btn.dataset.label, btn.dataset.url); });
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
     * IMPORTANT CORS constraint this section is built around: per the
     * Web Audio spec, once an <audio> element is wired into a Web Audio
     * graph via createMediaElementSource(), that element's audio is
     * SILENCED entirely for any resource that isn't same-origin or
     * doesn't come with a permissive Access-Control-Allow-Origin header
     * — not an error, not a fallback, just silence, with no exception to
     * catch. bh-streaming's whole aggregator feature (class-feeds.php)
     * exists specifically to feature audio hosted on OTHER servers this
     * site doesn't control and can't guarantee send CORS headers, so
     * wiring the PRIMARY playback element (`audio` above) into this
     * graph would risk silently breaking playback for exactly those
     * external/aggregated tracks — unacceptable regression risk for a
     * "bonus" feature like EQ/visualizer.
     *
     * The fix: EQ and the visualizer run through a completely separate,
     * hidden `shadowAudio` element that only ever becomes the actual
     * audible output when the CURRENT track has been confirmed safe
     * (same-origin, or a live CORS probe succeeds). `audio` (primary)
     * is muted only for the duration that shadowAudio is confirmed safe
     * and actively engaged; the instant a track turns out to be
     * ineligible, shadowAudio is paused and `audio` is unmuted — so the
     * worst case for an ungraphable track is simply "no EQ/visualizer
     * for this one," never silence.
     */
    var EQ_BANDS = [60, 250, 1000, 4000, 8000, 14000]; // fixed, musically-spread peaking filters — same "chain of BiquadFilterNodes" approach Funkwhale's own web client and most open-source web players use, not anything more exotic

    var audioCtx = null, shadowAudio = null, sourceNode = null, analyserNode = null, eqFilters = null, graphBuilt = false;
    var graphEngaged = false;   // true only while shadowAudio is the actual audible output
    var graphWanted = false;    // true once the listener has opened EQ or Visualizer at least once this session
    var eqBandsEl = document.getElementById('bhs-eq-bands');
    var graphNotice = null;     // lazily-created "unavailable for this track" message

    function ensureGraphNotice() {
        if (graphNotice) return graphNotice;
        graphNotice = document.createElement('p');
        graphNotice.className = 'bhs-empty';
        graphNotice.textContent = 'EQ and the visualizer aren’t available for this track — it’s hosted on a server that doesn’t grant cross-origin audio access. Playback itself is unaffected.';
        return graphNotice;
    }

    function isSameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; }
        catch (e) { return false; }
    }

    // A real, cheap capability probe rather than guessing: a tiny
    // ranged GET in explicit 'cors' mode. If the host doesn't send
    // Access-Control-Allow-Origin, the fetch PROMISE REJECTS (browsers
    // enforce this at the network layer for mode:'cors'), which we can
    // reliably catch — unlike the silent-mute failure mode this whole
    // section exists to avoid.
    function probeCors(url) {
        if (isSameOrigin(url)) return Promise.resolve(true);
        return fetch(url, { method: 'GET', mode: 'cors', headers: { 'Range': 'bytes=0-0' } })
            .then(function (r) { return r.ok || r.status === 206; })
            .catch(function () { return false; });
    }

    // Built once, lazily, on first EQ/Visualizer use — browsers block
    // AudioContext until a user gesture, and createMediaElementSource()
    // can only ever be called ONCE per element for its whole lifetime.
    // Built against shadowAudio, never against the primary `audio`.
    function ensureAudioGraph() {
        if (graphBuilt) return;
        if (!window.AudioContext && !window.webkitAudioContext) return; // ancient-browser fallback: EQ/visualizer simply unavailable, playback itself is unaffected
        graphBuilt = true;

        shadowAudio = new Audio();
        shadowAudio.crossOrigin = 'anonymous';

        var Ctx = window.AudioContext || window.webkitAudioContext;
        audioCtx = new Ctx();
        sourceNode = audioCtx.createMediaElementSource(shadowAudio);
        eqFilters = EQ_BANDS.map(function (freq) {
            var f = audioCtx.createBiquadFilter();
            f.type = 'peaking'; f.frequency.value = freq; f.Q.value = 1; f.gain.value = 0;
            return f;
        });
        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 128;

        var node = sourceNode;
        eqFilters.forEach(function (f) { node.connect(f); node = f; });
        node.connect(analyserNode);
        analyserNode.connect(audioCtx.destination);

        renderEqBands();
    }

    // Monotonic token guarding every async engage attempt — incremented
    // on every call (track change OR quality switch OR re-engage), so a
    // stale probe/play promise that resolves late (from a track the
    // listener has since moved past, or a superseded quality URL on the
    // SAME track) can never act on the wrong request. Comparing
    // `queue[queueIndex] !== t` alone (the original guard) missed the
    // same-track-different-quality-URL case flagged in review.
    var graphRequestToken = 0;

    // Attempts to make shadowAudio the actual audible output for
    // whatever's currently loaded in the primary element. Called when
    // the listener opens EQ/Visualizer, and again on every track or
    // quality change while either panel has ever been used (see
    // onTrackChangedForAudioGraph). Critically, `audio.muted` is only
    // ever set once shadowAudio has confirmed it's actually playing
    // (its 'playing' event) — never on probe success alone — so a
    // shadow element that fails to decode, stalls, or rejects play()
    // after a successful CORS probe can NEVER leave the listener with
    // silence: the primary element stays audible the whole time.
    function engageGraphForCurrentTrack() {
        var t = queue[queueIndex];
        if (!t || !t.url) return;
        var url = (currentQuality && shadowUrlOverride) || t.url;
        var myToken = ++graphRequestToken;

        ensureAudioGraph();
        if (!audioCtx) { showGraphNotice(true); return; } // no Web Audio support at all in this browser

        probeCors(url).then(function (eligible) {
            if (myToken !== graphRequestToken) return; // superseded by a newer track/quality change — ignore
            if (!eligible) { disengageGraph(); showGraphNotice(true); return; }

            showGraphNotice(false);
            shadowAudio.src = url;
            shadowAudio.currentTime = audio.currentTime;

            // Do NOT mute the primary element yet — wait for shadowAudio
            // to actually confirm it's producing sound. audio.muted only
            // flips once 'playing' fires below, and only if this is still
            // the current request.
            var onPlaying = function () {
                shadowAudio.removeEventListener('playing', onPlaying);
                if (myToken !== graphRequestToken) return;
                audio.muted = true;
                graphEngaged = true;
            };
            shadowAudio.addEventListener('playing', onPlaying);
            shadowAudio.addEventListener('error', function onErr() {
                shadowAudio.removeEventListener('error', onErr);
                if (myToken !== graphRequestToken) return;
                disengageGraph();
                showGraphNotice(true);
            }, { once: true });

            if (!audio.paused) {
                shadowAudio.play().catch(function () {
                    if (myToken !== graphRequestToken) return;
                    disengageGraph();
                    showGraphNotice(true);
                });
            }
            // If the primary is currently paused, shadowAudio simply
            // stays loaded-but-paused too — no mute happens until actual
            // playback starts, so there's nothing to restore in that case.
        });
    }

    var shadowUrlOverride = null; // set by switchToQuality() when the active quality differs from the track's default URL

    function disengageGraph() {
        if (shadowAudio) shadowAudio.pause();
        audio.muted = false;
        graphEngaged = false;
    }

    function showGraphNotice(show) {
        var notice = ensureGraphNotice();
        if (show) {
            if (!eqPanel.contains(notice)) eqPanel.appendChild(notice);
            vizCanvas.style.visibility = 'hidden';
        } else {
            if (eqPanel.contains(notice)) eqPanel.removeChild(notice);
            vizCanvas.style.visibility = '';
        }
    }

    // Keeps the shadow element roughly in sync with the primary one
    // while engaged — two independently-decoded streams can drift a
    // little over a long track; a periodic hard resync (not sample-
    // accurate, just bounded) is enough for EQ/visualizer purposes,
    // which are enhancements, not the source of truth for playback
    // position (the primary element still owns seek/duration/MediaSession).
    setInterval(function () {
        if (!graphEngaged || !shadowAudio) return;
        if (Math.abs(shadowAudio.currentTime - audio.currentTime) > 0.35) {
            shadowAudio.currentTime = audio.currentTime;
        }
        if (audio.paused && !shadowAudio.paused) shadowAudio.pause();
        if (!audio.paused && shadowAudio.paused) shadowAudio.play().catch(function () {});
    }, 2000);

    // Called from playCurrent() on every track change. Only does
    // anything if the listener has already opted into EQ/Visualizer at
    // least once — otherwise the graph is never even built, and every
    // track just plays through the plain primary element as always.
    function onTrackChangedForAudioGraph() {
        if (!graphWanted) return;
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
                var i = parseInt(slider.dataset.index, 10);
                if (eqFilters && eqFilters[i]) eqFilters[i].gain.value = parseFloat(slider.value);
            });
        });
    }

    // Disengages the shadow graph entirely once NEITHER the EQ panel nor
    // the visualizer wants it running anymore — otherwise shadowAudio
    // would keep silently playing in the background (and the primary
    // element would stay muted) after the listener closes both surfaces.
    function disengageGraphIfUnwanted() {
        var eqOpen = eqPanel.style.display !== 'none';
        if (!eqOpen && !visualizerOn) disengageGraph();
    }

    eqToggleBtn.addEventListener('click', function () {
        var wasOpen = eqPanel.style.display !== 'none';
        eqPanel.style.display = wasOpen ? 'none' : '';
        lyricsPanel.style.display = 'none';
        qualityPanel.style.display = 'none';

        if (wasOpen) {
            disengageGraphIfUnwanted();
        } else {
            graphWanted = true;
            engageGraphForCurrentTrack();
        }
    });
    document.getElementById('bhs-eq-reset').addEventListener('click', function () {
        if (!eqFilters) return;
        eqFilters.forEach(function (f) { f.gain.value = 0; });
        eqBandsEl.querySelectorAll('input[type=range]').forEach(function (s) { s.value = 0; });
    });

    // Visualizer: a simple bar-graph over the analyser's frequency-domain
    // data — reads from the SAME shadow-graph analyser as the EQ, so it's
    // subject to the exact same eligibility gating above (no separate
    // CORS risk of its own). Only drawn while toggled on AND the graph
    // is actually engaged for the current track.
    var vizCtx = vizCanvas.getContext('2d');
    function drawVisualizer() {
        if (!visualizerOn) return;
        requestAnimationFrame(drawVisualizer);
        if (!analyserNode || !graphEngaged || audio.paused) return;

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
        } else {
            disengageGraphIfUnwanted();
        }
    });

    /* ---------- local file import ---------- */

    document.getElementById('bhs-import-open').addEventListener('click', function () {
        if (!loggedIn) { alert('Log in to import your own music.'); return; }
        importModal.style.display = 'flex';
    });
    document.getElementById('bhs-import-close').addEventListener('click', function () { importModal.style.display = 'none'; });

    document.getElementById('bhs-import-submit').addEventListener('click', function () {
        var fileInput = document.getElementById('bhs-import-file');
        var errorBox = document.getElementById('bhs-import-error');
        errorBox.textContent = '';
        if (!fileInput.files.length) { errorBox.textContent = 'Choose a file first.'; return; }

        var form = new FormData();
        form.append('audio', fileInput.files[0]);
        var title = document.getElementById('bhs-import-title').value.trim();
        var artist = document.getElementById('bhs-import-artist').value.trim();
        if (title) form.append('title', title);
        if (artist) form.append('artist', artist);

        // No Content-Type header here — the browser sets the correct
        // multipart boundary itself when the body is a FormData object;
        // setting it manually would break the boundary.
        fetch(rest + 'import', { method: 'POST', headers: authHeaders(), body: form })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!res.ok) { errorBox.textContent = (res.data && res.data.message) || 'Import failed.'; return; }
                allTracks.push(res.data.track);
                importModal.style.display = 'none';
                fileInput.value = '';
                if (currentView === 'all') renderView();
            })
            .catch(function () { errorBox.textContent = 'Could not reach the server right now.'; });
    });

    /* ---------- tabs + filters ---------- */

    document.getElementById('bhs-tabs').querySelectorAll('.bhs-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.bhs-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentView = tab.dataset.view;
            renderView();
        });
    });
    searchInput.addEventListener('input', function () { if (currentView === 'all') renderView(); });
    genreFilter.addEventListener('change', function () { if (currentView === 'all') renderView(); });

    /* ---------- initial load ---------- */

    function loadGenreOptions() {
        var genres = {};
        allTracks.forEach(function (t) { t.genres.forEach(function (g) { genres[g] = true; }); });
        Object.keys(genres).sort().forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g; opt.textContent = g;
            genreFilter.appendChild(opt);
        });
    }

    /* ---------- Jam (shared listening) ---------- */
    // Polling-first (see class-jam.php's header note on why): every
    // 2s, whoever isn't the host fetches /jam/{code}/state and applies
    // it wholesale via jamApplyState(); the host instead PUSHES its own
    // state on every local transport action (see the guarded handlers
    // above) so a host's own listening feels instant, never waiting on
    // its own poll round-trip.

    var jamModal = document.getElementById('bhs-jam-modal');
    var jamBanner = document.getElementById('bhs-jam-banner');
    var jamErrorEl = document.getElementById('bhs-jam-error');

    function jamShowError(msg) { if (jamErrorEl) jamErrorEl.textContent = msg || ''; }

    document.getElementById('bhs-jam-toggle').addEventListener('click', function () {
        if (jam.active) { jamRenderBanner(); jamModal.style.display = ''; return; }
        jamModal.style.display = jamModal.style.display === 'none' ? '' : 'none';
        jamShowError('');
    });
    document.getElementById('bhs-jam-close').addEventListener('click', function () { jamModal.style.display = 'none'; });

    document.getElementById('bhs-jam-create').addEventListener('click', function () {
        if (!loggedIn) { jamShowError('Log in to start a Jam.'); return; }
        if (!queue.length) { jamShowError('Play something first — Jam starts from your current queue.'); return; }
        var voteMode = document.getElementById('bhs-jam-vote-mode').checked;
        var approvalMode = document.getElementById('bhs-jam-approval-mode').checked;
        var maxParticipants = parseInt(document.getElementById('bhs-jam-max-participants').value, 10) || 0;
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
                if (!res.ok) { jamShowError(res.d.message || 'Could not start a Jam.'); return; }
                jamStart(res.d, true);
            }).catch(function () { jamShowError('Could not start a Jam right now.'); });
    });

    document.getElementById('bhs-jam-join').addEventListener('click', function () {
        if (!loggedIn) { jamShowError('Log in to join a Jam.'); return; }
        var code = (document.getElementById('bhs-jam-code-input').value || '').trim().toUpperCase();
        if (!code) return;
        fetch(rest + 'jam/' + encodeURIComponent(code) + '/join', { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
            .then(function (res) {
                if (res.status === 202 && res.d.pending) {
                    // Approval-required mode: nothing to render as a live
                    // session yet — keep polling THIS SAME join endpoint
                    // until the host approves (or the request is denied,
                    // which shows up as a plain join failure once the
                    // host clears it from pending — there's no separate
                    // "you were denied" signal, matching how a kicked
                    // participant's poll just starts failing too).
                    jamShowError(res.d.message || 'Waiting for the host…');
                    jamAwaitApproval(code);
                    return;
                }
                if (res.status >= 400) { jamShowError((res.d && res.d.message) || 'Could not join that Jam.'); return; }
                jamStart(res.d, false);
            }).catch(function () { jamShowError('Could not join that Jam right now.'); });
    });

    var jamApprovalPoll = null;
    function jamAwaitApproval(code) {
        if (jamApprovalPoll) clearInterval(jamApprovalPoll);
        jamApprovalPoll = setInterval(function () {
            fetch(rest + 'jam/' + encodeURIComponent(code) + '/join', { method: 'POST', headers: authHeaders() })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                .then(function (res) {
                    if (res.status === 202) return; // still waiting
                    clearInterval(jamApprovalPoll);
                    jamApprovalPoll = null;
                    if (res.status >= 400) { jamShowError((res.d && res.d.message) || 'The host didn’t let you in.'); return; }
                    jamStart(res.d, false);
                }).catch(function () {});
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
        if (jam.pollTimer) clearInterval(jam.pollTimer);
        // The host still polls, at a slower cadence, purely to pick up
        // vote-skip results and the participant list — its own transport
        // state is never overwritten by a poll response (see
        // jamApplyState's isHost short-circuit).
        jam.pollTimer = setInterval(jamPoll, isHost ? 4000 : 2000);
    }

    function jamPoll() {
        if (!jam.active || !jam.code) return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/state', { headers: authHeaders() })
            .then(function (r) {
                if (r.status === 404) { jamEnd('This Jam has ended.'); throw new Error('ended'); }
                // 403 here (never for the host, who's always a
                // participant) means the host removed us — get_state's
                // own participant check is what actually detects this,
                // there's no separate "was I kicked" signal to poll.
                if (r.status === 403) { jamEnd('You were removed from this Jam by the host.'); throw new Error('kicked'); }
                return r.json();
            })
            .then(jamApplyState)
            .catch(function () { /* a missed poll just tries again next tick */ });
    }

    // The one function that turns server state into what's actually
    // playing. For a non-host, this IS the transport — queue/index/
    // playing/position all come from here, not from any local click.
    // For the host, only the parts that could change WITHOUT the host's
    // own action (participants, vote-skip tally, and — if a vote just
    // cleared — the index/position it forced) are applied; the host's
    // own play/pause/seek is authoritative locally and already pushed.
    function jamApplyState(state) {
        jam.controlMode = state.control_mode;
        jamRenderParticipants(state.participants);
        jamRenderSkipVotes(state);
        jamRenderPending(state.pending);

        if (jam.isHost) return; // see comment above — host doesn't take transport dictation from its own poll

        var incomingIds = state.queue.map(function (t) { return t.id; });
        var currentIds = queue.map(function (t) { return t.id; });
        var queueChanged = incomingIds.length !== currentIds.length || incomingIds.some(function (id, i) { return id !== currentIds[i]; });

        if (queueChanged) queue = state.queue;
        var trackChanged = queueIndex !== state.index || queueChanged;
        queueIndex = state.index;

        if (trackChanged) {
            playCurrent();
        }

        // Position projection: the server gives us "position P as of
        // server-time T," not "position right now" — a participant who
        // polls every 2s and just naively sets currentTime = P on
        // arrival would visibly stutter backward-in-time by however
        // stale the response already was. Project forward using elapsed
        // wall-clock time since T instead, same trick a realtime
        // transport would use, just applied to a slower poll interval.
        if (state.playing) {
            var elapsed = (Date.now() / 1000) - state.position_updated_at;
            var projected = state.position + Math.max(0, elapsed);
            if (Math.abs(audio.currentTime - projected) > 1.5) audio.currentTime = projected;
            if (audio.paused) audio.play().catch(function () {});
        } else {
            if (Math.abs(audio.currentTime - state.position) > 0.75) audio.currentTime = state.position;
            if (!audio.paused) audio.pause();
        }

        renderQueuePanel();
    }

    // Debounced-by-nature: only ever called right after a guarded local
    // transport action (see playPrev/playNext/playPauseBtn/seek/
    // toggleShuffle/queue-click above), never on a timer — the host's
    // push IS the state, so there's nothing to coalesce.
    function jamPushState() {
        if (!jam.active || !jam.isHost || !jam.code) return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/host-state', {
            method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                queue: queue.map(function (t) { return t.id; }),
                index: queueIndex,
                playing: !audio.paused,
                position: audio.currentTime || 0,
            }),
        }).catch(function () { /* next local action retries implicitly */ });
    }

    function jamVoteSkip() {
        if (!jam.active || !jam.code || jam.controlMode !== 'vote_skip') return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/vote-skip', { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json(); }).then(jamApplyState).catch(function () {});
    }

    function jamLeave() {
        if (!jam.active || !jam.code) return;
        fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/leave', { method: 'POST', headers: authHeaders() }).catch(function () {});
        jamEnd(null);
    }

    function jamEnd(notice) {
        jam.active = false;
        jam.isHost = false;
        jam.code = null;
        if (jam.pollTimer) clearInterval(jam.pollTimer);
        jam.pollTimer = null;
        jamBanner.style.display = 'none';
        jamBanner.innerHTML = '';
        if (jamParticipantsPanel) { jamParticipantsPanel.remove(); jamParticipantsPanel = null; }
        if (jamPendingPanel) { jamPendingPanel.remove(); jamPendingPanel = null; }
        if (jamApprovalPoll) { clearInterval(jamApprovalPoll); jamApprovalPoll = null; }
        if (notice) alert(notice);
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
        if (voteBtn) voteBtn.addEventListener('click', jamVoteSkip);
        document.getElementById('bhs-jam-leave-btn').addEventListener('click', jamLeave);
    }

    var jamParticipantsPanel = null;

    // A per-listener, purely-local mute: hides a specific person's name
    // from YOUR OWN "who's here" view. Everyone in a Jam still hears the
    // exact same shared audio stream (there's no per-participant audio
    // routing to mute) — this is about not having to look at a
    // troll's name, nothing more, and it's cheaper than reporting or
    // (for a host) kicking: no server round-trip, no one else is
    // affected, reversible any time. Scoped per Jam code so it doesn't
    // carry over oddly into an unrelated future session.
    function mutedKey() { return 'bhs_jam_muted_' + jam.code; }
    function getMuted() {
        try { return JSON.parse(localStorage.getItem(mutedKey()) || '[]'); } catch (e) { return []; }
    }
    function isMuted(uid) { return getMuted().indexOf(uid) !== -1; }
    function toggleMute(uid) {
        var list = getMuted();
        var i = list.indexOf(uid);
        if (i === -1) list.push(uid); else list.splice(i, 1);
        localStorage.setItem(mutedKey(), JSON.stringify(list));
    }

    function jamRenderParticipants(list) {
        if (!jam.active) return;
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
        // Everyone gets the mute toggle; only the host ALSO gets remove
        // (silently rejected on the host's own row — see class-jam.php's
        // kick() self-target check — harmless rather than worth
        // plumbing the host's own user ID through to hide one button).
        jamParticipantsPanel.innerHTML = list.map(function (p) {
            var name = esc(p.display_name || ('User #' + p.user_id));
            var mutedNow = isMuted(p.user_id);
            var html = '<span class="bhs-jam-participant' + (mutedNow ? ' bhs-jam-participant--muted' : '') + '">' + name
                + ' <button type="button" class="bhs-link-btn" data-mute-uid="' + p.user_id + '">' + (mutedNow ? 'unmute' : 'mute') + '</button>';
            if (jam.isHost) html += ' <button type="button" class="bhs-link-btn" data-kick-uid="' + p.user_id + '">remove</button>';
            html += '</span>';
            return html;
        }).join('');
        jamParticipantsPanel.querySelectorAll('button[data-mute-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                toggleMute(parseInt(btn.dataset.muteUid, 10));
                jamRenderParticipants(list); // re-render locally, no server round-trip
            });
        });
        jamParticipantsPanel.querySelectorAll('button[data-kick-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Remove this listener from the Jam?')) return;
                fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/kick', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.kickUid, 10) }),
                }).catch(function () {});
            });
        });
    }

    var jamPendingPanel = null;

    // Host-only: `state.pending` is only ever populated by the server
    // response when the caller IS the host (see class-jam.php's
    // respond()) — a non-host's state simply has no such key, so this
    // naturally renders nothing for anyone else.
    function jamRenderPending(pending) {
        if (!jam.active || !jam.isHost) return;
        if (!pending || !pending.length) {
            if (jamPendingPanel) { jamPendingPanel.remove(); jamPendingPanel = null; }
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
                fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/approve', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.approveUid, 10) }),
                }).then(function (r) { return r.json(); }).then(jamApplyState).catch(function () {});
            });
        });
        jamPendingPanel.querySelectorAll('button[data-deny-uid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                fetch(rest + 'jam/' + encodeURIComponent(jam.code) + '/deny', {
                    method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ user_id: parseInt(btn.dataset.denyUid, 10) }),
                }).then(function (r) { return r.json(); }).then(jamApplyState).catch(function () {});
            });
        });
    }

    function jamRenderSkipVotes(state) {
        if (!jam.active || jam.controlMode !== 'vote_skip') return;
        var btn = document.getElementById('bhs-jam-vote-skip-btn');
        if (btn) btn.textContent = state.i_voted_skip
            ? ('Voted (' + state.skip_votes_count + '/' + state.skip_votes_needed + ')')
            : ('Vote to skip (' + state.skip_votes_count + '/' + state.skip_votes_needed + ')');
    }

    Promise.all([
        fetch(rest + 'tracks').then(function (r) { return r.json(); }),
        fetch(rest + 'releases').then(function (r) { return r.json(); }),
        loggedIn ? fetch(rest + 'likes', { headers: authHeaders() }).then(function (r) { return r.json(); }) : Promise.resolve({ track_ids: [] }),
        loggedIn ? fetch(rest + 'playlists', { headers: authHeaders() }).then(function (r) { return r.json(); }) : Promise.resolve({ playlists: [] }),
    ]).then(function (results) {
        allTracks = results[0].tracks || [];
        releases = results[1].releases || [];
        likedIds = results[2].track_ids || [];
        myPlaylists = results[3].playlists || [];
        loadGenreOptions();
        // A shared-playlist link takes over the initial view entirely
        // (read-only, no tabs needed) — everything else above still
        // loads normally first since the shared view's own track
        // lookups depend on allTracks already being populated.
        if (!maybeOpenSharedPlaylist()) renderView();
    }).catch(function () {
        library.innerHTML = '<p class="bhs-empty">Could not load the library right now.</p>';
    });
})();
