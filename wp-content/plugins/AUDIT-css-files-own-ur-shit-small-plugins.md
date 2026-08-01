# CSS-file audit — Own Ur Shit ecosystem (small plugins)

Follow-up to the inline-style-only audit. This pass covers actual `.css` files only. Reference primitives:

- Admin tokens/classes (`own-ur-shit/includes/class-ui.php`): `--bhy-space-*`, `--bhy-text-*`, `--bhy-success/-warning/-danger(-bg)`, `.bhy-badge` (has `white-space: nowrap` built in), `.bhy-badge-truncate`, `.bhy-alert`, `.bhy-table-wrap`, `.bhy-card`.
- Front-end tokens/classes (`own-ur-shit/includes/class-style.php`): `--bh-*` (space/color/radius/font), `.bh-truncate` (`white-space:nowrap;overflow:hidden;text-overflow:ellipsis`), `.bh-clamp-2`, `.bh-clamp-3` (`-webkit-line-clamp`).

Files audited: `own-ur-shit/assets/css/{admin,public-profile,search,studio,toast}.css`, `bh-registry/assets/css/registry.css`, `bh-live/assets/css/live-player.css`, `bh-video/assets/css/video-player.css`, `bh-feedback/assets/css/feedback.css`.

---

## own-ur-shit/assets/css/admin.css

17 lines total, defines the plugin-status badge trio and the feature-maturity badge trio for the OUS dashboard cards grid.

- **Bugs found:** `.ous-badge` (line 6) has no `white-space: nowrap`:
  ```
  .ous-badge { font-size: 11px; padding: 3px 9px; border-radius: 999px; font-weight: 600; }
  ```
  `.bhy-badge` in `class-ui.php` bakes `white-space: nowrap` into the base badge rule specifically because it's a fixed-vocabulary pill — the exact same shape here (`active`/`inactive`/`missing`, `alpha`/`beta`/`experimental`) has no such guard, so a badge in a narrow card (`.ous-cards` is `auto-fit, minmax(280px, 1fr)`, i.e. can get quite narrow) can wrap its label onto two lines and blow out the pill shape.

- **(a) Duplicates an existing shared primitive.** `.ous-badge` + `.ous-badge-active/-inactive/-missing/-alpha/-beta/-experimental` (lines 6–17) is a complete hand-rolled reimplementation of `.bhy-badge` + `.bhy-badge-success/-warning/-neutral` from `class-ui.php`, right down to the `border-radius: 999px` pill shape and similar padding — but it uses its own hardcoded hex colors (`#1DB954`, `#f0e6c8`/`#8a5a00`, `#f0f0f1`/`#787c82`, `#dbe9ff`/`#1a4d99`, `#f3e3ff`/`#6b2fa3`) instead of `var(--bhy-success)` / `var(--bhy-warning)` / `var(--bhy-ink-dim)` etc. Since this file is loaded on the OUS admin dashboard (where `class-ui.php`'s tokens are already printed), the color and shape work should come from `.bhy-badge` variants, not a second parallel badge system. Recommend replacing the six rules here with `.bhy-badge` + a couple of new `.bhy-badge-*` variants (an "alpha/beta/experimental" set doesn't exist yet in `class-ui.php` — see Genuine gap below) plus `white-space: nowrap`.

- **(b) Genuine gap.** `class-ui.php` has no "maturity" badge variant (alpha/beta/experimental) — only success/warning/danger/neutral. If this alpha/beta/experimental vocabulary is going to recur elsewhere (feature flags, other plugin dashboards), it's worth adding `.bhy-badge-alpha/-beta/-experimental` to the shared design system rather than leaving it local to this one file.

- **(c) One-off/fine as-is.** `.ous-card`, `.ous-card-header`, `.ous-card-desc`, `.ous-card-meta` (lines 2–5) — simple card chrome, no variable-length title truncation risk since card titles here are short fixed plugin names, not user content. No `@media` breakpoint in the file, but the grid is already `auto-fit, minmax(280px, 1fr)`, which self-collapses on narrow screens without needing one.

---

## own-ur-shit/assets/css/public-profile.css

45 lines, the front-end public profile page + edit form. Consistently uses `--bh-*` tokens throughout — this is the cleanest file in the batch.

