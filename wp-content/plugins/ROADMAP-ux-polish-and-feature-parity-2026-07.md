# ROADMAP: UX polish & feature parity — contests, CRM, LMS, monetization

Written 2026-07-13, off the back of a same-day four-plugin research pass (parallel deep-dive against real competitor products/plugins for each of bh-contest, bh-crm, bh-courses, bh-monetization-woo) plus direct refinement answers from the project owner. This doc is the synthesis + decision record + build order — the four source research reports themselves aren't preserved as separate files (they lived in the session, not disk); this doc captures everything from them worth keeping.

**Standing priority still applies:** harden core before extending (see CLAUDE.md/VISION.md). Everything below is real, wanted feature work, but it's still "next," not "now" relative to any open core-hardening item. Nothing in this doc should be started without a fresh check that no higher-priority core fragility has surfaced since this was written.

**The one governing decision this whole pass surfaced:** the project owner's answer to both the contest-format question and the LMS-priority question was "give me all of it, and make it a real choice, not a house style I've imposed." Concretely: contests need a genuine per-contest *format* configuration (not a single hardcoded voting model), and LMS features (certificate/video-progress/resources/comments) should be built as things a course creator can turn on or off per course/lesson, not blanket ecosystem-wide behavior. This reframes several of the items below from "add a feature" to "add a feature AND make it configurable," which is real, deliberate scope — don't quietly simplify it back down to a single fixed behavior later.

---

## 1. Cross-cutting findings (apply to more than one plugin)

- **Unused extension points already exist in two places.** `bhc_course_completed` (bh-courses, `class-progress.php`) and `bhm_entitlement_granted`/`bhm_entitlement_revoked` (bh-monetization-woo, `class-products.php`) both fire today with zero listeners. Before building new instrumentation anywhere else in this ecosystem, check for an unused hook first — this is now the second time in one week a "missing feature" turned out to already have its wiring half-built.
- **SortableJS (MIT, actively maintained) is worth adopting now, no further discussion needed.** bh-crm's kanban board (`kanban-board.js`) hand-rolls HTML5 drag-and-drop with a header comment admitting it's an untested judgment call; bh-courses' newly-real Gutenberg quiz-question blocks want the same "reorder N items" UX. One small, permissively-licensed, well-maintained library solves both, removing a real cross-browser/touch-device bug surface. Do this as a standalone low-risk task, not gated on anything else in this doc.
- **VISION.md has a stale claim** — it says bh-crm doesn't read `bhcore_events` yet; `class-event-activity.php` already does. Fix as a docs-only correction whenever convenient, unrelated to any build work here.
- **The "recognition wall" pattern is legitimate, reusable UI** — GiveWP's `give_donor_wall` block (GPLv2, real WordPress.org code, actively maintained) is worth a 30-minute read before hand-rolling bh-monetization-woo's own donor wall. This is display-pattern borrowing only — GiveWP's actual donation-processing core is a parallel commerce stack to WooCommerce and must never be adopted; see the "not a fit" list below.
- **LifterLMS's open-source core** (`gocodebox/lifterlms`, GPLv2+) is the one genuinely deep reference from this whole pass. Not for code reuse (its post-type/class hierarchy is incompatible with `_bhc_steps`/`bhc_progress`), but its **Achievements/Engagements rules-engine** ("when X happens, award Y") is exactly the shape `bhc_course_completed` is already half-built toward, and its **Course Builder** section/lesson/quiz tree is a real reference for the drag-reorder authoring UI bh-courses now needs. Worth an actual study pass before building the certificate/achievement system in Section 4.
- **The "Lite LMS Progress Tracker" plugin researched last session is a confirmed dead end**, not just a low priority — read its actual source this pass: it stores completion in `localStorage` only, no server state, no login requirement, last released in Nov 2022. `bh-courses`' own `bhc_progress` table is already strictly more capable. Nothing to revisit here.

---

