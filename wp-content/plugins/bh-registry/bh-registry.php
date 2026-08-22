<?php
/**
 * Plugin Name: BH Registry
 * Description: A global, decentralized artist-link registry — a cross-instance directory of artists' public ActivityPub/RSS-Podcasting-2.0 links, submitted voluntarily and verified by domain ownership. Stores links and metadata only; never media.
 * Version:     0.1.15
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: The Self-Hosted Self
 */
if (!defined('ABSPATH')) exit;

// 0.1.15 — Adds GET /bhr/v1/artists/{id}/tracks: a read-only preview
// of a registered artist's actual tracks (fetches and parses their
// feed live via fetch_feed(), same enclosure-extraction approach
// bh-streaming's own importer uses, never importing or storing
// anything locally) — the piece a fan-facing "browse the global
// library" feature needs that didn't exist anywhere: this registry
// only ever knew ARTIST-level entries (a feed URL), never individual
// tracks within one. Building and live-testing this endpoint is what
// surfaced a real, significant, separate bug in bh-streaming's own
// feed EXPORT (see bh-streaming 0.5.30's own changelog for the full
// story) — this endpoint's honest "feed_unreachable" error, and the
// real WP_Error message temporarily surfaced for debugging, is what
// proved the export side had been silently broken this whole time.
// Verified live end-to-end after that fix landed: pointed a temporary
// test artist at this site's own real feed, confirmed real track data
// (title/artist/audio_url/duration) round-tripped correctly, then
// cleaned up the test data.

// 0.1.14 — Real gap found in a direct vision check with AJ: the
// bh-streaming bridge (class-streaming-bridge.php) only ever gave an
// admin a search box on the Feed Sources screen — being registered
// never surfaced an artist automatically, only made them findable if
// searched for by name, nowhere close to "a global library the admin
// has to choose from to curate." The REST endpoint (GET /bhr/v1/
// artists) already fully supported a blank-search browse-all query
// with protocol filtering — this was a pure admin-UI gap, no backend
// work needed. Added a real "Browse Registry" admin screen
// (BHR_StreamingBridge::render_browse_page(), still guarded by
// class_exists('BHS_Feeds') — bh-registry still never requires
// bh-streaming) showing every verified feed-protocol artist as a
// card, with a real one-click "Add to my library" action
// (handle_add_from_registry()) that creates a real bhs_feed_source
// post and triggers an IMMEDIATE sync via BHS_Feeds::sync_one_job()
// (the same public entry point the cron/job-queue fan-out already
// use, not a duplicated fetch), rather than waiting up to 12 hours for
// the next cron tick. Reuses BHR_API::list_artists()/get_feed_url()'s
// real, already-tested query logic via rest_do_request() (WordPress
// core's own internal REST dispatch) instead of duplicating SQL.
// Verified live end-to-end: temporarily marked one seed link
// verified, confirmed the card rendered, clicking "Add to my library"
// created a real bhs_feed_source post with the correct feed URL meta
// and correctly flipped the card to "Already in your library" —
// confirmed via direct DB query, then reverted the test state
// afterward. The second half of AJ's actual vision — a fan-facing,
// platform-independent cross-site library — is explicitly scoped as
// its own future design pass, not started here.

// 0.1.13 — Same SEO-timing bug found and fixed on bh-courses this
// session, same fix here: BHR_Frontend::render()'s SEO block only ever
// ran during the_content() (the [bh_registry] shortcode's own
// render), always after wp_head — where BH_SEO actually echoes its
// tags — has already fired. Extracted into its own set_seo_data()
// method and added a template_redirect hook that detects the
// shortcode on the current page early, via
// BH_SEO::shortcode_atts_on_current_page() (own-ur-shit) — confirmed
// live, the Artist Registry page now renders real meta/OG tags.

// 0.1.12 — Real bug fix surfaced by own-ur-shit's own final PHPStan
// level 6 brick (typing OUS_Debug::button() with a real `: void`
// return): class-debug.php here was calling it as `echo
// OUS_Debug::button(...)`, double-printing that debug-tools button on
// this plugin's own Debug Tools section — button() already echoes its
// own markup internally, the wrapping `echo` was pure extraneous
// output. Fixed by dropping the `echo`. NOT runtime-verified against a
// live install; smoke-test the Debug Tools page to confirm the button
// renders once, not twice.

