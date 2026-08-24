# Skinning third-party plugins

**Written 2026-08-24, from a measured diagnosis rather than a design opinion.**

Companions: `STYLE-SYSTEM.md` (our four layers), `DESIGN-CRAFT.md` (what "good" means here), `UX-AUDIT-PLAN.md` (how to measure).

## The problem, measured

On WooCommerce Analytics, WooCommerce text computed `rgb(30,30,30)` against a background of `rgb(22,20,15)` — **1.1:1**. Neither value is wrong on its own:

- WooCommerce paints **no background** on that component. It expects to inherit WordPress's white admin canvas.
- This skin repaints that canvas dark, on `body.wp-admin, #wpcontent, #wpbody-content`.

Their hardcoded dark text then lands on our dark ground. **That mechanism is not WooCommerce-specific.** It hits any plugin whose CSS was authored against WordPress's own light admin — which is essentially all of them, unless they shipped a dark mode.

The same session found the second half of the problem: `admin-skin.css` already carried ~33 rules dark-skinning WooCommerce's React admin (`.woocommerce-*`, `.components-*`). That was a **partial adoption** — enough to override WooCommerce's colors, not enough to replace them. Measured, those rules were the entire remaining failure set after the canvas was fixed: our cream `--shsas-text` stranded on a light canvas at 1.08:1.

**Half-adoption is the worst of the three states.** Unskinned is legible. Fully skinned is legible. Half-skinned is not.

## The three states, and how we choose

| State | Legible? | Cost | When |
|---|---|---|---|
| **Contained** (default) | Yes | Zero, permanent | Every plugin we have not explicitly adopted |
| **Adopted** | Yes, and on-brand | High, ongoing | A plugin we use daily and have measured end to end |
| **Half-adopted** | **No** | Worst of both | Never. This is the bug. |

## How containment works

`shsas_screen_is_owned()` (in `self-hosted-self-admin-skin.php`) classifies every admin screen and `admin_body_class` stamps `shsas-owned` or `shsas-unowned` on the body.

- **Owned**: WordPress core screens (deliberately themed) and our own ecosystem pages, matched on the prefixes `ous`, `bh-`, `bh_`, `bhs-`, `bhl-`, `bhv-`, `bhf-`, `bhc-`, `bhm-`, `bhr-`.
- **Unowned**: any other plugin's page.

On unowned screens the `THIRD-PARTY CONTAINMENT` block at the end of `admin-skin.css` restores WordPress core's own admin values inside `#wpbody-content` — canvas `#f0f0f1`, surfaces `#fff`, text `#1d2327`, links `#2271b1`.

Two properties of that block matter:

1. **Scope is `#wpbody-content` only.** The admin bar and admin menu stay fully themed. *Our chrome, their content* — that boundary is the whole idea, and it is why the admin still feels like ours.
2. **It wins on specificity, not enumeration.** Every partial-adoption rule is class-only, so one `#wpbody-content`-scoped rule outranks all of them at once — including any similar rule added later. There is no list to maintain.

`!important` is used deliberately there: the canvas rule it overrides is itself `!important`, so equal specificity and later source order are not enough.

### Measured result

WooCommerce Analytics: **22 contrast failures → 0.** WooCommerce Customers: **0**, no horizontal overflow. Our own dashboard: still `shsas-owned`, canvas still `rgb(22,20,15)`, **0 failures** — no regression.

### Known trade-off

The containment block clears background colors on `[class*="components-"]` / `[class*="woocommerce-"]`, which also clears genuinely meaningful fills (status badges, highlight rows). Legibility beats decoration on a screen we have explicitly chosen not to own. Getting both back is exactly what adoption is for.

## Adopting a plugin properly

Opt-in, one plugin at a time, and **only if it is finished**. The filter:

```php
add_filter('shsas_owned_screen', function ($owned, $screen_id, $screen) {
    return str_contains($screen_id, '_page_wc-admin') ? true : $owned;
}, 10, 3);
```

Marking a screen owned turns containment **off** for it. Do that only when all of the following hold:

1. Every surface the plugin renders has an explicit background from our tokens — never inherited.
2. Every text color is ours, not the plugin's, on every one of those surfaces.
3. Measured: **zero contrast failures** at 1440/1280/1024/961/782/375, in both themes, using the method in `UX-AUDIT-PLAN.md` (reload per theme, never toggle).
4. Empty, loading, and error states all checked — WooCommerce's React widgets report wrong colors mid-hydration and settle a frame later, which is a documented false-positive source. Measure after settle.
5. The adoption lives in its own file (`assets/css/adopt-<plugin>.css`), not scattered through `admin-skin.css`. That is what makes it revertible when the plugin ships a redesign.

**Assume every adoption breaks on a major version of its plugin.** That is the ongoing cost, and the reason containment — not adoption — is the default.

## Storybook

The open idea (AJ, 2026-08-24) is to pull third-party components into Storybook so an adoption layer is visually regression-testable rather than re-verified by hand each time.

The tractable version, given `tools/gen-storybook-fixtures.php` already exists: capture the **rendered markup** of the components an adoption targets — a `.components-card`, a `.woocommerce-summary__item`, a `wp-list-table` row — as static HTML fixtures, and render them in Storybook under both our adoption CSS and the plugin's own CSS. That turns "did the Woo update break our skin?" into a diff.

What this does *not* need is importing the plugin's build. These are static HTML + CSS fixtures, which keeps the deploy test intact: **the committed artifact still runs with nothing but PHP and WordPress.**

Not built. Sequenced behind finishing at least one real adoption, since fixtures for an adoption that does not exist would test nothing.
