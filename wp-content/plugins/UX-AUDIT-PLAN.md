# UX simulation audit — plan

**Written 2026-08-23.** A step-by-step plan for walking every screen this ecosystem owns as a *user would*, at every breakpoint, in both themes — and refactoring what's hostile into something human.

Companions: `DESIGN-CRAFT.md` (what "good" means here), `OPEN.md` (the backlog this feeds), `TESTS.md` (the four gates). The measured method — and the theme-toggle trap that fabricates findings — is in the admin-skin audit-method memory. **Read that before measuring anything.**

## Scope: the real inventory

**Admin pages (23).** `ous`, `ous-debug`, `ous-api-docs`, `ous-campaigns`, `ous-codebase-docs`, `ous-dmca-agent`, `ous-dmca-notices`, `ous-media-setup`, `ous-metrics`, `ous-portal-layout`, `ous-roles`, `ous-security`, `ous-setup-wizard`, `bh-contest-wizard`, `bh-crm-hub`, `bh-design`, `bh-studio`, `bh-style`, `bh-social`, `bh-tickets`, `bhl-obs-automation`, `bhs-isrc-registrant`, `bhs-pro-wizard`.

**CPT screens (12 types × list + edit = ~24).** `bh_contest`, `bh_submission`, `bh_course`, `bh_lesson`, `bh_feedback_request`, `bhl_stream`, `bhs_track`, `bhs_release`, `bhs_playlist`, `bhs_video`, `bhs_feed_source`, `bhv_video`.

**Front-end surfaces (22 shortcodes).** `bh_archive`, `bh_contest_player`, `bh_course`, `bh_courses`, `bh_dmca_notice`, `bh_judge_panel`, `bh_live`, `bh_notifications`, `bh_profile`, `bh_profile_link`, `bh_registry`, `bh_results_reveal`, `bh_streaming`, `bh_video`, `bhf_submit`, `bhm_buy`, `bhm_redeem_gift`, `bhm_tiers`, `bhm_tip_jar`, `bhm_verify_purchase`, `bhm_wallet`, `ous_search`.

Roughly **70 distinct screens**. Budget ~4 measurements per screen per theme; this is several sessions, not one.

## The matrix

Six widths × two themes, per screen: **1440 / 1280 / 1024 / 961 / 782 / 375**.

- 961 and 782 are the real WordPress breakpoints (sidebar auto-fold; admin bar switches to its 46px touch layout).
- **Reload per theme. Never toggle `data-shsas-theme` and re-read in the same task** — `var()` references don't re-resolve, which once produced 39 contrast failures that did not exist.

## What to measure (not eyeball)

Per screen, per theme, per width:

1. **Contrast** — composite translucent backgrounds properly; base fallback is `body`, not white (`:root` is transparent in this skin). Fail: <4.5 normal, <3 large.
2. **Clipping** — `scrollHeight > clientHeight` on non-scrollable boxes. This is the one an eyeball pass missed while a visibly sheared screen "passed."
3. **Horizontal overflow** — `documentElement.scrollWidth > innerWidth`.
4. **Overlap** — bounding-rect intersection of siblings that shouldn't touch.
5. **Touch targets** — <44×44px interactive elements at ≤782.
6. **Focus visibility** — tab through; every focusable element needs a visible ring. Front-end coverage is thin (6 files / 14 plugins), so expect failures.

**Rule out before reporting.** `.screen-reader-text` and anything clipped to ~1×1px; Query Monitor's chrome; `text-indent: 100%` + `overflow:hidden` (WP core's own icon-only mechanism at ≤782); `text-overflow: ellipsis` + `nowrap` (deliberate truncation, not clipping); WooCommerce React widgets mid-hydration (they report wrong colors for a frame, then settle).

**To decide "our bug or WP core's":** measure, set `link.disabled = true` on `admin-skin.css` + `admin-bar.css`, re-measure, re-enable. Identical geometry means it's core's behavior, not ours.

## Human-friendliness, beyond measurement

Numbers catch defects; they don't catch *hostility*. On every screen also ask:

- **Can a non-expert tell what this screen is for in five seconds?** If the h1 and first paragraph don't answer it, that's a finding.
- **Is the primary action obvious and singular?** Multiple competing primary buttons = none.
- **What does it look like with nothing in it?** Empty states exist (`BHY_UI::empty_state_html()`, 19 uses) but coverage is uneven. A first-run screen showing an empty table with no guidance is a defect.
- **What does an error look like?** Front-end has **no alert component at all** (`.bh-alert` = 0 uses ecosystem-wide) — so every front-end error is hand-rolled. Expect inconsistency.
- **Is anything irreversible without confirmation?** Deletes, revokes, refunds, publish/reveal.
- **Does the wording assume expertise?** VISION's "it just works" standard: plain-language questions, never raw API vocabulary ("Where should files live?" not "S3 Bucket Region").

## Step-by-step procedure

**Phase 0 — harness.** Rebuild the measured audit script (contrast/clipping/overflow/overlap + the exclusion list). Serve it from `wp-content/uploads/` and load via sync XHR so each call stays small. **Delete it from `uploads/` when done** — it must not ship.

