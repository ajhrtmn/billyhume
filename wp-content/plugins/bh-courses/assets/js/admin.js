/**
 * admin.js — course lesson-order drag-reorder (class-admin.php's
 * render_course_metabox()). Vanilla JS + vendored SortableJS, no
 * build step, same convention as this ecosystem's other admin
 * widgets (see bh-crm's kanban-board.js).
 *
 * QA rebuild: this used to be native HTML5 drag/drop
 * (dragstart/dragover/drop with manual DOM insertBefore math) — no
 * touch support at all, no visual drop indicator, and it shared this
 * file with ~220 lines of a dead legacy multistep lesson-builder
 * (#bhc-steps-builder) that self-guarded on that container's absence
 * ever since lesson authoring moved to the real Gutenberg block
 * editor (see class-content-bridge.php's own docblock) — harmless but
 * dead weight, deleted here rather than "improved."
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var list = document.getElementById('bhc-lesson-order-list');
        if (!list || typeof Sortable === 'undefined') return;

        var hidden = document.getElementById('bhc_lesson_order');

        function syncOrder() {
            var ids = Array.prototype.map.call(list.querySelectorAll('.bhc-order-item'), function (li) {
                return li.dataset.id;
            });
            hidden.value = ids.join(',');
        }

        Sortable.create(list, {
            handle: '.bhc-order-drag-handle',
            animation: 150,
            ghostClass: 'is-drag-ghost',
            chosenClass: 'is-drag-chosen',
            // Same touch-capable approach as bh-crm's kanban board —
            // forceFallback uses real pointer events instead of the
            // native HTML5 drag API, which has well-documented poor
            // mobile/touch support (exactly what this rebuild is
            // fixing).
            forceFallback: true,
            onEnd: syncOrder,
        });

        syncOrder(); // capture the server-rendered order as the hidden field's initial value, same as before
    });
})();
