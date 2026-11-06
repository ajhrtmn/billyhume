<?php
/**
 * Plugin Name: BH Registry
 * Description: A global, decentralized artist-link registry — a cross-instance directory of artists' public ActivityPub/RSS-Podcasting-2.0 links, submitted voluntarily and verified by domain ownership. Stores links and metadata only; never media.
 * Version:     0.1.21
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 * Ecosystem: The Self-Hosted Self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHR_VER',  '0.1.21');

define('BHR_PATH', plugin_dir_path(__FILE__));
define('BHR_URL',  plugin_dir_url(__FILE__));

/**
 * Independent of bh-streaming, which is only a consumer of the REST API; see class-streaming-bridge.php.
 */
foreach (['tables', 'links', 'activator', 'verification', 'wellknown', 'crawl', 'http-signature', 'activitypub', 'peers', 'api', 'admin', 'style-surface', 'debug', 'frontend', 'streaming-bridge', 'test-suite', 'discovery-test-suite'] as $f) {
    require_once BHR_PATH . "includes/class-$f.php";
}

// Safe unconditionally: activation only creates this plugin's own tables.
register_activation_hook(__FILE__, ['BHR_Activator', 'activate']);

/**
 * WHY plugins_loaded: plugins load alphabetically, so a parse-time class_exists() could miss an active dependency.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Registry</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHR_Activator::maybe_upgrade();

    // Also runs for already-active installs; version-gated, so it runs once.
    add_action('admin_init',    ['BHR_Activator', 'maybe_create_default_pages']);

    add_action('init',          ['BHR_Frontend', 'init']);
    add_action('init',          ['BHR_WellKnown', 'init']);
    add_action('init',          ['BHR_StyleSurface', 'init']);
    add_action('init',          ['BHR_Debug', 'init']);
    add_action('init',          ['BHR_Admin', 'init']);
    add_action('init',          ['BHR_Peers', 'init']);
    add_action('init',          ['BHR_StreamingBridge', 'init']);
    if (class_exists('OUS_TestRunner')) {
        add_action('init', ['BHR_TestSuite', 'init']);
        add_action('init', ['BHR_DiscoveryTestSuite', 'init']);
    }
    add_action('rest_api_init', ['BHR_API', 'register_routes']);
    add_action('rest_api_init', ['BHR_API', 'add_cors_headers']);

    // Daily re-check of verified links, since domain control can lapse.
    if (!wp_next_scheduled('bhr_recheck_links')) {
        wp_schedule_event(time(), 'daily', 'bhr_recheck_links');
    }
    add_action('bhr_recheck_links', ['BHR_Verification', 'recheck_all']);

    // Optional OUS_Jobs handlers; the plugin works without the job queue.
    if (class_exists('OUS_Jobs')) {
        OUS_Jobs::register('bhr_recheck_one_link', ['BHR_Verification', 'recheck_one']);
        // Crawl-discovered candidates use the same verification path as manual ones.
        OUS_Jobs::register('bhr_verify_gossip_candidate', ['BHR_Verification', 'recheck_one']);
        OUS_Jobs::register('bhr_crawl_one_peer', ['BHR_Crawl', 'crawl_one_peer']);
    }

    // Daily peer crawl (BHR_Crawl), which also serves as the peer liveness check.
    if (!wp_next_scheduled('bhr_peer_crawl')) {
        wp_schedule_event(time(), 'daily', 'bhr_peer_crawl');
    }



    // WebFinger/actor always resolve; the inbox and outbound federation need the layer enabled plus a relay.
    if (class_exists('BHR_ActivityPub')) {
        BHR_ActivityPub::init();
        if (!wp_next_scheduled('bhr_ap_relay_sync')) {
            wp_schedule_event(time(), 'daily', 'bhr_ap_relay_sync');
        }
    }
});
