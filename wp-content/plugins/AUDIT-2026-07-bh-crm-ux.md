# bh-crm — Magical UX Audit (2026-07-25)

**Scope:** bh-crm's user-facing surfaces — People list/detail (profile, Activity), Projects (top-level kanban board + the new nested-kanban sub-task system in `class-subtasks.php`), Segments, admin hub menu.

**Method:** Code-level read only — templates, admin render methods, JS, inline styles. **No live browser or WordPress install was available; nothing here was runtime-verified or clicked through.** All findings are inferred from markup/JS/CSS reasoning, not observed behavior. This plugin has no major UX issues on record from prior passes, so this is close to a first look, not a re-audit of known problems.

**Model:** Claude Sonnet 5.

Files read in full: `includes/class-subtasks.php` (605 lines), `assets/js/subtasks.js` (180), `assets/css/kanban-board.css` (400), `includes/class-event-activity.php` (158), `includes/class-people.php` (People list + detail, lines ~195-443), `includes/class-hub.php` (89), `assets/js/kanban-board.js` (partial, key functions), `includes/class-segments.php` (partial), `includes/class-projects.php` (grepped for structure).

---

## Deep dive: nested-kanban sub-task system (`class-subtasks.php`)

This is the primary target per the brief. Overall: genuinely more built-out than a checklist, and several details show real craft (loading-state fix, drag-handle hit-target sizing, mobile stacking, idempotent retry-with-backoff on save failures). But it has one significant rough edge and a couple of smaller inconsistencies against the top-level board it claims to mirror exactly.

### What's good
- **Full kanban at every level**, sharing the parent project's own `columns_config` rather than inventing a per-level column set — a deliberate, sensible scoping choice, documented as such (`class-subtasks.php:16-22`).
- **Visual progress rollup**, not just text — a real filled bar, both at the level being viewed and mini-variant inside each card showing that card's own children's rollup (`class-subtasks.php:220-228`, `render_card` line 330). Consistent green-for-done color language shared with `.bhcrm-kanban-card.is-done` (kanban-board.css:26-27, 217-220).
- **Breadcrumb with bounded growth** — full chain back to the card, collapsing past 5 segments to a non-dead-end "…" link (`class-subtasks.php:230-262`).
- **Inline-editable title/description that saves on blur**, matching the top-level board's own live-editable fields rather than a separate edit form (`class-subtasks.php:323-336`, wired in `subtasks.js:111-178`).
- **Save-failure handling is honest**: exponential-backoff retry (idempotent, since a full layout write is safe to repeat) before finally reloading with a visible error toast rather than silently discarding the drag the user just made (`subtasks.js:60-81`, with the fix explicitly called out in a comment as a prior regression).
- **Mobile**: the 782px breakpoint (matching WP core's own admin breakpoint) genuinely restructures the board — columns stack full-width instead of horizontal-scroll, drag handle grows to 20px/6-10px padding, action buttons get a 32px minimum touch target (kanban-board.css:361-400). This reads as actually mobile-considered, not a token media query.
- **Size warning** at 50 total nodes across the whole tree — a soft nudge ("worth considering... a separate project"), never a hard limit (`class-subtasks.php:58-61, 188-191`).
- **Bulk-add** ("one per line") matches the pattern already used elsewhere in the codebase (segment conditions), so it doesn't feel like a bolted-on one-off (`class-subtasks.php:367-387`).

### What's rough
- **Full page reload on every single drag-and-drop.** `subtasks.js:46-59` — on a *successful* reorder, the code shows a "Saved." toast then does `setTimeout(() => window.location.reload(), 600)` unconditionally. Every drag on a nested sub-task board costs a full page reload, discarding scroll position and any in-progress interaction elsewhere on the page. This directly contradicts the class's own docblock claim of reusing "the exact same visual/interaction language as the top-level project board" — the top-level board (`kanban-board.js:169-186, 238-303`) re-renders the DOM in place via `state.placements`/`render()` with zero page reload. A user who has used the parent project board will find the nested sub-task board dragging noticeably clunkier for no functional reason (the comment justifies it as needed to reflect the recursive parent-progress-bar update, but a targeted re-fetch/re-render — which the code already does for `refreshCounts()` — would avoid the full navigation).
- **Delete confirmation pattern diverges from the top-level board.** Sub-task cards use a native `confirm()` dialog (`class-subtasks.php:342`: `onsubmit="return confirm('Delete this sub-task and everything nested under it?')"`), while the top-level board uses an "arm on first click, red state, second click confirms" pattern with no native dialog (`kanban-board.js:414-438`, styled via `.bhcrm-delete-btn.is-armed` in kanban-board.css:284-286). Two different delete-confirmation UX patterns live side-by-side in what's marketed as one consistent interaction language.
- **No stalled-card indicator on sub-task boards.** The top-level board surfaces a "⚠ Nd stalled" badge per card (kanban-board.js:348-350, `.bhcrm-kanban-stalled-badge` in kanban-board.css:229-243) — a real, visible signal for cards that haven't moved. Sub-task cards (`class-subtasks.php` render_card, lines 295-352) have no equivalent, even though sub-tasks are exactly the kind of granular work item most likely to silently stall. Not necessarily a bug (may be a deliberate scope cut, but it's undocumented), but worth flagging since it undercuts the "identical interaction language" claim.
- **Every card always shows "Open board →", with no indicator of whether it actually has children.** `class-subtasks.php:339` renders the drill-down link unconditionally; the only hint that a card *has* sub-tasks is the mini progress bar, which is suppressed entirely when `$child_total === 0` (line 330: `if ($child_total > 0) self::render_progress_bar(...)`). A card with zero sub-tasks and a card with, say, 8 done sub-tasks and a filled bar both show the identical "Open board →" affordance; clicking through on an empty card lands on a blank board with only an add-form, no signal beforehand. This is exactly the "unclear nesting depth" risk flagged in the task brief — nothing on the card face distinguishes "drill down to see 6 more things" from "drill down to an empty board."
- **Column-drop-mutates-done-state is one-directional but silent about it.** Dropping a sub-task into the last (done) column auto-checks it; dragging it back out does *not* uncheck it (`class-subtasks.php:500-507`, mirrored in `kanban-board.js` reorderFromDom comment). This is a reasonable, deliberately-chosen convention and is well-documented in code, but there's no UI-facing explanation of it anywhere a user would see — someone who drags a card out of Done expecting the checkmark to clear will be surprised, and the only record of "why" lives in a PHP comment.

