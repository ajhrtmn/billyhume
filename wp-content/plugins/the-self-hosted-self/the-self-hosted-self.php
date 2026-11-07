<?php
/**
 * Plugin Name: The Self-Hosted Self
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.21.51
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('OUS_VER', '3.21.51');

define('OUS_PATH', plugin_dir_path(__FILE__));
define('OUS_URL',  plugin_dir_url(__FILE__));

// The canonical "core is active" signal dependents check: a constant, so
// internal class names can change without breaking them.
define('BHCORE_LOADED', true);

/**
 * WHY one plugin: identity (BHI_*) and style (BHY_*) live in core so
 * dependents never race PHP's alphabetical plugin-load order. Contest and
 * Streaming stay separate plugins.
 */
foreach (['tables', 'pages', 'registry', 'dashboard', 'installer', 'activation-manager', 'setup-wizard', 'banner', 'menu-merge', 'menu-icons', 'admin-guard', 'list-table', 'debug', 'debug-log', 'qm-integration', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'member-hardening', 'identity-activator', 'style', 'color', 'contrast', 'ui', 'style-gallery', 'customizer', 'notifications', 'jobs', 'roles', 'role-assignment', 'audit', 'revisions', 'search', 'admin-layout', 'content', 'commerce-provider', 'commerce-provider-woocommerce', 'commerce-providers', 'commerce', 'rewrite-healer', 'portal', 'portal-layout', 'menu-sync', 'visibility', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'badge', 'element', 'contrast-test-suite', 'design-suite', 'storybook-panel', 'block-style', 'share-card', 'media-wizard', 'media-token', 'seo', 'metrics', 'user-bar', 'campaigns', 'privacy', 'dmca', 'dmca-notices', 'mail', 'integration', 'hypermedia', 'github-updates'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
register_activation_hook(__FILE__, ['OUS_Audit', 'activate']);
register_activation_hook(__FILE__, ['OUS_Revisions', 'activate']);
register_deactivation_hook(__FILE__, function () {
    // Only this plugin's own cron event; the job queue table is kept so
    // reactivating resumes where it left off.
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
        // Root selector for Customizer click-to-select.
        'selector' => '.bh-badge',
        'tokens' => [
            'padding_v'   => ['label' => 'Vertical padding',   'type' => 'size', 'min' => 0, 'max' => 10, 'step' => 1, 'unit' => 'px', 'default' => 2],
            'padding_h'   => ['label' => 'Horizontal padding', 'type' => 'size', 'min' => 4, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 10],
            'radius'      => ['label' => 'Corner radius',      'type' => 'size', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px', 'default' => 999],
            'font_size'   => ['label' => 'Text size',          'type' => 'size', 'min' => 9, 'max' => 16, 'step' => 1, 'unit' => 'px', 'default' => 11],
            'font_weight' => ['label' => 'Text weight',        'type' => 'size', 'min' => 400, 'max' => 800, 'step' => 100, 'unit' => '', 'default' => 600],
            // Neutral variant colors, editable like the other properties.
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
add_action('init',          ['OUS_UserBar', 'init']);
add_action('init',          ['OUS_Audit', 'init']);
add_action('init',          ['OUS_Revisions', 'init']);
// WHY deferred to 'init': this class implements WC_Log_Handler_Interface, which
// WooCommerce defines only when its own main file loads, after this one
// alphabetically. Requiring it in the foreach above would fatal.
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

// Registers the default block types with BH_Content (loaded above).
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);
add_action('init',          ['OUS_CodebaseDocs', 'init']);

BH_Event::init();
BH_Identity::init();
OUS_Toast::init();
BH_Storybook_Panel::init();

// Placement storage behind the bh-crm project board.
add_action('init',          ['BH_Element', 'init']);

add_action('init',          ['BHY_BlockStyle', 'init']);
add_action('init',          ['BHY_Style', 'init']);

// Derives dependent color roles from the seed colors and registers its audit.
// Its init() hooks the bhy_style_settings option, so it must follow BHY_Style.
add_action('init',          ['BHY_Contrast', 'init']);

add_action('init', ['BHY_Gallery', 'init']);

BHY_Customizer::init();
add_action('init', ['BHY_UI', 'init_shared_admin_assets']);
BHY_UI::pin_hidden_submenus_to_bottom();

add_action('admin_menu',    ['OUS_Dashboard', 'add_menu']);

// WHY registered directly, not via 'init': the top-level 'bh-design' parent must
// exist before BHY_Gallery/BH_Studio attach to it and before OUS_MenuMerge (999).
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
    // Explicit flush so /account/ resolves immediately on activation.
    BHI_Portal::add_rewrite();
    flush_rewrite_rules();
});
