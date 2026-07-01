const D = window.BHData || {};

class BHPlayer {
    // root: the specific .bh-player-root element for THIS instance. Every
    // DOM lookup below is scoped to root.querySelector(...), never the
    // global document — that's what lets multiple contests run on the same
    // page at once without their controls colliding.
    constructor(root) {
        this.root = root;
        this.api = D.rest || '/wp-json/bh/v1/';
        this.nonce = D.nonce || '';
        this.loggedIn = !!D.loggedIn;
        this.maxBytes = D.maxBytes || 20971520;
        this.contest = root.dataset.contest || ''; // '' = server falls back to newest published

        this.tracks = [];
        this.sound = null;
        this.playingIndex = -1;
        this.scrubbing = false; // true while dragging the scrubber
        this.isLogin = true;    // auth modal mode

        this.start();
    }

    start() {
        this.renderSkeleton();
        this.updateAuthUI();
        this.loadTracks(1);
    }

    /* ---------- tiny utils ---------- */
    q(sel) { return this.root.querySelector(sel); }
    qa(sel) { return this.root.querySelectorAll(sel); }

    esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    fmtTime(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
    }

    // Deterministic hue per track so each row gets a distinct little "disc" —
    // no artwork needed, but the list still reads as visually varied.
    hue(id) { return (id * 47) % 360; }

