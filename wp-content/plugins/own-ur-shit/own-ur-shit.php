<?php
/**
 * Plugin Name: Own Ur Shit
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.4.4
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 3.4.4 — new OUS_ReliabilityTestSuite (class-reliability-test-suite.php),
// the first test coverage for OUS_ReliableStore and
// OUS_DebugLog::log_throttled() — both previously untested despite now
// being load-bearing (BHI_Auth's security throttles, the whole
// diagnostic-logging pipeline this session built out). Runs against the
// real options table with tagged/prefixed keys, cleaned up at the end
// of every run. Standing caveat: written and brace-balance-checked, but
// never actually executed — the Test Runner itself needs to be clicked
// on the live install to confirm these pass for real.
define('OUS_VER',  '3.4.4');

// 3.4.3 — continuation logging pass (per audit): BHI_Auth::register()'s
// wp_create_user() failure now logs the real WP_Error instead of
// discarding it. Standing caveat: reasoning/brace-balance-checked only.

// 3.4.2 — Portal's /account/ 404 is still unresolved on the live
// install per direct user report (rewrite rule confirmed missing every
// reload, but ZERO Portal log entries at all — not even the throttled
// "still broken" warning that should have fired at least once by now).
// Per explicit user direction, NOT chasing this further right now (it's
// not blocking other work) and NOT treating BHI_Portal's fix as a
// working reference elsewhere — but added one cheap, always-throttled
// diagnostic breadcrumb at the very top of add_rewrite() so the next
// person looking at this (me or the user) can tell in one page load
// whether the method is even being entered, rather than re-deriving
// that from scratch. See class-portal.php's own comment at that line.

// 3.4.1 — Debug Tools sections are now real <details>/<summary>
// collapsibles, closed by default (the page is long enough with a
// dozen-plus registered sections that scrolling past all of them to
// find one is real friction), with each section's open/closed state
// remembered per-browser via localStorage so it doesn't reset every
// page load. Deliberately localStorage, not a server-side per-user
// option — this is cosmetic UI state, not anything that needs to survive
// across devices or matters if lost, and per this session's whole
// object-cache saga, sidestepping server-side persistence entirely for
// something this low-stakes is the more robust choice on an install
// whose cache layer has already proven unreliable more than once. A
// section reached via the quick-nav/redirect anchor force-opens
// regardless of its stored state — landing on your test results while
// the section hiding them stays closed would defeat the whole point.

// 3.4.0 — a real, live-reported bug ("nothing is displayed with the
// tests") traced to the same root cause as this whole session's Portal/
// API-Docs saga: set_transient()/get_transient() are backed entirely by
// this install's persistent object cache when one is active, and that
// cache is unreliable here — a transient write can report success while
// the very next request's read sees nothing. New class-reliable-store.php
// (OUS_ReliableStore) consolidates the direct-DB-bypass-the-cache
// pattern this session kept hand-rolling ad-hoc (BHI_Portal's throttle,
// OUS_TestRunner's first fix) into one shared, documented utility.
// OUS_TestRunner's report storage now uses it (fixes the reported bug
// directly). More importantly: BHI_Auth's login-fail lockout and
// registration-rate-limit counters ALSO used plain transients — on this
// install, that meant those SECURITY throttles could silently fail
// open, not just show a UX glitch. Both now go through OUS_ReliableStore
// too. NOTE per explicit user instruction: BHI_Portal's own rewrite-rule
// fix is NOT being treated as a proven-working reference for this pass
// — the user reports Portal still isn't fully working, so this fix
// stands on its own reasoning, not on "the same pattern that fixed
// Portal" (which hasn't been confirmed fixed). Standing caveat
// unchanged: reasoning/brace-balance-checked only.

// 3.3.9 — two things: (1) real bug found in 3.3.8's own anchor-scroll
// fix — the sticky admin bar + this page's own sticky quick-nav both
// cover the top of the viewport, so a native browser anchor-jump landed
// the target section's heading BEHIND them, which looked identical to
// "still stuck at the top" (exactly what got reported after 3.3.8
// shipped). Fixed with scroll-margin-top on every section plus a JS
// scrollIntoView + brief highlight flash as a second, independent
// safety net. (2) Added BHI_Profiles::user_ids_with_profile_data() per
// QA-REPORT-code-quality.md's cross-plugin finding #2 — bh-crm's
// class-people.php and class-export.php both ran identical raw SQL
// against this table directly instead of through the class that owns
// it; a pure extraction, no behavior change.

// 3.3.8 — Debug Tools page UX fix (explicit user report: running a test
// or clicking any button jumped back to the page TOP instead of staying
// near the result, and the page is long enough that this meant
// re-scrolling every single time). OUS_Debug::redirect() now carries a
// per-section anchor (every section already has/gained a stable
// 'ous-section-{key}' id) so a button click lands you back exactly where
// you clicked from — results were already rendered colocated inside
// their own section (Test Runner's own transient-backed report, e.g.),
// the only missing piece was the redirect itself dropping the anchor.
// Also added a sticky "Jump to:" quick-nav bar so the long-scrolling
// problem has a second, independent fix (jump anywhere in one click)
// beyond just landing correctly after an action.

// 3.3.7 — request-correlation IDs shipped end to end: bhcore_debug_log
// gained a request_id column (BHI_Activator::DB_VERSION 1.6 -> 1.7),
// OUS_DebugLog::request_id() generates one short ID per PHP request and
// stamps it onto every log() call automatically (no call-site changes
// needed anywhere in the ecosystem), and Console & Logs gained a
// Request ID filter plus a clickable chip on every row that jumps
// straight to "everything else that happened during this exact
// request." Degrades safely on an install that hasn't migrated yet
// (has_request_id_column() checks the live schema, not just the stored
// DB_VERSION, before including the column in any insert — a not-yet-
// migrated install keeps logging, just without correlation IDs, rather
// than every log() call failing on an unknown-column error).

// 3.3.6 — first slice of a deliberately larger, ongoing logging-depth
// push (explicit user direction: debugging/logging needs to be "airtight"
// across the whole ecosystem, not just the Portal/API Docs incident that
// started this). This pass: BHI_Two_Factor::ajax_disable() now logs a
// security-relevant account change (2FA disabled) that previously left
// zero audit trail; BHI_Two_Factor::gate_login() now logs a real wrong-
// code attempt (throttled per-user), previously invisible. See
// class-debug-log.php for this same pass's bigger addition: per-request
// correlation IDs, so scattered log entries from one failing request can
// finally be traced together instead of read as isolated, unrelated rows.

// 3.3.5 — closes the real diagnostic gap the 3.3.3/3.3.4 back-and-forth
// exposed: both fixes only logged on FAILURE, so an empty Console & Logs
// table was ambiguous between "checked every request and genuinely
// fine" and "stopped running/self-healing entirely" — precisely the
// state the 3.3.4 throttle bug produced and that made it undiagnosable
// from log data alone. Added OUS_DebugLog::log_throttled() (logs at
// most once per N seconds per key, regardless of outcome) and wired it
// into OUS_Debug::is_locked() and BHI_Portal::add_rewrite() so a
// PASSING check now also leaves a periodic trace, and a check that's
// sitting out a throttle window while still broken logs THAT state
// explicitly (at 'warning') instead of silently doing nothing. "No log
// entries for this key in the last several minutes" is now itself a
// real, actionable signal — the check isn't running at all — rather
// than an empty table meaning nothing in particular.
// (see class-debug-log.php's own docblock for log_throttled() usage —
// intended for any check that runs on every request across this
// ecosystem, not just these two.)

// 3.3.4 — real bug found in 3.3.3's own fix: BHI_Portal's rewrite
// self-heal throttle used get_transient()/set_transient(), which on an
// install with a persistent object cache active stores the transient IN
// that same cache — exactly the layer this whole fix exists to not
// trust. A stuck/broken cache could make the throttle read "already
// attempted" forever, silently skipping the self-heal on every request
// with zero log trace, which is indistinguishable from "working, just
// waiting" from the outside. Confirmed as the live symptom on the
// user's install (rewrite rule still missing after multiple reloads,
// zero matching OUS_DebugLog entries). Replaced with a direct,
// cache-bypassing DB read/write for the throttle timestamp — same
// technique the persistence check itself already used. Standing
// caveat unchanged: still no network path to the live install to
// confirm this against the real, reported failure.

// 3.3.3 — fixed the real reported bug: BHI_Portal's /account/ 404 and
// API Docs' "not allowed to access this page" both came from
// user-facing symptoms of the SAME underlying pattern (a persistent
// object cache serving stale option reads across requests — confirmed
// on this specific install via each class's own Debug Tools
// diagnostic). Both previously relied on a one-shot "did this already
// run" flag that could mark itself successful without the write
// actually having persisted, requiring a manual Settings -> Permalinks
// -> Save to fix. Replaced with self-verifying checks that read
// straight from the database (bypassing the object cache layer
// entirely) on every request: BHI_Portal::add_rewrite() now re-flushes
// and re-verifies (throttled to once per 60s) until the rewrite rule
// is confirmed actually persisted, not just attempted; OUS_Debug::is_locked()
// gained a third, cache-bypassing host check alongside its existing
// raw-HTTP_HOST and home_url() checks. Both log via OUS_DebugLog when
// they self-heal AND when they still fail after a real attempt, so a
// still-broken install after this fix points at something genuinely
// outside WordPress's own caching layer (reverse proxy/CDN, read-only
// DB, multisite domain mapping) instead of requiring another guess.
// Standing caveat: reasoning-checked and brace-balance-checked only —
// the user has WordPress running and reported these bugs live, but I
// still have no network path to their install from this sandbox, so
// this fix itself has not been confirmed against the real, reported
// failure yet.
define('OUS_PATH', plugin_dir_path(__FILE__));
define('OUS_URL',  plugin_dir_url(__FILE__));

// The one canonical signal a dependent plugin (bh-contest, bh-streaming,
// or anything built later) checks for — a plain constant rather than a
// specific class name, so which internal classes this plugin happens to
// contain can change later without quietly breaking every dependent's
// "is my dependency active" check.
define('BHCORE_LOADED', true);

/**
 * As of version 3.0.0, this plugin absorbed what used to be two separate
 * plugins — BH Identity (accounts/profiles/auth) and BH Style (design
 * tokens + the gallery) — into this one, alongside the hub/dashboard
 * role it already had. Class names (BHI_*, BHY_*) are unchanged from
 * when they were separate plugins specifically so nothing in bh-contest
 * or bh-streaming's actual feature code needed to change — only their
 * own bootstrap's dependency check does (see bh-contest.php /
 * bh-streaming.php for the other half of that).
 *
 * Reasoning for the merge, for whoever finds this later: running
 * identity and style as separate plugins meant every dependent plugin
 * had to defend against PHP's alphabetical plugin-load order — a real,
 * demonstrated source of bugs (a dependency check succeeding or failing
 * depending on which letter a folder name happened to start with). One
 * base plugin removes that whole class of problem for the pieces that
 * are, in practice, always installed together anyway. Contest and
 * Streaming stay genuinely separate — someone who only wants one of
 * them shouldn't have to install the other.
 */
