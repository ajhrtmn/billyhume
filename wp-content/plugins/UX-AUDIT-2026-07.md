# UX Audit — ecosystem-wide walkthrough, 2026-07-13

Live walkthrough of admin screens and front-end surfaces across own-ur-shit, bh-contest, bh-crm, bh-courses, bh-monetization-woo, and bh-streaming, using a UX/accessibility reference synthesized from Nielsen Norman Group's 10 usability heuristics, WP-native admin patterns (WooCommerce/Jetpack as baseline), Trello/Linear kanban conventions, Udemy/Coursera course-player conventions, SaaS pricing-page conventions, and WebAIM's "Million" accessibility audit findings. Every finding below was actually seen live (screenshotted, in several cases confirmed against source or the database), not inferred from code alone — this is a real walkthrough, not a code review.

**How to read this doc:** findings are grouped by surface, each with what was actually observed and which heuristic it violates or satisfies. A "Priority" build-order section at the end turns this into an actionable plan, matching the style of `ROADMAP-ux-polish-and-feature-parity-2026-07.md`.

---

## 1. Ecosystem hub (`admin.php?page=own-ur-shit`)

**What's there:** a clean card grid, one card per plugin, green "Active" badge, version number, "Open →" CTA. Genuinely solid first impression — no clutter, clear activation status.

