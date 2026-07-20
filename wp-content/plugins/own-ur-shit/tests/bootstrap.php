<?php
/**
 * Deliberately NOT a WordPress install. These are pure-logic unit tests
 * for code paths that don't touch $wpdb or a real WP runtime at all —
 * TOTP math, base32, time-window tolerance — not integration tests
 * pretending to be something bigger. That's a real, useful category of
 * test on its own: the 2FA algorithm being CORRECT is exactly the kind
 * of thing you want verified against a published reference (RFC 6238),
 * independent of whether WordPress itself is even installed.
 *
 * Two things every class file in this ecosystem needs before it'll even
 * PARSE, let alone run — every single file opens with
 * `if (!defined('ABSPATH')) exit;`, so this has to run first or the
 * `require` below silently calls exit() and kills the whole test
 * process with zero explanation. Easy to lose an hour to if you don't
 * know to look for it.
 */
define('ABSPATH', __DIR__ . '/');

// The one WP function BHI_TwoFactor's ALGORITHMIC core would touch —
// but every test below calls verify_code($user_id, $code, $secret)
// with an explicit $secret, which short-circuits the `get_user_meta()`
// lookup entirely (`$secret ?: get_user_meta(...)`), so this stub
// exists only so the file parses cleanly, not because any test here
// actually exercises it. If a future test DOES need it, this is where
// to make it configurable rather than a fixed return value.
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) { return ''; }
}

require_once dirname(__DIR__) . '/includes/class-two-factor.php';

/**
 * A tiny helper for invoking BHI_TwoFactor's PRIVATE static methods
 * directly — base32_encode/decode and totp_at() are intentionally not
 * part of the public API (nothing outside this class needs them), but
 * testing the algorithm at that level, not just through the public
 * verify_code() entry point, is exactly what catches a subtle bit-
 * packing bug that a black-box test could miss entirely if it happened
 * to not affect the specific test vectors used at the public-API level.
 */
function bhi_2fa_call_private($method, ...$args) {
    $ref = new ReflectionMethod('BHI_TwoFactor', $method);
    // No setAccessible(true) call — a no-op (and deprecated) on PHP 8.1+,
    // where ReflectionMethod can invoke a private method directly.
    return $ref->invokeArgs(null, $args);
}
