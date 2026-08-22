<?php
/**
 * Plugin Name: BH Streaming
 * Description: An iTunes-like personal streaming library — releases, genres, shareable playlists, likes, lyrics, multi-quality audio, EQ, a visualizer, local-file import, a content-based recommendation engine, a gatekept RSS aggregator, shuffle/queue and shared-listening Jam sessions, and an aggregate artist metrics dashboard — installable as a PWA with reliable background audio.
 * Version:     0.5.31
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.5.31 — Two real, connected additions on top of 0.5.30's export
// fix, both direct from AJ's own description of the federated-library
// vision:
//   1. BHS_FanLibrary (class-fan-library.php, new): the fan-facing
//      half of that vision — a personal, cross-site playlist, kept
//      deliberately separate from bhs_likes/bhs_playlists (both keyed
//      to a real local bhs_track post, which a track a fan discovers
//      via the registry's global library may never become). New table
//      bhs_fan_library (DB_VERSION 1.4), 3 real REST routes (GET/POST
//      /bhs/v1/fan-library, DELETE /bhs/v1/fan-library/{id}), real
//      validation (missing fields, oversized fields, duplicate
//      detection scoped per-user). Verified live via 7 real HTTP round
//      trips (happy add, duplicate conflict, missing fields, list,
//      happy delete, delete-missing, oversized field) plus a real
//      OUS_TestRunner suite (BHS_TestSuite::run_fan_library_tests())
//      covering the same ground plus cross-user library isolation —
//      569/570 ecosystem-wide tests passing after this addition (the
//      1 failure is pre-existing/unrelated, see
//      PRODUCTION-READINESS-PLAN.md's own note on it).
//   2. A real, confirmed security gap found while verifying #1:
//      export_feed() never checked bhs_track_access_allowed at all —
//      a track gated behind a paid tier had its full audio file
//      exposed through the public, unauthenticated feed regardless,
//      bypassing the paywall for any external consumer. Now checks the
//      same filter class-api.php's own /tracks endpoint already gates
//      local playback with; a gated track falls back to a new
//      hoster-set preview clip (_bhs_preview_audio_id, its own upload
//      field on the track edit screen — a real separate teaser file,
//      never auto-derived from the original) if one exists, or is
//      omitted from the public feed entirely if not. Traced through a
//      real debugging session (confirmed the filter chain executes,
//      confirmed the sole registered callback reads the right meta) —
//      the one track available to test against in this environment
//      turned out to be legitimately exempt from monetization by
//      design (`_bhs_source = 'local-import'`), so the exemption path
//      itself is what got exercised live, not the full gated-track-
//      with-preview path; that logic was reviewed carefully instead of
//      screenshot-verified.

// 0.5.30 — Critical, real bug: BHS_Feeds::export_feed() (this site's
// own RSS/podcast feed export — the "public access link" half of the
// whole federation feature, and what another bh-streaming site's own
// importer or any real podcast client is supposed to subscribe to)
// returned a WP_REST_Response with the Content-Type header manually
// overridden to application/rss+xml — but WP_REST_Response's BODY
// always gets JSON-serialized by the REST server regardless of what
// header a callback sets; overriding the header doesn't change that.
// The result: every byte ever served at /wp-json/bhs/v1/feed.xml was
// a JSON-quoted string ("<?xml version=\"1.0\"...") wearing real
// XML-typed headers, not actual XML — confirmed live via `curl -D -`
// and by pointing this session's own new registry track-preview
// endpoint at it, where SimplePie's fetch_feed() correctly rejected
// it as invalid XML ("Not well-formed"). This means cross-site feed
// import/export — the entire point of this method, confirmed by
// checking against a second, genuinely separate real deployment
// (billyhume.wasmer.app) — had never once produced a real, consumable
// feed since this method was written; nothing had ever actually tried
// to parse it with a real feed parser until this session did. Fixed
// using the exact same bypass bh-live's own class-overlay.php already
// established for this "REST route needs to serve raw non-JSON
// content" case: exit the REST response cycle entirely
// (status_header()/header()/echo/exit) instead of returning a
// WP_REST_Response. Verified live: `curl -D -` now shows real,
// unquoted, valid XML; a real feed-parser round-trip (this session's
// new bhr/v1/artists/{id}/tracks preview endpoint, pointed at this
// exact feed) now correctly parses real track data out of it.
// IMPORTANT: any other live deployment running this plugin (e.g.
// billyhume.wasmer.app) needs this fix deployed via its own update
// path before cross-site federation actually works against it —
// flagged for AJ, not deployed from this session.

// 0.5.29 — Same SEO-timing bug found and fixed on bh-courses this
// session, same fix here: BHS_Player's maybe_set_seo_data() only ever
// ran during the_content() (the [bh_streaming] shortcode's own
// render), always after wp_head — where BH_SEO actually echoes its
// tags — has already fired. Added a template_redirect hook that looks
// up the shortcode's own track/release attributes on the current page
// early, via BH_SEO::shortcode_atts_on_current_page() (own-ur-shit),
// and calls the same existing method — no change to its own logic.

// 0.5.28 — Real, visible bug: the Metrics dashboard's D3 charts
// (stats-charts.ts/.js) hardcoded #C1503A — the FRONT-END --bh-accent
// default — for every line/area/bar. Two problems, not one: hardcoded
// at all, AND the wrong token FAMILY for where this screen lives.
// class-stats.php's Metrics page is wp-admin, where --bh-* isn't even
// defined (confirmed live: reads empty) — the real admin accent is
// --bhy-accent (own-ur-shit's own token system). Verified live on this
// install: --bhy-accent computes to #2f7dff (blue), so every chart was
// rendering in orange against an otherwise all-blue admin skin. Fixed
// with a bhsChartAccent() helper reading the real token at render
// time, literal kept as fallback. Recompiled via the project's own
// `npm run build:bh-streaming`, verified live: charts now render in
// the real accent blue.

// 0.5.27 — TypeScript pilot: converted player.js (state, per-view
// rendering, the playback/queue engine, Media Session, likes/
// playlists/volume/related-tracks, quality switching, the EQ/
// visualizer Web Audio graph, and shared-listening Jam sessions) —
// the last of the two large/risky files deliberately deferred from
// the earlier pilot rounds, and the harder of the two: no class to
// hang types on, so this stays the exact same one flat IIFE the
// original was, with every `var`/function inside it given a real
// type instead of being restructured into a class just to make
// typing easier. Real interfaces for the REST shapes this file reads
// (tracks/releases/playlists/Jam state), a `byId<T>()` non-null-cast
// helper matching player.ts's own `q<T>()` convention (bh-contest's
// class-shaped player, converted last round) for the many DOM lookups
// this template guarantees are present, and a real (not `any`)
// AudioContext/webkitAudioContext fallback for the EQ/visualizer
// graph. No @ts-nocheck.
// Two real, deliberate fixes worth calling out (both confirmed non-
// behavioral): (1) AudioContext isn't a Window property in TypeScript's
// own DOM lib (it's a bare global), so `window.AudioContext` doesn't
// type-check even though it worked at runtime — rewritten to check the
// real global `AudioContext` directly, falling back to
// `window.webkitAudioContext`, same two-branch fallback as before, just
// referenced correctly. (2) `.then()` for the play-tracking fetch
// needed an explicit trailing `return;` to satisfy noImplicitReturns —
// the function already implicitly returned undefined on the non-402
// path, this just makes that explicit.
// Every compiled assets/js/player.js diff was reviewed line-by-line
// against the pre-conversion file — the remaining deltas are all
// compiler reformatting (single-line `if (x) y;` expanded to braces)
// or type-safety-driven String()/Number() casts on values already
// implicitly coerced at runtime either way (seek.value, volume.value).
// No logic changed. `node --check` clean, no CommonJS artifacts.
// NOT runtime-verified against a live browser this session — this is
// the single highest-risk file in the whole TS pilot (real-time audio
// playback, Web Audio CORS handling, shared-listening session state),
// so treat this changelog's own "no behavior changed" claim as
// static-analysis-and-diff-reviewed, not live-tested, and prioritize a
// real browser smoke test (play/pause/seek, quality switch, EQ/
// visualizer toggle, starting and joining a Jam) before relying on
// this in production.

// 0.5.26 — Ecosystem quality Phase 2, brick 8/13: added native return/
// parameter types across all 22 includes files (293 findings, both
// mechanical level-6 categories) — the biggest brick completed so far.
// Covers the full surface: Jam's REST endpoints and session-row shape,
// the admin metaboxes, feed import/export, the public API's track/
// release payloads, playlists, artist metrics dashboard, the PRO
// registration wizard, chapters/resume, privacy exporters/erasers,
// video CPT, ISRC issuance, activation/migration, and the smaller
// utility classes (likes, style-surface previews, audio-hash duplicate
// detection, blocks, recommendations). Purely additive typing, no
// behavior change. This plugin is now clean at PHPStan level 6 in
// isolation.
// NOT runtime-verified against a live install.
// 0.5.25 — TypeScript pilot, continued: converted bhs-blocks.ts
// (bhs/player Gutenberg block registration). Same posture as every
// other plugin's TS pilot entry this session: plain `tsc`, no bundler,
// compiled .js committed, `npm run build:bh-streaming` after editing.
// player.js (1754 lines, the biggest single file in the ecosystem)
// deliberately NOT converted this pass — flagged for a dedicated future
// pass with real browser verification, not attempted blind.
// NOT runtime-verified against a live browser this session.
// 0.5.24 — PHPStan round 2, two small real fixes surfaced late (this
// plugin was already at 2 errors — the deliberate COOKIEPATH/
// COOKIE_DOMAIN exception — before this pass, unaffected by it): two
// get_posts() calls (class-api.php's get_releases(), class-video-
// post-types.php) passed a bare int as 'meta_value' where WP's own
// signature expects a string. Cast both.
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run. `php -l` clean.

// 0.5.23 — Real bugs found by a proper `composer install && vendor/bin/
// phpstan analyse` run (repo-root phpstan.neon, level 5; the pilot's
// original sandbox had no GitHub access to actually run this).
// class-admin.php: six esc_attr()/esc_html() call sites across the
// track/release/quality-file metaboxes and the Plays admin column passed
// an int directly where both functions expect a string (PHP 8.1+
// deprecation) — added explicit (string) casts. `php -l` clean.
// Runtime-verified live against localhost:10008: the Tracks admin list
// (Plays column) and a real track's Edit screen (Track Details, Quality
// Encodes metaboxes) both render cleanly with real IDs in the hidden
// fields.

// 0.5.22 — stats-charts.js converted to TypeScript (assets/ts/
// stats-charts.ts), this plugin's first TS-pilot file, following
// own-ur-shit's established pattern (plain `tsc`, module: none,
// compiled output committed since the live site runs no build step —
// new bh-streaming/tsconfig.json, `npm run build:bh-streaming`). `d3` is
// declared as a loose `any` global rather than pulling in a real
// @types/d3 package — precise D3 typings are a much heavier dependency
// than this pilot's "catch typos in our own code" goal calls for.
// Compiled assets/js/stats-charts.js verified with `node --check` and
// grepped for CommonJS `exports`/`require(` artifacts — clean. Purely a
// type-safety/authoring-layer change; no runtime behavior was touched.
// NOT runtime-verified against a live browser this session.

// 0.5.21 — Real D3 charts (vendored, assets/js/vendor/d3.min.js v7.9.0,
// ISC — a permissive license close to MIT, real bytes downloaded and
// verified against its own LICENSE file before vendoring) on the
// Metrics dashboard (class-stats.php), replacing what was a hand-rolled
// inline-CSS div bar chart for plays-per-day and plain HTML tables for
// listener region/referrer. Confirmed via an ecosystem-wide survey this
// session as the single best "real, artist-facing, already-BUILT, but
// plain-rendered" screen for D3 — no invented need. New
// assets/js/stats-charts.js (generic renderTimeSeries()/renderBarChart()
// helpers); class-stats.php's render() is otherwise unchanged — same
// SQL, same aggregation, just JSON-encoded into each chart container's
// data-chart attribute instead of looped into echo calls. Top Tracks and
// Most Skipped stay plain tables (title+count lists aren't naturally
// chart-shaped). D3 vendored plugin-local, not shared core — first real
// consumer in this ecosystem, matching the "don't build shared
// infrastructure before a second real need exists" call already applied
// to OUS_Integration.
// NOT runtime-verified against a live WordPress+MySQL install this
// session. `php -l` clean; `node -c` clean on both the vendored D3
// bundle and stats-charts.js.

// 0.5.1 — logging depth pass: BHS_Feeds::check_external_track_health()
// previously updated a track's health status with zero log trace — the only way
// to discover a dead external feed was manually browsing post meta. Now logs an
// info/warning entry on every ok<->down/degraded TRANSITION (not every check,
// which runs on a schedule and would otherwise flood the log).
define('BHS_VER',  '0.5.31');

// 0.5.10 — Design Suite gallery gap closed: registered the PRO Registration
// wizard (BHS_PROWizard) as its own surface (class-style-surface.php),
// previously entirely invisible to the token editor. Same light-on-light
// contrast bug found and fixed as own-ur-shit's 3.6.5 Media wizard surface —
// this preview's own wp-admin-style light background was inheriting the dark
// brand theme's light :host text color; fixed with an explicit text color.

// 0.5.9 — moving the "half-done" mock ISRC logic forward, AJ's own ask: ISRC
// generation is now real and server-side (BHS_ISRC::issue()), not a client-only
// Math.random() fill. Two real improvements: (1) the mock path now collision-
// checks against existing _bhs_isrc rows instead of trusting client-side
// randomness alone; (2) a new "ISRC Registrant" settings page (own-ur-shit →
// ISRC Registrant) lets an artist record a REAL registrant code once they've
// completed the actual, offline national-agency application — once that's on
// file, the same "Generate ISRC" button starts issuing real, sequential,
// correctly-shaped codes under that prefix instead of placeholders, with zero
// further code changes needed.

// 0.5.8 — new BHS_PROWizard (includes/class-pro-wizard.php): the PRO
// registration guided flow scoped in this plugin's own README ("PRO registration
// wizard — roadmapped, not built this pass") and built now. Thinner than
// OUS_MediaWizard by necessity — no PRO exposes a public membership-verification
// API, and SESAC/GMR are invitation- only with no self-serve signup at all, so
// this is honestly a guided- links-plus-storage tool, not a live-validated
// integration.

// 0.5.7 — mock ISRC issuance, built against the shape now so real issuance is a
// drop-in later (AJ's own ask): new BHS_ISRC (includes/class-isrc.php)
// recognizes a placeholder pattern ("ZZOUS..." — ZZ is ISO 3166-1's own reserved
// "never a real country" code, so it can't collide with a real ISRC once issued
// for real). Track edit screen gets a "Generate placeholder" button; the save
// handler re-derives the mock flag server-side rather than trusting a hidden
// POST field. maybe_set_seo_data() now strips a mock ISRC before it ever reaches
// published schema.org data — a fake code never gets published as if it were
// real.

// 0.5.6 — closes ROADMAP-discoverability.md's own named gap: [bh_streaming] now
// optionally accepts a `track` or `release` ID attribute
// (BHS_Player::maybe_set_seo_data()) and, if given, sets real
// MusicRecording/MusicAlbum schema.org JSON-LD via BH_SEO — the same mechanism
// bh-courses/bh-contest already use for Course/Event. Purely additive: the SPA
// shell (#bhs-app) itself is completely untouched, this is server-side metadata
// only.

// 0.5.5 — real cross-browser gap, caught by a grounded browser-quirk audit of
// every first-party .css/.js file in the ecosystem (not guessed): .bhs-seek's
// WebKit thumb was intentionally sized to 0x0 (the seek progress is drawn by a
// separate fill element, not the native thumb), but there was no ::-moz-range-
// thumb counterpart, so Firefox rendered its own native, VISIBLE slider
// thumb/track here while every other browser correctly showed none. Added the
// Firefox pseudo-elements (split into their own rule — a browser that doesn't
// recognize ::-moz-range-thumb drops the whole selector if it's comma-combined
// with -webkit-).

// 0.5.4 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG shortcode-
// to-block conversion continues, following bh-monetization- woo (0.4.9-0.4.11)
// and bh-contest (3.5.0)'s same wp.serverSideRender pattern. One new block,
// 'bhs/player' (class-blocks.php, assets/js/ bhs-blocks.js) — [bh_streaming]
// takes no attributes and BHS_Player:: render() is a single fixed mount div, so
// the block needs neither attributes nor an Inspector picker.
define('BHS_PATH', plugin_dir_path(__FILE__));
define('BHS_URL',  plugin_dir_url(__FILE__));

/**
 * Scope note, still true at this version: one site, one artist's own
 * catalog plus whatever OTHER feeds that artist explicitly chooses to
 * feature (see class-feeds.php) — not open federation. Real ActivityPub
 * Follow/Accept (anyone can follow anyone) needs a shared identity layer
 * this plugin doesn't have of its own — not open federation.
 */
