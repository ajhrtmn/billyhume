/**
 * bulk-select.js — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * Section 3: bulk actions on the person list (class-people.php). Pure
 * UX convenience on top of a form the browser already submits natively
 * (checkboxes + a submit button's own formaction) — this script never
 * touches submission itself, only the header "select all" checkbox and
 * the live "N selected" count.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('bhcrm-bulk-form');
        if (!form) return;

        var selectAll = form.querySelector('#bhcrm-select-all');
        var rowChecks = function () { return form.querySelectorAll('.bhcrm-row-select'); };
        var countEl = form.querySelector('.bhcrm-bulk-count');

        function updateCount() {
            var checked = Array.prototype.filter.call(rowChecks(), function (c) { return c.checked; });
            if (countEl) countEl.textContent = checked.length + ' selected';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks().forEach(function (c) { c.checked = selectAll.checked; });
                updateCount();
            });
        }

        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('bhcrm-row-select')) updateCount();
        });

        updateCount();
    });
})();
