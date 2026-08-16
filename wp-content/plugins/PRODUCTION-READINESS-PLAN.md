# Production-Readiness Plan (2026-08-16)

Direct request: "research and analyze... to determine what else we can
do to fulfill the requirements of the vision and roadmap documents to
make this thing production ready and magical to use... try not to
forget anything or let little things slip through cracks."

Method: four parallel research passes, each reading a cluster of
VISION.md/ROADMAP-*.md/design-plan documents and verifying every stated
requirement against the ACTUAL codebase (grep/Read, file:line
citations) rather than trusting the document's own claims — this
ecosystem's planning docs have a confirmed, repeated history of drift
in both directions (see Finding 0 below). Full per-requirement tables
are in the Appendix; this top section is the actionable plan.

---

## Finding 0 — Documentation rot is the #1 systemic issue, fix this first

Every one of the four research passes independently found the same
failure mode: a roadmap doc says a feature is "not built" / "roadmap
only" / "still entirely unbuilt," and the feature is fully implemented
and live in code. Confirmed instances, all real, all verified:

- **VISION.md's "near-term roadmap" section**: Metrics dashboard, email
  campaigns, front-end user bar, role-assignment UI, per-notification
  email preferences, SEO (meta/OG/JSON-LD), site-wide search, in-admin
  version history, and auctions are ALL fully built — the doc marks
  most of these "not started" or "worth building." Two are explicitly
  superseded by newer docs not yet reconciled (`ROADMAP-discoverability.md`,
  `ROADMAP-search-and-revisions.md` — both referenced in code comments,
  neither read in this pass, both need a follow-up read).
- **ROADMAP-platform-evolution.md**: `BH_Mail` and in-admin version
  history both marked "not built yet" — both exist.
- **ROADMAP-obs-integration.md**: states "Design pass only. Nothing in
  this document is built." False — Phases 1, 2, and 3 are all
  implemented and wired into a real admin UI.
- **ROADMAP-guided-setup-wizards.md §5**: GitHub-based update checks
  called "entirely unbuilt" — actually built, and this exact session's
  own git history shows two real bugs found and fixed in it ("Fix
  site-breaking bug: 'Check now' ran synchronously, timed out the whole
  site").
- **ROADMAP-lms-instructor-student-depth.md**: frames 1:1 scheduling/
  booking as unscoped research — it's fully built (`BHC_Sessions`, a
  real booking table, calendar UI, atomic-claim concurrency pattern).
- **DESIGN-SUITE-UNIFICATION-PLAN.md / ELEMENT-BUILDER-DESIGN-PLAN.md**:
  the reverse direction — both describe a custom visual page-builder
  GUI as the current plan, but it was built in full and then entirely
  deleted 11 days after the docs' own last "STATUS" update. The
  deletion's own reasoning doc (`PAGE-BUILDER-DELETE-KEEP-AUDIT.md`)
  no longer exists in the repo either — its content survives only as a
  comment block in `own-ur-shit/own-ur-shit.php:2261-2298`.
- **`BHC_Reviews`** (course ratings/reviews, a real shipped feature) is
  absent from all four LMS-related roadmap docs entirely — nobody
  documented it existing at all.

**Action**: before starting any of the "not started" work below, treat
every remaining "not started"/"roadmap only" claim in ANY doc not
covered by this pass (`ROADMAP-discoverability.md`,
`ROADMAP-search-and-revisions.md`, and anything else not listed above)
as unverified until grepped. A dedicated reconciliation pass — read
every roadmap doc, verify every claim, add a dated correction note at
the top of each stale doc (the pattern this session's own plan file
`lovely-sleeping-snowglobe.md` already uses successfully) — is real,
valuable, low-risk work and should happen before or alongside anything
else here. Budget: roughly one focused session, mostly grep/read, very
low implementation risk.

---

## P0 — Real bugs and exposure, fix before anything else

These are live risks, not roadmap gaps. Ordered by severity.

