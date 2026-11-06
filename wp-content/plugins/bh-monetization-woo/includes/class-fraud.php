<?php
if (!defined('ABSPATH')) exit;

/**
 * Refund-abuse and top-up velocity detection (buy -> extract -> refund -> repeat).
 * Only flags for human review, never auto-restricts: a false positive locks out a real fan.
 */
class BHM_Fraud {
    const REFUND_ABUSE_WINDOW = 30 * DAY_IN_SECONDS;
    const REFUND_ABUSE_THRESHOLD = 3;

    public static function track_refund_pattern(int $user_id, int $order_id): void {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        $log = array_values(array_filter($log, fn($ts) => $ts > $cutoff));
        $log[] = time();
        update_user_meta($user_id, '_bhm_refund_log', $log);

        $flagged = count($log) >= self::REFUND_ABUSE_THRESHOLD;
        update_user_meta($user_id, '_bhm_refund_flagged', $flagged ? '1' : '');

        // Cross-account correlation catches re-signups that evade the per-account flag.
        $fingerprint = self::fingerprint_for();
        global $wpdb;
        $wpdb->insert(BHM_Tables::refund_fingerprints(), [
            'fingerprint' => $fingerprint, 'user_id' => $user_id, 'wc_order_id' => $order_id,
        ]);
        $cutoff_sql = gmdate('Y-m-d H:i:s', $cutoff);
        $distinct_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM " . BHM_Tables::refund_fingerprints() . "
             WHERE fingerprint = %s AND created_at > %s", $fingerprint, $cutoff_sql
        ));
        $cross_account_flagged = $distinct_users >= 2;
        if ($cross_account_flagged) update_user_meta($user_id, '_bhm_refund_shared_device_flagged', '1');

        if ($flagged || $cross_account_flagged) {
            // Extension point only; a human decides what to do.
            do_action('bhm_refund_pattern_flagged', $user_id, count($log), $cross_account_flagged);
        }
    }

    // sha256(IP | first-party random cookie id); raw IPs are never stored.
    // Known limitation: behind a proxy/CDN REMOTE_ADDR is constant, so only the cookie half discriminates.
    const FINGERPRINT_COOKIE = 'bhm_fp';

    private static function fingerprint_for(): string {
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

    public static function refund_count_recent(int $user_id): int {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        return count(array_filter($log, fn($ts) => $ts > $cutoff));
    }

    /**
     * Top-up velocity flag (filterable cap). Never blocks: the money is already captured,
     * so refusing credit would only strand a paid order. Real card-fraud defense is the gateway's job.
     */
    const TOPUP_VELOCITY_WINDOW = DAY_IN_SECONDS;

    public static function topup_velocity_cap_cents(): int {
        return (int) apply_filters('bhm_topup_velocity_cap_cents', 50000); // $500/24h default
    }

    public static function track_topup_velocity(int $user_id, int $cents): void {
        $log = get_user_meta($user_id, '_bhm_topup_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::TOPUP_VELOCITY_WINDOW;
        $log = array_values(array_filter($log, fn($row) => is_array($row) && ($row['ts'] ?? 0) > $cutoff));
        $log[] = ['ts' => time(), 'cents' => (int) $cents];
        update_user_meta($user_id, '_bhm_topup_log', $log);

        $total_cents = array_sum(array_column($log, 'cents'));
        $flagged = $total_cents >= self::topup_velocity_cap_cents();
        update_user_meta($user_id, '_bhm_topup_velocity_flagged', $flagged ? '1' : '');

        if ($flagged) {
            do_action('bhm_topup_velocity_flagged', $user_id, $total_cents);
        }
    }

    public static function topup_total_recent_cents(int $user_id): int {
        $log = get_user_meta($user_id, '_bhm_topup_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::TOPUP_VELOCITY_WINDOW;
        return array_sum(array_column(array_filter($log, fn($row) => is_array($row) && ($row['ts'] ?? 0) > $cutoff), 'cents'));
    }
}
