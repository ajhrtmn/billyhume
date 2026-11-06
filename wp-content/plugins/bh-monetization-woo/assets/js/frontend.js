/**
 * Tip jar / PWYW amount chips: a chip only fills the number input; the server clamps the amount.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bhm-amount-chips').forEach(function (group) {
            var input = group.parentElement ? group.parentElement.querySelector('.bhm-amount-input') : null;
            if (!input) return;

            function markActive() {
                group.querySelectorAll('.bhm-amount-chip').forEach(function (chip) {
                    chip.classList.toggle('is-active', chip.dataset.amount === input.value);
                });
            }

            group.querySelectorAll('.bhm-amount-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    input.value = chip.dataset.amount;
                    markActive();
                });
            });
            input.addEventListener('input', markActive);
            markActive();
        });
    });
})();
