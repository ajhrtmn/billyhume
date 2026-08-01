# CSS-file design-system audit — bh-contest & bh-monetization-woo

Follow-up to the inline-style-only audit. This pass covers actual `.css` files (not inline PHP styles) in the four target stylesheets, checked against the shared design-system primitives in `own-ur-shit/includes/class-ui.php` (admin: `--bhy-*`, `.bhy-badge`, `.bhy-alert`, `.bhy-table-wrap`, `.bhy-card`) and `own-ur-shit/includes/class-style.php` (front-end: `--bh-*`, `.bh-truncate`, `.bh-clamp-2`, `.bh-clamp-3`).

Confirmed pre-fix: `bh-monetization-woo/assets/css/storefront.css` `.bhm-product-card-title` had zero line-clamp handling — already fixed by adding `display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;`. That fix is verified in place at line 58-61 of the current file. The rest of storefront.css was re-checked in full below; it is otherwise clean.

---

## bh-contest/assets/css/judging.css

Small file (57 lines), single-column list layout (`.bh-judge-panel { max-width: 720px; }`), not a grid — so the highest-priority "uneven card height" bug shape doesn't really apply here. Findings are minor.

**Genuine gap:**
- `.bh-judge-entry-title` (line 35-39) and `.bh-judge-artist` (line 40) render a track title and artist name with no `overflow`/`text-overflow`/`white-space` handling at all. These are user-submitted, unbounded-length strings (same content type as `.bh-track-title`/`.bh-track-artist` in player.css, which DO truncate). A long title/artist here will just wrap freely inside `.bh-judge-entry`, which is harmless layout-wise (no grid, no fixed height) but is inconsistent with how the same data is handled everywhere else in the plugin. Low risk, but worth aligning — either wrap in `.bh-truncate` (class-style.php) or leave as intentional multi-line wrapping and drop the concern.

