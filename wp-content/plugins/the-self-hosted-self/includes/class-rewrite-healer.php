<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared rewrite-rule self-heal used by BHI_Portal and BHM_Storefront: verify
 * the rule is persisted (bypassing the object cache), retry on a throttle, and
 * flush. Each consumer still registers its own rules.
 */
class BHY_RewriteHealer {
    const DEFAULT_THROTTLE_SECONDS = 60;

    /**
     * Call once per request, right after your own add_rewrite_rule() calls.
     *
     * @param string $check_pattern     Substring to find in the persisted
     *                                  rewrite_rules option (e.g. '^' . self::REWRITE_SLUG).
     * @param string $throttle_option   Options-table key this consumer owns for
     *                                  its retry timestamp.
     * @param string $log_context       OUS_DebugLog $context (e.g. 'Portal').
     * @param int    $throttle_seconds  Wait between flush attempts after a failure.
     */
    public static function maybe_heal(string $check_pattern, string $throttle_option, string $log_context, int $throttle_seconds = self::DEFAULT_THROTTLE_SECONDS): bool {
        if (self::rule_persisted($check_pattern)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('info', $throttle_option . '_pass', 300,
                    $log_context . ' rewrite-rule persistence check ran and confirmed the rule is present.', [], $log_context
                );
            }
            return true;
        }

        if (self::not_recently_attempted($throttle_option, $throttle_seconds)) {
            return self::force_flush_and_verify($check_pattern, $log_context);
        }

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('warning', $throttle_option . '_missing_throttled', 300,
                $log_context . ' rewrite rule confirmed missing this request, but a self-heal attempt was made recently — sitting out the throttle window rather than re-flushing.', [], $log_context
            );
        }
        return false;
    }

    // Public for Debug Tools diagnostics. WHY: reads the DB directly so a stale
    // persistent-cache copy cannot report a rule that never persisted.
    public static function rule_persisted(string $check_pattern): bool {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'rewrite_rules'));
        if (!$raw) return false;
        return strpos($raw, $check_pattern) !== false;
    }

    // WHY: direct DB read/write, not transients; a stuck object cache could make
    // a transient throttle read "attempted" forever.
    private static function not_recently_attempted(string $throttle_option, int $throttle_seconds): bool {
        global $wpdb;
        $last = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $throttle_option));
        if ($last && (time() - (int) $last) < $throttle_seconds) return false;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $throttle_option, (string) time()
        ));
        wp_cache_delete($throttle_option, 'options');
        wp_cache_delete('alloptions', 'options');
        return true;
    }

    private static function force_flush_and_verify(string $check_pattern, string $log_context): bool {
        flush_rewrite_rules();
        wp_cache_delete('rewrite_rules', 'options');
        wp_cache_delete('alloptions', 'options');

        $persisted = self::rule_persisted($check_pattern);

        // WHY: full wp_cache_flush() only as escalation; it wipes the cache other
        // code later in this request may rely on.
        if (!$persisted && function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $persisted = self::rule_persisted($check_pattern);
        }

        if ($persisted) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', $log_context . ' rewrite rule self-healed and confirmed persisted.', [], $log_context);
            }
            return true;
        }

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', $log_context . ' rewrite rule still not persisted after a forced flush + full cache eviction — likely cause is outside WordPress\'s own caching layer.', [], $log_context);
        }
        return false;
    }
}
