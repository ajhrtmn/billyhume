# ROADMAP — ecosystem-wide quality audit: how to run it (2026-07-25)

This is a **plan for how to conduct** a deep, comprehensive audit of all eight plugins (`own-ur-shit`, `bh-contest`, `bh-courses`, `bh-crm`, `bh-monetization-woo`, `bh-registry`, `bh-streaming`, `bh-feedback`) across two dimensions — "magical" UX and clean/SOLID/DRY code. It is **not** the audit itself. It was written after reading `VISION.md`, `STATUS.md`, the prior `QA-REPORT.md`/`QA-REPORT-code-quality.md`/`UX-AUDIT-2026-07.md`, and a structural pass over the actual plugin code (file/line counts). The real audit should be run by a strong, thorough model afterward — see "Who should run this" at the bottom.

## Why this doc exists, and what it's building on

This ecosystem already has real prior audit work — this is not audit #1. `QA-REPORT.md` (bug fixes, live-environment constraints), `QA-REPORT-code-quality.md` (DRY/SOLID pass, 2026-07-08), and `UX-AUDIT-2026-07.md` (live-walkthrough UX pass, 2026-07-13) already exist at the plugins root, and `STATUS.md`/`ecosystem-depth-pass-2026-07.md` track what's shipped since. **The next audit's first job is reading these three in full and confirming what's still true, not re-discovering the same findings from scratch.** A meaningful amount of code has changed since 07-08/07-13 (bh-crm alone jumped v1.3.5→v2.4.6; bh-courses got a full 4-phase "LMS depth-of-magic" pass), so re-verification is genuinely warranted — but "re-verify all seven bh-crm class-people.php findings from scratch" is a different, cheaper task than "find code quality problems in bh-crm with no prior context," and the real audit should know which one it's doing at each step.

## Scope and honest sizing

Total current size, measured directly (`find … -name "*.php" | xargs wc -l` per plugin, 2026-07-25):

| Plugin | PHP files | PHP lines | JS files |
|---|---|---|---|
| own-ur-shit (core) | 158 | 38,861 | 7 |
| bh-courses | 34 | 8,162 | 5 |
| bh-contest | 27 | 7,269 | 7 |
| bh-monetization-woo | 25 | 5,715 | 5 |
| bh-crm | 15 | 4,864 | 6 |
| bh-streaming | 22 | 4,021 | 2 |
| bh-registry | 11 | 1,627 | 1 |
| bh-feedback | 8 | 785 | 0 |
| **Total** | **300** | **~71,300** | **33** |

Two things this table makes obvious that a generic "audit the plugins" instruction wouldn't surface:

- **`own-ur-shit` core is more than half the entire codebase by itself** (38.9K of ~71.3K lines) — roughly the size of the other seven plugins combined. It cannot be treated as "one audit unit" alongside `bh-feedback`'s 785 lines without badly misallocating effort. It needs its own dedicated pass, probably split further by subsystem (identity/roles/events, the Design Suite / `BH_Element` page-builder, jobs/notifications/debug-log, style system) given its size and the number of genuinely distinct concerns living inside one plugin directory.
- **`bh-feedback` is barely started** (785 lines, 8 files, no JS) — per `STATUS.md` this is a brand-new peer plugin "entirely unbuilt, still just a plan" as of the last status pass. Auditing it deeply for DRY/SOLID violations or UX polish is close to premature; the highest-value thing an audit can do here is note what exists, confirm it follows the same `class_exists()` peer-plugin-optionality convention as everything else, and otherwise flag it as **too early for a full audit pass** rather than manufacture findings against a skeleton.

### Recommended sequencing — granular, per plugin × dimension, not bundled passes