// 0.1.11 — Ecosystem quality Phase 2, brick 5/13: added native return
// types and parameter types across all 11 includes files (94 findings,
// both mechanical level-6 categories). $wpdb->get_row()/get_results()
// rows are typed \stdClass (their real default object shape) rather
// than array — matches how every method in this plugin actually reads
// them (->id, ->url, etc., not ['id']). Purely additive typing, no
// behavior change. This plugin is now clean at PHPStan level 6 in
// isolation.
// NOT runtime-verified against a live install.
// 0.1.10 — PHPStan round 2 (this plugin went from 9 errors to 0): all
// 9 findings were the same real, if harmless, mismatch in
// class-test-suite.php — add_filter('pre_http_request', $filter, 10, 3)
// declared 3 accepted args for mock closures that only ever declared
// zero (function () { return [...]; }), since they return a fixed
// canned response regardless of the request. Dropped the unnecessary
// accepted_args count on the 9 closures that genuinely never use their
// args; the 2 closures elsewhere in the same file that DO use
// ($preempt, $args, $url) were left untouched.
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run. `php -l` clean.

// 0.1.2 — All three remote-verification checks (domain ownership challenge,
// open-feed fetch, ActivityPub actor fetch) now surface the actual failure
// reason instead of discarding it, so "not verified" distinguishes
// "never set up" from "our request to your server failed".
// 0.1.3 — bundled zip regenerated to match installed version, no code change.
// 0.1.4 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET, part of the Debug Tools reorganization.
// 0.1.5 — OUS_Search consumer. Reuses BHR_API::list_artists()'s
// 'active'/verified-only gate, so pending/rejected artists never surface in
// search. Links to the registry directory page since no per-artist
// canonical URL exists yet (the directory is one client-rendered page).
define('BHR_VER',  '0.1.15');
define('BHR_PATH', plugin_dir_path(__FILE__));
define('BHR_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note: this plugin is deliberately independent of bh-streaming.
 * Its whole value is being adoptable by someone with no streaming app at
 * all — a bare WordPress site, a future native app, or a plain fan-facing
 * search page. bh-streaming (or anything else) is a CONSUMER of this
 * plugin's REST API (specifically GET /bhr/v1/artists/{id}/feed-url),
 * never a dependency of it, and this plugin never requires bh-streaming
 * to exist. See class-streaming-bridge.php for the one-directional,
 * entirely-optional integration, modeled on bh-streaming's own
 * class-crm-integration.php.
 */
foreach (['links', 'activator', 'verification', 'api', 'admin', 'style-surface', 'debug', 'frontend', 'streaming-bridge', 'test-suite'] as $f) {
    require_once BHR_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own tables, which don't touch the core's identity/style
// classes this plugin depends on for its actual admin UI.
register_activation_hook(__FILE__, ['BHR_Activator', 'activate']);

/**
 * Gated behind plugins_loaded, never checked at file-parse time — see
 * bh-streaming's bh-streaming.php for the full rationale (WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check up here could run before a genuinely-active
 * dependency's file has actually been read yet on a given request).
 * plugins_loaded is a hard guarantee every active plugin's main file has
 * already loaded by the time callbacks registered on it run.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Registry</strong> requires the <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHR_Activator::maybe_upgrade();

    // A site that already had this plugin active before this version
    // (no default-pages logic existed until now) still gets the page —
    // maybe_create_default_pages() is itself version-gated via
    // bhr_pages_version, so it runs exactly once for such a site rather
    // than only ever firing on a fresh activation going forward.
    add_action('admin_init',    ['BHR_Activator', 'maybe_create_default_pages']);

    add_action('init',          ['BHR_Frontend', 'init']);
    add_action('init',          ['BHR_StyleSurface', 'init']);
    add_action('init',          ['BHR_Debug', 'init']);
    add_action('init',          ['BHR_Admin', 'init']);
    add_action('init',          ['BHR_StreamingBridge', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHR_TestSuite', 'init']);
    add_action('rest_api_init', ['BHR_API', 'register_routes']);
    add_action('rest_api_init', ['BHR_API', 'add_cors_headers']);

    // Periodic re-check of previously-verified links — control can lapse
    // (domain sold, DNS changed, well-known file removed) and a
    // "verified" badge that never gets re-checked stops meaning anything.
    if (!wp_next_scheduled('bhr_recheck_links')) {
        wp_schedule_event(time(), 'daily', 'bhr_recheck_links');
    }
    add_action('bhr_recheck_links', ['BHR_Verification', 'recheck_all']);

    // Optional: if the core's job queue is active, each link re-check
    // runs as its own queued job instead of all 50 running inline in one
    // cron tick — see BHR_Verification::recheck_all()'s docblock. A
    // plain class_exists() guard, never a hard dependency — this plugin
    // still works identically on a core version without OUS_Jobs.
    if (class_exists('OUS_Jobs')) {
        OUS_Jobs::register('bhr_recheck_one_link', ['BHR_Verification', 'recheck_one']);
    }
});
