# Own Ur Shit ecosystem — inline-style audit (Phase 1: inventory)

Full read-through of every inline `style="..."` attribute across all 10 plugins, done 2026-08-01, following the same shape as tonight's two live-verified bugs (missing `white-space:nowrap` on a hand-rolled badge; a STALE-badge+button pair with no whitespace between them, forcing table overflow). Goal: find every place the ecosystem reinvented something the shared component system (`own-ur-shit/includes/class-ui.php` for admin, `own-ur-shit/includes/class-style.php` for front-end) already solves, every place it's still missing a real utility, and every place the same *kind* of bug is already live and unnoticed.

This is Phase 1 only — **inventory, not fixes**. Per-plugin detail lives in four companion docs in this same directory:

- [`AUDIT-inline-styles-own-ur-shit.md`](AUDIT-inline-styles-own-ur-shit.md) — 174 sites
- [`AUDIT-inline-styles-bh-contest-bh-crm.md`](AUDIT-inline-styles-bh-contest-bh-crm.md) — 131 + 90 sites
- [`AUDIT-inline-styles-bh-streaming-courses-monetization.md`](AUDIT-inline-styles-bh-streaming-courses-monetization.md) — 80 + 35 + 34 sites
- [`AUDIT-inline-styles-bh-live-registry-video-social.md`](AUDIT-inline-styles-bh-live-registry-video-social.md) — 16 + 7 + 7 + 5 sites

Total: **579 inline style sites read and categorized** across all 10 plugins (own-ur-shit 194, bh-contest 131, bh-crm 90, bh-streaming 80, bh-courses 35, bh-monetization-woo 34, bh-live 16, bh-registry 7, bh-video 7, bh-social 5).

---

## Live bugs found (same shape as tonight's already-fixed ones)

Ranked by how visibly broken the result is, worst first:

1. **`bh-monetization-woo/assets/css/storefront.css:58`** — `.bhm-product-card-title` has zero `white-space`/`overflow`/line-clamp handling. Product cards render in a CSS grid (`storefront.css:31-39`), so a long product title grows one card taller than its row-mates — the exact uneven-row-height bug already fixed in bh-courses' catalog tonight. Fix: add `.bh-clamp-2` in markup or the `-webkit-line-clamp` rule directly in the CSS, same as `class-render-catalog.php:164` already does.

2. **`bh-courses/includes/class-progress-admin.php:117`** — the Student Progress `<table>` is not wrapped in `.bhy-table-wrap`, despite `class-ui.php`'s own docblock literally naming this table ("genuinely one column per lesson, the actual worst-case width in this whole ecosystem") as the wrapper's reason for existing. It's currently rendering un-wrapped. High-priority, one-line fix.

3. **`bh-social/includes/class-admin.php:307`** — the per-platform stats table is not wrapped in `.bhy-table-wrap`, while the near-identical drafts table 200 lines later in the same file correctly is. Inconsistent within one file, easy mechanical fix.