1. **GDPR/CCPA data-eraser coverage gap** — `own-ur-shit`, `bh-live`,
   `bh-streaming`, and `bh-courses` all implement
   `wp_privacy_personal_data_erasers`; **`bh-crm`, `bh-registry`, and
   `bh-monetization-woo` do not.** bh-crm stores notes/tags on real
   people, bh-monetization-woo stores non-financial customer records —
   a genuine legal-exposure gap for any site actually taking EU/CA
   users, not a nice-to-have. Real work: three new eraser callbacks
   following the existing four plugins' own pattern as the template.
2. **`BH_Content`'s `source:html` mismatch** (`own-ur-shit/includes/
   class-content.php:247`) — `tree_to_gutenberg_blocks()` unconditionally
   sets `'innerHTML' => ''`, while `studio.js:88,121` still declares
   `content: {source: 'html', ...}` on `bh/heading`/`bh/text`. This is
   the exact same bug class as the `bhc/text`/`bhc/quiz` content-
   integrity bug fixed this session (a block's real content model no
   longer matches its own attribute-sourcing declaration) — except this
   one is a live landmine waiting for the first `context_type='post'`
   Studio consumer, not yet-triggered. Confirmed the LMS itself is safe
   (lessons use `context_type='bhc_lesson'`, a different storage path).
   Real work: either fix the attribute source declaration or make
   `tree_to_gutenberg_blocks()` actually populate `innerHTML` — small,
   contained fix, but do it now while it's cheap, before something
   depends on the broken behavior.
3. **DMCA workflow is thinner than its file name implies** —
   `class-dmca.php` is, by its own docblock, only the designated-agent
   contact-info display + a shortcode. There is no actual notice-intake,
   counter-notice, or legal-timeline workflow anywhere. Low risk today
   (nobody's relying on it), but anyone reading "DMCA: done" from a
   file listing would be wrong. Real work: either build the real
   workflow (a genuine, multi-session feature) or rename/re-document
   the existing piece so it can't be mistaken for the whole thing —
   the second option is a 20-minute fix and should happen regardless of
   whether the first ever gets scoped.
4. **Dead-but-armed code**: `BH_Element::maybe_seed_default_states()`
   (`class-element.php:475-492`) and its `'states'` registration key
   call into `BH_Element_State`, a class deleted in the page-builder
   removal — guarded by `class_exists()` so it's inert, not broken, but
   it's dead weight with zero callers anywhere. Same for the orphaned
   `bhcore_element_prefabs`/`bhcore_element_states` tables and the
   `bhcore_element_placements.library_component_id` column still
   provisioned by `class-identity-activator.php`. Real work: a single
   cleanup pass — remove the dead method, and make an explicit decision
   (drop the orphaned schema in the next DB_VERSION bump, or leave a
   clear "kept for future reuse" comment instead of silence).
5. **Stale docblocks pointing at deleted things**: `class-design-suite.php:6-9`
   still describes a "Widgets & Elements" tab that no longer exists;
   `class-identity-activator.php`'s own changelog cites
   `LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md`, which doesn't exist in the
   repo (same problem as the already-known `PAGE-BUILDER-DELETE-KEEP-AUDIT.md`
   gap). Cheap, mechanical fixes — clean up alongside item 4.
6. **`own-ur-shit/element-prefab` block**: `OUS_Gutenberg_Block::register_block()`
   permanently no-ops now (guarded on a deleted class), which is
   correct — but whether any LIVE post still has this block embedded in
   its `post_content` was never actually checked against the real DB.
   Real work: one `post_content LIKE '%own-ur-shit/element-prefab%'`
   query across all post types, now that real DB access exists. If any
   post has it, that post is currently rendering nothing where a block
   used to be — worth knowing either way.

---

## P1 — Verification debt: code that's never been run

A recurring, explicit caveat across VISION.md's own "shipped this pass"
sections: many features were built and documented as complete during a
period with **no PHP/MySQL/network access at all** (confirmed in
CLAUDE.md's own project history) — meaning they compiled but were never
executed against a real WordPress install. Real execution now exists in
this environment (confirmed: `php -l`, live browser, working DB access
via wp-admin). This is high-value, low-effort work — much cheaper than
building anything new, and it's the difference between "should work by
analogy" and "confirmed working," the exact distinction this session's
own reconciliation work (task #22, the datepicker/Customizer sweep, the
bhc/text bug) kept finding real bugs by insisting on.

**Verified 2026-08-16**: ran the Test Runner's "Run all tests" live
against this install's real PHP/DB (Debug Tools → Test Runner) — **380
tests across all 12 currently-active plugins' registered suites, zero
failures.** This is real signal, not just "compiles" — genuinely
reassuring. Two plugins are simply INACTIVE on this install
(`bh-registry`, `bh-live`), which is why they contributed no suite
results — not itself a bug, but it means `bh-live`'s own suite has
never actually been exercised live here even though it's registered.

**Concrete sweep, in priority order** (most externally-facing / most
state-changing first):
1. `bh-live`'s `BHL_Overlay`/`BHL_Automation` (OBS bridge, browser-source
   overlays, token auth) — **zero test coverage in the source itself**
   despite being the newest, most stateful, most externally-integrated
   code in the ecosystem (confirmed: `class-test-suite.php` has 8 other
   test methods for bh-live but none for overlay/automation — a real
   gap regardless of whether the plugin is active). Add
   `run_overlay_tests()`/`run_automation_tests()` to the existing
   suite; can't smoke-test the live admin UI until the plugin is
   activated on an install (it's inactive here).
2. **`BH_SEO` — real, confirmed gap, verified live 2026-08-16.**
   `render_head_tags()` (`class-seo.php:55-56`) only outputs anything if
   some other code already called `BH_SEO::set_page_data()` earlier in
   the same request — it's an opt-in per-content-type API, not
   automatic. Confirmed the mechanism itself works correctly: a real
   `/courses/` page renders a real meta description and og:title
   (`grep` shows `class-render-catalog.php` calls `set_page_data()`).
   But `grep -rl "BH_SEO::set_page_data"` across the whole install shows
   it's only ever called from bh-registry (profiles), bh-contest
   (auth-gated pages), bh-streaming (player), and bh-courses
   (catalog/course pages) — **never from the theme's own `page.php`/
   `single.php`**. Confirmed live: both the homepage and a plain WP Page
   (`/sample-page/`) have zero meta description, zero OG tags, zero
   JSON-LD. This means every ordinary blog post and static page on the
   site — plausibly the majority of a real site's URLs — gets no SEO
   output at all, while only plugin-specific content types do. Real
   fix, small and contained: call `BH_SEO::set_page_data()` from the
   theme's own `page.php`/`single.php` (title + excerpt/content-derived
   description + featured image), the same pattern the four plugins
   above already establish.

   **FIXED 2026-08-16, both halves, verified live**: (a) a genuinely
   deeper bug than first diagnosed — `BHC_Render_Course::render_course()`
   called `set_page_data()` from inside the `the_content` filter, which
   runs AFTER `wp_head` (where the tags actually echo) already fired;
   extracted to `set_seo_data()` and added a `template_redirect` hook
   (fires before headers) for `is_singular('bh_course')` — confirmed
   live, a real course page now renders meta description/og:title/
   Course JSON-LD. (b) added the same template_redirect-hooked call in
   the theme's own `functions.php` for `is_singular(['page','post'])` —
   confirmed live on both a real Page and a real (shortcode-only) Post.
   One bug caught in the fix itself before shipping: `wp_strip_all_tags()`
   doesn't strip shortcodes, so a page whose content was literally just
   `[bh_contest_player contest="24"]` leaked that raw string into its
   meta description — switched to `get_the_excerpt()`, which handles
   both the manual-excerpt and auto-summary cases correctly (its own
   `wp_trim_excerpt()` already calls `strip_shortcodes()`). bh-courses
   0.4.85 → 0.4.86, theme 1.3.1 → 1.3.2. **Still open**: the same
   the_content-timing bug likely affects bh-contest, bh-streaming, and
   bh-registry's own `set_page_data()` call sites (all four were found
   calling it from inside content-rendering code, not just bh-courses)
   — bh-courses was fixed as the concrete proof-of-pattern; the other
   three need the identical treatment (extract to a named method, add a
   `template_redirect` hook keyed on `is_singular('<their CPT>')`).
3. `php -l`'d clean (confirmed this pass): auctions
   (`bh-monetization-woo/includes/class-auctions.php`), revisions
   (`OUS_Revisions` — already touched this session, one real bug found
   and fixed there), search (`OUS_Search`), role-assignment UI
   (`class-role-assignment.php`), DMCA (`class-dmca.php`), GitHub
   updates (`class-github-updates.php`), bh-live's overlay/automation,
   the anchoring/purchase-ledger pair. **Also confirmed**: Debug Tools'
   own Test Runner ("Run all tests") was actually run live against this
   install's real PHP/DB this pass — 380 tests across all 12
   currently-active plugins' suites, zero failures. Still worth walking
   each admin screen live and exercising the core action once (place a
   bid, restore a revision, run a search, assign a role) — passing unit
   tests don't catch every "does this admin page render without a
   fatal for a real click" case, and this pass only got to SEO before
   time ran out.
3. Chat aggregation merge logic (`bh-live/assets/js/obs-bridge.js`) —
   flagged by the research pass as "config storage confirmed real,
   actual merge-into-normalized-feed logic not read this pass." Read it
   and confirm it does what Phase 3 of the OBS roadmap claims, or file
   it as a real gap if it doesn't.
4. Now-playing OBS overlay for bh-streaming — the OBS integration
   research pass found `/chat`, `/votes`, `/health`, `/automation-bridge`
   routes but no `/now-playing` route, despite Phase 1 of
   ROADMAP-obs-integration.md naming it explicitly. Confirm this is a
   real gap (not just an undocumented route) before scoping it as new
   work.

---

## P2 — Confirmed real feature gaps, ranked by likely impact

Everything below was verified NOT STARTED (not just claimed) across all
four research passes. Grouped, not exhaustively re-described — see
Appendix for the full per-doc table.

**Genuinely load-bearing for "production ready," worth prioritizing:**
- Cross-plugin GDPR eraser gap — see P0 item 1, listed there because
  of its severity, not repeated here.
- i18n infrastructure — VISION.md's own "one file uses `__()`" framing
  was itself stale (51 files across the ecosystem call `__()`), but
  there's still no `.pot` file or locale-loading infrastructure
  confirmed — worth a real check before calling this "handled."
- A pluggable fallback for the OpenTimestamps anchoring calendar
  servers (`bh-monetization-woo/includes/class-anchoring.php`) — three
  hardcoded third-party URLs (alice/bob/finney), no alternate-provider
  contract. Not a hard rule violation (free, no custody, no wallet) but
  a soft external dependency inconsistent with this ecosystem's own
  `BH_Mail`-style "always ship a self-hosted default, third-party is
  an enhancement" convention. If those three servers ever go dark,
  every pending anchor stalls forever with no escape hatch.

**Real, substantial, deliberately-scoped-as-future-work features**
(each of these is a genuine multi-session build, not a quick fix —
listed for prioritization, not immediate action):
- LMS: branching lesson paths, mind-map authoring, instructor-graded
  assignments (needs a `bhc_submissions` table + grading queue +
  `bhcore_grade_assignments` capability — fully scoped in
  ROADMAP-lms-instructor-student-depth.md, just not built), course-level
  forum/discussion (current comments are lesson-scoped only), bulk
  student CSV roster import, course bundles, cross-course analytics/CSV
  export, PWA parity with bh-streaming.
- Monetization: donor/supporter wall, referral/affiliate tracking
  (ecosystem-wide — payment-plan/split-pay was also confirmed
  NOT STARTED).
- Platform: link-in-bio/landing-page tool, a real unified cross-plugin
  profile (current `BHI_Portal` aggregates panels but each stays
  plugin-siloed, not one history feed), a generic reusable `OUS_Wizard`
  step-runner framework (each wizard today is hand-rolled separately).
- Safety/legal: real audio fingerprinting beyond the sha1 stepping
  stone, a full DMCA notice/counter-notice workflow (see P0 item 3 for
  the immediate cheap fix; the real workflow itself is a bigger,
  separately-scoped build), royalty-split payout engine, per-fan
  demographic profiling (correctly gated behind a legal-review decision
  — don't build until that decision is made).
- Streaming/OBS: YouTube Content ID integration, PRO/MLC royalty-report
  export, realtime Jam transport (WebSocket/SSE — currently polling by
  deliberate design, with its own docblock noting the polling model was
  "built specifically to make this swap possible later").
- Design system: the Godot-style scene-tree / contextual add-child
  visual authoring UI has no replacement after the page-builder
  deletion — this is a real, if deliberate, regression relative to the
  original design-suite vision. Needs an explicit human decision: is a
  Gutenberg-native equivalent worth building, or is the native block
  inserter + BHY_BlockStyle combination (already shipped) considered
  sufficient going forward? Don't let this sit as an accidental gap —
  make it a decided one.
- Federated metrics (opt-in cross-instance aggregate reporting,
  k-anonymity threshold) — correctly still design-only, `OUS_Metrics`
  itself (the local-only prerequisite) is real and done.

**Deliberately out of scope / correctly deferred** (confirmed accurate
in the docs, no action needed): native mobile app, the three "big-vision
pillar" rounds (files/PM tool, BI/reporting, ERP/accounting/taxes,
email/calendar replacement, ActivityPub/federated social).

---

## P3 — Polish / scope-deviation flags

- **OBS automation shipped as a free-form rule builder** (event type ×
  action × free-text target) rather than the roadmap's specified fixed
  preset list — a real, documented design-principle deviation ("power
  without a rules-builder UI in v1"). Not necessarily wrong (the
  shipped version is arguably more capable), but worth a conscious
  "we changed our mind, here's why" note rather than silent drift.
- **Datastar migration is 2 of ~10 candidates converted** (`class-portal.php`,
  `bh-crm/segment-builder.js`) per `ROADMAP-hyperpress-migration.md`'s
  own tracked list. Confirmed as individually-scoped, later work per
  that doc's own stated policy — not urgent, just tracked here so it
  doesn't silently stall forever.
- `EVENT-TRACKING-ARCHITECTURE-PLAN.md`'s §6 consumer list is stale in
  the GOOD direction — `BH_Event::emit()` adoption has grown past what's
  documented (bh-tickets, mail-sent events, several bh-monetization-woo
  wallet/auction/referral events). Just needs the doc updated to match;
  no code work.

---

## Suggested execution order

1. Finding 0's reconciliation pass (cheap, prevents wasted future work
   on anything above).
2. P0, all six items — none is large, several are legal exposure or
   live landmines.
3. P1's verification sweep — cheap relative to its value, and this
   session has repeatedly shown live verification finds real bugs that
   static reading misses.
4. P2's "load-bearing" trio (GDPR eraser — already in P0, i18n check,
   anchoring fallback).
5. P2's feature list and P3 — pick by actual product priority; these
   are real, scoped, but genuinely multi-session builds, not something
   to start without explicit direction on which matters most right now.

---

## Appendix — full per-document findings

Preserved from the four parallel research passes, DONE/PARTIAL/NOT
STARTED/UNCLEAR per requirement with file:line evidence. Not
re-summarized here — see each pass's original report (this file's own
git history / the session transcript) for the complete tables covering:
VISION.md, ROADMAP-platform-evolution.md, ROADMAP-safety-and-metrics.md,
ROADMAP-lms-v3.md, ROADMAP-lms-instructor-student-depth.md,
LMS-AUTHORING-DESIGN-PLAN.md, ROADMAP-ux-polish-and-feature-parity-2026-07.md,
ROADMAP-federated-metrics.md, EVENT-TRACKING-ARCHITECTURE-PLAN.md,
ROADMAP-guided-setup-wizards.md, ROADMAP-obs-integration.md,
ROADMAP-streaming-media-scope-and-blockchain.md,
DESIGN-SUITE-UNIFICATION-PLAN.md, ELEMENT-BUILDER-DESIGN-PLAN.md,
ROADMAP-hyperpress-migration.md.

Not yet covered by any research pass, flagged for a follow-up read:
`ROADMAP-discoverability.md`, `ROADMAP-search-and-revisions.md` — both
referenced in code comments as superseding sections of VISION.md /
ROADMAP-platform-evolution.md audited here, neither read directly.
