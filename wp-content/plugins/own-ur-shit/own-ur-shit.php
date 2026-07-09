<?php
/**
 * Plugin Name: Own Ur Shit
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.4.15
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 3.4.15 — confirmed via Query Monitor (capability-checks + admin-screen
// panels, installed temporarily on the live site): the standalone
// admin.php?page=ous-api-docs / ous-codebase-docs pages fail because
// WordPress's own get_current_screen()/hook_suffix resolution falls
// back to the PARENT page's hook instead of the submenu's, on every
// request, regardless of correct registration/capability — a genuine
// WordPress-core page-hook lookup issue, not caching or capabilities
// (the two things chased hardest earlier). Since the Debug Tools
// SECTION versions of both pages are confirmed working end to end, the
// two standalone add_menu() registrations are now unhooked entirely
// (methods left defined, just not called) rather than left as dead,
// permanently-broken links sitting in the sidebar. Also fixed the one
// remaining internal link between the two (Codebase Docs' "Open API
// Docs" cross-link) to point at the section anchor instead of the now-
// unregistered standalone page. See VISION.md's "New dev/admin-only
// pages default to a Debug Tools SECTION" entry for the full incident
// writeup. Standing caveat: reasoning/brace-balance-checked only —
// please confirm the sidebar no longer shows API Docs/Codebase Docs as
// separate top-level-adjacent entries, and that both sections still
// work fine on Debug Tools.

// 3.4.14 — Stopped chasing the standalone-page access-denial bug
// (registration and capability both confirmed correct via logging, yet
// WordPress still blocked admin.php?page=ous-api-docs / ous-codebase-docs
// every time — root cause never found despite five diagnostic passes)
// and sidestepped it instead: both API Docs and Codebase Docs now render
// their REAL content as sections directly on the Debug Tools page
// (ous-debug), the one page that has never once failed to load all
// session. class-api-docs.php's render_debug_section() (previously just
// a diagnostic panel) and class-codebase-docs.php's new render_section()
// both call a shared render_content() method, factored out of each
// class's standalone render() so neither duplicates the actual body
// markup. Debug Tools' own "API Docs"/"Codebase Docs" buttons now jump
// to these sections (#ous-section-api-docs / #ous-section-codebase-docs)
// instead of linking to the still-broken standalone pages, which remain
// registered as a secondary access point but should not be relied on.
// Standing caveat: reasoning/brace-balance-checked only — please reload
// Debug Tools and confirm both sections now show real content inline.

// 3.4.13 — CONFIRMED via 3.4.12's render()-entry log: render() never
// runs at all for Codebase Docs — WordPress is blocking the request at
// its own core dispatch level (the $_wp_submenu_nopriv mechanism:
// add_submenu_page() checks current_user_can() at the MOMENT it's
// called, on that specific request, and silently marks the page
// no-priv if it fails then — separate from the page callback entirely).
// Un-throttled the registration log and added the exact request URI +
// a same-request current_user_can() reading, specifically so the entry
// from the real failing click (not a nearby unrelated page load) is
// unambiguous. Also added a temporary workaround in class-debug.php:
// hand-built, guaranteed-correct admin.php?page= links to both pages
// printed directly on the Debug Tools page itself, since a live bug
// report showed the WordPress-generated SIDEBAR link for these two
// pages resolving to a broken bare front-end path instead — a second,
// separate bug from the access-denial one, not yet root-caused either,
// worked around rather than left blocking. Standing caveat: please
// click Codebase Docs (via the new button on Debug Tools, not the
// sidebar) once and paste back the newest matching log line.

// 3.4.12 — 3.4.11's is_locked()-gate removal confirmed NOT the fix (user
// reports no change in behavior). Added the one truly decisive
// diagnostic left: a log line as the literal first statement inside
// render() itself for both classes — this settles, once and for all,
// whether WordPress is blocking the request before OUR code ever runs
// (a genuine core-level gate this session hasn't found the cause of
// yet) or whether the callback IS running and something inside it is
// the actual problem. Standing caveat: purely diagnostic, no behavior
// change; please click into both pages once more and report exactly
// what Console & Logs shows (or doesn't show) from "render() was
// entered."

// 3.4.11 — API Docs / Codebase Docs "not allowed" bug, actual fix (not
// another diagnostic pass): found that both were the ONLY two admin
// pages anywhere in this ecosystem that wrapped their own
// add_submenu_page() call in an is_locked() check before registering.
// Every other page (Debug Tools itself, Job Queue, every peer plugin's
// screens) registers unconditionally — is_locked() exists to gate
// DESTRUCTIVE seed/reset actions, not a read-only viewer page's mere
// existence in the menu, so conditionally skipping registration was the
// wrong design from the start, independent of whatever is_locked()
// itself was actually evaluating to on any given request. Both classes'
// add_menu() now register unconditionally, matching every other working
// page. Standing caveat: still reasoning-checked only, not yet clicked
// on the live install — please try both pages now.

// 3.4.10 — PHP restart on the live site confirmed OPcache was serving
// stale compiled code (explains several earlier "this fix didn't seem to
// take effect" moments this session) — after restarting, add_submenu_page()
// for both API Docs and Codebase Docs now confirmed returning a real
// hook_suffix, not FALSE. Registration is NOT the problem. But
// add_submenu_page() returns a real hook_suffix even when the CURRENT
// user lacks the registered capability — WordPress's actual access gate
// for that case is a separate current_user_can() re-check done when the
// page is actually requested, which a successful registration log can't
// rule out. Added a direct current_user_can('manage_options') check,
// logged with the exact request URI, to settle this definitively.
// Standing caveat: diagnostic only, still narrowing down root cause.

// 3.4.9 — API Docs / Codebase Docs still 404 with is_locked() confirmed
// NOT the cause (zero log entries even from the locked-branch logging
// 3.4.5/this pass added, meaning that branch never ran — but that was
// ambiguous, since the SUCCESS branch had no logging either, so "no log"
// couldn't distinguish "never called" from "ran fine"). Added logging to
// the success path too: both add_menu() methods now log whatever
// add_submenu_page() actually returned (a real hook suffix string, or
// FALSE on a genuine registration failure) every time they run, closing
// that ambiguity for the next reload. Standing caveat: diagnostic-only
// change, root cause still not confirmed — waiting on the next log
// check to narrow it down further.

// 3.4.8 — 3.4.7's own Portal fix had a real side effect: calling
// add_rewrite() synchronously at 'init' priority 10 meant its
// force_flush_and_verify() could run before other plugins' own
// default-priority rewrite registrations, and its unconditional
// wp_cache_flush() wiped the WHOLE object cache mid-request — very
// likely why API Docs started intermittently 404ing right after 3.4.7
// shipped (is_locked()'s cached host checks, read later in the same
// request, got yanked out from under it). Fixed two ways: add_rewrite()
// is now deferred to 'init' priority 20 (still the same request/pass,
// just after other plugins' default-priority rewrite rules have
// registered), and wp_cache_flush() is now an ESCALATION only reached
// if the cheaper targeted cache evictions didn't already fix it, not
// called unconditionally on every throttled self-heal attempt. Also
// regenerated four stale bundled zips (bh-contest, bh-courses,
// bh-monetization-woo, bh-registry) flagged on the Bundled Zip
// Freshness table. Standing caveat: reasoning-checked only, not yet
// confirmed against the live install — please reload a few pages
// (including /account/ and API Docs) and check whether both stay
// reachable now.

// 3.4.7 — Portal's /account/ 404, finally actually found (not another
// caching-layer guess): class-portal.php's own init() was hooking
// add_rewrite() onto 'init' FROM INSIDE a callback that is itself
// currently running as part of 'init' (own-ur-shit.php's own
// add_action('init', ['BHI_Portal','init']) at default priority 10).
// PHP's foreach over that priority's callback array is a snapshot taken
// when iteration starts; a handler appended to the SAME priority after
// iteration has already begun isn't picked up until 'init' fires again
// — which, on a normal page load, never happens in that request. So
// add_rewrite() was successfully scheduling itself to run on a request
// that would never come, which is exactly why even its own always-
// throttled diagnostic breadcrumb never once appeared in Console & Logs
// — the method was never being entered, not failing partway through.
// Fixed by calling add_rewrite() directly from inside init() instead of
// re-hooking it — we're already executing inside 'init' at that point,
// so a direct call runs it immediately, every request, no re-hooking
// needed. See class-portal.php's own comment at the fix site for the
// full mechanics. Standing caveat: reasoning-checked, brace-balance-
// checked, and this specific WP_Hook same-priority-snapshot behavior is
// a well-documented WordPress core mechanic (not a guess) — but this has
// NOT yet been clicked/reloaded on the live install. Please hard-refresh
// /account/ and check Debug Tools -> Portal after this update and report
// back whether the rewrite rule now shows as persisted.

// 3.4.6 — OUS_Jobs can now run on the REAL Action Scheduler library
// (Apache-2.0, github.com/woocommerce/action-scheduler — the same
// library WooCommerce itself bundles) instead of only its own
// hand-rolled wpdb-table queue. A one-click "Install Action Scheduler"
// button on Debug Tools -> Job Queue downloads the actual official
// release directly from GitHub onto the LIVE site (this dev sandbox has
// no outbound network access at all, confirmed by testing — so the
// library could not be vendored directly from here; fabricating
// placeholder code under a real project's name would be dishonest, so a
// real installer was built instead, same download_url()/unzip_file()
// mechanism OUS_Registry already uses for WooCommerce). register()/
// enqueue() delegate to Action Scheduler's native add_action()/
// as_enqueue_async_action() once installed, with ZERO call-site changes
// needed anywhere bh-registry/bh-streaming/etc. already call OUS_Jobs —
// until installed, every existing call transparently keeps using the
// original table-backed implementation exactly as before. See
// class-jobs.php's own docblock for the full reasoning. Standing
// caveat: reasoning/brace-balance-checked only, the install button
// itself has not been clicked against the live site yet — please try it
// and report back what Debug Tools -> Job Queue shows.

// 3.4.5 — real bug fix + new feature. (1) bh-contest's Live Console
// dropdown 403'd because its GET form dropped post_type on submit — see
// bh-contest 3.1.3 for the fix; own-ur-shit itself was audited alongside
// it (bh-contest, BHY_* styles, bh-crm, Debug Tools) for the same bug
// class and no other instance was found. (2) New OUS_CodebaseDocs
// (class-codebase-docs.php, "Own Ur Shit → Codebase Docs"): renders
// CODEBASE-WALKTHROUGH.md as real in-admin HTML, and turns every
// file-path mention in that doc into a "View live code" toggle that
// fetches the file's ACTUAL current contents via a locked-down AJAX
// endpoint (realpath()-verified inside the plugins root, manage_options-
// gated, nonce-checked) — so the walkthrough can never silently drift
// from the real code the way a pasted-in snippet would. Deliberately
// left OUS_ApiDocs' existing dependency-free viewer alone rather than
// swapping in a Swagger-UI bundle, to keep this ecosystem's own "no
// external JS/CDN" viewer convention intact; the two pages cross-link
// instead. Standing caveat: reasoning/brace-balance-checked only, not
// yet clicked on the live install.
define('OUS_VER', '3.4.15');

// superseded — kept only so a stray duplicate define() below this point
// (a recurring mistake this session) is easy to spot if it recurs:
// 3.4.4 — new OUS_ReliabilityTestSuite (class-reliability-test-suite.php),
// the first test coverage for OUS_ReliableStore and
// OUS_DebugLog::log_throttled() — both previously untested despite now
// being load-bearing (BHI_Auth's security throttles, the whole
// diagnostic-logging pipeline this session built out). Runs against the
// real options table with tagged/prefixed keys, cleaned up at the end
// of every run. Standing caveat: written and brace-balance-checked, but
// never actually executed — the Test Runner itself needs to be clicked
// on the live install to confirm these pass for real.

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
foreach (['registry', 'dashboard', 'installer', 'activation-manager', 'banner', 'menu-merge', 'debug', 'debug-log', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'content', 'commerce', 'portal', 'studio', 'studio-test-suite', 'codebase-docs'] as $f) {
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
add_action('init',          ['OUS_CodebaseDocs', 'init']);

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
