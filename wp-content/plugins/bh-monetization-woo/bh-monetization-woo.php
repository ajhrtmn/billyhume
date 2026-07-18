<?php
/**
 * Plugin Name: BH Monetization (WooCommerce)
 * Description: Artist monetization for bh-streaming — subscriptions, tips, pay-per-play, track/album purchase with lossless+compressed delivery, streaming-tier access, and refund/velocity fraud-pattern flagging — all backed by WooCommerce, never a parallel payments stack.
 * Version:     0.5.1
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: Own Ur Shit
 */
if (!defined('ABSPATH')) exit;

// 0.4.17 — BHM_Tiers::save() now logs a before/after diff of price_cents/
// annual_price_cents on every tier save, and tier deletion logs the tier's
// name and price before it's gone.

// 0.4.16 — wallet top-up fraud/abuse velocity cap: BHM_Fraud::
// track_topup_velocity() flags an account (surface for a human, never
// auto-block) when purchased top-ups exceed $500 in a rolling 24h window
// (filterable via 'bhm_topup_velocity_cap_cents'). Only fires for the
// 'topup' reason — admin grants and refund-reversal adjustments don't count.

// 0.4.15 — BHM_CRMIntegration::activity_summary() (wallet balance, active
// tier, purchase history, refund-fraud flags on the CRM person page) now
// requires the admin-only bhcore_view_crm_sensitive capability instead of
// bhcore_manage_crm; a non-admin manager sees nothing from this integration
// rather than a redacted version.

// 0.4.14 — BHM_Wallet::debit()/apply_delta() now emit BH_Event
// 'bhm/wallet_debit'/'wallet_credit' after each ledger write, feeding the
// CRM's unified per-person activity timeline (bh-crm 1.9.0). Additive only.

// 0.4.13 — wrapped BHM_PortalPanel::render()'s "Active tiers"/"Wallet"
// sections in the portal's shared .bhi-portal-section card class —
// previously bare h2/p/ul/table content with no separating divs.

// 0.4.3 — BHM_TestSuite gained DB-backed coverage for BHM_Wallet::debit()/
// apply_ledger_delta() (balance/ledger consistency, the atomic-UPDATE
// insufficient-balance decline).

// 0.4.2 — (1) BHM_Storefront::add_rewrite() upgraded from a version-gated
// "flush once, never re-verify" pattern to BHI_Portal's self-verifying
// shape, since the two classes shared the same fragile pattern. (2)
// BHM_Wallet::debit()/apply_delta() previously failed silently on both a
// declined debit and a balance/ledger desync — now logged via OUS_DebugLog
// at 'info'/'error' respectively, since a balance disagreeing with its own
// ledger is a real money-handling integrity risk.

