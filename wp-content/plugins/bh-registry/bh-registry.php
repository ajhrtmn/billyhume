<?php
/**
 * Plugin Name: BH Registry
 * Description: A global, decentralized artist-link registry — a cross-instance directory of artists' public ActivityPub/RSS-Podcasting-2.0 links, submitted voluntarily and verified by domain ownership. Stores links and metadata only; never media.
 * Version:     0.1.19
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: The Self-Hosted Self
 */
if (!defined('ABSPATH')) exit;

// 0.1.19 — Real redesign of 0.1.18's discovery mechanism, prompted by
// direct, in-session feedback after 0.1.18 both caused a real
// production outage (a mid-deploy race on Wasmer's file-sync, not a
// genuine code bug — confirmed by a clean redeploy of the identical
// code after a real wait) AND turned out to have a genuine, separate
// design bug once live-tested: the "Add Peer" form only auto-generated
// a secret, with no way to manually enter the secret the OTHER side
// had generated, so two independently-added peers could never actually
// agree on a shared secret — bidirectional gossip could never have
// worked as built. That bug is what prompted the real question: "That
// still requires human input? Not like web crawlers for discovery?",
// then "It shouldn't be manual at all - 100% automatic", then "No
// previous knowledge of one another should be required" — each a real
// rejection of the mutual-secret-exchange model, not scope creep.
//
// Replaced entirely (not patched) with an open, unauthenticated
// PULL/crawl model: class-gossip.php -> class-crawl.php (BHR_Gossip ->
// BHR_Crawl, the whole mental model changed from push-announce to
// pull-crawl). New GET /bhr/v1/peers/manifest (open, __return_true,
// like every other read route here) replaces POST /peers/announce and
// GET /peers/handshake entirely — no secret, no privileged inbound
// endpoint, no per-relationship setup beyond knowing a base_url. A
// daily cron crawls each active peer's manifest, ingests its verified
// links as candidates (still, unchanged, only ever a thin pointer
// queued for THIS site's own independent BHR_Verification::verify_link()
// check — never a trust shortcut), and auto-follows each peer's own
// known_peers list onward, hop-limited (bhr_peers.discovered_hop,
// option bhr_crawl_max_hops, default 3) and capped
// (BHR_Crawl::MAX_TOTAL_PEERS = 200) against unbounded growth from a
// colluding cluster.
//
// New, real attack surface this pull redesign introduces versus the
// reverted push design: this site now fetches URLs a REMOTE PEER
// merely CLAIMS exist (its known_peers list), not just URLs a local
// admin chose — a genuine SSRF vector if unguarded. Added
// BHR_Crawl::is_safe_external_url(): resolves the host, rejects any
// private/loopback/link-local/reserved-range IP (RFC1918, 127.0.0.0/8,
// 169.254.0.0/16 — including cloud metadata endpoints — ::1, fc00::/7,
// fe80::/10) and any non-http(s) scheme, checked before every single
// outbound request to a peer-supplied URL, including the peer's own
// base_url on every crawl (DNS can change after a peer was safely
// added).
//
// Direct follow-up: "Most interested in search index and activity
// pub" — the two mechanisms actually capable of true
// zero-prior-knowledge discovery (neither site having ever heard of
// the other), since nothing built from only two from-scratch nodes can
// bootstrap that alone; every real decentralized system leans on
// something already operating at internet scale for this (BitTorrent's
// bootstrap nodes, Mastodon's relay/WebFinger model, DNS's root
// servers). Built now: BHR_SearchDiscovery (class-search-discovery.php)
// — an optional, OFF BY DEFAULT weekly job querying an admin-configured
// search endpoint (a self-hostable SearXNG instance recommended over a
// paid vendor API — genuinely avoids a silent third-party dependency;
// a commercial API works too if that's what's configured) for other
// public installs, feeding any confirmed-real hit into the exact same
// BHR_Crawl::maybe_add_discovered_peer() pipeline a directly-crawled
// peer-of-a-peer uses. Settings follow own-ur-shit's own established
// Tier B pattern (class-media-wizard.php's Cloudflare Stream section —
// confirmed via direct exploration, not guessed): toggle + credential,
// password field always blank, a blank submit preserves the existing
// stored value.
//
// ActivityPub relay integration (the other priority layer) is
// DELIBERATELY NOT built in this version — real, existing Fediverse
// relay infrastructure already solves zero-prior-knowledge discovery
// for the activitypub protocol at internet scale today, but doing it
// correctly needs a real minimal ActivityPub actor (WebFinger
// discovery, an actor JSON endpoint, HTTP Signature verification for
// authenticated Follow/Accept/Announce activities) — genuine
// cryptographic-correctness-stakes protocol work, not something to
// rush in the same fast-iterating session that already had one real
// production incident from insufficiently-tested code. Settings
// fields (bhr_relay_enabled, bhr_relay_url) ARE added to the Peers
// screen now, stored for when the actor implementation lands, but
// nothing reads them yet.
//
// Schema: DB_VERSION 1.2 -> 1.3 (class-activator.php). bhr_peers'
// shared_secret/last_announced_at columns — genuinely dead after this
// redesign — get a real ALTER TABLE DROP COLUMN (guarded, checks
// existence first) rather than left as permanent unused schema;
// justified specifically because zero real peer data exists anywhere
// yet. New discovered_hop column added. bhr_links.discovered_via='crawl'
// replaces the old 'gossip' value going forward (both mean "not
// manually submitted" — provenance only, never affects verification).
//
// NOT runtime-verified against a live install yet at the time this
// entry was written — `php -l` clean on every touched/new file. Given
// 0.1.18's own incident, this version gets a real local-first
// verification pass AND a genuine wait-before-recheck on live deploy
// before being trusted, not an instant recheck (that's what produced
// the false-alarm revert last time).

