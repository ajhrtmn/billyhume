# Page Builder Rebuild — Reassessment & Plan

## 0. Addendum (post-review, same day) — decisions made and what's actually built

AJ reviewed this plan and made the calls this doc left open, plus added two constraints not in the original draft:

- **Structured, not freeform.** "Cant easily break the design" — confirmed the `@wordpress/block-editor` direction over GrapesJS.
- **Patterns: use the built-in one.** No custom reuse mechanism needed.
- **No JSX, no build step.** `assets/js/component-studio.js` is plain `wp.element.createElement` calls, hand-written, exactly as shipped to the browser.
- **Portability matters.** "I dont want to be dependent on WP propriety shit... it really needs really good abstractions so we can eventually migrate away from everything WP dependent." This is now a hard rule, not a nice-to-have: every Component's actual authored content (its HTML/CSS/JS/data-bindings) is plain, WordPress-agnostic data — no WP-specific syntax inside it. WordPress-specific mechanics (the block-editor authoring UI, `post_content`'s block-comment serialization, the auto-generated REST routes) are confined to the *authoring shell* and never appear in the public render path — a site visitor's browser never loads any WP admin package. If a future change ever makes a Component's stored content only make sense inside WordPress, that's the thing to push back on hardest.
- **Real composition, not copy/paste.** Live catch during the build: a draft "Contest Page" Component was about to duplicate the Now-Playing Bar's markup inline instead of reusing it — the OLD system's exact mistake in new clothes. Fixed with a second block type, `bh/component-ref`, which embeds another Component's live content *by reference*. Independent Components now coordinate only through plain `CustomEvent`s on `document` (e.g. `bhcb:category`, `bhcb:play`) — no direct references between them.

**What's actually built and seeded** (`includes/class-component-studio.php`, `assets/js/component-studio.js`, `assets/css/component-studio.css` — entirely new/additive, nothing existing touched): a real `bh_component` post type (draft/publish + full revision history for free), one flexible `bh/custom-block` type (HTML/CSS/JS authored entirely in its own Inspector panel, data bindings reusing `BH_Element_Data::resolve()` verbatim), and `bh/component-ref` for composition. Seeded content includes the original two example Components plus a genuine smoke test: bh-contest's real now-playing bar, header, category tabs, and track list, rebuilt as four independent, interactive Components and composed into one "Contest Page" — a faithful, working reproduction of a real, currently-shipping piece of this ecosystem's UI, not toy data. Full technical detail is in `own-ur-shit.php`'s own 3.4.76 changelog entry.

**Honest limits:** not live-verified (no browser in this environment) — needs a real click-through. The now-playing bar's playback is a simulated demo timer, not wired to a real `<audio>` element or BHPlayer's actual queue/track-loading logic. Login/submit modals were left out of this pass.

## 1. Why it feels disjointed (the honest diagnosis)

Three separate content/authoring systems have accumulated in this plugin, not one:

1. **BH_Element** — placements on registered Surfaces/Slots, a hand-rolled parent/child tree (`parent_placement_id`), stored in a custom table (`bhcore_element_placements`). This is what the Design Suite's "Structure" tab edits.
2. **BH_Element_Prefab / BH_Element_State** — "Components" (reusable, linked-or-detached) and "fixture states" (Storybook-style test contexts), both bolted on top of #1's custom table/tree, with their own hand-rolled REST routes and their own custom table (`bhcore_element_prefabs`, `bhcore_element_states`).
3. **BH_Content + BH_Studio** — a *second*, entirely separate document model (a real block tree, stored in `bhcore_content`), authored through an actual `@wordpress/block-editor` canvas (`assets/js/studio.js`). This already exists in the codebase and is opened as a **modal on top of** the Design Suite when a placement needs "nested content" — two different editors, bridged by a popup.

All of the authoring UI for #1 and #2 — the rail, the tree, the inspector, the breadcrumb, the selection state machine — is **hand-rolled vanilla JS** (`element-builder.js`, 3,393 lines) doing its own imperative DOM re-rendering (`renderCanvas()`/`renderInspector()` called by hand after every mutation, a manually-maintained `state.selection` union type, a manually-maintained dirty-key tracker). Every bug from this session — the DOMContentLoaded race, the doubled REST path, the string/number id-comparison bug — came from that hand-rolled layer, not from #3. That's not a coincidence: hand-written imperative UI state management is exactly the kind of thing that accumulates this class of bug as features stack up, and it's why every fix has felt like whack-a-mole instead of narrowing the problem down.

