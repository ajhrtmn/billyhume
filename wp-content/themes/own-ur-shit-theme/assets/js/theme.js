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

// Real gap found live: a submenu (e.g. Contests/Courses, added by
// OUS_MenuSync) near the right edge of the desktop nav bar ran off the
// viewport — every submenu anchors left:0 relative to its own parent
// <li>, with no awareness of how close that parent sits to the right
// edge. Measures each submenu's actual rendered position the moment it
// opens (mouseenter/focusin — the CSS :hover/:focus-within rule has
// already made it visible and measurable by the time either event
// fires) and only flips it right-aligned (.oust-submenu-flip, see
// theme.css) when it would genuinely overflow. Real per-item detection
// rather than a hardcoded "last N items" guess, so this keeps working
// correctly however many items the menu ends up with or in whatever
// order.
(function () {
    var lists = document.querySelectorAll('.oust-primary-nav .oust-nav-list');
    if (!lists.length) return;

    function checkOverflow(li) {
        var submenu = li.querySelector(':scope > ul');
        if (!submenu) return;
        var rect = submenu.getBoundingClientRect();
        li.classList.toggle('oust-submenu-flip', rect.right > window.innerWidth);
    }

    lists.forEach(function (list) {
        list.querySelectorAll(':scope > li').forEach(function (li) {
            if (!li.querySelector(':scope > ul')) return;
            li.addEventListener('mouseenter', function () { checkOverflow(li); });
            li.addEventListener('focusin', function () { checkOverflow(li); });
        });
    });
})();
