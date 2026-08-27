# OPEN — everything genuinely unfinished

**Consolidated 2026-08-23** from `PRODUCTION-READINESS-PLAN.md`, `STATUS.md`, `ROADMAP-ux-polish-and-feature-parity-2026-07.md`, `ecosystem-depth-pass-2026-07.md`, and the live session plan. Those files were deleted after their open items were carried here; everything below survived that merge because it is real and unbuilt. Anything they claimed that turned out to be already shipped is recorded in `STATE.md` instead.

Ordered by leverage, not by age. Design/craft items have their reasoning in `DESIGN-CRAFT.md`; this file is the tracker.

---

## Tier 1 — small, bounded, high leverage

These are cheap and disproportionately improve how the whole thing feels.

**Closed 2026-08-24, recorded because each was a class of bug rather than one screen:**

- **Third-party admin screens.** WooCommerce text measured 1.1:1 against our dark canvas — their CSS assumes WordPress's white admin, which is true of essentially every plugin. `shsas-owned` / `shsas-unowned` now contains unowned screens back to core's own values, scoped to `#wpbody-content` so our chrome stays ours. Analytics 22 failures → 0; Customers and MailPoet 0. See `THIRD-PARTY-SKINNING.md`.
- **Wide tables.** 21 `table.widefat` rendered without `.bhy-table-wrap` against 18 with it. Below 782px an unwrapped table now scrolls in its own box. Project Tracker overflow → 0px.
- **Sessions calendar.** FullCalendar was vendored with no stylesheet at all; the bundle contains zero style rules. Now ships `skeleton.css` + classic theme, themed through its `--fc-classic-*` variables (its class names are hashed, so selector overrides were never possible).
- **The suite's one red test.** `bh-tickets for_user()` — `$wpdb` returns bigints as strings, so a strict `in_array` never matched. **635/635 passing**, first fully green run on record.
- **Audit blind spot.** `tests/ux/audit.ts` returned `null` for `color(srgb ...)`, silently exempting most of the badge system from every audit ever run. Fixed; both failure modes documented in `UX-AUDIT-PLAN.md`.

1. ~~**Elevation scale.**~~ **DONE 2026-08-24.** Surfaces were a 1.07 / 1.10 / 1.13:1 ladder — all below the ~1.2:1 point at which two surfaces read as different — with one `--shsas-shadow` serving 14 rules plus 8 hardcoded shadows. Now a uniform **1.21:1** ladder (the ceiling the text tokens allow: `--shsas-text-dim` lands at 4.55:1 on the top surface, and 1.22 fails it), plus `--shsas-shadow-sm` / `-lg` in both themes. Light theme carries the scale entirely in shadow, since an elevated surface there is already `#fff`.
2. ~~**Front-end `.bh-alert` component.**~~ **DONE 2026-08-24.** The gap was worse than recorded: zero uses, and **34 hardcoded error-red literals in three different values**. Now `BHY_Style::alert_css()` + `alert()`. Body text is `--bh-text` (an alert is prose, not a badge); the icon lifts its hue toward the text colour because `--bh-danger` measured **2.68:1** raw on the dark front end. Measured: body 13.35:1, icons 5.01–8.59:1.
3. ~~**Debug Tools sticky quicknav offset.**~~ **DONE, already fixed before this line was last confirmed.** The `--bhy-admin-bar-offset` token (`class-ui.php`) was introduced 2026-08-23 in commit `3b5c1bb`, one hour after this item's "re-confirmed" note was written against the pre-token literal — the note was checking stale code. Measured 2026-08-24 on the live Debug Tools screen: at 1280px, bar is `position:fixed`/33px tall, offset resolves to `32px`, quicknav top sits at `32px` (matches). At 375px, bar switches to `position:absolute` (scrolls away with the page, WP core's own mobile behavior), offset resolves to `0px`, quicknav top sits at `0px` — no gap, no overlap.
4. ~~**Front-end admin bar ignores the theme toggle.**~~ **DONE, confirmed 2026-08-26.** `admin-bar.css` gained a `@media (prefers-color-scheme: light) { :root:not([data-shsas-theme]) { ... } }` block (its own header comment: "OPEN.md gap, closed here") reusing the same light-mode values `admin-skin.css` already defines. Currently dormant in practice since `self-hosted-self-admin-skin` is deactivated (see the Parked section) — the fix is real but nothing loads it right now.
5. ~~**`focus-visible` coverage on the front end.**~~ **MEASURED 2026-08-24 — not the gap it looked like.** The "6 files across 14 plugins" figure counted *files defining* the rule, which is a poor proxy for coverage: broad selectors in `theme.css` reach most elements. Checking every focusable element against every loaded `:focus`/`:focus-visible` rule gives **0 uncovered on `/courses/` (37 focusable, 32 rules)** and **0 on `/archive/` (30 focusable)**. Re-measure per page rather than trusting the file count.

   *Method note:* the first attempt called `.focus()` and diffed computed styles, which reported all 37 as uncovered. Programmatic focus does not reliably match `:focus-visible` — that is a keyboard-interaction pseudo-class. Rule-matching is the reliable check.
