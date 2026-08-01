# Own Ur Shit ecosystem — style system reference

One page, kept up to date, so the next contributor can check here before adding a new spacing value, color, badge, or table treatment instead of re-deriving it from source archaeology. Written after the 2026-08 inline-style + CSS-file audit found the same handful of shapes (status pills, notice boxes, wide tables, truncated card titles) independently reinvented 5-9 times each across the ecosystem.

## The four layers

Every plugin's styling — admin or front-end — belongs in one of these. When adding a rule, ask "which layer is this?" before writing it.

### Layer 1 — Tokens
Custom properties only. No selectors, no components — just named values (`--bhy-space-4`, `--bh-success`) with sane fallbacks, so the rest of the system can reference them instead of repeating a literal.

- **Admin**: `own-ur-shit/includes/class-ui.php`, `BHY_UI::design_system_css()`, the `:root { ... }` block at the top. Printed on every admin screen whose hook suffix contains `bh`/`ous` (`BHY_UI::print_design_system_css()`).
- **Front-end**: `own-ur-shit/includes/class-style.php`, `BHY_Style::inline_css()` (site-wide/per-entity theme colors — `--bh-accent`, `--bh-surface`, etc., user-configurable via Settings & Style) and `BHY_Style::badge_css()`'s own `:root` block (fixed semantic status colors — `--bh-success/-warning/-danger`, NOT user-themeable, since these mean "this thing succeeded/failed," not "this is my brand color"). Printed on every front-end page via `wp_head` (`BHY_Style::print_global_css()`).

**Adding a token**: name it consistently with its siblings (`--bhy-*` admin, `--bh-*` front-end), give it a fallback value everywhere it's referenced (`var(--bh-accent, #2271b1)`), and put it in the right sub-category — brand/themeable colors go through `inline_css()`'s per-entity override system, fixed semantic colors do not.

### Layer 2 — Utilities
Generic, single-purpose, composable. No visual identity of their own — they modify how *other* content behaves (wraps, truncates, clamps).

- **Admin**: `class-ui.php`, inline inside `design_system_css()` — `.bhy-truncate`, `.bhy-clamp-2`, `.bhy-badge-truncate`.
- **Front-end**: `class-style.php`, `BHY_Style::text_overflow_utils_css()` — `.bh-nowrap`, `.bh-truncate`, `.bh-clamp-2`, `.bh-clamp-3`, `.bh-badge-truncate`.

**Rule of thumb for which one to reach for**, per that method's own docblock in `class-style.php`:
| Content shape | Class |
|---|---|
| Badge/pill, fixed-vocabulary label ("Approved", "Live") | `.bh-nowrap` (front-end) / built into `.bhy-badge` already (admin) |
| Badge/pill, dynamic/unbounded label (user tag, artist name) | `.bh-badge-truncate` |
| Single-line non-badge content (table cell, list title) | `.bh-truncate` |
| Multi-line card title/description | `.bh-clamp-2` / `.bh-clamp-3` |

**If you're about to write `white-space:nowrap;overflow:hidden;text-overflow:ellipsis` or `-webkit-line-clamp` by hand anywhere** — stop, one of these four already does it. This exact hand-rolling (instead of reusing the class) was found repeated in `bh-streaming`, `bh-video`, and `bh-contest` during the audit; it's not wrong, it's just a maintenance trap the next re-theme won't reach.

### Layer 3 — Components
Named, reusable UI pieces built from tokens + utilities. This is the layer that gets reinvented most — check this table before writing a new one.

