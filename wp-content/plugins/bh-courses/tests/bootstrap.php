<?php
/**
 * Deliberately NOT a WordPress install — see own-ur-shit/tests/bootstrap.php
 * for the full reasoning. The short version: `if (!defined('ABSPATH')) exit;`
 * sits at the top of every class file in this ecosystem, so ABSPATH must
 * be defined before any `require` of a class file, or the require
 * silently kills the whole test process.
 *
 * BHC_Steps::save() touches three real WordPress sanitization functions
 * (wp_kses_post, sanitize_text_field, esc_url_raw) even on its pure-logic
 * path — these stubs are intentionally NOT full reimplementations of
 * WordPress's actual behavior (that would be testing our stub, not our
 * code), just close enough approximations that the SANITIZATION-SHAPE
 * assertions in StepsSanitizationTest (does a step with no valid choices
 * get dropped, does an out-of-range correct_index get clamped, etc.)
 * are still meaningful. Anything that depends on WordPress's REAL
 * escaping specifics belongs in a proper WP-integration test suite
 * (e.g. using the official wp-phpunit scaffolding against a real DB),
 * not here.
 */
define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($str) {
        // Real wp_kses_post() allows a broad, specific safe-HTML
        // allowlist; this stub only strips <script>/<style> so tests can
        // assert "dangerous tags don't survive" without needing WP's
        // real (large) default allowlist tables loaded.
        return preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', (string) $str);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        $url = trim((string) $url);
        if ($url === '') return '';
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
}
// BHC_Steps::get()/get_step()/count() call get_post_meta(); nothing in
// this suite exercises those (they're pure passthrough over WP's own
// postmeta API, not logic worth testing independent of a real DB), but
// the class file needs the symbol to exist to parse/autoload cleanly.
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) { return []; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) { return true; }
}

require_once dirname(__DIR__) . '/includes/class-steps.php';
