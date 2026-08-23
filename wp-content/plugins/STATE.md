# STATE — what is actually built

**Verified 2026-08-23 by reading code and running the suite, not by reading docs.** This file replaces `STATUS.md`, `ecosystem-depth-pass-2026-07.md`, and the status halves of the roadmaps. Its companions are `OPEN.md` (everything genuinely unfinished) and `DESIGN-CRAFT.md` (the design/UX thesis).

The rule that earned this file: **every prior status doc drifted in the same direction — claiming things were unbuilt that were actually shipped.** Six separate "still open" claims were false when checked this pass (listed at the bottom). Before writing "not built" anywhere, grep for it.

## Health gates (2026-08-23)

| Gate | Result |
|---|---|
| Test Runner (Debug Tools → Run all tests) | **635 tests, 19 suites, 634 pass** |
| Known failure | `bh-tickets` — `for_user() includes the requester's own ticket`. Pre-existing, unrelated to recent work. |
| `php -l`, all plugin files | clean (7,806 files) |
| PHPStan level 6 | **zero errors** |
| Horizontal overflow, admin, 1440→375, both themes | none |

A real PHP 8.5 runtime, MySQL, and a live WordPress install are all available now. Prior sessions had none of that, which is why so much of the older documentation is reasoned-through rather than verified. **Prefer running things over reading them.**

## Features — effectively complete

The feature layer is far more finished than the docs suggested. Confirmed present and wired:

- **Core (`own-ur-shit`)** — Jobs, Notifications, Roles, Events (`BH_Event`), Identity (`BHI_*`), Style/tokens (`BHY_*`), Debug Tools, Test Runner, API Docs, Audit log, `OUS_DebugLog` v2 (structured traces + self-trimming), Hypermedia/Datastar, Campaigns (`OUS_Campaigns` on `BH_Mail`), Metrics, Role-assignment UI, Media/CDN wizard, SEO (`BH_SEO`, schema.org, `llms.txt`), share cards.
- **Commerce (`bh-monetization-woo`)** — tiers, entitlements, wallet, gifting w/ redemption codes, free trials, coupons, pay-what-you-want, referrals/affiliates, full storefront + faceted browse, recommendations, and a tamper-evident purchase ledger with real OpenTimestamps anchoring.
- **LMS (`bh-courses`)** — courses/lessons/steps, quizzes, drip, tier-gating, reviews, certificates, progress admin, instructor notes, sessions calendar, interactive-video overlays, catalog with search/filter/sort/pagination.
- **CRM (`bh-crm`)** — people, activity on shared identity, nested kanban w/ SortableJS, card log, stall analytics, scenes/boards.
- **Federation (`bh-registry`)** — three-layer automatic discovery: open pull/crawl w/ SSRF guard, real ActivityPub actor (WebFinger, signed inbox), bootstrap seeds. 54/54 discovery tests pass.
- **Also real:** `bh-contest`, `bh-streaming`, `bh-feedback` (claim queue w/ atomic concurrency), `bh-live` (Cloudflare Stream), `bh-video`, `bh-tickets`, `bh-social`, `bh-mailpoet`, plus 2FA and the anti-fraud/safety stack.

## Design system — also more built than documented

Verified directly in `self-hosted-self-admin-skin/assets/css/admin-skin.css`:

- **Fluid spacing scale** — `--shsas-space-1..6`, real `clamp()` steps. *(Previously reported missing. It exists.)*
- **Atmosphere/haze** — `--shsas-haze-saturate/-brightness/-backdrop-blur`, `--shsas-focus-sibling-blur`, real `backdrop-filter`. *(Previously reported unbuilt. It exists.)* This is the "half-blood prince" depth-of-field half of the two-rule accent system.
- **Wallet-stack accordion** — collapse-and-peek small, fan-out large.
- **Command palette** (⌘K), **cross-document view transitions**, per-sidebar-item hue wayfinding, glow-for-importance badges.
- **Token bridge** — `--shsas-*` → `--bhy-*`, so peer plugins theme for free.
- **Four-layer style system** — tokens / utilities / components / plugin-local, documented in `STYLE-SYSTEM.md`. That doc is good; keep it current.
- **Front-end admin bar theming** — shipped, self-sufficient stylesheet with fallbacks. *(Previously reported "not started.")*

## Corrections — claims that were false when checked

Recorded so the same drift is easier to spot next time. Each of these was written as open/unbuilt somewhere in the docs and is actually done:

1. `--shsas-space-*` scale — claimed absent, exists.
2. Haze/atmosphere system — claimed unbuilt, exists.
3. Front-end admin bar theming — claimed "not started," shipped.
4. SortableJS adoption — claimed "worth adopting now," already vendored in both `bh-crm` and `bh-courses`.
5. `bhc_course_completed` / `bhm_entitlement_granted` / `_revoked` — claimed to fire with zero listeners, all now have listeners.
6. The two-part accent rule — claimed "AJ never finished describing it." He did; it's recorded in full.

`TESTS.md`'s old opening claim ("I don't have a PHP runtime available") was the most misleading of all — it is why several real bugs shipped unverified. Fixed.

## Where the remaining docs live

- `VISION.md` — mission, architecture, the big-vision pillars. Still the source of truth for *why*.
- `OPEN.md` — the consolidated unfinished backlog. Start here for *what next*.
- `DESIGN-CRAFT.md` — the design/UX thesis and craft backlog.
- `STYLE-SYSTEM.md` — the four layers; check before writing any new style rule.
- `TESTS.md` — how to run the suite and what it covers.
- Design-pass-only roadmaps still carrying real unbuilt scope: `ROADMAP-federated-metrics.md`, `ROADMAP-obs-integration.md`, `ROADMAP-streaming-media-scope-and-blockchain.md` (Part 1), `ROADMAP-lms-instructor-student-depth.md`, `ROADMAP-hyperpress-migration.md`, `ROADMAP-guided-setup-wizards.md` (kept for its reusable wizard pattern, not its status).
- `ETCH-COMPATIBILITY-NOTES.md` — why full-content-replacement was dropped. Real constraint, still binding.
- `own-ur-shit/PAGE-BUILDER-DELETE-KEEP-AUDIT.md` — why the custom page builder was deleted. Read before anyone proposes rebuilding one.
- `CODEBASE-WALKTHROUGH.md` / `WALKTHROUGH-GUIDE.md` — onboarding curriculum and the screen-by-screen GUI inventory (the latter doubles as the audit checklist).
