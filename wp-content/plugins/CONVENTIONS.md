# Code conventions — documentation, structure, naming

**Replaces the changelog-comment-block convention, 2026-08-23.** That convention was right about *what* to preserve and wrong about *where*. This file says where each kind of knowledge goes.

## The measurement that prompted this

| File | Total | Code | Comment |
|---|---|---|---|
| `the-self-hosted-self.php` | 2,555 | **115** | 2,299 (90%) |
| `self-hosted-self-admin-skin.php` | 1,724 | **111** | 1,528 (89%) |
| `bh-monetization-woo.php` | 466 | **41** | 397 (85%) |
| `bh-courses.php` | 809 | **118** | 646 (80%) |
| **All our PHP** | **78,268** | — | **29,137 (37%)** |

`the-self-hosted-self.php` is a 115-line file wearing a 2,299-line coat.

## Why the old convention has to change

It conflated three unrelated kinds of knowledge into one append-only blob in the wrong file:

1. **It duplicates git.** Git already records what changed, when, by whom, and the exact diff — queryable with `log`, `blame`, and `bisect`. A hand-maintained second copy is strictly worse: it can't be diffed, drifts from reality, and no tooling can check it.
2. **It's append-only and never pruned.** `0.1.0` entries still sit in files at version 3.10.42. Nobody reads entry 40.
3. **It's filed away from what it explains.** The rationale for a CSS rule lived in a PHP file 1,500 lines from the CSS. Hit live this session: the `#f6f7f7` fix reasoning was in `admin-skin.css` while the version bump was in the `.php`.
4. **It has already caused a site-wide outage.** An unescaped apostrophe *in a comment inside a large single-quoted string* silently terminated the string and produced a fatal parse error. Large prose blocks near string literals are a live hazard in this codebase, not a hypothetical.
5. **It substitutes for good design.** A 40-line comment explaining why a class exists usually means the class isn't named or shaped clearly enough. Prose is the cheapest fix and the least durable one.

**What it got right, and must not be lost:** this project was built across sessions with no shared memory, so those comments became the continuity mechanism. They record *why*, and which decisions must not be re-litigated. That knowledge is genuinely valuable and mostly absent from the code. It moves — it does not disappear.

## Where each kind of knowledge goes now

| Knowledge | Home |
|---|---|
| What changed, when, by whom | Git history |
| Release-level "what changed in 0.38.0" | `CHANGELOG.md` per plugin |
| Why this code is shaped this way (durable) | Source, ≤3 lines, adjacent to the code |
| Non-obvious constraint a reader might "fix" and break | `// WHY:` one-liner at the exact line |
| Decisions not to re-litigate; incidents | `CLAUDE.md`, `STATE.md`, `OPEN.md`, `DESIGN-CRAFT.md` |
| What's built / what's open | `STATE.md` / `OPEN.md` |

## The rules

**1. Code carries its own meaning first.** Before writing a comment, try: a better name, a named constant instead of a magic value, a small extracted function with a descriptive name, a typed signature, a guard clause instead of nesting. A comment that restates the code gets deleted.

```php
// Bad — comment carries meaning the code should
$d = (time() - $t) / 86400;          // days since enrollment

// Good — code carries it
const SECONDS_PER_DAY = 86400;
$days_since_enrollment = (time() - $enrolled_at) / SECONDS_PER_DAY;
```

**2. Comments answer "why," never "what."** The code says what. If "why" needs more than ~3 lines, that's a signal: extract a well-named function, or move it to a doc and link it.

**3. `// WHY:` for load-bearing non-obvious constraints.** Short, greppable, at the exact line. Reserve it for things that look removable and aren't:

```php
// WHY: !important — the last-resort rule below has equal specificity and wins on source order.
```

**4. Docblocks earn their place.** Public API, non-obvious params, array shapes, return contracts. Not `@param string $name The name`.

**5. No changelog blocks in source.** Version constant only. Narrative goes in the commit message; release-level summary in `CHANGELOG.md`.

**6. Delete dead and stale comments on sight.** A comment describing code that no longer exists is worse than none — it is actively misleading. Two real cases this session: `CLAUDE.md` cited two docs that never existed, and `PAGE-BUILDER-REBUILD-PLAN.md` described a class deleted long ago as "actually built and seeded."

