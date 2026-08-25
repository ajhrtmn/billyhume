<?php
/**
 * Plugin Name: BH Video
 * Description: A standalone video catalog and player — its own CPT, taxonomy, and browse/playback SPA, independent of bh-streaming's audio catalog. Depends only on The Self-Hosted Self's shared identity and style tokens.
 * Version:     0.4.3
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).
define('BHV_PATH', plugin_dir_path(__FILE__));
define('BHV_URL',  plugin_dir_url(__FILE__));
define('BHV_VER',  '0.4.3');

/**
 * A genuine PEER to bh-streaming/bh-courses/bh-feedback — depends only
 * on the-self-hosted-self (shared identity, roles/capabilities, style tokens).
 * Storage/CDN needs zero new code: a bhv_video's file is a standard WP
 * attachment, so wp_get_attachment_url() already gets CDN offload for
 * free once the Advanced Media Offloader is configured via
 * OUS_MediaWizard — same one-line abstraction BHS_API::audio_url_for()
 * already proves for audio.
 */
foreach (['activator', 'post-types', 'admin', 'api', 'video-player', 'chapters', 'test-suite'] as $f) {
    require_once BHV_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHV_Activator', 'activate']);
add_action('plugins_loaded', ['BHV_Activator', 'maybe_upgrade']);
add_action('admin_init', ['BHV_Activator', 'maybe_create_default_pages']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Video</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHV_PostTypes', 'register']);
    add_action('init', ['BHV_Admin', 'init']);
    add_action('init', ['BHV_Player', 'init']);
    add_action('init', ['BHV_Chapters', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHV_TestSuite', 'init']);
    add_action('rest_api_init', ['BHV_API', 'register_routes']);
});
