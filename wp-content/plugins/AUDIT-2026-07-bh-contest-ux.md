# bh-contest — Magical UX Audit (Task 13/16)

**Scope:** `bh-contest`'s user-facing surfaces — voting page (`[bh_contest_player]`), Results page/modal, contest admin (list + edit screen), submission flow (incl. new audio-replacement), and the new `BH_Element` player extension zones.
**Date:** 2026-07-25 · **Model:** Claude Sonnet 5
**Method:** Code-level read only — templates, admin render methods, JS (`assets/js/player.js`, `portal-submissions.js`), inline styles. **No live browser/WordPress install was available this session; nothing below was click-tested.** Every finding cites file:line. This is a UX-only pass — code quality is out of scope (covered separately).

---

## Re-verification of 07-13 findings

### 1. "Votes remaining" counter — STILL MISSING (confirmed still open)
Server computes `votes_left` on every vote response (`includes/class-api.php:261,279,314`), and the client uses it — but only as a one-shot toast after a vote fires: `assets/js/player.js:929-931` (`` `Vote counted${catSuffix} — ${body.votes_left} vote${...} left.` ``). There is no persistent on-page element (header, tabs, mini-player) showing remaining votes before a voter has cast one, and the toast itself disappears seconds after each vote. A voter arriving fresh, or one who voted a while ago and switched categories, has no ambient way to see "you have 2 votes left in this category" without clicking Vote again. Since the data (`votes_left`) is already returned per-vote and the limit config exists server-side, this is a genuinely cheap fix relative to the value — surface it as a small persistent badge near the category tabs or now-playing bar, refreshed on load and after each vote.

### 2. Branding-override visual weight — FIXED, and more thoroughly than suggested
07-13 suggested a lighter background for the "off" state. What's actually shipped is stronger: the entire "Contest Branding & Style" postbox now starts **collapsed** by default when override is off (`includes/class-admin-metaboxes.php:19,27-31`, via `postbox_classes_bh_contest_bh_contest_style` + WP's native `closed` postbox class), and inside the box, the whole override-fields block (`#bh_style_fields`, `class-admin-metaboxes.php:542`) is `display:none` until the checkbox is checked (toggled live at `class-admin-metaboxes.php:608-610`). This fully removes the "looks broken" ambiguity the original finding described — an editor scrolling past a long edit screen never sees a wall of grayed-out fields at all. Closed item.

### 3. Prior "genuinely good" verdicts — still true on re-read
- **Contest list admin table**: still real `WP_List_Table`/`OUS_ListTable` conventions — dual status pills (Submit/Vote, color-coded), one-click "Start now"/"End now" phase overrides, click-to-copy shortcode `<code>`, live subs/votes stats linking straight to Results (`includes/class-admin-list-tables.php:32-79`). No regressions found; still clears the WooCommerce Orders-table bar.
- **Voting page**: category tabs (`player.js:820-826`), instant category switching with no refetch (`player.js:831-836`), and per-track vote buttons with a clear checkmark+label "Voted" state (`player.js:843-861`) all read exactly as described. Still well-built.
- **Results page/modal**: medal icons for top 3, numbered rank badges otherwise, category color pills in the "All" view (`player.js:965-1006`) — all intact. One thing the code itself flags as a real, self-documented gap (not something I'm newly discovering, but worth carrying forward since it's UX-visible): the "All" tab's cross-category ranking only ever reads `.results`, so a judged/hybrid contest with 2+ categories will mislabel judge scores as "votes" and never show judged results there (`player.js:984-986`, "Known gap... Not yet fixed").

---

## Fresh findings — new features (no prior UX review)

### A. Submission audio replacement — well-considered, clear on both sides
**Contestant-facing (portal):** `includes/class-portal-panel.php:112-140`. Button/label copy adapts to state — "Finish your entry" (draft, no-audio-yet path) vs. "Wrong file? Upload a replacement" vs. "Upload a different replacement" if one's already pending review (`class-portal-panel.php:130-136`). The card also surfaces a persistent "(replacement pending review)" tag next to the vote count so a contestant checking back later isn't left guessing (`class-portal-panel.php:96`). JS gives an explicit in-progress state — button text flips to "Uploading…" (`assets/js/portal-submissions.js:82`), then shows a real success/pending message and reloads after ~1.6s so the new pending-state UI is visible (`portal-submissions.js:94-97`). This is a solid loop: no dead air, no ambiguity about whether the click did anything.

