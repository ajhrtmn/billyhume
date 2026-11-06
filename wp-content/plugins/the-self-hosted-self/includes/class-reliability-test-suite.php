<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner coverage for OUS_ReliableStore and OUS_DebugLog::log_throttled().
 * Uses the real options table; every test key is prefixed and cleaned up.
 */
class OUS_ReliabilityTestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['the-self-hosted-self-reliability'] = ['label' => 'The Self-Hosted Self — reliability (OUS_ReliableStore / log_throttled)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, mixed> */
    public static function run(): array {
        $rows = [];
        if (!class_exists('OUS_ReliableStore')) {
            return [['name' => 'OUS_ReliableStore not loaded', 'pass' => false, 'message' => 'Skipped.']];
        }

        $rows = array_merge($rows, self::run_reliable_store_tests());
        if (class_exists('OUS_DebugLog')) {
            $rows = array_merge($rows, self::run_log_throttled_tests());
        } else {
            $rows[] = ['name' => 'OUS_DebugLog not loaded', 'pass' => false, 'message' => 'Skipped log_throttled() tests.'];
        }
        return $rows;
    }

    /** @return array<int, mixed> */
    private static function run_reliable_store_tests(): array {
        $rows = [];
        $key = 'test_' . wp_generate_password(8, false); // unique per run, avoids collision with a real concurrent request

        // Set/get round-trip through the DB.
        OUS_ReliableStore::set($key, 'hello', 60);
        $rows[] = OUS_TestRunner::assert_same('hello', OUS_ReliableStore::get($key), 'set()/get() round-trip returns the exact value written');

        // A missing key returns the default, not a notice/error/null.
        $rows[] = OUS_TestRunner::assert_same('nope', OUS_ReliableStore::get('test_definitely_never_set_' . wp_generate_password(8, false), 'nope'), 'get() on a missing key returns the given default');
        $rows[] = OUS_TestRunner::assert_same(null, OUS_ReliableStore::get('test_definitely_never_set_' . wp_generate_password(8, false)), 'get() on a missing key with no default returns null');

        // Arrays round-trip through JSON (the shape OUS_TestRunner stores).
        $complex = ['report' => ['a' => 1, 'b' => [2, 3]], 'expires_at' => time() + 60];
        OUS_ReliableStore::set($key, $complex, 60);
        $rows[] = OUS_TestRunner::assert_same($complex, OUS_ReliableStore::get($key), 'set()/get() round-trips a nested array unchanged');

        // Negative TTL reads back as expired.
        OUS_ReliableStore::set($key, 'should-be-expired', -1);
        $rows[] = OUS_TestRunner::assert_same('expired-default', OUS_ReliableStore::get($key, 'expired-default'), 'a value stored with a past expiry reads back as expired, not the stale value');

        // increment(), as used by BHI_Auth's throttle counters.
        OUS_ReliableStore::delete($key);
        $rows[] = OUS_TestRunner::assert_same(0, (int) OUS_ReliableStore::get($key, 0), 'a freshly-deleted counter key reads back as 0 via the default');
        OUS_ReliableStore::increment($key, 60);
        OUS_ReliableStore::increment($key, 60);
        $third = OUS_ReliableStore::increment($key, 60);
        $rows[] = OUS_TestRunner::assert_same(3, $third, 'increment() returns the new value after 3 calls (1, 2, 3)');
        $rows[] = OUS_TestRunner::assert_same(3, (int) OUS_ReliableStore::get($key, 0), 'the incremented value is actually persisted, not just returned in-memory');

        // delete() — the key should be genuinely gone, not just expired.
        OUS_ReliableStore::delete($key);
        $rows[] = OUS_TestRunner::assert_same(null, OUS_ReliableStore::get($key), 'delete() removes the key entirely');

        OUS_ReliableStore::delete($key);

        return $rows;
    }

    /** @return array<int, mixed> */
    private static function run_log_throttled_tests(): array {
        $rows = [];
        $key = 'test_' . wp_generate_password(8, false);
        $table = self::log_table();
        global $wpdb;

        $count_for_key = function () use ($wpdb, $table, $key) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE source = %s", 'OUS_ReliabilityTestSuite/' . $key
            ));
        };

        // First call with a fresh key always logs.
        OUS_DebugLog::log_throttled('info', $key, 300, 'reliability suite: first call', [], 'OUS_ReliabilityTestSuite/' . $key);
        $after_first = $count_for_key();
        $rows[] = OUS_TestRunner::assert_same(1, $after_first, 'log_throttled() logs on the first call for a fresh key');

        // A second call within the window must not log.
        OUS_DebugLog::log_throttled('info', $key, 300, 'reliability suite: second call (should be suppressed)', [], 'OUS_ReliabilityTestSuite/' . $key);
        $after_second = $count_for_key();
        $rows[] = OUS_TestRunner::assert_same(1, $after_second, 'log_throttled() suppresses a second call within the throttle window');

        // A different key is a separate throttle bucket.
        $key2 = $key . '_b';
        OUS_DebugLog::log_throttled('info', $key2, 300, 'reliability suite: different key', [], 'OUS_ReliabilityTestSuite/' . $key);
        $rows[] = OUS_TestRunner::assert_same(2, $count_for_key(), 'a different throttle key logs independently of the first');

        // Cleanup: log rows plus the raw 'ous_log_throttle_{key}' option.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE source = %s", 'OUS_ReliabilityTestSuite/' . $key));
        $wpdb->delete($wpdb->options, ['option_name' => 'ous_log_throttle_' . sanitize_key($key)]);
        $wpdb->delete($wpdb->options, ['option_name' => 'ous_log_throttle_' . sanitize_key($key2)]);

        return $rows;
    }

    private static function log_table(): string {
        return OUS_Tables::debug_log();
    }
}
