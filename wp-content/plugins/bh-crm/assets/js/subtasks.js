"use strict";
/**
 * subtasks.js — BHCRM_Subtasks drag-between-columns (class-subtasks.php).
 * Mirrors kanban-board.js's own multi-column Sortable setup exactly
 * (one Sortable instance per column list, all sharing a group name so
 * a card can move between columns) — same visual/interaction language
 * as the top-level project board, not a second one. Vanilla JS,
 * vendored SortableJS, no build step, same convention as this
 * ecosystem's other admin widgets.
 *
 * Posts via fetch() to admin-post.php rather than a real <form> submit
 * — this view renders inside the same WP admin context where a nested
 * <form> silently breaks (see bh-contest's reject-form bug this same
 * session for the exact failure mode), so drag-end intentionally never
 * touches form submission at all.
 *
 * TypeScript pilot conversion — same posture as bh-crm's own
 * segment-builder.ts. Sortable (vendored assets/js/vendor/sortable.min.js)
 * and BHCoreToast are untyped external globals.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const board = document.getElementById('bhcrm-subtask-board');
        if (!board || typeof Sortable === 'undefined')
            return;
        const cfg = window.bhcrmSubtasksConfig || {};
        function currentLayout() {
            const layout = [];
            board.querySelectorAll('.bhcrm-kanban-column-cards').forEach((list) => {
                const column = list.dataset.column;
                Array.prototype.forEach.call(list.children, (cardEl) => {
                    layout.push({ uid: cardEl.dataset.nodeUid, column: column });
                });
            });
            return layout;
        }
        function save(attempt = 0) {
            var _a, _b, _c, _d, _e, _f;
            const fd = new FormData();
            fd.append('action', 'bhcrm_subtask_reorder');
            fd.append('nonce', (_a = cfg.nonce) !== null && _a !== void 0 ? _a : '');
            fd.append('project_id', (_b = board.dataset.projectId) !== null && _b !== void 0 ? _b : '');
            fd.append('user_id', (_c = board.dataset.userId) !== null && _c !== void 0 ? _c : '');
            fd.append('card_id', (_d = board.dataset.cardId) !== null && _d !== void 0 ? _d : '');
            fd.append('subtask_path', (_e = board.dataset.subtaskPath) !== null && _e !== void 0 ? _e : '');
            fd.append('layout', JSON.stringify(currentLayout()));
            fetch((_f = cfg.ajaxUrl) !== null && _f !== void 0 ? _f : '', { method: 'POST', credentials: 'same-origin', body: fd })
                .then((res) => res.json())
                .then((body) => {
                // Only reload when a card's completion state actually
                // flipped (server tells us via state_changed) — that's
                // the one case with an effect OUTSIDE this board (every
                // ancestor card's own recursive progress bar elsewhere
                // on the page), so it's the only case that genuinely
                // needs a full re-render. Plain reordering already
                // reflects correctly in the DOM (that's what the drag
                // itself just did) plus refreshCounts() above — no
                // reload needed, matching kanban-board.js's in-place feel.
                const stateChanged = !!(body && body.data && body.data.state_changed);
                if (typeof BHCoreToast !== 'undefined') {
                    BHCoreToast.show('Saved.', 'success');
                }
                if (stateChanged) {
                    setTimeout(() => { window.location.reload(); }, 600);
                }
            })
                .catch(() => {
                // Retry-audit pass, AJ's own standing ask: this
                // used to reload() unconditionally in the catch
                // block too, which on a real network failure
                // silently threw away the drag-drop the user just
                // made — the reload shows the OLD server layout,
                // no error, no sign the write never happened.
                // Reordering a full layout is idempotent (same
                // layout saved twice = same end state), so a real
                // retry is safe here; only give up and reload
                // (accepting the loss, but at least visibly) after
                // retries are exhausted.
                if (attempt < 2) {
                    setTimeout(() => { save(attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                    return;
                }
                if (typeof BHCoreToast !== 'undefined') {
                    BHCoreToast.show('Could not save that change — reloading to the last saved state.', 'error');
                }
                window.location.reload();
            });
        }
        // Also update the visible per-column counts immediately on
        // drop, rather than waiting on a round trip/reload — the
        // server write still happens via save() either way.
        function refreshCounts() {
            board.querySelectorAll('.bhcrm-kanban-column').forEach((col) => {
                const count = col.querySelector('.bhcrm-kanban-column-cards').children.length;
                const counter = col.querySelector('.bhcrm-kanban-column-count');
                if (counter)
                    counter.textContent = '(' + count + ')';
            });
        }
        board.querySelectorAll('.bhcrm-kanban-column-cards').forEach((list) => {
            Sortable.create(list, {
                group: 'bhcrm-subtask-board',
                animation: 150,
                ghostClass: 'is-drag-ghost',
                handle: '.bhcrm-kanban-card-drag-handle',
                forceFallback: true,
                filter: 'input, textarea, button, a, select',
                preventOnFilter: false,
                onEnd: function () {
                    refreshCounts();
                    save();
                },
            });
        });
        // Inline title/description editing — AJ's own ask, "make them
        // editable," matching the top-level board's own live-editable
        // card fields (kanban-board.js) instead of a separate
        // collapsed edit form. Saves on blur, one fetch per field,
        // both routed through the same bhcrm_subtask_save handler.
        function saveField(cardEl, field, value, statusEl, attempt = 0) {
            var _a, _b, _c, _d, _e, _f;
            const fd = new FormData();
            fd.append('action', 'bhcrm_subtask_save');
            fd.append('nonce', (_a = cfg.nonce) !== null && _a !== void 0 ? _a : '');
            fd.append('project_id', (_b = board.dataset.projectId) !== null && _b !== void 0 ? _b : '');
            fd.append('card_id', (_c = board.dataset.cardId) !== null && _c !== void 0 ? _c : '');
            fd.append('subtask_path', (_d = board.dataset.subtaskPath) !== null && _d !== void 0 ? _d : '');
            fd.append('node_uid', (_e = cardEl.dataset.nodeUid) !== null && _e !== void 0 ? _e : '');
            fd.append(field, value);
            if (statusEl)
                statusEl.textContent = 'Saving…';
            fetch((_f = cfg.ajaxUrl) !== null && _f !== void 0 ? _f : '', { method: 'POST', credentials: 'same-origin', body: fd })
                .then((res) => res.json())
                .then((body) => {
                if (!statusEl)
                    return;
                if (body && body.success) {
                    statusEl.textContent = 'Saved';
                    setTimeout(() => { statusEl.textContent = ''; }, 1200);
                }
                else {
                    statusEl.textContent = (body && body.message) || 'Failed to save.';
                }
            })
                .catch(() => {
                // Retry-audit pass, AJ's own standing ask: this
                // single-field overwrite is idempotent (same value
                // saved twice = same end state), so a real network
                // blip is safe to retry rather than immediately
                // reporting failure on the first dropped connection.
                if (attempt < 2) {
                    if (statusEl)
                        statusEl.textContent = 'Retrying…';
                    setTimeout(() => { saveField(cardEl, field, value, statusEl, attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                    return;
                }
                if (statusEl)
                    statusEl.textContent = 'Failed to save.';
            });
        }
        board.querySelectorAll('.bhcrm-kanban-card').forEach((cardEl) => {
            const titleInput = cardEl.querySelector('.bhcrm-subtask-title-input');
            const descInput = cardEl.querySelector('.bhcrm-subtask-desc-input');
            const statusEl = cardEl.querySelector('.bhcrm-subtask-save-status');
            if (titleInput) {
                let lastTitle = titleInput.value;
                titleInput.addEventListener('blur', () => {
                    if (titleInput.value.trim() === '') {
                        titleInput.value = lastTitle;
                        return;
                    }
                    if (titleInput.value === lastTitle)
                        return;
                    lastTitle = titleInput.value;
                    saveField(cardEl, 'title', titleInput.value, statusEl);
                });
                titleInput.addEventListener('keydown', (e) => { if (e.key === 'Enter')
                    titleInput.blur(); });
            }
            if (descInput) {
                let lastDesc = descInput.value;
                descInput.addEventListener('blur', () => {
                    if (descInput.value === lastDesc)
                        return;
                    lastDesc = descInput.value;
                    saveField(cardEl, 'notes', descInput.value, statusEl);
                });
            }
        });
        // Audit fix (2026-07-25): arm/disarm double-click, replacing the
        // native confirm() this delete form used to have — matches
        // kanban-board.js's own pattern exactly (first click arms +
        // relabels, 3s window, second click while armed actually
        // submits; any other interaction on the card disarms it).
        board.querySelectorAll('.bhcrm-subtask-delete-form').forEach((form) => {
            const btn = form.querySelector('.bhcrm-subtask-delete-btn');
            if (!btn)
                return;
            let armed = false;
            let armTimer = null;
            function disarm() {
                armed = false;
                if (armTimer)
                    clearTimeout(armTimer);
                btn.classList.remove('is-armed');
                btn.textContent = 'Delete';
            }
            btn.addEventListener('click', (e) => {
                if (!armed) {
                    e.preventDefault();
                    armed = true;
                    btn.classList.add('is-armed');
                    btn.textContent = 'Really delete?';
                    armTimer = setTimeout(disarm, 3000);
                }
                // else: armed and clicked again — let the form submit normally.
            });
            const card = form.closest('.bhcrm-kanban-card');
            if (card)
                card.addEventListener('pointerdown', (e) => { if (e.target !== btn)
                    disarm(); }, true);
        });
    });
})();
