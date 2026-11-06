/** Person-list bulk actions: header select-all and live "N selected" count; the form submits natively. */
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
