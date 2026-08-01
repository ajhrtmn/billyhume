# Inline `style="..."` Audit — bh-live, bh-registry, bh-video, bh-social

Scope: every inline `style=` attribute found in these four plugins (grep dumps read in full, all source files containing hits read in full for context). Checked against shared primitives in `own-ur-shit/includes/class-ui.php` (admin: `.bhy-badge`, `.bhy-alert`, `.bhy-table-wrap`, `--bhy-space-*`) and `own-ur-shit/includes/class-style.php` (front-end: `.bh-truncate`, `.bh-clamp-2/3`, `--bh-space-*`).

Note on bh-registry: tonight's two live-verified bugs (missing `nowrap` on a badge, missing gap between a button+badge pair) were fixed in `own-ur-shit/includes/class-registry.php`, which is a **different file** from this `bh-registry` plugin. `bh-registry` itself (7 sites) had not been separately audited before this pass — it is covered fully below.

---

## bh-live

### (a) Duplicates
None of bh-live's inline styles duplicate an existing `.bhy-badge`/`.bhy-alert`/`.bhy-table-wrap` primitive outright, but one case is a near-miss worth flagging as mechanical:

- `bh-live/includes/class-admin.php:221,223,225,229` — inline `<span style="color:#2a2;">` / `#a90` / `#c33` health-status text colors are hand-picked hex that map 1:1 onto the semantic success/warning/danger colors already defined for `.bhy-badge-success/warning/danger` (`--bhy-success`, `--bhy-warning`, `--bhy-danger` in class-ui.php). These should render as `.bhy-badge-success` etc. rather than raw hex — same "reinvented instead of reused" shape as tonight's badge bug, just with color tokens instead of layout.

### (b) Genuine gaps
- `bh-live/includes/class-cloudflare-engine.php:131` and `bh-live/includes/class-stream-engine.php:97` — identical `style="width:100%;aspect-ratio:16/9;border:0;"` on a video-embed `<iframe>`, repeated verbatim across two engine implementations. Propose `.bh-embed-16x9 { width:100%; aspect-ratio:16/9; border:0; }` (front-end, class-style.php) and use it in both `get_embed_html()` methods.
- `bh-live/includes/class-workers-chat.php:177` and `bh-live/includes/class-chat.php:50` — identical `style="width:100%;height:100%;min-height:400px;border:0;"` on a chat-embed `<iframe>`, repeated verbatim across two chat implementations. Propose `.bh-chat-embed { width:100%; height:100%; min-height:400px; border:0; }`.
- `bh-live/includes/class-admin.php:269` and `bh-video/includes/class-admin.php:52` — identical `style="max-width:480px;margin-bottom:8px;"` wrapper div holding a media preview (`<video>` or empty state), duplicated **across two separate plugins** verbatim. Propose `.bhy-media-preview { max-width:480px; margin-bottom:8px; }` (admin, class-ui.php) — single highest-value gap in this audit since it already appears identically in 2 plugins and would need it a 3rd time in bh-video's JS-inserted markup (see below).
- `bh-live/includes/class-admin.php:269,284` and `bh-video/includes/class-admin.php:1,3` — `style="width:100%;"` on `<video>` elements, repeated 4x across both plugins (2x PHP-rendered, 2x JS-inserted on media-picker select). Propose `.bh-video-fill { width:100%; }` (front-end) reused by both the initial render and the JS `.innerHTML` replacement, so there's one definition instead of four copies that can drift.
- `bh-live/includes/class-overlay.php:68,94,137` — identical `style="color:#f88;font-family:sans-serif;"` error-message `<p>`, repeated 3x in the same file (Stream not found / bh-contest inactive / invalid token). Propose `.bhl-overlay-error { color:#f88; font-family:sans-serif; }` scoped to this plugin's overlay CSS (these are bare-HTML OBS Browser Source pages outside the normal theme/shared-CSS pipeline, so a plugin-local class rather than a `.bhy-*`/`.bh-*` shared one is the right home).
- `bh-live/includes/class-admin.php:43,193,199` — `style="width:100%;"` (one with `max-width:640px`, one with `margin-top:4px`) on readonly `<input type="text">` "copy this URL" fields, 3 occurrences in one file with the same intent. Minor — folding into a `.bhy-input-full` utility is optional polish, not urgent.

