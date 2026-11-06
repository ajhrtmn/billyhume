"use strict";
/**
 * BHCoreToast: dependency-free toast renderer, loaded globally in admin and
 * front end (handle 'bhcore-toast', class-toast.php).
 *
 *   BHCoreToast.show('Vote recorded.', 'success');
 *   BHCoreToast.show('Could not save — try again.', 'error');
 *   BHCoreToast.show('Heads up: this is a preview.', 'warning');
 *   BHCoreToast.show('3 jobs ran.'); // type defaults to 'info'
 *
 * Optional third argument: auto-dismiss in ms (default 5000; 0 = manual close).
 */
(function (window, document) {
    'use strict';
    var REGION_ID = 'bhcore-toast-region';
    var VALID_TYPES = ['success', 'error', 'info', 'warning'];
    var region = null;
    /**
     * Lazily creates the toast stack: one live region, so screen readers announce
     * each new toast without repeating old ones.
     */
    function ensureRegion() {
        if (region && document.body.contains(region))
            return region;
        var found = document.getElementById(REGION_ID);
        if (!found) {
            found = document.createElement('div');
            found.id = REGION_ID;
            found.className = 'bhcore-toast-region';
            // Polite: announced without interrupting the user.
            found.setAttribute('role', 'status');
            found.setAttribute('aria-live', 'polite');
            found.setAttribute('aria-atomic', 'false');
            (document.body || document.documentElement).appendChild(found);
        }
        region = found;
        return found;
    }
    var BHCoreToast = {
        /**
         * Shows one toast. Unknown types are coerced to 'info'.
         */
        show: function (message, type, durationMs) {
            if (!message)
                return null;
            var resolvedType = (VALID_TYPES.indexOf(type) !== -1
                ? type
                : 'info');
            var resolvedDuration = typeof durationMs === 'number' ? durationMs : 5000;
            var toastRegion = ensureRegion();
            var toast = document.createElement('div');
            toast.className = 'bhcore-toast bhcore-toast-' + resolvedType;
            var msg = document.createElement('span');
            msg.className = 'bhcore-toast-msg';
            msg.textContent = String(message);
            toast.appendChild(msg);
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'bhcore-toast-close';
            closeBtn.setAttribute('aria-label', 'Dismiss notification');
            closeBtn.innerHTML = '&times;';
            toast.appendChild(closeBtn);
            var dismissed = false;
            var timer = null;
            var dismiss = function () {
                if (dismissed)
                    return;
                dismissed = true;
                if (timer)
                    clearTimeout(timer);
                toast.classList.remove('bhcore-toast-in');
                toast.classList.add('bhcore-toast-out');
                // Matches toast.css's transition so the exit animation is visible.
                setTimeout(function () {
                    if (toast.parentNode)
                        toast.parentNode.removeChild(toast);
                }, 220);
            };
            closeBtn.addEventListener('click', dismiss);
            toastRegion.appendChild(toast);
            // Next frame, so the initial state registers and the transition runs.
            window.requestAnimationFrame(function () {
                toast.classList.add('bhcore-toast-in');
            });
            if (resolvedDuration > 0) {
                timer = setTimeout(dismiss, resolvedDuration);
            }
            return toast;
        }
    };
    window.BHCoreToast = BHCoreToast;
    /**
     * Locks page scroll while any ecosystem modal is visible. Modals toggle
     * `style.display` at many call sites, so one MutationObserver watches the
     * known modal roots instead.
     */
    var MODAL_SELECTOR = '.bh-modal, .bhs-auth-modal, .bhs-import-modal, .bhs-jam-modal, .bhs-playlist-picker, .bhr-submit-modal';
    var LOCK_CLASS = 'bhcore-scroll-locked';
    function isVisible(el) {
        if (el.hasAttribute('hidden'))
            return false;
        var display = el.style.display || window.getComputedStyle(el).display;
        return display !== 'none';
    }
    function anyModalOpen() {
        var els = document.querySelectorAll(MODAL_SELECTOR);
        for (var i = 0; i < els.length; i++) {
            var current = els[i];
            if (current && isVisible(current))
                return true;
        }
        return false;
    }
    function syncScrollLock() {
        document.documentElement.classList.toggle(LOCK_CLASS, anyModalOpen());
    }
    function initScrollLock() {
        syncScrollLock();
        var observer = new MutationObserver(syncScrollLock);
        observer.observe(document.body || document.documentElement, {
            attributes: true,
            attributeFilter: ['style', 'hidden'],
            subtree: true
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScrollLock);
    }
    else {
        initScrollLock();
    }
})(window, document);
