/**
 * BHCoreToast — a minimal, dependency-free toast/notification renderer,
 * shared by the whole ecosystem (own-ur-shit core + every bh-* plugin) on
 * both wp-admin and the front end. No build step, no external libs, same
 * "plain vanilla JS enqueued via wp_enqueue_script" convention every other
 * asset in this ecosystem already follows (see BHY_UI's shared admin JS,
 * studio.js).
 *
 * Usage from ANY plugin's own JS, once this script (handle: 'bhcore-toast')
 * is enqueued/loaded on the page (own-ur-shit loads it globally — see
 * class-toast.php):
 *
 *   BHCoreToast.show('Vote recorded.', 'success');
 *   BHCoreToast.show('Could not save — try again.', 'error');
 *   BHCoreToast.show('Heads up: this is a preview.', 'warning');
 *   BHCoreToast.show('3 jobs ran.'); // type defaults to 'info'
 *
 * Optional third argument: auto-dismiss duration in ms (default 5000; pass
 * 0 to require the user to dismiss it manually via the close button).
 *
 * Not yet tested against a live browser/DOM in this pass — written and
 * reviewed for correctness, but there is no live JS execution available
 * in this environment. Please click a wired action (e.g. bh-crm "Save
 * notes") and confirm a toast actually appears before treating this as
 * proven.
 */
(function (window, document) {
    'use strict';

    var REGION_ID = 'bhcore-toast-region';
    var VALID_TYPES = ['success', 'error', 'info', 'warning'];

    var BHCoreToast = {
        /**
         * Lazily creates (once) the fixed-position, top-right toast stack —
         * a single live region so a screen reader announces each new toast
         * without re-announcing ones already on screen.
         */
        _region: null,
        _ensureRegion: function () {
            if (this._region && document.body.contains(this._region)) return this._region;
            var region = document.getElementById(REGION_ID);
            if (!region) {
                region = document.createElement('div');
                region.id = REGION_ID;
                region.className = 'bhcore-toast-region';
                // role="status" + aria-live="polite": announced without
                // interrupting whatever the user is currently doing —
                // appropriate for confirmations/errors that supplement,
                // rather than replace, existing WP admin notices.
                region.setAttribute('role', 'status');
                region.setAttribute('aria-live', 'polite');
                region.setAttribute('aria-atomic', 'false');
                (document.body || document.documentElement).appendChild(region);
            }
            this._region = region;
            return region;
        },

        /**
         * Shows one toast. $type is coerced to 'info' if not one of the
         * four recognized values, so a typo'd/unexpected type never throws
         * or silently fails to render — same "harmless degrade" posture as
         * the rest of this ecosystem's shared utilities.
         */
        show: function (message, type, durationMs) {
            if (!message) return null;
            type = VALID_TYPES.indexOf(type) !== -1 ? type : 'info';
            durationMs = typeof durationMs === 'number' ? durationMs : 5000;

            var region = this._ensureRegion();

            var toast = document.createElement('div');
            toast.className = 'bhcore-toast bhcore-toast-' + type;

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
                if (dismissed) return;
                dismissed = true;
                if (timer) clearTimeout(timer);
                toast.classList.remove('bhcore-toast-in');
                toast.classList.add('bhcore-toast-out');
                // Matches the CSS transition duration in toast.css — the
                // node is removed only after the fade/slide-out actually
                // finishes, not instantly, so the animation is visible.
                setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 220);
            };
            closeBtn.addEventListener('click', dismiss);

            region.appendChild(toast);

            // Applied on the next frame (not immediately) so the browser
            // registers the initial (offscreen/transparent) state first —
            // otherwise the CSS transition to bhcore-toast-in has nothing
            // to transition FROM and the toast just appears instantly.
            window.requestAnimationFrame(function () {
                toast.classList.add('bhcore-toast-in');
            });

            if (durationMs > 0) {
                timer = setTimeout(dismiss, durationMs);
            }

            return toast;
        }
    };

    window.BHCoreToast = BHCoreToast;

    /**
     * Body scroll-lock while any ecosystem modal is open. Every real modal
     * across bh-contest/bh-streaming/bh-registry is shown/hidden by toggling
     * `style.display` on its own root element (never removed from the DOM),
     * at many different call sites — there's no single shared open()/close()
     * function to hook. Rather than edit every call site in three plugins,
     * this watches the known modal-root selectors with one MutationObserver
     * and locks/unlocks <html> scrolling whenever any of them becomes
     * visible/hidden. The modal's own inner container keeps its
     * `overflow-y: auto`, so this only stops the page BEHIND it from
     * scrolling — the modal itself still scrolls normally.
     */
    var MODAL_SELECTOR = '.bh-modal, .bhs-auth-modal, .bhs-import-modal, .bhs-jam-modal, .bhs-playlist-picker, .bhr-submit-modal';
    var LOCK_CLASS = 'bhcore-scroll-locked';

    function isVisible(el) {
        if (el.hasAttribute('hidden')) return false;
        var display = el.style.display || window.getComputedStyle(el).display;
        return display !== 'none';
    }

    function anyModalOpen() {
        var els = document.querySelectorAll(MODAL_SELECTOR);
        for (var i = 0; i < els.length; i++) {
            if (isVisible(els[i])) return true;
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
    } else {
        initScrollLock();
    }
})(window, document);
