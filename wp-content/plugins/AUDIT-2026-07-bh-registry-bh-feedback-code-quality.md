# Code-Quality Audit — bh-registry & bh-feedback

**Date:** 2026-07-25
**Model:** Claude Sonnet 5 (claude-sonnet-5)
**Scope:** `bh-registry` (11 PHP files, 1627 lines) and `bh-feedback` (8 PHP files, 785 lines), combined into one task per dispatch instructions.
**Method:** Full read of every `.php` file in both plugins (all files listed below), static analysis only.
**Caveat:** No live PHP/MySQL/WordPress execution environment was available in this session — every finding below is from reading source, not from running the code. Anything phrased as a bug is a static-read finding, not a runtime-confirmed one, unless noted otherwise.

Files read — bh-registry: `bh-registry.php`, `includes/class-links.php`, `includes/class-activator.php`, `includes/class-api.php`, `includes/class-admin.php`, `includes/class-frontend.php`, `includes/class-verification.php`, `includes/class-streaming-bridge.php`, `includes/class-style-surface.php`, `includes/class-debug.php`, `includes/class-test-suite.php`.
Files read — bh-feedback: `bh-feedback.php`, `includes/class-activator.php`, `includes/class-post-types.php`, `includes/class-pricing.php`, `includes/class-requests.php`, `includes/class-queue.php`, `includes/class-portal-panel.php`, `includes/class-test-suite.php`.

---

## Rewrite-rule self-heal duplication — re-check verdict

**Verdict: still true, unchanged.** `bh-registry` has NOT grown a third copy of the ~90-line versioned/throttled-retry/forced-flush rewrite-rule algorithm that `own-ur-shit/class-portal.php` and `bh-monetization-woo/class-storefront.php` both implement.

The plugin's only rewrite-rule interaction is one line: `bh-registry/includes/class-activator.php:25`, inside `BHR_Activator::activate()`:

```php
public static function activate() {
    if (self::create_or_update_schema()) {
        update_option('bhr_db_version', self::DB_VERSION);
    }
    self::maybe_create_default_pages();
    flush_rewrite_rules();
}
```

A plain, unconditional, activation-only `flush_rewrite_rules()` call — no versioning, no throttle, no self-heal on `init`/`admin_init`. This is consistent because `bh-registry` doesn't register any custom rewrite structure (no CPT with custom permalinks, no custom query vars) — the plugin exposes a REST namespace (`bhr/v1`) and a plain shortcode page, neither of which needs rewrite rules to self-heal in the first place. There is nothing here that *should* grow the self-heal pattern; its absence is correct, not a gap.

`bh-feedback` has no `flush_rewrite_rules()` call anywhere at all — its one CPT (`bh_feedback_request`, `class-post-types.php`) is registered `'public' => false` with no rewrite/archive surface, so there is nothing to flush. Also correctly not present.

---

## bh-registry

Overall quality is high and consistent with the ecosystem's established bar — dense why-not-what comments, explicit cross-references to sibling classes and plugins (bh-streaming, bh-contest, bh-courses), a real test suite (`class-test-suite.php`, 248 lines) covering the actual trust-critical logic (`BHR_Verification::check_domain_ownership`, `check_activitypub_actor`, end-to-end `verify_link()`), and consistent `class_exists()` guarding for every peer-plugin touchpoint (`BHY_Style`, `BH_SEO`, `BHI_Reports`, `OUS_Jobs`, `OUS_DebugLog`, `BHS_Player`, `OUS_TestRunner`) — bh-registry depends only on core (`own-ur-shit`) and treats bh-streaming, bh-crm, etc. as fully optional throughout, matching the ecosystem convention.

### Findings

1. **No genuine defects found on this pass.** The two "error-handling audit gap" comments in `class-api.php:204-219` and `:224-240` document real prior bugs (unchecked `$wpdb->insert()` return values) that have already been fixed in the code shown — they're left as inline documentation of what the fix prevents, not open issues. Confirmed both `$wpdb->insert()` calls are now checked and return proper `WP_Error`/500 on failure.

2. **Minor: `BHR_Verification::recheck_all()` inline fallback duplicates `recheck_one()`'s call shape** (`class-verification.php:250-268`). When `OUS_Jobs` isn't active, the method loops and calls `self::verify_link($link)` directly rather than reusing `recheck_one()` (which just unwraps `$args['link_id']` and calls the same thing) — functionally harmless, negligible duplication, not worth flagging as a real defect. Noting only because it's the one place two code paths do the same thing slightly differently.

3. **`class-admin.php` action handler uses `wp_die('Not allowed.')` on a failed nonce/capability check** (`class-admin.php:123-125`) rather than returning a friendlier redirect — consistent with `OUS_AdminGuard::verify_nonce_and_cap`'s apparent convention elsewhere in the ecosystem (not independently verified in this pass since `OUS_AdminGuard` lives in `own-ur-shit`, out of scope), so not flagged as a deviation.

No file/line-level defect meets the bar for a real finding here. This plugin was already in good shape at the last full status pass and remains so.

---

## bh-feedback

### Build-state correction

