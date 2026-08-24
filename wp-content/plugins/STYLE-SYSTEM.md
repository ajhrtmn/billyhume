# The Self-Hosted Self ecosystem — style system reference

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

## Visual execution principles (applies ecosystem-wide, admin and front-end)

Established during the 2026-08 catalog/course/lesson redesign pass. These are concrete standards, not just aesthetic taste — apply them whenever building or reviewing any card grid, hero, or content surface, admin screens included once the front-end pattern is proven out.

- **No jagged/uneven card grids.** Two distinct causes, both found live in the same session: (1) CSS Grid's default `align-items: stretch` forces every card in a row to match its tallest sibling — use `align-items: start` instead so each card sizes to its own content; (2) even then, cards can still look jagged if their *internal skeleton* differs (some have an image, some don't; some have a footer CTA, some don't) — fix by giving every card the same shape: a consistent image/thumb slot (real asset or graceful placeholder) and reserved min-height for optional trailing content.
- **Depth and hover states are expected, not optional polish.** Standard treatment: a faint resting `box-shadow`, a hover lift (`translateY`) with an accent-tinted glow (`color-mix(in srgb, var(--accent) 35%, transparent)`), transitions on transform/shadow/border-color, and `prefers-reduced-motion` respected. Derive hover/glow colors from the existing accent token, never a new invented color.
- **Real placeholder art, not flat color blocks**, when there's no asset yet — an inline SVG illustration (no external dependency) over an accent-derived gradient, so unfinished content still reads as deliberate rather than a gap.
- **Overlay text on images with gradient scrims when it reads better** (poster/magazine-card treatment) rather than always stacking title text below an image.
- **Test and accommodate visual edge cases as standard practice**: long titles/names, missing optional content, zero/extreme values, locked/restricted states. Actually create test content and check the live render — this caught two real bugs in one session that were invisible from reading the CSS alone (a stale image with baked-in text creating a double-title, and a hero that silently degraded when there was no cover image).
- **Responsive means each breakpoint is independently optimized for its own viewing context** — not "mobile-first" in the progressive-enhancement sense. Design each significant breakpoint as its own considered layout, not a scaled-down version of the desktop one.
- **Live-verify every change in the actual browser** (screenshot + computed-style checks via the DOM), not just by reading the CSS.

## Plugin/theme independence — a hard architectural boundary, not a style preference

AJ's own words: "Encapsulate and abstract. The plugin styles should be theme independent. And the theme shouldn't depend on the plugins."

Found live: `BHM_Storefront::render_collection_page()`/`render_404()` called plain `get_header()`/`get_footer()` — correct only for a CLASSIC theme (real `header.php`/`footer.php` files). A block theme (Twenty Twenty-Five, or any future theme built for this ecosystem) ships neither, so those calls silently fell through to WordPress core's own bare theme-compat stub, or produced visibly wrong chrome. Confirmed as a **recurring** pattern, not a one-off — `bh-courses/templates/archive-bh_course.php` had already hit and fixed the identical failure earlier in the same session.

**The rule:**
- Any plugin-owned full-page render (a custom archive/landing page — not a shortcode/block embedded in ordinary post content) must detect and handle both classic and block themes, using the proven pattern already in this codebase: `wp_is_block_theme()` branching to `block_header_area()`/`block_footer_area()` (block theme) vs. `get_header()`/`get_footer()` (classic). Reuse the existing implementation (`archive-bh_course.php`, `BHM_Storefront::print_header()`/`print_footer()`) rather than reinventing it per plugin.
- Plugin CSS must never assume theme-supplied structure (a specific container width, font, color) — build a complete, self-contained layout from this ecosystem's own `--bh-*`/`--bhy-*` tokens, same posture already established for `.bhc-catalog-wrap`, `.bhm-storefront-wrap`, `.bhr-app`.
- The theme, in turn, must not depend on any plugin being active — its own templates/styles shouldn't break or look incomplete if a given ecosystem plugin is deactivated.

**Check both theme types before considering a plugin full-page template done** — the failure mode is silent (wrong chrome, not an error), so "it worked when I tested it" doesn't generalize across themes.

Also worth remembering: a custom `add_rewrite_rule()` does nothing until permalinks are flushed (Settings → Permalinks → Save, or `flush_rewrite_rules()` on activation) — an unflushed rule's URL silently falls through to normal site routing instead of hitting the plugin's `template_redirect` handler. Rule this out before assuming a routing bug is something deeper.

## Where this doc came from

Written after the 2026-08 audit pass: 579 inline `style=` sites and 17 `.css` files read in full across all 10 ecosystem plugins, which found ~600 inline styles ecosystem-wide, several live "uneven card height" / "badge text wraps" bugs (same root cause: a shared component existed but wasn't used), and — the reason this doc exists — the same badge/pill shape independently hand-rolled in at least 8 different plugin stylesheets with no shared front-end primitive to point at. See `AUDIT-inline-styles-MASTER.md` and `AUDIT-css-files-MASTER.md` (same directory) for the full findings this doc is downstream of.
