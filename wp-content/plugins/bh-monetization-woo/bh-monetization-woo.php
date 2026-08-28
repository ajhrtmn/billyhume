<?php
/**
 * Plugin Name: BH Monetization (WooCommerce)
 * Description: Artist monetization for bh-streaming — subscriptions, tips, pay-per-play, track/album purchase with lossless+compressed delivery, streaming-tier access, and refund/velocity fraud-pattern flagging — all backed by WooCommerce, never a parallel payments stack.
 * Version:     0.6.3
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 * Ecosystem: The Self-Hosted Self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHM_VER',  '0.6.3');

define('BHM_PATH', plugin_dir_path(__FILE__));
define('BHM_URL',  plugin_dir_url(__FILE__));

/**
 * Defining constraint: an artist who wants zero monetization pays zero
 * complexity cost.
 *
 * - Installs/activates independently of bh-streaming, which never calls
 *   into this plugin unless it's both installed and active (checked via
 *   the 'bhs_monetization_options' filter bh-streaming defines with an
 *   empty default).
 * - WooCommerce only becomes a hard requirement once an artist turns a
 *   monetization option on — until then this plugin just shows an
 *   "install WooCommerce" notice (same on-demand-install pattern as
 *   OUS_Registry/OUS_Installer, see 'wporg_slug' in class-admin.php).
 * - WooCommerce Subscriptions is an optional dependency on top of
 *   WooCommerce (detected via class_exists('WC_Subscriptions'), never
 *   required): without it, every option except the ongoing subscription
 *   tier still works on plain WooCommerce — that option just shows as
 *   unavailable rather than this plugin building its own parallel
 *   recurring-billing logic.
 */
foreach (['tables', 'money', 'activator', 'tiers', 'gate', 'post-gate', 'wallet', 'fraud', 'admin', 'product-sync', 'monetization-ui', 'play-gating', 'entitlements', 'products', 'gifts', 'referrals', 'downloads', 'frontend', 'style-surface', 'debug', 'mock-commerce', 'crm-integration', 'portal-panel', 'recommendations', 'storefront', 'test-suite', 'blocks', 'anchoring', 'purchase-ledger', 'ledger-crm-integration', 'auctions', 'auction-admin', 'auction-frontend'] as $f) {
    require_once BHM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHM_Activator', 'activate']);

// WooCommerce's presence is checked separately, per-feature, inside
// BHM_Products/BHM_Admin — unlike the core plugin, WooCommerce is meant to
// be absent on install and only required once an artist opts in, so a
// blanket admin_notice here would nag sites that haven't decided to use it.
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Monetization</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHM_Activator::maybe_upgrade();

    add_action('init',          ['BHM_Tiers', 'init']);
    add_action('init',          ['BHM_Gate', 'init']);
    add_action('init',          ['BHM_PostGate', 'init']);
    add_action('init',          ['BHM_Wallet', 'init']);
    add_action('init',          ['BHM_Admin', 'init']);
    add_action('init',          ['BHM_Products', 'init']);
    add_action('init',          ['BHM_Gifts', 'init']);
    add_action('init',          ['BHM_Referrals', 'init']);
    add_action('init',          ['BHM_Downloads', 'init']);
    add_action('init',          ['BHM_Frontend', 'init']);
    add_action('init',          ['BHM_Blocks', 'init']);
    add_action('init',          ['BHM_StyleSurface', 'init']);
    add_action('init',          ['BHM_Debug', 'init']);
    add_action('init',          ['BHM_MockCommerce', 'init']);
    add_action('init',          ['BHM_CRMIntegration', 'init']);
    // Ledger-anchored proof of purchase (ROADMAP-streaming-media-scope-
    // and-blockchain.md Part 2) — anchoring must init before the ledger,
    // since the ledger calls BHM_Anchoring::anchor_async() the moment a
    // row is written.
    add_action('init',          ['BHM_Anchoring', 'init']);
    add_action('init',          ['BHM_PurchaseLedger', 'init']);
    add_action('init',          ['BHM_LedgerCRMIntegration', 'init']);
    // Auction listings (Section 5a) — registers the OUS_Jobs handler
    // that actually closes/finalizes an auction at its scheduled end
    // time; see class-auctions.php's own docblock for the full design.
    add_action('init',          ['BHM_Auctions', 'init']);
    // Authoring metabox (BHM_AuctionAdmin) + front-end bid form
    // (BHM_AuctionFrontend) — the "next pass" class-auctions.php's own
    // docblock flagged as not yet built; shipped 2026-08-26.
    add_action('init',          ['BHM_AuctionAdmin', 'init']);
    add_action('init',          ['BHM_AuctionFrontend', 'init']);
    // BHM_PortalPanel is a class_exists()-guarded consumer of BHI_Portal's
    // filter, not a hard dependency — harmless if core is absent/too old.
    add_action('init',          ['BHM_PortalPanel', 'init']);
    // Called directly (not via a nested 'init' hook) — see class-blocks.php's
    // BHM_Blocks::init() docblock for why a nested add_action('init', ...)
    // registered from inside an already-executing 'init' callback never
    // fires in that request.
    BHM_Storefront::init();
    add_action('init',          ['BHM_TestSuite', 'init']);
    add_action('rest_api_init', ['BHM_Frontend', 'register_routes']);
});