- **Bugs found:** `.bhi-badge` (line 7) — the profile's role/verification badge pills — has no `white-space: nowrap`:
  ```
  .bhi-badge { background: var(--bh-surface-2); border: 1px solid var(--bh-border); color: var(--bh-text-dim); border-radius: 999px; padding: 3px 10px; font-size: 0.8em; text-decoration: none; }
  ```
  These sit in `.bhi-profile__badges` (line 6, `flex-wrap: wrap` — wrapping the *row* is fine and intended), but nothing stops an individual badge's own label text from wrapping mid-pill if the badge text is long (these are likely dynamic labels, not a fixed short vocabulary — worth confirming against the PHP that emits them). Same shape as the already-fixed `.bhm-product-card-title` bug and the `.bhy-badge` nowrap guard.

  `.bhi-bio-link` (line 30) — the profile's bio-link pills — also has no `white-space: nowrap` or truncation, and these render **user-entered link text**, which is unbounded length:
  ```
  .bhi-bio-link { background: var(--bh-surface); border: 1px solid var(--bh-border); color: var(--bh-text); border-radius: 999px; padding: 8px 16px; text-decoration: none; font-weight: 600; font-size: 0.9em; }
  ```
  This is a stronger case than the badge above — a long link label will wrap inside the pill shape and look broken. Recommend `white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: <something>` (mirroring `.bhy-badge-truncate`'s pattern, which doesn't have a front-end `--bh-*` equivalent — see gap below).

- **(b) Genuine gap.** There's no front-end equivalent of `.bhy-badge-truncate` (admin-only, `class-ui.php` line 750) for bounding pill/badge text at a fixed `max-width` while ellipsizing. `.bh-truncate` in `class-style.php` truncates a block-level or flex-item element, but nothing in the front-end token set currently combines that with the pill/badge shape the way `.bhy-badge-truncate` does for admin. Given `.bhi-badge` and `.bhi-bio-link` both need it here, and `bh-registry`'s `.bhr-badge` and `bh-feedback`'s `.bhf-badge*` are the same shape, this looks like a real recurring need — worth adding a `.bh-badge-truncate` (or similar) to `class-style.php`.

- **(c) One-off/fine as-is.** `.bhi-profile__name` (line 5) has no truncation, but it's a centered, non-grid single element under a 640px max-width container — long display names will just wrap normally, which is acceptable here (not a grid/card context). The one `@media (max-width: 480px)` block (lines 39–42) is scoped correctly to the one place with a real narrow-width failure mode (`.bhi-bio-link-row`'s side-by-side text/url inputs); the rest of the layout is already flex-column/wrap and doesn't need one.

---

## own-ur-shit/assets/css/search.css

35 lines, the `[ous_search]` autocomplete dropdown.

- **Bugs found:** `.ous-search-item-title` (line 34) has no truncation/clamp:
  ```
  .ous-search-item-title { font-size: 14px; font-weight: 600; color: var(--bh-text, inherit); }
  ```
  Each result row (`.ous-search-item a`, lines 25–28) is a `flex-direction: column` stack of type label / title / excerpt inside a fixed-width dropdown (`.ous-search-results`, `left:0; right:0` relative to a `max-width: 480px` container). A long search-result title (post title, artist name, etc. — inherently variable length) will wrap to multiple lines and make result rows uneven heights in the list, which is exactly the uneven-row-height failure mode already fixed once tonight in the storefront card grid. Recommend `.bh-truncate` (or a one-line `white-space:nowrap;overflow:hidden;text-overflow:ellipsis`) on this class.

  `.ous-search-item-type` (line 30) — the small "POST" / "ARTIST" type-label pill above the title — also has no `white-space: nowrap`, though this one is populated from a small fixed internal vocabulary (post-type slugs), so the practical risk is much lower than the title. Flagging for consistency/cheap-insurance since it sits right next to a proven-risky sibling.

- **(c) One-off/fine as-is.** No `@media` breakpoint in the file — `.ous-search` is `max-width: 480px` and everything scales fluidly (`width: 100%` fields, `overflow-y: auto` on results), so it naturally degrades on narrow viewports without a dedicated breakpoint.

- Good practice note (not a finding): every custom-property reference in this file has an explicit fallback (`var(--bh-border, #ccc)` etc.), which is the right pattern for a shortcode that might render before `class-style.php`'s tokens print — worth using as the template when fixing the other files' hardcoded colors.

---

## own-ur-shit/assets/css/studio.css

36 lines, the BH_Studio canvas builder admin screen (three-column layout: layers / canvas / inspector).

