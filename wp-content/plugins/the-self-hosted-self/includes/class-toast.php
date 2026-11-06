<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Toast: loads toast.js/toast.css everywhere and carries a toast message
 * across a classic admin-post POST+redirect.
 *
 * WHY OUS_ReliableStore, not transients: this install's object cache has lost
 * transient writes between requests. Keyed per user (or a guest cookie id), so
 * no redirect URL needs changing.
 *
 * Usage, right before a redirect:
 *
 *   OUS_Toast::queue('Notes saved.', 'success');
 *   wp_safe_redirect(...);
 *   exit;
 *
 * AJAX flows should call BHCoreToast.show() directly from JS instead.
 */
class OUS_Toast {
    const COOKIE = 'bhcore_toast_sid';
    const DEFAULT_TTL = 60; // seconds — only needs to survive one redirect round-trip, not sit around

    public static function init(): void {
        // Priority 1 so the guest cookie exists before anything on 'init' calls queue().
        add_action('init', [self::class, 'maybe_set_guest_cookie'], 1);

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_enqueue_scripts',    [self::class, 'enqueue_assets']);

        // Footer, so BHCoreToast.show() is already defined.
        add_action('admin_footer', [self::class, 'print_and_consume']);
        add_action('wp_footer',    [self::class, 'print_and_consume']);
    }

    /**
     * Loaded globally so any plugin's JS can call BHCoreToast.show().
     */
    public static function enqueue_assets(): void {
        wp_enqueue_style('bhcore-toast', OUS_URL . 'assets/css/toast.css', [], OUS_VER);
        wp_enqueue_script('bhcore-toast', OUS_URL . 'assets/js/toast.js', [], OUS_VER, true);
    }

    /* ---------------- queuing (PHP redirect hand-off) ---------------- */

    /**
     * Queues a one-shot toast for the current user/guest's next page load.
     * $type: success|error|info|warning (else 'info'). Returns false, quietly,
     * if no storage key resolves (e.g. guest cookie blocked).
     */
    public static function queue(string $message, string $type = 'info', int $ttl_seconds = self::DEFAULT_TTL): bool {
        if (!class_exists('OUS_ReliableStore')) return false;

        $key = self::store_key();
        if (!$key) return false;

        OUS_ReliableStore::set($key, [
            'message' => (string) $message,
            'type'    => self::sanitize_type($type),
        ], max(5, (int) $ttl_seconds));

        return true;
    }

    /** @param mixed $type */
    private static function sanitize_type($type): string {
        return in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
    }

    /**
     * Per-user key when logged in, else a short-lived HttpOnly guest cookie id
     * (never IP address, which is shared behind NAT/proxies).
     */
    private static function store_key(): string {
        $uid = get_current_user_id();
        if ($uid) return 'toast_u' . $uid;

        $sid = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return $sid ? 'toast_g' . $sid : '';
    }

    /**
     * Sets the guest cookie if missing and mirrors it into $_COOKIE, so a
     * queue() later in this same request can resolve a key.
     */
    public static function maybe_set_guest_cookie(): void {
        if (is_user_logged_in() || headers_sent()) return;
        if (isset($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] !== '') return;

        $sid = wp_generate_password(24, false, false);
        setcookie(self::COOKIE, $sid, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[self::COOKIE] = $sid;
    }

    /* ---------------- consuming (the other half of the hand-off) ---------------- */

    /**
     * Reads and deletes any queued notice, then prints a script calling
     * BHCoreToast.show(). WHY: delete before printing so a failed render cannot
     * leave the notice repeating on every refresh.
     */
    public static function print_and_consume(): void {
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
