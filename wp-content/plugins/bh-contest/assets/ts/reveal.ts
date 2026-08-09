/**
 * Results Reveal — public display. Polls its own state from the server
 * rather than sharing a page/tab with the admin controller, so this can
 * run on a completely different machine (e.g. the one doing the OBS
 * capture) from whatever machine the admin is clicking "Next" on.
 *
 * TypeScript pilot conversion (bh-contest's first), same posture as
 * own-ur-shit/assets/ts/*.ts: plain `tsc`, no bundler, compiled output
 * (assets/js/reveal.js) is committed since the live FTP-deployed site
 * runs no build step. Run `npm run build:bh-contest` after editing.
 * bhEsc (assets/js/bh-common.js) and anime (vendored assets/js/vendor/
 * anime.min.js) are untyped external globals, declared loosely below —
 * not worth a real type package for either.
 */

declare function bhEsc(value: unknown): string;

interface AnimeStagger {
    (value: number): unknown;
}

interface AnimeApi {
    animate(targets: unknown, params: Record<string, unknown>): unknown;
    stagger: AnimeStagger;
}

interface BHRevealWindow extends Window {
    BHData?: { rest?: string };
    anime?: AnimeApi;
}

interface BHRevealEntry {
    rank: number;
    title: string;
    artist: string;
    votes: unknown;
}

interface BHRevealState {
    type: string;
    title?: string;
    category?: string;
    entry_count?: number;
    entries?: BHRevealEntry[];
    just_revealed_rank?: number;
    source?: string;
    authoritative_index?: number;
}

(function () {
    var stage = document.getElementById('bh-reveal-stage');
    if (!stage) return;

    var cid = stage.dataset.contest || '';
    var win = window as BHRevealWindow;
    var rest = (win.BHData && win.BHData.rest) || '';
    var lastIndex: number | null = null;

    function medalIcon(rank: number): string {
        return rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank;
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: a judges-
    // sourced step's 'votes' field is actually a normalized 0-100 score
    // (see BH_Judging::judge_results()'s own docblock for why it's kept
    // under the same key rather than a new one) — labeled "score" here
    // purely for display so a judged leaderboard doesn't misleadingly
    // read as "42 votes".
    function renderEntries(entries: BHRevealEntry[], justRevealedRank?: number, source?: string): string {
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

    function render(data: BHRevealState): void {
        var html = '';
        if (data.type === 'none') {
            html = '<div class="bh-reveal-loading">No contest ready to reveal yet.</div>';
        } else if (data.type === 'intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Results</div><h1>' + bhEsc(data.title) + '</h1></div>';
        } else if (data.type === 'pass_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Now Revealing</div><h1>' + bhEsc(data.title) + '</h1></div>';
        } else if (data.type === 'category_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Category</div><h1>' + bhEsc(data.category) + '</h1>'
                + '<p class="bh-reveal-subtext">' + bhEsc(data.entry_count) + (data.entry_count === 1 ? ' entry' : ' entries') + '</p></div>';
        } else if (data.type === 'overall_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Grand Finale</div><h1>Overall</h1>'
                + '<p class="bh-reveal-subtext">Across all categories &mdash; ' + bhEsc(data.entry_count) + (data.entry_count === 1 ? ' entry' : ' entries') + '</p></div>';
        } else if (data.type === 'category_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">' + bhEsc(data.category) + '</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries || [], data.just_revealed_rank, data.source) + '</div></div>';
        } else if (data.type === 'overall_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">Overall</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries || [], data.just_revealed_rank) + '</div></div>';
        } else if (data.type === 'end') {
            html = '<div class="bh-reveal-intro"><h1>Thanks for watching!</h1></div>';
        }
        (stage as HTMLElement).innerHTML = html;
        animateReveal(data.type);
    }

    // anime.js v4 (vendored, assets/js/vendor/anime.min.js) takes over
    // the actual motion on a leaderboard reveal — the sequencing/pacing
    // clock (poll()/catchUp() below) is unchanged, this only replaces
    // the single blunt CSS keyframe (.bh-reveal-entry-new's old
    // 'bh-reveal-pop' animation, player.css) that used to be the only
    // animation on a fresh innerHTML swap. Staggered entry + a bigger
    // flourish on the winner specifically. v4's API uses shorthand
    // property names (x/y, not translateX/translateY) and an 'ease'
    // param rather than v3's 'easing' — confirmed against the real
    // v4.5.0 source/README before use; the exact easing string name and
    // whether 'opacity'/'scale' are literally valid property names in
    // v4 were NOT independently doc-verified beyond reasonable inference
    // from one confirmed README example — worth a real-browser check
    // before relying on this.
    function animateReveal(type: string): void {
        var anime = win.anime;
        if (typeof anime === 'undefined') return;
        if (type !== 'category_reveal' && type !== 'overall_reveal') return;

        var entries = (stage as HTMLElement).querySelectorAll('.bh-reveal-entry');
        if (!entries.length) return;

        anime.animate(entries, {
            opacity: [0, 1],
            y: [16, 0],
            delay: anime.stagger(90),
            duration: 450,
            ease: 'outCubic',
        });

        var winner = (stage as HTMLElement).querySelector('.bh-reveal-entry-winner');
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

    function poll(): void {
        if (stepping) return;
        var url = rest + 'reveal/state' + (cid ? '?contest=' + encodeURIComponent(cid) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data: BHRevealState) {
            var target = data.authoritative_index;
            if (target === lastIndex) return; // nothing changed

            // First load, a single-step advance, or a rewind (Previous /
            // Reset) — nothing to catch up on, just show it directly.
            if (lastIndex === null || (typeof target === 'number' && target <= lastIndex + 1)) {
                lastIndex = typeof target === 'number' ? target : lastIndex;
                render(data);
                return;
            }

            // The admin advanced by more than one step since the last
            // poll (a fast double-click, or just unlucky timing against
            // the poll interval) — walk through each skipped step in
            // turn with a real pause between them, rather than jumping
            // straight to the end and silently skipping whatever
            // suspense should have played out in between.
            if (typeof target === 'number') {
                catchUp(lastIndex + 1, target);
            }
        }).catch(function () { /* transient network hiccup — next poll will catch up */ });
    }

    function catchUp(from: number, to: number): void {
        stepping = true;
        var i = from;
        function next(): void {
            if (i > to) { stepping = false; lastIndex = to; return; }
            var url = rest + 'reveal/state?index=' + i + (cid ? '&contest=' + encodeURIComponent(cid) : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (data: BHRevealState) {
                render(data);
                i++;
                setTimeout(next, 1800); // same pacing a human clicking through would produce
            }).catch(function () { stepping = false; }); // give up the catch-up on a network hiccup — the next regular poll will resync from wherever things actually are
        }
        next();
    }

    poll();
    setInterval(poll, 2500);
})();
