<?php
if (!defined('ABSPATH')) exit;

/**
 * Refund/chargeback abuse-pattern detection — extracted out of
 * class-products.php in the ecosystem-wide DRY/SOLID refactor pass
 * (see the ecosystem's own README/changelog for the full rationale).
 * class-products.php was doing several genuinely distinct things under
 * one class (WooCommerce product sync, streaming-gate hooks, pay-per-
 * play billing, refund/fraud-pattern detection, entitlement granting);
 * this is the most self-contained of those responsibilities, so it's
 * the first one pulled out into its own single-responsibility class.
 *
 * Buy → stream or download → refund → repeat, extracting real content
 * or plays while the artist eats the cost every time. One refund is
 * completely normal (real dissatisfaction, a real payment dispute) —
 * this only reacts to a REPEATED pattern from the same account within a
 * rolling window, and it flags for admin review rather than silently
 * auto-banning anyone (a false positive here is a real fan getting
 * locked out, which is worse than a human spending 30 seconds checking
 * a flag).
 *
 * Only caller of track_refund_pattern() is BHM_Products::on_order_reversed()
 * — kept as a plain public static call (not a hook) since this is a
 * synchronous, required step of order reversal, not an optional
 * extension point. refund_count_recent() is also consumed externally by
 * bh-monetization-woo's own class-crm-integration.php, so an artist can
 * see refund-abuse signal alongside a listener's other activity in
 * bh-crm's person view.
 */
class BHM_Fraud {
    const REFUND_ABUSE_WINDOW = 30 * DAY_IN_SECONDS;
    const REFUND_ABUSE_THRESHOLD = 3;

    public static function track_refund_pattern($user_id, $order_id) {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        $log = array_values(array_filter($log, fn($ts) => $ts > $cutoff));
        $log[] = time();
        update_user_meta($user_id, '_bhm_refund_log', $log);

        $flagged = count($log) >= self::REFUND_ABUSE_THRESHOLD;
        update_user_meta($user_id, '_bhm_refund_flagged', $flagged ? '1' : '');

        // Cross-account correlation: the same evasion this per-account
        // log can't catch on its own — someone hitting the threshold,
        // then just signing up again under a new account. Hashed
        // (never raw IP) fingerprint of connection + a persistent,
        // non-tracking cookie id, recorded once per refund.
        $fingerprint = self::fingerprint_for();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhm_refund_fingerprints', [
            'fingerprint' => $fingerprint, 'user_id' => $user_id, 'wc_order_id' => $order_id,
        ]);
        $cutoff_sql = gmdate('Y-m-d H:i:s', $cutoff);
        $distinct_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bhm_refund_fingerprints
             WHERE fingerprint = %s AND created_at > %s", $fingerprint, $cutoff_sql
        ));
        $cross_account_flagged = $distinct_users >= 2;
        if ($cross_account_flagged) update_user_meta($user_id, '_bhm_refund_shared_device_flagged', '1');

        if ($flagged || $cross_account_flagged) {
            // Extension point, not an automatic restriction — this
            // plugin doesn't unilaterally decide to cut someone off;
            // it surfaces the pattern (here, and in bh-crm's activity
            // summary via class-crm-integration.php) so a human decides
            // what, if anything, to do about a specific account.
            do_action('bhm_refund_pattern_flagged', $user_id, count($log), $cross_account_flagged);
        }
    }

    // A hash, not the raw IP — this correlates repeat behavior without
    // this table itself becoming a new place raw connection data sits
    // around. The cookie half is a plain random id (set once, read on
    // subsequent visits), NOT a cross-site tracking mechanism and NOT
    // tied to any ad/analytics network — its only job is "does this
    // browser look like the same one as last time," same-origin only.
    //
    // Known limitation (noted in this ecosystem's QA report): on a site
    // behind a reverse proxy/CDN, REMOTE_ADDR is the proxy's IP for
    // every visitor, which collapses the IP half of this fingerprint to
    // a constant — the cross-account correlation above then leans
    // entirely on the cookie half. Not fixed here since this codebase
    // has no established X-Forwarded-For handling convention yet.
    const FINGERPRINT_COOKIE = 'bhm_fp';

    private static function fingerprint_for() {
        if (empty($_COOKIE[self::FINGERPRINT_COOKIE])) {
            $val = wp_generate_password(32, false);
            if (!headers_sent()) {
                setcookie(self::FINGERPRINT_COOKIE, $val, time() + 5 * YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            }
            $_COOKIE[self::FINGERPRINT_COOKIE] = $val;
        }
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        return hash('sha256', $ip . '|' . $_COOKIE[self::FINGERPRINT_COOKIE]);
    }

    public static function refund_count_recent($user_id) {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        return count(array_filter($log, fn($ts) => $ts > $cutoff));
    }
}