6. ~~**Resolve the `#1DB954` question.**~~ **RESOLVED 2026-08-24 — already answered in code.** `class-style.php` documents it deliberately: `#1DB954` is the front end's established status green, distinct from admin's muted `--bhy-success` (`#1a7f37`), and an earlier attempt to mirror the admin shade would have been a fourth green nobody called. The CSS files already use `var(--bh-success, #1DB954)` correctly.

   The remaining raw hex sits in admin PHP inline styles. **Measured in both themes rather than assumed: 5.49–6.68:1 dark, 6.01–7.25:1 light — zero failures.** My own arithmetic predicted a light-theme failure and was wrong. Tokenising those inline styles is tidiness, not a defect.
6b. **Admin-GUI override seam.** `admin-skin.css` is 50% `!important` (1,457 declarations), so customising any admin screen means a specificity war. Needs a deliberate, verified cascade pass — note `!important` *reverses* `@layer` precedence, so layering alone won't solve it. See `DESIGN-CRAFT.md` "CSS architecture".
6c. **Hardcoded hex outside the token system** — 200+ literals across seven plugin stylesheets (`kanban-board.css` 42, `bhm/frontend.css` 34, `feedback.css` 31, `registry.css` 30, …). They don't follow the theme and can't be re-skinned.

## Parked — `self-hosted-self-admin-skin` is EXPERIMENTAL (2026-08-25)

**Deactivated, back burner, AJ's call.** Chasing "look native everywhere except our own pages" cost a long session and produced several confidently-wrong "it's native now" claims — every round matched only *color* and declared victory, while the menu kept rendering in the skin's own font at its own sizes, with 44px rows instead of core's ~34px, and an active item drawn as a left glow bar rather than core's solid blue fill. The lesson worth keeping: **to verify "does this look native," turn the plugin off and compare — never measure one property and generalise.**

Left in a coherent state (unowned screens load none of its CSS at all; a `shsas_full_skin` option restores the old everywhere-skin; chrome font/metrics/active-item matched). Not deleted — the design-token system, contrast/accessibility work, and command palette are real and may be worth harvesting. Just not active and not a priority.

**Where the design effort goes instead:** this ecosystem's own pages and front end (Tier 2 below), which is where a distinct identity actually belongs.

## Tier 2 — the front-end craft gap

The largest single distance between the current state and the stated vision. `DESIGN-CRAFT.md` argues this in full; the work items:

