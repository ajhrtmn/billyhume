/**
 * bulk-select.js — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * Section 3: bulk actions on the person list (class-people.php). Pure
 * UX convenience on top of a form the browser already submits natively
 * (checkboxes + a submit button's own formaction) — this script never
 * touches submission itself, only the header "select all" checkbox and
 * the live "N selected" count.
 *
 * TypeScript pilot conversion — same posture as bh-crm's own
 * segment-builder.ts.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('bhcrm-bulk-form') as HTMLFormElement | null;
        if (!form) return;

        const selectAll = form.querySelector('#bhcrm-select-all') as HTMLInputElement | null;
        const rowChecks = () => form.querySelectorAll<HTMLInputElement>('.bhcrm-row-select');
        const countEl = form.querySelector('.bhcrm-bulk-count');

        function updateCount() {
            const checked = Array.prototype.filter.call(rowChecks(), (c: HTMLInputElement) => c.checked);
            if (countEl) countEl.textContent = checked.length + ' selected';
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                rowChecks().forEach((c) => { c.checked = selectAll.checked; });
                updateCount();
            });
        }

        form.addEventListener('change', (e) => {
            const target = e.target as HTMLElement;
            if (target.classList.contains('bhcrm-row-select')) updateCount();
        });

        updateCount();
    });
})();