**Gap (heuristic #6, recognition over recall; #1, visibility of system status):** every card shows *activation* status only, never *health* or *activity* status. There's no way to tell from this one screen whether a contest is live right now, whether the job queue has a backlog, or how many people are in the CRM — an admin has to click into each plugin to find out. A real dashboard opportunity: this is exactly the page that should surface "3 active contests, 1 voting closes today," "12 people, 2 tagged 'superfan'," "1 course, 0 lessons published" — turning six identical-looking cards into a screen that actually tells you something changed since you last looked.

---

## 2. Debug Tools (`admin.php?page=ous-debug`)

**What's there:** a grouped jump-nav (Monitoring & Health / Reference & Docs / Seed & Reset Tools) above collapsed accordion sections. Genuinely good information architecture for a page that's accumulated a lot of tools over many sessions — the grouping keeps it scannable instead of one long undifferentiated list.

**No real findings here** — this is one of the better-designed screens in the whole ecosystem. Worth treating as the reference pattern when any other "many tools on one page" screen needs restructuring.

---

## 3. Design Suite (`admin.php?page=bh-design`)

**Finding A — confirmed bug, isolated to this screen:** the Results preview surface renders the medal emoji (🥇/🥈/🥉) as garbled mojibake. Checked the actual PHP source (`bh-contest/includes/class-archive.php:70`) — the real UTF-8 emoji characters are correct in code. Then checked the REAL front-end Results page (see Section 6) — it renders correctly there, using a different icon (a ribbon/flag icon with a numbered badge, not the raw emoji). **This means the bug is specific to however Design Suite's preview surface (likely its shadow-DOM/iframe injection path) displays raw text content** — a charset-declaration gap in that one rendering path, not a data bug and not a front-end bug. Low urgency (admin-only preview, not customer-facing) but worth a quick fix since it undermines trust in the preview as an accurate representation of the real page.

**Finding B — low-contrast form inputs, confirmed live in production (not just this preview):** every text input in the Sign Up/Submit preview uses a beige/tan background with low-contrast tan-gray placeholder text. Initially assumed this was just preview-mockup styling — but the same beige-on-beige input styling appears on the REAL, live front-end course-catalog search box and streaming search box (Sections 4 and 7). This is the actual site-wide design token in production, not a mockup artifact — a real, likely WCAG-contrast-failing pattern (per the accessibility reference's #1 most common real-world violation type) affecting every search/filter input across the whole ecosystem, not a one-off.

**Finding C — stray, unexplained content:** a red bar reading "rety78" sits in the CRM profile preview with no visible label, source, or management affordance from the preview itself. Almost certainly leftover test data from an earlier session, but it's a good illustration of a smaller pattern worth naming: the live-preview surfaces don't currently make it obvious how to identify/edit/remove an individual placement from within the preview itself — you have to already know where it lives.

---

## 4. Contest admin (post list + edit screen)

**Contest list** (`edit.php?post_type=bh_contest`)**:** genuinely good — real WP_List_Table conventions, per-contest Submit/Vote status pills ("Open"/"Live now" language, not raw post_status), a one-click "shortcode to clipboard" column, live sub/vote counts inline, an "End now" action link. This is close to the WooCommerce Orders-table baseline the reference doc holds up as the standard to hit, and it clears it.

**Contest edit screen:** functional, classic-metabox styling (not a design complaint on its own — this ecosystem deliberately defaults to plain WP admin styling per its own stated convention). One thing worth a deliberate look rather than a fix-now: the "Contest Branding & Style" metabox's override fields are correctly `display:none` until the override checkbox is checked (verified in source, `class-admin.php:953` — not a bug), but the metabox gives very little visual weight to its own "off" state, which combined with a long page of metaboxes made it easy to misread as broken content on first glance. A one-line visual treatment (e.g., a lighter background on the collapsed/off state) would remove that ambiguity.

---

## 5. Contest front end — voting page

**What's there:** category tabs, per-track vote buttons with a clear "Voted" state once cast, a persistent mini-player pinned at the bottom of the viewport. This is a genuinely well-built listen-then-vote flow — better than several of the WordPress-specific competitor plugins surveyed in last week's research pass.

**Gap (heuristic #5, error prevention; matches the contest-research pass's own recommendation):** no visible "votes remaining" counter anywhere on the voting page. A voter has no way to know how many votes they have left in a category before or after casting one — they find out only by continuing to click "Vote" until it stops working. This was already flagged as a wanted improvement in the contest UX research; confirmed live here as a real, currently-missing element, not a hypothetical.

---

## 6. Contest front end — Results

**What's there:** a genuinely good real-world rendering — top 3 get a distinct ribbon/flag icon with a colored numbered badge, ranks 4+ get a plain "#N," category pills next to each entry, vote counts visible. This directly contradicts Section 3 Finding A above — the REAL page has no encoding problem at all, reinforcing that the mojibake bug is confined to Design Suite's own preview rendering.

---

## 7. LMS front end — course catalog

**What's there:** search box, sort dropdown, filter button — reasonable, minimal catalog chrome.

**Finding — bare empty state (heuristic #7 core violation):** with zero courses published, the page shows only "No courses found yet." — no explanation of whether this is because no courses exist at all vs. a filter excluding everything, and no call-to-action (not even a logged-in-admin-only "create your first course" prompt). This is the textbook bad-empty-state pattern the UX reference calls out by name.

---

## 8. Storefront (`/shop/`)

**What's there:** WooCommerce's own native empty state — "No results found. You can try clearing any filters or head to our store's home," both with real links. This is WooCommerce's own default, not custom code from this ecosystem, but it's worth citing directly against Section 7's finding: **the dependency already in this stack solves the empty-state problem better than this ecosystem's own catalog code does.** That's a concrete, low-effort target — the fix isn't "invent good empty-state copy," it's "match a pattern already sitting one plugin away."

---

## 9. Streaming front end (`/streaming/`)

**What's there:** a well-structured tabbed layout (All Tracks / Releases / Liked Songs / My Playlists), search + genre filter, a prominent "Import my music" CTA. Good bones.

**Finding — same bare empty state, a third time:** with no tracks imported, the list area shows only "No tracks match." — again no distinction between "you haven't imported anything yet" and "your filter/search matched nothing," and no repeated CTA at the point of the empty list itself (the "Import my music" button exists above, but the empty message doesn't point back to it). **This is now the third surface (courses catalog, streaming library, and by pattern-matching likely others not yet walked) showing the identical bare-empty-state gap** — this has crossed from "one screen's oversight" to "a systemic pattern worth fixing once, centrally, rather than three-plus times separately."

---

## 10. Cross-cutting patterns (seen more than once)

1. **Bare empty states, ecosystem-wide.** Courses catalog, streaming library, and by strong implication other list/catalog views not yet walked (CRM smart-lists once built, contest archive with no results, tier grid with no tiers) likely share the same gap. **This is the single highest-leverage UX fix available** — one shared, reusable "empty state" component/pattern (a message that names the specific cause + an actionable next step, matching WooCommerce's own default one plugin away) would fix this everywhere at once rather than needing N separate bespoke fixes. This is exactly the kind of shared, documented `BHY_UI` pattern this codebase's own conventions already call for (see `.bhy-table-wrap`'s precedent).
2. **Low-contrast beige-on-beige form inputs, site-wide.** Confirmed live in production on at least two unrelated front-end surfaces (course catalog search, streaming search) plus the Design Suite preview forms. Worth an actual contrast-ratio check against the real computed colors (not just eyeballing) before deciding whether this needs a token change — but it's a real, repeated pattern, not a one-off styling choice on a single page.
3. **No live status/health signal on the ecosystem hub.** Every plugin card shows activation state only; none show activity/health at a glance.
4. **Good, consistent list-table discipline where it's been applied.** The contest post list and Debug Tools' grouped accordion are both genuinely strong, WP-native, low-clutter patterns — worth treating as the ecosystem's own internal reference/baseline for any screen that needs restructuring, rather than reaching outside for a pattern that already exists in-house.

---

## 11. Suggested priority order

1. **Build one shared empty-state pattern** (a `BHY_UI` helper: icon/message/CTA, parameterized by "true zero-data" vs. "filtered to zero" state) and retrofit the courses catalog and streaming library onto it. Highest leverage, fixes the most-repeated finding at the root.
2. **Add a "votes remaining" indicator to the contest voting page.** Small, isolated, already an independently-confirmed want from last week's contest research — this doc adds live confirmation it's actually missing.
3. **Check real contrast ratios on the beige/tan input styling** against WCAG 4.5:1 and adjust the design tokens if it fails — affects multiple production surfaces at once since it's a shared token, not per-page CSS.
4. **Fix the Design Suite Results-preview mojibake.** Low urgency (admin-only, not customer-facing), but cheap once someone's in that file, and it currently makes the preview lie about what the real page looks like.
5. **Add live activity/health stats to the ecosystem hub cards** — a bigger, more speculative item (needs a per-plugin "status snippet" contract, similar in shape to the existing `bh_studio_block_types`/`ous_debug_tools` filter pattern), worth scoping as its own small design pass rather than a quick fix.

Not included in this pass: bh-crm person detail, bh-monetization tier/pricing pages, bh-registry admin, and the BHI_Portal account shell were not reached in this walkthrough (time-boxed) — worth a follow-up pass using this same reference before considering the ecosystem-wide review complete.
