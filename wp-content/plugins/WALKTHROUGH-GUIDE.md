# Own Ur Shit — GUI/UX Walkthrough Guide

This is a screen-by-screen guide to every admin GUI in this ecosystem, ordered deliberately by build priority rather than alphabetically or by menu position. Core comes first because everything else renders through it; the Design Suite comes second because it's the tool you'll use to fix everything after it; bh-contest comes third because it's the named next conversion target; the remaining plugins follow in dependency/risk order. For each screen this covers what's actually there, how it should look and behave when it's solid, how to use it today, known pitfalls, and a concrete next step to shore it up. This supersedes the previous version of this file, which was organized by menu structure rather than by what to work on first — read `STATUS.md` at the plugins root for the fuller build-state narrative behind these notes, and `CODEBASE-WALKTHROUGH.md` for a guided tour of the underlying code if you want to go deeper than a screen-level pass.

---

# Part 1 — Core (own-ur-shit)

## The BH_Element registry and render_slot architecture

This isn't a screen, it's the foundation every screen in Part 2 onward sits on, so it comes first. `BH_Element` (own-ur-shit/includes/class-element.php) is a placement/capability/data-binding system: any plugin registers element "types" (a heading, a stat card, a kanban board, a profile field) with a schema and a renderer, and a separate `bhcore_element_placements` table records which instances of which types are placed where, with what config. `render_slot()` walks a slot's placements and renders each one through `wrap_placement_html()`, which wraps every element's output in a consistent `data-bhel-*` marked wrapper so the same node the visual builder edited is the same node that renders live, both in wp-admin and on the front end.

How it should look/behave: a plugin author calls `BH_Element::register_type()` once, and from then on that element type is placeable, stylable, and inspectable from the Design Suite canvas with zero special-cased template code anywhere else — this is the "no special-cased pages" discipline referenced throughout STATUS.md.

Pitfall (resolved, mentioned for the record): `wrap_placement_html()`'s custom-class merge path had a real bug this session — it used `trim()` to strip a wrapping quote/bracket character off a merged class string, but `trim()` treats its second argument as a *character mask*, not a literal substring, so it could silently eat legitimate leading/trailing characters from real class names that happened to share characters with the mask. This is fixed now — the merge uses `substr()` with explicit positions instead of `trim()`'s mask semantics. No further action needed here, but if you see similar "strip N characters" logic elsewhere in this file or its siblings, check whether it made the same mistake.

Shore-it-up suggestion: grep the whole ecosystem for other `trim($str, $chars)` calls where `$chars` is longer than one character — this bug class (mask vs. literal) is an easy one to reintroduce elsewhere and worth a deliberate one-time audit now that you know to look for it.

## Accounts, identity, and the shared services (Dashboard, Reports, Security)

**Dashboard — `admin.php?page=own-ur-shit`.** The install/activate console for the whole ecosystem: a card per plugin showing install/activate status, pulled from `OUS_Registry::visible_cards()`. Use it to install and activate peer plugins in order. Pitfall: one-click install for bh-registry and bh-monetization-woo depends on bundled zips actually existing on disk at `own-ur-shit/bundled/*.zip` — confirm those are present before trusting the button. Shore-up: add a visible warning on the card itself if the expected zip is missing, instead of only failing at click time.

**Reports — `admin.php?page=ous-reports`.** A shared abuse/moderation queue any plugin routes a "Report" button into; currently only bh-registry uses it. Tabs for Open/Resolved/Dismissed, rows flagged red at 3+ independent reporters. This one is complete and needs no shoring up — it's a good reference for what "done" looks like elsewhere in this codebase.

**Security — `admin.php?page=ous-security`.** One checkbox gating whether users may enable their own 2FA, plus an enrolled-count. Minimal and complete. Actual 2FA setup happens on the user's own WP profile screen, not here — worth remembering when someone asks "where do I turn on 2FA" and can't find it on this page.

## Debug Tools, API Docs, Codebase Docs

