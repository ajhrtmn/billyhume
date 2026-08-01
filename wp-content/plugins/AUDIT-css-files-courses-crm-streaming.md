# CSS File Audit — bh-courses, bh-crm, bh-streaming

Follow-up to the inline-style audit. This pass covers actual `.css` files only. Design-system reference points: `own-ur-shit/includes/class-ui.php` (admin tokens `--bhy-*`, `.bhy-badge`, `.bhy-alert`, `.bhy-table-wrap`, `.bhy-card`) and `own-ur-shit/includes/class-style.php` (front-end tokens `--bh-*`, `.bh-truncate`, `.bh-clamp-2`, `.bh-clamp-3`). Confirmed already-fixed-tonight items (catalog card title clamp, catalog mobile padding) are not re-flagged.

---

## bh-courses/assets/css/admin.css

Small file, wp-admin metabox styling. Generally clean and already uses `var(--bhy-*, fallback)` consistently.

**(c) One-off/fine**
- `.bhc-order-title` (line 97-102): `flex:1; font-weight:600;` with no truncation. This is a lesson-order drag list, not a repeating card grid, and its siblings (`.bhc-order-steps`, `.bhc-order-status`) are already `white-space:nowrap`, so a long lesson title just wraps the row taller rather than breaking a grid. Low-risk, but if you want strict single-line rows, `.bh-truncate`-style handling would apply here too.
- `.bhc-order-status` (114-124) and `.bhc-order-steps` (108-112) already correctly use `white-space:nowrap` on their pills/labels — good pattern, worth pointing to as the model for the gaps below.

No bugs found in this file.

---

## bh-courses/assets/css/courses.css

**Bugs found**

1. **`.bhc-excerpt` has no clamp — course card description, same failure mode as the already-fixed title bug.** Line 53:
   ```css
   .bhc-excerpt { font-size: 13px; color: var(--bh-text-dim); }
   ```
   `.bhc-course-card` (38-41) is `display:flex; flex-direction:column` inside the `.bhc-catalog` auto-fit grid (37). The title (`h3`, line 49) already got `.bh-clamp-2` applied via PHP tonight, but the excerpt right below it is completely unclamped. A long excerpt will still make cards in the same grid row uneven height — this is the exact same bug shape as the title bug, just one element down, and it wasn't caught by that fix. Needs `.bh-clamp-2` or `.bh-clamp-3` applied server-side same as the title was.

2. **`.bhc-badge` / `.bhc-badge-difficulty` missing `white-space:nowrap`.** Line 114:
   ```css
   .bhc-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.02em; }
   ```
   Compare to `admin.css`'s `.bhc-order-status` (which explicitly sets `white-space:nowrap` on the same pill shape) — this rule doesn't. A difficulty label like "Intermediate" or "Advanced" can wrap mid-word inside the 999px pill at narrow card widths, breaking the pill shape.

3. **`.bhc-term` (category/tag pills) missing `white-space:nowrap`.** Line 162:
   ```css
   .bhc-term { font-size: 11px; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--bh-border); color: var(--bh-text-dim); }
   ```
   Same issue — a longer category name wraps inside the pill instead of staying single-line. `.bhc-course-terms` (161) is `flex-wrap:wrap`, so the fix is cheap: wrapping is fine at the *row* level, just not inside an individual pill.

4. **`.bhc-review-badge` missing `white-space:nowrap`.** Line 193:
   ```css
   .bhc-review-badge { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 999px; }
   ```
   Same pill-wrap risk as #2/#3 above.

**(b) Genuine gap**

5. **`.bhc-card-instructor` (121-122) has no truncation on the instructor name.** It's `display:flex; align-items:center; gap:6px` with a fixed-size avatar + name text, inside the same card that already had its title/excerpt overflow problems. A long instructor display name will wrap or push the row wider than the card. Should get `.bh-truncate` (or a `min-width:0` + flex-shrink setup) same as `.bhs-card-artist` in player.css already does correctly.

6. **`.bhc-leaderboard-name`** (188): `flex: 1; font-size: 14px;` — no truncation on a leaderboard row containing a user's display name. Long names will grow row height inconsistently across a leaderboard list where every other row is short. Minor but a real gap.

7. **`.bhc-course-hero img`** fixed `height:320px` (151) has no mobile override anywhere in this file's breakpoints (600px catalog-only, 780px lesson-layout, 480px lesson-mobile pass). A 320px-tall hero image at a narrow phone width eats a large share of the viewport with no responsive scaling. Worth a small mobile height reduction alongside the other breakpoints already in this file.