Two consequences follow directly:
- **The custom tables have no draft/publish or version history.** `bhcore_element_placements`, `bhcore_element_prefabs`, `bhcore_content` are bare rows — no `post_status`, no revisions. Adding those means either reinventing WordPress's own draft/publish/revision system by hand, or moving onto WordPress posts, which already have it.
- **There are effectively two competing "block editor" investments in this codebase already** (#1+#2 hand-rolled, #3 real). One of them (#3) is the well-laid foundation you asked me not to reinvent. It's just underused.

## 2. The recommendation: converge on WordPress's own stack, not a third system

WordPress's block editor (`@wordpress/block-editor`, `wp-element`, `wp-components`, `wp-data`) ships inside WordPress core, on every install, for free — no build step required to use it (though a small one, via `@wordpress/scripts`, makes authoring new block types nicer). `class-studio.php`'s own docblock already made this exact case when it was written: no vendored library, no network dependency, the *same* toolkit WordPress's own Site Editor and every serious modern page builder (including newer Elementor internals) is converging toward.

Concretely, here's what maps onto what:

| Current hand-rolled piece | Native WordPress equivalent | What you get for free |
|---|---|---|
| Surfaces/Slots/Placements tree (`BH_Element`) | A real block-editor canvas per slot, backed by a WP post (custom post type) instead of a bare table row | Draft/publish (`post_status`), full revision history, autosave, REST auto-generated (`show_in_rest`) |
| "Components" — linked vs. detached instances, leaf-value overrides (`BH_Element_Prefab`, Phase 4) | **Synced Patterns** (`wp_block` post type) — sync = "linked," un-sync = "detach," and modern WP even has **Pattern Overrides** for exactly "same component, per-instance leaf edits" | This is *already built and already ships in WordPress*. Phase 4 of the current plan reimplemented, by hand, a feature core WordPress already has. |
| Fixture states (`BH_Element_State`, Storybook-style) | No exact native equivalent — genuinely this ecosystem's own idea | Worth keeping, but as a small, optional layer (see §5), not a first-class always-visible concept |
| Hand-written REST routes (`ous/v1/elements/*`) | WP's auto-generated REST routes for any `show_in_rest` post type | No more hand-registering `register_rest_route()` calls — this class of bug (today's doubled-path 404) mostly stops being possible |
| The rail/canvas/inspector shell (`element-builder.js`) | `@wordpress/block-editor`'s own `BlockEditorProvider` + `wp-components` panels | This *is* what gives you the "Unity Inspector" density and Storybook-style tree for free — it's the same UI language as the Site Editor's own List View + block Inspector, which is already a clean, dense, professional reference implementation, not something to hand-style from scratch |

This is the "don't reinvent well-laid foundations" answer: the foundation already exists, partially adopted (`BH_Studio`), and the fastest way to a *simple* result is finishing that adoption and retiring the hand-rolled half, not building a fourth system.

## 3. What's genuinely still yours to build

Being honest about the gap: WordPress's block editor thinks in terms of "one post's content." It does not natively know about *this ecosystem's* concept of a Surface (a CRM profile page, a contest page, a courses catalog) with named Slots that need independently-assigned, independently-published content. That cross-surface slot-assignment layer is real, valuable, custom work — it's the actual product idea here, and it's worth keeping. The fix is to make it a **thin registration/assignment layer** on top of native posts (e.g., "Slot X on Surface Y is backed by post #123"), not a parallel storage-and-rendering engine that reimplements what WordPress already does.

## 4. On competing with Elementor/Wix

Full parity — pixel-precise absolute positioning, an animation timeline, a marketplace of hundreds of templates — is a multi-year, dedicated-team undertaking, and I don't think it's the right target anyway given "I like simple things."

But you don't need that to compete. WordPress's own Full Site Editing (patterns, templates, block-based theming, native draft/publish/revisions) is *already* positioned as the native, structured alternative to Elementor/Wix — plenty of real businesses have moved off Elementor onto exactly this stack because it's faster, doesn't bloat the page with a page-builder's own CSS/JS payload, and doesn't lock content into a proprietary shortcode format. That's a legitimate, defensible competitive position: **structured, fast, maintainable, Storybook-familiar component library — not "unlimited freeform canvas."** It also happens to be the one that gets you draft/publish and version history for free instead of as a separate build item.

## 5. Simplified mental model

Right now a user has to hold: Surfaces, Slots, Placements, Components (linked/detached), Overrides, Fixture States, and a separate Content Studio modal — seven concepts before they've placed a single button. Proposed collapse, to two primary concepts plus one advanced one:

- **Pages** (was "Surfaces/Slots") — where content lives. One real post-backed block editor per page/slot, opened directly, no modal hop.
- **Components** (was "Prefabs/Library") — reusable pieces, authored once, dropped in anywhere. Native synced Patterns; "detach to customize" is a single built-in button, not a bespoke override system.
- *(Advanced, collapsed by default)* **Preview states** — the fixture-state idea, for anyone who wants to see a component with sample data before publishing. Not shown until asked for.

