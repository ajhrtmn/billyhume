# Storybook — the design system, rendered from source

```bash
npm run storybook          # regenerates fixtures, then serves on :6006
npm run storybook:build    # static build -> storybook-static/
npm run storybook:fixtures # regenerate only (after changing a renderer)
```

## The one rule: nothing here hand-writes component markup

`tools/gen-storybook-fixtures.php` renders every component, in every state,
through the **real `BHY_UI::` renderers** and writes `fixtures.json`. The
stories only display that.

This matters because a story containing `<span class="bhy-badge …">` would be
one more copy of markup that already exists — free to drift the moment the
renderer changes. That is precisely how this codebase acquired eight
hand-rolled badge shapes and a `.bhm-paywall` copy that silently diverged in
`bh-streaming`. Generating from the render path means the gallery cannot lie.

**After changing a renderer or the design-system CSS, run
`npm run storybook:fixtures`.** `npm run storybook` does it automatically.

## What gets loaded, and why it took two passes

Storybook loads the actual stylesheets via `staticDirs` — `admin-skin.css` and
`the-self-hosted-self/assets/css/admin.css`. But two things are **PHP-generated and
printed inline**, so no `<link>` can reach them:

1. `BHY_UI::design_system_css()` — where `.bhy-alert` lives *exclusively*
   (zero occurrences in `admin-skin.css`).
2. `shsas_bridge_bhy_tokens()` — the `--bhy-*` → `--shsas-*` bridge.

Without both, components fell back to `class-ui.php`'s light `:root` defaults
and rendered light-on-dark. The generator now emits both from their real
sources. If a component ever looks unstyled or mis-themed here, suspect a
third inline-CSS source before suspecting the component.

## Toolbars

- **Theme** — toggles `data-shsas-theme` on `<html>`, the same attribute the
  skin keys every token off.
- **Viewport** — the same six widths the Playwright audit uses (1440 / 1280 /
  1024 / 961 / 782 / 375), so a finding in one is reproducible in the other.
- **Accessibility** — axe, per story.

## What it found on day one

`.bhy-alert-success/-warning/-danger` set `color` to a saturated hue on a 16%
tint of that same hue: **3.36 / 4.00 / 4.15:1** in light, **4.38:1** (danger)
in dark — all below AA. `.bhy-alert-info` was already correct because it uses
`var(--bhy-ink)`. Fixed by making all four follow info: ink for text, hue
carried by the 4px left border and the background tint. Now 9.86–13.56:1 in
both themes, verified in real wp-admin, with the hue signal intact.

Page-level previews never showed this, because seeing all four variants beside
each other is what made it obvious. That is the argument for this existing.
