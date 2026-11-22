# Etch compatibility

The live site's pages are built in [Etch](https://etchwp.com), a visual builder that stores everything as Gutenberg blocks.

## What works without extra effort

- **Our blocks** (`bhc/catalog`, `bhc/course`, `bhc/course-landing`, `bh/contest-player`, `bh/results-reveal`, `bh/archive`, `bhm/buy`, `bhm/tip-jar`, `bhm/tiers`) are "passthrough" blocks in Etch: they render normally on the published page. Inside the Etch canvas they show as a placeholder box; that's an Etch limitation with nothing to build against yet.
- **Post meta** (prices, dates, flags) can be bound directly in Etch's dynamic data (`this.meta`). Flat values bind well; JSON stored in one meta key does not. If a new value is something Billy would drop into a layout (a price, a name, a date), also store it as its own flat meta key.

## The one real incompatibility: Etch blanks `class` on `the_content` markup

On page load, Etch strips the `class` attribute (leaves `class=""`) from markup a plugin adds to `the_content` through a filter. It keeps `data-*` attributes. Filter priority doesn't help; it happens client-side. Lesson step wrappers in bh-courses were the case that broke.

For any wrapper added through `the_content` that code relies on by class:

1. Mirror what the class encodes into `data-*` attributes (`data-step-type`, `data-step-done`).
2. Rebuild the class in JS on load, and re-assert it with a short `MutationObserver` (about 5 seconds).
3. Add CSS that also matches the `[data-*]` selector, so it's styled before and without JS.

This doesn't affect registered blocks, shortcodes inside blocks, or admin screens.

## Live theme CSS (Automatic.css)

The live site also runs Automatic.css, which gives every top-level `<section>` page-section padding and flex layout. Plugins use `<div>` for boxes inside content, not `<section>`.
