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
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var board = document.getElementById('bhcrm-subtask-board');
        if (!board || typeof Sortable === 'undefined') return;
        var cfg = window.bhcrmSubtasksConfig || {};

        function currentLayout() {
            var layout = [];
            board.querySelectorAll('.bhcrm-kanban-column-cards').forEach(function (list) {
                var column = list.dataset.column;
                Array.prototype.forEach.call(list.children, function (cardEl) {
                    layout.push({ uid: cardEl.dataset.nodeUid, column: column });
                });
            });
            return layout;
        }

        function save() {
            var fd = new FormData();
            fd.append('action', 'bhcrm_subtask_reorder');
            fd.append('nonce', cfg.nonce);
            fd.append('project_id', board.dataset.projectId);
            fd.append('user_id', board.dataset.userId);
            fd.append('card_id', board.dataset.cardId);
            fd.append('subtask_path', board.dataset.subtaskPath);
            fd.append('layout', JSON.stringify(currentLayout()));

            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (body) {
                    if (!body || !body.success) window.location.reload();
                })
                .catch(function () { window.location.reload(); });
        }

        // Also update the visible per-column counts immediately on
        // drop, rather than waiting on a round trip/reload — the
        // server write still happens via save() either way.
        function refreshCounts() {
            board.querySelectorAll('.bhcrm-kanban-column').forEach(function (col) {
                var count = col.querySelector('.bhcrm-kanban-column-cards').children.length;
                var counter = col.querySelector('.bhcrm-kanban-column-count');
                if (counter) counter.textContent = '(' + count + ')';
            });
        }

        board.querySelectorAll('.bhcrm-kanban-column-cards').forEach(function (list) {
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
    });
})();
