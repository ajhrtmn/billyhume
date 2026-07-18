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
                            statusEl.textContent = r.body.message || 'Saved.';
                            statusEl.style.color = 'green';
                            setTimeout(function () { window.location.reload(); }, 900);
                        } else {
                            statusEl.textContent = (r.body && r.body.message) || 'Save failed.';
                            statusEl.style.color = '#b32d2e';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        statusEl.textContent = 'Save failed — check your connection and try again.';
                        statusEl.style.color = '#b32d2e';
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
                            statusEl.textContent = r.body.message || 'Uploaded — pending review.';
                            statusEl.style.color = 'green';
                            setTimeout(function () { window.location.reload(); }, 1200);
                        } else {
                            statusEl.textContent = (r.body && r.body.message) || 'Upload failed.';
                            statusEl.style.color = '#b32d2e';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        statusEl.textContent = 'Upload failed — check your connection and try again.';
                        statusEl.style.color = '#b32d2e';
                    });
            });
        });
    });
})();