Draft/Publish and version history are just... there, as standard WordPress editor chrome, because the content is a real post. No separate "Save all changes" / "Publish" / "Done editing" banner stack to design.

## 6. The LMS tie-in

`class-studio.php`'s own docblock already anticipated this: once bh-courses' lesson-step editor needs a real visual editor, it registers its own block types (`bhc/text`, `bhc/video`, `bhc/quiz`, etc.) against the *same* canvas, the same way `bh/container`/`bh/heading` do today. One editor, two consumers, for free. This is the strongest practical argument for doing this convergence now rather than later — build the LMS's lesson editor on the hand-rolled system and you've tripled the surface area of the exact thing that's been breaking all session; build it on the converged system and it's a block-type registration, not a new editor.

## 7. Phased plan (no big-bang rewrite)

1. **Components first.** Migrate `BH_Element_Prefab` off its custom table onto native synced Patterns. Smallest surface area, immediately proves the model, immediately gets you real draft/publish + revisions on the thing you called out by name (linked instances). Existing renders keep working during the swap.
2. **One real Surface, end to end.** Pick one live surface (contest page is the best-understood one from this session) and back its slots with real posts + the block editor, side by side with the old system. Prove the pattern before touching anything else.
3. **Roll the rest of the surfaces over**, one at a time, retiring `element-builder.js`'s hand-rolled canvas/rail/inspector as each surface migrates off it. `BH_Element`'s placement table and REST routes get deleted once nothing depends on them anymore, not before.
4. **bh-courses lesson-step editor** onto the same canvas, per §6.
5. **Preview states**, simplified, as the optional advanced layer, once the core is stable and simple.

Each phase leaves the site fully working — nothing goes down while the next phase is built.

## 7.5. On GrapesJS specifically ("battle-tested, not load-bearing on us")

Worth surfacing before you decide, since it's easy to lose track of across a long session: **this exact question was already asked and answered once in this codebase.** `class-studio.php`'s own docblock records that GrapesJS was evaluated and dropped, for two concrete reasons — your own prior hands-on experience customizing it was "funky," and this ecosystem's stated convention against vendoring third-party runtime dependencies. That's not me being cautious in the abstract; that's your own past verdict on it, already on file.

On "battle-tested and not load-bearing on us" as the actual criterion (which I think is the right criterion): `@wordpress/block-editor` is arguably the *more* battle-tested option of the two. It runs the Site Editor and post editor on every WordPress install — tens of millions of sites, maintained by a large team, security-patched as core WordPress, zero vendoring (it ships in WP core, so there's no separate library version to fall behind on or patch yourself). GrapesJS is a smaller open-source project, maintained by a much smaller team, that you'd vendor and own the upgrade path for yourself — closer to "load-bearing on us" than the native option, not further from it.

Where GrapesJS genuinely wins: pixel-precise freeform layout and a more visual, direct-manipulation feel closer to actual Wix/Elementor (drag anything anywhere, absolute positioning). If that specific freeform feel is what you actually want — closer to Wix's canvas than to the Site Editor's structured block canvas — that's a real, legitimate reason to pick it despite the above, and worth saying explicitly rather than me assuming "simple" means "structured." Worth confirming which you actually want before I commit to a direction.

On reinventing the core data model: agreed, and §3 above already points at the one piece that's genuinely worth custom-building (the Surface/Slot cross-site assignment layer) — everything else in the current three-system pile is a candidate for deletion in favor of native storage, not for a new custom model. I'd treat "streamline the data model" and "pick the editor library" as the same decision, not two separate ones: whichever canvas we pick, the data it saves into should be as close to a plain WordPress post as possible, specifically so it's not something only this plugin's code can read, migrate, or recover.

## 8. Open questions before I start

- **The big one:** do you want the structured, block-based feel (Site Editor/Gutenberg-style — snap-to-layout, consistent spacing, "can't easily break the design") or a freeform, drag-anything-anywhere canvas closer to actual Wix/Elementor? This decides between `@wordpress/block-editor` and something like GrapesJS more than any other factor, and it's a real product decision, not just a technical one — I don't want to assume "simple" means "structured" on your behalf.
- Does "Components" living as native WordPress **Patterns** (visible in the normal WP Patterns admin screen, not just inside this custom Design Suite) feel right to you, or do you want Components to stay inside this plugin's own dedicated UI, just rebuilt on the same underlying tech?
- Any objection to a small `@wordpress/scripts` build step (one `npm run build` before deploy) in exchange for writing new block types in JSX instead of raw `wp.element.createElement` calls? Zero runtime dependency either way — this is purely a dev-time ergonomics question.
- Should I start with Phase 1 (Components → Patterns) as the first concrete piece of work, or do you want to see it prototyped smaller/faster first before committing to the full phased plan?