### (c) One-off
- `class-admin.php:198` — `style="width:200px;"` on a single contest-slug input, genuinely one-off sizing.
- `class-live-player.php:42,44` — `style="display:none;"` on two container elements toggled by JS at runtime. This is a legitimate initial-hidden-state pattern (not a duplicated visual style), fine as inline.

### Bugs/regressions found
None of the same failure shapes as tonight's fixes (missing `nowrap`, missing inter-element gap, unclamped card text, missing mobile padding) were found in bh-live's inline styles specifically. The `.bhl-chat-source-tag` badge-like tag in `class-overlay.php` (line 74, inside a `<style>` block, not an inline `style=` attribute) already has `white-space` handled implicitly by `display:inline-block` + short fixed content, so it's out of scope here and not at risk the same way.

---

## bh-registry

Previously unaudited (see note above — the fixed file was in `own-ur-shit`, not here).

### (a) Duplicates
None found. bh-registry has very few inline styles (7 total) and none collide with `.bhy-badge`/`.bhy-alert`/`.bhy-table-wrap`.

### (b) Genuine gaps
- `bh-registry/includes/class-streaming-bridge.php:37` — `style="width:100%;"` on the artist-search `<input>` matches the same "full-width input" shape flagged in bh-live above. Not enough repetition inside bh-registry alone to justify its own class, but worth folding into the same cross-plugin `.bhy-input-full` utility if that gets created from the bh-live findings.

### (c) One-off
- `bh-registry/includes/class-frontend.php:137` — `style="display:none;"` on the submit modal, legitimate initial-hidden-state toggle.
- `bh-registry/includes/class-frontend.php:155` — `style="display:none;"` on the verify step, same pattern, legitimate.
- `bh-registry/includes/class-style-surface.php:38` — `style="background:var(--bh-accent);"` on a preview-only avatar placeholder.
- `bh-registry/includes/class-style-surface.php:45` — `style="background:var(--bh-accent-soft);"` on a second preview-only avatar placeholder. These two are deliberately different colors for a two-card gallery preview; not a repeat of the same value, genuinely one-off content.
- `bh-registry/includes/class-streaming-bridge.php:38` — `style="margin-top:8px;"` on a results container, one-off spacing.
- `bh-registry/includes/class-streaming-bridge.php:67` — `style="padding:4px 0;"` on a single search-result row template, one-off.

