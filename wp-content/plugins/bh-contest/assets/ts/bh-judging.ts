/**
 * TypeScript pilot conversion — same posture as this plugin's other
 * converted files. BHJudgeData/BHCoreToast are untyped external
 * globals (localized data + an optional shared toast helper).
 */

interface BHJudgeDataGlobal {
    contestId: string | number;
    restUrl: string;
    nonce: string;
}

declare const BHJudgeData: BHJudgeDataGlobal;
declare const BHCoreToast: { show(message: string, type: string): void } | undefined;

interface BHJudgeSaveResponse {
    success: boolean;
    status?: string;
}

(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const panel = document.querySelector('.bh-judge-panel');
        if (!panel) return;

        panel.addEventListener('input', (e) => {
            const target = e.target as HTMLElement;
            if (!target.matches('input[type="range"][data-criterion]')) return;
            const criterion = target.closest('.bh-judge-criterion');
            const out = criterion ? criterion.querySelector('.bh-judge-criterion-value') : null;
            if (out) out.textContent = (target as HTMLInputElement).value;
        });

        function collectScores(entry: HTMLElement): Record<string, number> {
            const scores: Record<string, number> = {};
            entry.querySelectorAll('input[data-criterion]').forEach((input) => {
                const el = input as HTMLInputElement;
                const criterion = el.dataset.criterion;
                if (criterion) scores[criterion] = parseInt(el.value, 10) || 0;
            });
            return scores;
        }

        function save(entry: HTMLElement, status: string, attempt = 0) {
            const body = new URLSearchParams();
            body.set('contest_id', String(BHJudgeData.contestId));
            body.set('submission_id', entry.dataset.submissionId ?? '');
            body.set('category', entry.dataset.category ?? '');
            body.set('status', status);
            const scores = collectScores(entry);
            Object.keys(scores).forEach((k) => { body.set('scores[' + k + ']', String(scores[k])); });

            const statusEl = entry.querySelector('.bh-judge-status');
            fetch(BHJudgeData.restUrl, {
                method: 'POST',
                headers: { 'X-WP-Nonce': BHJudgeData.nonce, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then((r) => r.json() as Promise<BHJudgeSaveResponse>)
                .then((res) => {
                    if (!res.success) {
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Could not save score.', 'error'); } else { alert('Could not save score.'); }
                        return;
                    }
                    const submitBtn = entry.querySelector('.bh-judge-submit');
                    const submitted = res.status === 'submitted';
                    entry.classList.toggle('bh-judge-entry-submitted', submitted);
                    if (statusEl) statusEl.textContent = submitted ? 'Submitted' : 'Draft';
                    if (submitBtn) submitBtn.textContent = submitted ? 'Update submission' : 'Submit score';
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(submitted ? 'Score submitted.' : 'Draft saved.', 'success'); }
                })
                .catch(() => {
                    // Retry-audit pass, AJ's own standing ask: this had
                    // no .catch() at all — a dropped connection here
                    // silently failed with zero feedback, and (worse)
                    // a judge could reasonably think their submitted
                    // score went through when it never left the
                    // browser. Safe to retry — the server side
                    // (BH_Judging::save_score()) is a real
                    // ON DUPLICATE KEY UPDATE upsert keyed on judge+
                    // submission+category, confirmed by reading it, not
                    // an insert-only log a retry could duplicate.
                    if (attempt < 2) {
                        if (statusEl) statusEl.textContent = 'Retrying…';
                        setTimeout(() => { save(entry, status, attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                        return;
                    }
                    const msg = 'Could not reach the server — your score was NOT saved. Check your connection and try again.';
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); } else { alert(msg); }
                });
        }

        panel.addEventListener('click', (e) => {
            const target = e.target as HTMLElement;
            const entry = target.closest('.bh-judge-entry') as HTMLElement | null;
            if (!entry) return;
            if (target.classList.contains('bh-judge-save-draft')) save(entry, 'draft');
            if (target.classList.contains('bh-judge-submit')) save(entry, 'submitted');
        });
    });
})();
