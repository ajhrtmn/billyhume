// Results Reveal — public display. Polls its own state from the server
// rather than sharing a page/tab with the admin controller, so this can
// run on a completely different machine (e.g. the one doing the OBS
// capture) from whatever machine the admin is clicking "Next" on.
(function () {
    var stage = document.getElementById('bh-reveal-stage');
    if (!stage) return;

    var cid = stage.dataset.contest || '';
    var rest = (window.BHData && window.BHData.rest) || '';
    var lastIndex = null;

    function medalIcon(rank) {
        return rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank;
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: a judges-
    // sourced step's 'votes' field is actually a normalized 0-100 score
    // (see BH_Judging::judge_results()'s own docblock for why it's kept
    // under the same key rather than a new one) — labeled "score" here
    // purely for display so a judged leaderboard doesn't misleadingly
    // read as "42 votes".
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
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries, data.just_revealed_rank, data.source) + '</div></div>';
        } else if (data.type === 'overall_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">Overall</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries, data.just_revealed_rank) + '</div></div>';
        } else if (data.type === 'end') {
            html = '<div class="bh-reveal-intro"><h1>Thanks for watching!</h1></div>';
        }
        stage.innerHTML = html;
    }

    var stepping = false; // true while walking through a catch-up sequence — pauses regular polling so it can't overlap and race

    function poll() {
        if (stepping) return;
        var url = rest + 'reveal/state' + (cid ? '?contest=' + encodeURIComponent(cid) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            var target = data.authoritative_index;
            if (target === lastIndex) return; // nothing changed

            // First load, a single-step advance, or a rewind (Previous /
            // Reset) — nothing to catch up on, just show it directly.
            if (lastIndex === null || target <= lastIndex + 1) {
                lastIndex = target;
                render(data);
                return;
            }

            // The admin advanced by more than one step since the last
            // poll (a fast double-click, or just unlucky timing against
            // the poll interval) — walk through each skipped step in
            // turn with a real pause between them, rather than jumping
            // straight to the end and silently skipping whatever
            // suspense should have played out in between.
            catchUp(lastIndex + 1, target);
        }).catch(function () { /* transient network hiccup — next poll will catch up */ });
    }

    function catchUp(from, to) {
        stepping = true;
        var i = from;
        function next() {
            if (i > to) { stepping = false; lastIndex = to; return; }
            var url = rest + 'reveal/state?index=' + i + (cid ? '&contest=' + encodeURIComponent(cid) : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (data) {
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
