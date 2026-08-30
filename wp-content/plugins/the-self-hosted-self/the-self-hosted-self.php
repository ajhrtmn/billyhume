<?php
/**
 * Plugin Name: The Self-Hosted Self
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.21.8
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('OUS_VER', '3.21.8');

define('OUS_PATH', plugin_dir_path(__FILE__));
define('OUS_URL',  plugin_dir_url(__FILE__));

// The one canonical signal a dependent plugin (bh-contest, bh-streaming,
// or anything built later) checks for — a plain constant rather than a
// specific class name, so which internal classes this plugin happens to
// contain can change later without quietly breaking every dependent's
// "is my dependency active" check.
define('BHCORE_LOADED', true);

// Runtime dependencies (Timber/Twig), vendored into this plugin and built by
// .github/workflows/deploy-ftp.yml. Guarded because the live host runs no
// composer install: if vendor/ is absent the ecosystem must degrade, not
// white-screen. BHY_View::is_available() is the check callers use.
if (is_readable(OUS_PATH . 'vendor/autoload.php')) {
    require_once OUS_PATH . 'vendor/autoload.php';
}

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
foreach (['tables', 'view', 'pages', 'registry', 'dashboard', 'installer', 'activation-manager', 'setup-wizard', 'banner', 'menu-merge', 'menu-icons', 'admin-guard', 'list-table', 'debug', 'debug-log', 'qm-integration', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'role-assignment', 'audit', 'revisions', 'search', 'admin-layout', 'content', 'commerce-provider', 'commerce-provider-woocommerce', 'commerce-providers', 'commerce', 'rewrite-healer', 'portal', 'portal-layout', 'menu-sync', 'visibility', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'badge', 'element-data', 'element', 'element-test-suite', 'design-suite', 'storybook-panel', 'gutenberg-block', 'block-style', 'share-card', 'media-wizard', 'media-token', 'seo', 'metrics', 'style-surface', 'user-bar', 'campaigns', 'page-surface', 'privacy', 'dmca', 'dmca-notices', 'mail', 'integration', 'hypermedia', 'github-updates'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
register_activation_hook(__FILE__, ['OUS_Audit', 'activate']);
register_activation_hook(__FILE__, ['OUS_Revisions', 'activate']);
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
add_action('init',          ['OUS_MediaWizard', 'init']);
add_action('init',          ['BHY_MediaToken', 'init']);
add_action('init',          ['BH_SEO', 'init']);
add_action('init',          ['OUS_Metrics', 'init']);
add_action('init',          ['OUS_StyleSurface', 'init']);
add_action('rest_api_init', ['BHI_Reports', 'register_routes']);
add_action('init',          ['BHI_TwoFactor', 'init']);
add_action('init',          ['OUS_Privacy', 'init']);
add_action('init',          ['OUS_DMCA', 'init']);
add_action('init',          ['OUS_DMCA_Notices', 'init']);

add_filter('cron_schedules', ['OUS_Jobs', 'register_cron_schedule']);
// QA fix, 3.4.85: OUS_Jobs::init()/OUS_Notifications::init() both
// internally register a SECOND add_action('init', ...) of their own
// (ActionScheduler::init() at priority 1, register_shortcode() at
// default priority 10, an anonymous job-handler registrant at default
// priority 10) — but since these two init() methods were THEMSELVES
// only ever invoked as 'init' hook callbacks, that inner registration
// happened WHILE 'init' was already executing, and WordPress's WP_Hook
// never revisits an already-passed (or, for priority 1, never-reached-
// because-lower-than-the-currently-executing-bucket) priority in the
// same pass — confirmed directly against a minimal WP_Hook
// reproduction, not assumed. The result: ActionScheduler never
// bootstrapped, the [bh_notifications] shortcode never registered, and
// the queued-email job handler never wired up, silently, on every real
// request, with zero error anywhere. Fixed by calling ::init() directly
// here instead of deferring through another 'init' hook layer — this
// file's own top-level statements already run well before 'init' ever
// fires (WordPress finishes loading every active plugin's main file
// before firing plugins_loaded, which itself fires before init), so
// every inner add_action('init', ..., $priority) call these two
// classes make now registers in plenty of time to fire correctly,
// in proper priority order, during the one real 'init' pass. See
// class-jobs.php's/class-notifications.php's own init() docblocks for
// what each individually-nested registration is for.
OUS_Pages::init();
OUS_Jobs::init();
OUS_Notifications::init();
add_action('init',          ['OUS_Roles', 'init']);
add_action('init',          ['OUS_RoleAssignment', 'init']);
add_action('init',          ['OUS_Campaigns', 'init']);
add_action('init',          ['OUS_Integration', 'init']);
add_action('init',          ['OUS_Hypermedia', 'init']);
add_action('init',          ['OUS_GithubUpdates', 'init']);
// The first registration — BH_Mail as the always-works transactional-
// email default, no enhancer registered yet (a real ESP swap point
// exists inside BH_Mail::deliver() itself, but nothing implements it
// today). Priority 20: after both OUS_Integration and BH_Mail have
// loaded, same "register after both sides exist" reasoning as every
// other cross-class registration on this hook.
add_action('init', function () {
    if (class_exists('OUS_Integration')) {
        OUS_Integration::register('bh_mail', [
            'label' => 'Transactional email',
            'description' => 'One-to-one/event-triggered email (notifications, receipts, confirmations).',
            'builtin_class' => 'BH_Mail',
        ]);
        // OUS_Campaigns lives here in core, so core is the natural owner
        // of this contract's builtin_class registration — bh-crm only
        // ever CONTRIBUTES segments to it (class-segments.php), and
        // bh-mailpoet (if active) merges in the enhancer_class from its
        // own bootstrap via the same key, without needing to repeat
        // label/description/builtin_class. This callback is added at
        // file-parse time, before plugins_loaded even fires, so it's
        // guaranteed to run before any peer plugin's own nested 'init'
        // registration — the enhancer-only merge always lands second.
        OUS_Integration::register('email_broadcast', [
            'label' => 'Email broadcast / marketing',
            'description' => 'Reach a live-queried audience (a bh-crm segment, or everyone) with a one-off or list-driven email.',
            'builtin_class' => 'OUS_Campaigns',
        ]);
        // Commerce-provider registry (class-commerce-providers.php) is a
        // multi-slot registry (woocommerce/shopify/stripe/squarespace),
        // not the single-enhancer shape this contract system otherwise
        // assumes — registered here anyway for the same status-report
        // visibility every other contract gets; BH_CommerceProviders'
        // own Debug Tools section (Commerce Providers) is the real,
        // detailed multi-provider view.
        OUS_Integration::register('commerce_provider', [
            'label' => 'Commerce / payments provider',
            'description' => 'Order/product/subscription model behind BH_Commerce — see the Commerce Providers Debug Tools section for the full multi-provider registry.',
            'builtin_class' => 'BH_WooCommerceProvider',
        ]);
    }
}, 20);
add_action('init',          ['OUS_PageSurface', 'init']);
add_action('init',          ['OUS_UserBar', 'init']);
add_action('init',          ['OUS_Audit', 'init']);
add_action('init',          ['OUS_Revisions', 'init']);
// Deferred require (not the unconditional foreach above): this file's
// class formally `implements WC_Log_Handler_Interface`, which
// WooCommerce only defines during ITS OWN main-file load — and
// active_plugins load in the order WordPress stores them, which on
// this install (and in general, since 'the-self-hosted-self' < 'woocommerce'
// alphabetically) puts the-self-hosted-self's main file BEFORE WooCommerce's.
// require_once-ing this file in the unconditional foreach above would
// fatal (interface not found yet). Guarding both the require AND the
// init() call behind 'init' (which fires only after every active
// plugin's main file has already loaded, WooCommerce included) is what
// makes this safe regardless of load order.
add_action('init', function () {
    if (!interface_exists('WC_Log_Handler_Interface')) return;
    require_once OUS_PATH . 'includes/class-wc-log-bridge.php';
    OUS_WCLogBridge::init();
}, 5); // priority 5: before WC_Logger's own get_handlers() call sites might run later the same request
add_action('init',          ['OUS_Search', 'init']);
add_action('init',          ['OUS_SetupWizard', 'init']);
add_action('init',          ['OUS_PortalLayout', 'init']);
add_action('init',          ['OUS_AdminLayout', 'init']);
add_action('init',          ['OUS_DebugLog', 'init']);
add_action('init',          ['OUS_QM_Integration', 'init']);
add_action('init',          ['OUS_TestRunner', 'init']);
add_action('init',          ['OUS_CoreTestSuite', 'init']);
add_action('init',          ['OUS_ReliabilityTestSuite', 'init']);
// New this pass (3.4.51 QA/testing follow-up) — see class-element-test-
// suite.php's own docblock for why: three real bugs in this exact layer
// were only caught by live screenshots tonight, one after another, each
// a class of mistake a cheap deterministic assertion would have caught
// immediately. class_exists() guard mirrors every other test suite's
// registration here — BH_Element itself is always loaded before this
// fires (require order above), but the guard costs nothing and matches
// convention.
if (class_exists('BH_Element')) add_action('init', ['BH_Element_TestSuite', 'init']);
// BH_Studio's own init() registers this pass's default block types with
// BH_Content — must fire after 'content' (BH_Content itself) has loaded,
// which the-self-hosted-self.php's require order above already guarantees, and
// after (or during) the same 'init' hook everything else here uses, so
// no separate hook priority juggling is needed.
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);
add_action('init',          ['OUS_CodebaseDocs', 'init']);
// QA fix, 3.4.85: same nested-'init' bug as OUS_Jobs/OUS_Notifications
// above (see that comment for the full explanation) — BH_Event::init()
// nests a job-handler registrant at priority 5, BH_Identity::init()
// nests maybe_issue_cookie() at priority 1, OUS_Toast::init() nests
// maybe_set_guest_cookie() at priority 1. All three were silently dead:
// a guest's first-touch identity/consent cookie never actually got
// issued, and a toast queued for a not-yet-cookied guest never
// persisted to their next request. Fixed the same way — call ::init()
// directly at this top-level point (well before 'init' fires) instead
// of through another 'init' hook layer.
BH_Event::init();
BH_Identity::init();
OUS_Toast::init();
BH_Storybook_Panel::init();
// QA fix, simulation pass: BHI_Auth::init() was never called anywhere —
// register_routes() (the REST endpoints) gets wired separately via
// rest_api_init below, so login/register/session worked, but init()
// itself (admin_post_bhi_verify_email + the wp_footer verification-toast
// handler) was completely orphaned. Confirmed via a real HTTP hit on
// admin-post.php?action=bhi_verify_email with a fresh, valid token: the
// user meta never updated and the redirect carried no bhi_verified param
// at all — the exact same silent-dead-hook failure mode as the three
// classes just above.
BHI_Auth::init();
// QA fix, 3.4.87: the 3.4.85 changelog claimed OUS_Gutenberg_Block::
// init() was fixed alongside the four classes just above — the fix
// itself (class-gutenberg-block.php's init() calling register_block()
// directly, no nested 'init' hook) WAS real, but the actual call site
// wiring it up was never added anywhere — a genuine incomplete-fix
// regression, caught by a follow-up audit specifically re-verifying
// every claimed fix rather than trusting the changelog. Currently a
// double no-op either way (register_block()'s own class_exists(
// 'BH_Element_Prefab') guard is false post-page-builder-delete), but
// wrong regardless, and would have silently stayed unregistered with
// zero error if that class ever came back.
OUS_Gutenberg_Block::init();
// Element builder (ELEMENT-BUILDER-DESIGN-PLAN.md) — BH_Element_Data
// before BH_Element purely for readability (registers the data
// sources before the element types that might reference them by
// slug); neither init() actually depends on load order since both
// only populate their own private in-memory registries on this same
// 'init' hook, read later by BH_Element::render_slot() at render time.
add_action('init',          ['BH_Element_Data', 'init']);
add_action('init',          ['BH_Element', 'init']);
// 3.4.78 follow-up — BHY_BlockStyle (class-block-style.php): the
// generic "Advanced Styles" InspectorControls panel added to every
// native block, AJ's own explicit ask not to lose the builder-era CSS-
// properties/databinding capability when its bespoke inspector was
// deleted. Hooks 'register_block_type_args'/'enqueue_block_editor_
// assets'/'render_block' directly (not gated behind a class_exists()
// guard the way peer-plugin touches are — this only touches WordPress
// core's own block registration and rendering, the-self-hosted-self's own
// BHY_Style, nothing optional).
add_action('init',          ['BHY_BlockStyle', 'init']);
// 3.4.81 follow-up — BHY_Style::init() (class-style.php): global
// wp_head/block_editor_settings_all token hooks, direct response to the
// gap the BHY_BlockStyle editor-canvas preview work above just exposed
// (see BHY_Style::init()'s own docblock) — --bh-* custom properties
// were only ever available on pages that already knew to echo
// inline_css() themselves (public profile/portal pages), never site-
// wide, so a token-based color set anywhere else produced a real but
// inert CSS declaration.
add_action('init',          ['BHY_Style', 'init']);
// Page-builder delete/keep audit (2026-07-13, doc since deleted — the
// reasoning below is the full record now) — real, live-verified
// cleanup, not a guess: BH_Element/BH_Element_Data (the data model +
// render_slot() engine, immediately above) are confirmed LIVE — real
// pages in bh-contest, bh-crm, bh-courses, the-self-hosted-self's own dashboard/
// portal all render through render_slot() today. Everything that used
// to sit ON TOP of that engine as a custom hand-rolled authoring UI is
// gone as of this pass: BH_Element_Prefab (class-element-prefab.php —
// the custom Components/linked-instance/override system; WordPress's
// own native synced Patterns do this job directly), BH_Element_State
// (class-element-state.php — fixture states/Storybook-style preview
// contexts; confirmed ZERO consumers anywhere outside the now-deleted
// builder UI), BH_Element_Builder (class-element-builder.php — the
// enqueue/localize glue for the equally-deleted assets/js/element-
// builder.js canvas), and BH_Component_Studio (class-component-
// studio.php — this SAME session's own first attempt at a smaller
// replacement, held to the identical standard rather than protected
// from it: a bespoke per-Component HTML/CSS/JS block is still a bespoke
// editing mechanism where typed, native Gutenberg block types with real
// render_callback()s are simpler and more idiomatic). All four files
// (plus assets/js/element-builder.js, assets/css/element-builder.css,
// assets/js/component-studio.js, assets/css/component-studio.css) are
// deleted, not just unhooked — the audit doc's own table had the full
// file-by-file reasoning and line counts; this comment is now the
// summary of record since that doc was deleted.
//
// OUS_Gutenberg_Block (class-gutenberg-block.php) is DELIBERATELY LEFT
// IN PLACE, unlike the others — its own register_block() already guards
// on class_exists('BH_Element_Prefab') (true before this pass, false
// after), so it now silently no-ops instead of registering its embed
// block, the same "harmless no-op" posture every other optional
// integration in this ecosystem already uses. This is intentionally
// NOT a hard delete: the audit couldn't confirm from code alone whether
// any real published post actually embeds the 'the-self-hosted-self/element-
// prefab' block (that needs a real `post_content LIKE` query against
// the live database, not available in this environment) — if a real
// post out there uses it, this now renders nothing instead of fataling,
// which is the safe failure mode until that check happens.
add_filter('bh_element_surfaces', ['OUS_Dashboard', 'register_element_surface']);
// ELEMENT-BUILDER-DESIGN-PLAN.md §5.4 — Portal as a real bh_element_surfaces
// contributor, mirroring OUS_Dashboard's/BHCRM_People's own registration
// line here exactly. BHI_Portal::init() (called via the 'init' hook
// object-registered elsewhere in this same file, see BHY_Gallery/etc.
// below) separately hooks its own 'bhi_portal_panels' registrant for the
// one new element-composed panel this phase ships — see class-portal.php.
add_filter('bh_element_surfaces', ['BHI_Portal', 'register_element_surface']);

// Templates first: peers register their views/ dirs on bhy_view_namespaces,
// and anything rendering during init must find the engine already up.
add_action('init', ['BHY_View', 'init'], 5);
add_action('init', ['BHY_Gallery', 'init']);
add_action('init', ['BHY_UI', 'init_shared_admin_assets']);
BHY_UI::pin_hidden_submenus_to_bottom();

/**
 * Hub role: unchanged in spirit, reduced in scope now that identity and
 * style aren't separate installable things anymore — the registry only
 * needs to track bh-contest and bh-streaming from here on.
 */
add_action('admin_menu',    ['OUS_Dashboard', 'add_menu']);
// DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 — registered directly here
// (not deferred to the 'init' hook the way BHY_Gallery/BH_Studio's own
// add_menu() calls are) so it lands in the 'admin_menu' callback queue
// BEFORE those two plugins' own init()-hooked registrations, and well
// before OUS_MenuMerge's relocation pass at priority 999 — the top-level
// parent must exist before anything tries to attach a submenu to it
// (§1.2's sequencing hazard note). Same direct-registration style
// OUS_Dashboard::add_menu() uses immediately above.
add_action('admin_menu',    ['BH_Design_Suite', 'add_menu']);
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
add_action('init', ['BH_CommerceProviders', 'init']);
add_action('init', ['OUS_MenuSync', 'init']);
register_activation_hook(__FILE__, function () {
    // BHI_Portal::add_rewrite() also runs on every 'init', but the
    // rewrite rule needs an explicit flush once so /account/ resolves
    // immediately on activation rather than waiting for WordPress's own
    // rewrite cache to naturally regenerate.
    BHI_Portal::add_rewrite();
    flush_rewrite_rules();
});
