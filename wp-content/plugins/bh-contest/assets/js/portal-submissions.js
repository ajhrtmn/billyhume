"use strict";
/**
 * portal-submissions.js — BH_PortalPanel's "Replace file" and "Edit
 * details" forms (class-portal-panel.php). Vanilla JS, no build step,
 * same convention as this ecosystem's other front-end widgets.
 * bhContestPortalConfig (wp_localize_script) carries restUrl + nonce.
 *
 * TypeScript pilot conversion — same posture as this plugin's other
 * converted files.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const cfg = window.bhContestPortalConfig || {};
        document.querySelectorAll('.bh-edit-details-form').forEach((formEl) => {
            const form = formEl;
            form.addEventListener('submit', (e) => {
                var _a, _b;
                e.preventDefault();
                const titleInput = form.querySelector('.bh-edit-title');
                const artistInput = form.querySelector('.bh-edit-artist');
                const statusEl = form.querySelector('.bh-edit-status');
                const btn = form.querySelector('button[type=submit]');
                const fd = new FormData();
                fd.append('title', titleInput.value.trim());
                fd.append('artist', artistInput.value.trim());
                btn.disabled = true;
                const originalLabel = btn.textContent;
                btn.textContent = 'Saving…';
                statusEl.textContent = '';
                fetch(cfg.restUrl + 'submissions/edit-details?submission_id=' + encodeURIComponent((_a = form.dataset.submissionId) !== null && _a !== void 0 ? _a : ''), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': (_b = cfg.nonce) !== null && _b !== void 0 ? _b : '' },
                    body: fd,
                }).then((res) => res.json().then((body) => ({ ok: res.ok, body })))
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
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(msg, 'success');
                        }
                        else {
                            statusEl.textContent = msg;
                            statusEl.style.color = 'green';
                        }
                        setTimeout(() => { window.location.reload(); }, 1400);
                    }
                    else {
                        const errMsg = (r.body && r.body.message) || 'Save failed.';
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(errMsg, 'error');
                        }
                        else {
                            statusEl.textContent = errMsg;
                            statusEl.style.color = '#b32d2e';
                        }
                    }
                })
                    .catch(() => {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    const msg = 'Save failed — check your connection and try again.';
                    if (typeof BHCoreToast !== 'undefined') {
                        BHCoreToast.show(msg, 'error');
                    }
                    else {
                        statusEl.textContent = msg;
                        statusEl.style.color = '#b32d2e';
                    }
                });
            });
        });
        document.querySelectorAll('.bh-replace-audio-form').forEach((formEl) => {
            const form = formEl;
            form.addEventListener('submit', (e) => {
                var _a, _b;
                e.preventDefault();
                const fileInput = form.querySelector('input[type=file]');
                const statusEl = form.querySelector('.bh-replace-status');
                const btn = form.querySelector('button[type=submit]');
                if (!fileInput.files || !fileInput.files.length) {
                    statusEl.textContent = 'Choose a file first.';
                    return;
                }
                const fd = new FormData();
                fd.append('audio', fileInput.files[0]); // .length check above guarantees index 0 exists
                btn.disabled = true;
                const originalLabel = btn.textContent;
                btn.textContent = 'Uploading…';
                statusEl.textContent = '';
                fetch(cfg.restUrl + 'submissions/replace-audio?submission_id=' + encodeURIComponent((_a = form.dataset.submissionId) !== null && _a !== void 0 ? _a : ''), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': (_b = cfg.nonce) !== null && _b !== void 0 ? _b : '' },
                    body: fd,
                }).then((res) => res.json().then((body) => ({ ok: res.ok, body })))
                    .then((r) => {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    if (r.ok) {
                        const msg = r.body.message || 'Uploaded — pending review.';
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(msg, 'success');
                        }
                        else {
                            statusEl.textContent = msg;
                            statusEl.style.color = 'green';
                        }
                        setTimeout(() => { window.location.reload(); }, 1600);
                    }
                    else {
                        const errMsg = (r.body && r.body.message) || 'Upload failed.';
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(errMsg, 'error');
                        }
                        else {
                            statusEl.textContent = errMsg;
                            statusEl.style.color = '#b32d2e';
                        }
                    }
                })
                    .catch(() => {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    const msg = 'Upload failed — check your connection and try again.';
                    if (typeof BHCoreToast !== 'undefined') {
                        BHCoreToast.show(msg, 'error');
                    }
                    else {
                        statusEl.textContent = msg;
                        statusEl.style.color = '#b32d2e';
                    }
                });
            });
        });
    });
})();
