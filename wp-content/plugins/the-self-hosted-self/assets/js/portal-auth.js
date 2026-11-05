"use strict";
/**
 * Account portal auth UI: the logged-out login / create-account card
 * (BHI_Portal::login_html()) and the "confirm your email" resend button.
 * Talks to the bhi/v1 REST routes in class-auth.php. Config comes from
 * window.BHIPortalAuth, printed by BHI_Portal::maybe_enqueue_assets().
 *
 * Compiled by `npm run build:the-self-hosted-self` to assets/js/portal-auth.js.
 */
(function () {
    var config = window.BHIPortalAuth;
    if (!config)
        return;
    var cfg = config;
    var NETWORK_ERROR = 'Couldn\'t reach the site. Check your connection and try again.';
    function post(path, body) {
        var headers = { 'Content-Type': 'application/json' };
        if (cfg.nonce)
            headers['X-WP-Nonce'] = cfg.nonce;
        return fetch(cfg.restBase + path, {
            method: 'POST',
            // Some in-app browsers default to credentials:'omit', which drops the auth Set-Cookie.
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(body),
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data || {} }; }, 
            // A non-JSON reply (a proxy error page, a PHP fatal) still needs a readable message.
            function () { return { ok: false, status: res.status, data: { message: 'Something went wrong on our end (error ' + res.status + '). Please try again.' } }; });
        });
    }
    // Cache-buster: a full-page cache holding the logged-out HTML for this URL can't serve it back after login.
    function goTo(url, extraParam) {
        var target = new URL(url, window.location.href);
        target.searchParams.set('_li', String(Date.now()));
        if (extraParam)
            target.searchParams.set(extraParam, '1');
        window.location.href = target.toString();
    }
    /* ---------- login / create account card ---------- */
    var errorBox = document.getElementById('bhi-login-error');
    var loginForm = document.getElementById('bhi-login-panel-login');
    var registerForm = document.getElementById('bhi-login-panel-register');
    function showError(message) {
        if (!errorBox)
            return;
        errorBox.textContent = message;
        errorBox.style.display = 'block';
    }
    function clearError() {
        if (errorBox)
            errorBox.style.display = 'none';
    }
    function field(id) {
        return document.getElementById(id);
    }
    function setBusy(form, busy) {
        var btn = form.querySelector('.bhi-login-submit');
        if (btn)
            btn.disabled = busy;
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.bhi-login-tab'));
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var _a;
            var target = tab.dataset.panel || 'login';
            tabs.forEach(function (t) {
                var active = t === tab;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (loginForm)
                loginForm.hidden = target !== 'login';
            if (registerForm)
                registerForm.hidden = target !== 'register';
            clearError();
            var first = (_a = (target === 'register' ? registerForm : loginForm)) === null || _a === void 0 ? void 0 : _a.querySelector('input');
            if (first)
                first.focus();
        });
    });
    if (loginForm) {
        var form = loginForm;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearError();
            var username = field('bhi-login-username');
            var password = field('bhi-login-password');
            var codeWrap = document.getElementById('bhi-login-2fa-field');
            var code = field('bhi-login-2fa');
            var remember = field('bhi-login-remember');
            if (!username || !password || !username.value.trim() || !password.value) {
                showError('Enter your username or email and your password.');
                return;
            }
            var body = {
                username: username.value.trim(),
                password: password.value,
                remember: remember ? remember.checked : true,
            };
            if (codeWrap && !codeWrap.hidden && code && code.value.trim())
                body.code = code.value.trim();
            setBusy(form, true);
            post('/login', body).then(function (result) {
                if (result.ok) {
                    goTo(cfg.redirectTo);
                    return;
                }
                setBusy(form, false);
                if (result.data.data && result.data.data.requires_2fa && codeWrap && code) {
                    codeWrap.hidden = false;
                    code.value = '';
                    code.focus();
                }
                showError(result.data.message || 'Login failed. Please try again.');
            }, function () {
                setBusy(form, false);
                showError(NETWORK_ERROR);
            });
        });
    }
    if (registerForm) {
        var regForm = registerForm;
        regForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearError();
            var username = field('bhi-reg-username');
            var email = field('bhi-reg-email');
            var password = field('bhi-reg-password');
            if (!username || !email || !password)
                return;
            if (!username.value.trim() || !email.value.trim() || !password.value) {
                showError('Fill in a username, email, and password.');
                return;
            }
            var body = {
                username: username.value.trim(),
                email: email.value.trim(),
                password: password.value,
            };
            var trap = regForm.querySelector('.bhi-login-trap input');
            if (trap)
                body[trap.name] = trap.value;
            setBusy(regForm, true);
            post('/register', body).then(function (result) {
                if (result.ok) {
                    goTo(cfg.redirectTo, 'bhi_welcome');
                    return;
                }
                setBusy(regForm, false);
                showError(result.data.message || 'Could not create your account. Please try again.');
            }, function () {
                setBusy(regForm, false);
                showError(NETWORK_ERROR);
            });
        });
    }
    /* ---------- "confirm your email" resend ---------- */
    var resend = document.querySelector('.bhi-portal-verify-resend');
    var resendStatus = document.querySelector('.bhi-portal-verify-status');
    if (resend) {
        var resendBtn = resend;
        resendBtn.addEventListener('click', function () {
            resendBtn.disabled = true;
            if (resendStatus)
                resendStatus.textContent = 'Sending…';
            post('/resend-verification', {}).then(function (result) {
                var message = result.ok
                    ? 'Sent. Check your inbox (and spam folder).'
                    : (result.data.message || 'Couldn\'t send the email. Please try again.');
                if (resendStatus)
                    resendStatus.textContent = message;
                // Re-enable after the server-side 2-minute throttle would allow another send.
                window.setTimeout(function () { resendBtn.disabled = false; }, result.ok ? 120000 : 3000);
            }, function () {
                if (resendStatus)
                    resendStatus.textContent = NETWORK_ERROR;
                resendBtn.disabled = false;
            });
        });
    }
})();