**(a) Duplicates**

8. `.bhc-badge`, `.bhc-term`, `.bhc-review-badge`, and `.bhc-order-status` (admin.css) are four separately hand-rolled badge/pill shapes within bh-courses alone — same `border-radius:999px; padding; font-size:11px` pattern reinvented four times with inconsistent `white-space` handling (only one of the four gets it right). There's no shared front-end `.bh-badge` primitive in `class-style.php` the way `class-ui.php` provides `.bhy-badge` for admin — this is a real front-end design-system gap, not just a bh-courses problem (see also `.bhs-badge*` in bh-streaming and `.bhcrm-kanban-stalled-badge` in bh-crm below, all independently reinvented).

**(c) One-off/fine**
- `.bhc-course-title` (155, hero h1 at 28px) and `.bhc-course-instructor-name` (159) are single-instance page-header elements, not repeating grid cells — low risk if they overflow.
- Hardcoded `#fff` on `.bhc-pagination-current` (145) and `.bhc-course-hero-content .bhc-course-title` (154) — both are text-on-accent-color/text-on-dark-scrim contexts, same pattern as `--bh-accent-contrast` used elsewhere in this file; acceptable as-is but could reference `--bh-accent-contrast` for consistency.

---

## bh-crm/assets/css/kanban-board.css

**Bugs found**

