# Own Ur Shit Ecosystem — Walkthrough / QA Guide

This is a screen-by-screen guide to what actually exists in the codebase right now, written for AJ to click through and verify. Every claim below is grounded in the render code, not in the design docs' aspirational language — where a design doc (VISION.md, DESIGN-SUITE-UNIFICATION-PLAN.md, ELEMENT-BUILDER-DESIGN-PLAN.md, ROADMAP-platform-evolution.md) says something is "done," this guide only repeats that if the actual `render()`/callback code backs it up. Anything not runtime-verified is called out explicitly.

## The seven plugins, one sentence each

- **own-ur-shit** — the core hub: activation dashboard, shared design system/style tokens, portal shell, reports queue, 2FA, debug tools, and the menu-merge plumbing that lets the other plugins plug into one admin surface.
- **bh-contest** — music contest voting: submissions, live reveal, results, a private live-console for running a stream.
- **bh-courses** — a small LMS: ordered multi-step lessons (text/image/quiz), progress tracking, optional supporter-tier gating.
- **bh-crm** — a person directory built on WordPress users: profiles, tags, notes, activity summaries, and a project/kanban tracker.
- **bh-monetization-woo** — WooCommerce-backed supporter tiers, tips, and a play-credit wallet; inert until WooCommerce is installed.
- **bh-registry** — a public, self-serve, domain-verified directory of artist ActivityPub/RSS links, with an admin abuse-review queue.
- **bh-streaming** — the artist's own streaming player/library, shared "Jam" listening sessions, and a metrics dashboard — **currently hidden on any environment WordPress calls "production"** (see Known Gaps below).

## Menu map (as it actually renders)

- **Own Ur Shit** (`admin.php?page=own-ur-shit`, top-level, `own-ur-shit/includes/class-dashboard.php`)
  - Dashboard (relabeled first item, same page/slug)
  - Reports (`ous-reports`, `own-ur-shit/includes/class-reports.php`, class `BHI_Reports`)
  - Security (`ous-security`, `own-ur-shit/includes/class-two-factor.php`)
  - Registry Submissions (`bh-registry-review`, merged in from `bh-registry/includes/class-admin.php` via the registry mechanism — no explicit `parent`, so it defaults here)
  - Monetization Settings (`bhm-settings`, merged in from `bh-monetization-woo/includes/class-admin.php`, also no explicit `parent`)
- **Design Suite** (`admin.php?page=bh-design`, top-level, `own-ur-shit/includes/class-design-suite.php`, callback is `BHY_Gallery::render()`)
  - Design Suite (relabeled first item, same page — a genuine tabbed unified shell: "Site Styles" + "Widgets & Elements")
  - Designer (`bh-style`, `own-ur-shit/includes/class-style-gallery.php`) — **renders the exact same `BHY_Gallery::render()` output as the top-level page above**
- **CRM** (`admin.php?page=bh-crm-hub`, top-level, `bh-crm/includes/class-hub.php`, callback is `BHCRM_People::render()`)
  - People (relabeled first item, same page)
  - Project Tracker (`bh-crm-projects`, merged in from `bh-crm/includes/class-registry.php`, `BHCRM_Projects::render_boards()`)
- **OUS Debug** (`admin.php?page=ous-debug`, top-level, position 99 — bottom of nav — `own-ur-shit/includes/class-debug.php`)
  - Debug Tools (relabeled first item, same page — hosts an in-page "API Docs" section and "Codebase Docs" section via anchor links)
  - API Docs (`ous-api-docs`, `own-ur-shit/includes/class-api-docs.php`) — standalone page, **documented as unreliable in code comments**, see gaps
  - Codebase Docs (`ous-codebase-docs`, `own-ur-shit/includes/class-codebase-docs.php`) — same caveat
