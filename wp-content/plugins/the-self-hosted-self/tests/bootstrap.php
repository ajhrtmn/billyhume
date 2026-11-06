<?php
/**
 * Pure-logic unit tests, deliberately not a WordPress install.
 * WHY: ABSPATH is defined first, because every class file starts with
 * `if (!defined('ABSPATH')) exit;` and would silently end the test process.
 */
define('ABSPATH', __DIR__ . '/');

// Only so the file parses; tests pass an explicit $secret, which skips this lookup.
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) { return ''; }
}

require_once dirname(__DIR__) . '/includes/class-two-factor.php';

/**
 * Calls BHI_TwoFactor's private static methods so the algorithm is tested below
 * the public verify_code() entry point.
 */
function bhi_2fa_call_private($method, ...$args) {
    $ref = new ReflectionMethod('BHI_TwoFactor', $method);
    // No setAccessible(true) call — a no-op (and deprecated) on PHP 8.1+,
    // where ReflectionMethod can invoke a private method directly.
    return $ref->invokeArgs(null, $args);
}
