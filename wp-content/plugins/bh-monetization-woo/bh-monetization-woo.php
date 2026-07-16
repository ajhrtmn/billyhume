<?php
/**
 * Plugin Name: BH Monetization (WooCommerce)
 * Description: Artist monetization for bh-streaming — subscriptions, tips, pay-per-play, track/album purchase with lossless+compressed delivery, streaming-tier access, and refund/velocity fraud-pattern flagging — all backed by WooCommerce, never a parallel payments stack.
 * Version:     0.4.13
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 * Ecosystem: Own Ur Shit
 */
if (!defined('ABSPATH')) exit;

// 0.4.13 — portal styling QA pass (own-ur-shit's ROADMAP-ux-polish-and-
// feature-parity-2026-07.md, styling half). BHM_PortalPanel::render()
// was echoing "Active tiers" and "Wallet" as bare, unwrapped h2/p/ul/
// table content with zero separating divs — confirmed live on
// /account/membership/, the two sections visually blended into one
// continuous list. Wrapped each in the portal's new shared
// .bhi-portal-section card class (own-ur-shit's class-portal.php).
// Verified live: two clearly separated cards with proper padding on
// both desktop and mobile (375px) viewports.

// 0.4.3 — BHM_TestSuite gained real DB-backed coverage for
// BHM_Wallet::debit()/apply_ledger_delta() (balance/ledger consistency,
// the atomic-UPDATE insufficient-balance decline) — previously untested
// despite this session's logging pass finding real error-handling gaps
// there. Standing caveat: written and brace-balance-checked, not yet
// executed against the live install.

// 0.4.2 — error-handling/logging depth pass, from a broader ecosystem-
// wide audit: (1) BHM_Storefront::add_rewrite() upgraded from a
// version-gated "flush once, mark done, never re-verify" pattern to
// BHI_Portal's self-verifying shape (class-portal.php in own-ur-shit) —
// the identical bug class already confirmed broken on a live install
// for Portal's own rewrite rule, applied here preventatively since the
// two classes shared the exact same fragile pattern. (2) BHM_Wallet::
// debit()/apply_delta() previously failed completely silently on both
// a declined debit and a balance/ledger desync (balance write succeeds,
// ledger insert fails) — now logged via OUS_DebugLog at 'info'/'error'
// respectively, since a wallet balance disagreeing with its own ledger
// is a real money-handling integrity risk that had zero visibility
// before. Standing caveat: reasoning/brace-balance-checked only, not
// run against a real WordPress+MySQL install.