7. **Systematic front-end audit, plugin by plugin.** Never done. Courses catalog/lesson, contest voting/reveal, streaming player/library, CRM public profiles, storefront checkout, theme archive/search/404. Use the measured method in the audit-method memory — contrast, overlap, clipping, overflow, at 1440/1280/1024/961/782/375, both themes.
8. **Unaudited admin screens.** Style Gallery, API Docs, Test Runner, quiz editor, `bh-registry` admin, `bh-streaming` Pro Wizard, `bh-monetization-woo` tier UI.
9. **Motion/feedback parity.** wp-admin has view transitions, haze, a command palette. The front end has none of that vocabulary, and neither side has skeleton/loading states in our own code (only vendored ones). State changes are page reloads.
10. **Design the moments that carry weight.** Course completion, entitlement granted, contest reveal, first supporter — all real state changes with emotional stakes, all currently rendered as table rows or redirects. The hooks now have listeners; what's missing is a shared celebration/acknowledgement treatment rather than per-plugin one-offs.
11. **First-run experience.** `VISION.md` makes "it just works" a design principle and the media/CDN wizard proves the pattern, but a brand-new install's first five minutes are undesigned.
12. **Periwinkle accent review across all screens.** The accent moved to `#8FA6E8` (dark) / `#4C63B6` (light); it propagates everywhere `--shsas-accent` is used. Two real contrast failures already traced to it this pass. Needs a sweep, not spot fixes.

## Tier 3 — feature scope, genuinely unbuilt

13. ~~**Design Suite Page Manager, Tier 4**~~ **STALE ENTRY, corrected 2026-08-26.** Traced through git history (`git log --all --oneline | grep "Page Manager"`) since the plan doc itself was deleted in a later doc-cleanup pass: this feature had Phases 1/2/3/5 shipped and Phase 4 explicitly DROPPED, not deferred (`80e67c6` — defaulting new pages to Design-Suite-managed was rejected outright because it silently breaks Etch-authored `post_content`; `bh/page-content` block is the documented recommended path instead). "Tier 4" here was this same Phase 4, misfiled as still-open backlog rather than a deliberate no. `class-page-surface.php` (the-self-hosted-self) is live with the managed toggle, `BH_Element::delete_context()` cleanup hook, and its own test coverage. Nothing left to build here — if a NEW Page Manager idea comes up later, it needs its own fresh scoping, not a resurrection of the dropped Phase 4.
14. ~~**Haze → scroll-proximity driven.**~~ **DONE 2026-08-24, on the catalogs.** Continuous viewport-proximity depth of field, as a **CSS scroll-driven animation** (`animation-timeline: view()`) — no JavaScript and no `IntersectionObserver` bookkeeping. Where unsupported the `@supports` block simply does not apply and every card stays crisp, so it is pure enhancement.

    Calibrated to the brief's own tie-breaker, usefulness over decoration: **1px blur, 0.72 opacity** at the extremes, not a heavy cinematic falloff. A card you have scrolled to sits in the crisp middle third; only cards genuinely at the edge of attention recede. `:focus-within` forces crisp with `!important`, since an animation outranks a normal declaration and keyboard focus must never land on a hazed card.

    **Not visually verified.** This environment's browser reports `prefers-reduced-motion: reduce`, so the rule is correctly suppressed here. Verified instead that it parses and would apply (`animation-timeline: view()`, `animation-range: entry exit`, `animation-name` resolving). Worth a real look on a machine without reduced motion.

    *One trap worth keeping:* the first version used the `animation` shorthand without a duration, which sets it to `0s` and silently disables a scroll-driven animation — every card computed `filter: none` with no error anywhere. Scroll timelines need `animation-duration: auto`.

    Still open for item 9: the rest of the front end has none of this vocabulary yet.