- **(c) One-off/fine as-is — but flagging the hardcoded colors.** `border-bottom: 1px solid #dcdcde` (line 10), `background: #fff` (line 11), `color: #646970` (line 12), `border-right/-left: 1px solid #dcdcde` (lines 23–24), `background: #f6f7f7` (line 21), `color: #646970` (line 27) are all values that have direct `--bhy-*` token equivalents in `class-ui.php` (`--bhy-border`, `--bhy-surface`, `--bhy-ink-dim`, `--bhy-subtle`) — this file happens to be the one place in the batch that uses zero `--bhy-*`/`--bh-*` custom properties despite being an admin-only screen where `--bhy-*` tokens are printed. Not a visual bug today (values match the token defaults), but it will silently drift out of sync the next time someone re-themes the admin color system via `class-ui.php`, since this file won't pick up the change. Worth a follow-up pass to swap these for `var(--bhy-border, #dcdcde)` etc.

- **(b) Genuine gap — missing responsive breakpoint.** `.bh-studio-body` (lines 14–18) is a fixed three-column grid (`220px 1fr 280px`) with no `@media` collapse for narrow viewports, and `#bh-studio-root` (line 6) is a fixed `height: 720px`. This is plausibly fine if the builder is explicitly desktop-only tooling (the file's own header comment about `studio.js`'s `supports.position: false` suggests deliberate constraints elsewhere), but if any admin user opens this on a tablet or a narrower laptop window it will not collapse gracefully — the 220px+280px side rails alone are 500px before any canvas content. Worth confirming with the plugin author whether mobile/tablet support for the builder is in scope; if so, this needs a breakpoint that stacks or collapses the side panels.

- `.bh-button` (line 33–36) correctly uses `var(--bhy-accent, #2271b1)` — good pattern, inconsistent with the rest of the file only using literals.

---

## own-ur-shit/assets/css/toast.css

97 lines, `BHCoreToast` notification component, loaded on both admin and front end.

- **(c) One-off/fine as-is.** This file is deliberately the best-documented one in the batch — its own header comment explains that hardcoded colors (`#fff`, `#1d2327`, `#dcdcde`, `#787c82`) are intentional fallbacks paired with `var(--bhy-*, <fallback>)` on the state-color rules (lines 61–64), specifically so the component matches the admin design system when those tokens are printed and still looks acceptable when they aren't (front end, or an admin screen `class-ui.php` doesn't touch). This is the same fallback pattern search.css uses and is worth holding up as the ecosystem's model for cross-context components — not a finding.
- `.bhcore-toast-msg` (line 66–70) already has `word-break: break-word` for the message body, and no card/grid layout is present, so no clamp/nowrap gap here.
- `@media (prefers-reduced-motion: reduce)` (lines 93–97) is a nice touch already present — no responsive-breakpoint gap since the toast region uses `max-width: min(360px, calc(100vw - 32px))` (line 29), which self-adapts to narrow viewports.

---

## bh-registry/assets/css/registry.css

52 lines, the artist registry directory (search/filter + card grid + submission modal).

- **Bugs found:** `.bhr-card-name` (line 10) — the artist name in each registry card — has no `-webkit-line-clamp` / truncation:
  ```
  .bhr-card-name { font-weight: 700; margin-bottom: 6px; }
  ```
  `.bhr-card` sits in `.bhr-grid` (line 7, `auto-fit, minmax(180px, 1fr)`), the exact same CSS-grid-of-cards shape as the already-fixed `bh-monetization-woo` storefront bug, and artist names are unbounded-length user content. A long name will wrap to 2–3 lines and make that card taller than its grid siblings, producing the same uneven-row-height problem. Recommend `.bh-clamp-2` (or a single-line `.bh-truncate`, depending on desired look) from `class-style.php`.

  `.bhr-badge` (line 11) — the verified/status pill on each card — has no `white-space: nowrap`:
  ```
  .bhr-badge { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 100px; margin: 2px 4px 0 0; }
  ```
  Same nowrap gap as `.bhy-badge` guards against, sitting in the same narrow card grid as the clamp bug above — compounds the risk of uneven card heights.

- **(a) Duplicates an existing shared primitive.** `.bhr-card-name` + `.bhr-badge` together are recreating a card-title-plus-status-badge layout that `.bh-clamp-2`/`.bh-truncate` and `.bhy-badge`'s nowrap guard already solve — the PHP emitting these cards should reference the shared front-end classes (`.bh-clamp-2`, and a nowrap-guarded badge class) rather than the fix living only in this file's bespoke rules.

