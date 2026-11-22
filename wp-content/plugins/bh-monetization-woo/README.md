# BH Monetization (WooCommerce)

Selling on billyhume.net, backed by WooCommerce (never a parallel payments system). WooCommerce owns the cart, checkout and payment; this plugin turns a completed order into **access** and keeps products in sync.

## What it does

- **Course products**: a course with a price gets a matching WooCommerce product (`BHM_ProductSync`). Paying for it grants access through an entitlement row; access is never re-derived from order status.
- **Supporter tiers** (`bhm_tier` posts, ranked by price): monthly/annual, with WooCommerce Subscriptions if installed, otherwise a one-time 30-day purchase. Any content can require a tier (`BHM_Gate`, `BHM_PostGate`).
- **Gift access**: an admin box on a course that grants it to someone as a real $0 order, so it uses the same path as a sale.
- **Gift memberships**: buy a tier for someone else's email; they claim it with a code.
- **Launch coupons**: WooCommerce coupons marked "apply automatically" (e.g. first 10 buyers get 30% off), shown as a launch price on sales pages.
- **Guest checkout**: a buyer without an account gets one at checkout, so the purchase always lands on an account they can log into.
- **Refund handling and fraud flags**: refunds and chargebacks revoke access; repeated buy-and-refund patterns are flagged for a human, never auto-blocked.
- **Tip jar and buy buttons** (blocks and shortcodes).

Wording people see lives in `words.php` in this folder.

## Leftovers

The **wallet** (prepaid credit), **referrals** and **storefront collections** were built for a streaming plugin that has since been removed. They still work but nothing on the site uses them; see `../OPEN.md`.

## Testing

Debug Tools → BH Monetization creates test tiers, entitlements and orders with no real payment. The Test Runner suite covers tier exclusivity, gift access, referrals and wallet holds against real, tagged, cleaned-up rows. `tests-integration/` holds a PHPUnit suite that runs against a real database in CI.
