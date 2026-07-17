<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner coverage for the two pieces of infrastructure this
 * session's whole Portal/API-Docs/Test-Runner-results saga produced —
 * OUS_ReliableStore (class-reliable-store.php) and
 * OUS_DebugLog::log_throttled() (class-debug-log.php) — neither of
 * which had any test coverage before this suite, despite now being
 * load-bearing for a security path (BHI_Auth's login-lockout/
 * registration-throttle counters) and the diagnostic pipeline itself.
 *
 * Runs against the REAL options table (this suite's whole reason for
 * existing is confirming the real DB round-trip works, not a mock of
 * it) — every key used here is prefixed 'ous_rs_test_'/'ous_log_throttle_test_'
 * and explicitly cleaned up at the end of run(), win or lose, so a test
 * run never leaves rows behind the way BHI_Portal's own get_or_create_test_user()
 * tags and later sweeps fake users.
 */
class OUS_ReliabilityTestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['own-ur-shit-reliability'] = ['label' => 'Own Ur Shit — reliability (OUS_ReliableStore / log_throttled)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
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

    private static function run_reliable_store_tests() {
        $rows = [];
        $key = 'test_' . wp_generate_password(8, false); // unique per run, avoids collision with a real concurrent request

        // Basic set/get round-trip — the whole point of this class: a
        // direct DB write followed immediately by a direct DB read (no
        // object-cache layer in between) must see the value that was
        // just written.
        OUS_ReliableStore::set($key, 'hello', 60);
        $rows[] = OUS_TestRunner::assert_same('hello', OUS_ReliableStore::get($key), 'set()/get() round-trip returns the exact value written');

        // A missing key returns the default, not a notice/error/null-that-
        // looks-like-a-real-falsy-value.
        $rows[] = OUS_TestRunner::assert_same('nope', OUS_ReliableStore::get('test_definitely_never_set_' . wp_generate_password(8, false), 'nope'), 'get() on a missing key returns the given default');
        $rows[] = OUS_TestRunner::assert_same(null, OUS_ReliableStore::get('test_definitely_never_set_' . wp_generate_password(8, false)), 'get() on a missing key with no default returns null');

        // Complex values (arrays) round-trip through the JSON encode/decode
        // — this is exactly the shape OUS_TestRunner itself relies on to
        // store its own multi-suite report.
        $complex = ['report' => ['a' => 1, 'b' => [2, 3]], 'expires_at' => time() + 60];
        OUS_ReliableStore::set($key, $complex, 60);
        $rows[] = OUS_TestRunner::assert_same($complex, OUS_ReliableStore::get($key), 'set()/get() round-trips a nested array unchanged');

        // Expiry — a value stored with a negative/zero TTL should read
        // back as expired (the default), not linger. Using -1 rather
        // than waiting a real second keeps this suite fast.
        OUS_ReliableStore::set($key, 'should-be-expired', -1);
        $rows[] = OUS_TestRunner::assert_same('expired-default', OUS_ReliableStore::get($key, 'expired-default'), 'a value stored with a past expiry reads back as expired, not the stale value');

        // increment() — the exact shape BHI_Auth's login-fail/registration-
        // throttle counters actually use in production.
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

        // Cleanup — belt-and-suspenders even though every key above used
        // the same $key and the last assertion already deleted it.
        OUS_ReliableStore::delete($key);

        return $rows;
    }

    private static function run_log_throttled_tests() {
        $rows = [];
        $key = 'test_' . wp_generate_password(8, false);
        $table = self::log_table();
        global $wpdb;

        $count_for_key = function () use ($wpdb, $table, $key) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE source = %s", 'OUS_ReliabilityTestSuite/' . $key
            ));
        };

        // First call with a fresh key should always log (nothing to
        // throttle against yet).
        OUS_DebugLog::log_throttled('info', $key, 300, 'reliability suite: first call', [], 'OUS_ReliabilityTestSuite/' . $key);
        $after_first = $count_for_key();
        $rows[] = OUS_TestRunner::assert_same(1, $after_first, 'log_throttled() logs on the first call for a fresh key');

        // A second call within the throttle window (300s) with the SAME
        // key must NOT log again — this is the entire point of the
        // method (see its own docblock: "no log entries" needs to mean
        // something specific, not be indistinguishable from a suppressed
        // duplicate).
        OUS_DebugLog::log_throttled('info', $key, 300, 'reliability suite: second call (should be suppressed)', [], 'OUS_ReliabilityTestSuite/' . $key);
        $after_second = $count_for_key();
        $rows[] = OUS_TestRunner::assert_same(1, $after_second, 'log_throttled() suppresses a second call within the throttle window');

        // A DIFFERENT key, same everything else, is a genuinely separate
        // throttle bucket and should log independently.
        $key2 = $key . '_b';
        OUS_DebugLog::log_throttled('info', $key2, 300, 'reliability suite: different key', [], 'OUS_ReliabilityTestSuite/' . $key);
        $rows[] = OUS_TestRunner::assert_same(2, $count_for_key(), 'a different throttle key logs independently of the first');

        // Cleanup — remove both the log rows this suite created and the
        // raw 'ous_log_throttle_{key}' option log_throttled() itself
        // writes directly (NOT via OUS_ReliableStore — it predates that
        // class and has its own inline option-name convention, see
        // class-debug-log.php's log_throttled()).
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE source = %s", 'OUS_ReliabilityTestSuite/' . $key));
        $wpdb->delete($wpdb->options, ['option_name' => 'ous_log_throttle_' . sanitize_key($key)]);
        $wpdb->delete($wpdb->options, ['option_name' => 'ous_log_throttle_' . sanitize_key($key2)]);

        return $rows;
    }

    private static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_debug_log';
    }
}