foreach (['registry', 'dashboard', 'installer', 'activation-manager', 'banner', 'menu-merge', 'debug', 'debug-log', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'content', 'commerce', 'portal', 'studio', 'studio-test-suite'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
register_deactivation_hook(__FILE__, function () {
    // Only the cron schedule this plugin itself created — never touches
    // any other plugin's scheduled events, and the job queue TABLE (and
    // anything still pending in it) is left completely alone, so
    // reactivating later picks up right where it left off.
    $timestamp = wp_next_scheduled(OUS_Jobs::CRON_HOOK);
    if ($timestamp) wp_unschedule_event($timestamp, OUS_Jobs::CRON_HOOK);
});
add_action('plugins_loaded', ['BHI_Activator', 'maybe_upgrade']);
add_action('init',          ['BHI_Auth', 'init']);
add_action('rest_api_init', ['BHI_Auth', 'register_routes']);
add_action('init',          ['BHI_PublicProfile', 'init']);
add_action('init',          ['BHI_Reports', 'init']);
add_action('rest_api_init', ['BHI_Reports', 'register_routes']);
add_action('init',          ['BHI_TwoFactor', 'init']);

add_filter('cron_schedules', ['OUS_Jobs', 'register_cron_schedule']);
add_action('init',          ['OUS_Jobs', 'init']);
add_action('init',          ['OUS_Notifications', 'init']);
add_action('init',          ['OUS_Roles', 'init']);
add_action('init',          ['OUS_DebugLog', 'init']);
add_action('init',          ['OUS_TestRunner', 'init']);
add_action('init',          ['OUS_CoreTestSuite', 'init']);
add_action('init',          ['OUS_ReliabilityTestSuite', 'init']);
// BH_Studio's own init() registers this pass's default block types with
// BH_Content — must fire after 'content' (BH_Content itself) has loaded,
// which own-ur-shit.php's require order above already guarantees, and
// after (or during) the same 'init' hook everything else here uses, so
// no separate hook priority juggling is needed.
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);

add_action('init', ['BHY_Gallery', 'init']);
add_action('init', ['BHY_UI', 'init_shared_admin_assets']);
BHY_UI::pin_hidden_submenus_to_bottom();

/**
 * Hub role: unchanged in spirit, reduced in scope now that identity and
 * style aren't separate installable things anymore — the registry only
 * needs to track bh-contest and bh-streaming from here on.
 */
add_action('admin_menu',    ['OUS_Dashboard', 'add_menu']);
add_action('init',          ['OUS_MenuMerge', 'init']);
add_action('init',          ['OUS_Debug', 'init']);
add_filter('ous_debug_tools', ['OUS_Registry', 'register_debug_section']);
add_action('admin_post_ous_activate', ['OUS_Dashboard', 'handle_activate']);
add_action('admin_post_ous_activate_all', ['OUS_Dashboard', 'handle_activate_all']);
add_action('admin_post_ous_activate_file', ['OUS_Dashboard', 'handle_activate_file']);
add_action('admin_post_ous_install',  ['OUS_Dashboard', 'handle_install']);
add_action('init',          ['OUS_Banner', 'init']);
add_action('admin_head',    ['OUS_Banner', 'maybe_print']);
add_action('admin_enqueue_scripts', ['OUS_Dashboard', 'enqueue_assets']);

/**
 * New cross-cutting interfaces (ROADMAP-platform-evolution.md Section 2/6):
 * BH_Content (content-block interface), BH_Commerce (commerce interface,
 * WooCommerce-backed today), BHI_Portal (the custom user-facing account
 * shell + wp-admin exclusion rollout). All three use the plain `BH_`/`BHI_`
 * prefixes already established for this ecosystem's shared, foundational
 * pieces — see each class's own docblock for the full contract.
 */
add_action('init', ['BH_Content', 'init']);
add_action('init', ['BHI_Portal', 'init']);
register_activation_hook(__FILE__, function () {
    // BHI_Portal::add_rewrite() also runs on every 'init', but the
    // rewrite rule needs an explicit flush once so /account/ resolves
    // immediately on activation rather than waiting for WordPress's own
    // rewrite cache to naturally regenerate.
    BHI_Portal::add_rewrite();
    flush_rewrite_rules();
});