// 0.4.0 — structured per-tier benefit lists, tier cover images, annual
// pricing alongside monthly, and the bhm_entitlement_granted/
// bhm_entitlement_revoked action pair. See class-tiers.php/class-products.php.
//
// 0.4.1 — wallet top-up/tip-jar product sync and the order/subscription-
// lifecycle handlers in class-products.php now go through BH_Commerce
// instead of touching WC_Order/WC_Subscription/WC_Product directly.
// 0.4.4 — bundled zip regenerated to match installed version, no code change
// 0.4.5 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET on this plugin's Debug Tools section.
//
// 0.4.6 — WooCommerce Subscriptions' native on-hold/pause status fired the
// same event bus as active/cancelled/expired, but nothing here listened for
// it — a fan who paused billing kept their tier-gated entitlement forever.
// Fixed by adding a woocommerce_subscription_status_on-hold listener
// (on_subscription_paused()) that revokes through a shared
// revoke_subscription_entitlements($subscription, $reason), extracted from
// on_subscription_ended()'s prior body so both callers share one revoke path
// with distinct reason strings. on_subscription_active() already re-grants
// on resume, no change needed there. Not yet clicked through live end-to-end
// since WooCommerce Subscriptions (a paid extension) isn't installed here.
//
// 0.4.7 — pay-what-you-want purchases, reusing the tip jar's cart-item-
// price-override pattern (apply_tip_price()/apply_tip_amount()) rather than
// building new variable-price plumbing: apply_purchase_price()/
// apply_purchase_amount() key off the same _bhm_purchase_price_cents meta a
// fixed-price purchase uses — when PWYW is on it's reinterpreted as a floor.
// New [bhm_buy id="<track-or-release-id>"] shortcode (render_purchase_
// button()) — previously no front-end "buy outright" entry point existed;
// purchase products were server-side only, reachable via a direct
// add-to-cart URL nothing linked to.
//
// 0.4.8 — a branded "Pause subscription"/"Resume subscription" control on a
// fan's active tier card (render_subscription_controls()), matching this
// class's existing thin-wrapper-around-WooCommerce posture. Only renders for
// a real recurring subscription (a bhm_entitlements row with a real
// wc_subscription_id). handle_manage_subscription() verifies both the nonce
// and that the subscription's get_user_id() matches the requesting user
// before calling WC_Subscription::update_status().
// 0.4.20 — free trials. A tier's edit screen (class-tiers.php) gets a "Free
// trial (days)" field, synced to both the monthly and annual WC Subscription
// product via BH_Commerce::upsert_product()'s trial_length/trial_period
// args, and surfaced on the fan-facing tier picker as an "N-day free trial"
// badge.
// 0.4.21 — bug fix: BHM_PortalPanel::active_entitlements() queried a
// nonexistent `tier_id` column on `bhm_entitlements` (the real column is
// `object_id`), so the portal's "Membership & Wallet" panel silently showed
// "No active supporter tier" for every user regardless of real state. Fixed
// the column name, and scoped the query to type IN
// ('subscription','streaming_tier') since this table also holds one-time
// purchase entitlements.
// 0.4.22 — gift memberships. A "Gift this" form on the tier picker captures
// a recipient email at add-to-cart time (BHM_Gifts::capture_gift_email());
// on_order_completed() checks for it and, instead of granting the buyer an
// entitlement, creates a redemption code (bhm_gift_redemptions) and emails
// the recipient a claim link. [bhm_redeem_gift] renders the claim form;
// claiming grants a real 30-day streaming_tier entitlement via
// BHM_Products::grant_gift_entitlement(). A matching Debug Tools action
// (simulate_gift_order) drives the same order-completion path as the
// existing tier-order simulation, since wp_mail() isn't reliable on a bare
// local install and redemption needs to be testable without real email.
// 0.4.23 — added save_post_page auto-detect for any page carrying
// [bhm_redeem_gift] (BHM_Gifts::redeem_page_url()), matching the existing
// tiers-page convention — previously fell back to the homepage until an
// admin manually wired up an option with no settings UI.
// 0.5.0 — storefront/merchandising: individual product pages and
// "customers also bought" relations.
//   1. BHM_Recommendations (new) — content-based scoring reusing bh-
//      streaming's BHS_Recommendations approach (shared bhm_collection/
//      product_cat/product_tag terms, weighted 3/2/1). Every single-product
//      page gets a "You may also like" section automatically
//      (woocommerce_after_single_product_summary).
//   2. Gutenberg registration for bhm/product-grid, bhm/product-filter, and
//      new bhm/related-products (register_block_type() + render_callback,
//      reusing the same PHP renderers BH_Content's registration calls).
//   3. Bug fix: WooCommerce core hardcodes the block editor off for
//      products (WC_Post_Types::gutenberg_can_edit_post_type() always
//      returns false). Added a later-priority filter override.
//   4. Two bug fixes found while polishing the single-product page:
//      storefront.css referenced a never-defined --bhy-color-* token
//      scheme (same class of bug already found in class-portal.php),
//      rewritten to the real --bh-* tokens BHY_Style emits; and the
//      price/button rules only matched the classic WooCommerce template's
//      markup, not Woo Blocks' DOM shape — rescoped to selectors stable
//      across both template modes.
// 0.5.1 — first consumer of own-ur-shit's OUS_Revisions shared service. A
// tier's full field set is a clean fit (an overwrite-on-save single object,
// unlike bh-crm's append-only notes). BHM_Tiers::save() now snapshots the
// tier's complete state on every save; the tier edit screen gets a "Version
// History" panel with Restore buttons that re-apply a prior version through
// the same save path (including re-syncing the WooCommerce product).
define('BHM_VER',  '0.5.1');