// 0.1.18 — Phase 2 of the peer gossip/announce plan (REVERTED, see
// 0.1.19 above — kept for incident record only, this version's own
// push+secret design was replaced, not built on). Originally: the actual
// protocol. New POST /bhr/v1/peers/announce (the inbound receiver —
// the ONLY route in this namespace that isn't __return_true;
// authenticated via a per-peer shared secret checked with hash_equals()
// for timing-safe comparison, class-gossip.php's check_peer_auth())
// and GET /bhr/v1/peers/handshake (open, a read-only self-description
// used at peer-add time and by the new daily liveness cron). New admin
// screen (class-peers.php, BHR_Peers) for adding/blocking/deleting
// peers — peering is always an explicit, mutual, admin-initiated
// action; this plugin never auto-discovers or auto-trusts anything.
//
// Discovery and trust stay genuinely separate, on purpose: an accepted
// announce candidate is a thin pointer (protocol+url only, nothing
// else trusted) inserted through the EXACT SAME pending-row shape
// POST /submissions already writes, then queued for this site's own
// independent BHR_Verification::verify_link() check — completely
// unchanged by any of this, never bypassed, never sped up. A peer
// cannot make an artist appear "verified" by claiming so; only a real
// domain-ownership + open-protocol check run BY THIS SITE can. Fan-out
// to other peers only happens AFTER local verification succeeds (via
// a new bhr/link_verified BH_Event, fired from verify_link()'s own
// success branch, for ANY successfully-verified link regardless of
// origin — one code path, no special-casing between manual and
// gossip-discovered links), never on receipt of an unverified
// candidate — a hostile/compromised peer cannot use this site to
// inject a fake-verified artist into the wider gossip graph.
//
// Real, deliberate abuse-surface hardening, since this is the first
// endpoint in the plugin that both accepts remote input AND triggers
// further outbound HTTP + DB writes on receipt: hop-limiting (receiver
// always clamps to its OWN configured ceiling, ignores whatever the
// sender's message claims), loop prevention (never re-announce back to
// the peer a candidate was just received from), dedup via a new
// bhr_gossip_seen table (a candidate already seen — from ANY peer, not
// just the same one twice — is neither re-queued for verification nor
// re-fanned-out), per-peer rate limiting (OUS_ReliableStore, 30
// announces/minute, checked before any DB write), a hard cap on
// candidates-per-announce (20) and raw body size (~20KB) checked
// before JSON decode, and verification is NEVER synchronous inside the
// announce request itself — always deferred to a queued job, so an
// inbound POST can never be used to make this site perform slow
// outbound fetches on an attacker's own timer.
//
// DB_VERSION 1.1 -> 1.2 (class-activator.php): one more additive
// bhr_links column, discovered_hop_count — found necessary while
// actually wiring the fan-out logic (verification can be re-triggered
// by several different paths — the queued job, the daily recheck cron,
// a manual re-check — and the correct hop_count to propagate at needs
// to survive all of them, so it lives on the row itself rather than
// being threaded through every possible job-args call site).
//
// Rollout stays exactly as scoped: a site with zero peers configured
// behaves byte-for-byte as it did before this version — /peers/announce
// always 401s (no secret exists to authenticate against), the fan-out
// loop in announce_verified_link() is always empty, and every existing
// route/behavior (submissions, verify, artists, feed-url, tracks) is
// completely untouched.
// NOT runtime-verified against a live install yet — `php -l` clean on
// every touched/new file. Live verification (a real 2-site peer
// exchange) and the deterministic simulation test suite are Phase 5,
// still to come.

