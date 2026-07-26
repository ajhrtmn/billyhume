# Code-Quality Audit — bh-monetization-woo

- **Scope:** entire `bh-monetization-woo` plugin, v0.5.2 (28 PHP files, ~6.2K lines) — read in full, not sampled.
- **Date:** 2026-07-25
- **Model:** claude-opus-4-8 (Claude Code agent, task 4/16)
- **Type:** CODE-QUALITY only (DRY/SOLID/naming/comments/dead code/fragile patterns). UX is a separate task.
- **Caveats:** Static analysis only. **No live PHP/MySQL/WordPress/WooCommerce/WC-Subscriptions execution environment is available** — nothing here was runtime-verified. Every finding below was confirmed by reading the actual file:line (grep hits were treated only as leads until read). This plugin handles real money (wallets, tiers, gifting, subscriptions, referrals) so findings are stated conservatively.

**Headline:** This is a high-quality, unusually well-commented codebase. The "why not what" comment discipline, sibling-class cross-referencing, and single-responsibility splits (class-products.php → ProductSync/MonetizationUI/PlayGating/Entitlements) are genuinely at or above the established bar. `class-wallet.php` and `class-entitlements.php` are exemplary. There are **no correctness bugs found** and **no security regressions found** in the money paths. Findings are almost entirely DRY/consistency/maintainability, and the two known-deferred duplication items have both grown materially since 07-08.

---

## Known-deferred duplication — FRESH counts (2026-07-25)

### (a) Cents-formatting duplication — GROWN 21 → ~34. **Recommend: prioritize now.**
Fresh count of `number_format($x / 100, 2 …)`-style cents→dollars formatting: **~34 occurrences across 13 files** (was 21 across the plugin on 07-08).

Per-file: class-frontend.php ×10, class-tiers.php ×4, class-product-sync.php ×3, class-monetization-ui.php ×3, class-crm-integration.php ×3, class-portal-panel.php ×2, class-ledger-crm-integration.php ×2, class-debug.php ×2, class-referrals.php ×1, class-purchase-ledger.php ×1, class-play-gating.php ×1, class-blocks.php ×1, class-admin.php ×1.

Two *distinct* idioms are interleaved and easy to confuse on a money path:
- **Display:** `number_format($cents / 100, 2)` → `"1,234.56"` (thousands separator, for UI).
- **Form/price value:** `number_format($cents / 100, 2, '.', '')` → `"1234.56"` (no separator, fed to `set_regular_price()` / `<input value>`).

Recommendation: **fix now**, not defer a third time. Add a tiny `BHM_Money` helper (e.g. `BHM_Money::display($cents)` and `BHM_Money::price($cents)`) or reuse a core helper if one exists in `own-ur-shit`. Rationale: (1) it's money code where a wrong-separator value silently corrupts a `set_price()` call; (2) 34 hand-rolled sites is past the threshold where the two idioms will keep getting mixed up; (3) the reverse `(int) round(((float) $x) * 100)` cents-parse is *also* duplicated ~8× (tiers save, monetization-ui save, frontend tip/purchase, product-sync, admin) and belongs in the same helper.

### (b) WooCommerce-availability guard duplication — GROWN & now INCONSISTENT. **Recommend: prioritize now (consistency), low effort.**
Fresh count: **24 bare `class_exists('WooCommerce')`** calls across 10 files, PLUS **9 verbose ternaries** `class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce')` (class-tiers.php ×4, class-monetization-ui.php ×2, class-product-sync.php ×2, class-admin.php ×1).

This is now worse than the 07-08 "18 scattered guards" — it's not just duplication, it's **two competing idioms living side by side**, sometimes in the same file/method:
- The "migrated" files route availability through the `BH_Commerce` abstraction seam.
- The newer money-render paths still use the bare check: `class-frontend.php` (render_tiers L149, render_tip_jar L378, render_wallet L568, render_purchase_button L482), `class-storefront.php` (render_product_grid_block L347, rest_query_products L470), `class-referrals.php`, `class-downloads.php`.
- **Same-method inconsistency:** `BHM_Frontend::render_tiers()` gates on bare `class_exists('WooCommerce')` (L149) but two lines into the loop asks `BH_Commerce::has_subscriptions()` (L182) — mixing the raw dependency and its abstraction in one render.

Recommendation: **prioritize now** as a mechanical consistency pass — collapse all 33 to a single `BH_Commerce::available()` (with the existing fallback baked into that one method, not re-spelled 9×). Low risk, and it closes the seam the migration explicitly set out to close. This is more valuable than (a) per line touched but (a) is the higher money-risk one — do both.

### (c) Cross-plugin rewrite "self-heal" dup — CONFIRMED still present. **Defer to cross-cutting pass (as scoped).**
`BHM_Storefront::add_rewrite()` + `rewrite_rule_persisted()` + `not_recently_attempted()` + `force_flush_and_verify()` (class-storefront.php L203-256, ~90 lines incl. `REWRITE_VERSION`/`VERIFY_THROTTLE_SECONDS` consts) is still byte-for-byte the port of `BHI_Portal`'s algorithm, and its own comments (L184-202) still admit the manual port. Confirmed present in this plugin's half. Correct to leave the shared-helper extraction to a future cross-cutting pass — just re-verified.