4. **`bh-contest/includes/class-admin-list-tables.php:42,48`** — submission-status and vote-status pills are hand-rolled instead of `.bhy-badge`, and (like tonight's original bug) have no `white-space:nowrap`. In a narrow list-table column, a longer status label **will wrap** — same failure, not yet triggered/reported, but live.

5. **`own-ur-shit/includes/class-media-wizard.php` (4 sites) and `bh-streaming/includes/class-isrc.php:47`** — `.bhy-alert` class applied, then every property the class already provides gets re-declared inline anyway (in `class-isrc.php:47`'s case, with a *different* border-left width than the shared token — `3px` inline vs. `4px` in the component — meaning the alert silently stops tracking the design system for that one call site). Not visibly broken today, but a landmine: any future edit to `.bhy-alert` in `class-ui.php` won't reach these call sites, and nobody will notice until they visibly diverge.

6. **`bh-crm`, multiple files** — several fixed-pixel-width text inputs (300–600px) with no `max-width:100%` fallback (`class-projects.php:401`, `class-people.php:215`, `class-card-log.php:364/403/404`, others). On a narrow wp-admin viewport (tablet, or a metabox sidebar) these overflow their container instead of shrinking — same failure family as the catalog's missing mobile padding, just not yet visually confirmed in-browser.

None of the four small plugins (bh-live, bh-registry, bh-video, bh-social) had this bug shape beyond #3 above — each was explicitly checked and ruled out (see their doc for the specific reasoning per plugin, including one case in bh-social where six badge-after-heading call sites were individually string-checked and confirmed to have a real trailing space, so they do *not* reproduce tonight's whitespace bug).

---

## Cross-plugin genuine gaps (Phase 2 candidates — extend class-ui.php / class-style.php)

These are shapes that repeat across *multiple* plugins, which is the strongest signal for "belongs in the shared layer, not a one-off." Roughly ordered by combined occurrence count:

| Proposed class | Surface | Shape | Where it repeats |
|---|---|---|---|
| `.bhy-text-success` / `.bhy-text-danger` / `.bhy-text-warning` / `.bhy-text-dim` | admin | text-only semantic color, no badge background/padding | own-ur-shit (8), bh-crm (8), bh-streaming (2+2), bh-courses (4), bh-monetization-woo (5), bh-live (health status, 4) — **~35 occurrences, the single largest cross-plugin gap** |
| `.bh-hidden` / `.bhy-hidden` | both | replace inline `style="display:none;"` used as a JS-toggle initial state, so toggle JS flips a class instead of `element.style.display` | bh-streaming (~15, concentrated in `class-player.php`), bh-courses (5), own-ur-shit, bh-live/registry/video (initial-hidden states) — **~25 occurrences** |
| `.bhy-input-full` | admin | `width:100%` on form inputs/textareas | own-ur-shit (10), bh-contest (15+, single largest count in that plugin), bh-crm, bh-streaming (10), bh-monetization-woo (8), bh-live/registry — **~50+ occurrences, likely the single most-repeated exact string in the whole codebase** |
| `.bhy-media-preview` | admin | `max-width:480px;margin-bottom:8px;` wrapper around an uploaded media preview | **verbatim duplicate across bh-live and bh-video**, two separate plugins — strongest possible signal for a shared component |
| `.bh-video-fill` | front-end | `width:100%` on `<video>` elements | bh-live (2) + bh-video (2), including JS-inserted markup |
| `.bh-embed-16x9` | front-end | `width:100%;aspect-ratio:16/9;border:0;` on embed iframes | bh-live, 2 near-identical engine implementations |
| `.bh-chat-embed` | front-end | `width:100%;height:100%;min-height:400px;border:0;` on chat iframes | bh-live, 2 implementations |
| `.bhy-chip` / `.bhy-chip-remove` | admin | dismissible tag/filter chip (asymmetric padding for trailing ×), distinct shape from `.bhy-badge` | bh-crm (saved-list tags) — proposed as generic enough to expect in bh-contest too |
| `.bhy-progress` / `.bhy-progress-fill` | admin | progress bar track+fill, currently hand-rolled per call site with inline conditional fill color | bh-crm (2), bh-courses (1, plus a possibly-dead `.bhc-admin-progress-bar` class worth checking) |
| `.bhy-option-card` (+ `.active` modifier) | admin | radio-button-as-card pattern (`border:2px solid X;border-radius:8px;padding:12px 14px;cursor:pointer`) | own-ur-shit (2 files, verbatim) |
| `.bhy-thumb-preview` | admin | 64×64 flex-centered logo/thumbnail preview box | own-ur-shit (2 files, verbatim) |
| `.bhy-mt-4/5/6/8` | admin | heading top-margin, should map onto the existing `--bhy-space-1..8` scale | own-ur-shit (17 occurrences) |
| `.bhy-visually-hidden` | admin | off-screen copy-source textarea (`position:absolute;left:-9999px`) | own-ur-shit (3, verbatim) |
| `.bhy-row-between` / `.bhy-inline-dot` | admin | flex space-between row / small status dot, used outside the badge component | bh-contest |
| `.bhy-input-xs/sm/md/lg` | admin | small-numeric and general fixed-width input scale, currently ad hoc magic numbers per call site | bh-contest, bh-crm (10 distinct widths, doesn't cleanly bucket — recommend rounding to a 3-4-step scale) |
| `.bhy-form-inline` | admin | `display:inline`/`inline-block` on single-button admin-post forms | bh-crm (5) |

**Not** worth a new class (per the agents' explicit checks): `bh-registry`'s and `bh-video`'s actual badge/card/grid CSS already lives in real stylesheet files (`registry.css`, `video-player.css`), not inline — so those plugins' visual risk (unclamped titles, missing nowrap) needs a *separate* CSS-file audit pass, not an inline-style fix. Flagging this as a known blind spot of this Phase 1 pass: **inline `style=` attributes are not the only place these bugs can hide** — the same clamp/nowrap/wrap checks should eventually be run against every plugin's own `.css` files too, not just PHP.

---

## Mechanical fixes (Phase 3 — swap to existing class, no new CSS needed)

Full list with file:line citations is in each companion doc's "(a) Duplicates" section. Highlights:

- **Status/vote/pass-fail pills → `.bhy-badge` family**: own-ur-shit (5), bh-contest (3, including one that also duplicates `.bhy-badge-dot`), bh-live (4, hex colors that map onto the existing success/warning/danger tokens).
- **Notice/warning boxes → `.bhy-alert` family**: own-ur-shit (4, redundant re-declaration), bh-contest (4, one with a dark-mode-only palette worth normalizing to the token), bh-monetization-woo (2, hand-rolled from scratch instead of using the existing class — colors already match `--bhy-warning-bg`/`--bhy-warning` almost exactly).
- **Card surface → `.bhy-card`**: bh-crm (1, hand-rolled using the design tokens as fallback values — clear evidence the author knew the token existed but not the component class), bh-streaming (3), bh-courses (2, fully redundant re-declaration on top of the class — the worst version of this pattern found anywhere).
- **Table wrapping → `.bhy-table-wrap`**: own-ur-shit (4), plus the two live bugs already listed above (bh-courses, bh-social).
- **Single-line truncation → `.bh-truncate`**: bh-streaming's `.bhs-card-title` (in `player.css`, not inline — hand-rolls the same 3 declarations `.bh-truncate` already provides).

Two **good control examples** worth noting (the pattern working as intended, cited so the "how it should look" reference exists): `bh-monetization-woo/includes/class-admin.php:140` (`.bhy-table-wrap` + a genuinely novel inline `max-width`) and `class-admin.php:173` (`.bhy-alert bhy-alert-success/danger` + only a genuinely novel `max-width`) — both are the class carrying the shared shape and the inline style carrying only the one legitimately page-specific value. Several of bh-courses' progress-bar fills (`class-render-catalog.php:181`, `class-render-course.php:204,310`, `class-portal-panel.php:127`) are the same correct pattern for dynamic width values.

---

## Genuinely one-off (leave alone)

Roughly 130-150 sites across all plugins are bespoke, single-occurrence layout that isn't worth abstracting — onboarding wizards, theme-preview mockup screens, one-shot form layouts, decorative dividers. Each companion doc lists representative examples rather than every single site, per the "don't force everything into a class for its own sake" principle. No action needed on these.

---

## Suggested sequencing from here

1. Fix the 6 live bugs above first — they're small, independently verifiable, and match a pattern you already know how to live-verify (Browser tools against localhost:10008, desktop + mobile widths).
2. Extend `class-ui.php`/`class-style.php` with the highest-confidence Phase 2 gaps — `.bhy-text-*`, `.bh-hidden`/`.bhy-hidden`, `.bhy-input-full`, `.bhy-media-preview` are the four with the strongest repeat-count/cross-plugin signal and the least design ambiguity (no new visual language, just naming what already exists in the tokens).
3. Batch Phase 3 mechanical swaps by content-category (all badges ecosystem-wide, then all alerts, then all cards, then all tables) rather than by plugin, live-verifying each batch.
4. Treat the CSS-file blind spot (registry.css, video-player.css, storefront.css, player.css) as its own follow-up pass — this audit only covered inline `style=` attributes in PHP, not rules already living in `.css` files, and at least one confirmed live bug (`storefront.css:58`) was only caught because an agent went and read the CSS directly instead of trusting the inline-style grep alone.
