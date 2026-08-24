/**
 * segment-builder.ts — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * Section 3: saved smart lists. Repeatable condition rows (field +
 * value) inside the "+ Build a new list" <details> panel
 * (class-people.php's render_segments_panel()). No build step at
 * runtime — TypeScript pilot (bh-crm's first), same posture as
 * the-self-hosted-self/assets/ts/*.ts: plain `tsc`, compiled to assets/js/
 * segment-builder.js, which is what's actually enqueued via
 * wp_enqueue_script(). Run `npm run build:bh-crm` after editing.
 * bhcrmSegmentFields (wp_localize_script) is BHCRM_Segments::FIELDS —
 * the same closed condition-type list the PHP side validates against,
 * so the picker can never offer something the server would reject.
 *
 * The live "N of M people match" preview (the-self-hosted-self 3.10+) is now
 * handled declaratively by Datastar attributes on #bhcrm-segment-
 * conditions (data-on:input/data-on:change, class-people.php's
 * render_segments_panel()) — this file only builds/removes condition
 * rows. Datastar's own event listeners pick up newly-inserted rows via
 * ordinary event bubbling, so addRow() needs no Datastar-specific code
 * at all. render_segments_panel() falls back to the OLD plain-fetch
 * preview markup (#bhcrm-segment-preview) on an the-self-hosted-self core older
 * than 3.10 (no OUS_Hypermedia) — that fallback is handled below by
 * simply checking whether that element exists.
 */

declare var ajaxurl: string;

interface BHCRMSegmentWindow extends Window {
    bhcrmSegmentFields?: Record<string, string>;
    bhcrmSegmentPreview?: { nonce?: string };
}

interface BHCRMSegmentPreviewResponse {
    success: boolean;
    data?: { count: number; total: number };
}

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var containerEl = document.getElementById('bhcrm-segment-conditions');
        var addBtn = document.getElementById('bhcrm-add-condition');
        if (!containerEl || !addBtn) return;
        var container: HTMLElement = containerEl;

        var win = window as BHCRMSegmentWindow;
        var fields = win.bhcrmSegmentFields || {};
        var rowIndex = 0;
        var previewEl = document.getElementById('bhcrm-segment-preview'); // only present on the pre-Datastar fallback markup

        var previewTimer: ReturnType<typeof setTimeout> | null = null;
        function schedulePreview(): void {
            if (!previewEl) return; // Datastar markup: nothing to do, its own data-on attributes already fire on the same bubbled events
            if (previewTimer) clearTimeout(previewTimer);
            previewTimer = setTimeout(runPreview, 350);
        }
        function runPreview(): void {
            var rows = container.querySelectorAll<HTMLElement>('.bhcrm-segment-row');
            var conditions: { field: string; value: string }[] = [];
            rows.forEach(function (row) {
                var select = row.querySelector<HTMLSelectElement>('select');
                var input = row.querySelector<HTMLInputElement>('input');
                if (select && input && (input.value !== '' || select.value === 'has_project')) {
                    conditions.push({ field: select.value, value: input.value });
                }
            });
            if (!conditions.length) { (previewEl as HTMLElement).textContent = ''; return; }

            (previewEl as HTMLElement).textContent = 'Checking…';
            var body = new URLSearchParams({ action: 'bhcrm_preview_segment', nonce: (win.bhcrmSegmentPreview || {}).nonce || '' });
            conditions.forEach(function (c, i) {
                body.append('conditions[' + i + '][field]', c.field);
                body.append('conditions[' + i + '][value]', c.value);
            });
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res: BHCRMSegmentPreviewResponse) {
                    if (!res.success || !res.data) { (previewEl as HTMLElement).textContent = ''; return; }
                    (previewEl as HTMLElement).textContent = res.data.count + ' of ' + res.data.total + ' people match';
                })
                .catch(function () { (previewEl as HTMLElement).textContent = ''; });
        }

        function addRow(): void {
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

            function renderValueInput(): void {
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
