/**
 * Front-end product filter for bhm-product-filter/bhm-product-grid.
 * Vanilla JS with no @wordpress/* deps, since it runs for anonymous visitors.
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
                // Tell the shopper the filter failed rather than leaving stale results.
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

    // Debounced live filtering; the Apply button still works on its own.
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
