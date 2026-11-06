"use strict";
/**
 * Nested sub-task board (BHCRM_Subtasks): drag between columns, inline edits, two-click delete.
 * Saves go through fetch() to admin-post.php, never a form submit.
 * Sortable and BHCoreToast are untyped globals, declared here and shared with kanban-board.ts.
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
                // Reload only when a card's done state flipped, since ancestor progress bars change.
                const stateChanged = !!(body && body.data && body.data.state_changed);
                if (typeof BHCoreToast !== 'undefined') {
                    BHCoreToast.show('Saved.', 'success');
                }
                if (stateChanged) {
                    setTimeout(() => { window.location.reload(); }, 600);
                }
            })
                .catch(() => {
                // Saving a full layout is idempotent, so retry; reload visibly only after retries fail.
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
        // Updates per-column counts immediately on drop.
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
        // Inline title/notes edits, saved on blur via bhcrm_subtask_save.
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
                // A single-field overwrite is idempotent, so retry.
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
        // Two-click delete: first click arms for 3s, second submits; other card interaction disarms.
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
