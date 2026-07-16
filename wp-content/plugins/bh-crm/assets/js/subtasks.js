/**
 * subtasks.js — BHCRM_Subtasks sibling drag-reorder (class-subtasks.php).
 * Vanilla JS + vendored SortableJS, no build step, same convention as
 * this ecosystem's other admin widgets (see kanban-board.js). Posts via
 * fetch() to admin-post.php rather than a real <form> submit — this
 * view renders inside the same WP admin context where a nested <form>
 * silently breaks (see bh-contest's reject-form bug this same session
 * for the exact failure mode), so drag-end intentionally never touches
 * form submission at all.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var list = document.getElementById('bhcrm-subtask-list');
        if (!list || typeof Sortable === 'undefined') return;
        var cfg = window.bhcrmSubtasksConfig || {};

        Sortable.create(list, {
            handle: '.bhcrm-subtask-drag-handle',
            animation: 150,
            onEnd: function () {
                var order = Array.from(list.querySelectorAll('.bhcrm-subtask-row')).map(function (row) {
                    return row.dataset.nodeUid;
                });

                var fd = new FormData();
                fd.append('action', 'bhcrm_subtask_reorder');
                fd.append('nonce', cfg.nonce);
                fd.append('project_id', list.dataset.projectId);
                fd.append('user_id', list.dataset.userId);
                fd.append('card_id', list.dataset.cardId);
                fd.append('subtask_path', list.dataset.subtaskPath);
                fd.append('order', order.join(','));

                fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (body) {
                        if (!body || !body.success) {
                            // Reload to resync with the server's own order rather than leaving the UI showing a drag that didn't actually save.
                            window.location.reload();
                        }
                    })
                    .catch(function () { window.location.reload(); });
            },
        });
    });
})();
