# Style system

Check here before adding a spacing value, color, badge, alert or table treatment. Every style rule belongs to one of four layers.

## 1. Tokens (custom properties only)

- **Front end**: `BHY_Style::inline_css()` in `the-self-hosted-self/includes/class-style.php` prints the site's theme colors and sizes (`--bh-accent`, `--bh-surface`, `--bh-text`…), editable in the Design Suite. Fixed status colors (`--bh-success/-warning/-danger`) are not themeable.
- **Component tokens** a plugin registers (`bhy_style_component_tokens`, emitted as `--bh-comp-<group>-<key>`) show up automatically in the Customizer and as `style:components.<group>.<key>` properties for `wp bh` and Debug Tools (`class-style-props.php`). Register a token instead of hard-coding a value Billy might want to change.
- **Admin**: `BHY_UI::design_system_css()` in `class-ui.php` (`--bhy-*`).
- Always reference a token with a fallback: `var(--bh-accent, #2271b1)`.

## 2. Utilities (behaviour only, no look of their own)

| Content | Class |
|---|---|
| Fixed short label that must not wrap | `.bh-nowrap` |
| Badge with unpredictable text | `.bh-badge-truncate` |
| One line of text (table cell, list title) | `.bh-truncate` (add `title=` with the full text) |
| Card title/description | `.bh-clamp-2` / `.bh-clamp-3` |

Admin equivalents: `.bhy-truncate`, `.bhy-clamp-2`, `.bhy-badge-truncate`. Never hand-write ellipsis or line-clamp CSS.

## 3. Components

| Component | Front end | Admin |
|---|---|---|
| Status pill | `.bh-badge` + `-success/-warning/-danger/-neutral` | `.bhy-badge` (+ `.bhy-badge-dot`) |
| Message box | `.bh-alert` via `BHY_Style::alert($text, $type, $title)` (text, never markup) | `.bhy-alert` |
| Card | (theme content styling) | `.bhy-card` |
| Wide table | — | `.bhy-table-wrap`, `.bhy-sortable` |
| Empty state | `BHY_UI::empty_state_html()` | — |
| Copy button | — | `.bhy-copy-btn` |

If the shape you need isn't here and will recur, add it to the shared file, not as a one-off.

## 4. Plugin-local

Page-specific layout in each plugin's `assets/css/*.css`. It uses layers 1–3 and never redefines their values.

## Visual standards

- **Card grids**: `align-items: start`, and give every card the same skeleton (image slot or placeholder, reserved space for optional parts) so rows don't look jagged.
- **Depth and hover**: a faint resting shadow, a hover lift (`translateY`) with an accent-tinted glow derived from the accent token, and `prefers-reduced-motion` respected.
- **Placeholders**: inline SVG over an accent gradient, not a flat color block.
- **Edge cases**: test long titles, missing content, zero/extreme values and locked states with real content.
- **Each breakpoint is its own layout**; check phone width (~375px).
- **Message boxes** use `--bh-text` for body text; the hue goes in the side rule and icon only.

## Plugin and theme independence

Plugin CSS never assumes the theme's structure, and the theme never depends on a plugin being active. A plugin's full-page template handles both classic and block themes (`wp_is_block_theme()` → `block_header_area()` / `get_header()`). A new `add_rewrite_rule()` does nothing until permalinks are flushed.