// 0.1.17 — Phase 1 of the peer gossip/announce plan: schema only, a
// deliberate no-op deploy (DB_VERSION 1.0 -> 1.1, class-activator.php).
// Two new tables — bhr_peers (an admin-added gossip partner: base_url,
// status, shared_secret, liveness tracking) and bhr_gossip_seen (a
// dedup/hop-limiting ledger, one row per (protocol,url) candidate hash)
// — plus two additive bhr_links columns (discovered_via,
// discovered_from_peer_id, pure provenance). Nothing reads or writes
// any of this yet; the actual protocol/REST routes/propagation logic
// is Phase 2, still to come. Verified: SHOW TABLES check in
// create_or_update_schema() now confirms all 4 tables, not just the
// original 2, before marking the migration successful.
// NOT runtime-verified against a live install yet — `php -l` clean.

// 0.1.16 — Phase 0 of the peer gossip/announce discovery plan (real
// gap found during a live cross-site federation test this session):
// verifying this site's OWN feed on ANOTHER site's registry requires
// publishing a token at https://{this-host}/.well-known/
// bh-registry-verify.txt — a path outside wp-content that needs raw
// filesystem/SSH access, confirmed genuinely unavailable on a real
// Wasmer-hosted production install (wp-admin-only access) and a real
// blocker on plenty of ordinary shared hosting too. Added
// BHR_WellKnown (class-wellknown.php): self-serves that exact path via
// a WP rewrite rule (same BHY_RewriteHealer::maybe_heal() self-healing
// pattern BHI_Portal/BHM_Storefront already use), content from a new
// admin-visible/regenerable bhr_wellknown_token option. A real static
// file at the same path still wins if one exists — this only fills the
// gap when one doesn't. BHR_Verification::check_domain_ownership()
// itself is completely unchanged; it was always just an HTTP GET
// against whatever host a submission claims — this only changes what
// answers that GET on THIS site specifically. First piece of a larger,
// fully-scoped peer gossip/announce plan (see the project's plan file)
// — later phases add opt-in peer-to-peer propagation on top of this,
// none of which is built yet.
// NOT runtime-verified against a live install yet — `php -l` clean.

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
define('BHR_VER',  '0.1.19');
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
foreach (['links', 'activator', 'verification', 'wellknown', 'crawl', 'http-signature', 'activitypub', 'peers', 'api', 'admin', 'style-surface', 'debug', 'frontend', 'streaming-bridge', 'test-suite', 'discovery-test-suite'] as $f) {
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
