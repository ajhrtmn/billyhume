# Own Ur Shit ecosystem — CSS-file audit (Phase 1b, follow-up to the inline-style audit)

The inline-style audit ([AUDIT-inline-styles-MASTER.md](AUDIT-inline-styles-MASTER.md)) explicitly flagged that it only covered inline `style="..."` attributes in PHP, not rules already living in real `.css` files — and one of its own fixes (`bh-monetization-woo/assets/css/storefront.css`'s product-card title) was only caught because an agent went and read the CSS directly. This pass closes that gap: every `.css` file in the 10-plugin ecosystem, read in full.

Per-file detail lives in three companion docs:
- [`AUDIT-css-files-own-ur-shit-small-plugins.md`](AUDIT-css-files-own-ur-shit-small-plugins.md) — own-ur-shit (5 files), bh-registry, bh-live, bh-video, bh-feedback
- [`AUDIT-css-files-bh-contest-monetization.md`](AUDIT-css-files-bh-contest-monetization.md) — bh-contest (judging.css, player.css), bh-monetization-woo (frontend.css, storefront.css)
- [`AUDIT-css-files-courses-crm-streaming.md`](AUDIT-css-files-courses-crm-streaming.md) — bh-courses (admin.css, courses.css), bh-crm (kanban-board.css), bh-streaming (player.css)

**Status: every confirmed bug and every cheap, unambiguous fix from all three docs has already been applied** (not just inventoried — this phase went straight to fixing, since the shapes were identical to already-verified bugs and the fixes were one-line, low-risk additions of `-webkit-line-clamp`/`white-space:nowrap`/`text-overflow:ellipsis`). Genuine design-system gaps (missing front-end semantic color tokens, no shared front-end `.bh-badge` primitive) are left as Phase 2 decisions below, not applied, since they involve new visual language / naming decisions.

---

## Bugs found and fixed (uneven-card-height / badge-wrap family)

Same two failure shapes as the original inline-style audit: (1) variable-length text with no clamp/truncate sitting in a CSS grid of cards → uneven row heights, and (2) a pill/badge shape with no `white-space:nowrap` → wraps mid-label in a narrow column.

| File | Rule | Fix applied |
|---|---|---|
| `bh-monetization-woo/assets/css/storefront.css:58` | `.bhm-product-card-title` | line-clamp (fixed in Phase 1a, re-verified clean here) |
| `bh-contest/assets/css/player.css:619-625` | `.bh-archive-title`/`-artist`/`-contest`/`-badge` | title clamp-2, artist/contest truncate, badge nowrap |
| `bh-courses/assets/css/courses.css:53` | `.bhc-excerpt` | line-clamp-3 (title was already fixed; excerpt below it wasn't) |
| `bh-courses/assets/css/courses.css:114,162,193` | `.bhc-badge`, `.bhc-term`, `.bhc-review-badge` | nowrap |
| `bh-courses/assets/css/courses.css:121-122` | `.bhc-card-instructor span` (instructor name) | truncate + `min-width:0` |
| `bh-courses/assets/css/courses.css:188` | `.bhc-leaderboard-name` | truncate + `min-width:0` |
| `bh-crm/assets/css/kanban-board.css:321` | `.bhcrm-sticky-card-title` | line-clamp-3 |
| `bh-crm/assets/css/kanban-board.css:135` | `.bhcrm-kanban-column-header` | truncate |
| `bh-registry/assets/css/registry.css:10-11` | `.bhr-card-name`, `.bhr-badge` | title clamp-2, badge nowrap |
| `bh-live/assets/css/live-player.css:46` | `.bhl-replay-title` | line-clamp-2 |
| `bh-live/assets/css/live-player.css:21-23` | `.bhl-chat-source-tag` | nowrap |
| `bh-streaming/assets/css/player.css:103,106` | `.bhs-queue-item`, `.bhs-queue-artist` | truncate |
| `bh-streaming/assets/css/player.css:192` | `.bhs-chapter-marker` | nowrap |
| `own-ur-shit/assets/css/search.css:34` | `.ous-search-item-title` | truncate |
| `own-ur-shit/assets/css/admin.css:6` | `.ous-badge` | nowrap |
| `own-ur-shit/assets/css/public-profile.css:7,30` | `.bhi-badge`, `.bhi-bio-link` | nowrap (bio-link also got `max-width:220px` + truncate — holds unbounded user text) |
| `bh-feedback/assets/css/feedback.css:19` | `.bhf-badge` family | nowrap |
| `bh-monetization-woo/assets/css/frontend.css:5,15` | `.bhm-billing-badge`, `.bhm-badge` | nowrap (billing-badge also truncate) |

18 files touched, all confirmed brace-balanced after editing (no syntax breakage). **Not** fixed live in-browser — the local install (`localhost:10008`) is a bare default WordPress site with no course/product/kanban content seeded, so these grids have nothing to render yet. Worth a real visual pass once content exists, but the fixes themselves are the same one-line pattern (`display:-webkit-box;-webkit-line-clamp:N;-webkit-box-orient:vertical;overflow:hidden` for multi-line, `white-space:nowrap;overflow:hidden;text-overflow:ellipsis` for single-line) already proven correct in the sibling files that had it right from the start (`bh-video/video-player.css`'s `.bhv-card-title`, `bh-streaming/player.css`'s `.bhs-card-title`).

---

## Not fixed — genuine design-system gaps needing a decision, not a mechanical patch

These came up repeatedly across independent files/plugins, which is the strongest signal this belongs in `class-style.php` (front-end) as a real addition, not a per-file patch:

1. **No front-end semantic color tokens.** `class-style.php` only exposes `--bh-accent`/`--bh-surface`/`--bh-border`/`--bh-text` etc. — there is no `--bh-success`/`--bh-warning`/`--bh-danger` equivalent to the admin side's `--bhy-success`/`--bhy-warning`/`--bhy-danger`. At least 4 files have independently hit this gap and either hardcoded a color (`bh-monetization-woo/frontend.css`'s `#1DB954`, `bh-contest/player.css`'s gold/danger literals) or invented their own undocumented token names that aren't defined anywhere (`bh-feedback/feedback.css`'s `--bh-warning`, `--bh-warning-muted-bg`, `--bh-accent-muted-bg` — worth checking whether these are silently falling back to their literal defaults 100% of the time, which would mean the "theming" is dead code). **Recommend**: add `--bh-success`/`--bh-success-bg`, `--bh-warning`/`--bh-warning-bg`, `--bh-danger`/`--bh-danger-bg` to `class-style.php`'s token set, mirroring the admin-side names exactly, then point bh-feedback's invented tokens at them.

2. **No shared front-end `.bh-badge` primitive.** `class-ui.php` has `.bhy-badge` for admin; `class-style.php` has nothing equivalent for front-end. Four plugins have independently hand-rolled the same "small pill, ~11px text, ~999px radius" shape with inconsistent `white-space` handling: `bh-courses` (`.bhc-badge`, `.bhc-term`, `.bhc-review-badge` — three separate reinventions in one plugin alone), `bh-streaming` (`.bhs-badge`, `.bhs-chapter-marker`), `bh-crm` (`.bhcrm-kanban-stalled-badge`), `bh-registry` (`.bhr-badge`), `bh-live` (`.bhl-live-badge`, partial), `bh-feedback` (`.bhf-badge`), `bh-monetization-woo` (`.bhm-badge`, `.bhm-billing-badge`, `.bhm-amount-chip`), `own-ur-shit` (`.ous-badge`, admin-context but front-facing dashboard). This is the single clearest "extend the shared system" candidate to come out of either audit pass — a `.bh-badge` + `.bh-badge-success/-warning/-danger/-neutral` in `class-style.php`, built once the color tokens above exist, would let most of these become one-line class swaps.

3. **No front-end equivalent of `.bhy-badge-truncate`.** Admin has a badge-with-max-width-and-ellipsis variant (`class-ui.php`); front-end doesn't, despite `.bhi-badge`/`.bhi-bio-link`/`.bhr-badge`/`.bhf-badge` all needing exactly that shape. Bundle this into the `.bh-badge` work above as a `.bh-badge-truncate` modifier.

4. **`bh-crm/assets/css/kanban-board.css` hardcodes every single color** (`#dcdcde`, `#2271b1`, `#00a32a`, `#d63638`, `#8a5a00`, etc. — no `var(--bhy-*)` anywhere) despite living entirely in wp-admin where those tokens are already printed. This is the worst offender for token drift found in either audit — a future re-theme of `class-ui.php`'s palette silently won't reach this file. Not fixed here since it's a large mechanical sweep (every color in a 407-line file), better done as its own dedicated batch with a live wp-admin visual check after, rather than folded into this pass.

5. **`bh-live/assets/css/live-player.css`'s narrow-viewport chat layout** — `.bhl-chat-slot`'s `min-width:260px` alongside a `flex:2 1 480px` video slot has no `@media` fallback anywhere in the file, and could force horizontal overflow under ~540px total width. Needs an actual phone-width check to confirm before deciding on a fix, not a blind patch.

6. **`own-ur-shit/assets/css/studio.css`'s fixed 3-column builder layout** (220px/1fr/280px, no breakpoint) — flagged but not touched, since it's unclear whether the canvas builder is deliberately desktop-only tooling. Needs a product decision from you, not a code fix.

---

## Good patterns confirmed (hold these up as the reference, not the exception)

- `bh-video/assets/css/video-player.css`'s `.bhv-card-title` and `bh-streaming/assets/css/player.css`'s `.bhs-card-title`/`.bhs-np-title` already had the correct truncate pattern from the start — proof the pattern is well understood in this codebase, just inconsistently applied.
- `bh-monetization-woo/assets/css/storefront.css`'s `.bhm-product-grid` has real `@media` breakpoints at 900px/520px, and its `.bhm-product-card`'s `max-width:320px;justify-self:center` fix (for "fewer products than columns" stretching) is a good example of the "worth a comment explaining the deliberate choice" convention this codebase already follows elsewhere.
- `own-ur-shit/assets/css/toast.css` and `search.css` both use `var(--bh-*, <fallback>)` on every token reference — the correct defensive pattern for a component that might render before the token-printing PHP runs, worth using as the template when the kanban-board.css token sweep (gap #4 above) eventually happens.
