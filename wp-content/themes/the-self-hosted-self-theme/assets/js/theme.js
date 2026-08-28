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

// Real bug found live hovering "Courses": .oust-primary-nav needs
// overflow-x:auto as a horizontal-scroll safety net for a nav wider
// than its row (see that rule's own docblock in theme.css), and the
// CSS Overflow spec forces overflow-y to auto too the moment
// overflow-x isn't visible on the same box — silently clipping any
// dropdown extending below the row to the row's own ~38-72px height.
// theme.css switched these dropdowns to `position: fixed` (laid out
// against the viewport, never clipped by an ancestor's overflow) to
// fix that; this sets the actual top/left the moment a dropdown opens
// (mouseenter/focusin — by then the CSS :hover/:focus-within rule has
// already made it visible and measurable), positioned directly under
// its own trigger link, and flips right-aligned when it would
// otherwise run off the viewport's right edge. Real per-item
// measurement, not a hardcoded "last N items" guess, so this keeps
// working correctly however many items the menu has or in what order.
(function () {
    var lists = document.querySelectorAll('.oust-primary-nav .oust-nav-list');
    if (!lists.length) return;

    function positionSubmenu(li) {
        var trigger = li.querySelector(':scope > a, :scope > button');
        var submenu = li.querySelector(':scope > ul');
        if (!trigger || !submenu) return;
        var triggerRect = trigger.getBoundingClientRect();
        submenu.style.top = (triggerRect.bottom + 6) + 'px';

        // Measure natural (left-aligned) width before deciding whether
        // it needs to flip — the flipped state changes its own left
        // offset, so this has to run before that assignment, not after.
        submenu.style.left = triggerRect.left + 'px';
        var submenuWidth = submenu.getBoundingClientRect().width;
        // documentElement.clientWidth, not window.innerWidth: the latter
        // includes the scrollbar's own width, which on a page tall
        // enough to scroll made this flip a few pixels later than the
        // content area it's actually measuring against.
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

            // The "More" trigger is a real <button aria-haspopup
            // aria-expanded>, unlike every other item's plain <a> — the
            // hover/focus-within CSS rule that opens it doesn't know
            // anything about that attribute, so it has to be kept in
            // sync here or the button would keep announcing "collapsed"
            // to a screen reader the whole time its own menu is open.
            var trigger = li.querySelector(':scope > button');
            if (!trigger) return;
            li.addEventListener('mouseenter', function () { trigger.setAttribute('aria-expanded', 'true'); });
            li.addEventListener('mouseleave', function () { trigger.setAttribute('aria-expanded', 'false'); });
            li.addEventListener('focusin', function () { trigger.setAttribute('aria-expanded', 'true'); });
            li.addEventListener('focusout', function () { trigger.setAttribute('aria-expanded', 'false'); });
        });
    });
})();

// Priority+ / "more nav" pattern, replacing the horizontal-scroll
// safety net above: real bug, found live — a scrolled-and-clipped item
// (most visibly the "Go to Portal" CTA, the one item that should never
// look broken) sitting right at the row's edge read as a genuinely
// broken layout, not "scroll for more." Nothing is hidden behind a
// scroll gesture now — whatever doesn't fit collapses into the "More"
// dropdown (header.php's items_wrap), which uses the exact same
// position:fixed submenu mechanism as every other top-level dropdown
// above. The account/portal CTA (.ous-menu-account-cta, seeded by
// the-self-hosted-self's OUS_MenuSync — see that class for why it
// carries this exact marker) is deliberately excluded from collapsing
// at all: a site's own primary CTA disappearing into an overflow menu
// is a worse outcome than the plain nav links giving way first.
(function () {
    var nav = document.querySelector('.oust-primary-nav');
    var list = nav ? nav.querySelector(':scope > .oust-nav-list') : null;
    var moreItem = list ? list.querySelector(':scope > .oust-nav-more') : null;
    var moreList = moreItem ? moreItem.querySelector(':scope > ul') : null;
    if (!nav || !list || !moreItem || !moreList) return;

    var cta = list.querySelector(':scope > .ous-menu-account-cta');
    // Real, stable order of the plain nav items (excludes the CTA and
    // the More trigger itself) — items move out of/back into this same
    // list, at this same relative order, as available width changes.
    var items = Array.prototype.slice.call(list.children).filter(function (li) {
        return li !== moreItem && li !== cta;
    });

    function collapse() {
        // Reset to the fully-expanded state first — the only reliable
        // way to know what actually fits now is to measure natural
        // widths with nothing already hidden away, not to guess from
        // whatever last frame's collapsed state happened to be.
        items.forEach(function (li) { list.insertBefore(li, moreItem); });
        moreItem.classList.remove('has-items');

        var available = nav.clientWidth;
        var used = 0;
        items.forEach(function (li) { used += li.offsetWidth + 4; }); // +4: the row's own flex gap
        if (cta) used += cta.offsetWidth + 4;
        if (used <= available) return; // everything fits — nothing to collapse

        // Reserve room for the More trigger itself once it's known to
        // be needed (it wasn't counted above, since showing it is
        // exactly what we're now deciding to do) — AND for the CTA,
        // which the loop below never considers moving, so its space
        // has to come out of the budget up front rather than be found
        // to already be spoken for.
        moreItem.classList.add('has-items');
        available -= moreItem.offsetWidth + 4;
        if (cta) available -= cta.offsetWidth + 4;

        // Fill from the front until the next item would no longer fit,
        // then move everything from there on into the More dropdown —
        // preserves left-to-right priority (earlier items are more
        // important) while still collapsing in one pass, no repeated
        // reflow-and-recheck per item.
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