## 2. Contests (bh-contest) — configurable format system

**Decision (from direct refinement): build toward supporting ALL of these as real, per-contest configuration options** — public voting (existing), judge/rubric scoring, a hybrid of both, and multi-round/elimination structure. Not one new fixed model bolted onto the current one — a genuine format choice made per contest at setup time.

### 2a. Judge/rubric scoring mode
- New concept: a **judge role** (capability-gated, likely a dedicated `bh_contest_judge` capability or a per-contest judge list, not full `manage_options`), a **rubric** (admin-defined list of criteria, e.g. "Originality," "Production," each 1–10 or similar), and **per-judge, per-entry, per-criterion scores**, averaged/normalized across judges (Devpost's pattern: judge-blind-to-other-judges'-scores, admin sees the aggregate).
- Storage: a new table (mirroring the existing votes table's transactional/row-locking discipline — `class-api.php`'s existing `FOR UPDATE` pattern is the right precedent to copy, not reinvent) rather than overloading the votes table, since judge scores and public votes are genuinely different shapes (a public vote is binary per category; a judge score is multi-criterion and needs an editable draft-then-submit state, per Devpost's "save progress" pattern).
- Contest configuration needs a new field: **format = public | judges | hybrid**. In hybrid mode, results reveal (`class-reveal.php`) needs to either show two separate leaderboards (Judges' Pick / People's Choice) or a blended score — this is a real design decision to make at build time, not defer; recommend defaulting to **two separate leaderboards** (matches Devpost's own hybrid convention and avoids inventing a blending formula that will need tuning per contest).
- `class-admin.php` needs a judge-scoring UI distinct from the existing public-facing voting UI — likely its own capability-gated screen, reusing `class-reveal.php`'s existing admin-controller pattern (a private, logged-in-only view) as the closest existing precedent.

### 2b. Multi-round / elimination format
- Real architecture change: today's model is submit-once → vote-once-per-category → reveal-once. An elimination format needs **rounds** as a first-class concept (a contest has 1+ rounds, each round has its own submission/voting window and its own entrant list, where round N's entrants are the survivors of round N-1).
- Recommend: a contest gets a `rounds` config (array of round definitions: name, submission window, voting window, cut count/percentage), and entries carry a `current_round` field. `BH_Helpers`' existing status-computation logic (`contest_status()`/`submission_status()`) needs to become round-aware rather than contest-wide.
- The reveal system already has the right shape for this (`class-reveal.php`'s tiered, sequenced reveal) — extending it to reveal "who advances to round 2" as its own reveal event, before the final round's reveal, is a natural fit rather than a rebuild.
- **Sequencing note:** build judge scoring before multi-round, not simultaneously — judge scoring is usable standalone (a single-round judged contest is a complete, shippable feature on its own), while multi-round is more valuable once judge scoring already exists (a common real pattern is "judges cut the field, fans vote on the finalists"). Building in this order also means round-aware code can be written knowing the judge-scoring data shape already exists, rather than guessing at both simultaneously.

### 2c. Anti-fraud: in-house IP+cookie signal (decision: yes, no CAPTCHA vendor)
- Extend the existing votes table (or a lightweight companion table) to capture IP address + a first-party cookie/fingerprint alongside the vote, purely as an additional signal for `BH_Helpers::suspicious_voters()` — same manual-review posture as today, just a stronger signal (multiple accounts from one IP in a short window, alongside the existing timestamp-clustering check), never automated blocking.
- Explicitly **not** adding a third-party CAPTCHA (Turnstile or otherwise) — direct decision from refinement. If real bot pressure appears later, revisit as its own deliberate call, not a default.
- IP capture needs a privacy note in whatever privacy policy this install already has (WordPress's own privacy-export/erasure tooling should cover it if IPs are stored in a standard postmeta/table shape) — flag this as a real compliance detail to close out during implementation, not an afterthought.

---

## 3. CRM (bh-crm) — near-term feature depth

Not covered by a direct refinement question this pass, but the research report's findings are concrete and low-risk enough to include here as a recommended (not yet confirmed) near-term slate. Flag disagreement if any of this isn't wanted.

- **Tag chips + autocomplete-from-existing-tags** in the person editor, replacing the current plain comma-separated text input. Contained front-end change, no schema change (still a meta-array-of-strings underneath). ✅ done (1.5.0) — progressive enhancement over the unchanged plain-text field, runtime-verified end to end.
- **Saved smart lists/segments** — the single clearest real feature gap: today, filtering is "one tag at a time via a URL query arg," with no saved multi-condition segment ("tagged X AND registered in last 30 days AND has an active project"). Groundhogg's (GPLv2, open-source) segment-builder UX is worth reading as a reference implementation before designing this from scratch. ✅ done (1.7.0) — flat AND-only conditions (deliberately not Groundhogg's full nested AND/OR tree, a real scoping choice), 4 condition types, saved as clickable pills. Runtime-verified end to end including a full live-browser click-through.
- **Notes: timestamped history + authorship + reminders**, replacing the current single-overwrite freeform textarea. This is the CRM's actual daily-use loop in every reference product (Pipedrive/HubSpot) and is currently the thinnest part of bh-crm. ✅ done (1.4.0) — new bhcrm_notes table, reminders via OUS_Jobs + OUS_Notifications, legacy notes auto-migrated. Runtime-verified end to end.
- **Bulk actions on the person list** (bulk tag, bulk export-selected) — currently all-or-nothing. ✅ done (1.6.0) — checkboxes + select-all, bulk tag ADDS not replaces, scoped export intersects against the legitimate CRM id set. Server-side/handler-level runtime-verified; live-browser click-through not completed this pass (tool was unavailable), flagged honestly in the changelog.
- **SortableJS swap for the kanban board** — see cross-cutting findings above; do this regardless of anything else in this section.

Deliberately **not** recommending: WP Fusion or any CRM-to-SaaS bridge (direct contradiction of bh-crm's reason for existing), or adopting Groundhogg/any full competing CRM plugin wholesale (reading its segment-builder code for reference is fine; running it as infrastructure is not).

---

## 3a. New domain, not yet scoped: user issue/support ticket system

AJ ask (2026-07-14): a support/help-desk ticket system for site users — distinct from Section 6a's event ticketing (that's admission tickets to a venue/event; this is "a user reports a problem, someone resolves it," the support-desk sense of "ticket"). Also distinct from bh-contest's own submission/moderation queue (that's contest-entry review, not general user support).

**Not scoped in detail yet** — needs its own refinement pass before real build-order placement. Open questions worth asking AJ directly when this comes up for real:
- Scope: pure bug/support reports from logged-in users, or does this fold in the "report" flows own-ur-shit's Reports & Moderation queue already has for other content types? Worth reading `class-reports.php` (or wherever that queue lives) first — the exact same "check for an unused/adjacent extension point before building new infrastructure" principle Section 1's cross-cutting findings already called out once this pass.
- Who resolves tickets — AJ alone, or does this need a lightweight assignable-agent/role concept (relevant once/if this ecosystem ever has more than one admin)?
- Status lifecycle: open/in-progress/resolved is the obvious minimum; worth deciding up front whether a "waiting on user reply" state matters (it usually does, in every real help-desk reference product).
- Notification path: reuses `OUS_Notifications`/`OUS_Toast` (already-shared, ecosystem-wide) rather than inventing a new one — should be a given, not an open question, but stated here so it isn't missed.
- Where tickets live: a real CPT (consistent with `bh_contest`/`bh_submission`/`bh_lesson` etc.) is almost certainly right, not a bespoke table — matches this ecosystem's own established convention of "a table only when the shape genuinely doesn't fit a post," per multiple existing plugins' own docblocks.
- Attachments (screenshots, log files) — likely yes, given how most real bug reports actually get filed; needs deciding if that's v1 or a fast-follow.

---

## 4. LMS (bh-courses) — all four, built as course-creator-configurable

**Decision (from direct refinement): build all four** — certificate of completion, real video progress tracking, downloadable resources per step, and comments/Q&A per lesson — **with course-creator control over each**, not a single ecosystem-wide switch.

### 4a. Certificate of completion
- The hook already exists (`bhc_course_completed`, fires exactly once per user/course). Build a certificate generator as a listener on this hook, not new instrumentation.
- Course-creator configurability: a course-level setting for whether a certificate is offered at all, and likely a simple template (course title, student name, completion date, maybe an instructor signature image — matching the attachment-ID pattern already used elsewhere in this plugin for images).
- Format: a self-hosted PDF generation approach (a lightweight, dependency-light PHP PDF library, not a hosted certificate-generation API) — matches the no-vendor-lock-in mission directly. Research this specific library choice at implementation time rather than picking one now; several small, permissively-licensed pure-PHP PDF libraries exist and the right one depends on exactly how much layout control the eventual template design needs.
- **Study LifterLMS's Achievements/Engagements module first** (per cross-cutting findings) — even though this pass only asks for a certificate, the rules-engine shape ("on course_completed, do X") is general enough that building it as a small extensible engine (rather than a single hardcoded certificate action) would make a future achievements/badges feature (if ever wanted) a natural extension rather than a rebuild. Don't over-engineer this now — just don't paint the implementation into a certificate-only corner.

### 4b. Real video progress tracking
- Today a video step completes via the same generic "Mark complete" button as a text step — no actual watch-position tracking.
- Needs: a `timeupdate` JS listener on the existing `<video>` element, a new field (percent watched, or last position in seconds) alongside the existing step-completion record, and — since flexibility is the theme — a **course-creator-configurable completion threshold** (e.g., "require 90% watched" vs. "any playback marks it complete," per course or per video step).
- Scoped correctly as additive: this doesn't touch the existing quiz/text/image step types or the drip/gating logic at all, purely extends the video step's own completion condition.

### 4c. Downloadable resources per step
- Smallest, most contained item in this whole doc: a 5th step type (`resource`: attachment ID + label + optional description), following the exact same pattern `class-steps.php`'s existing step-type registry already uses for image/video. No progress/gating logic changes needed — a resource step likely just needs a "mark as viewed" or is simply non-blocking (a course creator's choice — configurability point: should a resource step require acknowledgment to advance, or just always be available? Recommend defaulting to "always available, doesn't block advancement" unless a specific course wants otherwise).

