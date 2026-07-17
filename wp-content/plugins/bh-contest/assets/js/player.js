const D = window.BHData || {};

// One color per category, assigned by order (not hashed) so it stays
// stable as long as the admin doesn't reorder the category list. Each
// slot resolves to a --bh-cat-N custom property set by BHY_Style (see
// class-settings.php) — the actual hex values live in the Category
// Colors panel on the Settings & Style admin page, not here. The literal
// hexes below are only a fallback for the rare case that property isn't
// defined (e.g. this script loaded outside the plugin's own CSS).
const CAT_COLORS = [
    'var(--bh-cat-1, #FF5A36)', 'var(--bh-cat-2, #2DD4BF)', 'var(--bh-cat-3, #A78BFA)', 'var(--bh-cat-4, #F472B6)',
    'var(--bh-cat-5, #38BDF8)', 'var(--bh-cat-6, #A3E635)', 'var(--bh-cat-7, #FBBF24)', 'var(--bh-cat-8, #FB7185)',
];

// The one place the profile-field list/DOM-class mapping lives now —
// contract-drift fix, caught by an audit run right after the quiz-
// shuffle bug (own-ur-shit's BHI_Profiles::TEXT_COLS is the real PHP-
// side source of truth, already correctly single-sourced there; this
// was the JS-side counterpart, independently duplicated in THREE
// places — appendProfileFields()'s own map, prefillSubmitProfile()'s
// separately-typed copy of the identical map, and applyContactFields()/
// the contactFields.show default's own hardcoded field-name array).
// Nothing caught it drifting yet, but nothing PREVENTED it either — a
// field added to BHI_Profiles::TEXT_COLS in the future would silently
// never show up in one of these three without a human remembering to
// update all three by hand. Server field key -> DOM class-suffix
// mapping is legitimately a bh-contest template concern (not something
// PHP needs to dictate), so this stays JS-side rather than crossing the
// PHP/JS boundary via wp_localize_script — the fix is collapsing THREE
// copies into ONE, not moving the one copy.
const PROFILE_FIELDS = [
    { key: 'real_name', cls: 'realname' },
    { key: 'discord_name', cls: 'discord' },
    { key: 'twitch_name', cls: 'twitch' },
    { key: 'youtube_name', cls: 'youtube' },
];
const CONTACT_FIELD_KEYS = PROFILE_FIELDS.map(f => f.key).concat(['typical_platform', 'phone']);

