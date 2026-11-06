<?php
/**
 * Plugin Name: BH Streaming
 * Description: An iTunes-like personal streaming library — releases, genres, shareable playlists, likes, lyrics, multi-quality audio, EQ, a visualizer, local-file import, a content-based recommendation engine, a gatekept RSS aggregator, shuffle/queue and shared-listening Jam sessions, and an aggregate artist metrics dashboard — installable as a PWA with reliable background audio.
 * Version:     0.6.3
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHS_VER',  '0.6.3');

define('BHS_PATH', plugin_dir_path(__FILE__));
define('BHS_URL',  plugin_dir_url(__FILE__));

/**
 * Scope: one artist's catalog plus feeds that artist chooses to feature
 * (class-feeds.php), not open federation.
 */
foreach (['tables', 'env', 'activator', 'post-types', 'isrc', 'admin', 'pro-wizard', 'api', 'pwa', 'player', 'likes', 'fan-library', 'playlists', 'recommendations', 'feeds', 'style-surface', 'crm-integration', 'import', 'jam', 'stats', 'audio-hash', 'blocks', 'test-suite', 'chapters', 'booklet', 'video-post-types', 'privacy'] as $f) {
    require_once BHS_PATH . "includes/class-$f.php";
}

// Safe unconditionally: activation only creates this plugin's tables/pages.
register_activation_hook(__FILE__, ['BHS_Activator', 'activate']);

// WHY: core's upload mime list lacks aif/aiff, so wp.media and
// media_handle_upload() would silently reject AIFF files.
add_filter('upload_mimes', function ($mimes) {
    $mimes['aif|aiff'] = 'audio/aiff';
    return $mimes;
});

// Some fileinfo builds can't sniff AIFF, failing wp_check_filetype_and_ext();
// trust an aif/aiff extension when core's sniff came back empty.
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename) {
    if (empty($data['ext']) && preg_match('/\.aiff?$/i', $filename)) {
        $data['ext'] = 'aiff';
        $data['type'] = 'audio/aiff';
    }
    return $data;
}, 10, 3);

/**
 * WHY: dependency checks run on plugins_loaded, not at file-parse time,
 * since plugins load alphabetically and the core may not be read yet.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Streaming</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHS_Activator::maybe_upgrade();

    add_action('admin_init',    ['BHS_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BHS_PostTypes', 'register']);
    add_action('init',          ['BHS_Admin', 'init']);
    add_action('init',          ['BHS_ISRC', 'init']);
    add_action('init',          ['BHS_PROWizard', 'init']);
    add_action('init',          ['BHS_Player', 'init']);
    // WHY: hooked at 'init'; wp_register_script() earlier logs a "called incorrectly" notice.
    add_action('init',          ['BHS_Blocks', 'init']);
    add_action('init',          ['BHS_PWA', 'init']);
    add_action('init',          ['BHS_Feeds', 'init']);
    add_action('init',          ['BHS_StyleSurface', 'init']);
    add_action('init',          ['BHS_CRMIntegration', 'init']);
    add_action('init',          ['BHS_Stats', 'init']);
    add_action('init',          ['BHS_Privacy', 'init']);
    add_action('init',          ['BHS_Chapters', 'init']);
    add_action('init',          ['BHS_Booklet', 'init']);
    add_action('init',          ['BHS_VideoPostTypes', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHS_TestSuite', 'init']);
    add_action('rest_api_init', ['BHS_API', 'register_routes']);
    add_action('rest_api_init', ['BHS_API', 'add_cors_headers']);
    add_action('rest_api_init', ['BHS_PWA', 'register_routes']);
    add_action('rest_api_init', ['BHS_Likes', 'register_routes']);
    add_action('rest_api_init', ['BHS_FanLibrary', 'register_routes']);
    add_action('rest_api_init', ['BHS_Playlists', 'register_routes']);
    add_action('rest_api_init', ['BHS_Recommendations', 'register_routes']);
    add_action('rest_api_init', ['BHS_Feeds', 'register_routes']);
    add_action('rest_api_init', ['BHS_Import', 'register_routes']);
    add_action('rest_api_init', ['BHS_Jam', 'register_routes']);
    add_action('wp_head',       ['BHS_PWA', 'print_head_tags']);

    // Optional: with OUS_Jobs, each feed source syncs as its own job (see
    // BHS_Feeds::sync_all()); class_exists()-guarded.
    if (class_exists('OUS_Jobs')) {
        OUS_Jobs::register('bhs_sync_one_feed', ['BHS_Feeds', 'sync_one_job']);
    }
});
