<?php
/**
 * Plugin Name: The Self-Hosted Self
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.21.45
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('OUS_VER', '3.21.45');

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
foreach (['tables', 'view', 'pages', 'registry', 'dashboard', 'installer', 'activation-manager', 'setup-wizard', 'banner', 'menu-merge', 'menu-icons', 'admin-guard', 'list-table', 'debug', 'debug-log', 'qm-integration', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'member-hardening', 'identity-activator', 'style', 'color', 'contrast', 'ui', 'style-gallery', 'customizer', 'notifications', 'jobs', 'roles', 'role-assignment', 'audit', 'revisions', 'search', 'admin-layout', 'content', 'commerce-provider', 'commerce-provider-woocommerce', 'commerce-providers', 'commerce', 'rewrite-healer', 'portal', 'portal-layout', 'menu-sync', 'visibility', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'badge', 'element-data', 'element', 'element-test-suite', 'contrast-test-suite', 'design-suite', 'storybook-panel', 'gutenberg-block', 'block-style', 'share-card', 'media-wizard', 'media-token', 'seo', 'metrics', 'user-bar', 'campaigns', 'page-surface', 'privacy', 'dmca', 'dmca-notices', 'mail', 'integration', 'hypermedia', 'github-updates'] as $f) {
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
add_action('init',          ['BHI_MemberHardening', 'init']);
add_action('init',          ['BHI_PublicProfile', 'init']);
add_action('init',          ['BHI_Reports', 'init']);
add_action('init',          ['OUS_MediaWizard', 'init']);
add_action('init',          ['BHY_MediaToken', 'init']);
add_action('init',          ['BH_SEO', 'init']);
add_action('init',          ['OUS_Metrics', 'init']);
add_action('rest_api_init', ['BHI_Reports', 'register_routes']);
add_action('init',          ['BHI_TwoFactor', 'init']);
add_action('init',          ['OUS_Privacy', 'init']);
add_action('init',          ['OUS_DMCA', 'init']);
add_action('init',          ['OUS_DMCA_Notices', 'init']);

add_filter('cron_schedules', ['OUS_Jobs', 'register_cron_schedule']);

OUS_Pages::init();
OUS_Jobs::init();
OUS_Notifications::init();
add_action('init',          ['OUS_Roles', 'init']);
add_action('init',          ['OUS_RoleAssignment', 'init']);
add_action('init',          ['OUS_Campaigns', 'init']);
add_action('init',          ['OUS_Integration', 'init']);
add_action('init',          ['OUS_Hypermedia', 'init']);
add_action('init',          ['OUS_GithubUpdates', 'init']);


add_filter('bhy_style_component_tokens', function ($components) {
    $components['badge'] = [
        'label' => 'Badges & pills',
        // Click-to-select in BHY_Customizer's live preview (class-
        // customizer.php) — the real CSS selector for this component's
        // root element, so clicking any actual badge on a real page
        // jumps straight to this section.
        'selector' => '.bh-badge',
        'tokens' => [
            'padding_v'   => ['label' => 'Vertical padding',   'type' => 'size', 'min' => 0, 'max' => 10, 'step' => 1, 'unit' => 'px', 'default' => 2],
            'padding_h'   => ['label' => 'Horizontal padding', 'type' => 'size', 'min' => 4, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 10],
            'radius'      => ['label' => 'Corner radius',      'type' => 'size', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px', 'default' => 999],
            'font_size'   => ['label' => 'Text size',          'type' => 'size', 'min' => 9, 'max' => 16, 'step' => 1, 'unit' => 'px', 'default' => 11],
            'font_weight' => ['label' => 'Text weight',        'type' => 'size', 'min' => 400, 'max' => 800, 'step' => 100, 'unit' => '', 'default' => 600],
            // "More complete and standardized" (AJ, 2026-09-19): the
            // neutral badge variant's own background/text color, editable
            // like every other property here instead of only being
            // reachable via the .bh-badge-success/-warning/-danger
            // hardcoded variant classes.
            'bg_color'    => ['label' => 'Background color', 'type' => 'color', 'default' => '#f0f0f1'],
            'text_color'  => ['label' => 'Text color',       'type' => 'color', 'default' => '#646970'],
        ],
    ];
    return $components;
});

add_action('init', function () {
    if (class_exists('OUS_Integration')) {
        OUS_Integration::register('bh_mail', [
            'label' => 'Transactional email',
            'description' => 'One-to-one/event-triggered email (notifications, receipts, confirmations).',
            'builtin_class' => 'BH_Mail',
        ]);

        OUS_Integration::register('email_broadcast', [
            'label' => 'Email broadcast / marketing',
            'description' => 'Reach a live-queried audience (a bh-crm segment, or everyone) with a one-off or list-driven email.',
            'builtin_class' => 'OUS_Campaigns',
        ]);

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
}, 5);
add_action('init',          ['OUS_Search', 'init']);
add_action('init',          ['OUS_SetupWizard', 'init']);
add_action('init',          ['OUS_PortalLayout', 'init']);
add_action('init',          ['OUS_AdminLayout', 'init']);
add_action('init',          ['OUS_DebugLog', 'init']);
add_action('init',          ['OUS_QM_Integration', 'init']);
add_action('init',          ['OUS_TestRunner', 'init']);
add_action('init',          ['OUS_CoreTestSuite', 'init']);
add_action('init',          ['OUS_ReliabilityTestSuite', 'init']);

// if (class_exists('BH_Element')) add_action('init', ['BH_Element_TestSuite', 'init']);

// BH_Studio's own init() registers this pass's default block types with
// BH_Content — must fire after 'content' (BH_Content itself) has loaded,
// which the-self-hosted-self.php's require order above already guarantees, and
// after (or during) the same 'init' hook everything else here uses, so
// no separate hook priority juggling is needed.
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);
add_action('init',          ['OUS_CodebaseDocs', 'init']);

BH_Event::init();
BH_Identity::init();
OUS_Toast::init();
BH_Storybook_Panel::init();
BHI_Auth::init();
OUS_Gutenberg_Block::init();

add_action('init',          ['BH_Element_Data', 'init']);
add_action('init',          ['BH_Element', 'init']);

add_action('init',          ['BHY_BlockStyle', 'init']);
add_action('init',          ['BHY_Style', 'init']);

// BHY_Contrast (class-contrast.php): derives the dependent colour roles
// (inks, muted/disabled text, strong borders, focus ring, quiet-UI
// fills, on-* pairs) from the Design Suite seed colours, keeps them
// contrast-valid on any theme, and registers its own audit under Debug
// Tools + a Test Runner suite. Its init() hooks update_option_/
// add_option_bhy_style_settings, so it must be wired after BHY_Style.
add_action('init',          ['BHY_Contrast', 'init']);


add_filter('bh_element_surfaces', ['OUS_Dashboard', 'register_element_surface']);
add_filter('bh_element_surfaces', ['BHI_Portal', 'register_element_surface']);

// Templates first: peers register their views/ dirs on bhy_view_namespaces,
// and anything rendering during init must find the engine already up.
add_action('init', ['BHY_View', 'init'], 5);
add_action('init', ['BHY_Gallery', 'init']);

BHY_Customizer::init();
add_action('init', ['BHY_UI', 'init_shared_admin_assets']);
BHY_UI::pin_hidden_submenus_to_bottom();

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