### Bugs/regressions found
None. Checked specifically for the exact bug shapes from tonight:
- No badge-like element here lacks `white-space:nowrap` (bh-registry's actual `.bhr-badge`/`.bhr-badge-verified` classes are defined in `registry.css`, not inline, and out of scope for this inline-only pass — worth a quick look in a CSS-file audit pass, but not an inline-style bug).
- No adjacent inline button+badge pair with missing whitespace — the one candidate (`class-streaming-bridge.php`'s search-result link) is single-element, no pairing.
- No fixed-size card grid with inline styles here — `.bhr-grid`/`.bhr-card` layout is entirely in `registry.css`, not inline, so unclamped-title risk isn't visible from this pass.
- No inline mobile-padding gap found (no inline `padding`/margin on the search-row or grid container itself).

---

## bh-video

### (a) Duplicates
None outright, but see the cross-plugin dup with bh-live noted above (`(b)` section, `bh-live`) — bh-video's media-preview wrapper and `<video>` sizing are literal duplicates of bh-live's, so once a shared class exists these become mechanical swaps too:
- `bh-video/includes/class-admin.php:52` — `style="max-width:480px;margin-bottom:8px;"` → `.bhy-media-preview`.
- `bh-video/includes/class-admin.php:1,3` — `style="width:100%;"` on `<video>` → `.bh-video-fill`.

### (b) Genuine gaps
- `bh-video/includes/class-admin.php:64` — `style="width:100%;max-width:480px;"` on the track-select `<select>`. Combined with `bh-live/class-admin.php:43` (`width:100%;max-width:640px;`), this is the same "full-width, capped" input shape appearing twice across plugins with only the cap differing. Low priority given only 2 occurrences and differing max-widths, but worth folding into a parameterized `.bhy-input-capped` if the team ends up doing a spacing-token pass anyway.
- `bh-video/includes/class-chapters.php:35` — `style="width:100%;font-family:monospace;"` on the chapters `<textarea>`. One-off today, but if any other plugin adds a monospace textarea (e.g. a JSON/config field) this is the shape to reuse.

### (c) One-off
- `bh-video/includes/class-video-player.php:42,45` — `style="display:none;"` on the player wrap and chapters container, legitimate initial-hidden-state toggles matching the same pattern as bh-live/bh-registry.

### Bugs/regressions found
None of the tonight-shaped bugs found. Specifically checked:
- `.bhv-grid`/`.bhv-player-wrap` layout is entirely in `video-player.css`, not inline — no inline evidence of missing mobile padding or unclamped card titles (would need a CSS-file pass, not in scope here).
- No badge-like elements at all in bh-video.
- No adjacent button+badge or button+text pairs with missing inline gap.

---

## bh-social

### (a) Duplicates
- `bh-social/includes/class-admin.php:307` — `<table class="widefat striped" style="max-width:480px;">` for the per-platform stats table is **not** wrapped in `.bhy-table-wrap`, while the near-identical drafts table two hundred lines later (`class-admin.php:493`) correctly is (`<div class="bhy-table-wrap"><table class="widefat striped" style="margin-top:8px;">`). This is a straightforward mechanical fix: wrap the stats table in `.bhy-table-wrap` too, for the same sticky-header/scroll/border treatment the drafts table already gets, and drop the redundant `max-width:480px` (the wrapper already constrains via its own CSS/container).

### (b) Genuine gaps
None distinct enough to justify a new class — bh-social's remaining inline styles are heading spacing tweaks used once each.

### (c) One-off
- `bh-social/includes/class-admin.php:283` — `style="margin-bottom:16px;"` on the "Organic" section `<h2 class="nav-tab-wrapper">`, one-off section spacing.
- `bh-social/includes/class-admin.php:292` — `style="margin-top:32px;border-top:2px solid #ccd0d4;padding-top:20px;"` on the "Paid ad campaigns" `<h2>`, a one-off divider style marking the organic/paid section boundary. Not repeated elsewhere in this file or plugin.
- `bh-social/includes/class-admin.php:493` — `style="margin-top:8px;"` on the (correctly wrapped) drafts table, one-off spacing on top of the `.bhy-table-wrap` primitive.
- `bh-social/includes/class-admin.php:497` — `style="display:inline;"` on the per-row delete `<form>`, so the Delete button doesn't force a block-level break inside its table cell. Legitimate one-off layout fix, not a duplicate of any shared primitive.

### Bugs/regressions found
None of the tonight-shaped bugs found. Specifically checked:
- The `OUS_Badge::render()` calls that follow section headings (`class-admin.php:281,292,319,356,402,442`) are **not** bugs — each concatenation string ends in a literal trailing space (e.g. `'<h1>BH Social '`, `'<h3>YouTube '`) before the badge is appended, so there is whitespace between text and badge in every case. This is exactly the failure shape from tonight's button+badge bug, but it does NOT reproduce here — confirmed by inspecting the literal PHP string for each of the six call sites, not just the visual result.
- No unclamped card-grid text — bh-social's admin screens are plain WP admin tables/forms, no card grid exists.
- No missing mobile padding found inline — this is an admin-only plugin, standard wp-admin responsive behavior applies via the wp-admin table classes, and no inline overrides interfere with it.