- **(c) One-off/fine as-is / good practice.** The file's own trailing comment (lines 42–45) documents that it previously had zero mobile breakpoints and that the added `@media (max-width: 480px)` block (lines 46–52) fixes padding/control sizing on narrow phones — this is already handled, no further action needed. Every color in the file correctly uses `var(--bh-*, <fallback>)` except `.bhr-badge-verified` (line 12, `rgba(29,185,84,0.15)` / `#1DB954`) and `.bhr-form-error` (line 38, `#b3261e`) and `.bhr-form-success`'s literal fallback color that doesn't match the accent default — these are minor and likely intentional (verified-green and error-red aren't part of the `--bh-*` front-end token set the way `--bhy-success/-danger` are on the admin side), so treating as one-off rather than a bug, but flagging since `--bhy-success`/`--bhy-danger` equivalents don't currently exist as front-end `--bh-*` tokens (see gap in bh-feedback below, which already half-solves this with `--bh-warning`/`--bh-warning-muted-bg`/`--bh-accent-muted-bg`).

---

## bh-live/assets/css/live-player.css

47 lines, the live-stream page (embedded player + native chat + replay grid).

- **Bugs found:** `.bhl-replay-title` (line 46) — the title on each replay-grid card — has no truncation/clamp:
  ```
  .bhl-replay-title { font-size: 13px; font-weight: 600; }
  ```
  `.bhl-replay-card` lives in `.bhl-replay-grid` (line 42, `auto-fill, minmax(200px, 1fr)`) — another CSS-grid-of-cards with variable-length titles (replay/VOD titles are user- or artist-set), identical failure mode to the two bugs already found tonight. Recommend `.bh-clamp-2` from `class-style.php`. Notably, `bh-video/video-player.css`'s equivalent `.bhv-card-title` (same plugin family, near-identical grid) already handles this correctly (see that file's section) — this looks like the fix was applied in one sibling plugin but not this one, reinforcing that a shared class rather than a per-file inline rule is the right long-term fix.

  `.bhl-chat-source-tag` (lines 21–24) — the small "TWITCH"/"YOUTUBE" source badge on each chat message — has no `white-space: nowrap`:
  ```
  .bhl-chat-source-tag {
      display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase;
      padding: 1px 5px; border-radius: 3px; margin-right: 2px; background: var(--bh-surface-2); color: var(--bh-text-dim);
  }
  ```
  Lower risk than the other nowrap findings since the label text is a fixed short vocabulary today (`.bhl-chat-message--twitch`/`--youtube` are the only two modifiers wired up), but flagging for the same cheap-insurance reason as `.ous-search-item-type`.

