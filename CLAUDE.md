# Own Ur Shit ecosystem — CLAUDE.md

Read this before touching anything in `wp-content/plugins/`. It's the condensed version of `wp-content/plugins/VISION.md` (read that too, it's the real source of truth) plus the operating conventions this codebase has actually earned through real bugs, not theory.

## What this is

Own Ur Shit is **digital civil-service infrastructure**, not a startup — a self-hosted WordPress plugin ecosystem so an independent musician can own their audience/data outright instead of renting it from big-tech platforms. Every architectural call gets weighed against that: self-hosted always (ordinary shared hosting, no Redis/Docker/external broker assumed), no vendor lock-in, no quiet dependency on a paid third-party service where an owned equivalent will do.

**One required core (`own-ur-shit`) plus genuine peer plugins**, each depending ONLY on the core, never on each other directly:

- `bh-contest` — music contest voting/reveal/results
- `bh-streaming` — personal streaming library, Jam shared-listening
- `bh-crm` — person list/activity view on shared identity
- `bh-registry` — decentralized anonymous artist-link directory
- `bh-monetization-woo` — supporter tiers/purchases/tips/pay-per-play via WooCommerce
- `bh-courses` — LMS (courses → lessons → steps, quizzes, drip, tier-gating)

**The one rule that makes this work without turning into a tangled mess:** a peer plugin treats every OTHER peer plugin as entirely optional, checked via `class_exists('SomeClass')` **at hook-call time, never at file-parse time**, with a working fallback when the other plugin isn't installed. Never break this when adding cross-plugin code.

## Current standing priority (check VISION.md before assuming this has changed)

**Harden core before adding features.** Concretely: `own-ur-shit`, `bh-contest`, the style system, `bh-crm`, and Debug Tools need to be air-tight before new feature work. Jobs/errors/logs/testing/queue infra (`OUS_Jobs`, `OUS_DebugLog`, `OUS_TestRunner`, `OUS_ReliableStore`) is load-bearing — treat fragility there as higher priority than any feature request. Before building new dev/debug tooling, check for a viable open-source option first.

## The core's shared services — use these, don't reinvent them

Before adding a new cross-cutting concern, check whether it's actually a **Notifications** (`OUS_Notifications`), **Jobs** (`OUS_Jobs`), **Roles** (`OUS_Roles`), or **Events** (`BH_Event`/`bhcore_events`) problem first. Also real and shared: Identity (`BHI_*`), Style/design tokens (`BHY_*`), Debug Tools (`OUS_Debug`, filter `ous_debug_tools`), Console & Logs (`OUS_DebugLog::log()`), Test Runner (`OUS_TestRunner`, filter `bhcore_test_suites`), API Docs (`OUS_ApiDocs`, auto-generated from the live REST route table).

**New dev/admin-only pages default to a Debug Tools SECTION (`ous_debug_tools` filter), not a standalone `add_submenu_page()` page.** This install has a documented, multi-session history of standalone admin pages failing WordPress's own page-hook resolution (`get_plugin_page_hook()`) and showing "Sorry, you are not allowed to access this page" even with capability/registration both confirmed correct — see VISION.md's "New dev/admin-only pages" entry for the full incident. Query Monitor (`define('QM_ENABLE_CAPS_PANEL', true)` in wp-config.php) is the right diagnostic tool if this recurs. Where a standalone page is unavoidable, register secondary/hidden pages with `parent_slug = null`, not a real parent slug — a real parent slug has corrupted the top-level page's own callback/capability pairing on this install before (see `class-style-gallery.php`'s `add_menu()` for the documented pattern).

## Hard conventions — do not violate these without asking

