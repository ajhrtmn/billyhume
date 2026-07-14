(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.querySelector('.bh-judge-panel');
        if (!panel) return;

        panel.addEventListener('input', function (e) {
            if (!e.target.matches('input[type="range"][data-criterion]')) return;
            var out = e.target.closest('.bh-judge-criterion').querySelector('.bh-judge-criterion-value');
            if (out) out.textContent = e.target.value;
        });

        function collectScores(entry) {
            var scores = {};
            entry.querySelectorAll('input[data-criterion]').forEach(function (input) {
                scores[input.dataset.criterion] = parseInt(input.value, 10) || 0;
            });
            return scores;
        }

        function save(entry, status) {
            var body = new URLSearchParams();
            body.set('contest_id', BHJudgeData.contestId);
            body.set('submission_id', entry.dataset.submissionId);
            body.set('category', entry.dataset.category);
            body.set('status', status);
            var scores = collectScores(entry);
            Object.keys(scores).forEach(function (k) { body.set('scores[' + k + ']', scores[k]); });

            var statusEl = entry.querySelector('.bh-judge-status');
            fetch(BHJudgeData.restUrl, {
                method: 'POST',
                headers: { 'X-WP-Nonce': BHJudgeData.nonce, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Could not save score.', 'error'); } else { alert('Could not save score.'); }
                        return;
                    }
                    var submitBtn = entry.querySelector('.bh-judge-submit');
                    var submitted = res.status === 'submitted';
                    entry.classList.toggle('bh-judge-entry-submitted', submitted);
                    if (statusEl) statusEl.textContent = submitted ? 'Submitted' : 'Draft';
                    if (submitBtn) submitBtn.textContent = submitted ? 'Update submission' : 'Submit score';
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(submitted ? 'Score submitted.' : 'Draft saved.', 'success'); }
                });
        }

        panel.addEventListener('click', function (e) {
            var entry = e.target.closest('.bh-judge-entry');
            if (!entry) return;
            if (e.target.classList.contains('bh-judge-save-draft')) save(entry, 'draft');
            if (e.target.classList.contains('bh-judge-submit')) save(entry, 'submitted');
        });
    });
})();
