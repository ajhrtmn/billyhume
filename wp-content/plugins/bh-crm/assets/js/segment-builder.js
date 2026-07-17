/**
 * segment-builder.js — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * Section 3: saved smart lists. Repeatable condition rows (field +
 * value) inside the "+ Build a new list" <details> panel
 * (class-people.php's render_segments_panel()). No build step, plain
 * vanilla JS, same convention as this ecosystem's other admin widgets.
 * bhcrmSegmentFields (wp_localize_script) is BHCRM_Segments::FIELDS —
 * the same closed condition-type list the PHP side validates against,
 * so the picker can never offer something the server would reject.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('bhcrm-segment-conditions');
        var addBtn = document.getElementById('bhcrm-add-condition');
        if (!container || !addBtn) return;

        var fields = window.bhcrmSegmentFields || {};
        var rowIndex = 0;

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
                opt.textContent = fields[key];
                select.appendChild(opt);
            });

            var valueWrap = document.createElement('span');

            function renderValueInput() {
                valueWrap.innerHTML = '';
                if (select.value === 'has_project') {
                    // No value needed — "has a project" is true/false by
                    // its own existence as a condition row.
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
                } else {
                    input.type = 'text';
                    input.placeholder = select.value === 'tag' ? 'tag name' : 'value';
                    input.style.maxWidth = '200px';
                }
                valueWrap.appendChild(input);
            }

            select.addEventListener('change', renderValueInput);
            renderValueInput();

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'button-link';
            removeBtn.textContent = 'Remove';
            removeBtn.style.color = '#b32d2e';
            removeBtn.addEventListener('click', function () { row.remove(); });

            row.appendChild(select);
            row.appendChild(valueWrap);
            row.appendChild(removeBtn);
            container.appendChild(row);
        }

        addBtn.addEventListener('click', addRow);
        addRow(); // start with one condition row — an empty builder with zero rows is a dead end
    });
})();