class BHPlayer {
    // root: the specific .bh-player-root element for THIS instance. Every
    // DOM lookup below is scoped to root.querySelector(...), never the
    // global document — that's what lets multiple contests run on the same
    // page at once without their controls colliding.
    constructor(root) {
        this.root = root;
        this.api = D.rest || '/wp-json/bh/v1/';
        this.identityApi = D.identity || '/wp-json/bhi/v1/';
        this.nonce = D.nonce || '';
        this.loggedIn = !!D.loggedIn;
        this.maxBytes = D.maxBytes || 20971520;
        this.brand = D.brand || { part1: 'Your', part2: 'Brand', logoUrl: '' };
        this.contest = root.dataset.contest || ''; // '' = server falls back to newest published

        // Per-contest style override (accent + category colors + brand),
        // set server-side only when a contest has "Override site
        // styling" enabled (see BHY_Style::entity_style_payload).
        // Applied as inline CSS custom properties directly on this root
        // element so it only ever affects this one embedded instance —
        // the site-wide theme and any other contest on the same page
        // are untouched.
        if (root.dataset.styleOverrides) {
            try {
                const ov = JSON.parse(root.dataset.styleOverrides);
                if (ov.vars) Object.entries(ov.vars).forEach(([k, v]) => root.style.setProperty(k, v));
                if (ov.brand) this.brand = ov.brand;
            } catch (e) { /* malformed override data — fall back to site defaults silently */ }
        }

        this.tracks = [];
        this.categories = [];
        this.activeCategory = '';
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

    catColor(slug) {
        const i = this.categories.findIndex(c => c.slug === slug);
        return CAT_COLORS[(i >= 0 ? i : 0) % CAT_COLORS.length];
    }

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

    // Same shape as req() above, but against bh-identity's REST base
    // instead of this plugin's own — auth/profile calls now live there,
    // not here, and unlike a contest-scoped call there's no contest
    // param to append.
    async reqIdentity(path, opts = {}) {
        const o = { ...opts, headers: { 'X-WP-Nonce': this.nonce, ...(opts.headers || {}) } };
        let res, body = {};
        try { res = await fetch(this.identityApi + path, o); body = await res.json().catch(() => ({})); }
        catch (e) { return { ok: false, body: {} }; }
        return { ok: res.ok, body };
    }

    /* ---------- skeleton ---------- */
    renderSkeleton() {
        this.root.innerHTML = `
            <div class="bh-container">
                <div class="bh-header">
                    <div class="bh-brand">${this.brand.logoUrl
                        ? `<img class="bh-brand-logo" src="${this.esc(this.brand.logoUrl)}" alt="${this.esc(this.brand.part1 + this.brand.part2)}">`
                        : `${this.esc(this.brand.part1)}<span>${this.esc(this.brand.part2)}</span>`}</div>
                    <div class="bh-header-extra"></div>
                    <div class="bh-header-actions">
                        <button class="bh-results-btn bh-btn bh-btn-results" style="display:none;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M5 4h14v2h2v3a4 4 0 0 1-4 4h-.35A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 7.35 13H7a4 4 0 0 1-4-4V6h2V4zm0 4H4.9A2 2 0 0 0 5 8.9V8zm14 0v.9a2 2 0 0 0 .1-.9H19z"/></svg>
                            Results
                        </button>
                        <button class="bh-submit-btn bh-btn bh-btn-primary" style="display:none;">Submit a Song</button>
                        <button class="bh-login-btn bh-btn bh-btn-outline">Log In</button>
                        <a href="#" class="bh-logout-btn bh-btn bh-btn-outline" style="display:none;">Log Out</a>
                    </div>
                </div>

                <div class="bh-category-tabs" style="display:none;"></div>

                <!-- Task #80 follow-up, safe slice #2: a genuinely new,
                     empty zone above the tracklist that no lookup
                     elsewhere in this file reads from or requires — see
                     injectExtraZone() below. -->
                <div class="bh-tracklist-extra"></div>

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

                <!-- Task #80 follow-up, safe slice #3: a new zone AFTER
                     the now-playing bar, a sibling of it rather than
                     nested inside — kept fully outside the bar's own flex
                     layout/selectors (.bh-np-track/.bh-scrubber-container/
                     .bh-play-pause) so nothing about its playback wiring
                     is touched. -->
                <div class="bh-now-playing-extra"></div>

                <div class="bh-modal bh-auth-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="auth">&times;</span>
                    <h2 class="bh-auth-title">Log In</h2>
                    <input type="text" class="bh-user" placeholder="Username" autocomplete="username">
                    <input type="password" class="bh-pass" placeholder="Password" autocomplete="current-password">
                    <input type="email" class="bh-email" placeholder="Email (sign up only)" style="display:none;" autocomplete="email">
                    <div class="bh-reg-extra" style="display:none;">
                        <small>Optional — helps us credit you if you ever submit a track. Skip anything you'd rather not share.</small>
                        <div class="bh-field-row">
                            <input type="text" class="bh-reg-realname" placeholder="Real name">
                            <label class="bh-pub-toggle"><input type="checkbox" class="bh-reg-realname-pub"> public</label>
                        </div>
                        <div class="bh-field-row">
                            <input type="text" class="bh-reg-discord" placeholder="Discord username">
                            <label class="bh-pub-toggle"><input type="checkbox" class="bh-reg-discord-pub"> public</label>
                        </div>
                        <div class="bh-field-row">
                            <input type="text" class="bh-reg-twitch" placeholder="Twitch username">
                            <label class="bh-pub-toggle"><input type="checkbox" class="bh-reg-twitch-pub"> public</label>
                        </div>
                        <div class="bh-field-row">
                            <input type="text" class="bh-reg-youtube" placeholder="YouTube channel">
                            <label class="bh-pub-toggle"><input type="checkbox" class="bh-reg-youtube-pub"> public</label>
                        </div>
                        <select class="bh-reg-platform">
                            <option value="">Where do you usually watch?</option>
                            <option value="youtube">YouTube</option>
                            <option value="twitch">Twitch</option>
                        </select>
                    </div>
                    <input type="text" class="bh-website bh-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <button class="bh-auth-submit bh-btn bh-btn-primary">Continue</button>
                    <p><a href="#" class="bh-toggle-auth">Need an account? Sign up</a></p>
                </div></div>

                <div class="bh-modal bh-submit-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="submit">&times;</span>
                    <h2>Submit Your Track</h2>
                    <input type="text" class="bh-sub-title" placeholder="Song title">
                    <input type="text" class="bh-sub-artist" placeholder="Artist name">
                    <small class="bh-sub-fan-note">We need your real name and at least one way to reach you, so we can credit you properly.</small>
                    <div class="bh-field-row" data-field="real_name">
                        <input type="text" class="bh-sub-realname" placeholder="Real name">
                        <label class="bh-pub-toggle"><input type="checkbox" class="bh-sub-realname-pub"> public</label>
                    </div>
                    <div class="bh-field-row" data-field="discord_name">
                        <input type="text" class="bh-sub-discord" placeholder="Discord username">
                        <label class="bh-pub-toggle"><input type="checkbox" class="bh-sub-discord-pub"> public</label>
                    </div>
                    <div class="bh-field-row" data-field="twitch_name">
                        <input type="text" class="bh-sub-twitch" placeholder="Twitch username">
                        <label class="bh-pub-toggle"><input type="checkbox" class="bh-sub-twitch-pub"> public</label>
                    </div>
                    <div class="bh-field-row" data-field="youtube_name">
                        <input type="text" class="bh-sub-youtube" placeholder="YouTube channel">
                        <label class="bh-pub-toggle"><input type="checkbox" class="bh-sub-youtube-pub"> public</label>
                    </div>
                    <input type="tel" class="bh-sub-phone" data-field="phone" placeholder="Phone number (optional — for prize contact only, never shared)">
                    <select class="bh-sub-platform" data-field="typical_platform">
                        <option value="">Where do you usually watch?</option>
                        <option value="youtube">YouTube</option>
                        <option value="twitch">Twitch</option>
                    </select>
                    <textarea class="bh-sub-note" placeholder="Note to admins (optional)" rows="3"></textarea>
                    <label class="bh-file-label">
                        <span class="bh-file-label-text">Choose an audio file…</span>
                        <input type="file" class="bh-sub-file bh-file-input" accept=".mp3,.m4a,audio/mpeg,audio/mp4">
                    </label>
                    <small>MP3 or M4A · Max 20MB</small>
                    <button class="bh-upload-btn bh-btn bh-btn-primary">Upload</button>
                </div></div>

                <div class="bh-modal bh-share-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="share">&times;</span>
                    <h2>You're in! &#127881;</h2>
                    <p>Grab a shareable image to spread the word.</p>
                    <div class="bh-share-cards">
                        <a class="bh-share-card-link" data-share="entered" href="#" target="_blank" rel="noopener">
                            <img class="bh-share-card-thumb" data-share-img="entered" alt="Now entered — shareable image">
                            <span>Get "I entered" image</span>
                        </a>
                        <a class="bh-share-card-link" data-share="vote" href="#" target="_blank" rel="noopener">
                            <img class="bh-share-card-thumb" data-share-img="vote" alt="Vote for me — shareable image">
                            <span>Get "Vote for me" image</span>
                        </a>
                    </div>
                    <p class="bh-share-hint">Pair the "Vote for me" image with this link when you post: <a class="bh-share-contest-link" href="#" target="_blank" rel="noopener"></a></p>
                </div></div>

                <div class="bh-modal bh-results-modal"><div class="bh-modal-content">
                    <span class="bh-close" data-close="results">&times;</span>
                    <h2>Results</h2>
                    <!-- Task #80 follow-up, safe slice #4: additive only —
                         sits between the heading and '.bh-results-body',
                         which is the one element loadResults()-style code
                         actually looks up and rewrites. -->
                    <div class="bh-results-modal-intro"></div>
                    <div class="bh-results-body">Loading…</div>
                </div></div>
            </div>`;
        this.bind();
        this.injectExtraZone('headerExtra', '.bh-header-extra');
        // Task #80 follow-up — three more genuinely new, empty divs
        // (above the tracklist, after the now-playing bar, inside the
        // results modal), same additive boundary as header_extra: none
        // of these are read from or required by any this.q(...)-style
        // lookup elsewhere in this file, so wiring them in carries the
        // same low risk the first slot already proved out live.
        this.injectExtraZone('tracklistExtra', '.bh-tracklist-extra');
        this.injectExtraZone('nowPlayingExtra', '.bh-now-playing-extra');
        this.injectExtraZone('resultsModalIntro', '.bh-results-modal-intro');
    }

    // Task #80's real, safe slice — a genuinely new insertion point, not
    // a rebuild of anything player.js already owns. class-auth.php's
    // render() base64-encodes each real, server-rendered
    // 'bh_contest_player' BH_Element slot (editable in the Design Suite
    // tree, same as any other real placement) onto this.root's own
    // data-{name} attribute — read once here and dropped into that
    // zone's own empty div. Generalized off the original, header-extra-
    // only injectHeaderExtra() once three more zones needed the identical
    // read/decode/insert logic — datasetKey is the camelCase form of the
    // data-* attribute (e.g. 'headerExtra' reads this.root.dataset.
    // headerExtra), selector is that zone's own empty div. Deliberately
    // does NOT touch .bh-brand/.bh-header-actions, the tracklist itself,
    // the now-playing bar's own controls, or '.bh-results-body' — every
    // this.q(...)-style lookup elsewhere in this file keeps working
    // exactly as before, untouched.
    injectExtraZone(datasetKey, selector) {
        const raw = this.root.dataset[datasetKey];
        if (!raw) return;
        const target = this.q(selector);
        if (!target) return;
        try {
            // atob() + decodeURIComponent/escape round-trip handles UTF-8
            // content correctly (plain atob() alone mangles anything
            // outside Latin1, e.g. a real emoji/accented character in an
            // announcement) — same reasoning any base64<->UTF8 JS bridge
            // needs, not specific to this feature.
            const decoded = decodeURIComponent(escape(atob(raw)));
            target.innerHTML = decoded;
        } catch (e) {
            // Malformed/corrupt attribute — fail silently to an empty
            // (invisible, since CSS gives it no border/background of its
            // own) div rather than breaking the rest of the player over
            // one bad zone render.
        }
    }

    updateAuthUI() {
        this.q('.bh-submit-btn').style.display = this.loggedIn ? 'inline-flex' : 'none';
        this.q('.bh-login-btn').style.display  = this.loggedIn ? 'none' : 'inline-flex';
        this.q('.bh-logout-btn').style.display = this.loggedIn ? 'inline-flex' : 'none';
    }

    bind() {
        const modal = name => this.q(`.bh-${name}-modal`);
        const show = name => modal(name).style.display = 'flex';
        const openAuth = () => { this.setAuthMode(true); show('auth'); };

        this.q('.bh-login-btn').onclick   = openAuth;
        this.q('.bh-logout-btn').onclick  = e => { e.preventDefault(); this.logout(); };
        this.q('.bh-submit-btn').onclick  = () => { show('submit'); this.prefillSubmitProfile(); };
        this.q('.bh-results-btn').onclick = () => this.loadResults();
        this.q('.bh-auth-submit').onclick = () => this.auth();
        this.q('.bh-upload-btn').onclick  = () => this.upload();
        this.q('.bh-play-pause').onclick  = () => this.toggle();

        this.q('.bh-sub-file').addEventListener('change', e => {
            const f = e.target.files[0];
            this.q('.bh-file-label-text').textContent = f ? f.name : 'Choose an audio file…';
        });

        this.enhanceSelect(this.q('.bh-reg-platform'));
        this.enhanceSelect(this.q('.bh-sub-platform'));

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
        this.q('.bh-reg-extra').style.display = isLogin ? 'none' : 'flex';
        this.q('.bh-toggle-auth').innerText = isLogin ? 'Need an account? Sign up' : 'Have an account? Log in';
    }

    // Shared by the sign-up form and the submit form — appends whichever
    // profile fields have a value, plus their public/private checkbox, to
    // an outgoing FormData using the given field prefix ('reg' or 'sub').
    appendProfileFields(fd, prefix) {
        for (const { key: serverKey, cls } of PROFILE_FIELDS) {
            const val = this.q(`.bh-${prefix}-${cls}`).value.trim();
            if (val) {
                fd.append(serverKey, val);
                fd.append(`${serverKey}_public`, this.q(`.bh-${prefix}-${cls}-pub`).checked ? '1' : '0');
            }
        }
        const platform = this.q(`.bh-${prefix}-platform`).value;
        if (platform) fd.append('typical_platform', platform);

        // No public/private checkbox for this one — it only exists on
        // the submit form (prize contact only matters for someone who
        // could actually win), so the lookup is guarded rather than
        // assumed present the way the fields above are.
        const phoneEl = this.q(`.bh-${prefix}-phone`);
        if (phoneEl && phoneEl.value.trim()) fd.append('phone', phoneEl.value.trim());
    }

    // Replaces a native <select>'s on-page presentation with a themed
    // trigger + option list, while leaving the real <select> in the DOM
    // as the actual source of truth (just visually hidden). Every other
    // place in this file that reads `.value` or listens for 'change' on
    // one of these selects keeps working exactly as before — this only
    // changes what's drawn, not how the value is stored. Reusable for any
    // future <select> the plugin adds, not just the two current ones.
    enhanceSelect(select) {
        if (!select || select.dataset.enhanced) return;
        select.dataset.enhanced = '1';

        const wrap = document.createElement('div');
        wrap.className = 'bh-select-wrap';
        if (select.dataset.field) wrap.dataset.field = select.dataset.field;
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('bh-select-native');
        select.tabIndex = -1; // the trigger button is what actually takes focus/tabbing

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'bh-select-trigger';
        const label = document.createElement('span');
        trigger.appendChild(label);
        trigger.insertAdjacentHTML('beforeend',
            '<svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>');
        wrap.appendChild(trigger);

        const menu = document.createElement('div');
        menu.className = 'bh-select-menu';
        wrap.appendChild(menu);

        const syncLabel = () => {
            const opt = select.options[select.selectedIndex];
            label.textContent = opt ? opt.text : '';
            label.style.color = (opt && opt.value === '') ? 'var(--bh-text-dim)' : '';
        };
        const renderOptions = () => {
            menu.innerHTML = '';
            Array.from(select.options).forEach(opt => {
                const item = document.createElement('div');
                item.className = 'bh-select-option' + (opt.selected ? ' selected' : '');
                item.textContent = opt.text;
                item.onclick = () => {
                    select.value = opt.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncLabel();
                    renderOptions();
                    wrap.classList.remove('open');
                };
                menu.appendChild(item);
            });
        };

        trigger.onclick = (e) => {
            e.stopPropagation();
            const willOpen = !wrap.classList.contains('open');
            document.querySelectorAll('.bh-select-wrap.open').forEach(w => w.classList.remove('open'));
            if (willOpen) { renderOptions(); wrap.classList.add('open'); }
        };
        document.addEventListener('click', () => wrap.classList.remove('open'));

        // Exposed so code elsewhere that sets select.value directly
        // (bypassing the trigger, e.g. prefillSubmitProfile) can ask the
        // visible label to catch up — a plain .value= write has no event
        // for this component to observe on its own.
        select.bhResync = () => { syncLabel(); renderOptions(); };

        syncLabel();
        renderOptions();
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
            this.appendProfileFields(fd, 'reg');
        }

        btn.disabled = true;
        btn.innerText = this.isLogin ? 'Logging in…' : 'Creating account…';

        const { ok, body } = await this.reqIdentity(this.isLogin ? 'login' : 'register', { method: 'POST', body: fd });
        if (ok) {
            this.toast(this.isLogin ? 'Welcome back!' : 'Account created — you\'re now signed in.');
            // Reload rather than patch state in place. WordPress rejects any
            // REST request whose nonce doesn't match the CURRENT cookie
            // session — and the browser applies this login's new session
            // cookie before our very next request goes out, so an in-place
            // "fetch a fresh nonce" call would itself be sent with the now-
            // stale old nonce and get rejected. A reload sidesteps that
            // race entirely: the server bakes in a correct nonce fresh.
            setTimeout(() => window.location.reload(), 300);
            return;
        }
        this.toast(body.message || 'Authentication failed.', true);
        btn.disabled = false;
        btn.innerText = label;
    }

