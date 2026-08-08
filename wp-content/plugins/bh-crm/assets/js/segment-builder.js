/**
 * segment-builder.js — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * Section 3: saved smart lists. Repeatable condition rows (field +
 * value) inside the "+ Build a new list" <details> panel
 * (class-people.php's render_segments_panel()). No build step, plain
 * vanilla JS, same convention as this ecosystem's other admin widgets.
 * bhcrmSegmentFields (wp_localize_script) is BHCRM_Segments::FIELDS —
 * the same closed condition-type list the PHP side validates against,
 * so the picker can never offer something the server would reject.
 *
 * The live "N of M people match" preview (own-ur-shit 3.10+) is now
 * handled declaratively by Datastar attributes on #bhcrm-segment-
 * conditions (data-on:input/data-on:change, class-people.php's
 * render_segments_panel()) — this file only builds/removes condition
 * rows. Datastar's own event listeners pick up newly-inserted rows via
 * ordinary event bubbling, so addRow() needs no Datastar-specific code
 * at all. render_segments_panel() falls back to the OLD plain-fetch
 * preview markup (#bhcrm-segment-preview) on an own-ur-shit core older
 * than 3.10 (no OUS_Hypermedia) — that fallback is handled below by
 * simply checking whether that element exists.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('bhcrm-segment-conditions');
        var addBtn = document.getElementById('bhcrm-add-condition');
        if (!container || !addBtn) return;

        var fields = window.bhcrmSegmentFields || {};
        var rowIndex = 0;
        var previewEl = document.getElementById('bhcrm-segment-preview'); // only present on the pre-Datastar fallback markup

        var previewTimer = null;
        function schedulePreview() {
            if (!previewEl) return; // Datastar markup: nothing to do, its own data-on attributes already fire on the same bubbled events
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
            if (!conditions.length) { previewEl.textContent = ''; return; }

            previewEl.textContent = 'Checking…';
            var body = new URLSearchParams({ action: 'bhcrm_preview_segment', nonce: (window.bhcrmSegmentPreview || {}).nonce || '' });
            conditions.forEach(function (c, i) {
                body.append('conditions[' + i + '][field]', c.field);
                body.append('conditions[' + i + '][value]', c.value);
            });
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { previewEl.textContent = ''; return; }
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
        addRow(); // start with one condition row — an empty builder with zero rows is a dead end
    });
})();