15. ~~**Ecosystem dashboard plugin cards**~~ **DONE.** (a) `OUS_Dashboard::render_github_status()` (class-dashboard.php) — per-card inline GitHub status, called from `render_card()` right after the version line. (b) `CONVENTIONS.md`'s "Every new plugin gets a dashboard card, as a first build step" section. Both confirmed present in the current code/docs.
16. ~~**`bh-feedback` timestamped audio annotations**~~ **DONE 2026-08-26.** Shipped as a feature of the existing 'detailed' tier, not a new priced tier (explicit decision). New `BHF_Annotations` class + `bh_feedback_annotations` table; reviewer-only new markers, submitter can reply. First JS this plugin has ever shipped (`assets/ts/feedback.ts`, Web Audio API waveform, no bundler). Verified end-to-end against the real local WP+MySQL install — bh-feedback 0.2.0.
17. ~~**Auction listings**~~ **DONE 2026-08-26.** `BHM_Auctions` (bh-monetization-woo) turned out to already be a real, working backend engine from a 2026-08-01 design pass — this entry was stale in claiming it wasn't scoped. Finished the two real gaps: `BHM_AuctionAdmin` (product-edit metabox to author an auction) and `BHM_AuctionFrontend` (front-end bid form/status). Payment timing reworked from bid-time wallet holds to charge-on-win (explicit standing instruction) — a bid holds nothing, the winner is charged via `BHM_Wallet::debit()` only at close; an under-funded winner closes the auction unsold rather than falling back to the next bidder (real v2 gap). A winner already flagged by `BHM_Fraud`'s existing refund-pattern tracking skips the automatic charge and needs a human admin approve/reject action (`awaiting_review` status) — the real answer to VISION.md's fraud-interaction question, since `BHM_Fraud` has no synchronous blocking gate to hook into. Verified end-to-end (bid/reject/close/charge, and the fraud-flagged path) against the real local WP+WooCommerce+MySQL install. bh-monetization-woo 0.6.0.
18. **Social integrations (`bh-social`)** — **STALE ENTRY, corrected 2026-08-26.** This was never actually unbuilt. `BH_SocialPlatform` (cross_post/pull_stats/is_configured/get_status/disconnect) has real, substantial implementations for all four organic platforms — YouTube (391 lines), Twitch (365), Meta (406), TikTok (418), ~1580 lines total — plus a separate `BH_AdsPlatform` interface for paid campaigns (Roku/Samsung/Spotify/Vizio, out of scope for "social"). What genuinely was missing, confirmed by grepping the whole plugins tree: cross-posting was 100% manual (a textarea + button on bh-social's own settings page) — no plugin's own publish event ever triggered one automatically. **Cross-posting v1 shipped 2026-08-26** (`BHSO_AutoAnnounce`, bh-social 0.4.0): opt-in, default-off, Twitch-only auto-announce on first publish of `bh_course`/`bh_contest`/`bhs_release`, reacting to core `transition_post_status` directly. YouTube/Meta/TikTok deliberately excluded from the automatic path — their `cross_post()` uploads real media (attachment_id/video_url/image_url), and there's no matching media file to invent for a course/contest/release announcement; they stay manual-only. Still open: **embedding** (showing a connected platform's content inside this ecosystem's own pages — not investigated) and **import** (pulling a platform's existing content in — not investigated).
19. ~~**Branching lesson paths** and **mind-map authoring**~~ **DESIGN DOC DONE 2026-08-26** — `ROADMAP-branching-lessons-and-mindmap.md`. Recommends a `bhc_course_graph` edge-list kernel (lessons/steps as nodes, conditional edges) built first with a plain admin form, THEN a generic `{nodes, edges}` mind-map canvas as a second pass reusing the same authoring shape — explicitly NOT a page-builder revival, nodes only ever reference existing content (lessons/`BH_Element` placements/`BH_Content` blocks), never author it in-place. No code yet, per instruction — this was a design pass only.
20. ~~**In-admin version history for user-built content.**~~ **STALE ENTRY, corrected 2026-08-26** — this was never actually unstarted. `OUS_Revisions` (the-self-hosted-self, `class-revisions.php`) already exists, fully built, with real consumers (`bh-contest`, `bh-monetization-woo` tiers, several core screens) — this line was simply never updated after that shipped. `bh-courses` (`bh_course`, not `bh_lesson` — see its own CHANGELOG 0.7.0 for why) is now a consumer too. If another plugin's own user-built config needs this, it's the same three-line pattern (`snapshot()` on save, `render_history_panel()` in a metabox, a restore handler) — not a new design question.
21. **LMS authoring depth** — more block types (the interactive-video block becomes one), plus a curated LMS-specific inserter palette rather than the generic Studio list. Incremental, well understood.
22. ~~**Design question: annotation completion granularity.**~~ **ANSWERED AND BUILT.** Sub-index per annotation (explicit choice) — `BHC_Progress` gained a `sub_index` dimension (DB_VERSION 1.6) on top of `(user_id, lesson_id, step_index)`; `sub_index = 0` is the step's own row, a positive value is one specific in-video annotation's own independent completion record.