---

## Findings by severity

### Medium

**M1 — The "30-day fallback period" business constant is a magic literal spread across 4 files, with a silent coupling to the proration divisor.**
`class-gate.php:175` `calculate_downgrade_credit_cents()` divides by a literal `30`. The grant length it's crediting against is written as `strtotime('+30 days')` literals in `class-entitlements.php:107` (one-time tier), `class-entitlements.php:319` (`grant_gift_entitlement`), and implied by `class-gifts.php` comments; `class-admin.php:75` and `class-frontend.php` surface "30-day access" copy; `class-debug.php:186` defaults 30.
Failure scenario: if the fallback access period is ever changed (e.g. to 31 or 60 days) in the grant sites, the downgrade-credit proration in `calculate_downgrade_credit_cents()` keeps dividing by 30 and silently mis-credits every non-WC-Subscriptions downgrade — real wallet money, wrong amount, no error. These two numbers *must* stay equal and nothing enforces it.
Fix: a single `const BHM_FALLBACK_ACCESS_DAYS = 30` (on `BHM_Gate` or `BHM_Tiers`), referenced by both the grant-expiry sites and the proration divisor. This is the one spread-literal worth treating as more than a nit precisely because it's money and the coupling is invisible.

**M2 — Pure-money-logic extraction: mostly good, one gap.**
The reference template (`BHM_Gate::calculate_downgrade_credit_cents()`, extracted to be unit-testable) is well-followed: `BHM_Referrals::calculate_commission_cents()` (class-referrals.php:77) is likewise pure and covered in the test suite. Good.
The one unextracted money calc: the **annual-savings math in `BHM_Frontend::render_tiers()`** (class-frontend.php:195-201) — `annual_monthly_equivalent`, `full_year_at_monthly_rate`, and the `savings_percent` rounding — is inline in a render loop and has zero test coverage, unlike its siblings. It's the kind of "1 − (annual / (monthly×12))" percentage that's easy to get an off-by-rounding wrong on and is shown to fans as a marketing claim.
Fix: extract to `BHM_Tiers::annual_savings_percent($monthly_cents, $annual_cents)` (pure) and add it to class-test-suite.php alongside the downgrade-credit and commission cases. Low priority but it's the one money number a fan reads that isn't independently tested.

### Low

**L3 — Duplicated block-schema definitions (BH_Content vs WP-core registration).**
`BHM_Storefront::register_core_blocks()` (L115-148, WP core) and `register_content_block_types()` (L314-336, BH_Content) declare the same three block types (`bhm/product-grid`, `bhm/product-filter`, `bhm/related-products`) with near-identical attribute maps that differ only in type-token spelling (`'integer'`/`'boolean'` vs `'int'`/`'bool'`). Two hand-maintained copies of the same schema drift the moment one attribute is added to only one. Fix: define the attribute set once (a private static array + a small token-translation) and feed both registrations from it.

**L4 — Dead code: `BHM_MockCommerce::delete()` has no callers.**
class-mock-commerce.php:81-85 — `delete($id)` is defined but never invoked anywhere in the plugin (mock subs are only created/status-changed, never deleted; cleanup is by option reset). Harmless (test-double file), but it's dead. Remove, or wire it into a mock-reset path.

**L5 — Two script handles load the same JS file for the same blocks.**
class-storefront.php registers `bhm-storefront-studio-blocks-core` (L159, on `enqueue_block_editor_assets`, every block-editor screen) and `bhm-storefront-studio-blocks` (L504, on `admin_enqueue_scripts` gated to bh-studio pages) — both pointing at `assets/js/storefront-studio-blocks.js` with different dependency arrays. On a screen that is both a block editor and a bh-studio surface, the same block-registration script could enqueue twice under two handles. Not confirmed to double-register at runtime (couldn't execute), but two handles for one file is a real maintenance smell. Fix: one handle, superset dependency array, gate the enqueue condition rather than the handle.

**L6 — `render_product_grid_block()` docblock vs code mismatch (minor).**
class-storefront.php:344-345 docblock states "$attrs here always has every schema key filled … so no isset() guarding is needed," yet L359 immediately guards `!empty($attrs['showFilters'])` and the method is also called directly from `maybe_render_collection()` (L307) and `render_related_products_block` with hand-built arrays that do *not* go through `BH_Content::validate()`. The comment's guarantee only holds for the block-render caller, not the two direct callers — the defensive `!empty()`/`max(1,min(...))` guards are actually load-bearing. Fix: soften the comment (it's currently self-contradicting and could tempt a future edit to remove a guard the direct callers rely on).

**L7 — Inconsistent subscription-availability check inside one render path (consistency, ties to (b)).**
`BHM_Frontend::render_tiers()` mixes bare `class_exists('WooCommerce')` (L149 gate) with `BH_Commerce::has_subscriptions()` (L182 badge) and `class_exists('BH_Commerce') && BH_Commerce::has_subscriptions()` (L207 trial badge). Same information, three phrasings, one method. Folds into the (b) consistency pass.

