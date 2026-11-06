<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for OUS_Debug::is_locked(), the gate every "does real work"
 * Debug Tools section relies on. Pure logic: environment type, home_url() and
 * $wpdb are stubbed globals.
 *
 * NOTE: an unrecognized environment string currently reads as UNLOCKED; these
 * tests assert that real behavior. The function-missing branch (which does
 * lock) cannot be covered here, since the stub must exist for the other tests.
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
        // Baseline: all three signals look like a real production host.
        $GLOBALS['__ous_test_http_host'] = 'billyhume.wasmer.app';
        $GLOBALS['__ous_test_home_url'] = 'https://billyhume.wasmer.app';
        $GLOBALS['__ous_test_db_home'] = 'https://billyhume.wasmer.app';
        $_SERVER['HTTP_HOST'] = $GLOBALS['__ous_test_http_host'];

        if (defined('OUS_DEBUG_TOOLS_FORCE')) {
            // The constant cannot be undefined once set, so the test that sets it
            // runs last (test_zzz_).
            $this->markTestSkipped('OUS_DEBUG_TOOLS_FORCE already defined by an earlier test in this process.');
        }
    }

    public function test_production_with_a_real_looking_host_is_locked(): void {
        $this->assertTrue(OUS_Debug::is_locked(), 'A production environment type with a genuinely non-local host on every signal must lock.');
    }

    public function test_unrecognized_environment_string_is_NOT_locked(): void {
        // Documents current behavior (see file docblock).
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
        // A local host overrides WordPress's default 'production' environment.
        $GLOBALS['__ous_test_env_type'] = 'production';
        $GLOBALS['__ous_test_http_host'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $GLOBALS['__ous_test_home_url'] = 'http://localhost:10008';
        $GLOBALS['__ous_test_db_home'] = 'http://localhost:10008';
        $this->assertFalse(OUS_Debug::is_locked(), 'production env type + a localhost host on every signal must NOT lock -- this is the documented local-dev escape hatch.');
    }

    public function test_production_env_type_with_only_db_host_looking_local_is_not_locked(): void {
        // Any ONE of the three signals matching a local host is enough (an OR),
        // covering a stale cached home_url().
        $GLOBALS['__ous_test_env_type'] = 'production';
        $GLOBALS['__ous_test_http_host'] = 'billyhume.wasmer.app';
        $_SERVER['HTTP_HOST'] = 'billyhume.wasmer.app';
        $GLOBALS['__ous_test_home_url'] = 'https://billyhume.wasmer.app';
        $GLOBALS['__ous_test_db_home'] = 'http://localhost:10008';
        $this->assertFalse(OUS_Debug::is_locked(), 'A local-looking value on just the direct DB read must still trip the escape hatch.');
    }

    /**
     * Must run last: defining OUS_DEBUG_TOOLS_FORCE is permanent for the process.
     */
    public function test_zzz_force_override_unlocks_even_production(): void {
        define('OUS_DEBUG_TOOLS_FORCE', true);
        $this->assertFalse(OUS_Debug::is_locked(), 'OUS_DEBUG_TOOLS_FORCE must override even a genuinely production-looking environment -- the documented, deliberate escape hatch.');
    }
}
