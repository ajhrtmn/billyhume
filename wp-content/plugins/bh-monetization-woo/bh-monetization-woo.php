<?php
/**
 * Plugin Name: BH Monetization (WooCommerce)
 * Description: Sales for Billy's site through WooCommerce: course products, supporter tiers, gift access, launch coupons, tips, guest checkout and refund/fraud flagging. WooCommerce owns the cart and payments.
 * Version:     0.7.2
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHM_VER',  '0.7.2');

define('BHM_PATH', plugin_dir_path(__FILE__));
define('BHM_URL',  plugin_dir_url(__FILE__));

/**
 * Courses work without this plugin; WooCommerce is
 * needed only once a monetization option is used, and WC Subscriptions is optional (tiers fall back
 * to one-time 30-day access).
 */
foreach (['words', 'tables', 'money', 'activator', 'tiers', 'gate', 'post-gate', 'wallet', 'fraud', 'admin', 'product-sync', 'entitlements', 'gifts', 'gift-access', 'auto-coupons', 'guest-buyers', 'referrals', 'downloads', 'frontend', 'style-surface', 'debug', 'mock-commerce', 'crm-integration', 'portal-panel', 'recommendations', 'storefront', 'test-suite', 'blocks'] as $f) {
    require_once BHM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHM_Activator', 'activate']);

// WooCommerce is checked per feature; a blanket notice would nag sites that haven't opted in.
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Monetization</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHM_Activator::maybe_upgrade();

    // The tiers page uses the ecosystem's standalone chrome.
    add_filter('bh_standalone_surface', function ($is) {
        if ($is) return true;
        $tiers_page = (int) get_option('bhm_tiers_page_id', 0);
        return $tiers_page && is_page($tiers_page);
    });

    add_action('init',          ['BHM_Tiers', 'init']);
    add_action('init',          ['BHM_Gate', 'init']);
    add_action('init',          ['BHM_PostGate', 'init']);
    add_action('init',          ['BHM_Wallet', 'init']);
    add_action('init',          ['BHM_Admin', 'init']);
    add_action('init',          ['BHM_Entitlements', 'init']);
    add_action('init',          ['BHM_Gifts', 'init']);
    add_action('init',          ['BHM_Referrals', 'init']);
    add_action('init',          ['BHM_Downloads', 'init']);
    add_action('init',          ['BHM_Frontend', 'init']);
    add_action('init',          ['BHM_Blocks', 'init']);
    add_action('init',          ['BHM_StyleSurface', 'init']);
    // Design tokens for .bhm-tier-card and .bhm-btn; defaults match the old hardcoded CSS.
    if (class_exists('BHY_Style')) {
        add_filter('bhy_style_component_tokens', function ($components) {
            $components['tier_card'] = [
                'label' => 'Supporter tier card',
                'selector' => '.bhm-tier-card',
                'tokens' => [
                    'radius'  => ['label' => 'Corner radius', 'type' => 'size', 'min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 8],
                    'padding' => ['label' => 'Padding',       'type' => 'size', 'min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px', 'default' => 20],
                ],
            ];
            $components['button'] = [
                'label' => 'Button',
                'selector' => '.bhm-btn',
                'tokens' => [
                    'radius'    => ['label' => 'Corner radius',      'type' => 'size', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px', 'default' => 6],
                    'padding_v' => ['label' => 'Vertical padding',   'type' => 'size', 'min' => 4, 'max' => 20,  'step' => 1, 'unit' => 'px', 'default' => 10],
                    'padding_h' => ['label' => 'Horizontal padding', 'type' => 'size', 'min' => 8, 'max' => 32,  'step' => 1, 'unit' => 'px', 'default' => 18],
                ],
            ];
            return $components;
        });
    }
    add_action('init',          ['BHM_Debug', 'init']);
    add_action('init',          ['BHM_MockCommerce', 'init']);
    add_action('init',          ['BHM_CRMIntegration', 'init']);
    // Harmless if core's portal is absent.
    add_action('init',          ['BHM_PortalPanel', 'init']);
    add_action('init',          ['BHM_GiftAccess', 'init']);
    add_action('init',          ['BHM_AutoCoupons', 'init']);
    add_action('init',          ['BHM_GuestBuyers', 'init']);
    // Called directly: it registers its own 'init' hooks.
    BHM_Storefront::init();
    add_action('init',          ['BHM_TestSuite', 'init']);
    add_action('rest_api_init', ['BHM_Frontend', 'register_routes']);
});
