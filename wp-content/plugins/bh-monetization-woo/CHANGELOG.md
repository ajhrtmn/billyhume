# Changelog — BH Monetization (WooCommerce)

Newest first. Recent releases have their own line; older ones are summarized. The full detail of each change is in git.

0.7.3 - The tiers page link falls back to the home page unless the page is published (drafts and private pages were linked). The monthly equivalent of an annual price is rounded, not truncated.

0.7.2 - Wording is now a BH_Props provider: changes made with `wp bh set` (or the future admin screen) apply on top of words.php.

0.7.1 - Admin-screen wording (settings, tiers, gift access, storefront) moved into `words.php`; output unchanged. PHPStan fixes, shorter comments and a condensed changelog.

0.7.0 - Rescoped to selling courses and supporter tiers: removed streaming-only track pricing, pay-per-play gating, auctions, the anchored purchase ledger and track downloads (course video downloads stay). The buy-button block lists courses. Customer-facing wording moved into `words.php` (`BHM_Words`). Wallet, referrals and storefront remain but are unused.

0.6.13 - Members who log in, or land on WooCommerce's My account page, go to the site's account page instead.
0.6.12 - Fix: a guest could pay and get nothing. Checkout now requires an account for anything that grants access, and a guest purchase is attached to (or creates) an account.
0.6.11 - "Gift access" box on anything sold once: a real $0 completed order, so access works exactly like a sale.
0.6.10 - Order-received page links a buyer straight to what they bought.
0.6.8 - Security: two simultaneous gift redemptions could both succeed; now a single conditional update.
0.6.6–0.6.7 - Supporter tier card and button styles are editable in the Design Suite.
0.6.3–0.6.5 - The supporter tiers page creates itself and explains itself when no tiers exist yet.
0.6.1 - Money paths keep working when one order-completion step fails.

## Earlier history, in brief

- 0.5.x: one-time course purchase support; downloadable lesson videos with a download limit; tier-gating for ordinary posts and pages; first real-database integration test; PHPStan level 6 clean with real WooCommerce stubs.
- 0.4.x: annual pricing, free trials, per-tier benefit lists and cover images; gift memberships; pay-what-you-want; pause/resume subscription; blocks for the buy button, tip jar and tiers; fraud flags for refund abuse and top-up velocity; CRM and Metrics widgets.
- 0.1–0.3: supporter tiers on WooCommerce products, entitlements as the single source of access, the tip jar and wallet.