**One-off/fine:**
- No `@media` breakpoints in this file at all. Not a bug: the layout is a single fluid column (`max-width: 720px`, everything block-level or `flex-wrap: wrap` via `.bh-judge-actions`), so it naturally reflows without needing a breakpoint. No action needed.
- All colors/spacing route through `--bh-*` custom properties (inherited from player.css's `:root`) — no hardcoded hex/px magic numbers found. Clean.
- No badge/pill classes in this file.

**Duplicates:** none — this file has no shapes that overlap `.bhy-badge`/`.bhy-alert`/`.bhy-table-wrap`.

---

## bh-contest/assets/css/player.css

The largest of the four files (664 lines), and generally the most disciplined — almost every truncation-prone element already handles it (`.bh-track-title`/`.bh-track-artist` line 269, `.bh-np-info strong` line 324, `.bh-results-song` line 552, `.bh-select-option` line 490-493, `.bh-results-cat` line 549 `white-space: nowrap`). Real breakpoints exist at 640px, 380px, and reduced-motion is handled.

**Bug found — `.bh-archive-card` grid, lines 619-626:**
```css
.bh-archive-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: calc(16px * var(--bh-space-scale)); }
.bh-archive-card { background: var(--bh-surface); border: 1px solid var(--bh-border); border-radius: var(--bh-radius); padding: calc(16px * var(--bh-space-scale)); }
.bh-archive-title { font-family: var(--bh-font-display); font-weight: 600; font-size: calc(15px * var(--bh-font-scale)); }
.bh-archive-artist { color: var(--bh-text-dim); font-size: calc(12px * var(--bh-font-scale)); margin-top: 2px; }
.bh-archive-contest { color: var(--bh-text-dim); font-size: calc(11px * var(--bh-font-scale)); margin-top: 2px; text-transform: uppercase; letter-spacing: .03em; }
```
This is the exact bug shape already found and fixed in `storefront.css`'s `.bhm-product-card-title`: a CSS grid of cards (`auto-fit`, `minmax(260px, 1fr)`) with three separate variable-length text fields — track title, artist name, and contest name — none of which have any `-webkit-line-clamp`, `text-overflow`, or `white-space` handling. A long title/artist/contest name on one card will grow that card's text block taller than its siblings, breaking the even-row alignment the grid is otherwise designed to produce. Recommend `.bh-clamp-2` (or a 1-line variant) on `.bh-archive-title`, and `.bh-truncate` (or `white-space:nowrap;overflow:hidden;text-overflow:ellipsis`) on `.bh-archive-artist`/`.bh-archive-contest`, matching the fix pattern already applied to storefront.css.

**Duplicates:**
- `.bh-track-title, .bh-track-artist` (line 269), `.bh-np-info strong` (line 324), `.bh-results-song` (line 552), and `.bh-select-option` (line 490-493) all hand-roll `white-space: nowrap; overflow: hidden; text-overflow: ellipsis;` inline rather than composing the shared `.bh-truncate` utility class that `class-style.php`'s `text_overflow_utils_css()` already ships and prints site-wide via `wp_head`. Functionally identical, so not a bug — but it's four (really more, `.bh-results-votes` line 554 and `.bh-results-cat` line 549 also inline `white-space: nowrap` for the same "don't wrap this" reason as `.bh-nowrap`) independent copies of a rule the design system already centralizes. Worth a follow-up pass to swap these to the shared classes for the consolidation effort, though not urgent since the CSS is correct as written.

**One-off/fine:**
- `.bh-btn-results` (line 171-176) hardcodes `#FFDD8C`, `#F5A623`, `#2A1600` instead of `--bh-*` tokens. This is explicitly intentional per the file's own comment ("a warm gold treatment... sets it apart from the everyday coral accent") — a deliberate one-off "special event" color, not a magic-number oversight. No action needed.
- `.bh-btn-primary`/`.bh-play-pause` use hardcoded `#150705` for text-on-accent contrast — also intentional (documented elsewhere in the codebase as the standard dark-on-accent pairing). Fine.
- `.bh-modal` fallback `rgba(13,5,4,0.82)` and various `box-shadow`/`backdrop-filter` rgba values are one-off effect values, not palette colors — fine as literals.
- Mobile breakpoints (640px, 380px) are present and cover the now-playing bar, track rows, modals, disc sizing, reveal typography. Good coverage.

---

## bh-monetization-woo/assets/css/frontend.css

**Genuine gap — badges missing `white-space: nowrap`:**
- `.bhm-badge` (line 15): `display: inline-block; margin-top: 8px; font-size: 12px; padding: 3px 10px; border-radius: 100px; background: rgba(29,185,84,0.15); color: #1DB954;` — no `white-space: nowrap`. `class-ui.php`'s `.bhy-badge` (the admin-side equivalent shape) explicitly sets `white-space: nowrap` on the base class specifically because a pill/badge wrapping mid-word reads as broken. This is `inline-block` with no fixed width, so if the badge text is ever longer than expected (this is a "supporter" status badge — check whatever calls it for how bounded the label text actually is) it will wrap ugly inside the pill shape instead of the safe overflow the ecosystem's own badge convention prefers.
- `.bhm-billing-badge` (line 5) has the same gap — `display: block; font-size: 11px; ...` with no `white-space: nowrap`. Lower risk since it's a fixed vocabulary label (billing cadence), but still diverges from the `.bhy-badge`/`.bh-nowrap` convention.
- `.bhm-amount-chip` (line 40-44) — pill-shaped (`border-radius: 999px`), holds a short dollar amount, no `white-space: nowrap`. Very low risk (amounts are short and numeric) but same pattern gap.

**Genuine gap — hardcoded success-green with no matching front-end token:**
- `.bhm-tier-savings` (line 6): `color: #1DB954;` and `.bhm-badge` (line 15): `color: #1DB954; background: rgba(29,185,84,0.15);`. `class-style.php`'s front-end token set (`--bh-bg/surface/border/text/accent/...`) has no semantic "success" color analogous to the admin side's `--bhy-success` / `--bhy-success-bg` (class-ui.php line 686-687). These two rules duplicate the same hardcoded Spotify-green literal independently rather than reading from a shared token, and there's currently no front-end `--bh-success` to point at even if they wanted to. Worth deciding whether a `--bh-success` front-end token belongs in class-style.php's palette, or whether this stays a deliberate one-off (green reads universally as "positive/savings" regardless of site theme, which is a defensible reason to NOT theme it).

**One-off/fine:**
- `.bhm-btn` (line 13) hardcodes `color: #000` for text-on-accent-background contrast — same intentional pattern as player.css's `.bh-btn-primary`, not a bug.
- No `@media` breakpoints in this file — not a gap, since `.bhm-tier-grid` (auto-fit grid) and every flex row (`.bhm-tier-gift form`, `.bhm-tip-jar`, `.bhm-buy-form`, `.bhm-wallet-topups`) already uses `flex-wrap: wrap` / grid auto-fit, so they reflow without a breakpoint. Fine.
- Everything else consistently uses `var(--bh-*, fallback)` — good adherence to the token system.

**Duplicates:** none — no shapes here overlap `.bhy-table-wrap`/`.bhy-alert`/`.bh-clamp-*` (no card-grid text-truncation scenario in this file; tier names are short admin-curated labels, not user-generated content).

---

## bh-monetization-woo/assets/css/storefront.css (full re-check)

Beyond the already-fixed `.bhm-product-card-title` line-clamp (line 58-61, confirmed present), the rest of the file was re-read in full. No further bugs found.

**One-off/fine (verified clean):**
- `.bhm-product-grid` (line 31-39) has real breakpoints at 900px (2 columns) and 520px (1 column) — good responsive coverage, unlike some other files here that rely purely on auto-fit.
- `.bhm-product-card` (line 41-55) has a deliberate, well-commented `max-width: 320px; justify-self: center;` fix already in place for the "fewer products than grid columns stretches cards" problem — this reads as the SAME session's already-completed pass, not a new gap.
- `.bhm-collection-title` (line 19) and `.bhm-related-products-heading` (line 72) are page-level headings (not repeated card content), so no truncation risk.
- `.single-product .price` / `.single_add_to_cart_button` overrides (line 91-101) are the already-documented fix for classic-vs-block WooCommerce template coverage — correctly scoped, not a new issue.
- All colors consistently use `var(--bh-*, fallback)` — the exact pattern this file's own header comment describes fixing (previously referenced a nonexistent `--bhy-color-*` scheme). Confirmed no remaining `--bhy-color-*` references anywhere in the file.
- No badge/pill shapes in this file to check against `.bhy-badge`.

**Duplicates:** none.

---

## Summary

| File | Bugs found | Genuine gaps | Duplicates (minor) | Otherwise |
|---|---|---|---|---|
| judging.css | none | untruncated title/artist in `.bh-judge-entry-title`/`.bh-judge-artist` (low risk, no grid) | none | clean |
| player.css | **`.bh-archive-title`/`.bh-archive-artist`/`.bh-archive-contest` — same uneven-card-height bug shape as the confirmed storefront.css bug, in a grid at line 619-626** | — | 4-5 hand-rolled `.bh-truncate`/`.bh-nowrap`-equivalent rules that could reuse the shared utility classes | otherwise disciplined, good breakpoint coverage |
| frontend.css | none | `.bhm-badge`/`.bhm-billing-badge`/`.bhm-amount-chip` missing `white-space: nowrap`; hardcoded `#1DB954` success-green with no front-end `--bh-success` token to route through | none | fine otherwise |
| storefront.css | none (already-fixed bug re-verified in place) | none new | none | fully clean re-check |

**Highest priority: `bh-contest/assets/css/player.css` lines 619-626 (`.bh-archive-title`/`.bh-archive-artist`/`.bh-archive-contest`)** — the archive/library grid has the identical missing-line-clamp bug already found and fixed in storefront.css, just not yet applied here.
