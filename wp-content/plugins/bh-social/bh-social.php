<?php
/**
 * Plugin Name: BH Social
 * Description: Social/marketing platform integrations — organic cross-posting + stats (YouTube, Twitch, Meta/Instagram, TikTok) behind a BH_SocialPlatform interface, plus paid ad-campaign draft-capture (Roku, Spotify, Amazon DSP, Samsung, Vizio) behind a separate BH_AdsPlatform interface. Depends only on The Self-Hosted Self's shared identity and job queue.
 * Version:     0.4.0
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).
define('BHSO_PATH', plugin_dir_path(__FILE__));
define('BHSO_URL',  plugin_dir_url(__FILE__));
define('BHSO_VER',  '0.4.0');

foreach ([
    'tables', 'activator',
    'social-platform', 'youtube-platform', 'twitch-platform', 'meta-platform', 'tiktok-platform', 'platform-registry',
    'ads-platform', 'roku-ads', 'spotify-ads', 'amazon-dsp-ads', 'samsung-ads', 'vizio-ads', 'ads-platform-registry',
    'admin', 'auto-announce', 'test-suite',
] as $f) {
    require_once BHSO_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHSO_Activator', 'activate']);
add_action('plugins_loaded', ['BHSO_Activator', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Social</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHSO_Admin', 'init']);
    add_action('init', ['BHSO_YouTube', 'init']);
    add_action('init', ['BHSO_Twitch', 'init']);
    add_action('init', ['BHSO_Meta', 'init']);
    add_action('init', ['BHSO_TikTok', 'init']);
    add_action('init', ['BHSO_AutoAnnounce', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHSO_TestSuite', 'init']);

    add_action('admin_post_bhso_youtube_oauth_callback', ['BHSO_YouTube', 'handle_oauth_callback']);
    add_action('admin_post_bhso_twitch_oauth_callback', ['BHSO_Twitch', 'handle_oauth_callback']);
    add_action('admin_post_bhso_meta_oauth_callback', ['BHSO_Meta', 'handle_oauth_callback']);
    add_action('admin_post_bhso_tiktok_oauth_callback', ['BHSO_TikTok', 'handle_oauth_callback']);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(BHSO_YouTube::STATS_CRON_HOOK);
    wp_clear_scheduled_hook(BHSO_Twitch::STATS_CRON_HOOK);
    wp_clear_scheduled_hook(BHSO_Meta::STATS_CRON_HOOK);
    wp_clear_scheduled_hook(BHSO_TikTok::STATS_CRON_HOOK);
});