**Phase 1 — admin, logged in as admin.** Highest value, fully doable now. Order by blast radius:
1. `ous` dashboard, `ous-debug`, `ous-metrics` (daily-use screens)
2. `ous-setup-wizard`, `ous-media-setup`, `bh-contest-wizard`, `bhs-pro-wizard` (the "it just works" wizards — hold these to the TurboTax bar)
3. `bh-style`, `bh-design`, `bh-studio` (design surfaces — must exemplify the system)
4. `ous-roles`, `ous-security`, `ous-dmca-*`, `ous-campaigns`, `ous-portal-layout`, `ous-api-docs`, `ous-codebase-docs`
5. `bh-crm-hub`, `bh-tickets`, `bh-social`, `bhl-obs-automation`, `bhs-isrc-registrant`
6. CPT list + edit for all 12 types (the quiz editor and lesson builder are the known-dense ones)

**Phase 2 — front end, logged in.** All 22 shortcodes on a scratch page, plus CPT archives/singles, search, and 404. This is where the biggest gap is (`DESIGN-CRAFT.md` §2).

**Phase 3 — fixes.** Batch by root cause, not by screen — one token or shared-component fix usually clears many findings at once (the `#f6f7f7` and periwinkle-accent bugs each spanned several screens). Re-measure after each batch. Gate 4 (`curl` the site) after any structural change.

## Edge cases to construct deliberately

Real content, not lorem. Create these fixtures once and reuse:

- **Long values** — a 120-char course title, a 40-char single word (no spaces), an artist name with emoji/CJK/RTL, a 500-word lesson description.
- **Empty/zero** — course with 0 lessons, contest with 0 entries, wallet at $0.00, CRM person with no activity, 0 search results, empty notification list.
- **Extremes** — 200-entry contest, 50-lesson course, quiz with 30 questions, 1000-item registry list (pagination + `.bhy-table-wrap` scroll).
- **Missing optional content** — course with no cover image, track with no artwork, profile with no avatar/bio, video with no poster.
- **Numeric** — price of $0, $0.01, $9,999,999; 0% and 100% progress; negative wallet (if reachable).
- **State** — locked/drip-gated lesson, tier-gated track as a non-subscriber, claimed vs open feedback request, closed contest, expired entitlement, paused subscription.
- **Failure** — network error mid-submit, expired nonce, insufficient wallet balance, upload too large, wrong file type.

## Happy and unhappy paths

For each interactive flow, walk **both**:

| Flow | Happy | Unhappy to force |
|---|---|---|
| Feedback submit | upload → charge → request created | insufficient balance; bad file type; `wp_insert_post` fails (charge must reverse — code does this, verify the *message* is human) |
| Course enroll → complete | enroll, finish, certificate | drip-locked lesson; tier-gated without tier; quiz failed; resume mid-course |
| Contest vote | vote, see result | already voted; voting closed; not logged in; results not yet revealed |
| Purchase | buy → entitlement → download | payment declined; refund → entitlement revoked; download outside approved dir |
| Gift redeem | valid code | already redeemed; expired; malformed |
| Ticket | create → reply → resolve | reply to closed; not your ticket |
| Registry submit | submit → verify → listed | verification token mismatch; unreachable peer; duplicate |
| Wizards | complete run | back-navigation mid-wizard; abandon and return; invalid credential (must fail *at that step*, per VISION) |

For every unhappy path the question is not "does it error" but **"does a non-expert know what to do next?"**

## Flag for a session that can log in/out

I can't log out (I don't handle credentials, and logging out would end the session's access). These need a separate pass:

- **Logged-out front end** — every shortcode and archive as an anonymous visitor. This is what fans actually see, so it matters most.
- **Login / register / lost-password / email-verify screens** (`class-auth.php`) — styled by the skin, unverified.
- **Role-based views** — subscriber, contributor, judge (`bh_judge_panel`), reviewer (feedback queue), instructor. Capability-gated UI differs per role.
- **`OUS_Visibility` gating** — a course marked non-public, viewed logged-out.
- **Portal** (`class-portal.php`, `render_shell`, 418 lines) as a non-admin member.
- **2FA enrollment + challenge** flow.
- **First-run** — a genuinely fresh install with nothing configured.

Hand these over with: the same six widths, both themes, the same measured checks, and the edge-case fixtures above.

## Definition of done, per screen

1. Zero contrast failures below AA (or a recorded, justified exception).
2. Zero clipping, zero horizontal overflow, at all six widths, both themes.
3. Every interactive element has a visible focus state and a ≥44px touch target at ≤782.
4. Empty, long-content, and error states all render deliberately.
5. Screenshot captured in both themes at 1440 and 375.
6. Findings that survive verification logged in `OPEN.md` with measured before/after.

## Known traps

- Measure, don't eyeball — a visibly broken screen once passed an eyeball audit.
- Reload per theme; never toggle.
- Verify a finding is real before reporting: check the ancestor chain, and disable the skin to test whether it's core's behavior.
- Fix at the source — an inline `style="background:#fff"` in PHP can't be reached by any stylesheet; migrate it to `var(--bhy-surface, #fff)` rather than adding a second override.
- Don't batch-fix by screen when the cause is one token.