### 4d. Comments/Q&A per lesson
- WordPress's native comments API is unused on `bh_lesson` (`'supports'` doesn't include `'comments'` — a one-line addition, same one-line pattern used earlier this session to add `'editor'` support).
- Configurability point (real decision, not just a technical toggle): comments are public-by-default in WordPress unless moderated — a course creator needs a clear per-course (or global default + per-course override) choice for whether lesson comments are open, since an unmoderated public comment thread under paywalled course content is a real, different product decision than a private Q&A only enrolled students can see. **Recommend building the "enrolled-students-only" visibility gate as part of this from day one** (reusing `BHC_Gate`'s existing tier-access check to decide who can even SEE the comment thread, not just who can post) — building open-to-everyone first and retrofitting privacy later is the wrong order here.

---

## 5. Monetization (bh-monetization-woo) — all three confirmed

**Decision (from direct refinement): build all three now** — subscription pause, a public donor/supporter wall, and pay-what-you-want purchases.

### 5a. Subscription pause
- **This is arguably a real, pre-existing bug, not just a missing feature**: WooCommerce Subscriptions natively supports an on-hold/pause status, but `class-products.php`'s `on_subscription_ended()` only listens for `cancelled`/`expired` — meaning a paused subscription's entitlement is likely never revoked today. Fix this as step one (add a `woocommerce_subscription_status_on-hold` listener alongside the existing status hooks) before adding any new pause-initiating UI, since the entitlement-handling gap is the more urgent half of this.
- Then add the fan-facing pause button/flow itself (`class-frontend.php`), using WC Subscriptions' native pause capability — no new library, this is wiring an existing dependency's existing feature.

### 5b. Public donor/supporter wall
- Read GiveWP's `give_donor_wall` block source first (per cross-cutting findings) as a UI/UX reference — opt-in, anonymous-respecting, recent-N display, note support are all real, sound defaults worth copying the *shape* of.
- Build natively against this plugin's own tier/purchase data (`BHM_Tiers`/`BHM_Products`), not GiveWP's donation records — this is a display feature over existing data, not a data-model change. Needs an opt-in flag (per-supporter, likely a profile-level checkbox via the shared `BHI_Profiles` mechanism other opt-in preferences probably already use) — never default supporters into public display without consent.

### 5c. Pay-what-you-want purchases
- No new plumbing needed: the tip jar's existing `apply_tip_price`/`apply_tip_amount` cart-item-price-override pattern (`class-frontend.php`) is directly reusable against `class-products.php`'s purchase products. This is the cheapest item in this entire doc relative to its value — the hard part (variable-price cart items in WooCommerce) is already solved and battle-tested by the tip jar.
- Needs: a per-product toggle (fixed price vs. pay-what-you-want, optionally with a minimum) in the product/purchase admin UI, mirroring the tip jar's existing `TIP_MIN_CENTS` floor concept.

---

## 5a. WYSIWYG: shortcodes → real blocks with `ServerSideRender`

Direct AJ follow-up after seeing `[bhm_buy id="109"]` render as raw bracket text in the real post editor (5c's own shortcode): "how plausible is that shortcode preview as a feature... as much as we can make the page builder wysiwyg with stuff like that along with Gutenberg the better."

**The mechanism: WordPress core's own `wp.serverSideRender` (`@wordpress/server-side-render`)**, available as a plain global (`wp.serverSideRender.ServerSideRender`) — no npm, no build step, same convention every other block in this ecosystem already follows. A block's `edit()` becomes `el(ServerSideRender, { block: 'bhm/buy', attributes })`, which calls a REST endpoint that runs the block's real PHP render (or `do_shortcode()`) and drops the actual rendered HTML into the editor canvas. Not a visual mimic — it IS the front-end output, live, so there's no second render path to drift out of sync with what ships. Strictly better than a generic "shortcode preview" plugin, and it's the same architectural move as this session's `bhc/*` migration (real Gutenberg blocks instead of an opaque placeholder), just applied to the fan-facing surfaces that are still classic shortcodes.

**Real, worth-knowing tradeoff:** each attribute/content change triggers a debounced REST round-trip to re-render (a few hundred ms lag), not an instant client-side update. Fine for something edited rarely (a tier grid, a buy button); less pleasant for anything tweaked rapidly attribute-by-attribute. Not a blocker, just set expectations correctly before starting.

**Scope — every classic `[shortcode]` still in the ecosystem, each becomes its own small block wrapper:**
- `bh-monetization-woo`: `bhm_buy`, `bhm_tip_jar`, `bhm_tiers`, `bhm_wallet`
- `bh-contest`: `bh_contest_player`, plus whatever the results/reveal/archive shortcodes turn out to be (audit at implementation time, not guessed here)
- `bh-streaming`: `bh_streaming`
- `bh-courses`: the `[bh_courses]` catalog shortcode

This is real, multi-plugin surface area, not a quick patch — treat it as its own build-order item, not folded into 5c. Sequenced in the build order below as its own step; `bhm_buy` is the natural first block to convert (freshest in context, proves the pattern cheaply before expanding to the rest).

---

## 6. Suggested build order (dependency- and risk-aware, not a strict priority ranking)

1. **SortableJS swap** (bh-crm kanban + sets up bh-courses' quiz-question reordering) — standalone, low-risk, do first. ✅ done.
2. **Monetization: subscription-pause entitlement fix** (5a, the bug-fix half) — small, isolated, closes a real correctness gap. ✅ done.
3. **Monetization: pay-what-you-want purchases** (5c) — cheapest high-value item, reuses proven code. ✅ done.
4. **LMS: downloadable resources per step** (4c) — smallest LMS item, no architecture risk, builds confidence before the bigger LMS items. ✅ done.
5. **LMS: comments/Q&A with enrolled-only gating** (4d) — contained, but do the privacy-gate design carefully per the note above. ✅ done.
6. **Monetization: subscription-pause fan-facing UI** (5a, the feature half) — now that the entitlement bug is fixed underneath it. ✅ done.
7. **LMS: certificate of completion** (4a) — do the LifterLMS Achievements study first; this is the first item where under-scoping the design would cost real rework later. ✅ done — studied LifterLMS's Achievements/Engagements module first (confirmed the right shape is WordPress's own `bhc_course_completed` action hook, no bespoke registry class needed), vendored FPDF for on-demand PDF rendering (bh-courses/includes/class-certificates.php), runtime-verified a real PDF download end to end.
8. **LMS: video progress tracking** (4b) — independent of the above, can be parallelized with 7 if capacity allows. ✅ done — per-video-step configurable "require N% watched" threshold (0 = unchanged manual-click behavior), enforced only for directly-trackable `<video>` playback (not cross-origin iframe embeds), runtime-verified end to end including a real regression bug caught and fixed along the way (see bh-courses 0.4.14's changelog).
9. **Monetization: donor/supporter wall** (5b) — read GiveWP reference first; independent of everything above.
10. **WYSIWYG shortcode-to-block conversion** (5a. above) — start with `bhm_buy` to prove the pattern, then expand to the rest of the list; genuinely independent of everything else in this doc, slot in whenever capacity allows rather than strictly after #9. ✅ done (except `bhm_wallet`, deliberately deferred). bh-monetization-woo: `bhm_buy` (0.4.9), `bhm_tip_jar` (0.4.10), `bhm_tiers` (0.4.11). bh-contest: `bh_contest_player`, `bh_results_reveal`, `bh_archive` (3.5.0) — caught and fixed a real regression along the way (the front-end asset-enqueue gate only checked `has_shortcode()`; a block-authored page would have rendered an inert, never-hydrated container without a `has_block()` fix). bh-streaming: `bh_streaming` → `bhs/player` (0.5.4) — same `has_block()` fix applied preemptively. bh-courses: `bh_courses` → `bhc/catalog`, `bh_course` → `bhc/course` (0.4.15) — same fix applied preemptively a third time; these two blocks render real, complete server-side HTML rather than a JS-hydrated mount div. All via `wp.serverSideRender`, all runtime-verified end to end including live-browser hydration/render checks. `bhm_wallet` remains deliberately deferred — it's a logged-in-user-specific view, needs a decision on what the editor-canvas preview should show for a non-logged-in author before converting.

**Unplanned but done this pass: a real, confirmed WordPress bug swept across the ecosystem.** Building `bhm_buy`'s block surfaced a genuine bug — a class's own `init()` method (itself only ever invoked AS an `init` hook callback) internally registering a SECOND `add_action('init', ...)` of its own never fires that inner callback, ever, in the same request (WP_Hook doesn't revisit an already-passed priority bucket) — confirmed directly against a minimal WP_Hook reproduction, not assumed. A background audit found this pattern **9 times total** across own-ur-shit/bh-monetization-woo (bh-courses' one candidate turned out to already be correct via a priority-20 deferral trick, verified). All 9 fixed and runtime-verified: `own-ur-shit` — `OUS_Jobs` (Action Scheduler bootstrap AND cron-scheduling check were both dead), `OUS_Notifications` (`[bh_notifications]` shortcode AND the queued-email job handler were both dead), `BH_Event` (event-ingest job handler dead), `BH_Identity` (a guest's first-touch identity cookie was never issued), `OUS_Toast` (a guest's queued toast never persisted to their next request), `OUS_Gutenberg_Block` (currently inert for an unrelated reason, fixed regardless); `bh-monetization-woo` — `BHM_Storefront` (a taxonomy and its rewrite rule never registered). Fixing the first one (`OUS_Jobs`) surfaced a SECOND real bug: turning on genuinely-dead Action Scheduler bootstrap code collided with WooCommerce's own bundled copy of the same library (fatal), which itself needed a `plugins_loaded`-timing fix once the naive `class_exists()` guard proved too early (own-ur-shit's main file loads before woocommerce's, alphabetically). See own-ur-shit 3.4.85's and bh-monetization-woo 0.4.9's own changelogs for full detail.
11. **CRM: tag chips, saved smart lists, notes/reminders, bulk actions** (Section 3) — bundle as one CRM depth pass once the higher-priority confirmed items above are done. ✅ done — all four shipped (bh-crm 1.4.0-1.7.0), all runtime-verified, this item is complete.
12. **Contests: judge/rubric scoring mode** (2a) — the larger of the two contest format additions; do before multi-round per the reasoning in 2c. ✅ done — per-contest format (public/judges/hybrid), admin-defined rubric, per-contest judge list, new bh_judge_scores table, front-end [bh_judge_panel] scoring UI, and Reveal Party integration (hybrid shows two separate leaderboards, not a blended score). Runtime-verified end to end. Known scoping boundary: Discord announcements and player.js's results widget don't yet render judge/hybrid leaderboards — see bh-contest 3.3.0's changelog.
13. **Contests: in-house IP+cookie fraud signal** (2c) — can be done any time, independent of the format work, low complexity. ✅ done — bh_votes gained ip_address/voter_fp, new suspicious_ip_clusters() surfaced on the Results console, manual-review-only (no blocking, no CAPTCHA vendor). Runtime-verified end to end.
14. **Contests: multi-round/elimination format** (2b) — the single largest architectural item in this whole doc; do last, and only once judge scoring's data shape exists to build against. ✅ done — per-contest rounds config (1-4, sub/vote windows + cut count each), bh_votes/bh_judge_scores gained a `round` column so each round tallies independently, admin-triggered round advancement, Reveal Party reveals the active round's own tally. Runtime-verified end to end, including a real dbDelta migration bug caught and fixed live (see bh-contest 3.4.0's changelog). Same player.js scoping boundary as items 12/13.

Also done this pass, not originally in this list: the shared `BHY_Style::empty_state_html()` component (UX-AUDIT-2026-07.md's own top recommendation), retrofit onto bh-courses' catalog and bh-streaming's library, verified on desktop and mobile.

---

## 6a. New domain, not yet scoped: ticketing for venues and events

AJ ask (2026-07-14): "Roadmap show ticketing system for venues and events." Recorded here as a real future item, not folded into any existing plugin's scope — this is genuinely new ground, not an extension of bh-contest (music contests, a different domain) or bh-monetization-woo's tiers/purchases (recurring/one-off digital access, not a dated physical/virtual event with a fixed capacity).

**Not scoped in detail yet** — needs its own refinement pass (like Section 2's contest-format and Section 4's LMS questions got) before real build-order placement. Open questions worth asking AJ directly when this comes up for real:
- New plugin (`bh-events`?) vs. a module inside an existing one — probably its own plugin, given it's a distinct content type (events/venues) with its own lifecycle (on-sale date, event date, capacity, check-in), not a natural extension of contests or monetization tiers.
- Physical check-in at a real venue door needs a real mechanism — a scannable QR/barcode ticket + a simple scanner view (even just a phone camera + a REST lookup) is the standard shape; needs deciding whether that's in scope for v1 or a v2 follow-up.
- Capacity/sold-out handling: hard cap per ticket tier, waitlist, or both.
- Refunds/exchanges: how much of WooCommerce's own order/refund flow to lean on directly (same "wrap it, don't reinvent" principle bh-monetization-woo already established for payments) vs. what's genuinely ticketing-specific.
- Multi-venue/multi-date (a tour) vs. single-event — affects the data model from day one, worth deciding before any schema gets written.
- Whether this connects to bh-crm (a ticket buyer becoming a CRM contact, same pattern bh-contest/bh-monetization-woo already follow) — almost certainly yes, but worth confirming the exact touchpoint (on purchase? on check-in?).

**Likely reused from existing ecosystem work, not reinvented:** WooCommerce for the actual purchase/payment flow (same wrap-don't-reinvent posture as tiers/purchases), `OUS_Jobs` for any check-in-reminder emails, `BHY_Style`/`BH_Content` for front-end rendering and any block-based ticket-purchase widget (built as a real Gutenberg block with `ServerSideRender` from day one, per Section 5a's now-proven pattern, rather than a shortcode that gets converted later).

---

## 7. Deep future / speculative (explicitly NOT now)

- **Full achievements/badge system beyond a single certificate** — worth building only if the certificate work (4a) is designed as a small extensible engine per its own note; not worth scoping further until that's real.
- **Interactive video overlays, branching lesson paths, mind-map authoring** (`ROADMAP-lms-v3.md`) — already correctly flagged in that doc as needing a dedicated design pass and/or a data-model rework `BHC_Progress`'s linear-index shape can't currently support. Don't let this doc's momentum pull those forward; they're honestly scoped as later.
- **Gamification/points/leaderboards** — explicitly declined. Both the research and this project's own tone argue a competitive public leaderboard is a mismatch for a solo musician's calm, self-paced teaching space. Simple milestone badges (if the achievements engine above ever gets built) are fine; ranking students against each other is not being pursued.
- **A blended (non-two-leaderboard) judge+public scoring formula for contests** — deliberately deferred; ship two separate leaderboards first (2a) and only revisit blending if a specific contest genuinely needs one combined ranking.
- **Any hosted fraud-scoring API, third-party CAPTCHA, or cloud certificate-generation service** — consistently flagged as the wrong shape for this ecosystem's self-hosted mission across all four research passes. If any of these ever get proposed again later, that's a sign to re-read this doc, not a sign the mission changed.

---

## 8. Libraries/plugins — the definitive "using / not using" list from this pass

**Using:**
- **SortableJS** (MIT) — bh-crm kanban + bh-courses quiz-question reordering.

**Reading for reference only, never adopting as a dependency:**
- **GiveWP**'s `give_donor_wall` block source (GPLv2) — UI/UX reference for the donor wall (5b).
- **LifterLMS core** (`gocodebox/lifterlms`, GPLv2+) — architecture reference for the certificate/achievements engine (4a) and the drag-reorder course-builder UI.
- **Groundhogg** (GPLv2) — segment-builder UX reference for CRM smart lists (Section 3).
- **Devpost**'s judging model (proprietary, no code available) — UX/data-model reference only for judge/rubric scoring (2a).

**Confirmed dead end, don't revisit:**
- **Lite LMS Progress Tracker by LifterLMS** — localStorage-only, no server state, stale since Nov 2022.

**Explicitly rejected as infrastructure (mission conflict), fine as UX inspiration only:**
- WP Fusion, Ko-fi/Buy Me a Coffee, Patreon, Bandcamp, GiveWP's donation-processing core, any hosted CAPTCHA/fraud-scoring/dunning-ML vendor, Discord-as-a-dependency (still fine as an optional webhook consumer, per the existing pattern).
