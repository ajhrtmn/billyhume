# Code-Quality Audit — own-ur-shit: Style System + Design Suite / Element Builder

- **Date:** 2026-07-25
- **Scope:** `own-ur-shit` design-token/style system (`BHY_Style`, `BHY_UI`, `BHY_Gallery`) and the Design Suite / page-builder node-tree layer (`BH_Element`, `BH_Element_Data`, `OUS_PageSurface`, `OUS_StyleSurface`, `BH_Design_Suite`).
- **Dimension:** CODE QUALITY ONLY (DRY / SOLID / cohesion / naming / dead code / fragile patterns / stale comments). UX is a separate task.
- **Model / provenance:** Ran on **Opus 4.8 as the fallback** for this task. It was originally assigned to the ecosystem's strongest available model (this is the largest, newest, most architecturally novel subsystem) but that model was out of usage credits at run time. Compensated by reading the in-scope code in full rather than skimming.
- **Verification caveat (non-negotiable):** **No live PHP / MySQL / WordPress execution environment was available.** This is **static analysis only** — every finding below was confirmed by reading the actual file/line, but none is runtime-tested. Statements about live behavior (data loss, render output) are reasoned from the code, not observed. Treat the "failure scenario" lines as hypotheses to smoke-test, not confirmed incidents.
- **Files read in full:** `class-element.php` (2162), `class-style.php` (1074), `class-ui.php` (830), `class-style-gallery.php` (769), `class-element-data.php` (463), `class-page-surface.php` (297), `class-style-surface.php` (95), `class-design-suite.php` (71); plus `class-style-surfaces.php` / `class-element-surface.php` (bh-contest) and asset directory for the preview-path trace.

---

## Headline assessment

This is, by a wide margin, the **highest-quality subsystem I have seen in this audit series.** The "why not what" comment bar the ecosystem sets for itself is genuinely met here — most non-trivial branches carry a dense comment naming the failure mode they prevent and cross-referencing sibling classes by name, and several comments document real, live-confirmed bugs and their fixes (the `trim()` char-mask bug at `class-element.php:1076`, the `:not([hidden])` UA-specificity bug at `class-ui.php:34`, the `contain:layout` fixed-positioning bug at `class-ui.php:265`). Security boundaries (attribute allowlisting, the `custom_js` capability gate at the single write path, fail-closed sanitizers) are real and well-reasoned.