// 0.4.0 — ROADMAP-platform-evolution.md Section 4 (monetization tier
// depth): structured per-tier benefit lists, tier cover images, annual
// pricing alongside monthly, and the bhm_entitlement_granted/
// bhm_entitlement_revoked action pair. See class-tiers.php and
// class-products.php for the specifics.
//
// 0.4.1 — finished the BH_Commerce migration (Section 5's own stated
// prerequisite): wallet top-up/tip-jar product sync (class-frontend.php)
// and the order/subscription-lifecycle handlers in class-products.php
// (on_order_completed, on_order_reversed, on_subscription_active,
// on_subscription_ended) now go through BH_Commerce instead of touching
// WC_Order/WC_Subscription/WC_Product objects directly. See
// own-ur-shit/includes/class-commerce.php's docblock for the full
// migration history. NOT tested against a real WordPress+MySQL install
// yet — reasoning-only, same caveat as every other pass this session.
// 0.4.4 — bundled zip regenerated to match installed version, no code change
// 0.4.5 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET on this plugin's Debug Tools section, part
// of own-ur-shit's Debug Tools reorganization pass. No functional change
// to this plugin itself. Standing caveat: reasoning/brace-balance-
// checked only, not run against a real WordPress+MySQL install.
//
// 0.4.6 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: real,
// pre-existing bug, not just a missing feature. class-products.php's
// init() only ever listened for woocommerce_subscription_status_active/
// cancelled/expired — WooCommerce Subscriptions' native on-hold/pause
// status fired this same event bus all along, but nothing here ever
// listened for it, meaning a fan who paused billing (rather than
// cancelling outright) kept their tier-gated entitlement forever,
// unrevoked, for as long as they stayed paused. Fixed by adding a
// woocommerce_subscription_status_on-hold listener
// (on_subscription_paused()), which revokes through the exact same
// path on_subscription_ended() already used (both now call a shared
// revoke_subscription_entitlements($subscription, $reason) — extracted
// verbatim from on_subscription_ended()'s prior body, no logic changed,
// just given a second caller with its own reason string so
// bhm_entitlement_revoked listeners can tell "paused, might come back"
// apart from "actually over"). on_subscription_active() already
// re-grants automatically on the way back out of on-hold (WooCommerce
// Subscriptions fires that same event again on resume) — confirmed by
// reading that method, no change needed there.
// Honest caveat: WooCommerce Subscriptions (a paid extension) is not
// installed on this dev install, so the real hook firing end-to-end
// couldn't be clicked through in a live browser this pass. What WAS
// verified: PHP/DB syntax, and that revoke_subscription_entitlements()
// is a verbatim extraction of on_subscription_ended()'s prior
// (unchanged, presumably-correct) body — this is a mechanical
// copy/rename onto a second trigger, not new delete/query logic.
//
// 0.4.7 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5c: pay-
// what-you-want purchases, reusing the tip jar's own proven cart-item-
// price-override pattern (class-frontend.php's apply_tip_price()/
// apply_tip_amount()) rather than building new variable-price plumbing
// — apply_purchase_price()/apply_purchase_amount() are the same shape,
// just keyed to a specific purchase product's own
// _bhm_purchase_pwyw/_bhm_purchase_price_cents meta (the SAME price
// field a fixed-price purchase already used — when PWYW is on it's
// reinterpreted as a floor, not a new field). New admin checkbox
// ("Let fans pay what they want") next to the existing "Outright
// purchase price" field (class-products.php's render_object_ui()/
// save_object()). New [bhm_buy id="<track-or-release-id>"] shortcode
// (render_purchase_button()) — there was no existing front-end
// "buy this outright" entry point anywhere in the ecosystem before
// this (purchase products existed server-side, hidden from the
// catalog, reachable only via a direct add-to-cart URL nothing
// linked to) — same "drop it wherever" shortcode posture as
// [bhm_tip_jar]/[bhm_tiers], not wired to one specific template.
// RUNTIME-VERIFIED end to end on this actual install: created a real
// track with a $5 PWYW purchase price, confirmed the shortcode renders
// a "Name your price (min $5.00)" form, submitted $12 and confirmed
// the cart shows exactly $12.00 (not the $5 catalog price), then
// confirmed server-side floor enforcement by crafting a direct
// add-to-cart URL with bhm_purchase_amount=0.01 and confirming the
// cart clamped it to $5.00 — the <input> min attribute is a UX hint
// only, this is what actually enforces the floor.
//
// 0.4.8 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a, the
// feature half (0.4.6 already fixed the entitlement-revocation bug
// underneath this). A branded "Pause subscription"/"Resume
// subscription" control on a fan's active tier card
// (render_subscription_controls(), class-frontend.php) — same "thin
// wrapper, never send the fan to WooCommerce's own screens" posture
// this class already takes for checkout/the tip jar, not a new
// principle. Only renders for a REAL recurring subscription (a
// bhm_entitlements row with a real wc_subscription_id) — the one-time-
// purchase-as-access fallback model has nothing to pause. New
// admin-post handler (handle_manage_subscription()) verifies both the
// nonce AND that the subscription's own get_user_id() actually matches
// the requesting user before calling WC_Subscription::update_status()
// — a crafted subscription_id from a different account is never
// actionable.
// Honest caveat, same as 0.4.6: WooCommerce Subscriptions (a paid
// extension) is not installed on this dev install, so the real
// pause/resume status transition couldn't be clicked through end to
// end. What WAS verified live: WP_DEBUG_LOG confirmed zero PHP
// errors/warnings across the full code path this pass touches
// (bootstrap load, the tier grid's active-tier branch actually calling
// render_subscription_controls(), the class_exists('WC_Subscriptions')
// guard correctly short-circuiting to an empty, harmless string) —
// created a real tier post and a real bhm_entitlements row directly in
// the database and loaded the real [bhm_tiers] page to exercise this,
// not just a syntax check.
define('BHM_VER',  '0.4.13');

