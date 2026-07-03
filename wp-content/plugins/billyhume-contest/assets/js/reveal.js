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

    function renderEntries(entries, justRevealedRank) {
        return entries.map(function (e) {
            var isNew = e.rank === justRevealedRank;
            var isWinner = e.rank === 1;
            return '<div class="bh-reveal-entry' + (isNew ? ' bh-reveal-entry-new' : '') + (isWinner ? ' bh-reveal-entry-winner' : '') + '">'
                + '<span class="bh-reveal-medal">' + medalIcon(e.rank) + '</span>'
                + '<span class="bh-reveal-entry-info"><span class="bh-reveal-entry-title">' + bhEsc(e.title) + '</span>'
                + '<span class="bh-reveal-entry-artist">' + bhEsc(e.artist) + '</span></span>'
                + '<span class="bh-reveal-entry-votes">' + bhEsc(e.votes) + ' votes</span>'
                + '</div>';
        }).join('');
    }

    function render(data) {
        var html = '';
        if (data.type === 'none') {
            html = '<div class="bh-reveal-loading">No contest ready to reveal yet.</div>';
        } else if (data.type === 'intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Results</div><h1>' + bhEsc(data.title) + '</h1></div>';
        } else if (data.type === 'category_intro') {
            html = '<div class="bh-reveal-intro"><div class="bh-reveal-kicker">Category</div><h1>' + bhEsc(data.category) + '</h1></div>';
        } else if (data.type === 'category_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">' + bhEsc(data.category) + '</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries, data.just_revealed_rank) + '</div></div>';
        } else if (data.type === 'overall_reveal') {
            html = '<div class="bh-reveal-board"><div class="bh-reveal-kicker">Overall</div>'
                + '<div class="bh-reveal-entries">' + renderEntries(data.entries, data.just_revealed_rank) + '</div></div>';
        } else if (data.type === 'end') {
            html = '<div class="bh-reveal-intro"><h1>Thanks for watching!</h1></div>';
        }
        stage.innerHTML = html;
    }

    function poll() {
        var url = rest + 'reveal/state' + (cid ? '?contest=' + encodeURIComponent(cid) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            if (data.index === lastIndex) return; // nothing changed — don't re-render/re-trigger animations
            lastIndex = data.index;
            render(data);
        }).catch(function () { /* transient network hiccup — next poll will catch up */ });
    }

    poll();
    setInterval(poll, 2500);
})();
