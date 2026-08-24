# Changelog — BH Monetization (WooCommerce)

Moved out of `bh-monetization-woo.php` on 2026-08-23. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---


0.5.20 — class-downloads.php now bundles bh-streaming's new
BHS_Booklet ("CD jacket" bonus content — liner notes/lyrics sheet/
artwork/credits) as an additional real WooCommerce downloadable file
alongside a track or album's own audio quality encodes, class_exists()
guarded like every other cross-plugin touch. A separate per-order
metadata watermarking pass (buyer name/email embedded in the
delivered files) was built and live-tested this same session, then
explicitly killed by AJ before landing — this version carries no
watermarking code anywhere, plain files only, same as before.

0.5.19 — Downloadable lesson video, direct request: "Can we make
video downloadable as well?" BHM_Downloads::gather_files() gets a
new bh_course branch (checked first, independent of the existing
BHS_API guard, which is specific to bh-streaming and has nothing to
do with whether a course's videos can be gathered): walks the
course's real lesson order (BHC_PostTypes::lesson_order()) and each
lesson's real steps (BHC_Steps::get()), collecting only
source==='upload' video steps' real attachment URLs. Cloudflare
Stream and YouTube/Vimeo embed steps are silently skipped — never a
file to download in the first place, and this ecosystem's own
standing "don't claim a capability that isn't actually there"
convention rules out a fake affordance for them. In practice there
was never a per-step "Download" button to gate anyway — delivery is
entirely through WooCommerce's own native "My Downloads" account
page, which structurally only ever lists what actually got attached.

Real abuse protection, scoped to course video specifically (track/
release purchases deliberately left at WC's unlimited default,
unchanged — pre-existing behavior with no reason to alter here):
WooCommerce's own native download_limit/download_expiry product
props, set on the course-purchase product the moment its files are
attached. 8 downloads / 180 days by default, both filterable
(bhm_course_download_limit, bhm_course_download_expiry_days) rather
than hardcoded. Chosen over a hand-rolled parallel rate-limiter:
video files are typically far larger than an audio track, making
this the real place bandwidth cost matters, and WC's own mechanism
is already battle-tested rather than new, payment-adjacent code to
get right under time pressure.

NOT runtime-verified through an actual completed WooCommerce order
with a real uploaded lesson video — code-reviewed against
attach_for_item()'s existing, already-proven track/release path
(same function, same WC_Product_Download API, confirmed
set_download_limit()/set_download_expiry() are real methods on this
install's actual WooCommerce version before using them) but not
exercised end-to-end this session.

0.5.18 — Backend support for bh-courses' new per-course one-time
purchase (bh-courses 0.4.83). Three real, targeted fixes made while
wiring a third caller into existing infrastructure, not new
mechanisms:
  - BHM_Gate::user_owns_object(int, int): bool — extracted from
    duplicated inline SQL that used to live separately inside both
    user_has_tier_access() and user_has_benefit(). The actual reason
    this needed extracting, not just DRY for its own sake:
    user_has_tier_access() short-circuits to true when no tier is
    required, meaning its own inline purchase-check was NEVER
    reachable for an object sold purely as a one-time purchase with
    no tier at all — exactly bh-courses' main use case. A real,
    independently-callable helper is what makes that configuration
    work.
  - BHM_ProductSync::sync_object_purchase_product()'s product-naming
    ternary extended from binary (bhs_release vs. everything-else-
    is-Track) to a real label map including bh_course — the old
    ternary would have named every course product "... (Track
    Purchase)".
  - BHM_Entitlements::on_order_completed()'s object_type -> scope
    ternary similarly extended to map bh_course -> 'course' — a real
    data-quality fix (the actual unlock check never filters on
    scope, only object_id + type='purchase', so this wasn't a live
    access bug, but a granted entitlement row would otherwise have
    been mislabeled scope='track' for a course purchase).
NOT runtime-verified: on_order_completed() itself, through a real
completed WooCommerce order — see bh-courses 0.4.83's own changelog
for the full verification/non-verification breakdown.

0.5.17 — Task #8 (in-context tooltips), judicious pass: added a real
BHY_UI::tip() to the tier-edit screen's Monthly price field. The
price is more load-bearing than it looks — it's the ONLY signal that
ranks tiers against each other for gating (bh-courses' lesson gate
and this plugin's own post-gate both say "requires this tier OR any
higher-priced one," already tooltipped on THOSE consuming screens),
but nothing on the tier's own editing screen previously said so. An
artist reordering tiers by price had no in-context way to learn that
changing a price can silently change who already qualifies for a
gated lesson/post elsewhere.

0.5.16 — Tier-gating for ordinary Posts and Pages (new class-post-
gate.php, BHM_PostGate). BHM_Gate::get_required_tier()/
user_has_tier_access()/render_paywall_notice() were already generic
(keyed off any post_id), but until now only bh-courses (its own CPT)
and bh-streaming tracks/releases (BHM_MonetizationUI, via bh-streaming's
own action hooks) actually rendered a tier-select metabox and enforced
it — a plain blog post or page couldn't be gated at all despite the
underlying machinery already supporting it. Adds: a "Supporter access"
metabox (same `_bhm_required_tier` meta key every other gated object
type already uses, with a help tooltip on the price-rank rule — see
the-self-hosted-self 3.10.15's tip component), and the actual enforcement — a
the_content filter (priority 20, after wpautop/block/shortcode
rendering) that swaps in BHM_Gate::render_paywall_notice() for an
ungated visitor. Scoped to is_singular(['post','page']) +
in_the_loop() + is_main_query() specifically so it can never leak into
an excerpt, widget, REST response, or admin preview context that
wasn't meant to enforce gating. class_exists('BHM_Tiers')/
BH_Commerce::available()-guarded throughout, same posture as every
other monetization touchpoint in this plugin — a site with no
WooCommerce or no tiers created sees no metabox and no gating at all.
NOT runtime-verified against a live install by this commit alone —
verify by gating a real page behind a tier, viewing it logged out
(or as a non-entitled user), and confirming the paywall notice shows
instead of the real content.

0.5.15 — Real bug fix surfaced by the-self-hosted-self's own final PHPStan
level 6 brick (typing OUS_Debug::button() with a real `: void`
return): class-debug.php here was calling it as `echo
OUS_Debug::button(...)` at 16 call sites — the most of any plugin in
the ecosystem — double-printing every debug-tools button on this
plugin's own Debug Tools section. button() already echoes its own
markup internally, the wrapping `echo` was pure extraneous output.
Fixed by dropping the `echo` at every site. NOT runtime-verified
against a live install; smoke-test the Debug Tools page to confirm
buttons render once, not twice.

0.5.14 — Ecosystem quality Phase 2, brick 11/13: bh-monetization-woo is
now clean at PHPStan level 6 (native return/parameter types + precise
array-shape PHPDoc throughout every file in includes/, no shortcuts).
The largest brick in this phase so far — 30 files, ~532 findings.
Covers class-wallet.php (hold/release/capture-hold prepaid credit
ledger), class-entitlements.php (the order/subscription -> entitlement
bridge), class-storefront.php, class-frontend.php, class-mock-
commerce.php, class-products.php, class-purchase-ledger.php, class-
gate.php, class-tiers.php, class-play-gating.php, class-gifts.php,
class-auctions.php, class-monetization-ui.php, class-crm-
integration.php, class-referrals.php, class-anchoring.php, class-
fraud.php, class-downloads.php, class-blocks.php, class-test-suite.php,
class-product-sync.php, class-debug.php, class-recommendations.php,
class-admin.php, class-portal-panel.php, class-money.php, class-
ledger-crm-integration.php, class-style-surface.php, class-
activator.php. No behavior changes — a handful of get_posts()/
esc_html()/esc_attr() call sites needed an explicit (string)/(int)
cast at the call site once their surrounding parameter picked up a
native type. Scoped bh-monetization-woo PHPStan level 6 check and the
full 12-plugin level 5 ecosystem check both come back clean.
NOT runtime-verified against a live WordPress+MySQL+WooCommerce install.
0.5.13 — Real bugs surfaced by the repo-root PHPStan pass, now
actually running with a real php-stubs/woocommerce-stubs package
installed (the-self-hosted-self 3.10.10) instead of WC_* symbols just being
unresolved noise: 56 -> 28 errors from the stub alone, then 28 -> 0
(plus the two deliberately-unstubbed COOKIEPATH/COOKIE_DOMAIN
constants) from these fixes.
- class-downloads.php, class-frontend.php: foreach ($order->
  get_items() as $item) called $item->get_product_id(), which only
  exists on WC_Order_Item_Product, not the base WC_Order_Item type
  get_items() is typed to return. Harmless in practice today (no type
  filter passed = WC's own 'line_item' default = always Product in
  practice), but now guarded with a real instanceof check so a future
  type-filter change can't silently fatal on a shipping/fee/coupon
  line item.
- class-entitlements.php: removed legacy_get_order_array() —
  PHPStan-confirmed genuinely dead code, zero call sites anywhere in
  this plugin despite its own comment describing it as a fallback
  path; whatever was meant to call it never did.
- class-storefront.php: a stray, meaningless second argument on a
  render_product_grid_block() call (harmless at runtime — PHP
  silently ignores an extra arg — but not correct) removed.
class-product-sync.php's WC_Product_Subscription findings and
class-storefront.php's register_block_type() api_version int-vs-
string findings are confirmed NOT bugs — see the-self-hosted-self 3.10.10's
own changelog and phpstan.neon's inline comments for the reasoning
(a real, separate paid WooCommerce extension not covered by the core
stub package; a known inaccuracy in WordPress core's own docblock,
respectively) — scoped-ignored, not fixed, since the code itself is
already correct.
NOT runtime-verified against a live WordPress+MySQL install this
session — every fix here was confirmed via a real `vendor/bin/phpstan
analyse` run (this session has working composer/PHPStan) and by
reading WooCommerce's real stub definitions directly, a meaningfully
stronger bar than most of this session's other work, but still not
the same as exercising a real checkout/download flow in a browser.

0.5.12 — Real bugs found by a proper `composer install && vendor/bin/
phpstan analyse` run (repo-root phpstan.neon, level 5; the pilot's
original sandbox had no GitHub access to actually run this). (1)
class-debug.php: two Debug Tools seed helpers (tier seeding, storefront
test-product seeding) checked `is_wp_error($id)`/`is_wp_error(
$product_id)` on wp_insert_post()'s return — that function only
returns WP_Error when called with $wp_error=true (4th arg, not passed
here); it returns 0 on failure, so the error branch could never fire.
Changed both to a falsy check. (2) class-storefront.php's
maybe_render_collection() checked `is_wp_error($term)` after
get_term_by(), which only ever returns WP_Term|false, never WP_Error —
dead code, removed. (3) esc_attr()/esc_html() type-safety: several
call sites in class-debug.php/class-frontend.php/class-tiers.php
passed ints/floats directly where both functions expect a string
(PHP 8.1+ deprecation, not yet a hard error) — added explicit (string)
casts. `php -l` clean on all four files. Runtime-verified live against
localhost:10008: Debug Tools' "Create 1 test collection + 1 test
product" storefront-seeding action created a real product/collection
and the resulting /shop-collection/.../ landing page rendered cleanly.

0.4.17 — BHM_Tiers::save() now logs a before/after diff of price_cents/
annual_price_cents on every tier save, and tier deletion logs the tier's
name and price before it's gone.

0.4.16 — wallet top-up fraud/abuse velocity cap: BHM_Fraud::
track_topup_velocity() flags an account (surface for a human, never
auto-block) when purchased top-ups exceed $500 in a rolling 24h window
(filterable via 'bhm_topup_velocity_cap_cents'). Only fires for the
'topup' reason — admin grants and refund-reversal adjustments don't count.

0.4.15 — BHM_CRMIntegration::activity_summary() (wallet balance, active
tier, purchase history, refund-fraud flags on the CRM person page) now
requires the admin-only bhcore_view_crm_sensitive capability instead of
bhcore_manage_crm; a non-admin manager sees nothing from this integration
rather than a redacted version.

0.4.14 — BHM_Wallet::debit()/apply_delta() now emit BH_Event
'bhm/wallet_debit'/'wallet_credit' after each ledger write, feeding the
CRM's unified per-person activity timeline (bh-crm 1.9.0). Additive only.

0.4.13 — wrapped BHM_PortalPanel::render()'s "Active tiers"/"Wallet"
sections in the portal's shared .bhi-portal-section card class —
previously bare h2/p/ul/table content with no separating divs.

0.4.3 — BHM_TestSuite gained DB-backed coverage for BHM_Wallet::debit()/
apply_ledger_delta() (balance/ledger consistency, the atomic-UPDATE
insufficient-balance decline).

0.4.2 — (1) BHM_Storefront::add_rewrite() upgraded from a version-gated
"flush once, never re-verify" pattern to BHI_Portal's self-verifying
shape, since the two classes shared the same fragile pattern. (2)
BHM_Wallet::debit()/apply_delta() previously failed silently on both a
declined debit and a balance/ledger desync — now logged via OUS_DebugLog
at 'info'/'error' respectively, since a balance disagreeing with its own
ledger is a real money-handling integrity risk.

0.4.0 — structured per-tier benefit lists, tier cover images, annual
pricing alongside monthly, and the bhm_entitlement_granted/
bhm_entitlement_revoked action pair. See class-tiers.php/class-products.php.

0.4.1 — wallet top-up/tip-jar product sync and the order/subscription-
lifecycle handlers in class-products.php now go through BH_Commerce
instead of touching WC_Order/WC_Subscription/WC_Product directly.
0.4.4 — bundled zip regenerated to match installed version, no code change
0.4.5 — class-debug.php's register() now sets 'group' =>
OUS_Debug::GROUP_SEED_RESET on this plugin's Debug Tools section.

0.4.6 — WooCommerce Subscriptions' native on-hold/pause status fired the
same event bus as active/cancelled/expired, but nothing here listened for
it — a fan who paused billing kept their tier-gated entitlement forever.
Fixed by adding a woocommerce_subscription_status_on-hold listener
(on_subscription_paused()) that revokes through a shared
revoke_subscription_entitlements($subscription, $reason), extracted from
on_subscription_ended()'s prior body so both callers share one revoke path
with distinct reason strings. on_subscription_active() already re-grants
on resume, no change needed there. Not yet clicked through live end-to-end
since WooCommerce Subscriptions (a paid extension) isn't installed here.

0.4.7 — pay-what-you-want purchases, reusing the tip jar's cart-item-
price-override pattern (apply_tip_price()/apply_tip_amount()) rather than
building new variable-price plumbing: apply_purchase_price()/
apply_purchase_amount() key off the same _bhm_purchase_price_cents meta a
fixed-price purchase uses — when PWYW is on it's reinterpreted as a floor.
New [bhm_buy id="<track-or-release-id>"] shortcode (render_purchase_
button()) — previously no front-end "buy outright" entry point existed;
purchase products were server-side only, reachable via a direct
add-to-cart URL nothing linked to.

0.4.8 — a branded "Pause subscription"/"Resume subscription" control on a
fan's active tier card (render_subscription_controls()), matching this
class's existing thin-wrapper-around-WooCommerce posture. Only renders for
a real recurring subscription (a bhm_entitlements row with a real
wc_subscription_id). handle_manage_subscription() verifies both the nonce
and that the subscription's get_user_id() matches the requesting user
before calling WC_Subscription::update_status().
0.4.20 — free trials. A tier's edit screen (class-tiers.php) gets a "Free
trial (days)" field, synced to both the monthly and annual WC Subscription
product via BH_Commerce::upsert_product()'s trial_length/trial_period
args, and surfaced on the fan-facing tier picker as an "N-day free trial"
badge.
0.4.21 — bug fix: BHM_PortalPanel::active_entitlements() queried a
nonexistent `tier_id` column on `bhm_entitlements` (the real column is
`object_id`), so the portal's "Membership & Wallet" panel silently showed
"No active supporter tier" for every user regardless of real state. Fixed
the column name, and scoped the query to type IN
('subscription','streaming_tier') since this table also holds one-time
purchase entitlements.
0.4.22 — gift memberships. A "Gift this" form on the tier picker captures
a recipient email at add-to-cart time (BHM_Gifts::capture_gift_email());
on_order_completed() checks for it and, instead of granting the buyer an
entitlement, creates a redemption code (bhm_gift_redemptions) and emails
the recipient a claim link. [bhm_redeem_gift] renders the claim form;
claiming grants a real 30-day streaming_tier entitlement via
BHM_Products::grant_gift_entitlement(). A matching Debug Tools action
(simulate_gift_order) drives the same order-completion path as the
existing tier-order simulation, since wp_mail() isn't reliable on a bare
local install and redemption needs to be testable without real email.
0.4.23 — added save_post_page auto-detect for any page carrying
[bhm_redeem_gift] (BHM_Gifts::redeem_page_url()), matching the existing
tiers-page convention — previously fell back to the homepage until an
admin manually wired up an option with no settings UI.
0.5.0 — storefront/merchandising: individual product pages and
"customers also bought" relations.
  1. BHM_Recommendations (new) — content-based scoring reusing bh-
     streaming's BHS_Recommendations approach (shared bhm_collection/
     product_cat/product_tag terms, weighted 3/2/1). Every single-product
     page gets a "You may also like" section automatically
     (woocommerce_after_single_product_summary).
  2. Gutenberg registration for bhm/product-grid, bhm/product-filter, and
     new bhm/related-products (register_block_type() + render_callback,
     reusing the same PHP renderers BH_Content's registration calls).
  3. Bug fix: WooCommerce core hardcodes the block editor off for
     products (WC_Post_Types::gutenberg_can_edit_post_type() always
     returns false). Added a later-priority filter override.
  4. Two bug fixes found while polishing the single-product page:
     storefront.css referenced a never-defined --bhy-color-* token
     scheme (same class of bug already found in class-portal.php),
     rewritten to the real --bh-* tokens BHY_Style emits; and the
     price/button rules only matched the classic WooCommerce template's
     markup, not Woo Blocks' DOM shape — rescoped to selectors stable
     across both template modes.
0.5.1 — first consumer of the-self-hosted-self's OUS_Revisions shared service. A
tier's full field set is a clean fit (an overwrite-on-save single object,
unlike bh-crm's append-only notes). BHM_Tiers::save() now snapshots the
tier's complete state on every save; the tier edit screen gets a "Version
History" panel with Restore buttons that re-apply a prior version through
the same save path (including re-syncing the WooCommerce product).

0.4.19 — "Get Paid" card on the Monetization Settings screen
(BHM_Admin::render_get_paid_card()): checks WC_Payment_Gateways::
get_available_payment_gateways() for whether any gateway is enabled, plus
a link into WooCommerce core's own guided payments setup. This plugin has
no gateway config screen of its own — real credentials live in
WooCommerce core.

0.4.18 — first contributor to the-self-hosted-self's shared Metrics dashboard:
two widgets in class-crm-integration.php (Active supporters, New
entitlements). Reads bhm_entitlements directly rather than BH_Event,
since no purchase/entitlement event exists yet.

0.4.12 — class-crm-integration.php's activity_summary() entitlements
query (ORDER BY created_at DESC) had no id tiebreaker, which the loop
below depends on to pick the fan's "active tier" (most recently granted
non-expired one) — a bulk migration landing two entitlements in the same
second could silently pick the wrong tier. Fixed with `, id DESC`.

0.4.11 — third shortcode-to-block conversion, 'bhm/tiers' (class-
blocks.php, assets/js/bhm-blocks.js), same wp.serverSideRender pattern as
bhm/buy (0.4.9) and bhm/tip-jar (0.4.10). Zero attributes — always every
configured tier, site-wide. Old shortcode untouched.

0.4.10 — second shortcode-to-block conversion, 'bhm/tip-jar', same
wp.serverSideRender pattern 'bhm/buy' (0.4.9) proved out. Zero
attributes/Inspector picker needed — the tip jar is always the one
site-wide Tip product. Old [bhm_tip_jar] shortcode untouched.

0.4.9 — first shortcode-to-block conversion using wp.serverSideRender —
a real live preview in the editor canvas, calling the same render_callback
a real page load runs. New 'bhm/buy' block — an Inspector picker (backed
by /bhm/v1/purchasable-objects) selects which track/release. The old
[bhm_buy] shortcode is untouched and still registered.
Bug fix found mid-implementation: BHM_Blocks::init() originally called
add_action('init', [self::class, 'register_block']) from inside its own
'init' callback — a second, nested add_action('init', ...) registered
from an already-executing 'init' callback never fires in that request, so
the block silently never registered. Fixed by calling register_block()
directly instead of wrapping it in a second 'init' hook. The same bug
pattern was found in 8 more places across the-self-hosted-self/bh-monetization-woo/
bh-courses — not fixed in this pass, flagged as its own follow-up.

0.4.9 addendum, same pass: BHM_Storefront::init() had the identical
nested-'init' bug for its taxonomy and rewrite-rule registration. Fixed by
calling BHM_Storefront::init() directly from this file's plugins_loaded
callback instead of deferring through another 'init' hook layer.
