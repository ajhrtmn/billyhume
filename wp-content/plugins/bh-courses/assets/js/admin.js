"use strict";
/**
 * Course lesson-order drag-reorder (BHC_Admin::render_course_metabox()) using vendored
 * SortableJS, an untyped external global.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const list = document.getElementById('bhc-lesson-order-list');
        if (!list || typeof Sortable === 'undefined')
            return;
        const hidden = document.getElementById('bhc_lesson_order');
        function syncOrder() {
            const ids = Array.prototype.map.call(list.querySelectorAll('.bhc-order-item'), (li) => li.dataset.id);
            hidden.value = ids.join(',');
        }
        Sortable.create(list, {
            handle: '.bhc-order-drag-handle',
            animation: 150,
            ghostClass: 'is-drag-ghost',
            chosenClass: 'is-drag-chosen',
            // forceFallback uses pointer events; the native HTML5 drag API is poor on touch.
            forceFallback: true,
            onEnd: syncOrder,
        });
        syncOrder(); // capture the server-rendered order as the hidden field's initial value
    });
})();