The audit's real unit of delegation is **(plugin × dimension)** — code-quality and "magical UX" are different kinds of reasoning (this ecosystem already produces them as separate docs: `QA-REPORT-code-quality.md` vs. `UX-AUDIT-2026-07.md`) — with `own-ur-shit` core additionally split into three subsystems, since 158 files spanning identity/events, jobs/notifications/debug-log, and the style system/page builder is too large and multi-concern to be one unit even on its own. See the **Trigger** section below for the full 16-task table with model assigned per task; the rationale for splitting core, deferring `bh-feedback`, and treating money-handling code (`bh-monetization-woo`) with extra care — all first established here — carries through unchanged into that per-task breakdown.

This granularity also maps naturally onto separate audit *sessions* if a single context window can't hold enough of the codebase at sufficient depth — better to get many genuinely deep, tightly-scoped passes than one shallow eight-plugin skim.

## What "magical UX" concretely means here (grounded, not generic)

`VISION.md`'s own language sets the bar, and it's more specific than "good UX": the **"it just works" guided-setup principle** (AJ, 2026-07-17) — any feature wrapping a third-party service or technical integration should be a step-by-step wizard (one plain-language question per screen, validates credentials live, never requires reading the provider's own docs), with the raw settings page still available underneath for experts. The audit should judge every setup/config flow against this specific bar, not a generic "is this usable" heuristic. Also load-bearing: the **design-system convention** ("default to plain WP admin styling, deviate only for a genuine specific UX win, and make the deviation a shared reusable `BHY_UI` piece, never a one-off inline style" — `.bhy-table-wrap` is the cited precedent).

Concrete examples already on record to build from, not start from zero:

- **Good pattern, cite as reference**: the Debug Tools page (`admin.php?page=ous-debug`) — grouped jump-nav over collapsed accordions, praised in the 07-13 UX audit as "one of the better-designed screens in the whole ecosystem," worth using as the template when judging any other "many tools on one page" screen.
- **Good pattern, cite as reference**: the contest list admin screen (real `WP_List_Table` conventions, status pills, one-click shortcode-to-clipboard, live counts) — the 07-13 audit judged it against the WooCommerce Orders-table baseline and said it clears it.
- **Known smell, re-verify still true**: a **systemic bare-empty-state pattern** hit three separate times in the 07-13 pass (course catalog, streaming library, and by pattern-matching likely elsewhere) — "No courses found yet" / "No tracks match" with no distinction between "genuinely empty" vs. "filter matched nothing," and no CTA. Flagged there as having "crossed from one screen's oversight to a systemic pattern worth fixing once, centrally." The real audit should check whether this was fixed since 07-13, and if not, whether it has spread to `bh-feedback` or other new surfaces.
- **Known smell, re-verify still true**: low-contrast beige-on-beige search/filter inputs confirmed live on both the course-catalog and streaming search boxes in 07-13 — described there as "the actual site-wide design token in production," a likely WCAG-contrast failure, not a one-off styling accident.

## What "clean/SOLID/DRY" concretely means here (grounded, not generic)

Established convention worth judging code *against*, per `VISION.md`'s own stated rule and confirmed in the 07-08 pass by sampling docblocks: **dense "why not what" comments** that explain the failure mode a piece of code prevents and cross-reference sibling classes by name (cited good examples: `class-portal.php`, `class-people.php`, `class-wallet.php`, `class-jam.php`). Code that doesn't meet this bar — comments that restate what the code does, or no comments where a non-obvious constraint exists — is a real finding, not house-style nitpicking.

Concrete examples already on record:

- **Good pattern, cite as reference**: `BHM_Gate::calculate_downgrade_credit_cents()` — deliberately extracted out of its DB-touching caller specifically to be independently unit-testable. This is the ecosystem's own reference shape for "pull the pure logic out of the I/O" — worth checking whether other money/scoring/eligibility calculations follow it or not.
- **Known duplication, re-verify still true and check for a third copy**: the rewrite-rule "self-heal" algorithm (~90 lines: versioned rule, direct-`$wpdb` persistence check, throttled retry, forced flush with explicit cache-clear, throttled log trace) exists byte-for-byte in both `own-ur-shit/includes/class-portal.php` and `bh-monetization-woo/includes/class-storefront.php` — the Storefront copy's own comments admit it's a manual port. This is the strongest DRY candidate on record; check whether `bh-registry`'s activator (which the 07-08 pass noted has *no* self-heal logic at all) has since grown its own third copy instead of getting a shared helper.
- **Known SOLID violation, re-verify still true**: `bh-crm` reaches directly into `own-ur-shit` core's `bhi_profiles` table with raw SQL in two places (`class-people.php`, `class-export.php`), bypassing `BHI_Profiles`, the class that actually owns that table — an encapsulation violation the 07-08 report called out as contradicting `class-people.php`'s own docblock claim of never doing this to other plugins' tables.
- **Deferred, not urgent, but should get a fresh count**: 21 occurrences of `number_format($x/100, 2)` cents-formatting duplication and 18 scattered `class_exists('WooCommerce')` guards in `bh-monetization-woo`, both already identified twice and deliberately deferred — the real audit should get updated counts (this plugin has grown 0.4.5→0.5.1 since) and make an actual call on whether they've grown enough to prioritize, rather than deferring a third time by default.
- **New surface with no prior audit coverage at all**: everything from the 4-phase "LMS depth-of-magic" pass (achievements, leaderboard, callout/checklist/chord-chart/audio-compare step types), `OUS_Audit`, `OUS_Roles`, and all of `bh-feedback` — these post-date both prior audits and have zero code-quality review on record yet.

## Output format

**One index doc + one findings doc per pass**, not a single monolithic report and not scattered per-file notes:

- `AUDIT-2026-0X-<plugin-or-group>.md` per pass (matching this ecosystem's own existing naming convention — `QA-REPORT-code-quality.md`, `UX-AUDIT-2026-07.md` are the precedent to follow, not invent a new scheme).
- Each pass doc should end with a **prioritized punch-list** (the `ROADMAP-*.md` "Priority build-order section" convention already used in `UX-AUDIT-2026-07.md` — reuse that exact structure) so findings convert directly into actionable follow-up work, not just a list of observations.
- After all four passes, a short **synthesis doc** (`STATUS.md`-style) that reconciles cross-plugin patterns (e.g. "empty states are bad in three plugins" belongs in the synthesis, not repeated three times per-plugin) and updates `STATUS.md`/`ecosystem-depth-pass-2026-07.md` if anything they claim is now stale — this ecosystem has been burned before by stale "still open" claims (see `STATUS.md`'s own "second correction pass" note).

**Verification discipline — the ecosystem's own established standard, restated as the audit's rule**: every finding must be checked against the real file/line before being trusted, the same discipline `QA-REPORT-code-quality.md` and `UX-AUDIT-2026-07.md` already modeled ("every finding below was actually seen live... not inferred from code alone" / "read complete functions, not just grep hits, for every finding cited"). A pattern-match on a function name or a superficial "this looks like duplication" is a **lead**, not a finding — it graduates to a finding only after reading the actual code (or, for UX claims, the actual rendered screen) and confirming the failure mode concretely. Where no live PHP/MySQL/WordPress execution environment is available (true for every prior session per `QA-REPORT-code-quality.md`'s own note), say so explicitly in the output and treat the pass as static-analysis-only with that caveat named up front, rather than silently presenting static findings with live-audit confidence.

## One-shot or recurring?

**Recurring, on the same rough cadence this ecosystem already uses informally** — `QA-REPORT-code-quality.md` (07-08), `UX-AUDIT-2026-07.md` (07-13), and `ecosystem-depth-pass-2026-07.md` (07-21) show roughly a per-week-to-two-weeks natural rhythm of stock-taking passes already happening alongside feature work. A full four-pass audit like this one is heavier than those and shouldn't run every week, but given how much drifts between passes already (bh-crm's major version jump, `STATUS.md`'s own admission that "confirmed unbuilt" claims from earlier the same day turned out wrong on a closer look), a full pass every **4-6 weeks**, or whenever a plugin crosses a major version bump, is a reasonable standing cadence — not a one-time exercise to check off.

## Who should run this

Each task below runs on the model that fits its actual stakes/complexity — not a blanket top-tier assignment. AJ named Fable as the reference for "a good, better model" when top-tier reasoning is genuinely warranted; that's reserved for the single most architecturally complex piece of the ecosystem (task 3 below), with Opus and Sonnet used deliberately everywhere the reasoning load is smaller, per the table's own "why this model" column. Rough sizing: ~71K lines of PHP plus JS across 300+ files, split into 16 tightly-scoped tasks plus one inline synthesis — each individual task is sized to be doable in a focused session, closer in depth to the original `QA-REPORT-code-quality.md`/`UX-AUDIT-2026-07.md` passes than to a quick lookup, but no single task needs to hold the whole ~71K-line codebase in context at once.

## Trigger — how to actually kick this off later

This section is what a future "go" from AJ resolves to. It's designed to run **without infinite spiraling agents**, to spend tokens deliberately rather than defensively, and to put no more model strength on a task than that specific task's stakes/complexity actually warrant.

### Who runs this: one queen, many specialized workers

I (the orchestrating session) own the whole run: I dispatch each task below one at a time with its assigned model, review each one's output before deciding whether to continue, do the final synthesis myself rather than delegating it, and I'm the only place model-fallback or scope-adjustment decisions get made. No worker task ever reports to another worker task — every task's only relationship is me→worker, dispatched and reviewed by me. That's what keeps 16 granular tasks from becoming 16 independent, uncoordinated agent runs: one mind still holds the whole plan; it's delegating narrower slices of *execution*, never delegating judgment about the plan itself.

### The tasks — one row per actual unit of reasoning

**Code quality (static reading, DRY/SOLID/naming/comments):**

| # | Task | Scope | Why this model | Model |
|---|---|---|---|---|
| 1 | Core: identity, events, roles, audit log | `class-identity.php`, `class-event.php`, `OUS_Roles`, `OUS_Audit` | Cross-plugin backbone (every peer plugin's event emission routes through this), but already well-bounded/documented — solid reasoning needed, not maximal | Opus |
| 2 | Core: jobs, notifications, debug-log | `OUS_Jobs`, `OUS_Notifications`, `OUS_DebugLog` | Mechanical, already-established patterns (queue, trim-on-write) — lower ambiguity, cheaper tier does this well | Sonnet |
| 3 | Core: style system + Design Suite/page builder | `BHY_Style`/`BHY_UI`, `BH_Element` node-tree system | Largest, newest, most architecturally novel subsystem in the whole ecosystem (the 98KB `DESIGN-SUITE-UNIFICATION-PLAN.md` alone signals real complexity) — the one place worth top-tier reasoning | **Fable** |
| 4 | `bh-monetization-woo` code quality | whole plugin, 5.7K lines | Real money-handling logic (wallets, tiers, gifting) — consequential enough to warrant a step up | Opus |
| 5 | `bh-courses` code quality | whole plugin, 8.2K lines | Just went through a 4-phase feature pass — worth checking new code is consistent with old, real reasoning needed | Opus |
| 6 | `bh-contest` code quality | whole plugin, 7.3K lines | Mature, largest single file in the ecosystem lives here (`class-admin.php`) | Opus |
| 7 | `bh-crm` code quality | whole plugin, 4.9K lines | Mid-size, known narrow set of things to re-verify (two duplication findings on record) | Sonnet |
| 8 | `bh-streaming` code quality | whole plugin, 4.0K lines | Mid-size, nothing unusually complex on record | Sonnet |
| 9 | `bh-registry` + `bh-feedback` code quality (combined) | 2.4K lines total | Both small enough that splitting adds pure dispatch overhead with no quality gain; `bh-feedback` is a near-empty stub — confirm small state and convention, not deep excavation | Sonnet |

**"Magical" UX (live-walkthrough style, judged against the ecosystem's own stated design principles):**

| # | Task | Scope | Why this model | Model |
|---|---|---|---|---|
| 10 | Core admin UX | ecosystem hub, Debug Tools, Design Suite preview | Known nuance here already (a confirmed preview-vs-live mojibake bug in Design Suite) — needs careful judgment, not a surface skim | Opus |
| 11 | `bh-monetization-woo` UX | storefront, tiers, checkout | Mostly WooCommerce-native conventions already confirmed solid — lower-ambiguity check | Sonnet |
| 12 | `bh-courses` UX | catalog, course-taking flow, achievements/leaderboard | Recent complex 4-phase UX work plus a known empty-state gap — worth a closer read | Opus |
| 13 | `bh-contest` UX | voting, results, admin screens | Already confirmed largely solid in the prior audit — mostly re-verification | Sonnet |
| 14 | `bh-crm` UX | people/projects/activity screens | No major known issues on record | Sonnet |
| 15 | `bh-streaming` UX | library, player, search | Known empty-state gap to re-check — straightforward re-verification | Sonnet |
| 16 | `bh-registry` + `bh-feedback` UX (combined) | minimal admin surface + stub | Both small; `bh-feedback` is too early for a real UX pass, nothing more to check | Sonnet |

**Synthesis:**

| # | Task | Why | Model |
|---|---|---|---|
| 17 | Reconcile all 16 outputs into one cross-plugin summary; flag any `STATUS.md` claims now stale | Pure reasoning over text the other 16 tasks already produced — no fresh code reading, doesn't warrant delegating | **Me, inline — no agent** |

**Tally: 16 delegated agent tasks (1 Fable, 6 Opus, 9 Sonnet) + 1 inline synthesis = 17 total units of work.** Model spend is concentrated exactly where blast radius and ambiguity are highest (one Fable task, the single most architecturally complex subsystem) and spread thin everywhere the work is smaller or already well-scoped by prior audits.

### Token-saving tactics

- **I pre-digest the context once, instead of each task re-reading large source docs independently.** `VISION.md` (78KB), `STATUS.md` (19KB), and the two prior QA/UX reports are big files; each task's prompt gets only the excerpt actually relevant to its plugin/dimension (per the "why this model" column above), not an instruction to read the whole file itself.
- **The two smallest, lowest-stakes plugins are pre-merged** (`bh-registry` + `bh-feedback`, both dimensions — rows 9 and 16) since splitting them would add pure dispatch overhead for no quality gain. Already reflected in the task numbering above.
- **Tight output format per task** — the existing punch-list convention (see Output format above), not free-form narrative reports.
- **Checkpoint per plugin, not per task** — with core split into 3 tasks and every other plugin into 2 (code-quality + UX), checkpointing after all 16 individually would be excessive friction.

### Guardrails, fixed shape, no exceptions

- **17 units of work total, fixed — not "up to."** 16 delegated agent tasks + 1 inline synthesis. Sequential, never parallel, never nested.
- **No task spawns its own sub-agents** — stated explicitly in every task's prompt. This single constraint is what prevents spiraling even with 16 delegated pieces instead of 4.
- **No agent whose job is checking another agent's work.** Each auditing task owns its own verification discipline (confirm against real code before trusting a finding — see "Verification discipline" above).
- **Checkpoint per plugin by default** — I surface both outputs (code-quality + UX) for a given plugin together, then stop, unless AJ says "run straight through."
- **Model fallback, never silent substitution** — drop one tier (Fable→Opus→Sonnet) if a task's assigned model is unavailable, and say so.

**What "go" means, precisely:** when AJ says something like "run the ecosystem audit" or "go on the plugin audit plan," I run task 1 (core: identity/events/roles/audit, on Opus), then task 2, then task 3 (core: style/Design Suite, on Fable) — surfacing all three core outputs together as one checkpoint since they're one plugin — then stop and wait for confirmation before moving to `bh-monetization-woo`. If AJ would rather I run all 16 tasks straight through without checkpoints, they can say so explicitly at trigger time ("run the whole audit straight through") and I'll do that instead — still one task at a time, still no nesting, still exactly 17 total units of work.
