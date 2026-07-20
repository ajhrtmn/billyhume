/**
 * bh-monetization-woo front-end — tip jar / PWYW suggested-amount chips
 * (class-frontend.php's render_tip_jar()/render_purchase_button()).
 * Plain vanilla JS, no build step, same convention as this ecosystem's
 * other front-end widgets. Purely a convenience: clicking a chip just
 * sets the paired number input's value — the actual amount that gets
 * charged is still whatever's in that input at submit time, same
 * server-side-clamped path a manually-typed amount already goes through.
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