| Component | Admin class | Front-end class | Covers |
|---|---|---|---|
| Status pill | `.bhy-badge` + `-success/-warning/-danger/-neutral`, `.bhy-badge-dot` | `.bh-badge` + `-success/-warning/-danger/-neutral` | Any "state" indicator — open/closed, pass/fail, live/off-air |
| Notice/alert box | `.bhy-alert` + `-warning/-success/-danger/-info` | *(none yet — front-end has no alert component; if you need one, this is a real Layer 3 gap, add it to `class-style.php` rather than hand-rolling)* | Bordered/tinted callout box |
| Card/surface panel | `.bhy-card` | *(front-end pages generally use theme-native content styling, not a card component — confirm before assuming a gap)* | Background+border+radius+padding surface |
| Wide/scrollable table | `.bhy-table-wrap` (+ `--tall` modifier), `.bhy-sortable` | *(admin-only concept — front-end doesn't currently have data tables of this shape)* | Horizontal/vertical scroll, sticky header, container-query density |
| Copy-to-clipboard button | `.bhy-copy-btn` | — | — |
| Empty state ("nothing here yet") | — | `BHY_UI::empty_state_html()` | Zero-results / zero-data front-end states |

**Before hand-rolling a badge, alert, card, or table wrapper anywhere in this codebase — admin or front-end — check this table first.** If the shape you need isn't here, that's a real gap (like the front-end `.bh-alert` gap noted above) — add it here, in the shared file, not as a one-off.

### Layer 4 — Plugin-local
Everything else: page-specific layout that doesn't repeat elsewhere. Lives in each plugin's own `assets/css/*.css` (front-end) or inline in that plugin's own admin-render PHP (admin). This layer should **reference** Layers 1-3, not re-declare them — e.g. a plugin-local rule can use `var(--bh-accent, #2271b1)`, but shouldn't redefine what `#2271b1` means, and a plugin-local pill shouldn't redeclare `border-radius:999px;padding:2px 10px` when `.bh-badge` already provides it.

**A color repeated verbatim across 2+ plugins is a signal, not a coincidence — but don't assume which one it means.** `#1DB954` showed up identically across bh-contest, bh-streaming, bh-monetization-woo, and bh-registry's front-end code well before `--bh-success` existed — turned out to already BE this ecosystem's real front-end "success/positive/open/live" convention, just never named. `--bh-success` (Layer 1, `class-style.php`) is now `#1DB954` for exactly that reason — resolved 2026-08 after finding it also nearly became a *fourth*, unused green by defaulting to admin's `--bhy-success` (`#1a7f37`) shade instead of the one the front-end had already standardized on. Lesson for next time: when a hex repeats across plugins, check what it's actually being used FOR (here: several different files independently reaching for "this thing succeeded/is live/is open") before promoting it — and if a value like this is genuinely admin-only (own-ur-shit's dashboard, bh-contest's admin reports also use `#1DB954` in a few spots) it's a separate decision, since `--bh-success` (front-end, printed via `wp_head`) never reaches wp-admin pages at all — that's still an open question, not yet resolved.

## Decision checklist before writing a new style rule

1. **Is this a color/spacing/type value?** → Layer 1. Does a token for it already exist? Use it. Does it need to vary per-entity (brand color)? It goes through `inline_css()`'s override system. Is it a fixed semantic meaning (success/warning/danger)? It's a plain constant token, not themeable.
2. **Is this "don't wrap" / "truncate" / "clamp to N lines," with no visual identity of its own?** → Layer 2. Check the table above for which of the four utilities fits the content shape.
3. **Is this a named, recognizable "thing" (a badge, an alert, a card, a table)?** → Layer 3. Check the component table above. If it doesn't exist yet and the shape will plausibly recur, add it here — don't hand-roll it locally "just this once" (that's exactly how the ecosystem ended up with ~600 inline styles and 8 independently-reinvented badge shapes in the first place).
4. **Is this genuinely one page's bespoke layout, unlikely to recur?** → Layer 4, plugin-local. Reference tokens/utilities/components from Layers 1-3 rather than re-declaring their values.

## Where this doc came from

Written after the 2026-08 audit pass: 579 inline `style=` sites and 17 `.css` files read in full across all 10 ecosystem plugins, which found ~600 inline styles ecosystem-wide, several live "uneven card height" / "badge text wraps" bugs (same root cause: a shared component existed but wasn't used), and — the reason this doc exists — the same badge/pill shape independently hand-rolled in at least 8 different plugin stylesheets with no shared front-end primitive to point at. See `AUDIT-inline-styles-MASTER.md` and `AUDIT-css-files-MASTER.md` (same directory) for the full findings this doc is downstream of.
