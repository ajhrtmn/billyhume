<?php
/**
 * Plugin Name: BH Streaming
 * Description: An iTunes-like personal streaming library — releases, genres, shareable playlists, likes, lyrics, multi-quality audio, EQ, a visualizer, local-file import, a content-based recommendation engine, a gatekept RSS aggregator, shuffle/queue and shared-listening Jam sessions, and an aggregate artist metrics dashboard — installable as a PWA with reliable background audio.
 * Version:     0.5.2
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.5.1 — logging depth pass: BHS_Feeds::check_external_track_health()
// previously updated a track's health status with zero log trace —
// the only way to discover a dead external feed was manually browsing
// post meta. Now logs an info/warning entry on every ok<->down/degraded
// TRANSITION (not every check, which runs on a schedule and would
// otherwise flood the log). Standing caveat: reasoning/brace-balance-
// checked only, not run against a real WordPress+MySQL install.
// 0.5.2 — BHS_Stats::record_play()/record_skip() (class-stats.php) now
// also emit BH_Event 'bhs/play'/'bhs/skip' events (own-ur-shit's new
// BH_Event envelope, class-event.php) alongside the existing
// bhs_daily_stats aggregate rollup — additive only, the artist
// dashboard's own data path is unchanged. See
// EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6. Standing caveat:
// reasoning/brace-balance-checked only, not run against a real
// WordPress+MySQL install.
define('BHS_VER',  '0.5.2');
define('BHS_PATH', plugin_dir_path(__FILE__));
define('BHS_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note, still true at this version: one site, one artist's own
 * catalog plus whatever OTHER feeds that artist explicitly chooses to
 * feature (see class-feeds.php) — not open federation. Real ActivityPub
 * Follow/Accept (anyone can follow anyone) needs a shared identity layer
 * this plugin doesn't have of its own — not open federation.
 */
foreach (['env', 'activator', 'post-types', 'admin', 'api', 'pwa', 'player', 'likes', 'playlists', 'recommendations', 'feeds', 'style-surface', 'crm-integration', 'import', 'jam', 'stats', 'audio-hash'] as $f) {
    require_once BHS_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own table/default pages, neither of which touches the
// identity/style classes this plugin depends on for its actual
// features, so there's nothing here that can fatal-error even if the
// dependency below turns out to be missing.
register_activation_hook(__FILE__, ['BHS_Activator', 'activate']);

// AIFF isn't in WordPress core's default allowed-upload mime list (core
// ships mp3/m4a/ogg/wav/wma but not aif/aiff) — without this, an
// artist's or listener's .aif/.aiff file is silently rejected by both
// wp.media's audio picker (Quality Encodes, track audio) and
// class-import.php's media_handle_upload() call, with no obvious reason
// why. A plain global filter, safe to register unconditionally (no
// dependency on the core plugin or any class from it) — it only ever
// widens what WordPress itself will accept.
add_filter('upload_mimes', function ($mimes) {
    $mimes['aif|aiff'] = 'audio/aiff';
    return $mimes;
});

// Belt-and-suspenders alongside upload_mimes above: some PHP fileinfo
// builds don't confidently sniff .aiff's real content type, which can
// make wp_check_filetype_and_ext() (the deeper check media_handle_upload
// runs, independent of the extension whitelist above) still reject an
// otherwise-legitimate AIFF as a mismatch. If the extension is aif/aiff
// and core's own sniffing came back empty, trust the extension rather
// than blocking a real, common lossless format artists actually use.
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename) {
    if (empty($data['ext']) && preg_match('/\.aiff?$/i', $filename)) {
        $data['ext'] = 'aiff';
        $data['type'] = 'audio/aiff';
    }
    return $data;
}, 10, 3);

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
    add_action('init',          ['BHS_Stats', 'init']);
    add_action('rest_api_init', ['BHS_API', 'register_routes']);
    add_action('rest_api_init', ['BHS_API', 'add_cors_headers']);
    add_action('rest_api_init', ['BHS_PWA', 'register_routes']);
    add_action('rest_api_init', ['BHS_Likes', 'register_routes']);
    add_action('rest_api_init', ['BHS_Playlists', 'register_routes']);
    add_action('rest_api_init', ['BHS_Recommendations', 'register_routes']);
    add_action('rest_api_init', ['BHS_Feeds', 'register_routes']);
    add_action('rest_api_init', ['BHS_Import', 'register_routes']);
    add_action('rest_api_init', ['BHS_Jam', 'register_routes']);
    add_action('wp_head',       ['BHS_PWA', 'print_head_tags']);

    // Optional: if the core's job queue is active, each feed source's
    // sync runs as its own queued job instead of all of them running
    // inline in one cron tick — see BHS_Feeds::sync_all()'s docblock.
    // A plain class_exists() guard, never a hard dependency — this
    // plugin works identically on a core version without OUS_Jobs.
    if (class_exists('OUS_Jobs')) {
        OUS_Jobs::register('bhs_sync_one_feed', ['BHS_Feeds', 'sync_one_job']);
    }
});
