/**
 * portal-submissions.js — BH_PortalPanel's "Replace file" and "Edit
 * details" forms (class-portal-panel.php). Vanilla JS, no build step,
 * same convention as this ecosystem's other front-end widgets.
 * bhContestPortalConfig (wp_localize_script) carries restUrl + nonce.
 *
 * TypeScript pilot conversion — same posture as this plugin's other
 * converted files.
 */

interface BHContestPortalConfig {
    restUrl?: string;
    nonce?: string;
}

interface BHPortalSubmissionsWindow extends Window {
    bhContestPortalConfig?: BHContestPortalConfig;
}

// BHCoreToast is declared once, in bh-judging.ts — both files compile
// as part of the same tsc program (module: "none" means shared global
// scope, same as the plain <script> tags this compiles to), so a second
// `declare const` here would conflict rather than merge.

interface BHRestResult<T> {
    ok: boolean;
    body: T;
}

interface BHSaveResponseBody {
    message?: string;
}

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const cfg = (window as BHPortalSubmissionsWindow).bhContestPortalConfig || {};

        document.querySelectorAll('.bh-edit-details-form').forEach((formEl) => {
            const form = formEl as HTMLFormElement;
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const titleInput = form.querySelector('.bh-edit-title') as HTMLInputElement;
                const artistInput = form.querySelector('.bh-edit-artist') as HTMLInputElement;
                const statusEl = form.querySelector('.bh-edit-status') as HTMLElement;
                const btn = form.querySelector('button[type=submit]') as HTMLButtonElement;

                const fd = new FormData();
                fd.append('title', titleInput.value.trim());
                fd.append('artist', artistInput.value.trim());

                btn.disabled = true;
                const originalLabel = btn.textContent;
                btn.textContent = 'Saving…';
                statusEl.textContent = '';

                fetch(cfg.restUrl + 'submissions/edit-details?submission_id=' + encodeURIComponent(form.dataset.submissionId ?? ''), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
                    body: fd,
                }).then((res) => res.json().then((body: BHSaveResponseBody) => ({ ok: res.ok, body } as BHRestResult<BHSaveResponseBody>)))
                    .then((r) => {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        if (r.ok) {
                            // Was a plain-text status line next to the button —
                            // every sibling flow in this ecosystem (voting,
                            // judging, registry) confirms through BHCoreToast;
                            // this was the one holdout still feeling flatter
                            // than the rest of the site. Falls back to the
                            // original inline text if the shared toast script
                            // hasn't loaded for any reason.
                            const msg = r.body.message || 'Saved.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'success'); }
                            else { statusEl.textContent = msg; statusEl.style.color = 'green'; }
                            setTimeout(() => { window.location.reload(); }, 1400);
                        } else {
                            const errMsg = (r.body && r.body.message) || 'Save failed.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(errMsg, 'error'); }
                            else { statusEl.textContent = errMsg; statusEl.style.color = '#b32d2e'; }
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        const msg = 'Save failed — check your connection and try again.';
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); }
                        else { statusEl.textContent = msg; statusEl.style.color = '#b32d2e'; }
                    });
            });
        });

        document.querySelectorAll('.bh-replace-audio-form').forEach((formEl) => {
            const form = formEl as HTMLFormElement;
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const fileInput = form.querySelector('input[type=file]') as HTMLInputElement;
                const statusEl = form.querySelector('.bh-replace-status') as HTMLElement;
                const btn = form.querySelector('button[type=submit]') as HTMLButtonElement;
                if (!fileInput.files || !fileInput.files.length) {
                    statusEl.textContent = 'Choose a file first.';
                    return;
                }

                const fd = new FormData();
                fd.append('audio', fileInput.files[0]!); // .length check above guarantees index 0 exists

                btn.disabled = true;
                const originalLabel = btn.textContent;
                btn.textContent = 'Uploading…';
                statusEl.textContent = '';

                fetch(cfg.restUrl + 'submissions/replace-audio?submission_id=' + encodeURIComponent(form.dataset.submissionId ?? ''), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
                    body: fd,
                }).then((res) => res.json().then((body: BHSaveResponseBody) => ({ ok: res.ok, body } as BHRestResult<BHSaveResponseBody>)))
                    .then((r) => {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        if (r.ok) {
                            const msg = r.body.message || 'Uploaded — pending review.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'success'); }
                            else { statusEl.textContent = msg; statusEl.style.color = 'green'; }
                            setTimeout(() => { window.location.reload(); }, 1600);
                        } else {
                            const errMsg = (r.body && r.body.message) || 'Upload failed.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(errMsg, 'error'); }
                            else { statusEl.textContent = errMsg; statusEl.style.color = '#b32d2e'; }
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        const msg = 'Upload failed — check your connection and try again.';
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); }
                        else { statusEl.textContent = msg; statusEl.style.color = '#b32d2e'; }
                    });
            });
        });
    });
})();
