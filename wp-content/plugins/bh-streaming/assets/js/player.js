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

    var allTracks = [];
    var releases = [];
    var likedIds = [];
    var myPlaylists = [];
    var currentView = 'all';
    var queue = [];          // the ordered list of track objects currently being played through
    var queueIndex = -1;
    var audio = new Audio();

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
        return '<button type="button" class="bhs-card" data-id="' + t.id + '">'
            + '<img src="' + esc(t.artwork) + '" alt="" class="bhs-card-art">'
            + (t.external ? '<span class="bhs-badge">Featured</span>' : '')
            + '<div class="bhs-card-title">' + esc(t.title) + '</div>'
            + '<div class="bhs-card-artist">' + esc(t.artist) + '</div>'
            + '<div class="bhs-card-meta">' + (liked ? '&#9829;' : '') + ' ' + t.plays + ' plays</div>'
            + '</button>';
    }

    /* ---------- view rendering ---------- */

    function renderView() {
        relatedBox.style.display = 'none';

        if (currentView === 'all') {
            var tracks = filteredTracks();
            library.innerHTML = tracks.length
                ? '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>'
                : '<p class="bhs-empty">No tracks match.</p>';
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
                playQueue(list, index);
            });
        });
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
                var tracks = playlist.track_ids.map(function (id) { return allTracks.find(function (t) { return t.id === id; }); }).filter(Boolean);
                library.innerHTML = '<button type="button" class="bhs-back" id="bhs-back-to-playlists">&larr; Playlists</button>'
                    + '<h2 class="bhs-release-title">' + esc(playlist.title) + '</h2>'
                    + '<div class="bhs-grid">' + tracks.map(trackCardHtml).join('') + '</div>';
                document.getElementById('bhs-back-to-playlists').addEventListener('click', renderView);
                bindCardClicks(tracks);
            });
        });
    }

    /* ---------- playback + queue ---------- */

    function playQueue(list, index) {
        queue = list.slice();
        queueIndex = index;
        playCurrent();
        renderQueuePanel();
    }

    function playCurrent() {
        var t = queue[queueIndex];
        if (!t) return;

        audio.src = t.url;
        audio.play().catch(function () { /* needs a user gesture first — the click that got us here counts */ });
        fetch(rest + 'tracks/' + t.id + '/play', { method: 'POST' }).catch(function () {});

        nowplaying.style.display = '';
        artEl.src = t.artwork;
        titleEl.textContent = t.title;
        artistEl.textContent = t.artist;
        likeBtn.classList.toggle('liked', likedIds.indexOf(t.id) !== -1);
        likeBtn.innerHTML = likedIds.indexOf(t.id) !== -1 ? '&#9829;' : '&#9825;';

        updateMediaSession(t);
        loadRelated(t.id);
        renderQueuePanel();
    }

    function playPrev() { if (queueIndex > 0) { queueIndex--; playCurrent(); } }
    function playNext() { if (queueIndex < queue.length - 1) { queueIndex++; playCurrent(); } }

    function renderQueuePanel() {
        queueList.innerHTML = queue.map(function (t, i) {
            return '<div class="bhs-queue-item' + (i === queueIndex ? ' active' : '') + '" data-index="' + i + '">'
                + esc(t.title) + ' <span class="bhs-queue-artist">' + esc(t.artist) + '</span></div>';
        }).join('');
        queueList.querySelectorAll('.bhs-queue-item').forEach(function (item) {
            item.addEventListener('click', function () { queueIndex = parseInt(item.dataset.index, 10); playCurrent(); });
        });
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
    audio.addEventListener('timeupdate', function () {
        if (audio.duration) seek.value = (audio.currentTime / audio.duration) * 100;
        if ('mediaSession' in navigator && audio.duration && 'setPositionState' in navigator.mediaSession) {
            navigator.mediaSession.setPositionState({ duration: audio.duration, playbackRate: 1, position: audio.currentTime });
        }
    });

    playPauseBtn.addEventListener('click', function () { audio.paused ? audio.play() : audio.pause(); });
    document.getElementById('bhs-prev').addEventListener('click', playPrev);
    document.getElementById('bhs-next').addEventListener('click', playNext);
    seek.addEventListener('input', function () { if (audio.duration) audio.currentTime = (seek.value / 100) * audio.duration; });

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
        renderView();
    }).catch(function () {
        library.innerHTML = '<p class="bhs-empty">Could not load the library right now.</p>';
    });
})();
