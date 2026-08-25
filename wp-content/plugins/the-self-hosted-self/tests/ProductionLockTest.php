<?php

use PHPUnit\Framework\TestCase;

/**
 * OUS_Debug::is_locked() is the one gate every "does real work" Debug
 * Tools section (and, as of this session, BH_Storybook_Panel's shell_exec
 * buttons) relies on to never fire against a live site. Nothing
 * regression-tested the gate itself before now: a refactor could silently
 * drop it and nothing here would fail until someone ran a real "does real
 * work" button against production and watched it actually run.
 *
 * Pure-logic, no real WordPress runtime or database: wp_get_environment_type(),
 * home_url(), and $wpdb are all stubbed as controllable globals so each
 * test can drive the exact combination is_locked() actually branches on.
 * wp_parse_url() is aliased straight to PHP's own parse_url() -- no exotic
 * URL is used anywhere in these tests, so the difference between the two
 * (UTF-8 host handling) never matters here.
 *
 * Real finding while writing this: is_locked()'s own comment says it
 * "fails safe: unknown = blocked," but that is only true for the
 * function-missing branch. An environment string wp_get_environment_type()
 * returns that ISN'T the literal 'production' -- a typo, a value this
 * codebase has never seen -- skips the whole lock-evaluation block
 * entirely and falls straight through to `return false` (UNLOCKED). This
 * test asserts that REAL behavior rather than the doc comment's broader
 * claim, and names the discrepancy here rather than silently asserting
 * what the comment implies but the code doesn't do. Whether that's worth
 * tightening is a real product decision, not something to fix inside a
 * test file.
 *
 * Known gap, named rather than silently skipped: the `!function_exists(
 * 'wp_get_environment_type')` half of is_locked()'s condition is NOT
 * covered here, because this file stubs that function into existence for
 * every other test to work at all — there is no way, in one PHP process,
 * to test "the function doesn't exist" once something has already
 * defined it. That branch is the one part of the comment's "unknown =
 * blocked" claim that IS actually true in the code.
 */

$GLOBALS['__ous_test_env_type'] = 'production';
$GLOBALS['__ous_test_http_host'] = 'billyhume.wasmer.app';
$GLOBALS['__ous_test_home_url'] = 'https://billyhume.wasmer.app';
$GLOBALS['__ous_test_db_home'] = 'https://billyhume.wasmer.app';

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type() { return $GLOBALS['__ous_test_env_type']; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('home_url')) {
    function home_url() { return $GLOBALS['__ous_test_home_url']; }
}
if (!class_exists('OUS_Test_WPDB_Stub')) {
    /** Minimal $wpdb stand-in — only the two calls is_locked() makes. */
    class OUS_Test_WPDB_Stub {
        public $options = 'wp_options';
        public function prepare($query, ...$args) { return $query; }
        public function get_var($query) { return $GLOBALS['__ous_test_db_home']; }
    }
}
$GLOBALS['wpdb'] = new OUS_Test_WPDB_Stub();

require_once dirname(__DIR__) . '/includes/class-debug.php';

final class ProductionLockTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['__ous_test_env_type'] = 'production';
        // A non-local, real-looking production host/URL on all three
        // signals is_locked() checks — the "should actually lock" baseline
        // every test starts from unless it deliberately overrides one.
        $GLOBALS['__ous_test_http_host'] = 'billyhume.wasmer.app';
        $GLOBALS['__ous_test_home_url'] = 'https://billyhume.wasmer.app';
        $GLOBALS['__ous_test_db_home'] = 'https://billyhume.wasmer.app';
        $_SERVER['HTTP_HOST'] = $GLOBALS['__ous_test_http_host'];

        if (defined('OUS_DEBUG_TOOLS_FORCE')) {
            // A constant can't be undefined once set — the one test that
            // needs it defines it in its own body and is ordered last
            // (test_zzz_) specifically so no earlier test's setUp() ever
            // sees it already defined.
            $this->markTestSkipped('OUS_DEBUG_TOOLS_FORCE already defined by an earlier test in this process.');
        }
    }

    public function test_production_with_a_real_looking_host_is_locked(): void {
        $this->assertTrue(OUS_Debug::is_locked(), 'A production environment type with a genuinely non-local host on every signal must lock.');
    }

    public function test_unrecognized_environment_string_is_NOT_locked(): void {
        // See this file's own docblock: this documents real, current
        // behavior, not the "unknown = blocked" claim in is_locked()'s own
        // comment, which only holds for the missing-function branch below.
        $GLOBALS['__ous_test_env_type'] = 'some-unrecognized-string';
        $this->assertFalse(OUS_Debug::is_locked(), 'An environment string other than the literal "production" skips the lock check entirely under current behavior.');
    }

    public function test_local_is_not_locked(): void {
        $GLOBALS['__ous_test_env_type'] = 'local';
        $this->assertFalse(OUS_Debug::is_locked(), 'A real dev environment type must not be locked.');
    }

    public function test_development_is_not_locked(): void {
        $GLOBALS['__ous_test_env_type'] = 'development';
        $this->assertFalse(OUS_Debug::is_locked(), 'A real dev environment type must not be locked.');
    }

    public function test_production_env_type_but_localhost_http_host_is_not_locked(): void {
        // The documented reason this override exists at all (see
        // is_locked()'s own long comment): a local install that never set
        // WP_ENVIRONMENT_TYPE reads as 'production' by WordPress's own
        // default, so a real localhost/.test/.local host is treated as
        // the escape hatch rather than genuinely locking a developer out.
        $GLOBALS['__ous_test_env_type'] = 'production';
        $GLOBALS['__ous_test_http_host'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $GLOBALS['__ous_test_home_url'] = 'http://localhost:10008';
        $GLOBALS['__ous_test_db_home'] = 'http://localhost:10008';
        $this->assertFalse(OUS_Debug::is_locked(), 'production env type + a localhost host on every signal must NOT lock -- this is the documented local-dev escape hatch.');
    }

    public function test_production_env_type_with_only_db_host_looking_local_is_not_locked(): void {
        // Any ONE of the three signals (HTTP_HOST, home_url(), the direct
        // DB read) matching the local pattern is enough -- confirms this
        // is an OR across all three, not just the raw HTTP_HOST check,
        // which is the specific staleness scenario this mechanism exists
        // to catch (see the long comment above is_locked() about a
        // persistent-object-cache-stale home_url()).
        $GLOBALS['__ous_test_env_type'] = 'production';
        $GLOBALS['__ous_test_http_host'] = 'billyhume.wasmer.app';
        $_SERVER['HTTP_HOST'] = 'billyhume.wasmer.app';
        $GLOBALS['__ous_test_home_url'] = 'https://billyhume.wasmer.app';
        $GLOBALS['__ous_test_db_home'] = 'http://localhost:10008';
        $this->assertFalse(OUS_Debug::is_locked(), 'A local-looking value on just the direct DB read must still trip the escape hatch.');
    }

    /**
     * Must run after every "should lock" assertion above: defining
     * OUS_DEBUG_TOOLS_FORCE is permanent for the rest of this process,
     * same real-world shape as a wp-config.php constant. Named zzz_ to
     * sort last under PHPUnit's default declaration-order execution;
     * setUp() above skips anything that would otherwise run after it in
     * the same process.
     */
    public function test_zzz_force_override_unlocks_even_production(): void {
        define('OUS_DEBUG_TOOLS_FORCE', true);
        $this->assertFalse(OUS_Debug::is_locked(), 'OUS_DEBUG_TOOLS_FORCE must override even a genuinely production-looking environment -- the documented, deliberate escape hatch.');
    }
}
