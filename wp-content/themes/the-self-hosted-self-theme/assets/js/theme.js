(function () {
    var toggle = document.querySelector('.oust-nav-toggle');
    var mobileNav = document.getElementById('oust-mobile-nav');
    if (!toggle || !mobileNav) return;

    toggle.addEventListener('click', function () {
        var open = document.body.classList.toggle('oust-mobile-nav-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    mobileNav.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') {
            document.body.classList.remove('oust-mobile-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('oust-mobile-nav-open')) {
            document.body.classList.remove('oust-mobile-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
    });
})();

// Positions the position:fixed nav dropdowns (see theme.css: overflow-x on
// the nav would clip absolute ones) under their trigger on open, flipping
// right-aligned when they'd run off the viewport.
(function () {
    var lists = document.querySelectorAll('.oust-primary-nav .oust-nav-list');
    if (!lists.length) return;

    function positionSubmenu(li) {
        var trigger = li.querySelector(':scope > a, :scope > button');
        var submenu = li.querySelector(':scope > ul');
        if (!trigger || !submenu) return;
        var triggerRect = trigger.getBoundingClientRect();
        submenu.style.top = (triggerRect.bottom + 6) + 'px';

        // Measure natural width before the flip changes its left offset.
        submenu.style.left = triggerRect.left + 'px';
        var submenuWidth = submenu.getBoundingClientRect().width;
        // clientWidth, not innerWidth: innerWidth includes the scrollbar.
        var viewportWidth = document.documentElement.clientWidth;
        if (triggerRect.left + submenuWidth > viewportWidth) {
            submenu.style.left = 'auto';
            submenu.style.right = (viewportWidth - triggerRect.right) + 'px';
        } else {
            submenu.style.right = 'auto';
        }
    }

    lists.forEach(function (list) {
        list.querySelectorAll(':scope > li').forEach(function (li) {
            if (!li.querySelector(':scope > ul')) return;
            li.addEventListener('mouseenter', function () { positionSubmenu(li); });
            li.addEventListener('focusin', function () { positionSubmenu(li); });

            // The More <button> needs aria-expanded kept in sync; the CSS
            // hover/focus-within rule that opens it doesn't set it.
            var trigger = li.querySelector(':scope > button');
            if (!trigger) return;
            li.addEventListener('mouseenter', function () { trigger.setAttribute('aria-expanded', 'true'); });
            li.addEventListener('mouseleave', function () { trigger.setAttribute('aria-expanded', 'false'); });
            li.addEventListener('focusin', function () { trigger.setAttribute('aria-expanded', 'true'); });
            li.addEventListener('focusout', function () { trigger.setAttribute('aria-expanded', 'false'); });
        });
    });
})();

// Priority+ nav: items that don't fit collapse into the "More" dropdown
// (header.php). The account CTA (.ous-menu-account-cta, from OUS_MenuSync)
// never collapses.
(function () {
    var nav = document.querySelector('.oust-primary-nav');
    var list = nav ? nav.querySelector(':scope > .oust-nav-list') : null;
    var moreItem = list ? list.querySelector(':scope > .oust-nav-more') : null;
    var moreList = moreItem ? moreItem.querySelector(':scope > ul') : null;
    if (!nav || !list || !moreItem || !moreList) return;

    var cta = list.querySelector(':scope > .ous-menu-account-cta');
    // Stable order of plain nav items (excludes the CTA and More trigger).
    var items = Array.prototype.slice.call(list.children).filter(function (li) {
        return li !== moreItem && li !== cta;
    });

    function collapse() {
        // Reset to fully expanded first so natural widths can be measured.
        items.forEach(function (li) { list.insertBefore(li, moreItem); });
        moreItem.classList.remove('has-items');

        var available = nav.clientWidth;
        var used = 0;
        items.forEach(function (li) { used += li.offsetWidth + 4; }); // +4: the row's own flex gap
        if (cta) used += cta.offsetWidth + 4;
        if (used <= available) return; // everything fits — nothing to collapse

        // Reserve room for the More trigger and the CTA up front.
        moreItem.classList.add('has-items');
        available -= moreItem.offsetWidth + 4;
        if (cta) available -= cta.offsetWidth + 4;

        // Fill from the front; everything after the first misfit moves to More.
        used = 0;
        var cutoff = items.length;
        for (var i = 0; i < items.length; i++) {
            used += items[i].offsetWidth + 4;
            if (used > available) { cutoff = i; break; }
        }
        for (var j = items.length - 1; j >= cutoff; j--) {
            moreList.insertBefore(items[j], moreList.firstChild);
        }
    }

    collapse();
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(collapse, 100);
    });
})();
