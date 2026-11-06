<?php
/**
 * Deliberately not a WordPress install. ABSPATH must be defined before any class file is
 * required (each starts with `if (!defined('ABSPATH')) exit;`).
 * The WP function stubs are approximations, just enough for the sanitization-shape tests;
 * real escaping behavior belongs in a WP integration suite.
 */
define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($str) {
        // Stub only strips <script>/<style>, enough to assert dangerous tags don't survive.
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
// Used by BHC_Steps::save()'s chord-chart branch.
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return trim(preg_replace('/[\r\t ]+/', ' ', strip_tags((string) $str)));
    }
}
// Used by BHC_Steps::save()'s callout branch.
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($str) {
        return trim(strip_tags((string) $str));
    }
}
// BHC_Steps::get()/get_step()/count() reference get_post_meta(); not exercised here.
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) { return []; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) { return true; }
}

require_once dirname(__DIR__) . '/includes/class-steps.php';
