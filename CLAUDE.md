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

- **TypeScript is the source of truth for client code; `assets/js/*.js` is GENERATED — never edit it.** Corrected 2026-08-23: this entry previously read "no JSX, no build step, vanilla JS everywhere," which is wrong and actively dangerous — editing a generated `.js` gets silently overwritten on the next compile. There IS a build step (plain `tsc`, no bundler, no npm dependency at runtime): 28 `.ts` files under `assets/ts/` compile to `assets/js/` via each plugin's own `tsconfig.json` (`strict: true`, `noUncheckedIndexedAccess`, `module: none`, `target: ES2019`). Verified this pass: all five TS projects type-check clean, and the checked-in JS has **zero drift** from a fresh compile — that discipline is worth keeping.
  - Edit `assets/ts/*.ts`, then recompile from the plugin root: `npx tsc` (type-check only: `npx tsc --noEmit`). Commit both the `.ts` and the regenerated `.js`, since WordPress enqueues the `.js` directly.
  - What the old wording got RIGHT and still holds: **no JSX and no bundler.** For UI, pick between `wp.element.createElement` (aliased `el`) for a real Gutenberg `editor.BlockEdit` filter or simple client-side state, and **Datastar** (vendored, `own-ur-shit/assets/js/vendor/datastar.js`, wired via `OUS_Hypermedia`) — the default for new interactive admin/editor UI needing server-driven reactivity. `wp.element` stays valid for plain forms and static settings screens. Migration backlog: `ROADMAP-hyperpress-migration.md`. Datastar is vendored directly rather than depending on the HyperPress plugin, which is a much larger dependency than needed.
