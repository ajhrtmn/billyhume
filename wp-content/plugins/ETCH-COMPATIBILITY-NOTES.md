# Etch Compatibility Notes

Researched 2026-07-15, prompted by AJ's question: Billy's main dev is going to build the site in [Etch](https://etchwp.com) (a WordPress visual development environment). AJ wanted to know whether this ecosystem should adapt to accommodate Etch specifically, or try to accommodate every WordPress page-builder paradigm generically via some kind of interface contract.

**Short answer: no new work needed. Etch compatibility is already substantially satisfied as a side effect of two decisions already made for unrelated reasons** — this session's WYSIWYG shortcode-to-block conversion pass (`ROADMAP-ux-polish-and-feature-parity-2026-07.md` item 5a), and this ecosystem's long-standing convention of storing plugin data as ordinary WordPress post meta rather than a proprietary format.

Sources, all read directly from Etch's own docs site (not inferred from marketing copy):
- https://docs.etchwp.com/gutenberg/block-authoring
- https://docs.etchwp.com/gutenberg/passthrough
- https://docs.etchwp.com/integrations
- https://docs.etchwp.com/public-api

---

## What Etch actually is, architecturally

Etch is **not** a proprietary-format page builder in the Elementor/Beaver Builder/Bricks/Divi sense. Its stated core architecture: "Etch authors everything you build to custom Gutenberg blocks and maintains a bi-directional sync between Etch and Gutenberg... all content is stored in WordPress for portability and longevity." The block editor (Gutenberg) is Etch's own storage/sync layer, not a separate thing Etch merely exports to.

This matters because it means Etch's relationship to the *real* WordPress block registry (`register_block_type()`, the same core API this ecosystem's own blocks use) is load-bearing, not incidental.

## The mechanism that makes this ecosystem's blocks work in Etch: Passthrough Blocks

Direct quote, https://docs.etchwp.com/gutenberg/passthrough:

> "Etch is architected to work with any and all third party blocks, from checkout systems, to forms, to facets... When Etch detects a block that's not a core Gutenberg block, it essentially 'passes it through' to the front-end without attempting to parse it. And in Etch, you'll simply see a placeholder element. This allows you to use these blocks without breaking their functionality."

Concretely, for every block already built this session — `bhm/buy`, `bhm/tip-jar`, `bhm/tiers` (bh-monetization-woo), `bh/contest-player`, `bh/results-reveal`, `bh/archive` (bh-contest), `bhs/player` (bh-streaming), `bhc/catalog`, `bhc/course` (bh-courses) — this means:

- **The real, published front-end page**: works exactly as it does anywhere else in WordPress. `render_callback` fires, `ServerSideRender`'s output is what a visitor sees, full functionality (voting, purchasing, playback, etc.) — completely unaffected by Etch being involved anywhere in the page's construction.
- **Inside Etch's own visual canvas, while Billy's dev is building**: the block shows as a generic placeholder box, not a live visual preview of what it actually looks like. This is the one real, current gap — cosmetic only, not functional.

Etch's own docs acknowledge this gap is intentional-for-now, not a bug: "In the future, plugin developers can integrate directly with Etch to map the functionality into custom elements in Etch." That API does not exist publicly yet (Etch's own "Auto Block Authoring" doc calls it "Phase 2" of a still-unfinished refactor). **There is nothing to build against this today** — it's not a gap this ecosystem's own code can close from its side; it requires a future Etch-side extension point that hasn't shipped.

## The second, independent compatibility surface: dynamic data binding

https://docs.etchwp.com/integrations: Etch can bind directly to any WordPress post meta field via a `this.meta` namespace inside its own dynamic-data UI (with ACF-specific structured support via `this.acf` for plugins that use that specific format).

