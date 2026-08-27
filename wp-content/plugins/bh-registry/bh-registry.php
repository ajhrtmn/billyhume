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
foreach (['tables', 'links', 'activator', 'verification', 'wellknown', 'crawl', 'http-signature', 'activitypub', 'peers', 'api', 'admin', 'style-surface', 'debug', 'frontend', 'streaming-bridge', 'test-suite', 'discovery-test-suite'] as $f) {
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
            echo '<div class="notice notice-error"><p><strong>BH Registry</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
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
        // Crawl-discovered candidates get verified through the exact
        // same handler manual/cron re-checks use — one code path, no
        // separate "discovery verification" logic to drift out of sync
        // with the real thing.
        OUS_Jobs::register('bhr_verify_gossip_candidate', ['BHR_Verification', 'recheck_one']);
        OUS_Jobs::register('bhr_crawl_one_peer', ['BHR_Crawl', 'crawl_one_peer']);
    }

    // Daily peer crawl — replaces the old push+secret design's fan-out-
    // on-verify entirely; nothing needs to be told about a newly-
    // verified link anymore, since any peer crawling THIS site's own
    // /peers/manifest will see it on their own next pass. Also serves
    // as the liveness check (a peer that fails to answer its manifest
    // is exactly as "not there" as one that failed a lighter ping would
    // have been). Handler lives on BHR_Crawl (class-crawl.php).
    if (!wp_next_scheduled('bhr_peer_crawl')) {
        wp_schedule_event(time(), 'daily', 'bhr_peer_crawl');
    }

    // The search-index discovery layer that briefly lived here was
    // REMOVED rather than left as a dead toggle. Real finding from
    // testing it: public SearXNG instances effectively do not expose a
    // usable API — of six tried, one returned HTML even when asked for
    // JSON, two rate-limited (429), one 403'd, one refused to connect.
    // The JSON output is off by default in SearXNG precisely because
    // it is what scrapers abuse. That left the layer needing either a
    // self-hosted SearXNG or a paid API key to do anything at all,
    // AND needing the target sites to already be indexed (a new domain
    // is not, for months). Shipping a switch that cannot work without
    // infrastructure the admin has to stand up themselves is worse
    // than not shipping it: the bootstrap seed in BHR_Crawl solves the
    // same cold-start problem with no third party involved.
    // class-search-discovery.php is deleted; its options are left
    // untouched in the DB (harmless, and avoids destroying anything an
    // admin may have configured while it existed).


    // ActivityPub relay layer — the third discovery mechanism. Its
    // endpoints (WebFinger/actor) register unconditionally so the
    // actor document is always resolvable, but the inbox 404s and no
    // outbound federation happens at all unless an admin has enabled
    // the layer AND named a relay (see BHR_ActivityPub::maybe_serve()
    // and ::sync_relay()).
    if (class_exists('BHR_ActivityPub')) {
        BHR_ActivityPub::init();
        if (!wp_next_scheduled('bhr_ap_relay_sync')) {
            wp_schedule_event(time(), 'daily', 'bhr_ap_relay_sync');
        }
    }
});