**Admin-facing:** `includes/class-admin-metaboxes.php:52-146`. Three distinct, clearly differentiated states render in the same box — normal, pending-swap (amber-bordered callout with both the currently-live and pending audio players side by side, explicit "Approve replacement"/"Discard replacement" buttons, `class-admin-metaboxes.php:75-87`), and rejected (red-bordered, read-only reason). This correctly avoids the double-submission confusion the task worried about — nothing is silently overwritten; the old file keeps playing/voting until an admin explicitly approves the swap. Good.

**One real gap:** there's no comparable "pending review" affordance on the *public voting page itself*. A voter hearing/seeing a track has no way to know a replacement is in flight for it (nor should they, arguably — but there's also no state where the *currently playing* file could silently become stale mid-session if a swap is approved while they're mid-listen; not verified either way without a live run, flagging as an open question rather than a confirmed bug).

### B. `BH_Element` extension zones (`header_extra`, `tracklist_extra`, `now_playing_extra`, `results_modal_intro`) — feels integrated, not bolted-on
Server side: `includes/class-auth.php:101-136` renders each slot via `BH_Element::render_slot()`, base64-encodes it onto a `data-*` attribute on `#bh-player-root` (deliberately outside the div player.js rebuilds — `class-auth.php:78-85`), and only emits the attribute at all when the slot actually has visible content (`class-auth.php:151-165`, `slot_has_visible_content()`), so an unused zone adds zero markup.
Client side: `player.js:322,329-331` calls `injectExtraZone()` once per zone into brand-new, purpose-built empty divs already present in `renderSkeleton()` (`player.js:167,185,211,317`) — genuinely new DOM, not a repurposed existing element.
CSS: `assets/css/player.css:115-136` gives each zone real, considered treatment — `:empty { display: none }` so an unset zone collapses cleanly (no stray gap), plus real margin/gap rules matching the surrounding rhythm (e.g. `.bh-header-extra { display: flex; align-items: center; gap: ... }`, `.bh-tracklist-extra { margin-bottom: ... }`). This is the opposite of a bolted-on block — the empty-state handling alone is a level of polish a lot of "extension point" features skip. The one open risk (self-flagged in the code's own comments, not new) is that the skeleton itself remains hand-authored JS rather than BH_Element placements, and that conversion is explicitly deferred as a separate, riskier follow-up (`includes/class-element-surface.php:12-25`) — reasonable scoping, not a UX defect today.

Also worth noting: the "Manage my submission" portal link that used to render alongside `before_player` had a real bug where `render_slot()`'s output silently overwrote it — already caught and fixed per the inline comment at `class-auth.php:101-106` (now appends rather than overwrites). Re-verified the current code does append (`$before = $submission_link . BH_Element::render_slot(...)`), so this is closed, not a live finding — flagging only because it's exactly the kind of two-features-collide bug this task was watching for, and it's fixed.

---

## Confirmed good (carried forward + newly verified)

- Contest admin list table: status pills, quick schedule actions, live stats, click-to-copy shortcode.
- Voting page: category tabs, instant switching, clear Vote/Voted button states.
- Results page/modal: medals, rank badges, category color coding.
- Submission audio replacement: distinct contestant-side and admin-side states, clear button copy per state, in-progress feedback, no silent overwrites.
- New `BH_Element` extension zones: additive-only, empty-safe CSS, real spacing rules, base64-safe server→client handoff, scoped deliberately to avoid touching the load-bearing player skeleton.
- Branding-override metabox: now fully hides the fields block when off, and the whole box starts collapsed — closes the 07-13 gap outright.

---

## Prioritized punch-list

1. **Add a persistent "votes remaining" indicator on the voting page** (still open from 07-13). Data already exists (`votes_left` in every vote API response, `class-api.php:261`); needs a small always-visible UI element (e.g. near category tabs or now-playing bar) rather than only a post-vote toast. Medium effort, real payoff — this was flagged as wanted in prior contest UX research and remains the single biggest gap in an otherwise strong voting flow.
2. **Fix the self-flagged "All results" tab mislabeling for judged/hybrid contests** (`player.js:984-1006`) — scores get shown and summed as "votes" when 2+ categories exist in a judged contest. Not new, but user-facing and already documented as unfixed in the code itself.
3. **Verify, live, whether an approved audio swap can silently go stale for someone mid-listen** on the voting page (flagged as an open question in section A, not a confirmed bug — needs a real browser session to check, out of scope for this code-only pass).
4. Lower priority / non-blocking: the player-skeleton-to-BH_Element conversion remains explicitly deferred (documented, reasoned follow-up, not a defect).