STATUS.md's characterization ("entirely unbuilt, still just a plan") is **stale**. The plugin is a real, working v1 build: activator with a versioned migration (`class-activator.php`), a CPT (`class-post-types.php`), pricing (`class-pricing.php`), a submitter-facing shortcode + wallet-charge flow (`class-requests.php`, 209 lines), a self-serve reviewer claim queue with atomic concurrency guards (`class-queue.php`, 157 lines), a portal panel wiring both sides together (`class-portal-panel.php`, 130 lines), and a real test suite exercising the claim/release/complete state machine (`class-test-suite.php`, 89 lines). `bhcore_review_submissions` (the capability STATUS.md said was "waiting on it") is actively checked in three places: `class-queue.php:129,137,145` and `class-portal-panel.php:41`. This is not a skeleton — treat STATUS.md as out of date on this plugin, not as ground truth.

Given that, a normal-depth code-quality pass is warranted and was performed (not "too early").

### Peer-plugin-optionality convention — check

Mostly follows the convention correctly:
- `BHM_Wallet` (bh-monetization-woo) is checked via `class_exists('BHM_Wallet')` before every use — `class-requests.php:40, 103`. Submission is disabled with an explicit notice, not a broken form, matching the plugin's own docblock claim.
- `BHS_AudioHash` (bh-streaming) is explicitly **not** called — the plugin's own docblock (`class-requests.php:9-19`) explains why (that class is hardcoded to `bhs_track` posts and can't be reused) and implements its own self-contained sha1-file-hash duplicate check scoped to `bh_feedback_request` posts only. This is a deliberate, well-justified divergence, not an oversight — correctly optional in the sense that bh-streaming's absence changes nothing.
- `OUS_Notifications` (core, optional feature) is guarded — `class-queue.php:110`.
- `OUS_TestRunner`/`OUS_Debug` guarded — `bh-feedback.php:64`, `class-test-suite.php:21`.

**Gap: bh-feedback never registers itself via the `ous_registered_plugins` filter.** Every other plugin audited in this ecosystem (including bh-registry, see `class-admin.php:38-60` above) has an admin class hooking `ous_registered_plugins` to appear in the core's plugin-management dashboard, get a `dashboard_link`, and optionally a `bundled_zip` one-click-install entry. bh-feedback has no `class-admin.php` at all — its `foreach` list in `bh-feedback.php:45` is `['activator', 'post-types', 'pricing', 'requests', 'queue', 'portal-panel', 'test-suite']`, no `admin`. This means bh-feedback is currently invisible to the core's own plugin registry/dashboard even once active, unlike every sibling plugin. Whether this is intentional (v1 scope deliberately deferred it) or an oversight isn't determinable from the code alone — flagging it as a real, concrete gap rather than a code-quality nitpick, since it's a functional absence relative to an established ecosystem-wide convention, not a style issue.

### Findings

1. **`bh-feedback.php` (registration gap)** — no `ous_registered_plugins` filter hook / admin class, unlike every peer plugin (see above). Not a crash risk, but a real functional gap against the plugin's own "genuine peer to bh-courses/bh-contest/bh-streaming" framing in its top docblock (`bh-feedback.php:32-44`).

2. **`class-queue.php:106-108` — unchecked `$wpdb->insert()` on the review row.** Unlike bh-registry's `class-api.php` (which now checks every insert after its own documented "audit gap" fix — see above), `BHF_Queue::complete()` does not check the return value of `$wpdb->insert($wpdb->prefix . 'bh_feedback_reviews', [...])`. The surrounding comment (`:98-105`) explicitly acknowledges this failure mode ("If this somehow fails... a completed request with no review text is a rare, visible anomaly an admin can investigate") and argues it's an acceptable tradeoff versus rolling back the status transition — this is a reasoned, documented design choice, not an oversight, so it does not rise to the level of the bh-registry gap that was fixed. Noted for completeness rather than as an actionable defect.

3. **Minor: `class-requests.php:144` — `check_duplicate()` runs after the request post and its meta are already fully written**, including `_bhf_status`. If `check_duplicate()`'s own DB read (`class-requests.php:200-206`) throws or the process dies between `update_post_meta` calls, the request would be left mid-construction (e.g. missing `_bhf_duplicate_of`) but already publicly claimable — the queue code doesn't appear to depend on `_bhf_duplicate_of` being present for claim/complete to function (`class-queue.php`'s `claim()`/`complete()` never read it), so this is a low-severity, unlikely-in-practice gap rather than a functional bug. Mentioned for completeness only.

Nothing else in this plugin rises to a genuine finding — the atomic claim/release/complete state machine (`class-queue.php:56-119`) is correctly guarded (single conditional UPDATE per transition, ownership re-checked before release/complete), and the plugin's own test suite (`class-test-suite.php`) directly exercises the concurrency race it claims to prevent (double-claim, wrong-reviewer release, wrong-reviewer complete, double-complete) — all verified by reading the test bodies, not just their names.

---

## Summary

- **Rewrite-rule duplication:** confirmed still absent from bh-registry; correctly absent from bh-feedback too (neither plugin needs it).
- **bh-registry:** clean, no real findings; matches the ecosystem's comment-density and peer-optionality bar throughout.
- **bh-feedback:** build state is materially ahead of STATUS.md's "entirely unbuilt" description — it's a working v1 with real test coverage. One concrete gap (missing `ous_registered_plugins` registration, unlike every peer plugin) and two documented/low-severity items, not padding-required filler.