The findings below are therefore mostly **moderate/low** — refinements against an already-high bar, not rescue work. The one finding with real teeth is a latent data-loss bug from duplicated-then-drifted save logic (#1).

---

## HIGH / MODERATE

### 1. Duplicated **and already drifted** style-option save logic → latent custom-slider data loss
- **Files:** `class-style-gallery.php:125-170` (`BHY_Gallery::save()`) vs `class-element.php:1744-1773` (`BH_Element::rest_save_site_tokens()`)
- **Category:** duplication / correctness
- The REST handler's own docblock admits it "Mirrors `BHY_Gallery::save()`'s existing admin-post handler field-by-field." Both re-implement the same ~25-line sanitize-and-write of the `bhy_style_settings` option (brand fields, the `color_`/`cat_color_` loop, the font loop, the five `safe_number` sliders). The two copies have **already diverged**:
  - `save()` persists plugin-registered **custom sliders** into `$data['custom']` (`:153-160`) and snapshots to `OUS_Revisions` (`:164-166`). `rest_save_site_tokens()` does **neither** (grep-confirmed: no `custom`/`revision` in `:1744-1773`).
  - Because both call `update_option(BHY_Style::OPTION, $data)` with a freshly-rebuilt `$data` (full-row replace, not merge), a save through the REST path **drops the entire `custom` sub-array**.
- **Failure scenario:** A site has a plugin-registered custom slider (e.g. bh-streaming's queue-row-height) with a non-default value saved. Something POSTs `ous/v1/elements/site-tokens`. `update_option` writes a `$data` with no `custom` key → every custom-slider value silently resets to its default on the next `inline_css()` render, with no error. Also: that save is invisible to Version History.
- **Live-impact caveat:** The REST route's GUI consumer was `element-builder.js`, which `class-style-gallery.php`'s docblock says was **deleted**. So the route may currently have no caller — impact is latent, not active, until any REST client (future GUI, integration test, external script) hits it. The drift itself is real regardless.
- **Fix direction:** Extract one authority, e.g. `BHY_Style::save_from_input(array $incoming, bool $snapshot = true): void`, and have both `BHY_Gallery::save()` (from `$_POST`) and `rest_save_site_tokens()` (from the JSON body) delegate to it. Kills the drift class permanently and puts custom-slider + revision handling in one place.

### 2. God-class watch: `BH_Element` (2162 lines, 6+ responsibilities)
- **File:** `class-element.php`
- **Category:** SOLID / cohesion
- One class currently owns: (a) the type registry, (b) placement storage/CRUD + the parent-tree invariants/cycle-check, (c) render + binding resolution, (d) the HTML **wrapper/attribute security boundary** (`wrap_placement_html`/`build_html_attrs`/`resolve_tag`, `:1049-1349`), (e) a hand-mapped **JS code generator** for `config.actions` (`build_actions_js`, `:1189-1241`), (f) a **12-route REST bridge** (`:1493-1887`), and (g) the Debug Tools admin UI (`:1904-2161`).
- This is cohesive *around* "the element system" and each section is cleanly fenced with banner comments, so it is not an emergency. But (d)+(e) together are a distinct, security-critical, highly-testable concern (untrusted config → escaped HTML + generated inline JS) that would benefit from living in its own `BH_Element_Renderer` with focused unit tests; the REST bridge (f) is a second natural extraction (`BH_Element_REST`). No behavior change — pure relocation.
- **Fix direction:** Treat as "split when next touched," starting with the renderer/attribute boundary. Do **not** rewrite for its own sake; the value is isolating the HTML/JS-generation trust boundary for testability, not line count.

---

## LOW

### 3. `esc_html()` applied to intended-HTML delimiters (confirmed output bug)
- **File:** `class-element-data.php:460`
- **Category:** correctness (cosmetic, debug-only)
- `echo '<p><code>' . esc_html(implode('</code>, <code>', $formatters)) . '</code></p>';` — the `</code>, <code>` separators are meant to be real markup, but `esc_html` runs over the whole joined string, so the delimiters render as **literal visible text** (`&lt;/code&gt;, &lt;code&gt;`) between formatter slugs instead of producing separate `<code>` chips.
- **Failure scenario:** Two+ formatters registered → Debug Tools > Element Data Sources shows `compact_number</code>, <code>relative_time` as literal text.
- **Not a security issue** (slugs are code-controlled). Fix: `implode('</code>, <code>', array_map('esc_html', $formatters))` — the same per-item-escape pattern used correctly two lines up in the sources table.

### 4. Front-end UI component misfiled inside the design-token class
- **File:** `class-style.php:1012-1073` (`BHY_Style::empty_state_html()` + `EMPTY_STATE_ICONS`)
- **Category:** cohesion / naming
- `BHY_Style`'s own docblock defines it as "the design-token system." `empty_state_html()` is a self-contained **front-end list/catalog empty-state component** — its own inline SVG icons, ~15 lines of embedded CSS, CTA/clear-filter affordances. It has nothing to do with design tokens; it lives here only because `BHY_Style` was a convenient always-loaded home. It reads as out of charter next to `safe_color()`/`PROPERTY_MAP`.
- **Fix direction:** Relocate to a front-end UI helper (or a new `BHY_FrontUI`). Low urgency — it works and is well-documented — but it muddies `BHY_Style`'s single responsibility.

### 5. Stringly-typed constant indirection in the style resolver
- **File:** `class-style.php:732-761` (`scale_table()`, `enum_table()`)
- **Category:** simplification / fragility
- `PROPERTY_MAP` stores table references as **strings** (`'scale' => 'FONT_SIZE_STEPS'`), forcing two ~15-arm `switch` statements that map a constant *name* back to the constant. Adding one preset table now requires edits in three coordinated places (`PROPERTY_MAP` + the relevant switch + `style_schema_for_js()`); forgetting the switch arm silently yields `[]` (an unresolvable property that just drops). The `switch` does double as an allowlist, so it is defensible — but `constant('self::' . $name)` guarded by an `array_key_exists` against a single canonical table registry would remove one of the three edit sites and the silent-`[]` trap.
- **Fix direction:** Optional. A single `const STYLE_TABLES = [...]` array (name → array) referenced by both resolvers and the JS-schema export would collapse the indirection.

### 6. Triplicated inline `<form>` blocks in the debug placement list
- **File:** `class-element.php:1976-2007` (`render_dashboard_placement_list()`)
- **Category:** duplication
- Three nearly-identical `<form method="post" action=admin-post.php>` blocks (Remove / ↑ / ↓) differ only by the hidden `op` value and button label. A tiny `emit_op_form($id, $op, $label, $nonce, $confirm = '')` helper would DRY it.
- **Priority:** minimal — this is Phase-1 "bare list" debug UI by explicit design, not product surface.

### 7. Dead-in-production method with a stale cross-reference to deleted code
- **File:** `class-element.php:1429-1474` (`render_surface_preview()`)
- **Category:** dead code / stale comment
- The only remaining callers are its own test suite (`class-element-test-suite.php:125-126`). Its production callers (the Design Suite canvas) were removed when the builder shell + `element-builder.js` were deleted (per `class-style-gallery.php`'s cleanup docblock). Its own docblock still explains itself in terms of `element-builder.js`'s `fireSelectionEvent()` — a file that no longer exists.
- **Fix direction:** Either re-wire it to a live surface or mark it explicitly test-only; at minimum correct the docblock's cross-reference to deleted `element-builder.js` so it doesn't mislead the next reader. (Grep also surfaces other lingering `element-builder.js` mentions in comments across `class-element.php`, e.g. `:576-585`, `:1442-1445`, `:1664-1672` — worth a sweep to prevent comment rot now that the file is gone.)

---

## CONFIRMED GOOD (specifically verified this pass)

### The medal-emoji (🥇🥈🥉) mojibake bug appears **already fixed** in current source
This was the concrete target handed to this pass. Trace of the actual preview path:
- The Results preview surface is `results_preview()` (bh-contest `class-style-surfaces.php:206-250`, medal emoji at `:220`), registered via `bhy_style_surfaces`.
- All such surfaces render through **one** path: `BHY_Gallery::render_canvas()` → `preview_doc()` → `base64_encode` into a `data-doc` attribute → client-side decode + shadow-DOM attach in `render_script()`.
- Both halves of the diagnosed charset gap are closed in-source:
  - `preview_doc()` emits `<!doctype html>...<meta charset="utf-8">` (`class-style-gallery.php:272`).
  - `render_script()` decodes with `Uint8Array.from(atob(raw), c => c.charCodeAt(0))` → `new TextDecoder('utf-8').decode(bytes)` (`:567-570`), with a long comment documenting exactly the "atob yields one-byte-per-char, multi-byte UTF-8 came through mojibake" failure and this as the fix.
- The old vulnerable second path (`element-builder.js`'s canvas) was **deleted**; grep finds no remaining `atob`/`srcdoc`/`TextDecoder` in any shipped JS asset. `class-style-surface.php`'s top docblock independently corroborates this was "caught in the same pass that fixed the gallery's own character-decoding bug."
- **Verdict:** Static analysis shows the charset/decoding defect is resolved on the single live preview path. **Not runtime-verified** (no browser/PHP execution here) — recommend one live smoke test of the Results surface in Design Suite to confirm, but there is no code defect left to fix on this path. If mojibake is still observed live, look outside this subsystem (e.g. DB connection charset, or the source file's own byte encoding), not at the preview injection code.

### Style-discipline convention is honored (the audit's explicit worry)
- `BHY_UI` (`class-ui.php`) is a genuinely shared, documented admin design system — spacing/type scales, card/alert/badge, the cited `.bhy-table-wrap` sticky-header/scroll precedent, sortable/searchable/copy behaviors — all as reusable classes, printed once globally. No stray **one-off inline styles** crept into the reusable components.
- The heavy inline styles in `OUS_StyleSurface::media_wizard_preview()` (`class-style-surface.php:67-91`) and the contest `results_preview()` are **mock fixtures imitating real pages inside the preview canvas**, not product UI — inlining is correct there (they must be self-contained preview documents), and does not violate the "deviations must be shared BHY_UI pieces" rule.

### Security / correctness boundaries verified sound
- `build_html_attrs()` (`class-element.php:1261-1349`) is a real per-type attribute allowlist; `href`/`target`/`rel` only emit when the tag is `a` and enum-validated; custom `data-*` fail closed.
- `config.custom_js` gated by `bhcore_author_custom_js` at the **single** write path `save_placement()` (`:686-691`), not just in the GUI; `</script>` split-defused at render (`:1173`).
- Fail-closed sanitizers (`safe_color`/`safe_number`/`safe_length`/`safe_enum`, `class-style.php:502-796`) and the `would_create_cycle()` hop-cap (`:836-855`) are correct and match the "why not what" comment bar.
- `BH_Element_Data::resolve()` (`class-element-data.php:284-390`) has a clean, well-contracted never-fatal fallback ladder (literal → fixture → source → formatter, each degrading to the caller's default). No PHP callables are ever persisted — a genuine security/portability boundary, correctly held.

---

## Prioritized punch-list (build order)

1. **Fix the save-path drift (#1).** Extract `BHY_Style::save_from_input()`; route both `BHY_Gallery::save()` and `rest_save_site_tokens()` through it so custom sliders + revision snapshots can't be silently dropped by the REST path. *(Highest real risk — latent data loss.)*
2. **One-line escaping fix (#3):** `array_map('esc_html', $formatters)` at `class-element-data.php:460`. *(Trivial, confirmed wrong output.)*
3. **Comment-rot sweep (#7):** correct/remove the dead `element-builder.js` cross-references now that the file is gone; decide whether `render_surface_preview()` is test-only or should be re-wired.
4. **Relocate `empty_state_html()` (#4)** out of `BHY_Style` into a front-end UI home. *(Cohesion; do when next touching either class.)*
5. **When next substantially editing `class-element.php`, split out the renderer/attribute+actions-JS trust boundary (#2)** into `BH_Element_Renderer` (and consider `BH_Element_REST`). *(Structural; opportunistic, not urgent.)*
6. **Optional polish:** collapse `scale_table()`/`enum_table()` indirection (#5); DRY the debug placement forms (#6). *(Lowest — cosmetic/dev-only surfaces.)*
7. **Runtime re-verification (all of the above + the mojibake path):** none of this was executed live. Smoke-test on a real install — ideally as a non-admin `editor` holding only `bhcore_design_site`, after an OPcache reset — before relying on any of it in production, per this subsystem's own repeatedly-stated verification discipline.
