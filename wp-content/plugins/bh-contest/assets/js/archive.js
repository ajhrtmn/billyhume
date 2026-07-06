// Archive/Library — fetches the full catalog once, then filters
// client-side (search + contest dropdown) rather than re-fetching per
// keystroke. Reasonable for a catalog of the size this plugin is likely
// to accumulate; worth revisiting with real pagination if a site ends up
// with a genuinely large number of contests down the line.
(function () {
    var root = document.getElementById('bh-archive-root');
    if (!root) return;

    var rest = (window.BHData && window.BHData.rest) || '';
    var grid = document.getElementById('bh-archive-grid');
    var search = document.getElementById('bh-archive-search');
    var filter = document.getElementById('bh-archive-filter');
    var allTracks = [];

    function render() {
        var q = search.value.trim().toLowerCase();
        var cid = filter.value;
        var tracks = allTracks.filter(function (t) {
            if (cid && String(t.contest_id) !== cid) return false;
            if (q && t.title.toLowerCase().indexOf(q) === -1 && t.artist.toLowerCase().indexOf(q) === -1) return false;
            return true;
        });

        if (!tracks.length) { grid.innerHTML = '<p class="bh-empty">No tracks match.</p>'; return; }

        grid.innerHTML = tracks.map(function (t) {
            var badges = (t.placements || []).map(function (p) {
                return '<span class="bh-archive-badge">' + bhEsc(p) + '</span>';
            }).join('');
            var audio = t.url ? '<audio controls preload="none" src="' + bhEsc(t.url) + '" class="bh-archive-audio"></audio>' : '';
            return '<div class="bh-archive-card">'
                + '<div class="bh-archive-title">' + bhEsc(t.title) + '</div>'
                + '<div class="bh-archive-artist">' + bhEsc(t.artist) + '</div>'
                + '<div class="bh-archive-contest">' + bhEsc(t.contest_title) + '</div>'
                + (badges ? '<div class="bh-archive-badges">' + badges + '</div>' : '')
                + audio
                + '</div>';
        }).join('');

        // Only one track plays at a time.
        grid.querySelectorAll('.bh-archive-audio').forEach(function (audioEl) {
            audioEl.addEventListener('play', function () {
                grid.querySelectorAll('.bh-archive-audio').forEach(function (other) {
                    if (other !== audioEl) other.pause();
                });
            });
        });
    }

    fetch(rest + 'library').then(function (r) { return r.json(); }).then(function (data) {
        allTracks = data.tracks || [];
        (data.contests || []).forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.title;
            filter.appendChild(opt);
        });
        render();
    }).catch(function () {
        grid.innerHTML = '<p class="bh-empty">Could not load the archive right now.</p>';
    });

    search.addEventListener('input', render);
    filter.addEventListener('change', render);
})();
