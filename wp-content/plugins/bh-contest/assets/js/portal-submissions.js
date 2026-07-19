/**
 * portal-submissions.js — BH_PortalPanel's "Replace file" and "Edit
 * details" forms (class-portal-panel.php). Vanilla JS, no build step,
 * same convention as this ecosystem's other front-end widgets.
 * bhContestPortalConfig (wp_localize_script) carries restUrl + nonce.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.bhContestPortalConfig || {};

        document.querySelectorAll('.bh-edit-details-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var titleInput = form.querySelector('.bh-edit-title');
                var artistInput = form.querySelector('.bh-edit-artist');
                var statusEl = form.querySelector('.bh-edit-status');
                var btn = form.querySelector('button[type=submit]');

                var fd = new FormData();
                fd.append('title', titleInput.value.trim());
                fd.append('artist', artistInput.value.trim());

                btn.disabled = true;
                var originalLabel = btn.textContent;
                btn.textContent = 'Saving…';
                statusEl.textContent = '';

                fetch(cfg.restUrl + 'submissions/edit-details?submission_id=' + encodeURIComponent(form.dataset.submissionId), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce },
                    body: fd,
                }).then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
                    .then(function (r) {
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
                            var msg = r.body.message || 'Saved.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'success'); }
                            else { statusEl.textContent = msg; statusEl.style.color = 'green'; }
                            setTimeout(function () { window.location.reload(); }, 1400);
                        } else {
                            var errMsg = (r.body && r.body.message) || 'Save failed.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(errMsg, 'error'); }
                            else { statusEl.textContent = errMsg; statusEl.style.color = '#b32d2e'; }
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        var msg = 'Save failed — check your connection and try again.';
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); }
                        else { statusEl.textContent = msg; statusEl.style.color = '#b32d2e'; }
                    });
            });
        });

        document.querySelectorAll('.bh-replace-audio-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fileInput = form.querySelector('input[type=file]');
                var statusEl = form.querySelector('.bh-replace-status');
                var btn = form.querySelector('button[type=submit]');
                if (!fileInput.files.length) {
                    statusEl.textContent = 'Choose a file first.';
                    return;
                }

                var fd = new FormData();
                fd.append('audio', fileInput.files[0]);

                btn.disabled = true;
                var originalLabel = btn.textContent;
                btn.textContent = 'Uploading…';
                statusEl.textContent = '';

                fetch(cfg.restUrl + 'submissions/replace-audio?submission_id=' + encodeURIComponent(form.dataset.submissionId), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce },
                    body: fd,
                }).then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
                    .then(function (r) {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        if (r.ok) {
                            var msg = r.body.message || 'Uploaded — pending review.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'success'); }
                            else { statusEl.textContent = msg; statusEl.style.color = 'green'; }
                            setTimeout(function () { window.location.reload(); }, 1600);
                        } else {
                            var errMsg = (r.body && r.body.message) || 'Upload failed.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(errMsg, 'error'); }
                            else { statusEl.textContent = errMsg; statusEl.style.color = '#b32d2e'; }
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        var msg = 'Upload failed — check your connection and try again.';
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); }
                        else { statusEl.textContent = msg; statusEl.style.color = '#b32d2e'; }
                    });
            });
        });
    });
})();
