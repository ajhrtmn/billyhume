<?php
if (!defined('ABSPATH')) exit;

/**
 * Transient-like store that reads/writes the options table directly, for
 * values that must not be silently lost (security throttles, values surviving
 * a redirect).
 * WHY: with a persistent object cache, transients live only in that cache, and
 * an unreliable cache can drop writes. Opt-in only; plain caching should keep
 * using transients.
 */
class OUS_ReliableStore {
    private static function option_name(string $key): string {
        return 'ous_rs_' . sanitize_key($key);
    }

    // Raw JSON string + expiry, one option per key.
    /** @param mixed $value */
    public static function set(string $key, $value, int $ttl_seconds): void {
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

    // Direct DB read, bypassing get_option()'s cache. Returns $default if missing,
    // malformed, or expired.
    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::option_name($key)));
        if (!$raw) return $default;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) return $default;
        if (!empty($decoded['expires_at']) && time() > (int) $decoded['expires_at']) return $default;
        return $decoded['value'];
    }

    public static function delete(string $key): void {
        global $wpdb;
        $option = self::option_name($key);
        $wpdb->delete($wpdb->options, ['option_name' => $option]);
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    // Read, add 1, write back with a fresh TTL. Not atomic (may under-count by one
    // under a race); fine for abuse throttles, not for money (see BHM_Wallet).
    public static function increment(string $key, int $ttl_seconds): int {
        $value = (int) self::get($key, 0) + 1;
        self::set($key, $value, $ttl_seconds);
        return $value;
    }
}