- **(b) Genuine gap — missing responsive breakpoint.** The entire file has no `@media` query. Most of it (`.bhl-live-row`, `.bhl-replay-grid`) already uses `flex-wrap`/grid `auto-fill` so it degrades reasonably, but `.bhl-chat-slot` (line 14) has a hardcoded `min-width: 260px` inside a `flex: 1 1 280px` row alongside a `flex: 2 1 480px` video slot (line 12) — on a narrow phone viewport (< ~540px total) these two flex-basis values plus gap (16px) will force horizontal overflow or an awkward squeeze before the row wraps, since `min-width: 260px` prevents the chat panel from shrinking below that. Worth confirming actual mobile behavior; a `@media (max-width: 480px)` reducing/removing that `min-width` (mirroring `bh-registry`'s pattern) would be the fix.

- **(a) Duplicates an existing shared primitive (partial).** `.bhl-live-badge` (lines 34–38) is a bespoke "LIVE" status pill, structurally similar to `.bhy-badge`/`.bhr-badge` but with its own red color and pulsing-dot pseudo-element — this one's probably fine as a one-off given the dot decoration is genuinely custom, not flagging as a hard duplicate, just noting the shape overlap for awareness.

---

## bh-video/assets/css/video-player.css

36 lines, VOD library (topbar filter + player + chapter markers + video grid).

- **(a) Duplicates an existing shared primitive.** `.bhv-card-title` (line 35) already correctly handles the clamp/wrap risk:
  ```
  .bhv-card-title { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  ```
  This is functionally identical to `.bh-truncate` in `class-style.php` (`white-space:nowrap;overflow:hidden;text-overflow:ellipsis`), just hand-written locally instead of the PHP emitting `class="bh-truncate"` on the title element and dropping this rule. Not a bug — it works correctly — but it's the clearest evidence in this batch that the shared primitive should actually be reused rather than re-derived per plugin, since this is the "right" answer to the same problem `bh-registry` and `bh-live` got wrong two sections above.

- **(c) One-off/fine as-is.** `.bhv-chapter-marker` (lines 25–28) has no explicit `white-space: nowrap`, but chapter labels are typically short timestamps/titles set by the uploader in a constrained context (`flex-wrap: wrap` container, line 24) — low risk, not flagging as a bug. No `@media` breakpoint in the file, but `.bhv-grid` (`auto-fill, minmax(180px,1fr)`), `.bhv-topbar` (flex with `min-width:0` input), and `.bhv-player-wrap video` (`max-height: 70vh`) are all already fluid — no gap identified.

---

## bh-feedback/assets/css/feedback.css

30 lines, the fan feedback/request-queue widget (submission form + tier picker + request cards with status badges).

- **Bugs found:** `.bhf-badge` and its state modifiers (lines 19, 23–25) have no `white-space: nowrap`:
  ```
  .bhf-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.8em; font-weight: 600; background: var(--bh-surface-2, #f6f6f7); color: var(--bh-text-dim, #6b7280); }
  .bhf-badge-open { background: var(--bh-surface-2, #f6f6f7); color: var(--bh-text-dim, #6b7280); }
  .bhf-badge-claimed { background: var(--bh-warning-muted-bg, #fff6e5); color: var(--bh-warning, #9a6700); }
  .bhf-badge-completed { background: var(--bh-accent-muted-bg, #eef4ff); color: var(--bh-accent, #b3502e); }
  ```
  These are status pills (`open`/`claimed`/`completed`) on `.bhf-request-card` — fixed short vocabulary today so the wrap risk is lower than the registry/profile badges, but still missing the guard `.bhy-badge` treats as a baseline requirement for this exact shape. Cheap to add.

- **(b) Genuine gap.** This file already independently invented `--bh-warning`, `--bh-warning-muted-bg`, `--bh-accent-muted-bg` (lines 24–25) as front-end tokens for badge coloring — tokens that don't appear to be defined anywhere in `class-style.php` (which only exposes `--bh-accent` directly, not a "muted-bg" variant, and no `--bh-warning` at all based on the grep of that file). Two possibilities: either these are defined elsewhere and just not in the excerpt reviewed, or this file is relying on undefined custom properties and silently falling back to its literal fallback values every single time. Worth checking with the author — if undefined, this is effectively dead theming (the fallback is always what renders) and duplicates the exact "front end needs success/warning/danger tokens" gap flagged in `bh-registry`'s section above. If real design-system tokens for warning/success states on the front end are wanted (to match `--bhy-warning`/`--bhy-success` on the admin side), this is the second file in the batch asking for it — worth promoting to `class-style.php` as a shared token rather than each plugin inventing its own name.

- **(c) One-off/fine as-is.** No `@media` breakpoint in the file, but every layout here is already `flex-direction: column` or `flex-wrap: wrap` (`.bhf-submit-form`, `.bhf-tier-option`) with `box-sizing: border-box` on full-width inputs — genuinely fluid, no gap identified. `.bhf-request-card` (line 17) has no title-clamp concern since its variable content is an `<audio>` element, not text.

---

## Summary — bugs to fix first (missing clamp/nowrap/breakpoint)

1. `bh-registry/assets/css/registry.css:10` — `.bhr-card-name` no clamp, in a card grid → uneven card heights.
2. `bh-live/assets/css/live-player.css:46` — `.bhl-replay-title` no clamp, in a card grid → uneven card heights.
3. `own-ur-shit/assets/css/search.css:34` — `.ous-search-item-title` no clamp/truncate → uneven autocomplete row heights.
4. `own-ur-shit/assets/css/admin.css:6` — `.ous-badge` no `white-space: nowrap`.
5. `own-ur-shit/assets/css/public-profile.css:7,30` — `.bhi-badge` and `.bhi-bio-link` no `white-space: nowrap` (the latter holds unbounded user text — higher priority of the two).
6. `bh-registry/assets/css/registry.css:11` — `.bhr-badge` no `white-space: nowrap`.
7. `bh-feedback/assets/css/feedback.css:19,23-25` — `.bhf-badge` family no `white-space: nowrap`.
8. `bh-live/assets/css/live-player.css` — no `@media` breakpoint at all; `.bhl-chat-slot`'s `min-width: 260px` (line 14) risks horizontal overflow on narrow phones.
9. `own-ur-shit/assets/css/studio.css` — no `@media` breakpoint for the fixed 220px/1fr/280px three-column layout (confirm desktop-only is intentional before treating as a bug).

Lower-priority, for awareness: `own-ur-shit/assets/css/search.css:30` and `bh-live/assets/css/live-player.css:21-24` (fixed-vocabulary type/source tags, no nowrap but low real-world risk).
