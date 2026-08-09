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
 *
 * TypeScript pilot conversion — bh-courses' first (this plugin had no
 * assets/ts/ before this pass; new tsconfig.json/build:bh-courses
 * script added alongside). Sortable (vendored assets/js/vendor/
 * sortable.min.js) is an untyped external global.
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
