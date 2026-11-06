/**
 * Course lesson-order drag-reorder (BHC_Admin::render_course_metabox()) using vendored
 * SortableJS, an untyped external global.
 */

interface SortableOptions {
    handle?: string;
    animation?: number;
    ghostClass?: string;
    chosenClass?: string;
    forceFallback?: boolean;
    onEnd?: () => void;
}

interface SortableApi {
    create(el: Element, options: SortableOptions): unknown;
}

declare const Sortable: SortableApi | undefined;

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const list = document.getElementById('bhc-lesson-order-list');
        if (!list || typeof Sortable === 'undefined') return;

        const hidden = document.getElementById('bhc_lesson_order') as HTMLInputElement | null;

        function syncOrder() {
            const ids = Array.prototype.map.call(list!.querySelectorAll('.bhc-order-item'), (li: HTMLElement) => li.dataset.id);
            hidden!.value = ids.join(',');
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
