<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Toast — the ecosystem-wide toast notification system's PHP half:
 * asset loading (toast.js/toast.css, globally, both wp-admin and the
 * front end) plus a server-side redirect hand-off for the classic
 * admin-post.php POST+redirect flows this ecosystem is built on (most
 * actions here are NOT AJAX — see class-dashboard.php, class-jobs.php,
 * class-debug.php, bh-crm's class-notes.php, all of which redirect back
 * to a GET page after doing work).
 *
 * THE HAND-OFF PROBLEM: a toast is rendered client-side by JS
 * (BHCoreToast.show(), see assets/js/toast.js), but a classic redirect
 * flow's "the action succeeded" message only exists in PHP, on the
 * request that's about to redirect away — by the time the NEXT request
 * (the one that actually renders a page) runs, that PHP-side message is
 * gone unless something carries it across the redirect.
 *
 * WHY NOT set_transient()/get_transient() FOR THIS: this specific
 * install's persistent object cache has repeatedly, demonstrably lost
 * transient writes between requests — see OUS_ReliableStore's own
 * docblock (class-reliable-store.php) for the full incident history
 * (OUS_TestRunner's results vanishing, BHI_Portal's rewrite-rule
 * throttle silently stuck, OUS_Debug::is_locked() flipping per-request).
 * A toast that's supposed to confirm "your vote/note/action was saved"
 * silently never appearing would be exactly that same bug again, just in
 * a new place — so this reuses OUS_ReliableStore (direct options-table
 * read/write, bypassing the unreliable object-cache layer) instead of
 * transients, same as every other "must survive a redirect round-trip"
 * value in this codebase.
 *
 * WHY NOT A SIGNED QUERY-ARG: a query-arg hand-off would need every
 * existing redirect() call site across the ecosystem (OUS_Dashboard,
 * OUS_Debug, OUS_Jobs, bh-crm's class-notes.php, etc.) to be rewritten to
 * thread an extra signed param through — a much larger, riskier diff
 * than adding one new queue()/consume_and_print() pair that call sites
 * opt into individually. Keying the stored notice by user (falling back
 * to a short-lived per-guest cookie id for logged-out visitors) keeps the
 * same "one row, cheap, self-expiring" shape OUS_ReliableStore already
 * uses for its other keys, without touching the URL at all.
 *
 * USAGE from any plugin's PHP, right before an admin-post redirect:
 *
 *   OUS_Toast::queue('Notes saved.', 'success');
 *   wp_safe_redirect(...);
 *   exit;
 *
 * For an AJAX flow (bh-contest's vote REST route, bh-courses' mark-
 * complete AJAX handler), do NOT use this class — call
 * `BHCoreToast.show(...)` directly from the JS success handler instead;
 * there's no redirect to hand off across, so PHP-side queuing would just
 * add an unnecessary round trip.
 *
 * Not yet exercised against live PHP+MySQL in this pass — reasoning- and
 * brace-balance-checked only. Please click a wired action (bh-crm "Save
 * notes" is the simplest to test) and confirm the toast actually appears
 * once, then does NOT repeat on a plain page refresh.
 */
class OUS_Toast {
    const COOKIE = 'bhcore_toast_sid';
    const DEFAULT_TTL = 60; // seconds — only needs to survive one redirect round-trip, not sit around

    public static function init() {
        // Priority 1: run early enough that a guest's cookie is available
        // (via $_COOKIE, set on THIS request for use on the very next one)
        // before anything later on 'init' might call queue() this same
        // request — e.g. a guest submitting a front-end form that both
        // sets the cookie for the first time and queues a toast in one go.
        add_action('init', [self::class, 'maybe_set_guest_cookie'], 1);

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_enqueue_scripts',    [self::class, 'enqueue_assets']);

        // Printed late (footer, not head) so BHCoreToast.show() is always
        // already defined by the time this inline script runs, and so a
        // toast never blocks/delays anything else on the page.
        add_action('admin_footer', [self::class, 'print_and_consume']);
        add_action('wp_footer',    [self::class, 'print_and_consume']);
    }

    /**
     * Loaded globally (every admin screen, every front-end page) — this
     * is deliberately NOT gated to specific screens/plugins the way
     * BHY_UI's shared admin CSS/JS is, because the whole point is any
     * plugin in the ecosystem can call BHCoreToast.show() from its own
     * JS without knowing or caring whether toast.js happens to already
     * be loaded. Same "own script handle, no assumed dependency, cheap
     * enough to always load" posture OUS_Notifications' admin-bar bell
     * already established.
     */
    public static function enqueue_assets() {
        wp_enqueue_style('bhcore-toast', OUS_URL . 'assets/css/toast.css', [], OUS_VER);
        wp_enqueue_script('bhcore-toast', OUS_URL . 'assets/js/toast.js', [], OUS_VER, true);
    }

    /* ---------------- queuing (PHP redirect hand-off) ---------------- */

    /**
     * Queues a one-shot toast to be shown on the NEXT page load for the
     * current user (or current guest, via cookie — see below). Call this
     * right before a wp_safe_redirect()/exit in a classic admin-post.php
     * or front-end POST+redirect flow.
     *
     * $type: success|error|info|warning (anything else is coerced to
     * 'info', same graceful-degrade posture as BHCoreToast.show() itself
     * on the JS side).
     *
     * Returns false (a harmless no-op, not a fatal) if no storage key can
     * be resolved — e.g. a guest whose browser blocked the cookie set on
     * the previous request. A missed toast is a minor UX gap, not
     * something worth erroring over, matching this ecosystem's existing
     * "every extension point fails quietly" convention (OUS_Jobs,
     * OUS_Notifications, etc.).
     */
    public static function queue($message, $type = 'info', $ttl_seconds = self::DEFAULT_TTL) {
        if (!class_exists('OUS_ReliableStore')) return false;

        $key = self::store_key();
        if (!$key) return false;

        OUS_ReliableStore::set($key, [
            'message' => (string) $message,
            'type'    => self::sanitize_type($type),
        ], max(5, (int) $ttl_seconds));

        return true;
    }

    private static function sanitize_type($type) {
        return in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
    }

    /**
     * Per-user for logged-in users (covers every wp-admin action and any
     * front-end action gated behind login — the large majority of this
     * ecosystem's real action points: bh-crm notes, Debug Tools buttons,
     * bh-courses/bh-contest actions that already require an account).
     * Falls back to a short-lived, HttpOnly, per-guest cookie id for
     * logged-out visitors so a genuinely anonymous POST+redirect flow
     * (if one is ever added) still has somewhere to hand the message off
     * to — NOT relying on IP address, which is shared/unreliable behind
     * NAT or a proxy.
     */
    private static function store_key() {
        $uid = get_current_user_id();
        if ($uid) return 'toast_u' . $uid;

        $sid = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return $sid ? 'toast_g' . $sid : '';
    }

    /**
     * Sets the guest cookie once, if missing — logged-in users never get
     * one (their user id is already a stable, no-cookie-needed key). Also
     * mirrors the new value into $_COOKIE immediately so a queue() call
     * later in this SAME request (a guest's first-ever POST on the site)
     * can already resolve a store_key() without waiting for the cookie to
     * round-trip to the browser and back.
     */
    public static function maybe_set_guest_cookie() {
        if (is_user_logged_in() || headers_sent()) return;
        if (isset($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] !== '') return;

        $sid = wp_generate_password(24, false, false);
        setcookie(self::COOKIE, $sid, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[self::COOKIE] = $sid;
    }

    /* ---------------- consuming (the other half of the hand-off) ---------------- */

    /**
     * Reads and immediately deletes any queued notice for the current
     * user/guest, then prints a tiny inline script that calls
     * BHCoreToast.show() once the DOM is ready. Deleting BEFORE printing
     * (not after) means even a page that errors out partway through
     * rendering after this point won't leave the notice stuck showing on
     * every subsequent refresh — same "delete first, so a stuck value
     * can't repeat forever" shape as a one-shot flag should have.
     */
    public static function print_and_consume() {
        if (!class_exists('OUS_ReliableStore')) return;

        $key = self::store_key();
        if (!$key) return;

        $notice = OUS_ReliableStore::get($key);
        if (!$notice || !is_array($notice) || empty($notice['message'])) return;

        OUS_ReliableStore::delete($key);

        printf(
            "<script>document.addEventListener('DOMContentLoaded',function(){if(window.BHCoreToast){BHCoreToast.show(%s,%s);}});</script>\n",
            wp_json_encode((string) $notice['message']),
            wp_json_encode(self::sanitize_type($notice['type'] ?? 'info'))
        );
    }
}
