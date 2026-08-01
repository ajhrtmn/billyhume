# Inline Style Audit — bh-contest and bh-crm

Audited against shared primitives in `own-ur-shit/includes/class-ui.php` (admin: `.bhy-badge`, `.bhy-alert`, `.bhy-table-wrap`, `--bhy-space-*`) and `own-ur-shit/includes/class-style.php` (front-end: `.bh-truncate`, `.bh-clamp-2/3`, `--bh-space-*`).

Both plugins are almost entirely admin-screen PHP (`echo`/string-concat markup), so most findings are against the `.bhy-*` admin primitives. Neither plugin has much true front-end template code in the grep dump — `class-style-surfaces.php` (bh-contest) and `class-style-surface.php` (bh-crm) are admin-side theme-preview/mockup screens, not live front-end output, so they're bucketed as one-off/gap rather than checked against `.bh-truncate`/`.bh-clamp`.

---

## bh-contest

### (a) Duplicates

**Status/vote pill badges — duplicates `.bhy-badge`.** `background:...;color:#fff;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600` is exactly the `.bhy-badge` shape (color inverted — `.bhy-badge` variants use tinted bg + colored text, these use solid bg + white text, but it's the same component and should just get a `.bhy-badge-solid` modifier or reuse the existing variants with adjusted colors).
- `class-admin-list-tables.php:42` (submission-status pill)
- `class-admin-list-tables.php:48` (vote-status pill)
- `class-admin-metaboxes.php:283-284` (live/offline JS-built pill, dot + label — this one **also** duplicates `.bhy-badge-dot`)

Total: 3 sites, all in list-table/metabox status rendering.

**Warning/danger notice boxes — duplicates `.bhy-alert`.** `border:1px solid X;border-radius:4px;padding:10px 14px;margin-bottom:15px;background:Y` where X/Y are the warning or danger pair is precisely `.bhy-alert-warning`/`.bhy-alert-danger`.
- `class-admin-metaboxes.php:75` (pending-replacement notice, `#fff8e5`/`#dba617` — warning)
- `class-admin-metaboxes.php:93` (rejected notice, `#fbeaea`/`#b32d2e` — danger)
- `class-auth.php:27` (locked/inactive contest notice, `#3a2a00`/`#ffcf6b` — warning, dark-mode-only palette, worth normalizing to the token)
- `class-admin-metaboxes.php:168` (phase banner, dynamic color — same border+bg+padding shape, arguably `.bhy-alert` with an inline `--bhy-alert-color` custom property instead of fully inline)

Total: 4 sites.

**Spacing that maps to `--bhy-space-*` but is hardcoded.** Several `margin`/`padding` values (`4px`, `8px`, `16px`, `20px`) that already match `--bhy-space-1/2/4/5` are written as raw px instead of the token, right alongside other lines in the same files that *do* use `var(--bhy-space-*)` (e.g. `class-console.php` mixes both patterns). Lower priority per your framing, but worth a pass:
- `class-admin-metaboxes.php:78,85,94,199` (`margin-bottom:15px`, `margin-top:0`, `margin:8px 0 0`, etc.)
- `class-contest-wizard.php:89`, `class-style-surfaces.php:66` (`margin-top:20px`)
- `class-console.php:53` already uses `var(--bhy-space-4)` — good precedent to extend to the rest of the file's raw-px lines (`class-console.php:69` region uses `var(--bhy-space-2)` too, but line 33/etc mixed elsewhere).

### (b) Genuine gaps

**No utility for a labeled inline-flex row (icon/dot + text).** The "colored dot + status text" pattern shows up standalone, not just inside a badge:
- `class-admin-metaboxes.php:283-284` (live indicator: flex, gap:4px, dot span + label span)
- `class-admin-metaboxes.php:171,205` (`display:flex;align-items:center;justify-content:space-between` for a `<strong>` + a status dot placeholder)

Propose `.bhy-row-between` (flex, align-items:center, justify-content:space-between) and `.bhy-inline-dot` (7-8px circle, currentColor/bg, for building custom status indicators outside the badge component) — both admin-side, both repeat 2-3x here alone and are a generic-enough shape to expect elsewhere.

**No width utility for small numeric/short-text inputs.** `style="width:56px"`, `width:60px`, `width:70px`, `width:80px` on `<input type="number">` / small text inputs repeats across both plugins with slightly different magic numbers for essentially the same "short numeric input" need:
- `class-admin-metaboxes.php:210,211` (`width:56px`, vote base/bonus)
- `class-admin-metaboxes.php:434` (`width:80px`, round cut count)
- `class-debug.php:101` (`width:60px`, count)

Propose `.bhy-input-xs` (~60px), `.bhy-input-sm` (~120px) as fixed-width admin input utility classes — same idea as the ones already used ad hoc at `width:120px` in `class-admin-metaboxes.php:576-577`.

**No utility for full-width form field (`width:100%` on inputs/textareas).** This is the single most repeated inline style in the plugin — appears on `<input>`/`<textarea>` at least 15 times (`class-style-surfaces.php:53,63`; `class-contest-wizard.php:58,74,84,86`; `class-admin-metaboxes.php:86,106,107,108,112,115,231,237,370,393,396,483,514,547` region). Propose `.bhy-input-full` or simply document that a plain `<input>`/`<textarea>` inside `.bhy-card`/`.wrap` should get `width:100%` from a form-context rule rather than being repeated per-field — this is the highest-value mechanical win in this plugin by sheer count.

### (c) One-off

Roughly 25-30 sites are genuinely page-specific bespoke layout — the reveal-slide JS templates (`class-reveal.php:328-338`, building presentation HTML with one-off margins per slide type), the logo-preview box (`class-admin-metaboxes.php:547-549`), the category swatch grid wrappers (`class-admin-metaboxes.php:575,581,591`), and the entire `class-style-surfaces.php` theme-preview mockup block (lines 90-243, `--bh-cat-color`/`--bh-hue` custom-property wiring for a live theme demo — inherently one-off since it's rendering example UI, not real content). Not worth abstracting.

### Bugs/regressions found

- **`class-admin-metaboxes.php:576-577`** — two adjacent `<label>` elements (`First part` / `Accent part` text inputs) sit inside a flex container (`class-admin-metaboxes.php:575`, `gap:10px` — this one's fine, has a gap) but each `<label>` itself wraps flex-direction:column content with no stated `gap` fallback issue... actually re-check: line 575 does have `gap:10px`, so this is *not* a missing-gap bug. Retracting — see below for the actual bug.
- **`class-admin-metaboxes.php:241`** — `<button id="bh_discord_send">Send to Discord</button> <span id="bh_discord_status" style="margin-left:8px;">` relies on a literal space character between button and span for separation, same fragile pattern as the STALE-badge+button bug already fixed elsewhere. It happens to have a text-node space in the source so it won't zero-gap, but it's the same brittle pattern (whitespace-dependent, not a real gap) — flag for consistency, not urgent.
- **`class-admin-metaboxes.php:427`** — round-block container uses inline `border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:10px` per round, and `$display` is concatenated in front of it (`'style="' . $display . 'border:1px solid...'`) — if `$display` doesn't end in `;` this silently corrupts the style attribute. Worth a defensive semicolon check even though it may work today.
- **`class-admin-list-tables.php:42/48`**: the status pill duplicating `.bhy-badge` (see (a) above) uses `padding:2px 10px` inline and depends on the surrounding list-table cell not constraining width — same class of bug as the already-fixed "badge wrapped to two lines" issue. No `white-space:nowrap` is applied inline, and since it does NOT use `.bhy-badge` (which has `white-space:nowrap` built in), a long status label here **will wrap** in a narrow list-table column. This is the same bug shape as tonight's fixed badge-wrap issue — flag as high priority.

---

## bh-crm

### (a) Duplicates

**Progress bar track/fill — no shared component, but repeats verbatim within this plugin (borderline (a)/(b)).** Not one of the four named primitives, but it's the same shape twice with slightly different sizing, which strongly suggests it belongs in class-ui.php as `.bhy-progress` / `.bhy-progress-fill`:
- `class-subtasks.php:333` (`.bhcrm-progress-bar-track` / `-fill`, `width:X%` inline)
- `class-projects.php:745-746` (same track/fill pattern, additionally hardcodes `height:5px;background:#dcdcde;border-radius:999px` and a conditional fill color `#00a32a`/`#2271b1` inline instead of via a `.bhy-badge-success`-style modifier class)

Total: 2 sites, but each is a multi-property inline block, and the color-conditional logic at `class-projects.php:746` duplicates the same green/default semantic bh-crm already has via `bhcrm-progress-bar-fill.is-complete` class — the inline `background:` computation should be deleted entirely and left to CSS driven by `.is-complete`.

**Notice/warning box — duplicates `.bhy-alert-warning`.**
- `class-subtasks.php:298` — `color:var(--bhy-warning,#b45309)` on a `<p class="description">`, textual-only (no border/bg), so it's a *partial* duplicate — really closer to gap (b) below (text-only semantic color) than a full `.bhy-alert` swap.

**Card/surface panel — duplicates the admin `.bhy-card`-style surface treatment.**
- `class-people.php:258` — `margin:var(--bhy-space-4,16px) 0;padding:var(--bhy-space-4,16px);background:var(--bhy-surface,#fff);border:1px solid var(--bhy-border,#dcdcde);border-radius:var(--bhy-radius,8px)` is a hand-rolled `.bhy-card` (already uses the tokens as fallback values, which shows the author knew about the token but not the component class). Should just be `<div class="bhy-card">`.

Total across this plugin: 4 sites are near-exact duplicates of existing components.

### (b) Genuine gaps

**Chip/pill component for saved-list/filter tags — different shape than `.bhy-badge`, not a duplicate, but repeats and deserves its own utility.** `class-people.php:267` (`display:inline-flex;align-items:center;gap:4px;background:X;border:1px solid var(--bhy-border);border-radius:14px;padding:4px 4px 4px 12px`) — this is a dismissible-chip shape (asymmetric padding for a trailing × button at `class-people.php:269`), distinct from `.bhy-badge`'s status-pill purpose. Propose `.bhy-chip` / `.bhy-chip-remove` in class-ui.php — the "saved segment," "active tag filter," and "applied filter" UI pattern is generic enough to recur in bh-contest and other admin list views too.

**Text-only semantic color (success/danger/dim) without badge background — repeats often, no utility exists.** `color:var(--bhy-ink-dim,...)`, `color:var(--bhy-warning,...)`, `color:#b32d2e` (danger) appear as bare inline `color:` declarations on `<span>`/`<strong>`/`<a>` at least 8 times:
- `class-people.php:268-269,364,368,400` (ink-dim variants)
- `class-subtasks.php:298` (warning)
- `class-subtasks.php:478`, `class-notes.php` region, `class-card-log.php:415` (`color:#646970` — same ink-dim value, hardcoded instead of the token)

Propose `.bhy-text-dim`, `.bhy-text-warning`, `.bhy-text-danger`, `.bhy-text-success` — text-color-only versions of the badge semantic colors, exactly as your prompt anticipated. This is the single highest-count gap in bh-crm.

**Inline-form utility (`display:inline` / `display:inline-block` on `<form>`).** Admin-post forms wrapping a single button repeat this exact pattern 5+ times so the form doesn't force a line break:
- `class-card-log.php:301,377` (`display:inline;margin-left:8px`)
- `class-subtasks.php:434,472` (`display:inline`)
- `class-projects.php:871` (`display:inline-block`)

Propose `.bhy-form-inline` (display:inline-block, matches the majority usage) — trivial but repeats enough to be worth a class rather than 5 copies of the same 2 declarations.

**Fixed-width text input scale — same gap as bh-contest, different numbers.** `width:180px/200px/220px/280px/300px/320px/360px/400px/600px` scattered across `class-notes.php:218`, `class-tags.php:73`, `class-people.php:215,285`, `class-card-log.php:364,403,404`, `class-projects.php:401,402,890,1011`, `class-subtasks.php:514`. These don't cleanly bucket to 2-3 repeated values the way bh-contest's did — it's more like 10 distinct one-off widths. Recommend a small `.bhy-input-sm/md/lg` (e.g. 160/240/360px) scale that most of these could round to, rather than proposing per-value classes.

### (c) One-off

Roughly 20 sites are genuinely bespoke: the kanban board/column layout in `class-style-surface.php:84-107` (flex board with named columns — page-specific structural layout, not worth abstracting), the identity-header banner treatment (`class-people.php:356,358-359` — profile-page-specific avatar/banner composition), and various one-shot form layouts (`class-projects.php:398,1050` — flex row with specific gap for a single add-project form). Examples cited; not listing all individually.

### Bugs/regressions found

- **`class-people.php:267-269` chip/tag row has no `flex-wrap` on individual chip content and no `white-space:nowrap` guard** — the parent row at `class-people.php:262` does have `flex-wrap:wrap` (good), but a single long saved-list name inside one chip has nothing to stop it from stretching that one chip to an awkward width — same bug family as "no clamp on variable-length text," lower severity since it's an admin list name (usually short) rather than user-facing content, but worth a `.bh-truncate` on the `<a>` at line 268 as a defensive fix.
- **`class-projects.php:401` / `class-people.php:215`, `class-card-log.php:364/403/404` — fixed-px-width text inputs (`width:360px`, `width:320px`, `width:300px`) with no `max-width:100%`.** On a narrow admin viewport (mobile/tablet wp-admin, or a metabox sidebar) these will overflow their container rather than shrinking — the same class of bug as "no padding at mobile widths" / "table forced wider than its container" already found elsewhere tonight. None of these have a responsive fallback. Recommend `max-width:100%` alongside every fixed px width in this plugin, or switch to the proposed `.bhy-input-sm/md/lg` classes with `max-width:100%` baked in once.
- **`class-projects.php:745-746` progress bar fill color computed inline (`$pct >= 100 ? '#00a32a' : '#2271b1'`) duplicates the `.is-complete` class already applied on the same element one line earlier** — the inline color and the CSS class can theoretically disagree if `.is-complete`'s CSS definition and this PHP ternary's threshold ever drift apart (e.g. someone changes the CSS to trigger completion styling at a different condition). Not currently broken, but it's a landmine: same root cause as bugs where inline styles and class-driven styles fight over the same property.