---

## Confirmed good (specifically verified)

- **`BHM_Wallet` (class-wallet.php):** debit is a single atomic `UPDATE … WHERE balance_cents >= %d` with success by `rows_affected` (no TOCTOU), credit/reversal via `INSERT … ON DUPLICATE KEY UPDATE`. Balance/ledger desync is logged at `error`. Exemplary, and correctly the internal reference bar.
- **`BHM_Entitlements` (class-entitlements.php):** per-item `try/catch` in `on_order_completed()` so one bad line item can't strand the rest; NULL-safe idempotency (separate order-keyed vs subscription-keyed dedup queries, explicitly avoiding `%d`-coercing NULL to 0); one-active-tier exclusivity via `replace_active_tier_entitlements()`; notification de-dup so a tier-switch doesn't also fire a generic "Access granted." Solid.
- **Subscription pause/resume integration (the flagged fragility point):** the WC-Subscriptions native `on-hold` status is wired to `on_subscription_paused()` → `revoke_subscription_entitlements($sub, 'subscription_paused')`, sharing one revoke path with `on_subscription_ended()` via distinct reason strings; resume re-grants through the same `on_subscription_active()` that a real activation fires. The reason-aware notification copy (paused-can-resume vs ended) is correct. `handle_manage_subscription()` (class-frontend.php:338) verifies **both** the nonce **and** `$subscription->get_user_id() === $user_id` before any status change — no IDOR. The one honest caveat is already documented in-code and in the changelog: **untestable end-to-end without the paid extension** — which is exactly why `BHM_MockCommerce` exists. No fragility found beyond that stated gap.
- **`BHM_Fraud` (class-fraud.php):** flags-for-human-review-never-auto-blocks posture is consistent; velocity cap only counts real `topup` reason; hashed (never raw-IP) fingerprint with the reverse-proxy limitation honestly documented in-code. No new review concerns.
- **`BHM_Gifts` (class-gifts.php):** `unique_key` on the cart item prevents WooCommerce merging two different-recipient gifts of the same tier into one line (real bug prevented); redeem is nonce+login gated and the `status = 'redeemed'` guard is the double-claim defense (correctly noted as the guard, not `grant_entitlement`'s idempotency).
- **Pay-what-you-want / tip jar:** server-side clamp is applied at **both** add-to-cart (`apply_*_amount`) **and** total-calculation (`apply_*_price`) time, with the HTML min/max explicitly called out as a UX hint only. Floor/ceiling re-checked against live meta at calc time. Correct.
- **`BHM_Referrals`:** INSERT-as-atomic-claim on a `UNIQUE(wc_order_id)` (idempotent double-credit guard), self-referral guard, priority-20 ordering after entitlement grant — and it's the best-tested file (real coupon/order/wallet end-to-end + idempotency + self-referral in class-test-suite.php).
- **`BHM_PurchaseLedger` / `BHM_Anchoring`:** the "MUST NEVER be consulted for access control" rule is respected — writes-and-reads-own-table only, no hook into the gate/entitlement decision path; reversal rows are additive (history, never overwrite). Proof-file download is owner-or-`bhcore_view_crm_sensitive` gated.
- **CRM sensitivity gating:** financial/fraud data in both `BHM_CRMIntegration` and `BHM_LedgerCRMIntegration` is behind `bhcore_view_crm_sensitive` (not the broader `bhcore_manage_crm`), including the collapsed summary line.
- **Nested-`init` footgun:** correctly avoided — `BHM_Blocks::init()` and `BHM_Storefront::init()` call `register_*()` directly rather than re-hooking `init` from inside an executing `init` callback (documented at length; matches the changelog's cross-plugin note).

---

## Prioritized punch-list

1. **(b) WooCommerce-guard consistency pass** — collapse 24 bare + 9 ternary checks to one `BH_Commerce::available()`. Mechanical, low risk, closes the abstraction seam the migration intended. *Do first — cheapest, and it's currently actively inconsistent.*
2. **(a) `BHM_Money` cents helper** — one `display()`/`price()`/`parse()` trio, replace ~34 format + ~8 parse sites. Money-path correctness (the two `number_format` idioms are confusable). *Do second.*
3. **M1 — single `FALLBACK_ACCESS_DAYS` const** — couple the proration divisor (`/30`) to the grant-expiry literals so they can't silently diverge. Small, but it's real money and an invisible coupling.
4. **M2 — extract + test `annual_savings_percent()`** — the one fan-facing money number not independently tested.
5. **L3 — dedupe the block-schema definitions** (BH_Content vs WP-core registration).
6. **L5 — collapse the two storefront-studio-blocks script handles** to one.
7. **L4 — remove dead `BHM_MockCommerce::delete()`.**
8. **L6/L7 — comment/consistency cleanups** (fold L7 into item 1; fix the self-contradicting docblock in L6).
9. **(c) Rewrite self-heal cross-plugin extraction** — leave for the scoped cross-cutting pass; re-verified present here.