    async logout() {
        const btn = this.q('.bh-logout-btn');
        btn.style.pointerEvents = 'none';
        await this.reqIdentity('logout', { method: 'POST' });
        this.toast('Logged out.');
        // Same reasoning as auth(): the session cookie just changed, so a
        // reload is the only reliable way to pick up a matching nonce.
        setTimeout(() => window.location.reload(), 300);
    }

    /* ---------- submission ---------- */
    // Pulls whatever the person already gave us at sign-up so they don't
    // have to retype it here — only empty fields stay editable-but-blank.
    async prefillSubmitProfile() {
        const { ok, body } = await this.reqIdentity('profile');
        if (!ok || !body.profile) return;
        const p = body.profile;
        for (const { key: serverKey, cls } of PROFILE_FIELDS) {
            const input = this.q(`.bh-sub-${cls}`);
            if (p[serverKey] && !input.value) input.value = p[serverKey];
            this.q(`.bh-sub-${cls}-pub`).checked = !!p[`${serverKey}_public`];
        }
        if (p.typical_platform) {
            const sel = this.q('.bh-sub-platform');
            sel.value = p.typical_platform;
            if (sel.bhResync) sel.bhResync();
        }
        const phoneEl = this.q('.bh-sub-phone');
        if (phoneEl && p.phone && !phoneEl.value) phoneEl.value = p.phone;
    }

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
        this.appendProfileFields(fd, 'sub');