- **OUS · Contest** (top-level CPT menu, `edit.php?post_type=bh_contest`, `bh-contest/includes/class-post-types.php`)
  - Contests (the CPT list-table)
  - Submissions (CPT list-table, `bh_submission`)
  - Results (`bh-results`, `bh-contest/includes/class-admin.php`)
  - Live Console (`bh-console`, `bh-contest/includes/class-console.php`) — private, deliberately not linked anywhere else (shows contestants' real contact info)
- **OUS · Courses** (top-level CPT menu, `edit.php?post_type=bh_course`, `bh-courses/includes/class-post-types.php`)
  - Courses (CPT list-table)
  - Lessons (CPT list-table, `bh_lesson`)
  - Student Progress (`bhc-progress`, `bh-courses/includes/class-progress-admin.php`)
- **OUS · Streaming** (top-level CPT menu, `edit.php?post_type=bhs_track`, `bh-streaming/includes/class-post-types.php`)
  - Tracks (CPT list-table)
  - Releases, Playlists, Feed Sources, Genres (CPT/taxonomy list-tables — hidden entirely if `BHS_Env::hidden_in_production()` is true)
  - Metrics (`bhs-metrics`, `bh-streaming/includes/class-stats.php`)
- **Content Studio** (`bh-studio`, hidden — registered with a `null` parent, `own-ur-shit/includes/class-studio.php`) — reachable only by direct link or, per current code comments, as a modal iframe opened from inside the Design Suite "Widgets & Elements" tab. Not in any nav.
- **Element Builder** (`bh-element-builder`) — **no longer has its own menu entry at all.** Its `add_menu()` hook is commented out in `own-ur-shit/own-ur-shit.php`; its UI (`BH_Element_Builder::render_shell()`) is now inlined as the "Widgets & Elements" tab inside the Design Suite page instead.

## Organizational concerns worth your attention

1. **Design Suite and Designer are two menu items rendering the identical page.** `BH_Design_Suite::add_menu()` points the top-level "Design Suite" entry at `BHY_Gallery::render()`; `BHY_Gallery::add_menu()` separately registers a "Designer" submenu (`bh-style`) under the same parent, also calling `BHY_Gallery::render()`. Clicking either one lands you on the exact same tabbed page (Site Styles / Widgets & Elements). This isn't a bug that breaks anything, but it is a redundant nav item worth pruning or relabeling so it doesn't look like a mistake during QA.

2. **CRM has the same pattern.** The "CRM" top-level entry and the "People" submenu both call `BHCRM_People::render()`. Same non-breaking redundancy.

3. **"Registry Submissions" lives under Own Ur Shit, not under any Registry-branded menu.** `bh-registry`'s `admin_menus` entry (`bh-registry/includes/class-admin.php`) doesn't set a `parent` key, so `OUS_MenuMerge::merge()` defaults it to `own-ur-shit`. Someone looking to moderate registry submissions would reasonably look for a "Registry" menu (there isn't one — the registry's only public surface is a `[bh_registry]` shortcode) and instead has to know to check under "Own Ur Shit." Same is true of bh-monetization-woo's "Monetization Settings" — it also has no `parent` key and lands under Own Ur Shit rather than anywhere WooCommerce-adjacent.

4. **Content Studio is unreachable from any menu.** It registers with a `null` parent specifically so it doesn't show in nav; current code says it's meant to open as a modal from the Design Suite, but if that wiring is broken or removed, there is no way to find this page except by typing `admin.php?page=bh-studio` directly. Worth confirming during QA that the modal launch actually works from the Widgets & Elements tab.

5. **API Docs and Codebase Docs' standalone pages are called out in their own code comments as unreliable.** `own-ur-shit/includes/class-debug.php`'s `render()` includes an explicit note: "a live bug report showed WordPress consistently blocking them for reasons this session could not fully root-cause even with registration and capability both confirmed correct." The workaround shipped is to view the same content as anchor-linked sections inside the Debug Tools page instead (`#ous-section-api-docs`, `#ous-section-codebase-docs`). Standalone menu entries are still registered and still there to click, so this is worth testing directly: try both the submenu links and the anchors and see which actually works on this install.

6. **bh-streaming's entire public player renders nothing at all in a "production" environment**, and its CPT submenus (Releases, Playlists, Feed Sources, Genres) are similarly hidden. See Known Gaps under the Streaming section for detail — this is a real, current, code-level gate, not a hypothetical.

---

# Admin pages, one by one

## Own Ur Shit dashboard — `admin.php?page=own-ur-shit`

**File/class:** `own-ur-shit/includes/class-dashboard.php`, `OUS_Dashboard::render()`.

**What it's for:** the single install/activate console for the whole ecosystem — a card per plugin (`bh-crm`, `bh-contest`, `bh-streaming`, `bh-courses`, `bh-registry`, `bh-monetization-woo`, plus WooCommerce and Advanced Media Offloader as dependencies), each showing install/activate status.

**What you should see:** an "Install & Activate Everything" button (only shown if more than one plugin still needs action), a grid of `.ous-cards` — one per registered plugin from `OUS_Registry::visible_cards()` — and, below that, an "Other detected plugins" section for anything that merely declares `Ecosystem: Own Ur Shit` in its header without using the richer registration filter.

**How it's used:** click a card's Install button (pulls from a bundled zip or, for WooCommerce/Advanced Media Offloader, live from WordPress.org via `install_from_wporg()`), then Activate. Status/error banners appear via `?ous_activated`, `?ous_installed`, `?ous_error` query args.

**Gaps:** relies on `bundled_zip` files actually existing on disk at `own-ur-shit/bundled/*.zip` for the one-click install to work for `bh-registry` and `bh-monetization-woo` specifically — the code comments in both plugins' `class-admin.php`/`class-registry.php` flag this as a real precondition, not guaranteed. Worth confirming those zips are present before relying on the Install buttons.

## Reports — `admin.php?page=ous-reports`

**File/class:** `own-ur-shit/includes/class-reports.php`, class `BHI_Reports` (note: file/menu label say "Reports" but the class itself is prefixed `BHI_`, not `OUS_` — worth knowing if you're hunting for it in code).

**What it's for:** one shared abuse/moderation queue any plugin's content can route a "Report" button into. Currently wired up by bh-registry (`registry_artist` reports, labeled via the `bhi_report_target_label` filter in `bh-registry/includes/class-admin.php`).

**What you should see:** Open / Resolved / Dismissed tabs, a `wp-list-table`-style table (When, Reporter, Target, Category, Reason, Action), with rows highlighted red if 3+ independent reporters flagged the same target. "Mark actioned" and "Dismiss" links per row.

**How it's used:** review open reports, click through to investigate the flagged target (label is generated per-type, e.g. "Registry artist: Name (#12)"), then mark actioned or dismiss. Nothing here auto-hides content — it's a queue for a human decision, by design.

**Gaps:** none identified in the render path itself; it's a straightforward, fully-implemented CRUD queue.

## Security — `admin.php?page=ous-security`

**File/class:** `own-ur-shit/includes/class-two-factor.php`, `render_settings_page()`.

**What it's for:** a single site-wide toggle for whether users are *allowed* to enable two-factor authentication on their own profile (2FA itself is opt-in per-user, never forced).

**What you should see:** one checkbox ("Allow users to enable two-factor authentication on this site"), a count of currently-enrolled accounts, and a Save button.

**How it's used:** flip the checkbox, save. Actual 2FA setup per user happens on their own WordPress profile screen (not covered by this page).

**Gaps:** none identified — this is a minimal, complete settings screen.

## Registry Submissions — `admin.php?page=bh-registry-review`

**File/class:** `bh-registry/includes/class-admin.php`, `BHR_Admin::render()` (not directly hooked — reached only via the `OUS_MenuMerge` relocation described above).

**What it's for:** review queue for the public, self-serve artist link registry. Verification/activation happens automatically on domain-ownership proof; this page is for *abuse handling* after the fact, not a gate before a link goes live.

**What you should see:** a searchable/sortable `wp-list-table` of artist submissions with reject/restore/delete/reverify actions (per the task brief's existing description — confirmed by reading the class).

**How it's used:** monitor pending/failed verifications, manually reverify or reject entries that verify but are spam/abuse.

**Gaps:** the "Registry Submissions" label under "Own Ur Shit" navigation-discoverability issue flagged above in Organizational Concerns.

## Monetization Settings — `admin.php?page=bhm-settings`

**File/class:** `bh-monetization-woo/includes/class-admin.php`, `BHM_Admin::render()`.

**What it's for:** the one settings screen that exists whether or not WooCommerce is installed yet.

**What you should see:** if WooCommerce isn't active, a warning notice pointing back to the Own Ur Shit dashboard's "Install from WordPress.org" button and nothing else actionable. If WooCommerce is active, a status line noting whether WooCommerce Subscriptions is also active (supporter tiers bill as true recurring subscriptions) or not (tiers fall back to one-time 30-day access).

**How it's used:** install WooCommerce first via the main dashboard, then return here to confirm status and (per the rest of the file, not fully read in this pass) configure tiers/settings.

**Gaps:** entirely inert without WooCommerce — by design, not a bug, but worth confirming during QA that nothing here errors before WooCommerce is present.

## Design Suite — `admin.php?page=bh-design`

**File/class:** `own-ur-shit/includes/class-design-suite.php` registers the menu; the actual rendering is `BHY_Gallery::render()` in `own-ur-shit/includes/class-style-gallery.php`.

**What it's for:** the real, current unified design surface — a single page with two tabs, switched by plain JS with no page reload: "Site Styles" (the original style-token editor — sidebar of surfaces, live canvas preview, controls) and "Widgets & Elements" (the Element Builder's three-pane palette/canvas/inspector, inlined via `BH_Element_Builder::render_shell()`).

**What you should see:** an `h1` "Design Suite," a `.bhy-tabs` tab-switcher with two buttons, a `.bhy-tab-panel` for each tab. The Widgets & Elements panel mounts a JS app into `#bhel-app` (shows "Loading Element Builder…" until JS initializes) and explicitly notes it reads/writes the same `bhcore_element_placements` data as the underlying REST API — there is no separate storage between the visual builder and any list-based tool.

**How it's used:** pick a surface on the Site Styles tab to edit its tokens (colors, fonts, spacing) live in the canvas; switch to Widgets & Elements to place/configure UI elements visually.

**Gaps:** the class's own docblock states this is **not runtime-verified** — no live browser/PHP execution was available when this was built, and this install has "a documented, multi-session, never-fully-root-caused history with exactly this class of bug" (referring to standalone-page registration issues elsewhere in this codebase, e.g. API Docs). The docblock explicitly recommends smoke-testing this menu logged in as a non-admin "editor" role holding only `bhcore_design_site`, not as a full admin, since `manage_options` can mask capability-scoping bugs. Also see Organizational Concern #1 — "Designer" is a fully redundant second entry to the same page.

## Designer — `admin.php?page=bh-style`

Same file, same `BHY_Gallery::render()` as Design Suite above — this is not a distinct page, see Organizational Concern #1. No separate walkthrough needed; whatever you verify on Design Suite covers this too.

## CRM — `admin.php?page=bh-crm-hub`

**File/class:** `bh-crm/includes/class-hub.php` registers the top-level menu; renders via `BHCRM_People::render()` in `bh-crm/includes/class-people.php`.

**What it's for:** a person directory built from WordPress users who have either a CRM profile or recorded activity — no separate "contact" data model, it enriches real user accounts.

**What you should see (list view, no `user_id` in the query string):** a description line, a tag-filter row (built from `BHCRM_Tags::all_in_use()`), an "Export CSV" button, a live search box (`.bhy-table-search`) targeting a sortable `wp-list-table` with columns Name / Email / Tags / Activity / Registered. Activity is populated cross-plugin via the `bh_crm_activity_summary` filter.

**How it's used:** browse or search the directory, filter by tag, click a name to open their detail/profile view (`render_detail()`/`render_profile()` — not fully read this pass, but confirmed to exist), or export the current filtered list to CSV.

**Gaps:** the top-level menu registration carries the same "NOT runtime-verified" caveat as Design Suite, with the identical recommendation to smoke-test as a non-admin holding only `bhcore_manage_crm`. Also see Organizational Concern #2 — "People" is a redundant second entry to the same page.

## People — `admin.php?page=bh-crm-hub` (submenu)

Redundant with the CRM top-level entry above — see Organizational Concern #2.

## Project Tracker — `admin.php?page=bh-crm-projects`

**File/class:** `bh-crm/includes/class-registry.php` registers this via the menu-merge mechanism; renders via `BHCRM_Projects::render_boards()` in `bh-crm/includes/class-projects.php`.

**What it's for:** a cross-person index of every kanban-style project board in the CRM (the boards themselves live inside a person's profile, not here — this is a listing, not a second board implementation).

**What you should see:** a table (Project, Person, Cards, Updated, Open board) listing every project across all people, or a "No projects yet" message linking back to People if there are none.

**How it's used:** scan for a project across all people at once, click "Open board" to jump to that person's profile at the actual kanban board (`admin.php?page=bh-crm&user_id=...&project_id=...`).

**Gaps:** none identified beyond the general standalone-page caution noted for the CRM hub above (same `parent`/`capability` mechanism).

## OUS Debug — `admin.php?page=ous-debug`

**File/class:** `own-ur-shit/includes/class-debug.php`, `render()`.

**What it's for:** a dev/QA toolbox — environment lock status, seed/reset actions, and (per the code) the *reliable* way to view API Docs and Codebase Docs content (see below).

**What you should see:** an environment banner ("Locked" if `wp_get_environment_type()` looks like production, blocking seed/reset actions unless `OUS_DEBUG_TOOLS_FORCE` is defined or the environment is explicitly local/development/staging; "Unlocked" otherwise), jump-link buttons to the in-page API Docs and Codebase Docs sections, then a list of registered debug tool sections from the `ous_debug_tools` filter (grouped, per a recent reorganization pass referenced in `class-registry.php`'s changelog comments — e.g. a "Monitoring & Health" group and a "Reference & Docs" group).

**How it's used:** use this as your primary path to API/Codebase docs (not the standalone pages — see Organizational Concern #5), and for any seed/reset/debug actions while developing.

**Gaps:** this page's own code explicitly documents that its sibling standalone pages (API Docs, Codebase Docs) have an unresolved WordPress registration/access bug — worth testing directly rather than assuming either path works.

## API Docs — `admin.php?page=ous-api-docs`

**File/class:** `own-ur-shit/includes/class-api-docs.php`, `render()` / shared `render_content()`.

**What it's for:** a live-generated OpenAPI 3.0 spec of every REST route this ecosystem registers, plus a browsable rendering of it — always in sync with actual code, never hand-maintained.

**What you should see:** a description line with the raw OpenAPI JSON URL (`rest_url('ous/v1/openapi.json')`) and a Copy button, followed by the generated route documentation.

**How it's used:** as a live reference for anyone (including a future coding agent) integrating with this ecosystem's REST API, or import the JSON into Postman/Insomnia/Swagger UI.

**Gaps:** per Organizational Concern #5, this standalone page is specifically called out in code comments as unreliable — test whether it loads for you before depending on it; the same content is also reachable as a section anchor inside Debug Tools.

## Codebase Docs — `admin.php?page=ous-codebase-docs`

**File/class:** `own-ur-shit/includes/class-codebase-docs.php`, `render()` / shared `render_content()`.

**What it's for:** a guided, sequential tour of the whole codebase, generated from `CODEBASE-WALKTHROUGH.md` (a separate file in this same `plugins/` directory), with every file it references made live-readable inline.

**How it's used:** as a narrative onboarding doc for a human or agent new to the codebase.

**Gaps:** same reliability caveat as API Docs — see Organizational Concern #5.

## OUS · Contest — `edit.php?post_type=bh_contest`

**File/class:** CPT registered in `bh-contest/includes/class-post-types.php`; menu items added directly (not via merge) in `bh-contest/includes/class-admin.php` and `class-console.php`.

**Contests / Submissions:** standard CPT list-tables, enhanced with custom columns — Contests shows a status pill, copyable shortcode, a link to the auto-created page, and submission/vote stats; Submissions shows which contest each entry belongs to plus a contest filter dropdown.

**Results — `admin.php?page=bh-results`:** `BH_Admin::render_results()` (not fully read this pass, referenced via `add_menus()`).

**Live Console — `admin.php?page=bh-console`:** `BH_Console::render()`. Shows every submission for a chosen contest with its audio playable inline, plus the real name/contact identity behind each submitter, and embeds the results-reveal controls used to actually run a live stream. The class's own docblock is explicit that this page is deliberately never linked near the public-facing results page, since it exposes private contact info that must never appear in an OBS capture. A code comment also documents a real, since-fixed bug: the contest-picker `<form method="get">` on this page must carry `post_type=bh_contest` as a hidden field, or WordPress can't resolve the submenu and throws a permissions error.

**Gaps:** none beyond what's noted; this section appears complete and specifically hardened against a real reported bug.

## OUS · Courses — `edit.php?post_type=bh_course`

**Courses / Lessons:** standard CPT list-tables.

**Student Progress — `admin.php?page=bhc-progress`:** `bh-courses/includes/class-progress-admin.php`, `BHC_ProgressAdmin::render()`. Its own docblock states this replaced an earlier version that could only ever show one seeded student's progress — "not a real teaching workflow." Gated on a `bhcore_manage_students` capability (falls back to `edit_posts` if that capability class isn't loaded, to avoid silently locking everyone out on an older core).

**What you should see:** a course selector, then that course's student progress list (columns/detail not fully read this pass — confirm on-screen).

**Gaps:** capability fallback logic is worth testing with a non-admin account to confirm the intended instructor-only access actually works as described.

## OUS · Streaming — `edit.php?post_type=bhs_track`

**Tracks:** CPT list-table (top-level, always visible).

**Releases, Playlists, Feed Sources, Genres:** CPT/taxonomy list-tables, but each is registered with `show_ui`/`show_in_menu` conditional on `$visible` — meaning they, and the entire streaming player itself, can vanish from the admin menu entirely depending on environment (see Known Gaps under the public Streaming section).

**Metrics — `admin.php?page=bhs-metrics`:** `bh-streaming/includes/class-stats.php`, `render()`. Gated on `edit_posts`. Pulls last-30-day stats from a custom table: plays by day, top 10 tracks by plays, top 10 tracks by skips, and a by-country breakdown (approximated from the `Accept-Language` header, not real GeoIP — the code is explicit this is "signal, not the same thing as actual location").

**How it's used:** review play/skip trends and rough listener locale mix over the trailing 30 days.

**Gaps:** country data is a deliberate approximation, not precise geography — don't over-trust it during QA.

---

# Public-facing surfaces

## Portal — `/account/` (rewrite-owned)

**File/class:** `own-ur-shit/includes/class-portal.php`, class `BHI_Portal`.

**What it's for:** a genuinely separate, branded front-end account area for logged-in users — not a reskinned wp-admin, not a pile of unrelated shortcodes on separate pages. Built from panels contributed by any plugin via the `bhi_portal_panels` filter.

**What you should see:** a two-column shell — a left nav (`.bhi-portal-nav`) listing every registered panel with a dashicon, and a main content area (`.bhi-portal-main`) rendering the active panel. Currently five panels are registered:
- **Profile** (`own-ur-shit/includes/class-public-profile.php`) — identity/profile info.
- **Notifications** (`own-ur-shit/includes/class-notifications.php`).
- **Contest Submissions** (`bh-contest/includes/class-portal-panel.php`).
- **My Courses** (`bh-courses/includes/class-portal-panel.php`).
- **Membership & Wallet** (`bh-monetization-woo/includes/class-portal-panel.php`).

Note: `class-portal.php`'s own docblock says this "ships... ONE real migrated panel (profile/identity)" — that line is now out of date; three other plugins have since added their own panels. Trust the panel filter registrations (verified above), not that sentence.

**How it's used:** log in, visit `/account/`, navigate between panels via the left nav (URLs are `/account/{panel-id}/`). Logged-out visitors are redirected to `wp_login_url()`.

**Gaps:** the file contains a long, detailed comment describing a real, previously-shipped bug where the rewrite rule never actually registered (a WordPress hook-timing footgun — re-hooking `init` from inside `init` at the same priority never fires). The fix (call `add_rewrite()` directly rather than re-hooking it) is in place, but given the documented history of registration bugs across this codebase's other standalone-page surfaces, it's worth confirming `/account/` actually loads and isn't a 404 before relying on it.

## bh-contest — public voting (`[bh_contest_player]` shortcode)

**File/class:** `bh-contest/includes/class-auth.php`, `BH_Auth::render()`.

**What it's for:** the public voting/submission interface embedded via shortcode on a contest's page (auto-created per contest, per the admin Contests list).

**What you should see:** if the shortcode's `contest` attribute doesn't resolve to a real contest, logged-in editors see an inline warning box (visitors see nothing); otherwise a `#bh-player-root-N` div is emitted with `data-contest` set, which a JS app (not read this pass) mounts into.

**Gaps:** the actual voting UI is entirely client-side JS not covered in this pass — worth a manual click-through to confirm submission and voting flows work end to end.

## bh-courses — public catalog and lessons (`[bh_courses]`, `[bh_course]`)

**File/class:** `bh-courses/includes/class-render.php`, `render_catalog()` / `render_course()`.

**What you should see (catalog):** a filter bar (search, category, topic, sort by newest/alphabetical/popular), and a grid of course cards, each showing a lock icon if the viewer doesn't have access, a difficulty badge, lesson count, and (if logged in) a progress bar.

**How it's used:** browse/filter/search courses, click into one, work through lessons; access gating is checked per-course via `BHC_Gate::user_can_access_course()`.

**Gaps:** individual lesson-taking flow (`render_course()`'s deeper content, quiz rendering) wasn't read in full this pass — worth a manual walkthrough of an actual lesson.

## bh-streaming — public player (`[bh_streaming]`)

**File/class:** `bh-streaming/includes/class-player.php`, `render()`.

**What you should see:** a player shell (`#bhs-app`) with a topbar/account area, wired to two REST namespaces (`bhs/v1`, `bhi/v1`) via localized JS config — **except that `render()`'s very first line is `if (BHS_Env::hidden_in_production()) return '';`.**

**Critical gap:** `BHS_Env::hidden_in_production()` (in `bh-streaming/includes/class-env.php`) returns true — hiding the player entirely, rendering an empty string — whenever `wp_get_environment_type()` reports `production`, *or* whenever `wp_get_environment_type()` isn't available at all, unless a `BHS_FORCE_VISIBLE` constant is explicitly defined true in `wp-config.php`. The same gate also drives whether Releases/Playlists/Feed Sources/Genres show up in wp-admin at all (see the Streaming admin section above). **This means on a real production deployment, without that constant set, the entire public streaming feature — and most of its admin UI — silently disappears.** This is confirmed directly in code, not inferred. Check `wp_get_environment_type()` on the live site and whether `BHS_FORCE_VISIBLE` is defined before assuming streaming is live for visitors.

## bh-crm — public profiles

No public-facing profile template or shortcode was found for bh-crm in this pass (no `class-public-profile.php`-equivalent file exists in `bh-crm/`, and no `add_shortcode` calls in that plugin beyond the portal panel). The Portal's "Profile" panel is owned by `own-ur-shit/includes/class-public-profile.php`, not bh-crm — CRM data itself appears to be admin-only. If a public profile surface for CRM is expected, it does not currently exist in code; treat this as a real gap rather than something to hunt for further.

## bh-registry — public directory (`[bh_registry]`)

**File/class:** `bh-registry/includes/class-frontend.php`, `render()`.

**What you should see:** a search bar, a protocol filter (All / ActivityPub / RSS / Podcasting 2.0), a "Submit your link" button opening a modal, and a grid (`#bhr-grid`) that loads results via JS (shows "Loading…" server-side). The submit modal explains the plugin stores only the public link and basic metadata, never media, and requires proving domain control via a small text file.

**How it's used:** visitors browse/search/filter published artist links, or submit their own (which routes into the admin Registry Submissions queue described above once verified or flagged).

**Gaps:** the actual grid population and submission verification flow are JS/REST-driven and weren't traced further in this pass.

## bh-monetization-woo — checkout/purchase flows

**File/class:** `bh-monetization-woo/includes/class-frontend.php`.

- **`[bhm_tiers]` → `render_tiers()`:** a grid of supporter tier cards (cover image, presumably price/description below what was read), marking the viewer's currently-active tier. Returns a plain message if WooCommerce isn't installed or no tiers are configured yet.
- **`[bhm_tip_jar]` → `render_tip_jar()`:** a simple form that adds a configurable-amount "tip" product to the WooCommerce cart and redirects to checkout — amount is clamped between `TIP_MIN_CENTS` and `TIP_MAX_CENTS`.
- **`[bhm_wallet]` → `render_wallet()`:** shows the logged-in user's play-credit wallet balance and top-up options (each mapped to a real WooCommerce product via `sync_wallet_topup_products()`), or a "log in to see your wallet" message if logged out.

Also present: `bh-monetization-woo/includes/class-storefront.php` registers rewrite/template behavior not read in detail this pass.

**Gaps:** actual checkout completion, refund handling, and the "refund/velocity fraud-pattern flagging" mentioned in the plugin's own registry description were not traced in this pass — these are real WooCommerce order-hook logic worth testing with an actual test purchase rather than just reading code.

---

# Summary of the most significant issues to verify live

1. **bh-streaming's public player and most of its admin submenus disappear entirely in a production environment** unless `BHS_FORCE_VISIBLE` is defined — check this site's actual `wp_get_environment_type()` value.
2. **API Docs and Codebase Docs standalone admin pages are flagged in the code itself as unreliable** ("WordPress consistently blocking them ... could not fully root-cause"); the documented workaround is viewing them as sections inside Debug Tools instead.
3. **Design Suite/Designer and CRM/People are each two menu entries pointing at the identical rendered page** — not broken, but worth pruning for a cleaner nav during your walkthrough.
4. **Registry Submissions and Monetization Settings both live under "Own Ur Shit" rather than any registry- or commerce-branded menu**, because their `admin_menus` entries don't set a `parent` key and default there — a discoverability issue, not a functional one.
5. **Content Studio has no menu entry at all** (`null` parent by design) and depends on a modal-iframe launcher from inside Design Suite that should be confirmed working, not assumed.
