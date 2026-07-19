/**
 * Front-end browse/filter interactivity for bhm-product-filter +
 * bhm-product-grid (see class-storefront.php's render functions for the
 * markup this wires up). Plain vanilla JS, no framework — this runs on
 * the public storefront for anonymous visitors, so no @wordpress/*
 * dependency (those are enqueued for logged-in admin/editor contexts
 * only) and no build step, same discipline as every other front-end
 * script in this ecosystem (see OUS_Notifications' own inline-script
 * convention).
 */
(function () {
    'use strict';
    if (!window.bhmStorefrontConfig) return;

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    function applyFilter(filterForm) {
        var grid = filterForm.closest('.bhm-storefront-wrap, body').querySelector('.bhm-product-grid')
            || document.querySelector('.bhm-product-grid');
        if (!grid) return;

        var params = new URLSearchParams();
        var collection = grid.dataset.bhmCollection;
        var category = filterForm.querySelector('.bhm-filter-category');
        var minPrice = filterForm.querySelector('.bhm-filter-min-price');
        var maxPrice = filterForm.querySelector('.bhm-filter-max-price');
        var inStock = filterForm.querySelector('.bhm-filter-in-stock');

        if (collection) params.set('collection', collection);
        if (category && category.value) params.set('category', category.value);
        else if (grid.dataset.bhmCategory) params.set('category', grid.dataset.bhmCategory);
        if (minPrice && minPrice.value) params.set('min_price', minPrice.value);
        if (maxPrice && maxPrice.value) params.set('max_price', maxPrice.value);
        if (inStock && inStock.checked) params.set('in_stock', '1');

        grid.setAttribute('aria-busy', 'true');
        fetch(window.bhmStorefrontConfig.restUrl + '?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                grid.innerHTML = data.html || '';
                grid.removeAttribute('aria-busy');
            })
            .catch(function () {
                // Previously left the stale grid up with no sign to the
                // shopper that their filter selection failed to apply.
                grid.removeAttribute('aria-busy');
                var msg = 'Could not update results — check your connection and try again.';
                if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); }
                else { grid.insertAdjacentHTML('afterbegin', '<p class="bhm-filter-error" style="color:#b32d2e;">' + msg + '</p>'); }
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.bhm-filter-apply');
        if (!btn) return;
        var form = btn.closest('.bhm-product-filter');
        if (form) applyFilter(form);
    });

    // Live-filter on input too (debounced), not just the explicit Apply
    // click — the Apply button stays as a clear, always-available action
    // for anyone on a slower connection or who prefers not to trigger a
    // request on every keystroke.
    var debouncedApply = debounce(function (form) { applyFilter(form); }, 400);
    document.addEventListener('input', function (e) {
        var form = e.target.closest('.bhm-product-filter');
        if (form) debouncedApply(form);
    });
    document.addEventListener('change', function (e) {
        if (e.target.matches('.bhm-filter-category, .bhm-filter-in-stock')) {
            var form = e.target.closest('.bhm-product-filter');
            if (form) applyFilter(form);
        }
    });
})();