// 0.4.12 — QA fix, part of the same ecosystem-wide ordering-tiebreaker
// sweep as bh-crm 1.4.0/own-ur-shit 3.4.86. class-crm-integration.php's
// activity_summary(): the entitlements query (ORDER BY created_at DESC)
// had no id tiebreaker — a real correctness gap here, not just a
// display-order nit, since the loop right below it depends on that
// order to pick which entitlement counts as the fan's "active tier"
// (the most recently granted non-expired one). A bulk migration or
// promo grant landing two entitlements in the same second could have
// silently picked the wrong tier. Fixed with `, id DESC`.

// 0.4.11 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: third
// shortcode-to-block conversion, 'bhm/tiers' (class-blocks.php,
// assets/js/bhm-blocks.js), same wp.serverSideRender pattern as
// bhm/buy (0.4.9) and bhm/tip-jar (0.4.10). Zero attributes — always
// every configured tier, site-wide, same as [bhm_tiers] itself takes
// no atts. Old shortcode untouched. RUNTIME-VERIFIED end to end: REST
// block-renderer endpoint confirmed both the empty-state message
// ("No supporter tiers are set up yet.") and a real tier grid render
// correctly, then confirmed the empty state live in an actual page
// editor with zero console errors.

// 0.4.10 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: second
// shortcode-to-block conversion, 'bhm/tip-jar' (class-blocks.php,
// assets/js/bhm-blocks.js), same wp.serverSideRender pattern 'bhm/buy'
// (0.4.9) proved out. Zero attributes/Inspector picker needed — the tip
// jar is always the one site-wide Tip product, so the block's edit()
// renders the live preview unconditionally, no configuration step.
// The old [bhm_tip_jar] shortcode is untouched and still registered.
// RUNTIME-VERIFIED end to end: confirmed via the real REST block-
// renderer endpoint AND live in an actual page editor — inserted the
// block, the real "Send a tip: $5 [Send Tip]" form rendered
// immediately with zero console errors, no picker/configuration
// needed.

