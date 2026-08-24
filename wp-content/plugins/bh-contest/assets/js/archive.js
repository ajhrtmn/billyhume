"use strict";
/**
 * Archive/Library — fetches the full catalog once, then filters
 * client-side (search + contest dropdown) rather than re-fetching per
 * keystroke. Reasonable for a catalog of the size this plugin is likely
 * to accumulate; worth revisiting with real pagination if a site ends up
 * with a genuinely large number of contests down the line.
 *
 * TypeScript pilot conversion — same posture as reveal.ts/bh-common.ts.
 * bhEsc is declared as an untyped external global (defined in the
 * already-converted bh-common.ts, loaded first — see bh-contest.php's
 * enqueue order).
 */
(function () {
    const root = document.getElementById('bh-archive-root');
    if (!root)
        return;
    const rest = (window.BHData && window.BHData.rest) || '';
    const grid = document.getElementById('bh-archive-grid');
    const search = document.getElementById('bh-archive-search');
    const filter = document.getElementById('bh-archive-filter');
    let allTracks = [];
    function render() {
        const q = search.value.trim().toLowerCase();
        const cid = filter.value;
        const tracks = allTracks.filter((t) => {
            if (cid && String(t.contest_id) !== cid)
                return false;
            if (q && t.title.toLowerCase().indexOf(q) === -1 && t.artist.toLowerCase().indexOf(q) === -1)
                return false;
            return true;
        });
        if (!tracks.length) {
            grid.innerHTML = '<p class="bh-empty">No tracks match.</p>';
            return;
        }
        grid.innerHTML = tracks.map((t) => {
            const badges = (t.placements || []).map((p) => '<span class="bh-archive-badge">' + bhEsc(p) + '</span>').join('');
            const audio = t.url ? '<audio controls preload="none" src="' + bhEsc(t.url) + '" class="bh-archive-audio"></audio>' : '';
            return '<div class="bh-archive-card ous-catalog-card">'
                + '<div class="bh-archive-title">' + bhEsc(t.title) + '</div>'
                + '<div class="bh-archive-artist">' + bhEsc(t.artist) + '</div>'
                + '<div class="bh-archive-contest">' + bhEsc(t.contest_title) + '</div>'
                + (badges ? '<div class="bh-archive-badges">' + badges + '</div>' : '')
                + audio
                + '</div>';
        }).join('');
        // Only one track plays at a time.
        grid.querySelectorAll('.bh-archive-audio').forEach((audioEl) => {
            audioEl.addEventListener('play', () => {
                grid.querySelectorAll('.bh-archive-audio').forEach((other) => {
                    if (other !== audioEl)
                        other.pause();
                });
            });
        });
    }
    fetch(rest + 'library')
        .then((r) => r.json())
        .then((data) => {
        allTracks = data.tracks || [];
        (data.contests || []).forEach((c) => {
            const opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = c.title;
            filter.appendChild(opt);
        });
        render();
    })
        .catch(() => {
        grid.innerHTML = '<p class="bh-empty">Could not load the archive right now.</p>';
    });
    search.addEventListener('input', render);
    filter.addEventListener('change', render);
})();
