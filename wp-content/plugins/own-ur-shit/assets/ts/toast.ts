/**
 * BHCoreToast — a minimal, dependency-free toast/notification renderer,
 * shared by the whole ecosystem (own-ur-shit core + every bh-* plugin) on
 * both wp-admin and the front end. No build step at runtime — TypeScript
 * pilot, compiled with plain `tsc` (no bundler) to assets/js/toast.js,
 * which is what class-toast.php actually enqueues via wp_enqueue_script().
 * Run `npm run build:own-ur-shit` after editing this file; the compiled
 * output is committed, since the live FTP-deployed site runs no build
 * step at all.
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

type BHCoreToastType = 'success' | 'error' | 'info' | 'warning';

interface BHCoreToastApi {
    show(message: unknown, type?: string, durationMs?: number): HTMLElement | null;
}

interface BHCoreToastWindow extends Window {
    BHCoreToast: BHCoreToastApi;
}

(function (window: Window, document: Document) {
    'use strict';

    var REGION_ID = 'bhcore-toast-region';
    var VALID_TYPES: BHCoreToastType[] = ['success', 'error', 'info', 'warning'];

    var region: HTMLElement | null = null;

    /**
     * Lazily creates (once) the fixed-position, top-right toast stack —
     * a single live region so a screen reader announces each new toast
     * without re-announcing ones already on screen.
     */
    function ensureRegion(): HTMLElement {
        if (region && document.body.contains(region)) return region;
        var found = document.getElementById(REGION_ID);
        if (!found) {
            found = document.createElement('div');
            found.id = REGION_ID;
            found.className = 'bhcore-toast-region';
            // role="status" + aria-live="polite": announced without
            // interrupting whatever the user is currently doing —
            // appropriate for confirmations/errors that supplement,
            // rather than replace, existing WP admin notices.
            found.setAttribute('role', 'status');
            found.setAttribute('aria-live', 'polite');
            found.setAttribute('aria-atomic', 'false');
            (document.body || document.documentElement).appendChild(found);
        }
        region = found;
        return found;
    }

    var BHCoreToast: BHCoreToastApi = {
        /**
         * Shows one toast. type is coerced to 'info' if not one of the
         * four recognized values, so a typo'd/unexpected type never throws
         * or silently fails to render — same "harmless degrade" posture as
         * the rest of this ecosystem's shared utilities.
         */
        show: function (message: unknown, type?: string, durationMs?: number): HTMLElement | null {
            if (!message) return null;
            var resolvedType: BHCoreToastType = (VALID_TYPES.indexOf(type as BHCoreToastType) !== -1
                ? (type as BHCoreToastType)
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
            var timer: ReturnType<typeof setTimeout> | null = null;
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

            toastRegion.appendChild(toast);

            // Applied on the next frame (not immediately) so the browser
            // registers the initial (offscreen/transparent) state first —
            // otherwise the CSS transition to bhcore-toast-in has nothing
            // to transition FROM and the toast just appears instantly.
            window.requestAnimationFrame(function () {
                toast.classList.add('bhcore-toast-in');
            });

            if (resolvedDuration > 0) {
                timer = setTimeout(dismiss, resolvedDuration);
            }

            return toast;
        }
    };

    (window as BHCoreToastWindow).BHCoreToast = BHCoreToast;

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

    function isVisible(el: HTMLElement): boolean {
        if (el.hasAttribute('hidden')) return false;
        var display = el.style.display || window.getComputedStyle(el).display;
        return display !== 'none';
    }

    function anyModalOpen(): boolean {
        var els = document.querySelectorAll<HTMLElement>(MODAL_SELECTOR);
        for (var i = 0; i < els.length; i++) {
            var current = els[i];
            if (current && isVisible(current)) return true;
        }
        return false;
    }

    function syncScrollLock(): void {
        document.documentElement.classList.toggle(LOCK_CLASS, anyModalOpen());
    }

    function initScrollLock(): void {
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