- **No JSX, no build step, vanilla JS everywhere** — every admin/editor script in this ecosystem is enqueued directly, using `wp.element.createElement` (aliased `el`) rather than JSX, no npm/webpack/`@wordpress/scripts` build. See `assets/js/block-style-panel.js` for the current reference example of this pattern applied to a real Gutenberg `editor.BlockEdit` filter.
- **Portability rule (standing, from the project owner directly):** stored content/data shapes should stay plain and WP-agnostic wherever realistically possible; WP-specific mechanics (block attributes, admin screens, hooks) are the *attachment* layer, not the *data* layer. `BHY_BlockStyle`'s `bhStyle` attribute is the current reference example — the stored shape is a flat `{ "group.property": "value" }` map, the exact same shape `BH_Element` placements already store in `config.style`; only the mechanism that attaches it to a block is WP-specific.
- **`class_exists()` guards at hook-call time**, never file-parse time, for every cross-plugin (and increasingly cross-file-within-core) touch. Grep for `class_exists(` before assuming a class is always loaded.
- **Every wide/dense admin table** uses the shared `.bhy-table-wrap` class (`BHY_UI`, class-ui.php) — sticky header, horizontal scroll, denser padding. Don't invent a second wide-table treatment.
- **Version bump discipline:** every real change bumps both the plugin header `Version:` and the `OUS_VER` constant (or the equivalent per-plugin constant) in the same commit, with a changelog comment block prepended immediately above the constant explaining what changed and why. Read the last 10-15 changelog blocks in `own-ur-shit.php` before writing a new one — match the voice and level of detail, don't write a one-liner where the existing convention is a real paragraph.
- **"NOT runtime-verified" disclosure:** if a change wasn't actually exercised against a live WordPress+MySQL install (common in past sessions that had no PHP/MySQL/network access at all), say so explicitly in the changelog comment and to whoever's reading the output. Don't imply something is confirmed working when it's only been reasoned through.

## A real bug this exact codebase hit — worth internalizing

`BHY_UI::admin_page_css()` (class-ui.php) returns a large chunk of CSS as ONE giant single-quoted PHP string. An unescaped apostrophe inside a comment inside that string (`They're genuinely...`) silently terminated the string mid-file and turned the rest of the CSS into stray PHP tokens — a real, site-wide fatal parse error (that file loads on every request, front-end and admin), surfaced live as WordPress's generic "There has been a critical error" screen. It was invisible to hand-rolled brace-counting checks (braces still balanced) and only found by temporarily flipping `WP_DEBUG_DISPLAY`/`WP_DEBUG_LOG` on and reading the literal parse-error line out of `wp-content/debug.log`. **If you have a real PHP interpreter in your environment (Claude Code should), just run `php -l` on every touched file before calling anything done — that one command would have caught this instantly.** Prior sessions working on this repo had no PHP/MySQL/network access at all (a walled-off sandbox), which is why this class of bug has slipped through before; if you have real execution, use it aggressively — this codebase has been debugged blind for a long time.

## The page-builder saga — read this before touching the Design Suite / Styles pages

An earlier arc of this project built a custom hand-rolled visual page-builder (Structure/Library rail, a Components/linked-instance system, `BH_Component_Studio`) on top of `class-style-gallery.php`. After honest reassessment, **all of it was deleted** (not deprecated — actually removed, ~6,700 lines) in favor of native WordPress Gutenberg blocks + the pre-existing, still-live `BHY_Style` (design tokens) + `BH_Element`/`BH_Element_Data` (the real, still-live placement/data-binding engine — `render_slot()` is called by real pages across bh-contest/bh-crm/bh-courses, keep this) + `BH_Content` (a separate, also-live block-tree document system already built on real `@wordpress/block-editor` packages). `class-style-gallery.php` is back to being just the Styles/Design Suite page (site-wide design tokens + a Storybook-patterned live preview, `bhy_style_surfaces` filter for peer plugins to register a preview surface). Don't rebuild a custom page-builder here again without a very deliberate, re-litigated decision — the reasoning for why it was wrong the first time is real and still applies.

The CSS-properties/databinding capability that builder's inspector exposed was NOT lost — it's `BHY_BlockStyle` now (`class-block-style.php`), a generic "Advanced Styles" panel on every native block, reading `BHY_Style::PROPERTY_MAP`/`scoped_inline_style()`/`style_schema_for_js()` (all pre-existing, class-style.php) rather than reinventing the property vocabulary.

