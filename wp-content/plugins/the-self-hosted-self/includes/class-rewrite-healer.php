<?php
if (!defined('ABSPATH')) exit;

/**
 * Audit fix (2026-07-25) — extracted from a real, hand-synced duplicate:
 * BHI_Portal::add_rewrite() (class-portal.php) and
 * BHM_Storefront::add_rewrite() (bh-monetization-woo/includes/
 * class-storefront.php) both independently implemented the identical
 * ~90-line algorithm (versioned rewrite rule, direct-$wpdb cache-
 * bypassing persistence check, throttled retry via a raw options-table
 * timestamp, forced flush with explicit cache eviction, throttled log
 * trace on pass/fail) — the Storefront copy's own comments admitted it
 * was a manual port ("Upgraded to BHI_Portal's self-verifying shape").
 *
 * A real, live bug was found while extracting this: Storefront's
 * force_flush_and_verify() still called wp_cache_flush() UNCONDITIONALLY
 * on every self-heal attempt — the exact pattern Portal's own history
 * (see this class's own escalation logic below) already identified as
 * "very likely the cause of the API Docs page intermittently breaking,"
 * since a full object-cache flush mid-request affects unrelated code
 * running later in that same request. Storefront's hand-synced copy had
 * fallen behind that fix. Routing both consumers through this one
 * implementation closes that gap for Storefront too, not just DRY.
 *
 * Each consumer still registers its OWN rewrite rule(s) (different
 * slugs/query vars) — only the self-heal ALGORITHM around that
 * registration is shared here.
 */
class BHY_RewriteHealer {
    const DEFAULT_THROTTLE_SECONDS = 60;

    /**
     * Call this once per request, immediately after your own
     * add_rewrite_rule() call(s) — same call site add_rewrite() already
     * occupied in both prior implementations.
     *
     * @param string $check_pattern     The literal substring to look for
     *                                  in the persisted rewrite_rules
     *                                  option (e.g. '^' . self::REWRITE_SLUG)
     *                                  — proof your rule actually made it
     *                                  into the persisted table, not just
     *                                  that add_rewrite_rule() ran.
     * @param string $throttle_option   A unique options-table key this
     *                                  consumer owns for its own retry
     *                                  timestamp (e.g. 'bhi_portal_rewrite_last_attempt').
     * @param string $log_context       Passed straight to OUS_DebugLog's
     *                                  own $context param (e.g. 'Portal').
     * @param int    $throttle_seconds  How long to sit out between real
     *                                  flush attempts once one has failed.
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

    // Public (audit fix, 2026-07-25) — both BHI_Portal's and
    // BHM_Storefront's own Debug Tools diagnostics call this directly to
    // show "is the rule actually in the DB right now," independent of
    // the throttle/flush machinery below.
    //
    // Bypasses the object cache on purpose — $wpdb->get_var() talks
    // straight to the database, so a stale persistent-cache copy of the
    // option can't fool this check (the original bug this whole
    // mechanism exists to catch: a flush that reported success while
    // never actually persisting).
    public static function rule_persisted(string $check_pattern): bool {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'rewrite_rules'));
        if (!$raw) return false;
        return strpos($raw, $check_pattern) !== false;
    }

    // Deliberately NOT get_transient()/set_transient() — see this
    // method's history in class-portal.php's original version: on an
    // install with a persistent object cache, a transient-based throttle
    // can read as "already attempted" forever if that cache is stuck,
    // silently skipping the self-heal with nothing logged. A direct,
    // cache-bypassing DB read/write avoids that failure mode.
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

        // Escalation, not unconditional — a full wp_cache_flush() wipes
        // the ENTIRE object cache mid-request, which other code running
        // later in this same request may depend on having a warm cache
        // for. Only reached if the two targeted evictions above weren't
        // enough — see this class's own docblock for the real bug this
        // fixed in the Storefront consumer specifically.
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