        const { ok, body } = await this.req('submit', { method: 'POST', body: fd });
        if (ok) {
            this.q('.bh-submit-modal').style.display = 'none';
            this.qa('.bh-sub-title, .bh-sub-artist, .bh-sub-note, .bh-sub-file, .bh-sub-realname, .bh-sub-discord, .bh-sub-twitch, .bh-sub-youtube, .bh-sub-phone')
                .forEach(el => el.value = '');
            this.q('.bh-file-label-text').textContent = 'Choose an audio file…';
            this.toast('Track submitted! It will appear once an admin approves it.');
            this.showShareModal(body);
        } else {
            this.toast(body.message || 'Upload failed. Check the file and try again.', true);
        }
        btn.disabled = false;
        btn.innerText = 'Upload';
    }

    // Populates and opens the share modal added alongside the submit
    // flow — entered_card_url/vote_card_url/contest_page_url all ride
    // on the submit API's own success response (class-api.php's
    // submit()), so this needs no second request. Guarded on all three
    // being present since an older cached copy of this JS talking to a
    // freshly-updated API (or vice versa during a deploy) shouldn't
    // throw trying to read a field that isn't there yet.
    showShareModal(body) {
        if (!body.entered_card_url || !body.vote_card_url) return;
        this.q('[data-share="entered"]').href = body.entered_card_url;
        this.q('[data-share-img="entered"]').src = body.entered_card_url;
        this.q('[data-share="vote"]').href = body.vote_card_url;
        this.q('[data-share-img="vote"]').src = body.vote_card_url;
        const link = this.q('.bh-share-contest-link');
        link.href = body.contest_page_url || '#';
        link.textContent = body.contest_page_url || '';
        this.q('.bh-share-modal').style.display = 'flex';
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

        this.categories = body.categories || [];
        if (!this.activeCategory || !this.categories.some(c => c.slug === this.activeCategory)) {
            this.activeCategory = this.categories.length ? this.categories[0].slug : '';
        }
        this.renderCategoryTabs();

        this.contactFields = body.contact_fields || {
            show: CONTACT_FIELD_KEYS,
            require_real_name: true, require_handle: true, require_phone: false,
        };
        this.applyContactFields();

        this.tracks = body.tracks || [];
        if (!this.tracks.length) { list.innerHTML = '<div class="bh-empty">No tracks yet. Be the first to submit!</div>'; return; }

        this.renderTrackRows();
    }

    // Shows/hides each contact field in the submit form per this
    // contest's configuration (see BH_Helpers::contact_config() on the
    // server), and rewrites the hint text to describe what's actually
    // required here instead of a fixed sentence that might not match.
    // Called after enhanceSelect() has already wrapped the platform
    // <select> (that happens once, synchronously, during bind() at
    // construction — this runs later, after the async tracks fetch
    // resolves), so hiding "typical_platform" correctly targets the
    // wrapper carrying that data-field, not the now-invisible raw select.
    applyContactFields() {
        const cfg = this.contactFields;
        CONTACT_FIELD_KEYS.forEach(f => {
            const el = this.q(`[data-field="${f}"]`);
            if (el) el.style.display = cfg.show.includes(f) ? '' : 'none';
        });

        const parts = [];
        if (cfg.require_real_name) parts.push('your real name');
        if (cfg.require_handle) parts.push('at least one way to reach you (Discord, Twitch, or YouTube)');
        if (cfg.require_phone) parts.push('a phone number');
        const hint = this.q('.bh-sub-fan-note');
        if (hint) {
            hint.textContent = parts.length
                ? 'We need ' + parts.join(' and ') + ' before you can submit.'
                : 'Optional — fill in whatever you\'d like below.';
        }
    }

    // A row of pill tabs, one per voting category — only shown when a
    // contest actually defines 2+ of them. A single category is the same
    // as none from the voter's perspective, so it stays hidden rather than
    // adding a tab that never does anything.
    renderCategoryTabs() {
        const wrap = this.q('.bh-category-tabs');
        if (this.categories.length < 2) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }

        wrap.style.display = 'flex';
        wrap.innerHTML = this.categories.map(c => `
            <button class="bh-cat-tab ${c.slug === this.activeCategory ? 'active' : ''}" data-cat="${this.esc(c.slug)}" style="--bh-cat-color:${this.catColor(c.slug)}">${this.esc(c.name)}</button>
        `).join('');
        wrap.querySelectorAll('.bh-cat-tab').forEach(btn => {
            btn.onclick = () => this.switchCategory(btn.dataset.cat);
        });
    }

    // Switching categories never refetches — every category's vote state
    // for every track came back in the initial /tracks call, so this is
    // instant and reuses whatever's already in memory.
    switchCategory(slug) {
        if (slug === this.activeCategory) return;
        this.activeCategory = slug;
        this.renderCategoryTabs();
        this.renderTrackRows();
    }

    activeCategoryName() {
        const c = this.categories.find(c => c.slug === this.activeCategory);
        return c ? c.name : '';
    }

    renderTrackRows() {
        const list = this.q('.bh-tracklist');
        const color = this.catColor(this.activeCategory);
        list.innerHTML = this.tracks.map((t, i) => {
            const voted = !!(t.votes && t.votes[this.activeCategory]);
            return `
            <div class="bh-track-row" data-index="${i}">
                <div class="bh-disc" style="--bh-hue:${this.hue(t.id)}"></div>
                <div class="bh-track-details">
                    <div class="bh-track-title">${this.esc(t.title)}</div>
                    <div class="bh-track-artist">${this.esc(t.artist)}</div>
                </div>
                <button class="bh-vote-btn ${voted ? 'voted' : ''}" data-id="${t.id}" id="vote-${t.id}" style="--bh-cat-color:${color}">
                    <svg class="bh-check" viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    <span>${voted ? 'Voted' : 'Vote'}</span>
                </button>
            </div>`;
        }).join('');
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
        const track = this.tracks.find(t => String(t.id) === String(id));
        const btn = this.q(`#vote-${id}`);
        if (btn) btn.disabled = true;

        const catName = this.activeCategoryName();
        const catSuffix = catName ? ` for ${catName}` : '';

        const { ok, body } = await this.req('vote', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ submission_id: id, category: this.activeCategory }),
        });

        if (ok && body.action === 'added') {
            // Write the result back into the data model, not just the DOM —
            // renderTrackRows() rebuilds from this.tracks on every tab
            // switch, so without this the vote appeared to "disappear"
            // the moment you switched away and back (it was never lost
            // server-side, only in what got redrawn on screen).
            if (track) { track.votes = track.votes || {}; track.votes[this.activeCategory] = true; }
            if (btn) { btn.classList.add('voted'); btn.querySelector('span').textContent = 'Voted'; }
            this.toast(body.votes_left > 0
                ? `Vote counted${catSuffix} — ${body.votes_left} vote${body.votes_left === 1 ? '' : 's'} left.`
                : `Vote counted${catSuffix} — that was your last vote here.`);
        } else if (ok) {
            if (track && track.votes) track.votes[this.activeCategory] = false;
            if (btn) { btn.classList.remove('voted'); btn.querySelector('span').textContent = 'Vote'; }
            this.toast(`Vote removed${catSuffix} — you can pick another track.`);
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

        const cats = body.categories || [];
        if (!cats.length) { out.innerHTML = '<p class="bh-results-empty">No votes have been cast yet.</p>'; return; }

        this._resultsCats = cats;
        this._resultsActive = cats.length > 1 ? 'all' : cats[0].slug;
        this.renderResultsBody();
    }

    renderResultsList(results) {
        if (!results || !results.length) return '<p class="bh-results-empty">No votes have been cast yet.</p>';
        const medals = ['🥇', '🥈', '🥉'];
        return `<ol class="bh-results-list">${results.map(r => `
            <li class="${r.rank <= 3 ? 'bh-results-top' : ''}">
                <span class="bh-results-rank">${r.rank <= 3 ? medals[r.rank - 1] : '#' + r.rank}</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">${this.esc(r.title)}</span>
                    <span class="bh-results-artist">${this.esc(r.artist)}</span>
                </span>
                <span class="bh-results-votes">${r.votes} vote${r.votes === 1 ? '' : 's'}</span>
            </li>`).join('')}</ol>`;
    }

    // Every category's results flattened into one list, re-ranked by vote
    // count across the whole contest, with a colored category badge per
    // row (matching the tab colors) so it reads as "all of it in one
    // place" rather than a confusing mash-up.
    renderAllResultsList(cats) {
        const rows = [];
        cats.forEach(c => (c.results || []).forEach(r => rows.push({ ...r, categoryName: c.name, categorySlug: c.slug })));
        rows.sort((a, b) => b.votes - a.votes);
        rows.forEach((r, i) => { r.rank = i + 1; });
        const top = rows.slice(0, 20);
        if (!top.length) return '<p class="bh-results-empty">No votes have been cast yet.</p>';

        const medals = ['🥇', '🥈', '🥉'];
        return `<ol class="bh-results-list">${top.map(r => `
            <li class="${r.rank <= 3 ? 'bh-results-top' : ''}">
                <span class="bh-results-rank">${r.rank <= 3 ? medals[r.rank - 1] : '#' + r.rank}</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">${this.esc(r.title)}</span>
                    <span class="bh-results-artist">${this.esc(r.artist)}</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:${this.catColor(r.categorySlug)}">${this.esc(r.categoryName)}</span>
                <span class="bh-results-votes">${r.votes} vote${r.votes === 1 ? '' : 's'}</span>
            </li>`).join('')}</ol>`;
    }

    renderResultsBody() {
        const out = this.q('.bh-results-body');
        const cats = this._resultsCats;
        const tabDefs = cats.length > 1 ? [{ slug: 'all', name: 'All' }, ...cats] : cats;

        const tabs = tabDefs.length > 1
            ? `<div class="bh-category-tabs bh-results-tabs">${tabDefs.map(c => `
                <button class="bh-cat-tab ${c.slug === this._resultsActive ? 'active' : ''}" data-cat="${this.esc(c.slug)}" style="--bh-cat-color:${c.slug === 'all' ? 'var(--bh-text-dim)' : this.catColor(c.slug)}">${this.esc(c.name)}</button>
              `).join('')}</div>`
            : '';

        const body = this._resultsActive === 'all'
            ? this.renderAllResultsList(cats)
            : this.renderResultsList((cats.find(c => c.slug === this._resultsActive) || cats[0]).results);

        out.innerHTML = tabs + body;

        if (tabDefs.length > 1) {
            out.querySelectorAll('.bh-cat-tab').forEach(btn => {
                btn.onclick = () => { this._resultsActive = btn.dataset.cat; this.renderResultsBody(); };
            });
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bh-player-root').forEach(root => new BHPlayer(root));

    // The email verification link redirects back here with this flag.
    // Doesn't need a BHPlayer instance — toast() just appends a plain
    // element to <body>, so a standalone version works the same way,
    // best-effort on whatever page the redirect happened to land on
    // (only actually visible if that page also has player.css loaded,
    // i.e. has the contest shortcode — see is_email_verified()'s own
    // notes on this in class-auth.php for the reasoning).
    const params = new URLSearchParams(location.search);
    if (params.has('bh_verified')) {
        const ok = params.get('bh_verified') === '1';
        const msg = ok ? 'Email confirmed — you can vote and submit now!' : 'That verification link is invalid or expired.';
        let t = document.getElementById('bh-toast');
        if (!t) { t = document.createElement('div'); t.id = 'bh-toast'; t.className = 'bh-toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.toggle('error', !ok);
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3400);

        params.delete('bh_verified');
        const clean = location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash;
        history.replaceState({}, '', clean);
    }
});
