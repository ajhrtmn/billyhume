<?php
/**
 * Plugin Name: BH Live
 * Description: Two-way interactive live streaming — a thin WordPress-side integration over a self-hosted Owncast server (v1), behind an engine abstraction so a future swap (OvenMediaEngine, a different chat mechanism) is a new class, not a rewrite. Depends only on Own Ur Shit's shared identity and style tokens.
 * Version:     0.1.0
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
define('BHL_VER',  '0.1.0');

foreach (['stream-engine', 'admin'] as $f) {
    require_once BHL_PATH . "includes/class-$f.php";
}

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Live</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHL_Admin', 'init']);
});
