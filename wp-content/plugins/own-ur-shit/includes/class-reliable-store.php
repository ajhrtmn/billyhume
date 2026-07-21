<?php
if (!defined('ABSPATH')) exit;

/**
 * A drop-in replacement for set_transient()/get_transient() for cases
 * where silently losing the value is a real problem, not just cosmetic
 * — security throttles (login lockouts, registration rate limits), and
 * anything whose whole point is surviving a redirect round-trip (a
 * background job's last-run report, e.g. OUS_TestRunner's results).
 *
 * WHY THIS EXISTS: WordPress transients are stored ENTIRELY inside a
 * persistent object cache (Redis/Memcached) when one is active — NOT in
 * the options table, which is only the fallback for sites with no
 * persistent cache. On some installs a misconfigured/unreliable
 * persistent object cache means transient writes report success but
 * aren't readable on the very next request. Whatever's actually wrong
 * with that cache, the fix at the application layer is: don't trust the
 * object cache for anything that matters, read and write the options
 * table directly instead.
 *
 * This is NOT a general transient replacement — it's a separate,
 * explicitly-opted-into API so a plain get_transient() call elsewhere
 * (caching an expensive computation, where "sometimes falls back to
 * recomputing" is fine) isn't silently made slower by going through raw
 * SQL for no reason. Use this only where get_transient() returning
 * nothing when it should have is a real bug, not just a cache miss.
 */
class OUS_ReliableStore {
    private static function option_name($key) {
        return 'ous_rs_' . sanitize_key($key);
    }

    // Raw JSON string + expiry, one option per key.
    public static function set($key, $value, $ttl_seconds) {
        global $wpdb;
        $option = self::option_name($key);
        $payload = wp_json_encode(['value' => $value, 'expires_at' => time() + (int) $ttl_seconds]);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $option, $payload
        ));
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    // Direct DB read, bypassing get_option()'s cache layer — the whole
    // point of this class. Returns $default if missing, malformed, or
    // expired (expiry is checked in PHP, not via a MySQL query, so this
    // stays a single simple SELECT).
    public static function get($key, $default = null) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::option_name($key)));
        if (!$raw) return $default;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) return $default;
        if (!empty($decoded['expires_at']) && time() > (int) $decoded['expires_at']) return $default;
        return $decoded['value'];
    }

    public static function delete($key) {
        global $wpdb;
        $option = self::option_name($key);
        $wpdb->delete($wpdb->options, ['option_name' => $option]);
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    // Convenience for the counter shape current call sites (login-fail
    // count, registration throttle count) want — read current int, add
    // 1, write back with a fresh TTL, single round trip from the
    // caller's perspective. NOT atomic (a real race between two
    // simultaneous failed logins from the same IP could under-count by
    // one) — acceptable here since these are abuse-slowing throttles,
    // not billing-grade counters; see BHM_Wallet's debit()/apply_delta()
    // for the atomic-UPDATE pattern used where undercounting would be a
    // real money problem.
    public static function increment($key, $ttl_seconds) {
        $value = (int) self::get($key, 0) + 1;
        self::set($key, $value, $ttl_seconds);
        return $value;
    }
}
