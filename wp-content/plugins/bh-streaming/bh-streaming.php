<?php
/**
 * Plugin Name: BH Streaming
 * Description: An iTunes-like personal streaming library — releases, genres, shareable playlists, likes, lyrics, multi-quality audio, EQ, a visualizer, local-file import, a content-based recommendation engine, a gatekept RSS aggregator, shuffle/queue and shared-listening Jam sessions, and an aggregate artist metrics dashboard — installable as a PWA with reliable background audio.
 * Version:     0.5.5
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
// 0.5.3 — UX-AUDIT-2026-07.md: the "All Tracks" library view's bare
// "No tracks match." replaced with the shared
// BHY_Style::empty_state_html() component (own-ur-shit 3.4.82),
// pre-rendered server-side (class-player.php's BHSData.emptyStateZero/
// emptyStateFiltered) since this view is JS-rendered, not PHP — one
// source of truth with the same component bh-courses' catalog now
// uses, not a second bespoke JS empty state. player.js picks zero vs.
// filtered based on whether a search term or genre filter is
// currently active, same distinction the catalog already makes.
// RUNTIME-VERIFIED, with a real bug caught in the same pass: the
// component's <style> block was originally only embedded once per
// request, which broke the moment this exact swap-between-two-
// variants pattern replaced `library.innerHTML` a second time,
// silently destroying the first fragment's <style> tag along with it
// — see own-ur-shit 3.4.82's own changelog for the fix (the component
// now embeds its style on every call). Confirmed both variants render
// at the correct size on desktop and 375px mobile after the fix.
define('BHS_VER',  '0.5.5');

// 0.5.5 — real cross-browser gap, caught by a grounded browser-quirk
// audit of every first-party .css/.js file in the ecosystem (not
// guessed): .bhs-seek's WebKit thumb was intentionally sized to 0x0
// (the seek progress is drawn by a separate fill element, not the
// native thumb), but there was no ::-moz-range-thumb counterpart, so
// Firefox rendered its own native, VISIBLE slider thumb/track here
// while every other browser correctly showed none. Added the Firefox
// pseudo-elements (split into their own rule — a browser that doesn't
// recognize ::-moz-range-thumb drops the whole selector if it's
// comma-combined with -webkit-). Not verified against a real Firefox
// render in this session (only a Chromium-based preview pane was
// available) — the fix follows documented ::-moz-range-* syntax
// correctly, but flagging that it's unverified against the actual
// engine it targets.

// 0.5.4 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG
// shortcode-to-block conversion continues, following bh-monetization-
// woo (0.4.9-0.4.11) and bh-contest (3.5.0)'s same wp.serverSideRender
// pattern. One new block, 'bhs/player' (class-blocks.php, assets/js/
// bhs-blocks.js) — [bh_streaming] takes no attributes and BHS_Player::
// render() is a single fixed mount div, so the block needs neither
// attributes nor an Inspector picker. Old shortcode untouched. Respects
// BHS_Env::hidden_in_production() exactly like the shortcode always
// has (render_callback calls BHS_Player::render() directly).
// Same class of regression already caught once in bh-contest 3.5.0,
// fixed here BEFORE shipping rather than after: BHS_Player::
// maybe_enqueue()'s asset gate only ever checked has_shortcode(), which
// a block-authored page has none of — fixed with has_block() alongside
// it, same as bh-contest's three blocks got.
// RUNTIME-VERIFIED end to end on this actual install: confirmed the
// block registered and rendering the real player markup via the exact
// REST block-renderer endpoint the editor calls, then built a real page
// with the block and loaded it in a live browser — confirmed player.js
// actually enqueued (has_block() fix working), confirmed it made its
// own real REST calls (tracks/releases/likes/playlists all 200 OK),
// and confirmed the full app UI (tabs, search, genre filter, Import my
// music, the shared empty-state component) rendered and hydrated
// correctly with zero console errors. Test page cleaned up afterward.
define('BHS_PATH', plugin_dir_path(__FILE__));
define('BHS_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note, still true at this version: one site, one artist's own
 * catalog plus whatever OTHER feeds that artist explicitly chooses to
 * feature (see class-feeds.php) — not open federation. Real ActivityPub
 * Follow/Accept (anyone can follow anyone) needs a shared identity layer
 * this plugin doesn't have of its own — not open federation.
 */
foreach (['env', 'activator', 'post-types', 'admin', 'api', 'pwa', 'player', 'likes', 'playlists', 'recommendations', 'feeds', 'style-surface', 'crm-integration', 'import', 'jam', 'stats', 'audio-hash', 'blocks'] as $f) {
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
    // QA fix, caught live via WP_DEBUG_LOG: same fix as bh-contest's
    // BH_Blocks — hooked normally at 'init' instead of called directly
    // at plugins_loaded time, since wp_register_script() (inside
    // BHS_Blocks::register_blocks()) needs to run no earlier than
    // 'init' or WordPress logs a real "called incorrectly" notice.
    add_action('init',          ['BHS_Blocks', 'init']);
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
