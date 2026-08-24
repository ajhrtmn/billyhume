<?php
/**
 * Plugin Name: BH Social
 * Description: Social/marketing platform integrations — organic cross-posting + stats (YouTube, Twitch, Meta/Instagram, TikTok) behind a BH_SocialPlatform interface, plus paid ad-campaign draft-capture (Roku, Spotify, Amazon DSP, Samsung, Vizio) behind a separate BH_AdsPlatform interface. Depends only on The Self-Hosted Self's shared identity and job queue.
 * Version:     0.3.4
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// 0.3.4 — Ecosystem quality Phase 2, brick 7/13: added native return/
// parameter types across all 16 includes files (233 findings, both
// mechanical level-6 categories) — the most repetitive brick so far.
// Both interfaces (BH_SocialPlatform, BH_AdsPlatform) got typed method
// contracts; all 9 implementing classes (YouTube/Twitch/Meta/TikTok,
// Roku/Spotify/Amazon-DSP/Samsung/Vizio) matched to them via the same
// mechanical signature pattern, since all 4 organic platforms and all
// 5 ad platforms genuinely share one shape each. Purely additive
// typing, no behavior change. This plugin is now clean at PHPStan
// level 6 in isolation.
// NOT runtime-verified against a live install.
// 0.3.3 — This plugin's first PHPStan pass (newly added to
// phpstan.neon's scanned paths this round). One finding, confirmed as a
// real stub gap rather than a bug and scoped-ignored: $wpdb->last_error
// has no property type declared anywhere in php-stubs/wordpress-stubs,
// so a plain `if ($wpdb->last_error)` truthy check after an earlier
// reset in the same file gets misread as permanently false — same root
// cause as the ecosystem-wide `!== ''` ignore rule already in
// phpstan.neon, this is the plain-truthy-check variant of it.
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run. `php -l` clean.

// 0.1.0 — scaffold (2026-08-01): YouTube decided as the first platform
// specifically because it has no app-review gate (unlike Meta/TikTok) —
// the OAuth app itself is created in Google Cloud Console, but using it
// against your OWN channel needs no approval, making it the fastest
// real thing to integrate first behind BH_SocialPlatform
// (class-social-platform.php) so Twitch/Meta/TikTok are each one more
// implementation class, not a rewrite.
//
// 0.2.0 — Twitch added (chat-announcement cross-post + follower/live
// stats via Helix).
//
// 0.3.0 — Meta (Instagram, via a linked Facebook Page) and TikTok
// added, completing the organic BH_SocialPlatform group. Also adds the
// BH_AdsPlatform group (class-ads-platform.php) — deliberately a
// SEPARATE interface, not one more BH_SocialPlatform implementation,
// since paid ad-campaign management is a genuinely different shape
// (budget/targeting/billing, not caption/attachment). Roku Ads Manager
// was the platform named in the original scoping conversation; a
// follow-up research pass added Spotify Ad Studio (a strong fit — $250
// minimum vs Roku's $500, built around exactly this ecosystem's
// music-listening audience) plus Amazon DSP/Samsung Ads/Vizio Ads for
// completeness, though those three are honestly far less accessible to
// an independent artist (see each class's own docblock). None of the
// five have a confirmed public self-serve REST API, so each is a
// draft-capture-plus-handoff tool (BHSO_RokuAds's docblock explains the
// reasoning in full) rather than a simulated live integration.
//
// 0.3.1 — every organic-platform section and the whole ads group now
// carry an OUS_Badge (the-self-hosted-self's new shared alpha/beta/experimental
// helper, added this pass) flagging that none of this has been
// exercised against a real connected account yet — the code is real
// and correct against each platform's documented API, but "verified
// live" is a distinct, not-yet-true claim this badge deliberately
// avoids implying.
define('BHSO_PATH', plugin_dir_path(__FILE__));
define('BHSO_URL',  plugin_dir_url(__FILE__));
define('BHSO_VER',  '0.3.4');

foreach ([
    'tables', 'activator',
    'social-platform', 'youtube-platform', 'twitch-platform', 'meta-platform', 'tiktok-platform', 'platform-registry',
    'ads-platform', 'roku-ads', 'spotify-ads', 'amazon-dsp-ads', 'samsung-ads', 'vizio-ads', 'ads-platform-registry',
    'admin', 'test-suite',
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
