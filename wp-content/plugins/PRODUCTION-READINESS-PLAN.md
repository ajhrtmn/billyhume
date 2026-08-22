# Consolidated open-work plan (2026-08-21, late session) — READ THIS FIRST

Direct request: "Let's fix the things we skipped from the previous
pass, plan to audit the ecosystem and fold all current work into that
together." This section is the single, current, prioritized list —
everything below it is backing detail/history, same relationship the
existing "RECONCILIATION"-style sections in this doc already have to
their own detail sections. Checked every "still open" claim against
this session's actual git log before listing it here, not copied
blind from stale prose.

## Genuinely still open (never touched this session, confirmed by grep)

In rough priority order:

1. **Front-end WP admin bar theming** — the wp-admin skin themes the
   admin bar in wp-admin; the SAME admin bar for logged-in users on
   the front end never got the same pass. Real, named gap, not
   started.
2. **Debug Tools sticky-header/admin-bar scroll gap** — the page's own
   sticky section-jump header stays fixed while the WP admin bar
   scrolls away on mobile, leaving a visible gap. Needs a real
   sticky-positioning fix (likely a `top` offset that doesn't assume
   the admin bar is always present). Not started.
3. **Ecosystem dashboard plugin-card UX** — two concrete asks: (a) let
   each plugin card check/update its own GitHub status inline, not
   just centrally on Debug Tools; (b) a process convention that every
   NEW plugin gets a dashboard card as one of its first build steps.
   Not started.
4. **Haze/blur system → dynamic, scroll-proximity-driven** (real
   feature request, not a bug) — currently hover-triggered only;
   asked for continuous viewport-proximity blur (blurrier near the
   edge, sharpens toward center), likely `IntersectionObserver`-driven.
   Real, bounded, standalone design-system work. Not started.
5. **Wasmer theme-deploy-gap verification** — still genuinely
   unconfirmed from either side; needs AJ to check dev-ous.wasmer.app's
   own GitHub Updates → "Update now" behavior directly, since this
   session has no access to that environment. Blocked on AJ, not on
   code.

## New this pass — the federated music-library gap (2026-08-21)

