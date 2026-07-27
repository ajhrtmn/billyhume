<?php
/**
 * Plugin Name: BH Live
 * Description: Two-way interactive live streaming — a thin WordPress-side integration behind an engine abstraction, with a choice of a self-hosted Owncast server (free, own hosting) or Cloudflare Stream Live (managed, metered, video-only). Depends only on Own Ur Shit's shared identity and style tokens.
 * Version:     0.7.0
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.1.0 — scaffold. Video/live-streaming scoping pass (2026-07-26,
// wondrous-mixing-forest.md): Owncast decided for v1 specifically
// because it bundles chat + a web player + RTMP ingest in one
// deployable unit, making it the easiest real thing to integrate
// first — behind bh-live's own BHL_StreamEngine interface
// (class-stream-engine.php) so a later OvenMediaEngine implementation
// is a second class, not a rewrite. Chat is deliberately NOT
// abstracted yet in this scaffold — Owncast's own bundled chat/embed
// covers v1 entirely; a separate BHL_Chat interface (so a custom
// polling-based chat, matching bh-streaming's own Jam sessions, can
// later replace Owncast's bundled one independent of the video engine)
// is real future work, not needed for this first slice.
//
// A live stream genuinely cannot run on ordinary shared hosting —
// real-time RTMP ingest/transcoding needs its own dedicated box. This
// plugin is intentionally just the thin WordPress-side integration
// layer (read live status, embed the player, manage the connection
// settings) — see BHL_OwncastEngine's own docblock for exactly what it
// does and doesn't do.
define('BHL_PATH', plugin_dir_path(__FILE__));
define('BHL_URL',  plugin_dir_url(__FILE__));
define('BHL_VER',  '0.7.0');

foreach (['stream-engine', 'chat', 'cloudflare-engine', 'engine-registry', 'host-provisioner', 'fly-provisioner', 'post-types', 'streams', 'admin', 'api', 'live-player', 'test-suite'] as $f) {
    require_once BHL_PATH . "includes/class-$f.php";
}

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Live</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHL_PostTypes', 'register']);
    add_action('init', ['BHL_Streams', 'init']);
    add_action('init', ['BHL_Admin', 'init']);
    add_action('init', ['BHL_Player', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHL_TestSuite', 'init']);
    add_action('rest_api_init', ['BHL_API', 'register_routes']);
});

// Cleared on deactivation, same convention every other cron-scheduling
// plugin in this ecosystem follows (bh-streaming's BHS_Feeds does not
// currently clear its own — worth revisiting there too, out of scope
// here) — a deactivated plugin shouldn't leave a dangling scheduled
// event calling a hook nothing will ever handle again.
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(BHL_Streams::CRON_HOOK);
});