## Design references (taste, not dependencies)

Apple HIG / Material Design / GitHub Primer for the design SYSTEM itself. Storybook's own UX for how a component-gallery TOOL should feel (not a dependency — `BHY_Gallery`'s live-preview Style page independently arrived at this shape). Default to plain, unmodified WordPress admin styling; deviate only for a genuine, specific UX win, and make the deviation a shared, documented `BHY_UI` class, never a one-off inline style.

## Libraries/tooling worth knowing about for roadmap work (researched July 2026, verify currency before relying on specifics)

- **WordPress Block Bindings API** (core since 6.5, matured through 6.8) — native, no-build databinding for block attributes to post meta/custom data sources. Check this before building any new custom databinding mechanism.
- **GrapesJS** — BSD-3-Clause, actively maintained, the reference open-source drag/drop canvas if `ROADMAP-platform-evolution.md` Section 3's visual builder ever gets built on a canvas rather than a Gutenberg layer.
- **Tabulator** (MIT) / **Grid.js** — lightweight, no-build JS table/grid libraries, the right fit for Section 3's "structured-data table view" (course lesson lists, quiz question reordering) once that's built.
- **Radicale / Baïkal** — lightweight self-hosted CalDAV/CardDAV servers, the standards-based path for VISION.md's email/calendar pillar; Radicale is DB-free (just files), Baïkal has a friendlier admin UI.
- **HyperPress** (GPLv2+, github.com/EstebanForge/HyperPress) — wires htmx/Alpine.js/Datastar into WordPress block development with zero build step; worth looking at as prior art before hand-rolling similar glue, given this ecosystem's own "no build step" rule.
- **Dolibarr / Akaunting / LedgerSMB** — open-source PHP double-entry accounting, relevant if the ERP/accounting pillar in VISION.md's "round two" section ever gets picked up (realistic near-term scope there is a thin `BH_Ledger` interface, not rebuilding any of these).

## Practical environment notes

- Local dev via Local by Flywheel (`localhost:10008`), site root = this repo root, git remote `origin` → `github.com/ajhrtmn/billyhume.git`, branch `dev`. WP_DEBUG is normally **off** in `wp-config.php` — flip `WP_DEBUG_DISPLAY`/`WP_DEBUG_LOG` on temporarily when chasing a real bug, and revert both before finishing (never leave debug display on).
- No real PHP/MySQL/network access existed in the sandbox most of this project's history was built in — if you have real execution now, prioritize actually running things (`php -l`, WP-CLI, the Test Runner suites, hitting real admin pages) over another round of static reading. That's the single biggest quality upgrade available right now.
- The custom hand-lexed PHP syntax checkers referenced in old changelog comments (`phpcheck.py`) were a workaround for having no real interpreter — don't perpetuate that pattern if `php -l` is actually available to you.

## Where the detailed docs live (in `wp-content/plugins/`)

- `VISION.md` — the one to reread periodically; mission, architecture, standing priorities, full near-term roadmap, the two "big-vision pillars" sections (creator-workspace stretch goals, then enterprise/personal-finance stretch goals).
- `ROADMAP-platform-evolution.md` — storefront, monetization depth, the LMS/block-builder foundation, custom user portal, social/marketing (roadmap-only).
- `ROADMAP-lms-v3.md` — interactive video, richer authoring, branching lesson paths (flagged as needing a dedicated design pass before any code), mind-map authoring (roadmap-only).
- `ROADMAP-feedback-and-courses-v2.md` — the BH Feedback plan (paid song feedback, tiered depth) and BH Courses' honest gap list.
- `ROADMAP-safety-and-metrics.md` — fraud/abuse detection, fan-metrics dashboard, long-term safety/legal items.
- `PAGE-BUILDER-DELETE-KEEP-AUDIT.md` (`wp-content/plugins/own-ur-shit/`) — the file-by-file reasoning for the page-builder deletion above; read before assuming any deleted class should come back.