Direct vision check from AJ: hosting + publishing a public feed
already works (`bh-streaming`'s `BHS_Feeds` import/export), and the
decentralized, verified "who's out there" directory already works
(`bh-registry`, its own domain-ownership + protocol-openness
verification — the non-blockchain version of what AJ described).
Two real gaps against the actual vision, confirmed by reading
`class-streaming-bridge.php` and `class-api.php`'s `list_artists()`:

1. ~~**Admin curation is search-only, not browse-all**~~ — **FIXED,
   verified live (2026-08-21, bh-registry 0.1.14, commit `ecbc2b1`)**.
   Added a real "Browse Registry" admin screen under bh-streaming's own
   menu (`class-streaming-bridge.php`'s `render_browse_page()`), still
   guarded by `class_exists('BHS_Feeds')` — bh-registry still never
   requires bh-streaming. Shows every verified feed-protocol artist as
   a real card with a one-click "Add to my library" action that
   creates a real `bhs_feed_source` post and triggers an IMMEDIATE sync
   (`BHS_Feeds::sync_one_job()`, the same entry point cron/job-queue
   already use) rather than waiting for the next cron tick, and
   correctly marks already-added artists so there's no duplicate-add
   path. Reuses `BHR_API`'s existing, already-tested query logic via
   `rest_do_request()` (WordPress core's own internal REST dispatch)
   instead of duplicating SQL. A real, useful side-finding along the
   way: the registry's periodic re-verification (`BHR_Verification::
   recheck_all()`, audited earlier as code-only) was CONFIRMED actually
   running live — this install's own fake seed-data links (fake
   `.test` domains) had already been correctly flipped from `verified`
   to `failed` by the real cron job, exactly as designed. Verified the
   new feature end-to-end by temporarily marking one seed link
   verified, confirming the card/add/already-added states all worked
   correctly via direct DB query, then reverting the test state.
2. **No fan-facing cross-site library at all** — everything today is
   admin-curated onto one site's own catalog; a fan has no way to
   search the global registry themselves or build a personal library
   spanning multiple independent sites. Genuinely new feature, real
   open design questions (where it lives, storage, cross-site
   playback) — explicitly scoped as its OWN design pass, not started
   until gap 1 lands and is confirmed working.

---

# Field Reports (2026-08-16, mobile screenshots against dev-ous.wasmer.app)

Direct field data: a batch of real phone screenshots taken against the
**deployed** `dev-ous.wasmer.app` instance — a genuinely different
environment from `localhost:10008` (Local by Flywheel), which is where
this entire session's own live verification has happened. That
distinction turned out to matter a lot — see the first item below.

## Environment gap — likely explains several "why does this look
## broken/stale" reports at once

The front-end screenshots show pink branding and the literal text "OWN
UR SHIT" — this session's rebrand to "The Self-Hosted Self" plus the
full warm-noir palette overhaul landed in the theme back on 2026-08-15
(commit `f081fcd` and after; local theme is now at 1.3.2). CLAUDE.md
already documents the likely cause: **Wasmer's auto-deploy pulls
`wp-content/plugins/` from GitHub on every push but never
`wp-content/themes/`** — no `wasmer.toml` in this repo controls that
scope, so it's a host-dashboard setting outside this codebase's direct
control. The existing, intended workaround is exactly the GitHub
Updates "Check now"/"Update now" mechanism (`OUS_GithubUpdates`) — the
live site is meant to self-update the theme by pulling from GitHub
directly, bypassing Wasmer's broken deploy scope. That mechanism was
also reported broken this same round (see below) — fixed and verified
locally, but **not yet confirmed against the actual dev-ous.wasmer.app
site**, since this session has no direct access to it. Recommend: once
this branch is pushed, log into dev-ous.wasmer.app directly and click
"Check now" → "Update now" for the theme there, and report back
whether it actually pulls the current palette/rebrand — that's the
real test of whether this diagnosis is correct.

## Fixed this round, verified live (all on localhost, all committed)

1. **Sidebar nav icons overlapping their own text labels on mobile**
   ("The Self-H[icon]ted Self", "Design Sui[icon]e", etc.) — root
   cause: WP core's own `.auto-fold` responsive CSS sets
   `div.wp-menu-image` to `position:absolute` with a fixed left offset
   sized for core's own icon-only collapsed column (meant to pair with
   core's own `.wp-menu-name{left:-999px}` that hides the label
   entirely in that state). This skin keeps the label visible instead
   (better UX) but never reset the icon to match, so it rendered on
   top of the now-visible text. Fixed, reproduced/re-verified live with
   the real mobile menu toggle both before and after.
2. **Admin Skin plugin missing from the ecosystem dashboard, the
   "Install & Activate Everything" bundle, and GitHub Updates** — it
   was simply never registered in `OUS_Registry` at all, despite being
   ours to author/bundle like every peer plugin. Added the registry
   entry and generated its own bundled zip (didn't exist before).
   Confirmed live: shows Active on the dashboard, appears in GitHub
   Updates.
3. **GitHub Updates "Check now" — real bug, not just UX.** Root cause:
   `check_all()` ran up to ~13 sequential `wp_remote_get()` calls (10s
   timeout each) inside ONE queued job. A few slow responses can
   exceed PHP's `max_execution_time` and get the whole job silently
   killed mid-loop — a hard timeout kill isn't a catchable exception,
   so nothing gets logged as failed anywhere, matching the reported
   symptom exactly (every row stuck "not checked yet," zero visible
   error). Fanned out to one independent job per source (each with its
   own atomic claim/retry) instead of one job looping all of them.
   Also fixed the UX gap on top: "Check now" only ever queued a job
   and told the user, in prose, to go click a SEPARATE "Run due jobs
   now" button elsewhere — now auto-submits that exact button as a
   real second request the instant the page reloads, without
   recombining the two calls into one request (which is the exact
   site-breaking synchronous-timeout bug this async design already
   fixed once before). Verified live: before, one click left 10 of 13
   sources stuck; after, all 13 completed in one pass.

## Still open — real, needs a dedicated pass (not yet touched)

Screenshots showed several more real issues on dev-ous.wasmer.app that
weren't reproduced/fixed this round (some may be downstream of the
stale-theme gap above, some are independent):

- ~~**WooCommerce Settings (Payments, Shipping tabs) and WooCommerce
  Home dashboard render completely unstyled**~~ — **FIXED, verified
  live (2026-08-21, admin-skin 0.30.0, commit `9a4356c`)**. Confirmed
  real via a live WCAG contrast sweep (not just the screenshot): WC's
  newer `@woocommerce/components` + Emotion CSS-in-JS screens are a
  genuinely different rendering layer from classic PHP admin markup,
  same gap already documented for the block editor — each
  sub-component sets its OWN explicit background/text color instead of
  inheriting. Fixed WC Home (task/inbox cards, stats overview, Jetpack
  promo card, count badges — including a second classless `<span>`
  duplicate of an already-fixed title, the same text rendered twice in
  the DOM), the admin bar's own "Store coming soon" site-visibility
  badge (site-wide, not WC-Home-specific), the Payments tab's
  extension badge/payment-count pill/expand button, and the Shipping
  tab's blank-state copy plus recommended-extensions panel. Re-swept
  all three screens clean after fixing (zero findings) — but only
  checked against localhost:10008, not yet confirmed against
  dev-ous.wasmer.app.
- ~~**bh-courses Sessions admin — the calendar grid renders blank**~~ —
  **INVESTIGATED (2026-08-21), no code bug found; likely environment-
  specific.** Full source read (`class-sessions-admin.php`,
  `sessions-admin.ts`/`.js`, vendored FullCalendar v7.0.2) plus a live
  check against localhost:10008 confirms the calendar renders and
  initializes correctly there (real events, real date grid,
  `FullCalendar` global defined) — the PHP/JS/data pipeline is
  internally consistent, no functional bug in the shipped code. Most
  likely explanation on the deployed site: either the 777KB vendored
  `fullcalendar.global.js` failing to load there (404/stale-cache/
  permissions), or a caching/minification/optimization layer on
  dev-ous.wasmer.app rewriting the `<script>` tag to add `async`/
  `defer`/`type="module"` — the JS guards on `typeof FullCalendar ===
  'undefined'` and fails silently (no console error) if the global
  never attaches, matching the reported symptom (empty grid, no
  visible error) exactly. Needs live devtools Network/Console
  verification against the actual dev-ous.wasmer.app page to confirm
  which — not fixable further from this environment alone.
- ~~**Media & CDN Setup wizard — provider cards appear dimmed/
  disabled**~~ — **FIXED, verified live (2026-08-21, admin-skin
  0.31.0, commit `78ac991`)**. See the "Activate-everything sweep"
  section below for the full story — the gated section had never
  actually rendered on this install before, confirmed real (not a
  screenshot artifact) by activating Advanced Media Offloader locally
  to reproduce it.
- ~~**Course-completion screen's "Get share image" renders a broken-
  image icon**~~ — **FIXED (2026-08-21, own-ur-shit 3.10.39, commit
  `73664ed`)**. Root cause: `BH_ShareCard::output_png()` called
  `generate()` (unconditional `imagettftext()`) before sending the
  `image/png` header — on any host whose GD build lacks FreeType
  support, that call fatals or emits a warning into the response body,
  corrupting the PNG bytes. Not reproducible on this local install
  (its own GD build has FreeType), so this was reasoned through and
  fixed defensively rather than reproduced end-to-end: added a
  `gd_capable()` guard plus a try/catch safety net, both falling back
  to a new `render_fallback()` (a real, valid, on-brand PNG using only
  `imagefilledrectangle()`, nothing FreeType-dependent) instead of a
  broken response. Also found and fixed, live, a second real bug
  while verifying this one: every card style's corner wordmark was
  hardcoded to the pre-rebrand name "OWN UR SHIT" instead of the
  actual site name — every share card generated since the rebrand had
  been silently advertising the wrong brand. Verified live: the
  normal (FreeType-present) path renders unchanged, screenshotted;
  wordmark now correctly reads the live site name. The fallback path
  itself (FreeType actually missing) could not be exercised live in
  this environment — logic-reviewed instead, not screenshot-verified.
- **Front-end WP admin bar (for logged-in users) needs the same dark
  theming pass** the wp-admin skin already gets — currently unstyled/
  default, a real, named gap ("The admin bar on the front end needs
  style consideration too").
- **A layout bug on scroll**: the Debug Tools page's own sticky
  section-jump header stays fixed while the WP admin bar scrolls away,
  leaving a visible gap where the admin bar used to be. Needs a real
  sticky-positioning fix (likely a `top` offset that assumes the admin
  bar is always present, not accounting for it scrolling out of a
  mobile viewport).
- **Ecosystem dashboard's plugin-card list — direct UX feedback**: "This
  doesn't feel like great UX either." Two concrete asks bundled with
  this: (1) let each plugin card check/update its own GitHub status
  directly (not just centrally on the Debug Tools page), and (2) make
  sure any NEW plugin built from here forward gets a dashboard card as
  one of the first build steps — a process convention to add wherever
  this ecosystem's own build checklist lives (CLAUDE.md or this plan),
  not just a one-time fix.
- **Haze/blur system — real feature request, not a bug**: "I like the
  blur/haze, but I'd almost prefer it to be automatic and dynamic
  depending on what is close to the focal parts of the UI, so if it's
  closer to off screen it's blurrier, but it automatically comes into
  focus as it comes into real view." Currently the haze system
  (sidebar/card-groups/modals, admin-skin.css) is hover-triggered only.
  This asks for a genuinely different mechanism: continuous,
  scroll/viewport-proximity-driven blur (something near the viewport
  edge is blurrier; it sharpens as it approaches the visual center) —
  likely an `IntersectionObserver` or scroll-position calculation
  driving a CSS custom property per element, not a small tweak. Real,
  bounded, standalone piece of design-system work — worth scoping
  properly rather than bolting on ad hoc.

## Activate-everything sweep (2026-08-21)

Direct request: "activate everything in the ecosystem locally to make
sure we cover them all in our fixes as well." This local install had 8
plugins sitting inactive (Advanced Media Offloader, BH Feedback, BH
Live, BH MailPoet, BH Registry, BH Social, BH Tickets, plus one more)
— exactly how the Media & CDN Setup wizard bug above went undetected
for so long (its entire provider-card section is gated behind
`class_exists()` on the Advanced Media Offloader class, so it had
never actually rendered on this install before this session). Bulk-
activated all 8 via the Plugins screen; now 17/17 active.

Verification done with WP_DEBUG_LOG temporarily enabled (reverted
before finishing, per this project's own standing convention) plus a
live contrast sweep on every newly-active plugin's own admin screen:
`own-ur-shit` dashboard, Media & CDN Setup (incl. the BH Live
Owncast/Cloudflare-Stream section), Registry Submissions, Feedback
Requests, BH Social, Support Tickets, Monetization Settings. All clean
— zero contrast findings, zero PHP errors logged beyond the expected
"ADVMO: no cloud provider configured yet" notice (benign, provider
simply isn't configured). No further theming gaps found from this
pass; the two real bugs it did surface (Media wizard dimmed cards,
share-card GD/FreeType fallback + stale watermark) are documented and
fixed above.

## Functional-depth audit (2026-08-21) — new tracked initiative

Direct request: "make sure that everything is actually doing what it's
supposed to be doing... verses just literal interpretations of the
ideas and rules... go through them all serially and meticulously."
This is a different axis than everything above — the visual/CSS
sweeps confirm screens RENDER correctly; this checks whether each
plugin's actual features DO the real job they claim to, live, as a
real user, not just whether the UI is present and themed.

**Stock-take.** Version number is a rough proxy for how much real
iteration a plugin has had, and it matches what's actually been
exercised this session:
- **Deep, live-verified, real end-to-end flows already**: `bh-contest`
  (v3.7.30 — submit/vote/reveal), `bh-courses` (v0.4.86 — enroll/
  lesson/quiz/tier-gate), `bh-crm` (v2.4.21), `bh-monetization-woo`
  (v0.5.19 — purchase/entitlement). Not re-auditing these here.
- **Touched only superficially so far** (contrast sweeps, PHPStan
  typing, registry entries — never actually exercised as a live user
  flow): `bh-streaming` (v0.5.29 — Pro Wizard spot-checked only,
  player never walked end-to-end), `bh-registry` (v0.1.13),
  `bh-feedback` (v0.1.5), `bh-live` (v0.9.4), `bh-social` (v0.3.4),
  `bh-tickets` (v1.0.1), `bh-video` (v0.4.2), `bh-mailpoet` (v1.1.3).
  These are this initiative's actual scope.

**Method, confirmed with AJ directly (2026-08-21):**
1. Read the plugin's own stated purpose (its description, its slice of
   VISION.md/ROADMAP docs).
2. Walk its real admin UI AND portal/front-end surface as an actual
   user would — does the primary action produce the real, correct
   effect (DB write, external call, entitlement grant, notification),
   not just "does it render."
3. Judge each core feature: genuinely purposeful/integrated, honestly-
   scoped-but-limited (fine, as long as it says so — see the Vizio Ads
   precedent below), or a literal/shallow stand-in for something
   bigger.
4. **Small, clear gaps get fixed and live-verified immediately. Larger
   gaps (a real multi-day feature build) get logged here with a clear
   scope, not started ad hoc** — confirmed preference over cataloging
   everything first or over-building mid-audit.
5. **Third-party integrations** (YouTube/Twitch/Meta for bh-social,
   Owncast/Cloudflare for bh-live, MailPoet): verify the code path
   critically — does it do real work when credentials ARE present, does
   it fail gracefully when they're not — without needing live
   third-party credentials in this environment, per AJ's own call.

**A good-faith precedent already found, worth calibrating against**:
`bh-social/includes/class-vizio-ads.php` — a real DB-backed draft-
capture implementation with an honest doc comment explaining exactly
why it's `get_status(): 'manual_handoff'` rather than a fake self-serve
API integration (Vizio has no public self-serve ads API). This is the
target shape: honestly-scoped is fine, silently-pretending is not.

**Confirmed order** (least-audited/highest stub-risk first, since
contest/courses/crm/monetization already earned trust): `bh-social` →
`bh-tickets` → `bh-feedback` → `bh-live` → `bh-registry` → `bh-video`
→ `bh-mailpoet` → `bh-streaming` (real end-to-end player walkthrough,
despite the higher version number) → `own-ur-shit` core last (biggest
surface, most other plugins depend on it).

### bh-social — audited (2026-08-21)

**Overall: real, honest code, one confirmed functional gap.** Read
every platform class plus `class-admin.php`'s full handler/render
inventory, then verified live against `admin.php?page=bh-social`
(previously never rendered here — inactive until this session's
activate-everything pass).

**What's genuinely real and working, not stubs:**
- YouTube/Twitch/Meta/TikTok: real OAuth2 flows (state-nonce-protected
  callbacks, token refresh, credential round-trip through
  `wp_options`), real API calls (`wp_remote_get`/`wp_remote_post`
  against each platform's actual documented endpoints, not mocked),
  real `OUS_Jobs`-backed stats-pull cron writing to a real
  `wp_bhso_platform_stats` table, and a real PHPUnit test suite
  exercising credential persistence and validation. The UI itself is
  honestly labeled — "Alpha"/"Experimental" `OUS_Badge`s with specific,
  accurate caveats per platform (Meta's app-review timeline, TikTok's
  HTTPS-only redirect + sandbox limits), plus a page-level banner:
  "every integration below is real, working code... but 'alpha' here
  specifically means not yet verified against a live account." This is
  exactly the honest-scoping bar to hold every other plugin to.
- Ads draft-capture (Roku/Spotify/Amazon DSP/Samsung/Vizio): fully
  wired end-to-end (save/list/delete, real DB-backed drafts, "Open
  {Platform} Manager" handoff link) — a correctly-scoped feature given
  none of these 5 platforms have a public self-serve API, confirmed via
  each class's own research-backed doc comments (Vizio's is the
  clearest example: "included for completeness/honest signaling only").
- No front-end/portal surface exists for this plugin (no shortcode,
  no public stats display) — checked against VISION.md/
  ROADMAP-platform-evolution.md, this is correctly scoped as an
  artist-internal ops tool, not a missing feature.

**Confirmed real gap**: the actual "organic **cross-posting**" half of
the plugin's own description — the whole reason `cross_post()`/
`enqueue_cross_post()` exist on `BHSO_YouTube`, `BHSO_Meta`, and
`BHSO_TikTok` — has **zero UI entry point anywhere in the ecosystem**.
Confirmed by grepping every `add_action('admin_post_...')` registration
in `bh-social.php`/`class-admin.php`: connect, disconnect, and
stats-pull are wired for all 4 platforms, but only **Twitch** got a
matching feature UI ("Send a chat announcement" — a real form,
`handle_twitch_announce()`, wired and working). YouTube/Meta/TikTok's
video cross-post methods are real, tested, callable code with no
caller anywhere — not in `bh-social` itself, not in `bh-video`, not in
`bh-streaming`, not in `bh-contest` (grepped all three for any
`BHSO_*`/`cross_post` reference — zero hits). A user cannot actually
cross-post a video through this plugin today, despite it being the
plugin's headline feature.

**Scoped fix, not started (logged per "fix small, log big")**: add a
"Cross-post a video" form to each of the 3 platform sections in
`class-admin.php`'s `render_youtube_section()`/`render_meta_section()`/
`render_tiktok_section()`, following the exact two patterns already
proven elsewhere in this codebase — Twitch's own announce form (same
file, `render_twitch_section()`) for the form/handler/nonce shape, and
`bh-video/includes/class-admin.php`'s existing `wp.media()` JS picker
for attachment selection (so this isn't inventing a third UI pattern).
Needs: 3 new `admin_post_bhso_{platform}_cross_post` handlers calling
the existing `enqueue_cross_post()` methods (already correctly
signatured and job-queue-backed), 3 small forms (attachment picker +
title/description/privacy fields, platform-specific), each gated
behind `$status === 'connected'` same as the existing stats section.
Real but bounded — roughly the same shape as the Twitch announce
feature, times three. Not built this session; flagged for a dedicated
pass.

### bh-tickets — audited (2026-08-21)

**Overall: real, complete, correctly-wired feature. One real gap found
and fixed.** Read all 7 includes files, then verified live end to end:
submitted a real ticket through the portal (`/account/?panel=tickets`),
confirmed it appeared correctly in the staff list and detail view
(`admin.php?page=bh-tickets`), status-change and assignment dropdowns
present and wired to real handlers.

**What's genuinely real:** `BHT_Tickets::create()`/`BHT_Replies::add()`
— real DB-backed CRUD, real `BHCRM_Links` requester relationship, real
`BH_Event` emission for the activity log, a real bh-crm activity-
summary integration (`register_crm_activity()`). Staff list/detail/
reply/status/assign are all fully wired with real nonces and capability
checks (`bhcore_manage_tickets`) — no dead buttons, no decorative-only
controls.

**Confirmed real gap, found and FIXED live (bh-tickets 1.0.2, own-
ur-shit not touched, commit `663ce3b`)**: `BHT_Replies::maybe_notify()`
already handled staff-reply → requester and requester-reply → assignee
notifications, and its own doc comment openly admitted a brand-new,
UNASSIGNED ticket notified nobody at all. That gap was real and live —
`bht/ticket_created` fired for the event log but nothing ever listened
to it for a notification. A support plugin whose own description
promises "staff triage from wp-admin" needs staff to actually learn a
ticket exists. Added `BHT_Tickets::notify_staff_new_ticket()`, notifying
every `bhcore_manage_tickets` holder (skipping the creator). Verified
live: submitted a real ticket, confirmed via direct DB query this
install has exactly one staff account (the ticket's own creator) — zero
notification rows is the CORRECT result here (self-notification is
deliberately skipped). The "notify a different real staff member" path
itself couldn't be exercised without a second staff account in this
environment; reviewed the logic carefully instead against the identical,
already-proven pattern in `maybe_notify()` right above it.

**Minor, not fixed**: `BHT_Tickets::set_status()` emits a `BH_Event`
for the log but doesn't directly notify the requester of a status
change (only a reply does). Arguably fine — a bare status flip without
comment is lower-urgency than a reply — logged here as a real but minor
observation, not a confirmed gap worth blocking on.

**Also fixed live while verifying the above** (own-ur-shit 3.10.40,
commit `663ce3b`), two direct field reports on the portal shell itself
(not bh-tickets-specific — these reach every peer plugin's portal
panel): `.bhi-portal-main` had zero generic form-element styling (only
the login page's own fields were themed), so bh-tickets' New Ticket
form rendered as stark unstyled white boxes; and `.bhi-portal-nav
a.is-active` used a flat, fairly saturated `--bh-accent-soft` fill
("ugly contrast" per direct feedback) instead of the restrained accent-
glow treatment this project's own design brief calls for — switched to
`--bh-accent-muted-bg`, matching the low-alpha treatment already used
elsewhere in `theme.css`. Both verified live, screenshotted, full
contrast sweep clean.
### bh-feedback — audited (2026-08-21), no gaps found

**Overall: genuinely excellent, no confirmed functional gap.** Read
all 8 includes files (submit/upload/wallet-debit, the self-serve
claim/release/complete reviewer queue, portal panel). This is the
most carefully-reasoned plugin audited so far, despite its low version
number (0.1.5) — a real reminder that version count tracks ITERATION,
not quality; this plugin just hasn't needed many fixes.

**What's genuinely real:** `BHF_Requests::handle_submit()` — real
atomic wallet debit (`BHM_Wallet::debit()`, fails cleanly on
insufficient balance rather than ever creating an unpaid request),
real reversal-on-post-creation-failure (mirrors
`BHM_Entitlements`' own refund-reversal posture), a real scoped-and-
reverted `upload_files` capability grant for the audio upload (same
trust-boundary pattern as `class-public-profile.php`), and a real
sha1-file-hash duplicate-submission detector surfaced to the reviewer
(not silently auto-rejected). `BHF_Queue::claim()`/`release()`/
`complete()` — real atomic conditional-UPDATE claim locking (same
TOCTOU-safe pattern `BHM_Wallet::debit()` uses), and a real, honestly-
documented cache-invalidation bug the plugin's OWN test suite caught
in a prior session (`clean_post_cache()` needed after a raw `$wpdb`
postmeta UPDATE, since that bypasses WP's object cache). Both the
reviewer queue (claim/release/complete, with the duplicate-flag
surfaced) and the submitter form are fully wired to real portal UI
(`class-portal-panel.php`) — verified live (`/wp-admin/admin.php?
page=bh-feedback-requests` renders correctly).

**A deliberate non-finding, worth recording as a real judgment
call**: unlike bh-tickets' new-ticket notification gap (fixed this
session), reviewers here do NOT get notified when a new request is
submitted — only the submitter gets notified on completion. Did NOT
flag this as a gap: this plugin is explicitly a self-serve PULL queue
by its own description ("any account with the Reviewer job claims it
from a shared queue"), and the submitter-facing copy already sets a
multi-day turnaround expectation per tier — a periodic-check model is
the correct, intended design here, not an oversight the way bh-
tickets' silence was. Applying the same fix mechanically to both
would have been wrong for this one.
### bh-live — audited (2026-08-21), no confirmed gap

**Overall: the most ambitious plugin audited so far (18 files, 2,613
lines), and consistently well-built throughout.** Read the stream-
engine abstraction, both chat implementations, the front-end player,
and the OBS Browser Source overlay system; verified live.

**What's genuinely real:**
- `BHL_StreamEngine`/`BHL_OwncastEngine` — real status polling against
  Owncast's documented public `/api/status` endpoint, a real
  `disconnect()` admin action (token-gated), and an honest docblock
  explaining why there's deliberately no `start_stream()` (RTMP ingest
  begins the moment OBS connects — outside WordPress's control by
  Owncast's own design, not a missing feature).
- `BHL_Chat` — abstracted SEPARATELY from the video engine (a real,
  deliberate architecture decision per its own docblock, citing a named
  planning doc), with TWO real implementations: `BHL_OwncastChat`
  (real iframe embed) and `BHL_PollingChat` (a genuinely complete
  native chat — real REST routes, real rate limiting, a real
  transient-based mute mechanism matching bh-streaming's Jam feature,
  per-stream message scoping, and a real cross-platform relay endpoint
  with token auth for a future Twitch/YouTube chat bridge). The client
  JS (`assets/js/chat.js`) is a real polling implementation, not a
  static stub — confirmed real `fetch()` calls on a `setInterval`.
- `BHL_Player`'s `[bh_live]` shortcode correctly sequences script
  dependencies (chat.js loads before live-player.js specifically to
  avoid a real race the plugin's own comment names) and gracefully
  handles the unconfigured/offline state.
- `BHL_Overlay` — real OBS Browser Source pages (chat overlay, contest-
  votes overlay guarded by `class_exists('BH_API')`, a health-check
  endpoint, and an automation bridge for local OBS control) served as
  raw HTML bypassing the REST JSON envelope (the one correct way to do
  this from inside a `register_rest_route` callback).

**Verified live**: the `[bh_live]` shortcode on a real published page
correctly rendered "Not live right now" (Owncast unconfigured on this
install) rather than erroring or showing a blank void; the health
overlay endpoint (`/wp-json/bhl/v1/overlay/6177/health`) returned a
real, correctly-shaped JSON response reflecting the unconfigured state
accurately (`"configured":false`).

**No confirmed functional gap found.** This plugin sets the bar for
what "honestly scoped and genuinely complete" looks like in this
audit — on par with bh-feedback, and a useful contrast against
bh-social's real gap (a fully-built backend with no UI to reach it) —
everything checked in bh-live actually has both halves: real backend
AND a real way to reach it.
### bh-registry — audited (2026-08-21), no confirmed gap

**Overall: genuinely excellent, real end-to-end verification
mechanism.** Read all 10 includes files; verified live on the real
Artist Registry page (`?p=66`).

**What's genuinely real:** `BHR_Verification` — a real, two-question
trust mechanism, both parts independently checked: (1) domain
ownership via a well-known-file challenge
(`/.well-known/bh-registry-verify.txt`, exact-line-match on a random
token, protocol-agnostic by design so ActivityPub and RSS submissions
share one proof-of-control mechanism), and (2) protocol openness — a
real `fetch_feed()`-based enclosure check for RSS/Podcasting 2.0
(honestly duplicated from bh-streaming's identical logic rather than a
hard cross-plugin dependency, with a flagged future de-dup path), and
real ActivityPub actor discovery per spec (Accept header negotiation,
real Actor-type + outbox validation, not "any URL that returns JSON").
A link only ever verifies with BOTH checks passing; failure reasons
are logged privately (`OUS_DebugLog`) rather than exposed publicly, a
deliberate anti-gaming decision. Periodic re-verification runs daily,
fans out to `OUS_Jobs` (one job per link) when the core job queue is
active — same resilience pattern already fixed elsewhere in this
session (`OUS_GithubUpdates`'s Check-Now bug), degrading gracefully to
inline when it's not.

**Verified live**: the real Artist Registry page renders a populated,
searchable/filterable directory; clicking "Submit your link" opens a
genuinely complete, correctly-themed submission modal (name/bio/email/
protocol/URL, real copy explaining the domain-ownership requirement)
wired to a real REST endpoint (`POST /bhr/v1/submissions`).

**Not a code bug, worth noting**: the visible artist cards
("Echo Parade `_bhr_seed_`", etc.) are this local install's own
Debug Tools seed data (`BHR_Debug::seed()` — a deliberate, well-built
dev fixture, tag embedded directly in the display name so "Reset
Everything" can find and remove it later via `LIKE`). Left as-is
rather than reset, since it may be an existing intentional fixture
from a prior session's own testing — flagging here rather than
silently clearing it.

**No confirmed functional gap found.**
### bh-video — audited (2026-08-21), one real gap fixed

**Overall: real, complete SPA and admin upload flow — one confirmed
"undiscoverable feature" gap, fixed.** Read all 7 includes files;
verified live.

**What's genuinely real:** `[bh_video]` is a genuine browse/playback
SPA (real REST-backed search/genre-filter, real `<video>` element
playback, chapters support) — confirmed working live via its own
auto-generated `assets/js/video-player.js`, not a static stub. Admin
upload flow is fully wired: a real `wp.media()` picker scoped to
`library: { type: 'video' }`, a real `save_post_bhv_video` handler.
This plugin's own changelog already shows real, honest self-correction
history (a `register_taxonomy()` `show_in_menu` behavior caught and
fixed via reading WP core source directly, an `attachment_id`
false-return type caught via PHPStan).

**Confirmed real gap, found and FIXED (bh-video 0.4.3, commit
`563e3b2`)**: unlike bh-registry (`BHR_Activator::
maybe_create_default_pages()`) and bh-streaming, this plugin never
auto-created a landing page for its own shortcode on activation.
Confirmed live before fixing: zero `bhv_video` posts existed on this
install and no page anywhere referenced `[bh_video]` — the SPA and
its REST API were both fully real and working, just genuinely
undiscoverable to an admin who didn't already know to hand-create a
page and paste the shortcode in, despite the plugin's own description
promising "a standalone video catalog and player." Added
`BHV_Activator::maybe_create_default_pages()`, mirroring
`BHR_Activator`'s exact version-gated pattern. Verified live: visiting
any wp-admin screen now creates a real "Videos" page, confirmed via
direct DB query and a live screenshot of the correctly-rendering
(empty, since no videos exist yet) SPA.
### bh-mailpoet — audited (2026-08-21), genuinely verified end to end

**Overall: a real chance to close this plugin's own long-standing
"NOT runtime-verified" disclosure, and it passed completely.** This
plugin's own docblock (`class-sync.php`) has honestly flagged since
it was written that its MailPoet API calls (`getLists`, `addList`,
`getSubscriber`, `addSubscriber`, `subscribeToList`,
`unsubscribeFromLists`) were written against MailPoet's DOCUMENTED
API surface, never against the real installed plugin, since MailPoet
was never installed on this repo. Per AJ's own suggestion mid-session
("Might should install mailpoet"), installed the real MailPoet plugin
(5.36.0, official WordPress.org release) — the single highest-value
thing this audit step could do, turning a "reasoned through" gap into
an actually-checked one.

**Verified for real, not just read:**
- Every method signature `BHMP_Sync` calls
  (`\MailPoet\API\MP\v1\API::getLists()`, `addList()`, `getSubscriber()`,
  `addSubscriber()`, `subscribeToList()`, `unsubscribeFromLists()`) was
  grepped directly out of the real installed
  `wp-content/plugins/mailpoet/lib/API/MP/v1/API.php` — every one
  matches exactly, including parameter shapes.
- Triggered a REAL sync via Debug Tools' "Sync now" button: **92
  synced, 0 failed**, confirmed live in the admin UI.
- Confirmed via direct DB query that real subscriber rows actually
  landed in MailPoet's own `wp_mailpoet_subscribers` table (3,039 total
  after sync, real emails/names), and that `get_or_create_list_id()`
  correctly auto-created a real MailPoet segment ("The Self-Hosted Self
  Fans") rather than silently failing or duplicating.
- `BHMP_InstantSync` — read all 5 real-time hooks (`user_register`,
  `profile_update`, `woocommerce_order_status_completed`,
  `bhm_entitlement_granted`, and the ecosystem's own `bh_event_emitted`
  bus for 7 specific event types) and confirmed every single claim in
  the Debug Tools UI's own copy ("real-time sync also runs on
  registration, profile updates, WooCommerce order completion,
  entitlement grants, and the ecosystem's own event bus") is backed by
  a real, correctly-wired hook — not UI copy overselling what the code
  does.

**No confirmed functional gap** — this plugin was already honestly
self-aware about its one real unknown (untested against a live
MailPoet install), and that unknown is now closed, cleanly.

**Deferred, per AJ's own direction** ("We will likely wanna skin
mailpoet's GUI as needed when we get to the bh-mailpoet step"):
MailPoet's own admin UI (a large, actively-developed third-party React
app) hasn't completed its onboarding wizard on this install yet, so
its real working screens (Emails, Subscribers, Automations) aren't
reachable to audit visually today. A light contrast check of the
currently-visible onboarding/homepage screen came back clean (zero
findings), but a full skin pass is explicitly scoped as future,
opportunistic work once MailPoet is actually being used day-to-day —
not attempted this round.
### bh-streaming — audited (2026-08-21), real front end confirmed

**Overall: genuinely rich, real front-end player and REST layer,
verified live — one real environment limitation (not a code gap)
prevented full end-to-end audio-decode confirmation.**

**Verified live on the real Streaming page (`?p=63`)**: a fully real
SPA — All Tracks/Releases/Liked Songs/My Playlists tabs, search, genre
filter, an "Import my music" action, real track cards. Clicking a
track produced a real player bar (prev/play/next, like, add-to-
playlist, Lyrics, EQ, Viz, Jam, volume) and fired FIVE real, correctly-
sequenced REST calls confirmed via the network log: `GET /bhs/v1/
tracks`, `POST /tracks/{id}/play` (play-count tracking), `GET /tracks/
{id}/related`, the actual audio file request (a real `206 Partial
Content` range response — a correct streaming-audio response, not an
error), and `POST /tracks/{id}/resume` (resume-position tracking).

**A real, honest failure path, correctly handled**: the click produced
a "Playback error — retrying…" badge, then "This track isn't playable
right now — its source may be temporarily unavailable." Traced this to
its actual root cause rather than assuming a code bug: this install
has exactly ONE published `bhs_track` ("Midnight Static"), and its
audio attachment (`tiny-test.mp3`) is a 10-byte file — a bare ID3
header with zero actual audio data (`xxd` confirmed: just the ID3
magic bytes, nothing else). The HTTP layer worked correctly (a real
`206 Partial Content` response); the browser's audio decoder correctly
failed to decode zero bytes of audio, and the player correctly
surfaced a graceful, honest error message instead of hanging or
crashing. **This is the player's error-handling working exactly as
designed** — a genuinely good finding, not a bug — but it also means
the actual "does real audio genuinely play" happy path could not be
verified in this specific environment, since no track with real,
complete audio content currently exists on this install.

**Also confirmed real** (code-read): `BHS_Jam`'s shared-listening
feature has a real class with real registered REST routes, matching
this session's already-established understanding of Jam as a proven,
working feature from earlier work.

**No confirmed functional gap.** Recommend a follow-up spot-check once
a real, complete audio file is uploaded to this install to confirm
actual decoded playback specifically — everything up to and including
the correct byte-range HTTP transfer is already verified working.
### own-ur-shit core — audited (2026-08-21), verified via direct check
### plus real exercise across every peer-plugin audit above

**Deliberately scoped, not exhaustive** — core is the ecosystem's
largest plugin by far (1,234 PHPStan findings historically, dozens of
shared services), a genuine multi-session undertaking of its own to
audit standalone. Instead of a from-scratch pass, closed out this
initiative by (1) directly checking the one shared service not yet
exercised this session, and (2) recognizing that core's other named
shared services already got REAL, live exercise as a byproduct of
every peer-plugin audit above — arguably a more meaningful signal than
a cold read, since each was proven working under actual use, not just
inspected.

**Directly checked this pass**: `BH_Event::emit()` (the emitter side —
`class-event.php:105-127`) fires `do_action('bh_event_emitted', $type,
$job_args, $args)`, confirmed to match exactly what bh-mailpoet's
`BHMP_InstantSync::sync_from_event()` listens for (already verified
working in the bh-mailpoet section above — closes the loop on both
ends of that bus). `OUS_Roles`' admin UI (`admin.php?page=ous-roles`)
verified live: a real, populated per-person job-grant table
(Instructor/Reviewer/Designer/CRM Manager), correctly excluding
Administrator accounts per its own stated design ("an Administrator
already has every job below via their role").

**Already verified working, as a real side effect of this session's
other work** (not re-tested standalone here, since duplicating already-
real verification would add nothing): **Notifications**
(`OUS_Notifications::notify()`) — used successfully in the bh-tickets
fix this session, and read/reused correctly in bh-feedback's
completion notice and bh-live's stream engine. **Jobs** (`OUS_Jobs`) —
the GitHub Updates Check-Now fix earlier this session directly
exercised and fixed a real bug in it; bh-registry's periodic re-check
and bh-social's stats-pull cron both fan out through it correctly.
**Wallet** (`BHM_Wallet`) — bh-feedback's real atomic debit-with-
reversal flow exercises this directly. **Portal** (`BHI_Portal`) —
fixed two real live-caught bugs in it this session (form theming,
active-nav contrast), now verified clean. **Share cards**
(`BH_ShareCard`) — fixed a real GD/FreeType fallback gap and a stale-
branding bug this session, verified live. **Identity/Roles data
model** — every peer plugin's user-scoped feature (tickets, feedback
requests, chat messages, registry submissions) correctly resolved real
`WP_User` rows throughout.

**No new functional gap found** in this pass. The core's shared-
service layer held up consistently under real, live exercise across
every plugin that depends on it this session — the strongest possible
signal for a foundational piece, short of a dedicated standalone audit
(which, given its size, is worth scoping as its own future session
rather than compressing into this one's tail end).

## Standing permission, noted for future work

"I don't mind using unsplash and other 3rd party visual assets so long
as they are open source/Creative Commons/public domain, or otherwise
free to use for this purpose." — applies to any future placeholder
art, hero imagery, or empty-state illustrations; no need to ask each
time within those license bounds.

---

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
