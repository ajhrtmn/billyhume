/**
 * Nested sub-task board (BHCRM_Subtasks): drag between columns, inline edits, two-click delete.
 * Saves go through fetch() to admin-post.php, never a form submit.
 * Sortable and BHCoreToast are untyped globals, declared here and shared with kanban-board.ts.
 */

interface SortableOptions {
    group?: string;
    animation?: number;
    ghostClass?: string;
    handle?: string;
    forceFallback?: boolean;
    filter?: string;
    preventOnFilter?: boolean;
    onEnd?: () => void;
}

interface SortableApi {
    create(el: Element, options: SortableOptions): unknown;
}

declare const Sortable: SortableApi | undefined;
declare const BHCoreToast: { show(message: string, type: string): void } | undefined;

interface BHCrmSubtasksConfig {
    nonce?: string;
    ajaxUrl?: string;
}

interface BHSubtasksWindow extends Window {
    bhcrmSubtasksConfig?: BHCrmSubtasksConfig;
}

interface BHSubtaskLayoutRow {
    uid: string | undefined;
    column: string | undefined;
}

interface BHSubtaskSaveResponse {
    success?: boolean;
    message?: string;
    data?: { state_changed?: boolean };
}

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const board = document.getElementById('bhcrm-subtask-board');
        if (!board || typeof Sortable === 'undefined') return;
        const cfg = (window as BHSubtasksWindow).bhcrmSubtasksConfig || {};

        function currentLayout(): BHSubtaskLayoutRow[] {
            const layout: BHSubtaskLayoutRow[] = [];
            board!.querySelectorAll<HTMLElement>('.bhcrm-kanban-column-cards').forEach((list) => {
                const column = list.dataset.column;
                Array.prototype.forEach.call(list.children, (cardEl: HTMLElement) => {
                    layout.push({ uid: cardEl.dataset.nodeUid, column: column });
                });
            });
            return layout;
        }

        function save(attempt = 0) {
            const fd = new FormData();
            fd.append('action', 'bhcrm_subtask_reorder');
            fd.append('nonce', cfg.nonce ?? '');
            fd.append('project_id', board!.dataset.projectId ?? '');
            fd.append('user_id', board!.dataset.userId ?? '');
            fd.append('card_id', board!.dataset.cardId ?? '');
            fd.append('subtask_path', board!.dataset.subtaskPath ?? '');
            fd.append('layout', JSON.stringify(currentLayout()));

            fetch(cfg.ajaxUrl ?? '', { method: 'POST', credentials: 'same-origin', body: fd })
                .then((res) => res.json() as Promise<BHSubtaskSaveResponse>)
                .then((body) => {
                    // Reload only when a card's done state flipped, since ancestor progress bars change.
                    const stateChanged = !!(body && body.data && body.data.state_changed);
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Saved.', 'success'); }
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
            board!.querySelectorAll('.bhcrm-kanban-column').forEach((col) => {
                const count = col.querySelector('.bhcrm-kanban-column-cards')!.children.length;
                const counter = col.querySelector('.bhcrm-kanban-column-count');
                if (counter) counter.textContent = '(' + count + ')';
            });
        }

        board.querySelectorAll('.bhcrm-kanban-column-cards').forEach((list) => {
            Sortable!.create(list, {
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
        function saveField(cardEl: HTMLElement, field: string, value: string, statusEl: Element | null, attempt = 0) {
            const fd = new FormData();
            fd.append('action', 'bhcrm_subtask_save');
            fd.append('nonce', cfg.nonce ?? '');
            fd.append('project_id', board!.dataset.projectId ?? '');
            fd.append('card_id', board!.dataset.cardId ?? '');
            fd.append('subtask_path', board!.dataset.subtaskPath ?? '');
            fd.append('node_uid', cardEl.dataset.nodeUid ?? '');
            fd.append(field, value);

            if (statusEl) statusEl.textContent = 'Saving…';
            fetch(cfg.ajaxUrl ?? '', { method: 'POST', credentials: 'same-origin', body: fd })
                .then((res) => res.json() as Promise<BHSubtaskSaveResponse>)
                .then((body) => {
                    if (!statusEl) return;
                    if (body && body.success) {
                        statusEl.textContent = 'Saved';
                        setTimeout(() => { statusEl.textContent = ''; }, 1200);
                    } else {
                        statusEl.textContent = (body && body.message) || 'Failed to save.';
                    }
                })
                .catch(() => {
                    // A single-field overwrite is idempotent, so retry.
                    if (attempt < 2) {
                        if (statusEl) statusEl.textContent = 'Retrying…';
                        setTimeout(() => { saveField(cardEl, field, value, statusEl, attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                        return;
                    }
                    if (statusEl) statusEl.textContent = 'Failed to save.';
                });
        }

        board.querySelectorAll<HTMLElement>('.bhcrm-kanban-card').forEach((cardEl) => {
            const titleInput = cardEl.querySelector('.bhcrm-subtask-title-input') as HTMLInputElement | null;
            const descInput = cardEl.querySelector('.bhcrm-subtask-desc-input') as HTMLTextAreaElement | null;
            const statusEl = cardEl.querySelector('.bhcrm-subtask-save-status');

            if (titleInput) {
                let lastTitle = titleInput.value;
                titleInput.addEventListener('blur', () => {
                    if (titleInput.value.trim() === '') { titleInput.value = lastTitle; return; }
                    if (titleInput.value === lastTitle) return;
                    lastTitle = titleInput.value;
                    saveField(cardEl, 'title', titleInput.value, statusEl);
                });
                titleInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') titleInput.blur(); });
            }

            if (descInput) {
                let lastDesc = descInput.value;
                descInput.addEventListener('blur', () => {
                    if (descInput.value === lastDesc) return;
                    lastDesc = descInput.value;
                    saveField(cardEl, 'notes', descInput.value, statusEl);
                });
            }
        });

        // Two-click delete: first click arms for 3s, second submits; other card interaction disarms.
        board.querySelectorAll('.bhcrm-subtask-delete-form').forEach((form) => {
            const btn = form.querySelector('.bhcrm-subtask-delete-btn') as HTMLElement | null;
            if (!btn) return;
            let armed = false;
            let armTimer: ReturnType<typeof setTimeout> | null = null;
            function disarm() {
                armed = false;
                if (armTimer) clearTimeout(armTimer);
                btn!.classList.remove('is-armed');
                btn!.textContent = 'Delete';
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
            if (card) card.addEventListener('pointerdown', (e) => { if (e.target !== btn) disarm(); }, true);
        });
    });
})();
