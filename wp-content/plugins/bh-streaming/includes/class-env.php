<?php
if (!defined('ABSPATH')) exit;

/**
 * Streaming is being actively built out but isn't what's being pushed
 * live right now — the dev/planning conversation this came out of
 * wanted it to keep working exactly as-is on dev/staging (so building
 * on it doesn't stop), while staying out of the way in production: no
 * dashboard card, no admin menus, no front-end shortcode output.
 *
 * Deliberately NOT a deactivation. Nothing here touches whether the
 * plugin is active, its DB tables, its cron jobs, or its REST routes —
 * this only gates the three visible surfaces above. Reusing the exact
 * same convention OUS_Debug::is_locked() already uses for the same
 * reason: wp_get_environment_type() defaults to 'production' when
 * WP_ENVIRONMENT_TYPE isn't set, so an unconfigured site fails safe
 * (hidden) rather than accidentally shipping a half-built feature to
 * real visitors.
 *
 * Override: define('BHS_FORCE_VISIBLE', true) in wp-config.php for a
 * production site that's deliberately ready to turn streaming back on
 * — flip this one constant rather than touching any of the three call
 * sites below.
 */
class BHS_Env {
    public static function hidden_in_production() {
        if (defined('BHS_FORCE_VISIBLE') && BHS_FORCE_VISIBLE) return false;
        return !function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production';
    }
}