**7. Commit messages carry the narrative.** This is where the old changelog voice belongs — full reasoning, what was measured, what was ruled out. It's attached to the exact diff, and `git log`/`blame` retrieve it on demand.

## Version bump discipline (unchanged, minus the block)

Every real change still bumps the plugin header `Version:` and the matching constant in the same commit. What changes: the narrative goes to the commit message and `CHANGELOG.md`, not a comment block above the constant.

## Structure: SOLID as applied here

- **Single responsibility** — if a class needs "and" to describe it, split it. Functions over ~50 lines are a smell; over ~100 is a defect unless it's a flat data blob (a CSS string, schema DDL).
- **Encapsulation** — no reaching past an owner for its data. Physical details (table names, option keys, meta keys) live behind a named accessor in one class. `BHM_Tables` is the reference: 64 inline `$wpdb->prefix . 'bhm_…'` constructions became one owner, so a typo is a fatal instead of a silent query against a nonexistent table.
- **DRY, with judgment** — the same *knowledge* in two places is a bug. Two similar-looking lines that answer to different reasons are not duplication; don't over-abstract them into a shared helper with a boolean flag.
- **Dependency direction** — peer plugins depend only on the core, never each other, always `class_exists()`-guarded at hook-call time. Unchanged and non-negotiable.
- **Open/closed** — extend through the existing filters (`ous_debug_tools`, `bhcore_test_suites`, `bhy_style_surfaces`) rather than editing the core.

## Cross-plugin guards: check the METHOD, not the class

`class_exists('OUS_Pages')` is not a sufficient guard when you are calling a method that core only recently gained.

A peer plugin can deploy ahead of core — that is the normal state on any host that syncs plugin folders independently, and it is what happened live on 2026-08-24. The class was present, the method was not, and the site died with **`Call to undefined method OUS_Pages::ensure()`** on every admin page. `class_exists()` returned `true` and the call fatalled anyway.

```php
// Wrong when the method is newer than the class:
if (!class_exists('OUS_Pages')) return;
OUS_Pages::ensure(...);

// Right:
if (!method_exists('OUS_Pages', 'ensure')) return;
OUS_Pages::ensure(...);
```

`class_exists()` remains correct for guarding a whole *class* that may be absent. The moment you call a method added after that class shipped, the guard has to name the method — because "is this plugin installed" and "is this plugin new enough" are different questions, and only the second one is what the call actually depends on.

Still at hook-call time, never at file-parse time. Both rules apply together.

## Wide tables

`.bhy-table-wrap` (BHY_UI, `class-ui.php`) is still the convention: sticky header, horizontal scroll, denser padding. Use it.

**It is now backed by a safety net rather than trust.** An audit on 2026-08-24 found **21** `table.widefat` instances rendered without the wrapper against **18** with it — more than half ignored the rule, and the visible result was Project Tracker overflowing horizontally on a phone (a 586px table, `overflow: visible`, no scroll parent).

Below 782px, `.wrap table.widefat` / `.wrap table.wp-list-table` now become scrolling blocks themselves, and the wrapped case is explicitly restored to `display: table` so a scroll container never nests inside another. That covers tables added later that forget the wrapper.

The net makes a missing wrapper *survivable*, not correct — you still lose the sticky header and the denser padding. Wrap new tables.

## The rendering layers — which tool owns what

Three rendering technologies now coexist: Timber/Twig, Datastar, and Lit.
That is only an improvement if the boundary is explicit. Without a rule,
"use each where it makes sense" becomes four idioms and *more* context to
hold, which is the opposite of the point.

**The deciding question is: where does the state live?**

| Where state lives | Tool | Owns |
|---|---|---|
| Server, rendered once per request | **Timber/Twig** | page structure, component markup, lists, tables, forms, admin screens |
| Server, changes over time | **Datastar** | live status, anything that would otherwise be a REST-polling loop or a hand-rolled fetch-and-replace |
| Browser, local to one widget | **Lit** | players, editors, drag-reorder, timelines, canvases — behaviour with no server round-trip |
| — | **plain PHP** | the fallback when the template engine is unavailable (see `BHY_View::is_available()`) |

**Worked examples.**

- A supporter-tier table → Timber. Server renders it once; nothing changes until the page reloads.
- A "sync in progress" badge that updates as a job runs → Datastar. State is authoritative on the server and changes over time.
- The audio player's scrub bar → Lit. Position, buffering and waveform are browser-local; the server has no opinion mid-track.
- A quiz editor reordering questions → Lit for the drag interaction, Timber for the initial render, and a normal form POST to persist. All three, each doing its own job.