// 0.4.19 — "Get Paid" card on the Monetization Settings screen
// (BHM_Admin::render_get_paid_card()): checks WC_Payment_Gateways::
// get_available_payment_gateways() for whether any gateway is enabled, plus
// a link into WooCommerce core's own guided payments setup. This plugin has
// no gateway config screen of its own — real credentials live in
// WooCommerce core.

// 0.4.18 — first contributor to own-ur-shit's shared Metrics dashboard:
// two widgets in class-crm-integration.php (Active supporters, New
// entitlements). Reads bhm_entitlements directly rather than BH_Event,
// since no purchase/entitlement event exists yet.

// 0.4.12 — class-crm-integration.php's activity_summary() entitlements
// query (ORDER BY created_at DESC) had no id tiebreaker, which the loop
// below depends on to pick the fan's "active tier" (most recently granted
// non-expired one) — a bulk migration landing two entitlements in the same
// second could silently pick the wrong tier. Fixed with `, id DESC`.

// 0.4.11 — third shortcode-to-block conversion, 'bhm/tiers' (class-
// blocks.php, assets/js/bhm-blocks.js), same wp.serverSideRender pattern as
// bhm/buy (0.4.9) and bhm/tip-jar (0.4.10). Zero attributes — always every
// configured tier, site-wide. Old shortcode untouched.

// 0.4.10 — second shortcode-to-block conversion, 'bhm/tip-jar', same
// wp.serverSideRender pattern 'bhm/buy' (0.4.9) proved out. Zero
// attributes/Inspector picker needed — the tip jar is always the one
// site-wide Tip product. Old [bhm_tip_jar] shortcode untouched.

// 0.4.9 — first shortcode-to-block conversion using wp.serverSideRender —
// a real live preview in the editor canvas, calling the same render_callback
// a real page load runs. New 'bhm/buy' block — an Inspector picker (backed
// by /bhm/v1/purchasable-objects) selects which track/release. The old
// [bhm_buy] shortcode is untouched and still registered.
// Bug fix found mid-implementation: BHM_Blocks::init() originally called
// add_action('init', [self::class, 'register_block']) from inside its own
// 'init' callback — a second, nested add_action('init', ...) registered
// from an already-executing 'init' callback never fires in that request, so
// the block silently never registered. Fixed by calling register_block()
// directly instead of wrapping it in a second 'init' hook. The same bug
// pattern was found in 8 more places across own-ur-shit/bh-monetization-woo/
// bh-courses — not fixed in this pass, flagged as its own follow-up.
//
// 0.4.9 addendum, same pass: BHM_Storefront::init() had the identical
// nested-'init' bug for its taxonomy and rewrite-rule registration. Fixed by
// calling BHM_Storefront::init() directly from this file's plugins_loaded
// callback instead of deferring through another 'init' hook layer.
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
foreach (['activator', 'tiers', 'gate', 'wallet', 'fraud', 'admin', 'products', 'gifts', 'downloads', 'frontend', 'style-surface', 'debug', 'crm-integration', 'portal-panel', 'recommendations', 'storefront', 'test-suite', 'blocks'] as $f) {
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
    add_action('init',          ['BHM_Gifts', 'init']);
    add_action('init',          ['BHM_Downloads', 'init']);
    add_action('init',          ['BHM_Frontend', 'init']);
    add_action('init',          ['BHM_Blocks', 'init']);
    add_action('init',          ['BHM_StyleSurface', 'init']);
    add_action('init',          ['BHM_Debug', 'init']);
    add_action('init',          ['BHM_CRMIntegration', 'init']);
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