**Debug Tools — `admin.php?page=ous-debug`.** The dev/QA toolbox: environment lock banner (blocks seed/reset actions on anything WordPress calls "production" unless `OUS_DEBUG_TOOLS_FORCE` is defined), jump-links to in-page API Docs/Codebase Docs sections, and grouped debug-tool sections from the `ous_debug_tools` filter. This is the reliable path to API/Codebase docs content.

**API Docs / Codebase Docs standalone pages — `ous-api-docs` / `ous-codebase-docs`.** Same content as the Debug Tools anchors, but as their own top-level menu entries, and the code's own comments flag them as unreliable — WordPress has, on this install, consistently blocked them for reasons never fully root-caused despite registration and capability both being confirmed correct. Pitfall: don't assume these standalone links work; test them directly before depending on them for anything. Shore-up: either fix the underlying registration bug (worth a dedicated session comparing this against BHI_Portal's documented rewrite-timing bug — they may share a root cause class) or just remove the standalone menu entries entirely and keep only the Debug Tools anchors, since a known-unreliable duplicate of a working page is worse than no duplicate.

## Portal (`/account/`)

The branded front-end account shell, built from panels contributed via `bhi_portal_panels`. Five panels registered today: Profile, Notifications, Contest Submissions, My Courses, Membership & Wallet. How it should look: a left nav listing every registered panel, a main area rendering the active one, URLs like `/account/{panel-id}/`. Pitfall: this class's own code documents a previously-shipped rewrite-registration bug (re-hooking `init` from inside `init` at the same priority never fires) — the fix is in place, but given this codebase's repeated history of standalone-page registration bugs, confirm `/account/` isn't 404ing before assuming it's fine. Shore-up: add an automated Test Runner suite entry (see class-test-runner.php) that hits `/account/` and asserts a 200, so this class of regression gets caught automatically instead of by chance.

---

# Part 2 — Design Suite / style-builder GUI

This is the highest-priority screen to get solid next, because it's the tool that will be used to convert bh-contest and every future surface — flaws here get multiplied across every future conversion.

## The unified shell — `admin.php?page=bh-design`

Two tabs on one page, switched with plain JS, no reload: "Site Styles" (the original token editor — surface picker, live canvas preview, controls) and "Widgets & Elements" (the Element Builder's three-pane palette/canvas/inspector). Both tabs read and write the same underlying data (`bhcore_element_placements`, `BHY_Style` tokens) that the REST API uses — there's no separate storage between the visual tool and anything list-based. How it should look: instant tab switching, a canvas that reflects live edits without a full page reload, an inspector pane that always matches what's selected on canvas.

Pitfall: the canvas was recently converted from an `<iframe>` to a same-document `<div>` with `attachShadow()` — this is current architecture, not a stale claim; if you see references anywhere (old docs, comments, your own memory of earlier sessions) describing the canvas as an iframe, that's out of date. The shadow-DOM approach was chosen specifically per the standing "never use iframes unless truly necessary" rule, and it means canvas styles are scoped without the cross-origin/messaging overhead an iframe would need. Worth a direct smoke test that shadow-DOM style isolation is actually behaving (no site-wide CSS leaking in, no canvas CSS leaking out) since this is a recent, not-yet-heavily-battle-tested change.

Also flagged in code comments as never runtime-verified end to end — worth smoke-testing as a non-admin editor holding only `bhcore_design_site`, not as full admin, since `manage_options` can mask capability-scoping bugs.

## Site Styles tab

Pick a surface from the sidebar, edit its design tokens (colors, fonts, spacing) with live preview in the canvas. This is the older, more mature half of the page and is the one to trust most. Real UX convention lives here: `BHY_UI::swatch_field()` for color pickers, `.bhel-style-group` for grouped style controls — visually consistent, and this is the standard the rest of the builder should be held to.

## Widgets & Elements tab — the real placement inspector

Two clicks from the palette into an actual placement's inspector, this is where you configure a real `BH_Element` instance — style controls here correctly use `BHY_UI::swatch_field()`/`.bhel-style-group`, matching the Site Styles tab's visual language. This is the reference implementation to copy from.

## The demo-only Live View outline/style panel — element-builder.js `renderDemoOutline()`

Pitfall (open, confirmed this session): this panel — reached from within the same Widgets & Elements tab, a couple of clicks away from the real placement inspector above — renders its color and text controls with raw `<input type=color>` and `<textarea>` elements instead of reusing `BHY_UI::swatch_field()`/`.bhel-style-group`. The result is a visible, confusing inconsistency: two panels in the same tool, a couple of clicks apart, that look and behave differently for the same kind of control. A user has no way to know from the UI alone that one is "the real thing" and one is a demo — they look like two competing designs.

Shore-up: this is a contained, mechanical fix — swap the raw `<input type=color>`/`<textarea>` markup in `renderDemoOutline()` for calls into the same `BHY_UI` helpers the real inspector uses, or better, delete the demo-only path entirely if it no longer serves a purpose distinct from the real inspector. Do this before starting the bh-contest conversion below, since bh-contest's new surface will be built and QA'd through this exact tool — a confusing builder UI will make that conversion harder to verify correctly.

---

# Part 3 — bh-contest (top priority conversion target)

## Current state: hardcoded mockup catalog, not a real surface

bh-contest's catalog preview registers itself via `bhy_style_surfaces` (`bh-contest/includes/class-style-surfaces.php`) — but the actual preview markup is hand-written HTML with fake/sample data, not something backed by real `BH_Element` placements or real contest data. This is true across bh-contest, bh-streaming, and bh-courses' catalog previews, but bh-contest is the one explicitly named as next to fix — it's the ecosystem's single biggest architectural gap right now: what looks like an editable, live surface in the Design Suite is actually a static mockup that can't be meaningfully edited or bound to real data.

How it should look once converted: a real `BH_Element`-backed surface, the same way bh-crm's profile page and bh-courses' lesson pages already are — registered element types, real placements stored in `bhcore_element_placements`, rendered through `render_slot()`/`wrap_placement_html()` with actual contest/submission data bound in, editable live from the Design Suite's Widgets & Elements tab exactly like a CRM profile field is today.

Reference pattern to follow: `bh-crm/includes/class-style-surface.php` (CRM profile page conversion) and `bh-courses/includes/class-lesson-surface.php` (LMS lesson conversion) are the two prior, completed examples of exactly this migration. Read both alongside `own-ur-shit/includes/class-element.php`'s `register_type()`/`render_slot()` before starting — the shape of the conversion (register element types for each meaningful piece of a contest card/results view, replace the hardcoded HTML in `class-style-surfaces.php` with real placements, wire real contest data into the render callbacks) should closely mirror what those two files already did.

Shore-up suggestion: don't touch bh-streaming's or bh-courses' catalog mockups yet — do bh-contest first, end to end, verified in the (now-fixed) Design Suite, and treat it as the template for converting the other two afterward. Trying to convert all three mockup surfaces at once risks discovering a builder gap partway through and having to redo work in three places instead of one.

## Contests / Submissions / Results / Live Console (existing, stable admin screens — not part of the conversion)

These are ordinary CPT list-tables and dedicated admin pages, not style-surface mockups, and are unaffected by the above. Contests and Submissions are enhanced list-tables (status pill, shortcode, stats). Results (`admin.php?page=bh-results`) and Live Console (`admin.php?page=bh-console`) are separate, deliberately-unlinked pages — Live Console shows real contestant contact info and must never be discoverable from anything an OBS capture might show. A previously real bug is already fixed and hardened against: the Live Console's contest-picker form must carry `post_type=bh_contest` as a hidden field or WordPress can't resolve the submenu. No action needed here beyond normal regression awareness.

---

# Part 4 — bh-crm and bh-courses (already converted, lower risk)

## bh-crm — People / profile page

Already converted to a real `BH_Element`-backed surface (the reference pattern for Part 3). The People list (`admin.php?page=bh-crm-hub`) is a straightforward roster with tag filters, search, and CSV export; clicking a name opens the live-rendered profile detail view, editable from the Design Suite the same way any other real surface is. Known gap, not a GUI bug: bh-crm isn't wired to `bhcore_events` yet, so the Activity section on a profile doesn't include pre-signup event history — this is a data-completeness gap, not a rendering one, and is explicitly next on bh-crm's own list rather than an oversight.

**Project Tracker — `admin.php?page=bh-crm-projects`.** A real kanban board system built entirely on `BH_Element`, listing every project across all people with a link into that person's actual board. Solid, no rendering pitfalls identified. Feature-completeness gap (documented elsewhere, not a bug): reusable checklists, timestamped fixes, a feedback log, stall analytics, and file-linking are all designed in `PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md` but not built — worth knowing before promising parity with a tool like TrackIt.

## bh-courses — Lessons and Student Progress

Lesson authoring moved off a fixed four-step-type metabox form onto the `BH_Content`/`BH_Studio` block canvas, and lessons render through `class-lesson-surface.php` — the second reference conversion alongside bh-crm's profile page. Student Progress (`admin.php?page=bhc-progress`) replaced an earlier version that could only show one seeded student — confirm its `bhcore_manage_students` capability fallback actually restricts access correctly for a non-admin instructor account, since that's the one part of this screen not fully verified. The course *catalog* preview, unlike the lesson page, is still the hardcoded mockup described in Part 3 — don't confuse "lessons are converted" with "the catalog is converted"; they're different surfaces at different states.

---

# Part 5 — bh-streaming and bh-monetization-woo (last)

## bh-streaming

Its catalog/library preview is the same kind of hardcoded mockup surface as bh-contest's, and should follow the same conversion pattern once bh-contest's conversion is proven out — not before. Separately, and unrelated to the style-surface work: the entire public player and most of its CPT admin submenus (Releases, Playlists, Feed Sources, Genres) vanish entirely whenever `wp_get_environment_type()` reports (or can't determine) anything other than local/development/staging, unless `BHS_FORCE_VISIBLE` is explicitly defined in `wp-config.php`. This is a real, confirmed-in-code gate, not a hypothetical — check this site's actual environment type and that constant before assuming streaming is visible to anyone. Metrics (`admin.php?page=bhs-metrics`) is a real, working dashboard (plays, skip-rate, a country breakdown approximated from `Accept-Language`, explicitly not real GeoIP) — no action needed there beyond knowing the country data is a rough signal, not precise.

## bh-monetization-woo

Monetization Settings (`admin.php?page=bhm-settings`) is a thin, mostly-complete status screen — warns if WooCommerce isn't active, otherwise reports Subscriptions status. Public storefront shortcodes (`[bhm_tiers]`, `[bhm_tip_jar]`, `[bhm_wallet]`) are implemented but checkout completion, refunds, and the fraud-pattern flagging haven't been traced through a live purchase — worth an actual test transaction rather than trusting the code read alone. This plugin is last in this guide's priority order because it has no style-surface mockup problem to fix and is inert without WooCommerce installed — nothing here blocks or depends on the Part 1–3 work above.

---

# Cross-cutting notes worth keeping in view

Two menu items point at identical rendered output in two places (Design Suite/Designer both call `BHY_Gallery::render()`; CRM/People both call `BHCRM_People::render()`) — cosmetic redundancy, not a bug, but worth pruning once the higher-priority work above is done so QA passes aren't second-guessing whether a duplicate nav entry is a mistake. Registry Submissions and Monetization Settings both land under the "Own Ur Shit" menu rather than anything registry- or commerce-branded, because their `admin_menus` entries don't set a `parent` key — a discoverability issue worth a one-line fix whenever convenient, not urgent. Content Studio (`bh-studio`) has no menu entry by design and is meant to open as a modal from the Design Suite's Widgets & Elements tab — confirm that launcher still works given how much the Design Suite canvas has changed recently (iframe-to-shadow-DOM), since that's exactly the kind of change that could quietly break a modal launcher without anyone noticing until they go looking for it.
