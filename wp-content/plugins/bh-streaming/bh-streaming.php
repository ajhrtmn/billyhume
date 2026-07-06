<?php
/**
 * Plugin Name: BH Streaming
 * Description: An iTunes-like personal streaming library — releases, genres, playlists, likes, a content-based recommendation engine, and a gatekept RSS aggregator — installable as a PWA with reliable background audio.
 * Version:     0.3.0
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

define('BHS_VER',  '0.3.0');
define('BHS_PATH', plugin_dir_path(__FILE__));
define('BHS_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note, still true at this version: one site, one artist's own
 * catalog plus whatever OTHER feeds that artist explicitly chooses to
 * feature (see class-feeds.php) — not open federation. Real ActivityPub
 * Follow/Accept (anyone can follow anyone) needs a shared identity layer
 * this plugin doesn't have of its own — not open federation.
 */
foreach (['activator', 'post-types', 'admin', 'api', 'pwa', 'player', 'likes', 'playlists', 'recommendations', 'feeds', 'style-surface', 'crm-integration'] as $f) {
    require_once BHS_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own table/default pages, neither of which touches the
// identity/style classes this plugin depends on for its actual
// features, so there's nothing here that can fatal-error even if the
// dependency below turns out to be missing.
register_activation_hook(__FILE__, ['BHS_Activator', 'activate']);

/**
 * Gated behind plugins_loaded rather than checked directly here at
 * file-parse time — WordPress loads active plugins' files in
 * alphabetical folder order, so a direct class_exists() check at the
 * top of this file could run BEFORE the dependency's own file has even
 * been read yet on a given request, regardless of whether that
 * dependency is genuinely active (this specifically happened before:
 * "bh-streaming" sorts alphabetically ahead of what used to be a
 * separately-named dependency). plugins_loaded is a hard WordPress
 * guarantee — it only fires after EVERY active plugin's main file has
 * already been fully loaded — so checking there is reliable regardless
 * of naming or folder order.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Streaming</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHS_Activator::maybe_upgrade();

    add_action('admin_init',    ['BHS_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BHS_PostTypes', 'register']);
    add_action('init',          ['BHS_Admin', 'init']);
    add_action('init',          ['BHS_Player', 'init']);
    add_action('init',          ['BHS_PWA', 'init']);
    add_action('init',          ['BHS_Feeds', 'init']);
    add_action('init',          ['BHS_StyleSurface', 'init']);
    add_action('init',          ['BHS_CRMIntegration', 'init']);
    add_action('rest_api_init', ['BHS_API', 'register_routes']);
    add_action('rest_api_init', ['BHS_PWA', 'register_routes']);
    add_action('rest_api_init', ['BHS_Likes', 'register_routes']);
    add_action('rest_api_init', ['BHS_Playlists', 'register_routes']);
    add_action('rest_api_init', ['BHS_Recommendations', 'register_routes']);
    add_action('rest_api_init', ['BHS_Feeds', 'register_routes']);
    add_action('wp_head',       ['BHS_PWA', 'print_head_tags']);
});
