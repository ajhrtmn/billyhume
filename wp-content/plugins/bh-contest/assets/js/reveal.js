"use strict";
/**
 * Results Reveal public display. Polls the server for state, so it can run on a
 * different machine (e.g. the OBS one) from the admin controller.
 * bhEsc and anime are untyped globals, declared loosely below.
 */
(function () {
    var stage = document.getElementById('bh-reveal-stage');
    if (!stage)
        return;
    var cid = stage.dataset.contest || '';
    var win = window;
    var rest = (win.BHData && win.BHData.rest) || '';
    var lastIndex = null;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Sound is on by default; the presenter's choice persists in localStorage.
    var SOUND_KEY = 'bh_reveal_sound';
    var soundOn = localStorage.getItem(SOUND_KEY) !== 'off';
    var soundToggle = document.getElementById('bh-reveal-sound-toggle');
    function syncSoundToggle() {
        if (!soundToggle)
            return;
        soundToggle.textContent = soundOn ? '🔊' : '🔇';
        soundToggle.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
    }
    if (soundToggle) {
        syncSoundToggle();
        soundToggle.addEventListener('click', function () {
            soundOn = !soundOn;
            localStorage.setItem(SOUND_KEY, soundOn ? 'on' : 'off');
            syncSoundToggle();
        });
    }
    // Web Audio synthesized tones (no audio assets); one shared AudioContext.
    var audioCtx = null;
    function getAudioCtx() {
        if (audioCtx)
            return audioCtx;
        var Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor)
            return null;
        audioCtx = new Ctor();
        return audioCtx;
    }
    function tone(freq, startAt, duration, gain) {
        var ctx = getAudioCtx();
        if (!ctx)
            return;
        var osc = ctx.createOscillator();
        var g = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        g.gain.setValueAtTime(0, ctx.currentTime + startAt);
        g.gain.linearRampToValueAtTime(gain, ctx.currentTime + startAt + 0.02);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + startAt + duration);
        osc.connect(g);
        g.connect(ctx.destination);
        osc.start(ctx.currentTime + startAt);
        osc.stop(ctx.currentTime + startAt + duration + 0.05);
    }
    // Short blip for an ordinary entry.
    function playRevealBlip() {
        if (!soundOn)
            return;
        tone(660, 0, 0.15, 0.08);
    }
    // Three-note fanfare reserved for #1.
    function playWinnerFanfare() {
        if (!soundOn)
            return;
        tone(523.25, 0, 0.18, 0.09);
        tone(659.25, 0.12, 0.18, 0.09);
        tone(783.99, 0.24, 0.4, 0.11);
    }
    function medalIcon(rank) {
        return rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank;
    }
    // For a judges step, 'votes' holds a 0-100 score, so it's labeled "score".
    function renderEntries(entries, justRevealedRank, source) {
        var unit = source === 'judges' ? ' score' : ' votes';
        return entries.map(function (e) {
            var isNew = e.rank === justRevealedRank;
            var isWinner = e.rank === 1;
            return '<div class="bh-reveal-entry' + (isNew ? ' bh-reveal-entry-new' : '') + (isWinner ? ' bh-reveal-entry-winner' : '') + '">'
                + '<span class="bh-reveal-medal">' + medalIcon(e.rank) + '</span>'
                + '<span class="bh-reveal-entry-info"><span class="bh-reveal-entry-title">' + bhEsc(e.title) + '</span>'
                + '<span class="bh-reveal-entry-artist">' + bhEsc(e.artist) + '</span></span>'
                + '<span class="bh-reveal-entry-votes">' + bhEsc(e.votes) + unit + '</span>'
                + '</div>';
        }).join('');
    }
    function render(data) {
        var html = '';
        if (data.type === 'none') {
            html = '<div class="bh-reveal-loading">No contest ready to reveal yet.</div>';
        }
        else if (data.type === 'intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Results</div><h1>' + bhEsc(data.title) + '</h1></div>';
        }
        else if (data.type === 'pass_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Now Revealing</div><h1>' + bhEsc(data.title) + '</h1></div>';
        }
        else if (data.type === 'category_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Category</div><h1>' + bhEsc(data.category) + '</h1>'
                + '<p class="bh-reveal-subtext">' + bhEsc(data.entry_count) + (data.entry_count === 1 ? ' entry' : ' entries') + '</p></div>';
        }
        else if (data.type === 'overall_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Grand Finale</div><h1>Overall</h1>'
                + '<p class="bh-reveal-subtext">Across all categories &mdash; ' + bhEsc(data.entry_count) + (data.entry_count === 1 ? ' entry' : ' entries') + '</p></div>';
        }
        else if (data.type === 'category_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">' + bhEsc(data.category) + '</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries || [], data.just_revealed_rank, data.source) + '</div></div>';
        }
        else if (data.type === 'overall_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">Overall</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries || [], data.just_revealed_rank) + '</div></div>';
        }
        else if (data.type === 'end') {
            html = '<div class="bh-reveal-intro"><h1>Thanks for watching!</h1></div>';
        }
        stage.innerHTML = html;
        animateReveal(data.type, data.just_revealed_rank);
        playCueFor(data.type, data.just_revealed_rank);
    }
    function playCueFor(type, justRevealedRank) {
        if (type !== 'category_reveal' && type !== 'overall_reveal')
            return;
        if (justRevealedRank === undefined)
            return;
        if (justRevealedRank === 1)
            playWinnerFanfare();
        else
            playRevealBlip();
    }
    // Confetti for #1. The canvas is removed after each run so a long OBS session
    // never accumulates them; skipped under prefers-reduced-motion.
    function confettiBurst() {
        if (reducedMotion)
            return;
        var canvas = document.createElement('canvas');
        canvas.className = 'bh-reveal-confetti';
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        document.body.appendChild(canvas);
        var rawCtx = canvas.getContext('2d');
        if (!rawCtx) {
            canvas.remove();
            return;
        }
        var ctx = rawCtx;
        var colors = ['#FFD700', '#FF6B6B', '#4ECDC4', '#95E1D3', '#F38181'];
        var particles = [];
        for (var i = 0; i < 90; i++) {
            particles.push({
                x: canvas.width / 2 + (Math.random() - 0.5) * 200,
                y: canvas.height * 0.35,
                vx: (Math.random() - 0.5) * 12,
                vy: Math.random() * -10 - 4,
                size: Math.random() * 6 + 4,
                color: colors[i % colors.length] || '#FFD700',
                rotation: Math.random() * 360,
                spin: (Math.random() - 0.5) * 20,
            });
        }
        var start = performance.now();
        function frame(now) {
            var elapsed = now - start;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(function (p) {
                p.vy += 0.35; // gravity
                p.x += p.vx;
                p.y += p.vy;
                p.rotation += p.spin;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate((p.rotation * Math.PI) / 180);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            });
            if (elapsed < 2600) {
                requestAnimationFrame(frame);
            }
            else {
                canvas.remove();
            }
        }
        requestAnimationFrame(frame);
    }
    // Staggered entry animation plus a winner pulse, via vendored anime.js v4.
    // v4 uses x/y (not translateX/Y) and 'ease' (not 'easing').
    function animateReveal(type, justRevealedRank) {
        if (type !== 'category_reveal' && type !== 'overall_reveal')
            return;
        var entries = stage.querySelectorAll('.bh-reveal-entry');
        if (!entries.length)
            return;
        if (justRevealedRank === 1)
            confettiBurst();
        var anime = win.anime;
        if (typeof anime === 'undefined' || reducedMotion)
            return;
        anime.animate(entries, {
            opacity: [0, 1],
            y: [16, 0],
            delay: anime.stagger(90),
            duration: 450,
            ease: 'outCubic',
        });
        var winner = stage.querySelector('.bh-reveal-entry-winner');
        if (winner) {
            anime.animate(winner, {
                scale: [1, 1.08, 1],
                duration: 700,
                delay: 450, // after the staggered entries have settled
                ease: 'inOutQuad',
            });
        }
    }
    var stepping = false; // true while walking through a catch-up sequence — pauses regular polling so it can't overlap and race
    var liveBadge = document.getElementById('bh-reveal-live');
    var liveLabel = document.querySelector('.bh-reveal-live-label');
    var missedPolls = 0;
    function markStalled(stalled) {
        if (!liveBadge)
            return;
        liveBadge.classList.toggle('bh-reveal-live-stalled', stalled);
        if (liveLabel)
            liveLabel.textContent = stalled ? 'RECONNECTING' : 'LIVE';
    }
    function poll() {
        if (stepping)
            return;
        var url = rest + 'reveal/state' + (cid ? '?contest=' + encodeURIComponent(cid) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            missedPolls = 0;
            markStalled(false);
            var target = data.authoritative_index;
            if (target === lastIndex)
                return; // nothing changed
            // First load, one-step advance, or rewind: render directly.
            if (lastIndex === null || (typeof target === 'number' && target <= lastIndex + 1)) {
                lastIndex = typeof target === 'number' ? target : lastIndex;
                render(data);
                return;
            }
            // Skipped several steps between polls: walk through each one with a pause.
            if (typeof target === 'number') {
                catchUp(lastIndex + 1, target);
            }
        }).catch(function () {
            // Show "reconnecting" only after several misses in a row.
            missedPolls++;
            if (missedPolls >= 3)
                markStalled(true);
        });
    }
    function catchUp(from, to) {
        stepping = true;
        var i = from;
        function next() {
            if (i > to) {
                stepping = false;
                lastIndex = to;
                return;
            }
            var url = rest + 'reveal/state?index=' + i + (cid ? '&contest=' + encodeURIComponent(cid) : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (data) {
                render(data);
                i++;
                setTimeout(next, 1800); // same pacing a human clicking through would produce
            }).catch(function () { stepping = false; }); // abandon catch-up; the next poll resyncs
        }
        next();
    }
    poll();
    setInterval(poll, 1500);
})();