- **Portability rule (standing, from the project owner directly):** stored content/data shapes should stay plain and WP-agnostic wherever realistically possible; WP-specific mechanics (block attributes, admin screens, hooks) are the *attachment* layer, not the *data* layer. `BHY_BlockStyle`'s `bhStyle` attribute is the current reference example — the stored shape is a flat `{ "group.property": "value" }` map, the exact same shape `BH_Element` placements already store in `config.style`; only the mechanism that attaches it to a block is WP-specific.
- **`class_exists()` guards at hook-call time**, never file-parse time, for every cross-plugin (and increasingly cross-file-within-core) touch. Grep for `class_exists(` before assuming a class is always loaded.
- **Every wide/dense admin table** uses the shared `.bhy-table-wrap` class (`BHY_UI`, class-ui.php) — sticky header, horizontal scroll, denser padding. Don't invent a second wide-table treatment.
- **Version bump discipline:** every real change bumps both the plugin header `Version:` and the `OUS_VER` constant (or the per-plugin equivalent) in the same commit. **The narrative goes in the commit message and the plugin's `CHANGELOG.md` — NOT in a comment block in source.** That older convention was retired 2026-08-23 after it grew to 29,137 comment lines ecosystem-wide, with `own-ur-shit.php` at 2,555 lines wrapping 115 lines of actual code; the history is preserved verbatim in each plugin's `CHANGELOG.md`. Full reasoning and the replacement documentation rules are in `wp-content/plugins/CONVENTIONS.md` — read that before writing a comment longer than about three lines.
- **Critical infrastructure always ships with a minimal, self-hosted, built-in default — a third-party integration is an enhancement layered on top, never the only implementation.** (AJ, standing rule, 2026-08-02.) Concretely: define a small interface/contract (usually just a class with a couple of static methods) that this ecosystem implements itself with plain WordPress primitives, then let an optional third-party plugin enhance or replace the implementation behind that same contract — `class_exists()`-guarded, same as every other cross-plugin touch. Precedent already in the codebase, which is what this rule is formalizing rather than inventing: `BH_Mail` (`own-ur-shit/includes/class-mail.php`) wraps `wp_mail()` behind one `deliver()` seam meant for a real ESP later; `OUS_Campaigns` (`class-campaigns.php`) is a complete, working live-segment email broadcaster on top of `BH_Mail`/`wp_mail()` alone, no third-party sender required. A new integration should extend one of these existing contracts (e.g., a marketing-automation plugin becomes an alternate *send provider* behind `OUS_Campaigns`' existing segment/send shape) rather than standing up a second, parallel mechanism next to it — two competing "how do we send email" systems is exactly the tangle the peer-plugin optionality rule above exists to prevent, just one layer deeper (within-contract, not just within-plugin).
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
- **GrapesJS** — BSD-3-Clause, actively maintained, the reference open-source drag/drop canvas if the visual-builder idea ever gets built on a canvas rather than a Gutenberg layer (see `OPEN.md`; note the page-builder deletion audit first).
- **Tabulator** (MIT) / **Grid.js** — lightweight, no-build JS table/grid libraries, the right fit for Section 3's "structured-data table view" (course lesson lists, quiz question reordering) once that's built.
- **Radicale / Baïkal** — lightweight self-hosted CalDAV/CardDAV servers, the standards-based path for VISION.md's email/calendar pillar; Radicale is DB-free (just files), Baïkal has a friendlier admin UI.
- **HyperPress** (GPLv2+, github.com/EstebanForge/HyperPress) — surveyed, not adopted: it's a full standalone WordPress plugin/Composer library (own admin UI, HyperFields/HyperBlocks systems, its own REST namespace), a bigger dependency than needed. **Datastar itself is now vendored directly** (`own-ur-shit/assets/js/vendor/datastar.js`, `OUS_Hypermedia`) — see the hard-conventions entry above.
- **Dolibarr / Akaunting / LedgerSMB** — open-source PHP double-entry accounting, relevant if the ERP/accounting pillar in VISION.md's "round two" section ever gets picked up (realistic near-term scope there is a thin `BH_Ledger` interface, not rebuilding any of these).

## Practical environment notes

- Local dev via Local by Flywheel (`localhost:10008`), site root = this repo root, git remote `origin` → `github.com/ajhrtmn/billyhume.git`, branch `dev`. WP_DEBUG is normally **off** in `wp-config.php` — flip `WP_DEBUG_DISPLAY`/`WP_DEBUG_LOG` on temporarily when chasing a real bug, and revert both before finishing (never leave debug display on).
- No real PHP/MySQL/network access existed in the sandbox most of this project's history was built in — if you have real execution now, prioritize actually running things (`php -l`, WP-CLI, the Test Runner suites, hitting real admin pages) over another round of static reading. That's the single biggest quality upgrade available right now.
- The custom hand-lexed PHP syntax checkers referenced in old changelog comments (`phpcheck.py`) were a workaround for having no real interpreter — don't perpetuate that pattern if `php -l` is actually available to you.

## Where the detailed docs live (in `wp-content/plugins/`)

Consolidated 2026-08-23: 26 docs down to 16, after a pass that verified claims against code rather than against other docs. Ten were deleted because their content had actually shipped — recoverable from git history if the original reasoning is ever needed.

**Start here, in this order:**
- `STATE.md` — what is actually built, verified by reading code and running the suite. Also lists the six "still open" claims that turned out to be false. **Grep before writing "not built" anywhere.**
- `OPEN.md` — the single consolidated backlog of everything genuinely unfinished, ranked by leverage. Absorbed the old `PRODUCTION-READINESS-PLAN.md`, `STATUS.md`, `ecosystem-depth-pass`, and the UX-parity roadmap.
- `DESIGN-CRAFT.md` — the design/UX thesis: what would make this feel magical, and the craft standards to hold. Read with `STYLE-SYSTEM.md`.
- `VISION.md` — mission, architecture, standing priorities, the big-vision pillars. Still the source of truth for *why*.

**Reference:**
- `TOOLING-EVALUATION.md` — which third-party tools to adopt and refuse, measured against the real deploy pipeline. The governing test: **deployment is a verbatim FTP sync with no build step, so build-time tooling is fine and runtime dependencies are not** — "does the committed artifact run with nothing but PHP and WordPress?"
- `UX-AUDIT-PLAN.md` — the step-by-step screen-by-screen UX audit plan (~70 screens, 6 widths, both themes), including what needs a session that can log out.
- `CONVENTIONS.md` — documentation/structure/naming rules: code carries its own meaning, comments answer "why" in ≤3 lines, `// WHY:` for load-bearing constraints, no changelog blocks in source.
- `STYLE-SYSTEM.md` — the four style layers (tokens/utilities/components/plugin-local). Check before writing any new style rule.
- `TESTS.md` — the three gates and how to run them. (Its old "no PHP runtime available" claim was wrong and has been corrected — a real runtime, MySQL, PHPStan, and the Test Runner are all available. Run things.)
- `ETCH-COMPATIBILITY-NOTES.md` — why full-content-replacement was dropped. Still binding.
- `CODEBASE-WALKTHROUGH.md` / `WALKTHROUGH-GUIDE.md` — onboarding curriculum, and the screen-by-screen GUI inventory that doubles as the audit checklist.
- **Page-builder deletion reasoning** — the `PAGE-BUILDER-DELETE-KEEP-AUDIT.md` this file used to cite never actually existed on disk (a phantom reference, found and removed 2026-08-23). The surviving reasoning is the "page-builder saga" section above plus `own-ur-shit.php`'s own changelog around the 3.4.76 / deletion entries. `PAGE-BUILDER-REBUILD-PLAN.md` was also deleted the same day: it described `BH_Component_Studio` as "actually built and seeded" when all three of its files had long since been removed, which made it actively misleading. Read the saga section before assuming any deleted class should come back.

**Design-pass-only, nothing built** (kept because the thinking is worth having): `ROADMAP-federated-metrics.md`, `ROADMAP-obs-integration.md`, `ROADMAP-streaming-media-scope-and-blockchain.md` (Part 1), `ROADMAP-lms-instructor-student-depth.md`, `ROADMAP-hyperpress-migration.md` (the live Datastar backlog), `ROADMAP-guided-setup-wizards.md` (kept for its reusable wizard pattern, not its status).