Since this ecosystem already stores nearly everything as ordinary post meta (`_bhm_purchase_price_cents`, `_bh_start`, `_bhc_certificate_enabled`, etc. — this is the established, ecosystem-wide convention documented throughout every plugin's own activator/admin classes), **Billy's dev can build fully custom Etch-native layouts that pull live data straight from this ecosystem's plugins, with zero additional work required from this codebase.** This works independent of whether the dev uses this ecosystem's registered blocks at all — they could, for example, hand-build a custom "course price" display in Etch bound directly to `_bhm_purchase_price_cents`, without ever touching the `bhm/buy` block.

**One real limitation to keep in mind for future features** (not a problem today, nothing to fix): Etch's meta binding reads flat/scalar values cleanly. It does not unpack a JSON blob stored in a single meta value the way it special-cases ACF's own structured format. A few places in this ecosystem already store structured data this way (e.g. tier benefit lists as a JSON array in one meta key) — that data works fine everywhere else in the plugin, but wouldn't bind usefully inside Etch's own dynamic-data UI as-is. **Recommendation for future features, not a retrofit ask:** if a value is the kind of thing an artist/dev would plausibly want to drop into a custom Etch layout (a price, a name, a date, a count), consider also mirroring it into its own flat meta key alongside whatever structured storage the feature would use anyway. Cheap insurance, not urgent.

## What Etch's Public API is (and why it's not relevant here)

https://docs.etchwp.com/public-api documents `window.etch` — a browser-side, experimental (v0.x) TypeScript API, distributed as an MIT-licensed npm package (`@digital-gravy/etch-public-api`), for scripts to read/mutate the **document Etch's builder is actively editing** (blocks, styles, loops, components, custom fields, undo/redo) while someone is using the builder. This is a tool for building companion browser tooling/extensions that script the *builder UI itself* — not a server-side registration point for a plugin's content types, and not something this ecosystem has any reason to integrate with. Documented here only so it isn't mistaken for the "map to custom elements" extension point discussed above — it is a different thing.

---

## Addendum 2026-08-30 — a real, confirmed incompatibility: Etch blanks `class` on `the_content`-injected markup

The research above holds for **blocks** (passthrough) and **meta binding**. It missed a third surface, found live on `billyhume.net`:

**Etch's front-end hydration strips the `class` attribute (`class=""`) off DOM nodes a plugin appends to `the_content` via a filter.** It keeps `data-*` and `tabindex`, drops classes. One-shot on page load — not a live `MutationObserver`. The server HTML is correct (verified by comparing the raw response to the rendered DOM); only the hydrated DOM is affected.

Hit with `bh-courses` lesson step wrappers: `BHC_Render_Lesson` emits
`<div class="bhc-step bhc-step-video bhc-step-done" data-step-index="…">`, the live DOM showed `<div class="" data-step-index="…">`. `courses.js` keyed its step show/hide and feature wiring on `.bhc-step`, so the step container went invisible after a bulk-hide and the chapter list / watch-% never attached.

### Why filter priority doesn't fix it

Moving the `the_content` append to `PHP_INT_MAX` (after Etch's own filter) did **not** help — Etch hydrates client-side from the final DOM regardless of PHP filter order.

### The mitigation pattern (bh-courses 0.16.19)

For any wrapper this ecosystem injects into `the_content` and then relies on by class:

1. **Emit `data-*` mirrors** of anything the class encodes (`data-step-type`, `data-step-done`).
2. **Rebuild the class in JS on init** from those attributes, and **re-assert via a short (~5s) `MutationObserver`** — the strip's timing vs. your own script isn't guaranteed.
3. **Add a CSS fallback** that also matches the `[data-*]` attribute selector, so the element is styled in the gap before JS runs and if JS fails entirely.

### Scope

This only affects markup added through `the_content` (or a similar late content filter). It does **not** affect:
- Registered blocks (passthrough renders their `render_callback` output untouched).
- Anything rendered by a shortcode *inside* a block Etch owns — still passthrough.
- Admin screens.

If another peer plugin starts appending class-dependent markup to `the_content` on this site, expect the same and apply the same three-part mitigation. Tracked as a project memory (`project_etch_strips_classes`).

---

## Bottom line / recommendation

1. **Don't build a page-builder-agnostic "interface contract" layer.** Elementor/Beaver Builder/Bricks/Divi each have genuinely incompatible proprietary widget systems — a real adapter for all of them would be unbounded scope for speculative value. The two integration surfaces this ecosystem already has — shortcodes (near-universal fallback; most builders have a raw-shortcode/raw-HTML widget) and real Gutenberg blocks (core WP +, per the research above, Etch specifically) — already cover the paradigms that matter for this project.
2. **Keep building new front-end features as real blocks (`register_block_type()` + `ServerSideRender`) from day one**, the same pattern established this session — not shortcodes to be converted later. This was already the plan for the newly-roadmapped ticketing items; this research just confirms the reasoning.
3. **No retrofit work needed on anything already shipped.** Every block built this session already passes through Etch correctly today, by construction.
4. **One cheap, concrete validation step, whenever convenient (not blocking anything):** have Billy's dev actually drop one existing block (e.g. `bhm/buy`) into a real Etch-built page and confirm the passthrough behavior matches documentation in practice. Docs and reality don't always perfectly agree; this is a five-minute check that would fully close the loop.
