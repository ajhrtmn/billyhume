"use strict";
/**
 * Repeatable segment condition rows (BHCRM_People::render_segments_panel()).
 * bhcrmSegmentFields is BHCRM_Segments::FIELDS, the same list the server validates against.
 * The live match count is Datastar-driven when available; otherwise the #bhcrm-segment-preview fetch fallback below runs.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var containerEl = document.getElementById('bhcrm-segment-conditions');
        var addBtn = document.getElementById('bhcrm-add-condition');
        if (!containerEl || !addBtn)
            return;
        var container = containerEl;
        var win = window;
        var fields = win.bhcrmSegmentFields || {};
        var rowIndex = 0;
        var previewEl = document.getElementById('bhcrm-segment-preview'); // only present on the pre-Datastar fallback markup
        var previewTimer = null;
        function schedulePreview() {
            if (!previewEl)
                return; // Datastar markup handles the preview itself
            if (previewTimer)
                clearTimeout(previewTimer);
            previewTimer = setTimeout(runPreview, 350);
        }
        function runPreview() {
            var rows = container.querySelectorAll('.bhcrm-segment-row');
            var conditions = [];
            rows.forEach(function (row) {
                var select = row.querySelector('select');
                var input = row.querySelector('input');
                if (select && input && (input.value !== '' || select.value === 'has_project')) {
                    conditions.push({ field: select.value, value: input.value });
                }
            });
            if (!conditions.length) {
                previewEl.textContent = '';
                return;
            }
            previewEl.textContent = 'Checking…';
            var body = new URLSearchParams({ action: 'bhcrm_preview_segment', nonce: (win.bhcrmSegmentPreview || {}).nonce || '' });
            conditions.forEach(function (c, i) {
                body.append('conditions[' + i + '][field]', c.field);
                body.append('conditions[' + i + '][value]', c.value);
            });
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                if (!res.success || !res.data) {
                    previewEl.textContent = '';
                    return;
                }
                previewEl.textContent = res.data.count + ' of ' + res.data.total + ' people match';
            })
                .catch(function () { previewEl.textContent = ''; });
        }
        function addRow() {
            var i = rowIndex++;
            var row = document.createElement('div');
            row.className = 'bhcrm-segment-row';
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
            var select = document.createElement('select');
            select.name = 'conditions[' + i + '][field]';
            Object.keys(fields).forEach(function (key) {
                var opt = document.createElement('option');
                opt.value = key;
                opt.textContent = fields[key] || '';
                select.appendChild(opt);
            });
            var valueWrap = document.createElement('span');
            function renderValueInput() {
                valueWrap.innerHTML = '';
                if (select.value === 'has_project') {
                    // has_project needs no value.
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'conditions[' + i + '][value]';
                    hidden.value = '1';
                    valueWrap.appendChild(hidden);
                    var note = document.createElement('span');
                    note.className = 'description';
                    note.textContent = '(no value needed)';
                    valueWrap.appendChild(note);
                    return;
                }
                var input = document.createElement('input');
                input.name = 'conditions[' + i + '][value]';
                if (select.value === 'registered_after' || select.value === 'registered_before') {
                    input.type = 'date';
                }
                else {
                    input.type = 'text';
                    input.placeholder = select.value === 'tag' ? 'tag name' : 'value';
                    input.style.maxWidth = '200px';
                }
                input.addEventListener('input', schedulePreview);
                valueWrap.appendChild(input);
            }
            select.addEventListener('change', function () { renderValueInput(); schedulePreview(); });
            renderValueInput();
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'button-link';
            removeBtn.textContent = 'Remove';
            removeBtn.style.color = '#b32d2e';
            removeBtn.addEventListener('click', function () { row.remove(); schedulePreview(); });
            row.appendChild(select);
            row.appendChild(valueWrap);
            row.appendChild(removeBtn);
            container.appendChild(row);
        }
        addBtn.addEventListener('click', addRow);
        addRow(); // start with one row
    });
})();