## Tier 4 — design-pass-only, nothing built

Each still has its own doc because the thinking is worth keeping:

- `ROADMAP-federated-metrics.md` — privacy-preserving cross-instance metrics.
- `ROADMAP-obs-integration.md` — StreamElements-shaped tooling for `bh-live`.
- `ROADMAP-streaming-media-scope-and-blockchain.md` Part 1 — streaming media scope.
- `ROADMAP-lms-instructor-student-depth.md` — scheduling, grading, homework.
- `ROADMAP-hyperpress-migration.md` — the live Datastar conversion backlog.
- `VISION.md`'s three big-vision pillar sections — unscoped strategic bets, deliberately.

## Deploy gap — RESOLVED 2026-08-25

**`self-hosted-self-admin-skin` was git-tracked but absent from `deploy-ftp.yml`'s `PLUGIN_FOLDERS` whitelist**, so it never reached the live site — every design-system change (token system, contrast fixes, admin-bar mobile layout, periwinkle/cyan accents) existed only locally. Added to `PLUGIN_FOLDERS` (now 14 entries), and `tools/check-plugin-whitelist.sh` (wired into `checks.yml`'s `repo-consistency` job) now enforces this list stays in sync with `tools/ecosystem-plugins.txt` going forward, so this specific class of drift can't silently recur. Confirmed live on `billyhume.wasmer.app` shortly after.

## Tooling — adopted 2026-08-23

Playwright, Stylelint, Vitest, PHPCS (security, changed-files) and a CSS formatter are all installed, configured, wired into CI, and passing. See `TOOLING-EVALUATION.md` for the tuning decisions. Still open from that evaluation:

