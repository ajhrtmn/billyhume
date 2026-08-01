# Inline-style audit — own-ur-shit

174 inline `style="..."` sites found across `own-ur-shit/includes/*.php` (excluding the 3 doc-comment mentions of the `style="..."` attribute itself in class-style.php and class-menu-icons.php, and the class-element.php lines that build a `style="..."` attribute dynamically from a validated declaration string — those are the *mechanism*, not something to fix).

Shared components already available to check against:
- `.bhy-badge` + `-success/-danger/-neutral` (admin, class-ui.php ~733) — colored status pill.
- `.bhy-alert` + `-warning/-success/-danger/-info` (admin, class-ui.php ~719) — bordered/tinted notice box.
- `.bhy-table-wrap` (admin) — table wrapper.
- `.bh-truncate`, `.bh-clamp-2`, `.bh-clamp-3` (front-end, class-style.php ~104-106).
- `--bhy-space-1..8` (admin scale, class-ui.php ~680), `--bh-space-xs/sm/md/lg/xl` (front-end scale, class-style.php ~664).

## (a) Duplicates an existing shared component — mechanical fix

**Pass/fail/status pills that should be `.bhy-badge`.** Currently hand-rolled as `color:#fff;background:X;padding:2px 8px;border-radius:3px;font-size:11px` (or `font-weight:600` variant) — the exact shape `.bhy-badge` exists to cover, and the exact bug class that hit tonight's registry table (missing `white-space:nowrap`, which `.bhy-badge` provides for free).
- `class-test-runner.php:154-155` — PASS/FAIL pills.
- `class-debug-log.php:660` — log-level pill (`strtoupper($r['level'])`).
- `class-api-docs.php:242` — HTTP method pill (GET/POST/etc).
- `class-media-wizard.php:229,133(dup line ref)` — "Recommended" pill on provider cards.
Total: ~5 near-identical instances.

**`.bhy-alert` re-declared inline on top of the class.** `class-media-wizard.php:129,139,160,168,433,483` all do `class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px;margin:16px 0;max-width:760px;"` — the class already supplies border-left/background/padding/margin, so most of this inline style is dead weight fighting the class; only `max-width:760px` is actually novel (see genuine gap below). `class-debug.php:384` does the same pattern without even using the `-info` variant that already matches its blue accent color.

**Table wrapping/width duplicating `.bhy-table-wrap`.** `class-debug-log.php:606,651`, `class-test-runner.php:151`, `class-portal-layout.php:95` set `margin-top`/`max-width` on `<table class="widefat">` outside a `.bhy-table-wrap` — should be wrapped consistently like the other tables in the same files.

## (b) Genuine gaps — no utility exists yet

1. **Semantic text-only color** (no badge background, just `color:#00a32a` success / `color:#d63638` danger on plain text/spans). ~8 occurrences: `class-debug-log.php:8,9,10,11` (health-check states), `class-two-factor.php:210` (error text), `class-studio.php:123` (missing-content warning), `class-test-runner.php:143`. Propose `.bhy-text-success` / `.bhy-text-danger` / `.bhy-text-warning` matching the badge palette variables (`var(--bhy-success)` etc. already exist per class-ui.php:725-726).

2. **Visually-hidden copy-source pattern.** `<textarea style="position:absolute;left:-9999px;">` appears identically 3x: `class-debug-log.php:649`, `class-test-runner.php:133,149` — a "select-all-and-copy" trick. Propose `.bhy-visually-hidden`.

3. **Heading top-margin.** `margin-top:16/20/24/32px` on `h2`/`h3`/`h4` — 17 occurrences across `class-dashboard.php:121,146,201`, `class-element.php:1911,1924`, `class-test-runner.php:141`. These are section-break headings inside admin pages that otherwise have no spacing convention. Given the existing `--bhy-space-1..8` scale (4/8/12/16/20/24/32px), propose `.bhy-mt-4/.bhy-mt-5/.bhy-mt-6/.bhy-mt-8` utilities mapped 1:1 to that scale rather than a new one.

4. **`max-width:760px` container pattern.** Repeated ~15x throughout `class-media-wizard.php` on forms/divs/hr/alerts (lines 219,293,295,299,330,334,337,342,343,361,365,368,372,374,376,406,433,439,442,446,448,481,483,489,498,505,511). This isn't really a "utility class" gap — it's that the whole wizard should render inside one `<div style="max-width:760px">`/`.bhy-wizard-wrap` wrapper instead of repeating the constraint on every child element. Worth a `.bhy-wizard-wrap` (or generic `.bhy-content-narrow`) class either way.

5. **Wide-input pattern.** `width:100%;max-width:480px;` on text/password inputs — 10 occurrences in `class-media-wizard.php` (137,146,148,153,155,157,164,166,173) plus `class-page-surface.php` inputs. Propose `.bhy-input-wide`.

6. **Thumbnail/logo preview box.** Identical flex block (`width:64px;height:64px;border:...;border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto`) at `class-style-gallery.php:318` and `class-setup-wizard.php:155` — verbatim duplicate across two different files. Propose `.bhy-thumb-preview` component (with the inner `<img>` getting `.bhy-thumb-preview img { width:100%;height:100%;object-fit:contain }` instead of per-instance inline).

7. **"Provider/option card" pattern** (radio-button-as-card): `display:block;border:2px solid X;border-radius:8px;padding:12px 14px;cursor:pointer;background:#fff;` — `class-style-surface.php:73,77` and `class-media-wizard.php:227` are near-identical, differing only in the border color being conditional on `checked`. Propose `.bhy-option-card` + `.bhy-option-card.active` (border color driven by the modifier class, not inline conditional).

## (c) Genuinely one-off

Most of `class-style-surface.php` and `class-style-gallery.php` — these are bespoke onboarding/gallery screens with layout that doesn't recur elsewhere (grid template columns for a two-provider comparison, brand wordmark split-input layout, color swatch preview rows). ~25 sites here are reasonably left inline. Same for one-off decorative touches like `class-revisions.php`'s masonry column layout (`column-width:220px;column-gap:12px`) and `class-codebase-docs.php`'s syntax-highlight `<pre>` background — single call sites, not worth a class.

## Bugs/regressions found

None beyond what's already fixed tonight (the `.bhy-badge` wrap bug and the STALE-badge+button whitespace/overflow bug, both in `class-registry.php`, already patched). No other own-ur-shit inline-style site currently produces broken/overflowing layout on inspection — the issues here are consistency/duplication, not live breakage. Worth a live pass in the browser regardless once (a)/(b) are fixed, to catch anything static reading missed.
