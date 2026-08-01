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