- **Make PHPCS blocking** — currently `continue-on-error: true`. Flip once the touched-file debt (~1,200 pre-existing low-severity findings: un-unslashed nonces, `esc_url_raw` without `wp_unslash`) is paid down.
- **PHPStan level 7** — tracked non-blocking in CI since 2026-08-25 (`phpstan-level7.neon`), ~486 findings, mostly unhandled `|false`/`|WP_Error` unions. Still worth doing as its own project. (A native `: never` return-type declaration on a function that unconditionally `exit()`s is fine and used correctly elsewhere — the thing that broke the clean level-6 gate previously was a `@phpstan-return never` *docblock annotation*, a different mechanism; don't conflate the two.)

**Adopted 2026-08-25** (were open here as of 2026-08-23):

- **`wp-env` + `wp-phpunit`** — `bh-monetization-woo/tests-integration/` runs `BHM_Wallet::debit()`'s atomicity claim against real MySQL, wired into `checks.yml` as `db-integration-tests` (`continue-on-error: true` pending its first clean GitHub-runner pass). Verified by deliberately breaking the atomic guard and watching the concurrent-write test fail.
- **The `ux-audit` CI job** — the old `if: false`-gated job in `checks.yml` is gone; the logged-out audit moved to its own `storybook-audit.yml`, which needed no provisioned WordPress instance after all (it points at the real live site). Paired there with a new Storybook build+screenshot-diff job.

## Found by the new tooling, not yet fixed

- ~~**Theme search button fails AA**~~ **FIXED 2026-08-25** (the-self-hosted-self 3.13.0), along with its real root cause: `--bh-accent-contrast` was consumed in 15 places but defined nowhere, so uses fell through to disagreeing hardcoded fallbacks (11 dark, 4 white). Now derived from measured contrast against the chosen accent. Accent also moved #C1503A -> #C85C48 (AJ's call).
- ~~**Light "The Door — Day" theme accent fails AA**~~ **FIXED 2026-08-26** (the-self-hosted-self 3.15.3). Changed to `#A83D1A` (same hue, deepened) — 5.24:1 on background, 4.65:1 on surface, both real AA passes. The live site is currently on "The Door — Night" (confirmed via the real `bhy_style_settings` option), so this was a preset-catalog fix, not an immediate live-site visual change.
- ~~**Touch targets under 44px at ≤782**~~ **FIXED 2026-08-26** (the-self-hosted-self 3.15.3) — `.oust-nav-toggle` grown directly to 44×44 (a real icon button); `.oust-card-readmore` and `.oust-site-brand` given an invisible `::after` hit-area extension to 44px tall instead of visible padding (both are real independently-focusable links, not decorative riders on a bigger click area, and padding would have grown the rows they sit in). Verified live in a browser via `getBoundingClientRect()` — all three now measure a true 44px+ hit area with zero visible layout change.
- ~~**CHANGELOG.md behind Version: header**~~ **FIXED 2026-08-26** — `bh-contest` (3.7.33) and `bh-tickets` (1.0.3) both reconstructed from real git history (`git log -S"Version:..."` against each version bump's actual commit, not invented) and added to their CHANGELOG.md files; `tools/check-changelog-drift.sh` now reports every plugin OK, including `self-hosted-self-admin-skin` (already resolved separately, no longer drifted). The 3 unrecorded `bh-courses` versions between 0.4.86 and 0.4.91 remain genuinely unrecoverable — no real history exists behind those specific bumps to reconstruct from, unlike bh-contest/bh-tickets where the commits themselves still had it.

## Vendor cleanup

An early CSS-formatter run reformatted **WooCommerce's** stylesheets (gitignored, so not revertible via git). Semantically identical and the site is healthy, and the next WooCommerce update overwrites them — but if pristine vendor files are wanted sooner, reinstall WooCommerce 10.9.4 from wp-admin. The formatter now hard-refuses non-ecosystem paths.

## Blocked on AJ, not on code

- **Wasmer deploy-gap verification** — needs a direct check of `dev-ous.wasmer.app`'s GitHub Updates → "Update now" behavior. `self-update-canary.yml` (2026-08-25) now confirms the URLs that mechanism depends on resolve correctly every day, but that's a curl check against raw GitHub URLs, not a real click-through of the "Update now" button in wp-admin — the actual install flow is still unverified.
- **`bh-courses` Sessions calendar blank on deployed site** — no code bug found; local renders correctly. Needs live devtools Network/Console on the deployed page to confirm whether the 777KB vendored FullCalendar 404s or gets `async`/`defer`/`module` rewritten by an optimization layer. The JS guards on `typeof FullCalendar === 'undefined'` and fails silently, matching the symptom exactly.
- **ActivityPub relay** — cannot run on `billyhume.wasmer.app` (that PHP/WASM build has no openssl; all crypto is `function_exists()`-guarded and degrades cleanly). Works on ordinary hosting. No relay URL chosen yet.
- **Bootstrap seeds** — only one entry; should be 3–4 so no single host is a cold-start single point of failure.

## Standing verification discipline

Non-negotiable, learned from real escaped bugs:

- `php -l` every touched file; PHPStan must stay at zero; run the Test Runner suite.
- **Measure, don't eyeball.** Contrast, overlap, clipping (`scrollHeight` vs `clientHeight`), horizontal overflow.
- **Reload per theme — never toggle `data-shsas-theme` and re-read in the same task.** `var()` references do not re-resolve; this fabricated 39 contrast failures that did not exist.
- To decide "skin bug or WP core bug," disable the skin's stylesheets and re-measure.
- Version bump + changelog in the same commit, matching the established voice.
- Say plainly when something was reasoned through rather than run.