**Bottom line on the kanban sub-task system**: it clears the bar of "not a flat checklist" and shows real polish in several spots (loading state, mobile, save-failure handling), but the core interaction (drag-and-drop) is measurably worse on nested boards than on the parent board it explicitly claims to match — a full reload per drag is the kind of thing that reads as unpolished the moment someone actually uses it, even though nothing else about the feature is sloppy.

---

## Activity section coherence (People detail page)

`class-people.php:419-428` renders one `<h3>Activity</h3>` inside a single `.bhy-card`, then loops over every section contributed via the `bh_crm_activity_summary` filter, each rendered identically as `<h4>{plugin name}</h4><p>{summary}</p>` plus an optional expanded `render()` callback. Three known contributors currently hook this filter:
- `BHCRM_Event_Activity::contribute_summary` (bh-crm itself) — 25-event table, `includes/class-event-activity.php:106-119, 126-157`.
- `BHM_CRMIntegration::activity_summary` (bh-monetization-woo, `class-crm-integration.php:18`) — tier data.
- Ledger CRM integration (`class-ledger-crm-integration.php:19`) — wallet data.

**Assessment: structurally coherent, functionally still two silos.** The wrapping is consistent — one card, one heading, uniform per-plugin sub-heading treatment — so visually it does *not* read as two bolted-together widgets; that part of the code-quality fix (task 7) actually paid off at the UX level too. However:
- The individual sections are not merged into one chronological stream. The event table (`class-event-activity.php:133-152`) is its own `<table>` sorted by `occurred_at DESC`; the wallet/tier summaries are separate one-line `<p>` blocks with no timestamp and no interleaving with the event rows.
- There's real **redundancy**: `bhm/wallet_credit` and `bhm/wallet_debit` are both explicit types in the event-type label map (`class-event-activity.php:90-91`), meaning individual wallet transactions already appear as rows in the 25-event table — while the wallet-balance summary contributed separately by bh-monetization-woo shows a rolled-up total in its own block right below. The same underlying activity is represented twice, in two different formats, with nothing cross-referencing them.
- No filter/sort/search across the combined activity — a user scanning "everything this person did" has to read a table, then separately read prose summaries below it, mentally merging timelines themselves.

Net: not "two disconnected sections bolted together" visually, but functionally it's a container of independent read-only widgets rather than a unified feed — closer to "well-organized appendix" than "activity timeline."

---

## People list / detail — other observations