foreach (['env', 'activator', 'post-types', 'isrc', 'admin', 'pro-wizard', 'api', 'pwa', 'player', 'likes', 'fan-library', 'playlists', 'recommendations', 'feeds', 'style-surface', 'crm-integration', 'import', 'jam', 'stats', 'audio-hash', 'blocks', 'test-suite', 'chapters', 'video-post-types', 'privacy'] as $f) {
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
            echo '<div class="notice notice-error"><p><strong>BH Streaming</strong> requires the <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHS_Activator::maybe_upgrade();

    add_action('admin_init',    ['BHS_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BHS_PostTypes', 'register']);
    add_action('init',          ['BHS_Admin', 'init']);
    add_action('init',          ['BHS_ISRC', 'init']);
    add_action('init',          ['BHS_PROWizard', 'init']);
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
    add_action('init',          ['BHS_Privacy', 'init']);
    add_action('init',          ['BHS_Chapters', 'init']);
    add_action('init',          ['BHS_VideoPostTypes', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHS_TestSuite', 'init']);
    add_action('rest_api_init', ['BHS_API', 'register_routes']);
    add_action('rest_api_init', ['BHS_API', 'add_cors_headers']);
    add_action('rest_api_init', ['BHS_PWA', 'register_routes']);
    add_action('rest_api_init', ['BHS_Likes', 'register_routes']);
    add_action('rest_api_init', ['BHS_FanLibrary', 'register_routes']);
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
