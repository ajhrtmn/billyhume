# Inline-style audit — bh-streaming, bh-courses, bh-monetization-woo

Scope: every inline `style="..."` site in these three plugins (grep dumps: 75 sites in bh-streaming, 34 in bh-courses, 33 in bh-monetization-woo), checked against the shared primitives in `own-ur-shit/includes/class-ui.php` (admin: `.bhy-badge`, `.bhy-alert`, `.bhy-table-wrap`, `--bhy-space-*`) and `own-ur-shit/includes/class-style.php` (front-end: `.bh-truncate`, `.bh-clamp-2/3`, `--bh-space-*`).

---

## bh-streaming

### (a) Duplicates

- **Status-pill badge shape** duplicates `.bhy-badge` (which already resolves to `border-radius:999px`). Inline: `background:#1DB954;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;` / the grey `#787c82` variant.
  - `includes/class-style-surface.php:41,46`
  - `includes/class-pro-wizard.php:202` (both the open-signup and invitation-only spans on one line)
  - 4 occurrences total. Should be `<span class="bhy-badge bhy-badge-success">` / `bhy-badge-neutral` (add a `background:#1DB954` override or a new `bhy-badge-*` variant if the exact green isn't in the existing palette).

- **Card surface shape** (`border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;`) duplicates `.bhy-card` (`--bhy-radius:8px`, same border/background) almost field-for-field.
  - `includes/class-style-surface.php:40,45`
  - `includes/class-pro-wizard.php:201`
  - 3 occurrences. Swap to `class="bhy-card"`.

- **`.bhy-alert` used but re-styled inline anyway.** `includes/class-isrc.php:47` applies `class="bhy-alert"` and then also sets `border-left:3px solid #2271b1;background:#f6f7f7;padding:14px 16px;margin:16px 0;max-width:760px;` inline — the class already provides border-left (4px, not 3px — the inline value silently overrides the shared token), background, and padding. Only `max-width:760px` is actually novel here. This is the same "class present, fully re-declared inline anyway" pattern flagged as a bug in bh-courses below — drop everything except `max-width` and let `.bhy-alert-info` (`includes/class-pro-wizard.php:172` already does this correctly) carry the rest.

- **Two-column card grid** (`display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;max-width:{640|760}px;`) is byte-for-byte the same shape in two files.
  - `includes/class-style-surface.php:39`
  - `includes/class-pro-wizard.php:199`
  - No existing utility for this — see (b).

### (b) Genuine gaps

- **JS-toggle `display:none` initial state**, ~15 sites, all in `includes/class-player.php` (lines 8–24 of the dump: `#bhs-login-open`, `#bhs-account-info`, `#bhs-import-modal`, `#bhs-auth-modal`, the email field, `#bhs-related`, `#bhs-nowplaying`, `#bhs-quality-toggle`, `#bhs-np-chapters`, `#bhs-jam-banner`, `#bhs-queue-panel`, `#bhs-jam-modal`, `#bhs-lyrics-panel`, `#bhs-quality-panel`, `#bhs-eq-panel`, `#bhs-playlist-picker`) plus similar ones in `class-admin.php:146` and `class-video-post-types.php:78`. This is the single most repeated shape in the plugin. Propose a `.bh-hidden` / `.bhy-hidden` utility (`display:none !important` or plain `display:none`) that markup emits instead of inline `style="display:none;"`, and have the toggle JS flip the class instead of `element.style.display`. Bonus: makes the "currently shown" state trivially greppable/CSS-overridable per theme.

- **Full-width form control** (`style="width:100%;"` on inputs/selects/textareas), ~10 sites across `class-feeds.php`, `class-admin.php`, `class-isrc.php`, `class-pro-wizard.php`, `class-style-surface.php`. Propose `.bhy-input-full { width:100%; }` (admin) — trivial but this is by far the single most common exact-string repeat in the dump.

- **Text-only danger/success color** (no badge background), e.g. `color:#1DB954` for "attached", `color:#b3261e` / `color:#b32d2e` for "missing"/"Remove"/errors — `class-admin.php:32,41`, `class-audio-hash.php:106`. No existing "text-color-only" semantic class (the closest, `.bhy-badge-danger`, brings a pill background you don't always want). Propose `.bhy-text-success` / `.bhy-text-danger` (color only, no padding/background) — this exact color repeats identically in bh-courses and bh-monetization-woo too (see below), so it's worth landing once, ecosystem-wide.

- **Fixed-size square media preview** (`width:120px;height:120px;background:#f0f0f0;border-radius:6px;overflow:hidden;` and its 160px sibling), `class-admin.php:30,286`. Propose `.bhy-media-preview` / `.bhy-media-preview--lg` since this exact box (with the same `<img style="width:100%;height:100%;object-fit:cover;">` inside it) appears twice in this file alone and is a plausible cross-plugin admin pattern (any "featured image/audio artwork" upload field).

### (c) One-off

Roughly 20 sites are genuinely bespoke and not worth a shared class: the QR-code share box (`class-feeds.php:89-95`), the plays-per-day bar chart (`class-stats.php:145,148`), the lyrics/chapters `<textarea>` monospace styling (`class-chapters.php:37`, `class-admin.php:269,272`), one-off admin-notice padding wrappers. Example: `class-admin.php:217` (`margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #dcdcde;` — a one-time section divider, not repeated elsewhere in the dump).

### Bugs/regressions found

- **`class-isrc.php:47`** — `.bhy-alert` class applied but every property the class already provides is re-declared inline with a *different* value (`border-left:3px` vs the shared token's `4px`). Not fatal, but it means this one alert box silently drifts from the shared alert styling any time the design system's border width/colors change — the inline override wins the cascade and nobody editing `class-ui.php` will know this call site stopped tracking it.
- **`class-admin.php:150`**: `style="' . ($is_mock ? '' : 'display:none;') . 'color:#996800;"` — functionally fine (property order doesn't matter in CSS), but building the string this way is fragile: a future edit that inserts a property between the ternary and `color:` risks a missing semicolon merging two declarations. Low priority, flagging for awareness only, not a live bug today.

---

## bh-courses

### (a) Duplicates

- **`.bhy-card` used but fully re-styled inline anyway** — this is a clean duplicate/bug, worse than the streaming case above because *nothing* is left as a genuine override:
  - `includes/class-progress-admin.php:109`: `class="bhy-card" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;background:#fff;"`
  - `includes/class-progress-admin.php:225`: same, plus `max-width:640px;`
  - Every property in the inline style (margin, padding, border, background) is already what `.bhy-card` provides via `--bhy-space-4`/`--bhy-border`/`--bhy-surface`. Delete the inline style entirely (keep `max-width` on the second one if it's actually needed).

- **Hand-rolled progress bar** duplicates the plugin's own existing progress-bar component. `includes/class-progress-admin.php:302` builds a full mini progress bar from scratch inline (`background:#e0e0e0;border-radius:3px;width:120px;height:8px;display:inline-block;overflow:hidden;` wrapping a `background:#2271b1;height:100%;width:X%;` fill div) even though `class="bhc-admin-progress-bar"` is already on the wrapper — meaning either the CSS class has no rules backing it (dead class, real bug — see below) or it's simply not being used. Compare to the *correct* pattern used elsewhere in this very plugin: `.bhc-progress-bar` / `.bhc-progress-fill` with only `style="width:X%"` supplying the one legitimately-dynamic value (`class-render-catalog.php:181`, `class-render-course.php:204,310`, `class-portal-panel.php:127` — these four are fine, textbook "class carries the shape, inline carries only the one dynamic number" usage, not a violation).

- **Text-only danger color**, same shape/gap as bh-streaming's: `color:#b32d2e` at `class-admin.php:918,922` ("none —", "orphaned"), `class-progress-admin.php:112,301` (stalled-student flag ⚠). Same `.bhy-text-danger` proposal as above — 4 occurrences here.

### (b) Genuine gaps

- **JS-toggle `display:none`**, same pattern/proposal as bh-streaming: `class-render-lesson.php:134,157,332,333,394`. Same `.bh-hidden`/`.bhy-hidden` utility would cover these (front-end context here, so `.bh-hidden`).

- **Section heading reset** (`style="margin-top:0;font-size:14px;"` on an `<h2>`), identical at `class-progress-admin.php:110,226`. Small, but exact duplicate twice in one file — candidate for `.bhy-card > h2` styling already existing generically in `class-ui.php:711` (`.bhy-card > h2, .bhy-card > h3 { font-size: var(--bhy-text-sm); ... }`) — if these two `<h2>`s are inside `.bhy-card` wrappers (they are, see above), **this inline style is entirely redundant with CSS the card already provides once the card's own inline override is removed.** Fold into the `.bhy-card` fix above.

### (c) One-off

About a dozen sites are legitimately bespoke: the certificate signature field max-width (`class-admin.php:312`), module/section title input width (`class-admin.php:506`), the "available after N days" number input width (`class-admin.php:518`), the instructor-notes textarea (`class-instructor-notes.php:37`), the video-mb-limit input (`class-video-settings.php:56`). None of these repeat elsewhere in the dump.

### Bugs/regressions found

- **`class-progress-admin.php:117`**: `<table class="widefat striped" style="max-width:720px;">` is a plain hand-built admin table that is **not** wrapped in `.bhy-table-wrap`. This is the exact bug shape already fixed elsewhere this session (a table with no horizontal-scroll affordance, forced wider than its container on narrow viewports) — `class-ui.php`'s own docblock for `.bhy-table-wrap` specifically calls out "BH Courses' Student Progress — genuinely one column per lesson, the actual worst-case width in this whole ecosystem" as a table that needs the wrapper, and this is that exact table not using it. High-priority fix: wrap it, and drop the redundant inline `max-width` (the wrapper handles overflow itself).
- **`class-progress-admin.php:302`** — the hand-rolled progress bar noted in (a): either `.bhc-admin-progress-bar` has no CSS backing it (in which case this is silently working by accident via 100% inline styling and the class is decorative/dead) or the plugin has two divergent progress-bar implementations that will visually drift from each other the next time either is restyled. Worth a two-minute check of whether `.bhc-admin-progress-bar` actually has rules; if not, either delete the class attribute or wire it to real CSS and drop the inline styles.
- **Course-card title clamp — confirmed fixed, and confirmed not needed elsewhere in this plugin.** `class-render-catalog.php:164` already uses `class="bh-clamp-2"` on the catalog card title (per the file's own comment, applied tonight). Checked the other two title-rendering surfaces in this plugin's dump (`class-render-course.php`, `class-render-lesson.php`) — neither renders a card-grid title with the same uneven-row-height risk, so no further action needed inside bh-courses itself.

---

## bh-monetization-woo

### (a) Duplicates

- **`.bhy-table-wrap` already used correctly** — `class-admin.php:140` (`class="bhy-table-wrap" style="max-width:760px;"`) is a good example of the right pattern (class carries the scroll/height behavior, inline carries just a page-specific max-width). No action needed; noting it because it's the control case against the bh-courses table bug above.
- **`.bhy-alert` used correctly with only a genuinely novel inline value** — `class-admin.php:173` (`class="bhy-alert bhy-alert-success/danger" style="max-width:760px;"`) — same good pattern, no action needed.
- **Text-only danger color**, same recurring gap: `class-crm-integration.php:122,127,130,133` (fraud/risk flags), `class-crm-integration.php:146` (Revoke button). 5 occurrences — the densest cluster of this pattern in any of the three plugins. Strong case for landing `.bhy-text-danger` centrally and pointing all three plugins at it in one pass.
- **Hand-rolled alert/card boxes that don't use `.bhy-alert` at all**, `class-tiers.php:207-210` (`background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:16px 0 12px;`) and `class-tiers.php:223-225` (`background:#fcf9e8;border:1px solid #dba617;border-radius:6px;padding:14px 16px;margin-bottom:12px;`). These are functionally the exact `.bhy-alert-info` / `.bhy-alert-warning` shapes (compare colors: `#fcf9e8`/`#dba617` is essentially `--bhy-warning-bg`/`--bhy-warning`) built from scratch instead of using the existing classes. Should become `class="bhy-alert bhy-alert-info"` and `class="bhy-alert bhy-alert-warning"`.

### (b) Genuine gaps

- Same `width:100%`/fixed-width input pattern as the other two plugins (`class-tiers.php:168,177,191,210,220`; `class-monetization-ui.php:95,98`; `class-debug.php:53,101`) — same `.bhy-input-full` proposal, 8 occurrences here alone.

### (c) One-off

`class-tiers.php:163,166` (cover-image preview conditional display, tied to a specific `max-width:200px;max-height:120px` shape not repeated elsewhere), the checkbox row layout at `class-tiers.php:227`, the SHA-256 hash `<code>` styling at `class-purchase-ledger.php:194`. None repeat elsewhere in the dump.

### Bugs/regressions found

- **Product cards have no title clamp — confirmed missing, matches the exact known failure pattern.** `assets/css/storefront.css:58`: `.bhm-product-card-title { padding:0 12px; font-weight:600; font-size:14px; color:var(--bh-text, inherit); }` has **no** `white-space`/`overflow`/`line-clamp` handling at all — a long product title will simply grow the card's height, producing uneven row heights in `.bhm-product-grid` (`class-recommendations.php:92`, `class-storefront.php:359`, both rendered as CSS grid — `storefront.css:31-39` — where every card in a row should be the same height). This is not an inline-style issue but it's exactly the gap the task asked to check for, and it's the same failure shape as the already-fixed bh-courses catalog bug. Recommend `class="bhm-product-card-title bh-clamp-2"` (or add `-webkit-line-clamp` directly to the CSS rule) the same way `class-render-catalog.php:164` was fixed.
- **bh-streaming has the same class of gap, one severity level down.** `assets/css/player.css:55`: `.bhs-card-title { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }` — this one *does* handle overflow, but it hand-rolls the exact three declarations `.bh-truncate` already provides (`class-style.php:104`) instead of using the shared class. Not a visible bug (it works), but it's a duplicate worth collapsing — replace the three properties with `.bh-truncate` (or apply the class in markup and delete the CSS rule) so future changes to the shared truncation behavior (e.g. adding `title="..."` handling, or changing the ellipsis approach) don't need a second edit here.

---

## Cross-plugin summary (for whoever compiles the master doc)

- **Highest-priority bug**: `bh-monetization-woo/assets/css/storefront.css:58` — `.bhm-product-card-title` has zero overflow handling, same uneven-card-height failure mode as tonight's already-fixed bh-courses catalog bug.
- **Second bug**: `bh-courses/includes/class-progress-admin.php:117` — Student Progress table not wrapped in `.bhy-table-wrap`, the exact table the wrapper's own docblock names as its worst-case use case.
- **Recurring mechanical fix across all three plugins**: `.bhy-card` class applied then fully re-declared inline (bh-courses `class-progress-admin.php:109,225`; partial version in bh-streaming's `.bhy-alert` misuse at `class-isrc.php:47`).
- **Recurring proposed new utility, present in all three plugins**: a text-color-only `.bhy-text-danger`/`.bhy-text-success` (13 combined occurrences: bh-streaming 2, bh-courses 4, bh-monetization-woo 5, plus 2 more in bh-streaming's video/audio-hash files) and a `display:none`-on-load `.bh-hidden`/`.bhy-hidden` utility (~20 combined occurrences, heaviest in bh-streaming's player modals).