    // One shared toast for the whole page — fine even with multiple player
    // instances, since only one action happens at a time.
    toast(msg, err = false) {
        let t = document.getElementById('bh-toast');
        if (!t) { t = document.createElement('div'); t.id = 'bh-toast'; t.className = 'bh-toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.toggle('error', !!err);
        t.classList.add('show');
        clearTimeout(window._bhToastTimer);
        window._bhToastTimer = setTimeout(() => t.classList.remove('show'), 3400);
    }

    // Single fetch wrapper. The nonce is attached on every request, GET
    // included — WordPress only recognizes a logged-in user on REST calls
    // when a valid nonce is present. The resolved contest ID/slug for THIS
    // instance is appended automatically unless the call already sets one.
    async req(path, opts = {}) {
        const o = { ...opts, headers: { 'X-WP-Nonce': this.nonce, ...(opts.headers || {}) } };
        let url = this.api + path;
        if (this.contest) url += (url.includes('?') ? '&' : '?') + 'contest=' + encodeURIComponent(this.contest);
        let res, body = {};
        try { res = await fetch(url, o); body = await res.json().catch(() => ({})); }
        catch (e) { return { ok: false, body: {} }; }
        return { ok: res.ok, body };
    }

    // After login/register/logout the auth cookie changed, so pull a fresh nonce.
    // Shared across instances on the page (session is site-wide, not per-player).
    async refreshSession() {
        const { body } = await this.req('session');
        if (body && typeof body.logged_in !== 'undefined') {
            this.loggedIn = !!body.logged_in;
            this.nonce = body.nonce || this.nonce;
        }
    }

    /* ---------- skeleton ---------- */
    renderSkeleton() {
        this.root.innerHTML = `
            <div class="bh-container">
                <div class="bh-header">
                    <div class="bh-brand">Billy<span>Hume</span></div>
                    <div class="bh-header-actions">
                        <button class="bh-results-btn bh-btn bh-btn-results" style="display:none;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M5 4h14v2h2v3a4 4 0 0 1-4 4h-.35A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 7.35 13H7a4 4 0 0 1-4-4V6h2V4zm0 4H4.9A2 2 0 0 0 5 8.9V8zm14 0v.9a2 2 0 0 0 .1-.9H19z"/></svg>
                            Results
                        </button>
                        <button class="bh-submit-btn bh-btn bh-btn-primary" style="display:none;">Submit a Song</button>
                        <button class="bh-login-btn bh-btn bh-btn-outline">Log In</button>
                        <a href="#" class="bh-switch-btn bh-link-muted" style="display:none;">Switch account</a>
                    </div>
                </div>

                <div class="bh-tracklist">Loading tracks…</div>

                <div class="bh-now-playing-bar">
                    <div class="bh-np-track">
                        <div class="bh-disc bh-np-disc"></div>
                        <div class="bh-np-info">Select a track</div>
                    </div>
                    <div class="bh-scrubber-container">
                        <span class="bh-time bh-time-elapsed">0:00</span>
                        <input type="range" class="bh-scrubber" value="0" min="0" max="100" step="0.1" aria-label="Seek">
                        <span class="bh-time bh-time-duration">0:00</span>
                    </div>
                    <button class="bh-play-pause" aria-label="Play or pause">
                        <svg class="bh-icon-play" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        <svg class="bh-icon-pause" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="display:none;"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                    </button>
                </div>

                <div class="bh-modal bh-auth-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="auth">&times;</span>
                    <h2 class="bh-auth-title">Log In</h2>
                    <input type="text" class="bh-user" placeholder="Username" autocomplete="username">
                    <input type="password" class="bh-pass" placeholder="Password" autocomplete="current-password">
                    <input type="email" class="bh-email" placeholder="Email (sign up only)" style="display:none;" autocomplete="email">
                    <input type="text" class="bh-website bh-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <button class="bh-auth-submit bh-btn bh-btn-primary">Continue</button>
                    <p><a href="#" class="bh-toggle-auth">Need an account? Sign up</a></p>
                </div></div>

                <div class="bh-modal bh-submit-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="submit">&times;</span>
                    <h2>Submit Your Track</h2>
                    <input type="text" class="bh-sub-title" placeholder="Song title">
                    <input type="text" class="bh-sub-artist" placeholder="Artist name">
                    <textarea class="bh-sub-note" placeholder="Note to admins (optional)" rows="3"></textarea>
                    <label class="bh-file-label">
                        <span class="bh-file-label-text">Choose an audio file…</span>
                        <input type="file" class="bh-sub-file bh-file-input" accept=".mp3,.m4a,audio/mpeg,audio/mp4">
                    </label>
                    <small>MP3 or M4A · Max 20MB</small>
                    <button class="bh-upload-btn bh-btn bh-btn-primary">Upload</button>
                </div></div>

                <div class="bh-modal bh-results-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="results">&times;</span>
                    <h2>Results</h2>
                    <div class="bh-results-body">Loading…</div>
                </div></div>
            </div>`;
        this.bind();
    }

    updateAuthUI() {
        this.q('.bh-submit-btn').style.display = this.loggedIn ? 'inline-flex' : 'none';
        this.q('.bh-login-btn').style.display  = this.loggedIn ? 'none' : 'inline-flex';
        this.q('.bh-switch-btn').style.display = this.loggedIn ? 'inline-block' : 'none';
    }

    bind() {
        const modal = name => this.q(`.bh-${name}-modal`);
        const show = name => modal(name).style.display = 'flex';
        const openAuth = () => { this.setAuthMode(true); show('auth'); };

        this.q('.bh-login-btn').onclick   = openAuth;
        this.q('.bh-switch-btn').onclick  = e => { e.preventDefault(); openAuth(); };
        this.q('.bh-submit-btn').onclick  = () => show('submit');
        this.q('.bh-results-btn').onclick = () => this.loadResults();
        this.q('.bh-auth-submit').onclick = () => this.auth();
        this.q('.bh-upload-btn').onclick  = () => this.upload();
        this.q('.bh-play-pause').onclick  = () => this.toggle();

        this.q('.bh-sub-file').addEventListener('change', e => {
            const f = e.target.files[0];
            this.q('.bh-file-label-text').textContent = f ? f.name : 'Choose an audio file…';
        });

        // Close via X or backdrop click.
        this.qa('.bh-modal').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m || e.target.dataset.close) m.style.display = 'none';
            });
        });

        this.q('.bh-toggle-auth').onclick = e => {
            e.preventDefault();
            this.setAuthMode(!this.isLogin);
        };

        // Event delegation: one listener covers every (re-rendered) track row.
        this.q('.bh-tracklist').addEventListener('click', e => {
            const v = e.target.closest('.bh-vote-btn'); if (v) return this.vote(v.dataset.id);
            const row = e.target.closest('.bh-track-row'); if (row) this.play(+row.dataset.index);
        });

        // Scrubber: freeze auto-updates while dragging, seek on release.
        const s = this.q('.bh-scrubber');
        const grab = () => { this.scrubbing = true; };
        const drop = () => {
            if (this.sound) this.sound.seek((this.sound.duration() || 0) * (parseFloat(s.value) / 100));
            this.scrubbing = false;
        };
        s.addEventListener('input', grab);
        s.addEventListener('pointerdown', grab);
        s.addEventListener('touchstart', grab, { passive: true });
        s.addEventListener('change', drop);
        s.addEventListener('pointerup', drop);
        s.addEventListener('touchend', drop);
    }

    setAuthMode(isLogin) {
        this.isLogin = isLogin;
        this.q('.bh-auth-title').innerText = isLogin ? 'Log In' : 'Sign Up';
        this.q('.bh-email').style.display = isLogin ? 'none' : 'block';
        this.q('.bh-toggle-auth').innerText = isLogin ? 'Need an account? Sign up' : 'Have an account? Log in';
    }

    /* ---------- auth ---------- */
    async auth() {
        const btn = this.q('.bh-auth-submit'), label = btn.innerText;
        const fd = new FormData();
        fd.append('username', this.q('.bh-user').value.trim());
        fd.append('password', this.q('.bh-pass').value);
        if (!this.isLogin) {
            fd.append('email', this.q('.bh-email').value.trim());
            fd.append('website', this.q('.bh-website').value); // honeypot
        }

        btn.disabled = true;
        btn.innerText = this.isLogin ? 'Logging in…' : 'Creating account…';

        const { ok, body } = await this.req(this.isLogin ? 'login' : 'register', { method: 'POST', body: fd });
        if (ok) {
            this.q('.bh-auth-modal').style.display = 'none';
            this.q('.bh-user').value = ''; this.q('.bh-pass').value = ''; this.q('.bh-email').value = '';
            await this.refreshSession();
            this.updateAuthUI();
            await this.loadTracks(1);
            this.toast(this.isLogin ? 'Welcome back!' : 'Account created — you\'re now signed in.');
        } else {
            this.toast(body.message || 'Authentication failed.', true);
        }
        btn.disabled = false;
        btn.innerText = label;
    }

    /* ---------- submission ---------- */
    async upload() {
        const file = this.q('.bh-sub-file').files[0];
        const title = this.q('.bh-sub-title').value.trim();
        const artist = this.q('.bh-sub-artist').value.trim();
        const note = this.q('.bh-sub-note').value.trim();
        const btn = this.q('.bh-upload-btn');

        if (!file || !title || !artist) return this.toast('Add a song title, artist name, and an audio file.', true);
        if (file.size > this.maxBytes) return this.toast('That file is over 20MB. Please choose a smaller one.', true);

        btn.disabled = true;
        btn.innerText = 'Uploading… please wait';

        const fd = new FormData();
        fd.append('title', title); fd.append('artist', artist); fd.append('note', note); fd.append('audio', file);

        const { ok, body } = await this.req('submit', { method: 'POST', body: fd });
        if (ok) {
            this.q('.bh-submit-modal').style.display = 'none';
            this.qa('.bh-sub-title, .bh-sub-artist, .bh-sub-note, .bh-sub-file').forEach(el => el.value = '');
            this.q('.bh-file-label-text').textContent = 'Choose an audio file…';
            this.toast('Track submitted! It will appear once an admin approves it.');
        } else {
            this.toast(body.message || 'Upload failed. Check the file and try again.', true);
        }
        btn.disabled = false;
        btn.innerText = 'Upload';
    }

    /* ---------- tracks ---------- */
    async loadTracks(page) {
        const list = this.q('.bh-tracklist');
        const { ok, body } = await this.req(`tracks?page=${page}`);
        if (!ok) { list.innerHTML = '<div class="bh-empty">Could not load tracks.</div>'; return; }

        // The server resolves an untargeted shortcode to "newest published
        // contest" — lock this instance to that specific ID so a second
        // contest being published elsewhere mid-session can't silently
        // swap what this embed shows.
        if (body.contest_id) this.contest = String(body.contest_id);
        this.resultsPublished = !!body.results_published;
        this.q('.bh-results-btn').style.display = this.resultsPublished ? 'inline-flex' : 'none';

        this.tracks = body.tracks || [];
        if (!this.tracks.length) { list.innerHTML = '<div class="bh-empty">No tracks yet. Be the first to submit!</div>'; return; }

        list.innerHTML = this.tracks.map((t, i) => `
            <div class="bh-track-row" data-index="${i}">
                <div class="bh-disc" style="--bh-hue:${this.hue(t.id)}"></div>
                <div class="bh-track-details">
                    <div class="bh-track-title">${this.esc(t.title)}</div>
                    <div class="bh-track-artist">${this.esc(t.artist)}</div>
                </div>
                <button class="bh-vote-btn ${t.voted ? 'voted' : ''}" data-id="${t.id}" id="vote-${t.id}">
                    <svg class="bh-check" viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    <span>${t.voted ? 'Voted' : 'Vote'}</span>
                </button>
            </div>`).join('');
    }

    play(index) {
        if (this.sound) this.sound.stop();
        const t = this.tracks[index];
        if (!t || !t.src) return this.toast('This track has no audio file.', true);

        this.playingIndex = index;
        this.q('.bh-np-info').innerHTML = `<strong>${this.esc(t.title)}</strong><br><small>${this.esc(t.artist)}</small>`;
        this.q('.bh-np-disc').style.setProperty('--bh-hue', this.hue(t.id));
        this.req('play', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ submission_id: t.id }) });

        this.sound = new Howl({
            src: [t.src], html5: true,
            onplay: () => { this.setPlayIcon(true); requestAnimationFrame(() => this.tick()); },
            onpause: () => this.setPlayIcon(false),
            onend: () => { this.setPlayIcon(false); this.q('.bh-scrubber').value = 0; this.q('.bh-time-elapsed').textContent = '0:00'; },
        });
        this.sound.play();
    }

    setPlayIcon(playing) {
        this.q('.bh-icon-play').style.display = playing ? 'none' : '';
        this.q('.bh-icon-pause').style.display = playing ? '' : 'none';
        this.q('.bh-np-disc').classList.toggle('spinning', playing);
    }

    toggle() {
        if (!this.sound) return;
        this.sound.playing() ? this.sound.pause() : this.sound.play();
    }

    tick() {
        if (this.sound && this.sound.playing()) {
            const dur = this.sound.duration() || 0;
            if (!this.scrubbing) {
                const seek = this.sound.seek() || 0;
                this.q('.bh-scrubber').value = dur ? (seek / dur) * 100 : 0;
                this.q('.bh-time-elapsed').textContent = this.fmtTime(seek);
            }
            this.q('.bh-time-duration').textContent = this.fmtTime(dur);
            requestAnimationFrame(() => this.tick());
        }
    }

    /* ---------- voting ---------- */
    async vote(id) {
        if (!this.loggedIn) { this.setAuthMode(true); this.q('.bh-auth-modal').style.display = 'flex'; return this.toast('Log in to vote.'); }
        const btn = this.q(`#vote-${id}`);
        if (btn) btn.disabled = true;

        const { ok, body } = await this.req('vote', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ submission_id: id }),
        });

        if (ok && body.action === 'added') {
            if (btn) { btn.classList.add('voted'); btn.querySelector('span').textContent = 'Voted'; }
            this.toast(body.votes_left > 0
                ? `Vote counted — ${body.votes_left} vote${body.votes_left === 1 ? '' : 's'} left.`
                : 'Vote counted — that was your last vote.');
        } else if (ok) {
            if (btn) { btn.classList.remove('voted'); btn.querySelector('span').textContent = 'Vote'; }
            this.toast('Vote removed — you can pick another track.');
        } else {
            this.toast(body.message || 'Could not record your vote.', true);
        }
        if (btn) btn.disabled = false;
    }

    /* ---------- results ---------- */
    async loadResults() {
        const out = this.q('.bh-results-body');
        out.innerHTML = 'Loading…';
        this.q('.bh-results-modal').style.display = 'flex';

        const { ok, body } = await this.req('results');
        if (!ok) { out.innerHTML = `<p class="bh-results-empty">${this.esc(body.message || 'Results are not available yet.')}</p>`; return; }
        if (!body.results || !body.results.length) { out.innerHTML = '<p class="bh-results-empty">No votes have been cast yet.</p>'; return; }

        out.innerHTML = `<ol class="bh-results-list">${body.results.map(r => `
            <li>
                <span class="bh-results-rank">#${r.rank}</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">${this.esc(r.title)}</span>
                    <span class="bh-results-artist">${this.esc(r.artist)}</span>
                </span>
                <span class="bh-results-votes">${r.votes} vote${r.votes === 1 ? '' : 's'}</span>
            </li>`).join('')}</ol>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bh-player-root').forEach(root => new BHPlayer(root));
});
