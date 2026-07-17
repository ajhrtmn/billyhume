# BH Monetization (WooCommerce)

Supporter tiers, outright purchase, tips, and pay-per-play for
`bh-streaming` — all backed by WooCommerce, never a parallel payments
stack. Depends only on `own-ur-shit`. Never requires `bh-streaming` to
exist, and `bh-streaming` never requires this plugin — see "Independence"
below.

## The Patreon-lite foundation

Supporter tiers (`bhm_tier` posts) aren't just for bh-streaming. Any
post type from any plugin can paylock itself with one line —
`update_post_meta($id, '_bhm_required_tier', $tier_id)` — and check
access with `BHM_Gate::user_has_tier_access($user_id, $tier_id, $id)`.
`bhm_extra_entitlement_check` is the escape hatch for a future plugin's
own entitlement types (mirroring `bh-crm`'s own extension-point
pattern). This is explicitly anticipated groundwork for the future
learning-management/courses layer (VISION.md's "artist platform"
layer) — gating a paid lesson works identically, with zero changes
needed here.

## Independence

- `bh-streaming` exposes `bhs_track_monetization_ui`/`_save` (actions),
  `bhs_track_access_allowed`/`bhs_track_play_allowed` (filters), and
  `bhs_track_lock_notice`/`bhs_track_play_denied_message` (filters).
  Every one of them is a no-op with a safe default if this plugin isn't
  active — bh-streaming never mentions WooCommerce, prices, or tiers.
- This plugin only hooks into those bh-streaming extension points
  `if (class_exists('BHS_Admin'))`, checked safely inside `init()` (see
  every other plugin in this ecosystem for why never at file-parse
  time). Delete bh-streaming, and this plugin's own tiers/purchases/
  wallet keep working — there's just nothing to gate.

## WooCommerce Subscriptions (a real, honest caveat)

WooCommerce core has **no recurring billing of its own** — that's
WooCommerce Subscriptions, a separate, official, paid extension.
Treated as a further *optional* dependency: detected via
`class_exists('WC_Subscriptions')`, never required. Without it, a
supporter tier sells as a **one-time, 30-day** grant instead of true
recurring billing — the admin UI says this plainly rather than
pretending to offer automatic renewal it can't enforce.

## Externally-aggregated tracks are never monetizable

A track bh-streaming pulled in from another artist's own feed
(`_bhs_source = 'external'`) is not this site's content to sell, gate,
or charge per-play — that would misdirect a fan's money away from
whoever actually made it. `BHM_Products::is_external_track()` is
checked in the UI (fields don't render), the save handler (a crafted
POST can't set monetization meta on one either), and both gating hooks
(`track_access_allowed`/`track_play_allowed` always pass these through).

## Pay-per-play: an actual server-authoritative ledger

`_bhs_play_count` on bh-streaming is a cheap, unauthenticated vanity
counter — never something a payout should be computed from. This
plugin's own `bhm_play_log` table is the real record: every allowed
play (paid or not) gets a row, written server-side at the moment
bh-streaming's `/tracks/{id}/play` endpoint actually fires — not at
catalog-listing time, which would charge a listener just for loading
the page. Debits happen atomically against `bhm_wallet` (an
`INSERT ... ON DUPLICATE KEY UPDATE`, not a read-then-write, so
concurrent plays can't race into an incorrect balance).

**Not built (flagged explicitly, not silently skipped):** a full
royalty-split payout engine (e.g. dividing a subscription pool by
relative plays across an artist's whole catalog) is real, useful, and
a natural next step on top of `bhm_play_log` — but it's genuinely
separate scope from this plugin's job of gating and charging correctly.

## Fraud/abuse: what this plugin does and doesn't do

Real AML/KYC/fraud detection is the payment gateway's job — Stripe,
PayPal, etc. are the regulated money-services businesses here, with
their own compliance and fraud-detection systems. This plugin doesn't
pretend to replace that. What it *does* take responsibility for:

- The tip jar's amount is clamped server-side (`TIP_MIN_CENTS`/
  `TIP_MAX_CENTS` in `class-frontend.php`) and the price is actually
  applied via `woocommerce_before_calculate_totals` — not just cosmetic.
- A refunded or cancelled WooCommerce order actually **revokes**
  whatever it granted (`BHM_Products::on_order_reversed()`) — a
  chargeback can't leave a fraudster with permanent access or wallet
  credit while the artist eats the loss.
- Every grant and every wallet delta is a real, timestamped, server-
  written row (`bhm_entitlements`, `bhm_wallet_ledger`) — a genuine
  audit trail if something needs investigating after the fact.

## Testing without real money

**Own Ur Shit → Debug Tools → BH Monetization** (locked outside
`wp_get_environment_type() !== 'production'`, same as every other
plugin's debug section) mints test tiers, entitlements, and wallet
credit directly, plus a "simulate a refund" button that exercises the
exact same revocation code path a real chargeback would, and a
"simulate tier order" button that drives a REAL WooCommerce order
through `update_status('completed')` — the actual production code
path, not a shortcut around it — for testing the true end-to-end flow
without a real payment gateway.