**Rules that keep this from sprawling.**

1. **Default to Timber.** It is the boring choice and correct for most of this codebase — the measured markup surface is ~2,857 lines of server-rendered output. Reach for the others only when the table above says to.
2. **Never use two for one job.** If Datastar can swap a fragment, do not also build a Lit component that fetches the same data. The overlap between Datastar and Lit is the real risk; Timber barely overlaps either.
3. **Lit components must render into light DOM.** Shadow DOM encapsulates styles, which would cut them off from `--shsas-*`/`--bhy-*` tokens and `admin-skin.css` — the design system stops reaching inside. Override `createRenderRoot()` to return `this`.
4. **Lit is progressive enhancement, not the render path.** The server sends real, styled, working markup; the custom element upgrades it. If the JS never loads, the page still works. A component that renders nothing until hydrated is doing it wrong here.
5. **Everything degrades.** Timber falls back to PHP, Datastar falls back to a normal form POST, Lit falls back to the server-rendered markup underneath it. This ecosystem runs on ordinary shared hosting and a WASM runtime with no openssl; assume the enhancement layer can be absent.

## Sharing code between plugins

The recurring question is whether this ecosystem needs a NuGet-style shared-project mechanism, especially now that a build step exists. **It already has one, and the build step is the wrong lever.**

**What exists.** All 12 peers declare `Requires Plugins: the-self-hosted-self`, which WordPress 6.5+ enforces at activation (this install runs 7.0). The core *is* the shared library, and the dependency is declared and enforced — that is the "shared project reference."

**The hard constraint that shapes everything.** WordPress loads active plugins sorted by path, so:

```
bh-contest → bh-courses → bh-crm → bh-monetization-woo → bh-streaming → the-self-hosted-self
```

**The core loads LAST.** Every `bh-*` peer's main file parses before it. So a peer can never `extends`, `implements`, or `use` a *core* class at file-parse time — it would fatal on a cold load. This is why every core reference happens inside `plugins_loaded`/`init` as a guarded static call. That is not a workaround; it is the correct response to the constraint, and it should stay.

**Share contracts, not implementations.**

- **Interfaces + adapters** are the right pattern, and `bh-social` already demonstrates it: `BH_SocialPlatform` / `BH_AdsPlatform` with a per-provider implementation each (YouTube, Twitch, Meta, TikTok; Roku, Spotify, Samsung, Vizio…). Note *why* it works — those interfaces live inside `bh-social` and load before their implementors in the same `require_once` loop, so parse order is fully controlled. Keep an interface and its implementors in one plugin.
- **Cross-plugin contracts** (shared meta keys, event names, capability names) belong in the core as constants, read at runtime. `OUS_Visibility::META_KEY` is the reference.
- **Behaviour** crosses plugin boundaries through guarded static calls (`class_exists('BHM_PurchaseLedger') && BHM_PurchaseLedger::confirmed_purchases()`), never through inheritance.

**What NOT to do.**

- **Don't add a PHP build step.** Composer here is deliberately dev-only — its own `composer.json` says so: *"Not a runtime dependency of any plugin — WordPress itself is loaded from wp-load.php, never Composer's autoloader."* A runtime Composer dependency breaks "install a zip on ordinary shared hosting," which is a VISION-level constraint, not a preference. The `tsc` build is fine because it produces committed, plain `.js` that WordPress enqueues directly — no runtime dependency at all. Apply the same test to any future build step: **does the shipped artifact still work with nothing but PHP and WordPress?**
- **Don't vendor or inline shared code at build time.** That is the drift problem, and this codebase has paid for it repeatedly: eleven near-identical table classes, `.bhm-paywall` duplicated into `bh-streaming` and silently diverging, eight hand-rolled badge shapes. Copies drift; references don't.
- **Don't over-share.** Eleven `class-tables.php` files look like duplication, but each is a genuinely different data map; a shared base would save ~15 lines per plugin and buy a parse-time coupling risk. Structural similarity is not duplication — *the same knowledge in two places* is.

## Reviewing your own comment

Ask: **would a competent reader who knows PHP and WordPress need this?** If it explains the language, delete it. If it explains the domain or a constraint, keep it — short. If it explains history, it belongs in git.