// 0.4.9 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a (the
// WYSIWYG follow-up): the FIRST shortcode-to-block conversion in this
// ecosystem using WordPress core's own wp.serverSideRender — a real
// live preview in the editor canvas, not a mimic (it calls the exact
// same render_callback a real visitor's page load runs). New 'bhm/buy'
// block (class-blocks.php, assets/js/bhm-blocks.js) — an Inspector
// picker (backed by a new /bhm/v1/purchasable-objects REST endpoint,
// listing every bhs_track/bhs_release with a real purchase price
// configured) selects which track/release, and the block canvas shows
// the actual rendered buy button/PWYW form live. The old [bhm_buy]
// SHORTCODE is untouched and still registered — this is a new,
// additive authoring path, not a breaking replacement; existing content
// using the shortcode keeps working exactly as before.
// Real bug caught and fixed mid-implementation, not just reasoned
// through: BHM_Blocks::init() originally called
// add_action('init', [self::class, 'register_block']) — but
// BHM_Blocks::init() is ITSELF only ever invoked as an 'init' callback
// (this file's own plugins_loaded handler does
// add_action('init', ['BHM_Blocks', 'init'])). A second, nested
// add_action('init', ...) registered from inside an already-executing
// 'init' callback never fires in that same request — confirmed
// directly against a minimal WP_Hook reproduction, not assumed — so the
// block silently never registered, request after request, with zero
// error anywhere. Fixed by calling register_block() directly instead
// of wrapping it in a second 'init' hook (nothing register_block_type()
// needs depends on a later point in 'init' than where this already
// runs). A background audit (spawned this same pass) found this exact
// bug pattern in 8 MORE places across own-ur-shit/bh-monetization-woo/
// bh-courses — real, currently-silent breakage (guest cookies never
// set, identity cookies never issued, cron/Action Scheduler bootstrap
// skipped, rewrite rules and a taxonomy never registered, a shortcode
// never registered, comment support never added to a post type) — NOT
// fixed in this pass (out of scope for the WYSIWYG work), flagged to
// AJ directly and as its own follow-up task.
// RUNTIME-VERIFIED end to end on this actual install, including the
// registration-bug fix itself (confirmed the block was NOT in
// WP_Block_Type_Registry before the fix, WAS after): created a real
// purchasable track with a real synced WooCommerce product, called the
// exact REST endpoint (/wp/v2/block-renderer/bhm/buy) wp.
// serverSideRender itself calls and confirmed it returns the real
// "Buy for $5.00" button HTML, then confirmed the same live in an
// actual page editor in the browser — inserted the block, picked the
// track from the Inspector dropdown, and watched the canvas render the
// real button with zero console errors. Test track/product/page
// cleaned up afterward.
//
// 0.4.9 addendum, same pass: a background audit spawned to check for
// more instances of the exact nested-'init' bug above found one more
// in THIS plugin — BHM_Storefront::init() (class-storefront.php)
// internally registered its own taxonomy AND rewrite-rule registration
// via a second add_action('init', ...), both silently dead for the
// same reason. Fixed by calling BHM_Storefront::init() directly from
// this file's own plugins_loaded callback instead of deferring through
// another 'init' hook layer. RUNTIME-VERIFIED: booted WordPress with
// WP_DEBUG_LOG on, confirmed taxonomy_exists('bhm_collection') is now
// true and the shop-collection rewrite rule is present in the real
// stored rewrite_rules option — both were false/absent before this fix.
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
foreach (['activator', 'tiers', 'gate', 'wallet', 'fraud', 'admin', 'products', 'downloads', 'frontend', 'style-surface', 'debug', 'crm-integration', 'portal-panel', 'storefront', 'test-suite', 'blocks'] as $f) {
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
    add_action('init',          ['BHM_Blocks', 'init']);
    add_action('init',          ['BHM_StyleSurface', 'init']);
    add_action('init',          ['BHM_Debug', 'init']);
    add_action('init',          ['BHM_CRMIntegration', 'init']);
    // Portal panel is a class_exists()-guarded consumer of BHI_Portal's
    // filter, not a hard dependency — add_filter() on a filter nobody
    // applies (core not present/too old to have BHI_Portal) is harmless,
    // same convention as every other cross-plugin registration here.
    add_action('init',          ['BHM_PortalPanel', 'init']);
    // QA fix, 0.4.9: BHM_Storefront::init() internally registers TWO
    // more 'init' callbacks of its own (register_taxonomy(), add_rewrite())
    // at default priority 10 — but since init() itself was only ever
    // invoked AS an 'init' hook callback, both nested registrations
    // happened WHILE 'init' was already executing its priority-10
    // bucket, and WordPress's WP_Hook never revisits a bucket it has
    // already passed in the same request (confirmed directly against a
    // minimal WP_Hook reproduction this session, not assumed). Result:
    // the bhm_collection taxonomy and its rewrite rule silently never
    // registered, on every real request, with zero error anywhere.
    // Fixed by calling ::init() directly here (this plugins_loaded
    // callback finishes well before 'init' fires) instead of deferring
    // through a second 'init' hook layer — see class-blocks.php's
    // BHM_Blocks::init() for the identical bug/fix found earlier this
    // same pass in this same plugin.
    BHM_Storefront::init();
    add_action('init',          ['BHM_TestSuite', 'init']);
    add_action('rest_api_init', ['BHM_Frontend', 'register_routes']);
});
