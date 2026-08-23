# UX audit harness

Measured, repeatable version of the audit described in
`wp-content/plugins/UX-AUDIT-PLAN.md`. Replaces the throwaway browser
harness that was hand-rolled (twice) before this existed.

## Run

```bash
export WP_ADMIN_USER=... WP_ADMIN_PASS=...   # never commit these
npm run audit:ux            # headless, prints per-width findings
npm run audit:ux:ui         # interactive, for working a screen to zero
```

Without credentials the admin specs skip cleanly rather than fail.

## What it measures

Per screen × 6 widths (1440/1280/1024/961/782/375) × both themes:
contrast (AA), clipping (`scrollHeight` vs `clientHeight`), page-level
horizontal overflow, and <44px touch targets at ≤782.

## Two things it deliberately gets right

- **Reloads per theme** rather than toggling `data-shsas-theme`. Toggling
  and re-reading in the same task does not re-resolve `var()` references
  and once produced 39 contrast failures that did not exist.
- **Composites backgrounds** up the ancestor chain, with `body` as the
  base — `:root` is transparent in this skin, so a white base invents
  failures on every dark screen.

Exclusions (each earned by a real false positive): `.screen-reader-text`,
elements clipped to ~1px, Query Monitor's chrome, `text-overflow:
ellipsis` truncation, and `text-indent: 100%` (WP core's own icon-only
mechanism at ≤782).

## Adding a screen

Append to `SCREENS` in `admin.spec.ts`. Set `expectClean: true` once a
screen has actually been brought to zero, so it stays there.