1. **`.bhcrm-sticky-card-title` has no truncation or clamp at all.** Line 321-323:
   ```css
   .bhcrm-sticky-card-title {
       font-weight: 600;
   }
   ```
   This is the server-rendered sticky-card title (`BH_Element::render_slot()` output, per the file's own comment at line 306) — task names of arbitrary length rendered into a fixed-width sticky card with zero overflow handling. Directly matches the kanban-card-long-task-name risk this audit was scoped to check. Needs `-webkit-line-clamp` or `.bh-truncate`-equivalent handling.

2. **No truncation on `.bhcrm-kanban-column-header`** (135-140), the draggable-column title shown at a fixed `flex: 0 0 260px` column width (line 127). If a column/status name is long, it wraps freely with no line-clamp or ellipsis, inconsistent with the rest of the tightly-controlled card layout below it.

**(b) Genuine gap — highest priority in this file**

3. **The entire stylesheet hardcodes admin colors instead of using `--bhy-*` tokens, despite living entirely in wp-admin.** Every color in the file is a raw hex/rgba literal — `#dcdcde`, `#2271b1`, `#646970`, `#00a32a`, `#8c8f94`, `#f6f7f7`, `#fff`, `#a7aaad`, `#50575e`, `#d63638`, `#8a5a00`, `#fdf0d5`, `#f0d090`, `#777`, `#eee` — none of them reference `var(--bhy-border)`, `var(--bhy-accent)`, `var(--bhy-ink-dim)`, `var(--bhy-success)`, `var(--bhy-danger)`, or `var(--bhy-warning)`/`var(--bhy-warning-bg)`, all of which exist and are defined in `class-ui.php` (lines 680-683, 719-741) for exactly this purpose. Compare directly to `bh-courses/assets/css/admin.css`, which is the same kind of wp-admin stylesheet and correctly uses `var(--bhy-ink-dim, #646970)` etc. throughout. Concrete examples:
   - Line 42-45: `background: #dcdcde;` (progress track) — should be `var(--bhy-border, #dcdcde)`.
   - Line 53: `background: #2271b1;` (progress fill) — should be `var(--bhy-accent, #2271b1)`.
   - Line 59: `background: #00a32a;` (complete state) — should be `var(--bhy-success, #00a32a)`.
   - Line 243-250 (`.bhcrm-kanban-stalled-badge`): `color: #8a5a00; background: #fdf0d5; border: 1px solid #f0d090;` — this is a warning badge that should be built from `var(--bhy-warning)` / `var(--bhy-warning-bg)`, ideally reusing `.bhy-badge-warning` from `class-ui.php` (line 740) rather than hand-rolling the amber palette here.
   - Line 291-293 (`.bhcrm-delete-btn.is-armed`): `background: #d63638; border-color: #d63638;` — should be `var(--bhy-danger, #d63638)`.
   
   This isn't a cosmetic nit: because none of these reference the design-system tokens, any future theme/brand-color change to `--bhy-*` in `class-ui.php` will silently not apply to the kanban board, leaving it visually inconsistent with the rest of wp-admin.

**(a) Duplicates**
- `.bhcrm-kanban-stalled-badge` (239-250) duplicates the shape of `.bhy-badge-warning` (pill, warning color, small text) without using the shared class or its tokens — see #3 above.

**(c) One-off/fine**
- `.bhcrm-kanban-card-title-input` / `.bhcrm-subtask-title-input` / `.bhcrm-kanban-card-notes` (editable `<input>`/`<textarea>` elements) correctly don't need clamp/ellipsis handling — native form controls already clip/scroll their own overflow text.
- Mobile breakpoint (368-407) is present and reasonably thorough (touch targets, stacked columns) — no gap there.

---

## bh-streaming/assets/css/player.css

This file is in noticeably better shape than the other two on the primary "titles without clamp" risk — `.bhs-card-title`/`.bhs-card-artist` (55-56) and `.bhs-np-title`/`.bhs-np-artist` (75-76) already correctly use `white-space:nowrap; overflow:hidden; text-overflow:ellipsis;`.

**Bugs found**

1. **`.bhs-queue-item` / `.bhs-queue-artist` have no overflow handling.** Lines 103-106:
   ```css
   .bhs-queue-item { padding: 8px 6px; font-size: 13px; cursor: pointer; border-radius: 4px; }
   ...
   .bhs-queue-artist { color: var(--bh-text-dim); font-size: 11px; }
   ```
   `.bhs-queue-panel` (98-101) is a fixed `width:280px` side panel. A long track title or artist name in the queue list will wrap onto multiple lines instead of truncating, making queue rows inconsistent height right next to the now-playing bar and card grid that both handle this correctly. Same fix pattern as `.bhs-np-title`/`.bhs-card-title` above (`white-space:nowrap; overflow:hidden; text-overflow:ellipsis;`), just missing here.

**(b) Genuine gap**

2. **`.bhs-jam-participant` names** (178-180) — `font-size:11px` in a `flex-wrap:wrap` row, no truncation on individual participant display names. Lower priority than #1 since it's flex-wrap at the row level (a long name just wraps to its own visual chip rather than breaking a fixed-width container), but still inconsistent with the rest of the file's careful truncation elsewhere.

3. **`.bhs-chapter-marker`** (192) — pill-shaped chapter label (`border-radius:999px`) with no `white-space:nowrap`. A long chapter title could wrap inside the pill, same shape-break risk flagged in courses.css's badges.

**(c) One-off/fine**
- `.bhs-badge` / `.bhs-badge-locked` (58, 147) are short fixed-vocabulary labels (lock icon / status), low overflow risk; no `white-space:nowrap` set but practically fine given their content.
- `.bhs-release-title` (60, page-level h1) — single instance, not a repeating card, low risk if overflowing.
- Hardcoded `#ff6b6b` (auth error/invalid state, lines 29-30) and `rgba(180,40,40,...)`/`rgba(180,120,20,...)` (stream health colors, 184-188) are **not** violations of "use existing tokens" — `class-style.php` does not define any front-end `--bh-danger`/`--bh-warning`/`--bh-success` tokens (only `--bh-accent`/`--bh-accent-soft`/`--bh-overlay` and their derived hover/muted variants exist, confirmed by grep of class-style.php). This is a genuine front-end design-system gap worth raising separately — there's no semantic error/warning color token to consolidate onto yet — but it isn't a bug in this file specifically.
- Mobile breakpoint (154-159) exists and covers the main layout panels reasonably.

---

## Cross-file summary

**Highest priority:**
- `bh-courses/assets/css/courses.css:53` — `.bhc-excerpt` unclamped, causes the same card-height problem the title clamp fix was meant to solve.
- `bh-crm/assets/css/kanban-board.css:321` — `.bhcrm-sticky-card-title` has zero overflow handling on server-rendered card titles.
- `bh-crm/assets/css/kanban-board.css` (whole file) — hardcodes wp-admin colors instead of `--bhy-*` tokens; will drift silently from any future admin theme/brand change.
- `bh-streaming/assets/css/player.css:103-106` — `.bhs-queue-item`/`.bhs-queue-artist` missing the truncation pattern this file otherwise applies consistently elsewhere.

**Secondary:** badge/pill `white-space:nowrap` gaps in `courses.css` (`.bhc-badge`, `.bhc-term`, `.bhc-review-badge`) and `player.css` (`.bhs-chapter-marker`), plus the broader observation that there is no shared front-end `.bh-badge` primitive in `class-style.php` — three plugins have each independently hand-rolled the same pill shape with inconsistent overflow handling.
