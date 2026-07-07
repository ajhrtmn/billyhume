<?php
/**
 * Plugin Name: BH Registry
 * Description: A global, decentralized artist-link registry — a cross-instance directory of artists' public ActivityPub/RSS-Podcasting-2.0 links, submitted voluntarily and verified by domain ownership. Stores links and metadata only; never media.
 * Version:     0.1.1
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: Own Ur Shit
 */
if (!defined('ABSPATH')) exit;

define('BHR_VER',  '0.1.1');
define('BHR_PATH', plugin_dir_path(__FILE__));
define('BHR_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note: this plugin is deliberately independent of bh-streaming.
 * Its whole value is being adoptable by someone with no streaming app at
 * all — a bare WordPress site, a future native app, or a plain fan-facing
 * search page. bh-streaming (or anything else) is a CONSUMER of this
 * plugin's REST API (specifically GET /bhr/v1/artists/{id}/feed-url),
 * never a dependency of it, and this plugin never requires bh-streaming
 * to exist. See class-streaming-bridge.php for the one-directional,
 * entirely-optional integration, modeled on bh-streaming's own
 * class-crm-integration.php.
 */
foreach (['links', 'activator', 'verification', 'api', 'admin', 'style-surface', 'debug', 'frontend', 'streaming-bridge'] as $f) {
    require_once BHR_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own tables, which don't touch the core's identity/style
// classes this plugin depends on for its actual admin UI.
register_activation_hook(__FILE__, ['BHR_Activator', 'activate']);

/**
 * Gated behind plugins_loaded, never checked at file-parse time — see
 * bh-streaming's bh-streaming.php for the full rationale (WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check up here could run before a genuinely-active
 * dependency's file has actually been read yet on a given request).
 * plugins_loaded is a hard guarantee every active plugin's main file has
 * already loaded by the time callbacks registered on it run.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Registry</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHR_Activator::maybe_upgrade();

    // A site that already had this plugin active before this version
    // (no default-pages logic existed until now) still gets the page —
    // maybe_create_default_pages() is itself version-gated via
    // bhr_pages_version, so it runs exactly once for such a site rather
    // than only ever firing on a fresh activation going forward.
    add_action('admin_init',    ['BHR_Activator', 'maybe_create_default_pages']);

    add_action('init',          ['BHR_Frontend', 'init']);
    add_action('init',          ['BHR_StyleSurface', 'init']);
    add_action('init',          ['BHR_Debug', 'init']);
    add_action('init',          ['BHR_Admin', 'init']);
    add_action('init',          ['BHR_StreamingBridge', 'init']);
    add_action('rest_api_init', ['BHR_API', 'register_routes']);
    add_action('rest_api_init', ['BHR_API', 'add_cors_headers']);

    // Periodic re-check of previously-verified links — control can lapse
    // (domain sold, DNS changed, well-known file removed) and a
    // "verified" badge that never gets re-checked stops meaning anything.
    if (!wp_next_scheduled('bhr_recheck_links')) {
        wp_schedule_event(time(), 'daily', 'bhr_recheck_links');
    }
    add_action('bhr_recheck_links', ['BHR_Verification', 'recheck_all']);

    // Optional: if the core's job queue is active, each link re-check
    // runs as its own queued job instead of all 50 running inline in one
    // cron tick — see BHR_Verification::recheck_all()'s docblock. A
    // plain class_exists() guard, never a hard dependency — this plugin
    // still works identically on a core version without OUS_Jobs.
    if (class_exists('OUS_Jobs')) {
        OUS_Jobs::register('bhr_recheck_one_link', ['BHR_Verification', 'recheck_one']);
    }
});
