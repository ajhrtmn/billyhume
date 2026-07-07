<?php
/**
 * Plugin Name: BH Monetization (WooCommerce)
 * Description: Artist monetization for bh-streaming — subscriptions, tips, pay-per-play, track/album purchase with lossless+compressed delivery, streaming-tier access, and refund/velocity fraud-pattern flagging — all backed by WooCommerce, never a parallel payments stack.
 * Version:     0.2.0
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: Own Ur Shit
 */
if (!defined('ABSPATH')) exit;

define('BHM_VER',  '0.2.0');
define('BHM_PATH', plugin_dir_path(__FILE__));
define('BHM_URL',  plugin_dir_url(__FILE__));

/**
 * The defining constraint of this whole plugin: an artist who wants
 * ZERO monetization must pay zero complexity cost. Concretely:
 *
 * - This plugin installs and activates independently of bh-streaming —
 *   bh-streaming never requires it, and never even calls into it unless
 *   this plugin is both installed AND active (checked via the
 *   'bhs_monetization_options' filter bh-streaming defines and applies
 *   with an empty default — see bh-streaming's own admin/API classes
 *   for where that filter is called).
 * - WooCommerce ITSELF only ever becomes a hard requirement the moment
 *   an artist actually turns a monetization option on — this plugin
 *   can be installed and simply do nothing (show an "install
 *   WooCommerce to enable this" notice) until WooCommerce is present.
 *   Exactly the same on-demand-install pattern the core's own
 *   OUS_Registry/OUS_Installer already use for third-party
 *   dependencies (see 'wporg_slug' in class-admin.php below).
 * - WooCommerce Subscriptions (a SEPARATE, paid, official WooCommerce
 *   extension — WooCommerce core has no recurring-billing support of
 *   its own) is treated as a further OPTIONAL dependency on top of
 *   WooCommerce: detected via class_exists('WC_Subscriptions'), never
 *   required. Without it, every monetization option EXCEPT the ongoing
 *   subscription tier still works on plain WooCommerce alone — the
 *   subscription option simply shows as unavailable with a plain-
 *   language explanation, rather than this plugin silently building its
 *   own parallel recurring-billing logic (which would directly violate
 *   the ecosystem's "don't reinvent what already exists" principle).
 */
foreach (['activator', 'tiers', 'gate', 'wallet', 'fraud', 'admin', 'products', 'downloads', 'frontend', 'style-surface', 'debug', 'crm-integration', 'portal-panel', 'storefront', 'test-suite'] as $f) {
    require_once BHM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHM_Activator', 'activate']);

// Gated on plugins_loaded, never at file-parse time — same rationale as
// every other plugin in this ecosystem (see bh-streaming.php's own
// docblock on this for the full history). BHCORE_LOADED is the core's
// own marker constant; WooCommerce's presence is checked separately,
// per-feature, inside BHM_Products/BHM_Admin themselves, since (unlike
// the core) WooCommerce is meant to be ABSENT on install and only
// required once an artist opts in — a blanket admin_notice here would
// incorrectly nag every site that installs this plugin before deciding
// to use it at all.
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Monetization</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHM_Activator::maybe_upgrade();

    add_action('init',          ['BHM_Tiers', 'init']);
    add_action('init',          ['BHM_Gate', 'init']);
    add_action('init',          ['BHM_Wallet', 'init']);
    add_action('init',          ['BHM_Admin', 'init']);
    add_action('init',          ['BHM_Products', 'init']);
    add_action('init',          ['BHM_Downloads', 'init']);
    add_action('init',          ['BHM_Frontend', 'init']);
    add_action('init',          ['BHM_StyleSurface', 'init']);
    add_action('init',          ['BHM_Debug', 'init']);
    add_action('init',          ['BHM_CRMIntegration', 'init']);
    // Portal panel is a class_exists()-guarded consumer of BHI_Portal's
    // filter, not a hard dependency — add_filter() on a filter nobody
    // applies (core not present/too old to have BHI_Portal) is harmless,
    // same convention as every other cross-plugin registration here.
    add_action('init',          ['BHM_PortalPanel', 'init']);
    add_action('init',          ['BHM_Storefront', 'init']);
    add_action('init',          ['BHM_TestSuite', 'init']);
    add_action('rest_api_init', ['BHM_Frontend', 'register_routes']);
});
