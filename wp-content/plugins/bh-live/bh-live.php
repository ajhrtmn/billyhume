<?php
/**
 * Plugin Name: BH Live
 * Description: Two-way interactive live streaming — a thin WordPress-side integration behind an engine abstraction, with a choice of a self-hosted Owncast server (free, own hosting) or Cloudflare Stream Live (managed, metered, video-only). Depends only on The Self-Hosted Self's shared identity and style tokens.
 * Version:     0.9.5
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).
define('BHL_PATH', plugin_dir_path(__FILE__));
define('BHL_URL',  plugin_dir_url(__FILE__));
define('BHL_VER',  '0.9.5');

foreach (['tables', 'activator', 'stream-engine', 'chat', 'polling-chat', 'cloudflare-engine', 'workers-chat', 'engine-registry', 'host-provisioner', 'fly-provisioner', 'post-types', 'streams', 'admin', 'api', 'overlay', 'automation', 'live-player', 'test-suite', 'privacy'] as $f) {
    require_once BHL_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHL_Activator', 'activate']);
add_action('plugins_loaded', ['BHL_Activator', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Live</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHL_PostTypes', 'register']);
    add_action('init', ['BHL_Streams', 'init']);
    add_action('init', ['BHL_Privacy', 'init']);
    add_action('init', ['BHL_Admin', 'init']);
    add_action('init', ['BHL_Player', 'init']);
    add_action('init', ['BHL_PollingChat', 'init']);
    add_action('init', ['BHL_Automation', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHL_TestSuite', 'init']);
    add_action('rest_api_init', ['BHL_API', 'register_routes']);
    add_action('rest_api_init', ['BHL_Overlay', 'register_routes']);
    add_action('rest_api_init', ['BHL_Automation', 'register_routes']);
});

// Cleared on deactivation, same convention every other cron-scheduling
// plugin in this ecosystem follows (bh-streaming's BHS_Feeds does not
// currently clear its own — worth revisiting there too, out of scope
// here) — a deactivated plugin shouldn't leave a dangling scheduled
// event calling a hook nothing will ever handle again.
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(BHL_Streams::CRON_HOOK);
});
