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
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const rows = document.getElementById('bhc-dl-rows');
        const addBtn = document.getElementById('bhc-dl-add');
        if (!rows || !addBtn)
            return;
        const count = document.getElementById('bhc-dl-count');
        const lessons = JSON.parse(rows.dataset.lessons || '[]');
        let next = rows.children.length + 1000; // unique input keys for new rows
        function updateCount() {
            const n = rows.querySelectorAll('.bhc-dl-row').length;
            if (count)
                count.textContent = n ? n + ' file' + (n === 1 ? '' : 's') : 'No files yet.';
        }
        function el(tag, attrs, text) {
            const node = document.createElement(tag);
            Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
            if (text !== undefined)
                node.textContent = text;
            return node;
        }
        function addRow(a) {
            if (rows.querySelector('.bhc-dl-row[data-id="' + a.id + '"]'))
                return; // already listed
            const i = String(next++);
            const li = el('li', { class: 'bhc-dl-row', 'data-id': String(a.id) });
            li.appendChild(el('span', { class: 'bhc-order-drag-handle dashicons dashicons-menu', 'aria-hidden': 'true', title: 'Drag to reorder' }));
            const file = el('span', { class: 'bhc-dl-file' });
            file.appendChild(el('strong', {}, a.filename));
            file.appendChild(document.createTextNode(' '));
            file.appendChild(el('span', { class: 'description' }, ((a.subtype || '').toUpperCase() + ' ' + (a.filesizeHumanReadable || '')).trim()));
            li.appendChild(file);
            li.appendChild(el('input', { type: 'hidden', name: 'bhc_downloads[' + i + '][id]', value: String(a.id) }));
            li.appendChild(el('input', { type: 'text', name: 'bhc_downloads[' + i + '][label]', value: a.title || '', placeholder: 'Name shown to students (optional)', 'aria-label': 'Name shown to students' }));
            const select = el('select', { name: 'bhc_downloads[' + i + '][lesson]', 'aria-label': 'Shows with' });
            lessons.forEach(function (o) { select.appendChild(el('option', { value: String(o.id) }, o.title)); });
            li.appendChild(select);
            li.appendChild(el('button', { type: 'button', class: 'button-link bhc-dl-remove', 'aria-label': 'Remove ' + a.filename }, 'Remove'));
            rows.appendChild(li);
        }
        addBtn.addEventListener('click', function () {
            if (typeof wp === 'undefined' || !wp.media)
                return;
            const frame = wp.media({ title: 'Add course downloads', button: { text: 'Add to Downloads' }, multiple: 'add' });
            frame.on('select', function () {
                frame.state().get('selection').toJSON().forEach(addRow);
                updateCount();
            });
            frame.open();
        });
        rows.addEventListener('click', function (e) {
            const btn = e.target.closest('.bhc-dl-remove');
            if (!btn)
                return;
            btn.closest('.bhc-dl-row').remove();
            updateCount();
        });
        if (typeof Sortable !== 'undefined') {
            Sortable.create(rows, { handle: '.bhc-order-drag-handle', animation: 150, ghostClass: 'is-drag-ghost', chosenClass: 'is-drag-chosen', forceFallback: true });
        }
        updateCount();
    });
})();