- **Good**: the People detail page was explicitly restructured to fix a confirmed layout bug — the whole page used to render as one undifferentiated block; `.bhy-card` wrapping was added around each section (Profile, Tags, Notes, Projects, Activity) per `class-people.php:383-392`'s own comment describing the before/after. This is a real, disclosed fix, not a vague claim.
- **Segments/Smart lists panel** (`class-people.php:255-296`) uses design-system tokens (`--bhy-space-*`, `--bhy-accent`, etc.) consistently and is explicitly called out in its own comment as "worth matching going forward" relative to other panels on the same page that use hand-picked pixel values — i.e., the code itself flags an internal design-system inconsistency on the very page you're auditing. Worth confirming those other panels (e.g. `render_identity_header`, `class-people.php:343-367`) get brought in line; right now the identity header uses raw hex/px values (`#eee`, `120px`, `14px` etc.) while the segments panel below it uses tokens — a visible seam if the token values differ from the hardcoded ones in a themed/dark-mode context.
- **Bulk actions** (tag/export) use one `<form>` with per-button `formaction`, a reasonable no-JS-required pattern (`class-people.php:211-219`), consistent with the "plain WP admin, no one-off cleverness" convention.
- **Segment builder** live-preview ("N people match" as conditions change) is a real, disclosed wizard-quality fix — explicitly framed as fixing "zero feedback while you built a list" (`class-segments.php:51-59`). Good adherence to the "it just works" guided-setup bar for something technical.

---

## Confirmed good

- **`.bhy-table-wrap` spot check — still correctly applied, not regressed.** Three call sites confirmed:
  - `includes/class-people.php:224` — People directory table, `bhy-table-wrap bhy-table-wrap--tall` (explicitly reasoned as warranting extra scroll room since the table *is* the whole page — good judgment call documented inline).
  - `includes/class-people.php:315` — Profile fields table.
  - `includes/class-event-activity.php:133` — Activity event-history table.
  No plain, unwrapped `wp-list-table` was found anywhere in this plugin's PHP; the wide CRM table has not regressed to bare WP list-table styling.
- Segment builder's live match-count feedback loop.
- People detail page's `.bhy-card` sectioning fix (disclosed, concrete before/after).
- Nested kanban's loading-state fix, mobile breakpoint, and save-flash acknowledgment on every silent auto-save (kanban-board.css:182-195) — closes the loop on "did that actually save," a real polish detail carried through to the sub-task board too via shared CSS.
- Save-failure handling in both `kanban-board.js`/`subtasks.js` uses honest retry-then-visible-failure, never a silent success lie.

---

## Prioritized punch-list

1. **[High] Nested sub-task board reloads the full page on every drag-drop** (`subtasks.js:59`). Replace with an in-place DOM update (mirroring `kanban-board.js`'s `render()`/`state.placements` approach) so dragging on a sub-task board feels as smooth as the parent board it's supposed to match. This is the single biggest gap between the feature's stated ambition ("Trello/Linear-style kanban") and its actual feel.
2. **[Medium] No visual signal for "this card has sub-tasks" vs "empty."** Add a lightweight badge/count next to "Open board →" (e.g. reuse the mini progress bar's total even when done=0, or show "(0)" vs "(N)") so users aren't drilling into empty boards blind (`class-subtasks.php:330-339`).
3. **[Medium] Delete-confirmation UX is inconsistent between top-level and nested boards** — native `confirm()` on sub-tasks (`class-subtasks.php:342`) vs. arm/disarm double-click on the parent board (`kanban-board.js:414-438`). Pick one pattern.
4. **[Low-Medium] Activity section duplicates wallet transactions** between the raw event table and the rolled-up wallet-summary block — either suppress `bhm/wallet_*` rows from the generic event table when the monetization plugin's own summary is present, or visually link them (e.g. a note under the event table pointing at the wallet summary above).
5. **[Low] No stalled-card badge on nested sub-task boards**, unlike the top-level board — confirm whether this is a deliberate scope cut or an oversight; if the former, no action needed, but currently undocumented.
6. **[Low] Column-drop-to-Done auto-completes, drag-out doesn't un-complete — undocumented to the user.** Consider a one-line inline hint near the Done column header (e.g. "dropping here marks a card done") since this convention is currently only explained in code comments.
7. **[Low] Design-token inconsistency within the same People detail page** — segments panel uses `--bhy-*` tokens, identity header (just above it) uses raw hex/px values. Bring the identity header in line, per the code's own "worth matching going forward" note (`class-people.php:251-254`).
