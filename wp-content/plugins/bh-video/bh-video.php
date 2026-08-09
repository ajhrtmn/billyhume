<?php
/**
 * Plugin Name: BH Video
 * Description: A standalone video catalog and player — its own CPT, taxonomy, and browse/playback SPA, independent of bh-streaming's audio catalog. Depends only on Own Ur Shit's shared identity and style tokens.
 * Version:     0.4.2
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.4.2 — Ecosystem quality Phase 2, brick 2/13: added native return
// types and parameter types across all 7 includes files (class-
// activator, class-admin, class-api, class-chapters, class-post-types,
// class-test-suite, class-video-player — 48 findings, all the two
// mechanical PHPStan level-6 categories). One small real fix surfaced
// along the way: BHV_API::video_url_for() declared a `string` return
// but wp_get_attachment_url() can return `false` for a missing
// attachment — added a `?: ''` fallback so the declared type is
// actually honest, not just asserted. Everything else is purely
// additive typing, no behavior change. This plugin is now clean at
// PHPStan level 6 in isolation; phpstan.neon itself stays at level 5
// ecosystem-wide until all 13 bricks land.
// NOT runtime-verified against a live install.
// 0.4.1 — This plugin's first PHPStan pass (newly added to phpstan.neon's
// scanned paths this round). Two findings: esc_attr() needed a string,
// not the int $vid it was given directly. Real, if minor, bug in
// class-post-types.php: register_taxonomy()'s 'show_in_menu' is a plain
// bool for taxonomies (unlike post types, which do support a string
// parent-slug) — confirmed by reading wp-admin/menu.php's real
// consumption of it directly. Passing self::MENU_PARENT there just
// evaluated truthy; changed to `true`, which achieves the exact same
// real placement (WP already nests a taxonomy under its associated post
// type's own menu automatically), correctly this time.
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run and by reading WP core source
// directly. `php -l` clean.

// 0.1.0 — scaffold. Video/live-streaming scoping pass (2026-07-26): a
// standalone video platform, not tied to a track/release the way
// bh-streaming's own bhs_video (class-video-post-types.php) is — that
// CPT stays exactly what it always was, a 1:1 promotional-video
// wrapper for a release. bh-video is the real catalog: its own
// top-level admin menu, its own taxonomy, its own REST-backed
// browse/playback SPA, following bhs_track's shape
// (bh-streaming/includes/class-post-types.php) as the closest existing
// reference.
define('BHV_PATH', plugin_dir_path(__FILE__));
define('BHV_URL',  plugin_dir_url(__FILE__));
define('BHV_VER',  '0.4.2');

/**
 * A genuine PEER to bh-streaming/bh-courses/bh-feedback — depends only
 * on own-ur-shit (shared identity, roles/capabilities, style tokens).
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

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Video</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
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
