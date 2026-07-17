# The Library / Structure hybrid — a two-tab component-library + live-site builder on the existing `BH_Element` engine

A design pass for the rebuild AJ asked for directly: the current Design Suite builder — one unified node tree (Site → surfaces → slots → placements, Godot-scene-tree style) with a side inspector — "feels clunky and wrong" even though "the underlying elements are right." The target is a hybrid of two metaphors: **Godot's live scene-tree** (which exists today) and **Storybook's component library** (a categorized sidebar of components, a Controls panel, and per-component named *states*/variants browsed in isolation against mock data). The concrete ask is two tabs — a **Library** (build/edit/combine elements against fixture data, decoupled from the live DB) and a **Structure** (the real site tree of live instances) — with data/action binding working *identically* in both.

This doc is grounded in the code that actually ships, cited by file and class, in the same call-site-first, honest-about-gaps spirit as `ELEMENT-BUILDER-DESIGN-PLAN.md` and `DESIGN-SUITE-UNIFICATION-PLAN.md`. It is the direct successor to those two: they built and then promoted the `BH_Element` engine and its three-pane builder; this doc reorganizes that builder's *information architecture* around AJ's two-tab hybrid, decides what of the engine survives, and specifies the two genuinely-new concepts the hybrid needs (fixture states, and a linked-component instance model) that do not exist today.

Standing caveat, same as every sibling doc: reasoned and contract-checked against the actually-read shapes of `BH_Element`, `BH_Element_Data`, `BH_Element_Prefab`, `BHY_Style`/`BHY_Gallery`, `BH_Content`, the `bhcore_element_placements`/`bhcore_element_prefabs` tables, and `element-builder.js` — but no live PHP/MySQL/WordPress/browser execution was available this pass. Nothing below is claimed as smoke-tested.

---

## 0. The findings that frame the design

Five facts from reading the code determine almost everything that follows.

1. **The "clunky and wrong" feeling has a specific, nameable cause: the current single tree conflates two fundamentally different concerns into one outline.** `element-builder.js`'s tree (post-3.4.37) is `Site → Surface → Slot → live placements`, and its "Library" is *only* a contextual add-child popup powered by `GET .../elements/types`. There is no place to *build and refine a reusable component in isolation* — everything is authored in-situ on a live surface, and the only reusable artifact (a prefab) can only be created *from* an already-placed live subtree, then re-inserted. AJ's instinct is right: a component **library** (things you design once, in the abstract, against representative data) and a site **structure** (where those things actually live, wired to real data) are two different mental models being forced through one tree. The fix is to *separate* them — which the data model already almost supports.

2. **The `BH_Element_Prefab` system is already 70% of "the Library."** `class-element-prefab.php` is a named, reusable, saved composition of one-or-more placements, stored *separately* from live placements (`bhcore_element_prefabs`, deliberately not a flag on `bhcore_element_placements`), deep-copyable, full-subtree-capable (`save_from_node()`/`instantiate()`'s `parent_ref` id-remap), and — critically — already renderable with **zero DB writes** via `render_definition()` (added 3.4.47 for the Gutenberg block, using negative in-memory ids). A prefab is *exactly* a Library component minus two things: named fixture states, and a live instance link. The Library tab is not a new engine; it is a first-class home and authoring surface for a system that already exists but is currently reachable only through a right-click on a live node.

3. **The binding engine has a single, clean resolution choke point that makes "binding works in the Library against fixtures" a ~10-line change, not a parallel system.** Every bound attribute on every placement flows through `BH_Element_Data::resolve($attr_value, $ctx, $default)` (`class-element-data.php:287`). It already substitutes `context.*` tokens out of a caller-supplied `$ctx` array and already has a documented never-fatal fallback ladder. "Bind to the real vote count while you're still in the Library" resolves cleanly: the *binding descriptor is identical* in both tabs (`{"bind":{"source":"bh_contest.vote_count",...}}`); only the **render context differs** — a fixture map in the Library, a real per-viewer context in Structure. This is the load-bearing realization that makes "binding ubiquitous across both tabs" achievable without duplicating a single resolver.

4. **Structure already exists and works — it is today's tree, essentially unchanged.** `bhcore_element_placements` + `parent_placement_id` tree + `render_slot()`/`render_placement()` + the `bh_element_surfaces` self-registration (`bh-crm`, `bh-contest`, `bh-courses`, portal, dashboard) + the `ous/v1/elements/placements/*` REST bridge are the live-site structure and need almost no change. The "Structure tab" is a rename and re-scoping of the tree that ships today, not new construction.

5. **Two new concepts genuinely do not exist and must be built: fixture states, and a live component-instance link.** `register_type()` has *no* variant/state concept — this is explicitly flagged as an unbuilt seam in `DESIGN-SUITE-UNIFICATION-PLAN.md`'s 3.4.32 status note ("a real implementation needs `register_type()` to gain an actual `variants`/`states` key server-side"). And `BH_Element_Prefab::instantiate()` is deliberately **copy-on-place with no back-reference** (`class-element-prefab.php:260-303` documents why). AJ's "button prefab used in many places" case wants the opposite — edit-once-update-everywhere — which requires a link the schema does not have today. These are the two real builds; everything else is reorganization.

**Net finding: this is a UI-shell rebuild plus two new engine capabilities, not a ground-up rewrite.** The `BH_Element`/`BH_Element_Data`/`BH_Element_Prefab` data model, the placement table, the surface/slot system, the REST bridge, the token pickers, and the shadow-DOM canvas all survive. AJ said he's willing to "cut down to the metal, rebuild from the ground up if necessary" — the honest judgment is that the metal (the data model) is sound and is the most valuable, hardest-won part of this whole effort; what's wrong is one level up, in how the tree presents that model. Rebuilding the engine would throw away the binding/capability/actions system that is the actual product, to re-derive it. The rebuild should be spent on the *shell's information architecture*, not the engine.

---

## 1. The core architectural decision — reuse the engine, rebuild the shell's IA

**Decision: keep the entire `BH_Element` data model. Re-architect the builder UI into two tabs that are two *modes of one editor*. Add exactly two new engine capabilities (fixture states; linked component instances) and one new sandbox surface. No table is dropped; no registry class is rewritten.**

Why "two modes of one editor" and not two editors: the hard constraint is that data/action binding must behave *identically* in Library and Structure. The only reliable way to guarantee that — rather than building two editors and forever fighting drift between them — is for both tabs to drive the *same* tree component, the *same* inspector, the *same* add-child picker, the *same* binding UI, and the *same* `resolve()` path. They differ in exactly three axes:

| axis | Structure tab | Library tab |
|---|---|---|
| **What the tree is rooted at** | a live surface/context (`bh_crm_profile`, ctx=person id), same as today | the `__library` sandbox surface, ctx = the component being edited |
| **Render context for `resolve()`** | real per-viewer `$ctx` (`user_id`, `contest_id`, …) | the active fixture **state**'s mock context + mock source values |
| **What "save/place" persists** | a live placement row, rendered to real visitors | a snapshot into a component **definition**, plus the ability to place *instances* of it into Structure |

Everything else — the Godot nesting, the inspector's Element/Style/Data sections, `.bhel-field-row` control rows, `custom_js`/`actions`, the shadow-DOM canvas — is *shared code*, not duplicated. This is the single most important structural decision in the doc: **Library and Structure are one editor pointed at two different roots with two different render contexts.**

### 1.1 Where plugin-contributed content fits (AJ's follow-up question)

Peer plugins contribute to the Library at **two levels**, and both already have a real mechanism:

- **Primitive types** — every plugin that calls `BH_Element::register_type()` today (core's own primitives, and anything a peer plugin registers) automatically becomes a Library **Primitive**, grouped by its existing `category`. Nothing new here — the Library's primitive list is just `types_for_surface()`'s existing output, presented in the new sidebar instead of only inside the add-child popup.
- **Pre-built Components** — this is worth stating explicitly since it wasn't fully spelled out above: a plugin can *also* ship a ready-made Component (not just raw primitives) the same way `bh-contest/includes/class-element-surface.php` registers data sources today — a small `register_default_components()` step, run once on activation/upgrade, that calls the same `BH_Element_Prefab::save_from_node()`-shaped insert a human uses when saving a Component from the Library UI. A concrete example: bh-contest could ship a "Results table" Component pre-built from a few primitives + a `bh_contest.vote_count`-style binding, so a site owner drags it in rather than building it from scratch. This is additive to the plan below (a `register_component()`-style hook, mirroring `register_type()`), not a structural change — flagged here as a real Phase-5-or-earlier candidate once Phase 1's authoring path is solid, since it reuses exactly the storage `save_from_node()` already produces.

So: plugins register **primitives** (code) that populate the Library's raw material, and — optionally — **pre-built Components** (data, same shape a human-authored Component produces) that populate the Library's ready-to-place catalog. Either way, what a Component actually places into Structure at the end is identical: a linked instance or a detached copy of the same `bhcore_element_prefabs` row shape, per §5 below.

### File-by-file: reused vs. changed vs. new

**Reused essentially as-is:**
- `own-ur-shit/includes/class-element.php` — the type registry, placement storage, `parent_placement_id` tree, `render_slot()`, `save_placement()`'s custom_js gate + tree invariants + container auto-context, `build_actions_js()`, `wrap_placement_html()`, `resolve_tag()`/`build_html_attrs()`. **Changed narrowly** (see §4/§5): `render_placement()` gains a fixture-context passthrough and a linked-instance merge branch; two small REST routes are added; a nullable `library_component_id` is threaded through `save_placement()`. `save_placement()`'s existing invariants, actions, and custom_js path are untouched.
- `own-ur-shit/includes/class-element-data.php` — the resolver, the never-fatal ladder, formatters, `context.*` substitution. **Changed narrowly:** `resolve()` gains a fixture-mode branch (§4.3). Every existing signature and failure rung is preserved; in Structure (no fixture context) behavior is byte-identical to today.
- `own-ur-shit/includes/class-element-prefab.php` — **this becomes the Library component store.** `save_from_node()`, `instantiate()`, `render_definition()` are reused directly. **Extended** with fixture-state storage (§4.2) and a linked-instance semantics option alongside the existing copy semantics (§5).
- The `bh_element_surfaces` registrants (`bh-crm/includes/class-people.php`, `bh-contest/includes/class-element-surface.php`, portal, dashboard, and the future `bh-courses` lesson surface) — **unchanged.** They keep declaring their live surfaces/slots exactly as they do now. Core adds *one* new registrant: the `__library` sandbox surface, plus the optional `register_component()` hook from §1.1.
- `own-ur-shit/assets/css/element-builder.css` — `.bhel-style-group-body > .bhel-field-row` (the 108px-label + control grid, `element-builder.css:382`) is the shipped "Controls" Name/Control row pattern; it carries straight into both the inspector's Data section and the Library's new Fixtures/State section. **Extended** with two-tab chrome + Library-browser + state-tab styles.
- `own-ur-shit/includes/class-style-gallery.php` — the Design Suite shell that reparents `.bhel-topbar`/`.bhel-canvas`/`.bhel-inspector`. **Extended** to host the two-tab switch; the reparenting mechanics are reused.

**Genuinely new (small, bounded):**
- Schema: a nullable `library_component_id BIGINT UNSIGNED DEFAULT 0` column on `bhcore_element_placements`, and a new `bhcore_element_states` table (or a `states` JSON column on `bhcore_element_prefabs`) — one `DB_VERSION` bump in `class-identity-activator.php` (next after 1.10), landing beside `bhcore_element_placements`/`bhcore_element_prefabs` exactly as prior tables did.
- One core registrant for the `__library` sandbox surface.
- `element-builder.js` — the largest single change, but additive: a Library/Structure tab switch, a Storybook-shaped Library browser, a fixture-state switcher, a Fixtures inspector section, and a "place linked vs. detached" affordance in the add-child picker. It reuses the existing tree-build, inspector, add-child popup, reorder, and `globalSave()` verbatim.

**Nothing is rewritten from scratch.** The rebuild is real (the shell's IA genuinely changes shape), but it is a *re-composition* of existing parts plus two new engine features — not a new engine.

---

## 2. The GrapesJS question — a real recommendation: **No.** Continue the thin custom layer.

AJ is explicitly open to reconsidering GrapesJS (rejected earlier in favor of the thin Gutenberg-layer-first approach) *if* it can be abstracted well enough for this specific two-tab fixture-state hybrid. Here is a real recommendation with the reasoning shown, not a hedge.

**What GrapesJS actually is:** a mature, MIT-licensed, framework-agnostic web-builder *toolkit* — a drag-drop canvas, a layer manager (tree), a style manager, a blocks panel, and a traits (attribute) panel, over its own **component model** that serializes to its own HTML/CSS/JSON. It can be loaded as a UMD bundle with no build step, so on the narrow question of "does it violate VISION.md's no-build/shared-hosting rule" it does *not* automatically fail. That is the strongest point in its favor, and it's genuine: GrapesJS would hand you a real direct-manipulation canvas (drag a node onto the page, click-select inside the render) that the current custom builder conspicuously lacks (it uses up/down buttons + a tree + a non-interactive preview).

**Why it is still the wrong choice here:**

1. **It reintroduces the exact "second, incompatible content model" the ecosystem already rejected once.** `LMS-AUTHORING-DESIGN-PLAN.md` and `ELEMENT-BUILDER-DESIGN-PLAN.md` both refused to build a second arbitrary-nesting tree because `BH_Content`/`BH_Element` already exist. GrapesJS *is* a third tree with its own component types, its own storage, and its own serialization. To keep placements, surfaces/slots, the `parent_placement_id` tree, prefabs, and the linked-instance model this doc proposes, you would be continuously translating GrapesJS's component graph ↔ `bhcore_element_placements` on every load and save. That adapter is not "customizing GrapesJS" — it is fighting GrapesJS's core assumptions at every seam, permanently.

2. **The crown-jewel capabilities live *outside* anything GrapesJS models, and they are the whole point.** The value of `BH_Element` is not the boxes — it is server-side data binding against ecosystem sources (`bhcore_events.count`, `bh_contest.vote_count`, CRM fields), resolved per-viewer at render time through `BH_Element_Data::resolve()`, plus the codeless `actions` system and the capability-gated `custom_js`. GrapesJS is a client-side HTML/CSS editor; it has no concept of a source resolved against a render context, no concept of per-surface placement capability (`types_for_surface()`), and no concept of codeless server-side actions. Every one of those would have to be bolted on as custom traits *and a parallel server render path* — meaning GrapesJS's own rendering (its entire reason to exist) gets bypassed for precisely the elements that matter most. You'd carry the weight of the framework while routing around it for the important cases.

3. **The two genuinely-new things this hybrid needs — fixture states and linked instances — are not GrapesJS features you'd inherit; they're things you'd build *inside* it against its grain.** Fixture-per-state binding resolution is specific to `BH_Element_Data`'s source model. The linked-instance/synced-component model would collide with GrapesJS's own "symbol" system (which has its own storage and its own override semantics), forcing a choice between GrapesJS symbols (that don't understand your bindings) or your own model (fighting GrapesJS's). Either way you build the hard part yourself and inherit an integration tax.

4. **The custom layer is already ~80% built and speaks `BH_Element` natively.** The three-pane shell, the tree, the inspector, the token pickers, the shadow-DOM canvas, the REST bridge, the contextual add-child picker, reorder, prefabs, and `render_definition()` all exist and all speak the real data model. The gap AJ describes (a Library/Structure split + fixture states) is reorganization + two features — not a missing engine that GrapesJS would supply.

**Recommendation:** **Do not adopt GrapesJS.** The one real thing it would give you — a polished drag-drop direct-manipulation canvas — is not worth coupling the ecosystem's most valuable, most self-hosting-aligned subsystem to an external framework's incompatible content/component model. If, after Phases 0–3 below, *canvas drag-drop* is judged a hard requirement (it is currently satisfied by tree + up/down reorder, deliberately, per the no-live-browser build constraints), the proportionate move is to import a *single small, self-contained* interaction primitive (e.g. an `interact.js`-class drag helper, one UMD file, no build) wired to the *existing* reorder/reparent REST calls — not the whole builder framework. That keeps the data model, the bindings, and the no-lock-in posture intact while adding the one interaction GrapesJS is genuinely good at. Flag: even that smaller step needs a live browser to build responsibly, which no pass has had yet.

---

## 3. The two tabs — conceptual model

### 3.1 Structure tab = today's live tree, re-scoped

The Structure tab is the site's real content: for each registered surface (dashboard, `bh_crm_profile`, portal panels, contest player zones, LMS lesson UI), the live `bhcore_element_placements` subtree rooted at the synthetic "Site" node, exactly as `element-builder.js` renders it today (`Site → Surface → Slot → placements`, per the 3.4.37 status note). Selecting a node shows its Element/Style/Data inspector; the canvas renders the live surface against a real preview context (`preview_ctx`, e.g. `bh-contest`'s "most recently published contest"). This is the Godot scene-graph AJ already has and likes — it stays.

The *only* change to Structure is where "add child" gets its choices: the add-child picker is now **powered by the Library** (§5.3) — it lists primitive element types *and* saved Library Components (both human-authored and plugin-shipped, per §1.1), and placing a Component offers linked-vs-detached.

### 3.2 Library tab = the component library, authored against fixtures

The Library tab is where you build, edit, and combine elements **decoupled from live data**. It is not a read-only catalog — it is the source you build from. It has three regions that map onto the Storybook screenshots AJ sent:

- **Left: a categorized component sidebar** — grouped by `category` (the same grouping `register_type()`/`renderPalette()` already produce: text / layout / media / data), listing both **Primitives** (code-registered types: `bh/heading`, `bh/button`, `bh/image`, `bh/container`, `bh/stat-card`, plugin-contributed types) and **Components** (saved compositions from `bhcore_element_prefabs`, whether human-authored or plugin-shipped). This is Storybook's left rail.
- **Center: the isolated canvas** — renders *one* selected Library item, alone, against the **currently-selected fixture state**. This is Storybook's "component in isolation" view (Default / Expanded / Added-to-cart). Uses the same shadow-DOM canvas as Structure, but in a single-component isolation mode rather than a full-surface mode.
- **Right: the inspector** — the same Element/Style/Data sections as Structure, **plus** a new **State / Fixtures** section (Storybook's "Controls" table) for authoring the mock data each state renders against.

The Library itself uses node-tree composition ("still kinda like Godot nesting," in AJ's words): editing a Component opens its subtree in the *same* tree/inspector/add-child stack Structure uses, rooted at the `__library` sandbox surface (§3.3). So combining smaller elements into a larger component is the identical interaction as building a live page — which is exactly why binding stays ubiquitous.

### 3.3 The `__library` sandbox surface — the mechanism that unifies the two editors

Authoring a Component reuses the entire Structure editor by pointing it at a synthetic surface that is **never rendered on the live site**:

```php
// registered once, in core, alongside the real surfaces
add_filter('bh_element_surfaces', function ($surfaces) {
    $surfaces['__library'] = [
        'group'       => 'Library',
        'label'       => 'Library (sandbox)',
        'slots'       => ['root' => ['label' => 'Component root']],
        'context'     => ['type' => 'component', 'param' => 'component_id'],
        // preview_ctx is replaced at render time by the active fixture STATE (§4),
        // never a real viewer context — this surface has no real viewer.
        'preview_ctx' => function () { return []; },
        'internal'    => true, // never offered as a live placement target in Structure's surface list
    ];
    return $surfaces;
});
```

When you edit Library Component #N, the editor loads `(surface='__library', context_id=N, slot='root')` and uses the *exact* `bhcore_element_placements` tree machinery to compose it — `save_placement()`, the `parent_placement_id` tree, container `content_context_id` bridges, `custom_js`, `actions`, everything. On "Publish/Save Component," the sandbox subtree is snapshotted into the component's definition via the already-existing `BH_Element_Prefab::save_from_node()` (`class-element-prefab.php:167`), which already walks a subtree and stores relative `parent_ref` indices. The sandbox rows can be kept as the editable working copy and re-snapshotted on each save (recommended — keeps editing cheap and lossless), with the prefab `definition` as the published artifact that instances read from.

This is the payoff of Finding 0: the Library authoring canvas is not new code — it is the Structure editor with a different root and a fixture render context. "Binding/actions ubiquitous across both tabs" falls out for free because it is *literally the same editor*.

---

## 4. Fixture states — the one genuinely new authoring concept

Fixture states are what let a Library item render against representative, mock data — "never touching real DB content" (AJ's explicit answer). They are new; nothing today has them.

### 4.1 What a state is

A **state** is a named bundle of mock data that a Library item renders against, so the same item can be browsed as Default / Expanded / Added-to-cart (Storybook's variants). A state carries three things:

1. **Attribute fixtures** — literal values for the item's own `schema` attrs (e.g. a `bh/stat-card`'s `label = "Plays this month"`).
2. **Binding fixtures** — mock resolved values for each data source the item *binds* to, keyed by source slug (and optionally attr key). This is the crux: `bh_contest.vote_count → 1204` in the "Default" state, `→ 0` in an "Empty" state. The binding descriptor on the element is unchanged; the state just supplies what that binding *resolves to* while in the Library.
3. **Context fixtures** — mock `context.*` tokens (`user_id`, `contest_id`, `post_id`) for bindings whose args reference the render context, so context-parameterized sources have something to resolve against.

```json
// a component's states, authored in the Library
{
  "states": {
    "default":  {
      "label": "Default",
      "attrs":   { "label": { "literal": "Plays this month" } },
      "bindings":{ "bhcore_events.count": 1204 },
      "context": { "user_id": 0 }
    },
    "empty":    {
      "label": "No data yet",
      "bindings":{ "bhcore_events.count": 0 }
    },
    "big":      {
      "label": "Viral",
      "bindings":{ "bhcore_events.count": 2400000 }
    }
  }
}
```

### 4.2 Where states are stored, and how authored

Two kinds of Library item carry states, stored in two matching places:

- **Components (prefabs).** States live with the component definition — either a new `states` JSON column on `bhcore_element_prefabs` or a top-level `states` key alongside the existing `definition` array. Authored in the Library inspector's State section; a component owns its own state set.
- **Primitive types (`register_type()`).** A type MAY ship default states via a new optional `'states'` manifest key (code-declared, ships with the plugin — e.g. `bh/stat-card` ships a "Default" fixture of `1204`). Author-added/overridden states for a type are stored in a new small table `bhcore_element_states` `(id, owner_kind ENUM('type','component'), owner_key VARCHAR, name, data JSON)`, merged over the manifest defaults at read time. Recommend the single `bhcore_element_states` table for *both* kinds to avoid two storage paths; the manifest `'states'` key is just the seed layer under it. (This mirrors how `BHY_Style` layers a code `DEFAULTS` under a DB option, and how per-instance style overrides layer over global tokens.)

Authoring UX: in the Library canvas, states render as Storybook-style "story" tabs across the top of the isolated canvas (Default / Empty / Viral / +). Selecting a state renders the item against it. The inspector's **State / Fixtures** section is a `.bhel-field-row` table (reusing the shipped Controls grid, `element-builder.css:382`) with one row per attr fixture, one row per bound source (its mock value), and one row per referenced context token — exactly the "Name / Control" table in the screenshots. Add/rename/delete states inline. This is the one net-new inspector section; it reuses the shipped row CSS wholesale.

### 4.3 How binding resolves against fixtures — the ~10-line engine change

`BH_Element_Data::resolve()` (`class-element-data.php:287`) gains a fixture-mode branch *before* it calls a source's `resolve` callable. When the render context carries a fixture map (Library mode), a bound attr whose source has a fixture value returns that fixture value instead of hitting the source:

```php
public static function resolve(array $attr_value, array $ctx = [], $default = '') {
    if (array_key_exists('literal', $attr_value)) return $attr_value['literal'];

    $binding = $attr_value['bind'] ?? null;
    if (!is_array($binding) || empty($binding['source'])) return $default;
    $slug = (string) $binding['source'];

    // NEW: fixture mode — Library/isolated-preview render. If the active
    // state supplied a mock value for this source, return it verbatim
    // (still run the formatter below), NEVER calling the real resolver.
    // Absent in every live Structure render, so production is byte-identical.
    if (!empty($ctx['__fixtures']) && array_key_exists($slug, $ctx['__fixtures'])) {
        $value = $ctx['__fixtures'][$slug];
        // ... existing formatter step applies unchanged ...
        return $value;
    }

    // ... existing unregistered-source / resolve / null / WP_Error / formatter ladder, untouched ...
}
```

`BH_Element::render_placement()` passes the active state's `bindings` map through as `$ctx['__fixtures']` and its `context` map merged into `$ctx` when rendering in Library mode (a new `render_slot()`/`render_definition()` caller path used only by the Library canvas + the `POST .../preview` REST route with a `state` param). In Structure, no `__fixtures` key is ever set, so `resolve()` behaves exactly as today — this is additive and non-breaking, the same posture every prior binding change took.

**This is the concrete answer to "what does 'bind to real vote count' mean while you're still in the Library."** You bind to the real source `bh_contest.vote_count` exactly as you would in Structure — the binding is authored once and travels with the element when it's placed. In the Library, the active state supplies a mock `1204` so you can see it render (and design the Empty / Viral variants); in Structure, that same binding resolves against the real contest. The binding is portable and identical across tabs; only the *data it resolves against* is fixture-vs-real. That is precisely the decoupling AJ asked for.

---

## 5. Library → Structure — the linked-instance vs. one-off decision

This is the real product decision AJ flagged. He draws two cases explicitly: (a) "prefab instances you duplicate/place repeatedly (e.g. a button prefab used in many places)," and (b) "one-off compositions built by combining smaller elements... used in exactly one place." He asks whether (a) means a live link (edit once, update everywhere) or copy-on-place.

### 5.1 The call: **both, chosen per placement. Components default to a live link; primitives and detached copies diverge freely.**

The two cases map cleanly onto WordPress's own, self-hosted, no-lock-in precedent — **synced vs. unsynced patterns** (formerly reusable blocks). WordPress already ships exactly this distinction: a *synced* pattern is edit-once-update-everywhere; an *unsynced* pattern is inserted as a plain, divergent copy. Adopting that mental model keeps the Library aligned with a mechanism WordPress users already understand and that is 100% self-hosted.

Concretely:

- **Case (a) — linked instance (the default when placing a Component).** Placing a Library Component into Structure creates a placement that *references* the component master. Editing the master propagates to every linked instance. Per-instance **overrides** (leaf values: attr fixtures→real bindings, style tokens, text) are allowed and stored on the instance; structural changes (adding/removing nested nodes) are made on the master and flow everywhere. This is the "button used in many places, restyle once" case, and it is what makes a *library* a library rather than a snippet-paster.
- **Case (b) — one-off / detached.** Two sub-flows, both already supported by today's copy semantics:
  - Build directly in Structure by combining primitive types from the add-child picker (today's plain placements). Diverges freely because it was never linked.
  - Place a Component and choose **"Insert a copy (detached)"** → today's `BH_Element_Prefab::instantiate()` deep-copy (`class-element-prefab.php:304`), which materializes independent rows with no back-link. Diverges freely.

Per-placement choice: the add-child picker's Component entries offer both "Insert linked instance" (default) and "Insert a copy." Primitive entries are always plain (there's no master to link to).

### 5.2 How a linked instance renders and stores — schema and render path

- **Schema:** add `library_component_id BIGINT UNSIGNED NOT NULL DEFAULT 0` to `bhcore_element_placements`. `0` = a normal placement (today's behavior, unchanged). Non-zero = a linked instance whose structure comes from that component master.
- **Storage of a linked instance:** ONE placement row, `library_component_id = N`, whose `config` holds *only overrides* (a map keyed by the master's relative `parent_ref` index → per-node override of attrs/style/bindings). Its nested structure is **virtual** — not materialized as child rows.
- **Render path:** `render_placement()` gets a linked-instance branch. When `library_component_id` is set, it renders the master's definition through the *existing* `BH_Element_Prefab::render_definition()` machinery (`class-element-prefab.php:404`) — which already renders a component tree with zero DB writes using negative in-memory ids — then applies the instance's stored overrides node-by-node before the type's `render` callable runs. This reuses code that already ships for the Gutenberg embed block; the linked instance is essentially "an in-place `render_definition()` with an overrides layer."
- **Detach op:** a "Detach from Library" action on a linked instance calls `instantiate()` to materialize the master's current definition into real, independent child rows under the placement, folds in the instance's overrides, then nulls `library_component_id`. After detach it is an ordinary divergent subtree (case b). One-way, explicit, undoable only by re-linking (out of scope for v1).

**Why linked-by-default rather than copy-by-default (a reversal of today's deliberate copy-only choice):** the current copy-only default was chosen (`class-element-prefab.php` docblock) specifically to avoid a "stray future feature accidentally propagating changes." That concern was correct *when there was no explicit instance concept*. AJ is now explicitly asking for the propagating case ("button used in many places"), so we make the link a *deliberate, first-class, visibly-marked* thing (a linked instance shows a link badge in the tree, its virtual children are read-only-except-overrides) rather than an accidental one. The copy path is preserved verbatim for case (b). This is not overriding the prior decision so much as completing it: the prior doc built the copy half; this builds the link half AJ now needs, and keeps both.

### 5.3 The add-child picker *is* the Library

AJ: "Structure's existing 'add child' picker should be powered by/replaced by the Library." The current `openAddChildPicker()` popup (powered by `GET .../elements/types`) is extended to a two-section Storybook-shaped picker:

- **Primitives** — `types_for_surface(surface)` (respecting the existing per-surface capability manifest, so a lesson-progress element still can't land on the login screen). Placing one = today's plain placement.
- **Components** — `GET .../elements/prefabs` (the existing list route, `class-element-prefab.php:516`), grouped by category, each with "Insert linked instance" / "Insert a copy." Only components whose contained types are all admitted on the target surface are offered (a capability intersection — flag as a real check to implement, §9).

The picker stays click-to-open/click-to-choose (no drag-drop), consistent with the shipped interaction and the no-live-browser build constraint.

---

## 6. Data/action binding ubiquity — the hard constraint, satisfied structurally

AJ made this a hard constraint: the same binding system (`BH_Element_Data` sources, the codeless `actions` system, gated `custom_js`) must work identically whether you're editing inside Library or Structure. This is satisfied *by construction*, not by parallel effort, because of the "one editor, two modes" decision:

- **Same descriptor, everywhere.** A binding is `{"bind":{"source",...}}` stored in `config.attrs.{key}`, identical in a `__library` sandbox row and a live Structure row. When a component is placed (linked or copied) into Structure, its bindings travel verbatim — no translation, no re-authoring.
- **Same resolver, everywhere.** `BH_Element_Data::resolve()` is the single path. Library renders swap in `$ctx['__fixtures']` (§4.3); Structure renders supply real `$ctx`. Nothing else changes. A source registered by a peer plugin (`bh_contest.vote_count`, `class-element-surface.php:59`) is bindable in the Library exactly as in Structure — the Library just shows it resolving to the state's mock value.
- **Same actions, everywhere.** `config.actions` (the codeless `{trigger, action, ...}` allowlist compiled by `build_actions_js()`, `class-element.php:1044`) is stored on the element and rendered identically in both tabs. In the Library canvas the action wires up against the isolated preview DOM (a real click toggles the class / fires the fetch), so you can *test* interactions in isolation before placing — a genuine Library win, at zero new cost.
- **Same `custom_js` gate, everywhere.** `save_placement()` strips `custom_js` unless `current_user_can('bhcore_author_custom_js')` (`class-element.php:600`) — this fires on the `__library` sandbox writes too, since all writes funnel through `save_placement()`. One consequence to name (§9): a *placed* linked instance renders the *master's* `custom_js`, authored by whoever built the component; the gate protects *authoring*, and placing a component you didn't write still runs its script — a trust/review consideration, not a new hole (same as embedding any prefab today).

Because binding is engine-level and both tabs are the same engine, ubiquity is the default state, not a feature to maintain.

---

## 7. Concrete UI / interaction design

Enough detail to build. All of it lives inside the proven Design Suite shell (`class-style-gallery.php`), **never a new standalone admin page** — the documented WordPress hook-resolution bug on this install (`class-api-docs.php` docblock; `DESIGN-SUITE-UNIFICATION-PLAN.md` §1.4) makes new top-level/standalone slugs a demonstrated risk, and the standing fix is "render inside an already-loading page."

### 7.1 The two-tab switch

A top-level `Library | Structure` segmented control in the Design Suite shell, above the three panes. Active tab persisted in `localStorage` under a dedicated key (e.g. `bhds_active_tab`), reusing the exact per-browser cosmetic-state pattern already established for Debug Tools section open/closed memory (`class-debug.php:449`; `own-ur-shit.php:1243`). Switching tabs swaps *which root the shared tree/inspector/canvas is bound to* — it does not tear down and rebuild the panes.

The existing "Site" root node and its global-tokens inspector (the 3.4.36 synthetic Site node) belong under **Structure** (it's site-wide live style). Global style tokens remain what the one inspector shows when Site is selected — unchanged.

### 7.2 Structure tab (mostly today's screen)

- **Left pane:** the live tree, `Site → Surface → Slot → placements`, unchanged from 3.4.37, with one addition: linked instances render with a small link badge and their virtual children are shown but marked read-only-except-overrides.
- **Center pane:** the shadow-DOM canvas rendering the selected surface against its `preview_ctx`, live-updating on token/style edits (the existing `#bhy-vars` rewrite technique).
- **Right pane:** the inspector — Element / Style / Data sections, unchanged. For a linked-instance node, the inspector shows an "Overrides" affordance and a "Detach from Library" button; structural add/remove is disabled on virtual children (edit the master in the Library instead).
- **Add child:** the Library-powered picker (§5.3).

### 7.3 Library tab (the new screen, reusing every pane)

- **Left pane:** the Storybook component sidebar — Primitives and Components, grouped by category, search/filter at top. Selecting an item loads it into the canvas + inspector. A "New Component" action creates an empty `__library` sandbox subtree.
- **Center pane:** the **isolated** shadow-DOM canvas — one item, rendered against the **active state**, with a row of state "story" tabs across the top (Default / Empty / Viral / +). Same canvas component as Structure, in single-item isolation mode (renders `render_definition()`/one sandbox subtree, not a full surface). This is the Storybook "component in isolation across its states" view.
- **Right pane:** the same inspector (Element / Style / Data) **plus** the new **State / Fixtures** section (§4.2) — a `.bhel-field-row` Controls table for the active state's mock attrs/bindings/context, exactly matching the Name/Control screenshots.
- **Composition:** editing a Component opens its subtree in the *same* tree used by Structure (rooted at `__library`, ctx = component id), with the same add-child picker (so you can nest primitives and even other Components), same reorder, same `custom_js`/`actions`. "Publish/Save Component" snapshots via `save_from_node()`.

### 7.4 Carry-forward of shipped systems

- **Shadow-DOM canvas:** reused for both tabs; Structure renders a full surface, Library renders one isolated item against a state. The isolation mode is a new *caller* of the same canvas, not a new canvas.
- **`.bhel-style-group-body > .bhel-field-row` Controls grid** (`element-builder.css:382`): reused verbatim for the Data section (as today) *and* the new State/Fixtures section — this is the shipped "Controls table" the Storybook screenshots want; no new table CSS needed, only new rows.
- **localStorage persistence** (`class-debug.php`/`own-ur-shit.php` pattern): reused for the active-tab memory and Library sidebar group expand/collapse, same "cosmetic per-browser state, never a server option" reasoning already documented.
- **The 3.4.36 one-inspector-branching-on-selection model**: preserved. The inspector now branches on `{ site | placement | library-item | library-node }`, one component, not four — the same discipline the 3.4.36 note established.

---

## 8. Staged build plan

Sequenced by dependency and risk, smallest real slice first, each independently shippable and live-verifiable, matching this codebase's named-slice discipline.

**Phase 0 — the two-tab shell, pure IA, no new data.** Add the `Library | Structure` switch to the Design Suite shell (localStorage-persisted). Structure tab = today's tree, unchanged. Library tab = a read-only browser of existing prefabs (rendered via the existing `render_definition()`) + registered types, grouped Storybook-style. No fixtures, no linked instances, no authoring yet. **This directly targets the "clunky and wrong" complaint with the least surface area** and proves the IA feels right before any engine change. *Testable:* both tabs render; switching persists; the Library lists every existing prefab and type.

**Phase 1 — Library authoring via the `__library` sandbox.** Register the internal `__library` surface. Wire "New Component" / "Edit Component" to open its subtree in the existing tree/inspector/add-child stack rooted at `__library`; "Publish" snapshots via `save_from_node()` (already exists). Renders against empty/real ctx for now (no fixtures). Makes the Library genuinely "not read-only." *Testable:* build a two-node component in the Library, publish it, see it in the browser; reopen and edit it.

**Phase 2 — fixture states.** New `bhcore_element_states` storage (+ optional `register_type()` `'states'` manifest seed), the `resolve()` fixture-mode branch (§4.3), the canvas state-tab switcher, and the State/Fixtures inspector section (reusing `.bhel-field-row`). **The headline new capability.** *Testable:* a `bh/stat-card` in the Library shows `1204` in Default and `0` in Empty via fixtures, with its real `bhcore_events.count` binding untouched; no DB read occurs in Library mode.

**Phase 3 — add-child picker = Library (copy/detached path).** Extend the Structure add-child picker to list Primitives + Components (§5.3), grouped Storybook-style, with capability intersection. Component placement uses the *existing* `instantiate()` copy-on-place (detached, case b) only — no linking yet. *Testable:* place a Library Component into a CRM sidebar as a detached copy; it diverges freely; the master is untouched.

**Phase 4 — linked instances (synced model, case a).** Add `library_component_id` column (DB_VERSION bump), the render-from-master-plus-overrides branch in `render_placement()` (reusing `render_definition()`), the linked-instance override storage/UI, and the "Detach" op. Make "Insert linked instance" the default Component placement. **Highest-risk data change, sequenced last** after the picker and authoring are proven. *Testable:* place one component in three surfaces as linked instances; edit the master; all three update; override one instance's text; only it diverges; detach it; it becomes independent.

**Phase 5 — polish / deferred.** Fixture states for `list`/`series` kinds (mock list data authoring); component thumbnails in the browser; richer per-instance override UX; the optional plugin `register_component()` hook from §1.1; and an *explicit decision* on whether any self-hosted visual-regression-across-states is pursued (§9 — likely deferred as out-of-constraints). Named, not scheduled.

Migration is near-zero: existing prefabs *are* Library Components (same table); existing placements *are* Structure (same table, `library_component_id` defaults to 0). No data conversion pass is required — a real advantage of reusing the engine.

---

## 9. Open questions and risks I could not resolve with high confidence

1. **Per-instance override granularity for linked instances.** Confident about *leaf-value* overrides (attrs, style tokens, bound-vs-literal). Not confident about how far override should go before the honest answer is "detach." Recommendation: v1 allows leaf overrides only; any structural change requires editing the master or detaching. **Needs AJ's confirmation of the override boundary before Phase 4.**

2. **Container elements (`content_context_id` / `BH_Content`) inside linked components.** v1 recommendation: inner `BH_Content` is master-owned, not per-instance-overridable. **Under-verified, needs a live round-trip test.**

3. **Fixture authoring for `list` / `series` bindings.** Scalars are easy; collections need a repeatable row editor. Deferred to Phase 5; scalar-only fixtures in v1.

4. **Visual regression testing across states (the Storybook feature in AJ's screenshots).** Needs a headless browser + screenshot pipeline — **directly conflicts with VISION.md's no-build/shared-hosting/no-external-service constraints**, same reason Storybook's own runtime was ruled out this session. The fixture-state model (Phase 2) is the necessary precondition if ever pursued, but the diffing itself is very likely permanently out of scope on ordinary shared hosting. **Recommend telling AJ this explicitly rather than implying parity with the screenshots.**

5. **Performance of linked-instance rendering.** Every linked instance re-renders its master's definition on each page load; no caching layer exists today. A render cache keyed by `(component_id, master updated_at, overrides hash, ctx)` is a real candidate but its own pass. **Unmeasured, no live environment.**

6. **Capability intersection for placing Components.** Undefined today whether a Component with mixed-surface-admitted contents should hard-block or skip-with-warning. **Needs AJ's call.**

7. **`custom_js` trust when placing others' Components.** Placing a linked instance runs the master's capability-gated `custom_js`, authored by whoever built it — a non-issue on a single-artist install, a real policy question once multiple site-builder employees exist (`DESIGN-SUITE-UNIFICATION-PLAN.md` §1.3's future scenario). **Needs a policy call**, likely: re-check the placer's own capability and strip/inert custom_js they couldn't have authored themselves.

8. **`__library` sandbox rows must be excluded from any "count all placements" tooling.** No real surface queries `surface='__library'` today, so this is low-risk, but any future Debug Tools/metrics view that scans placements broadly needs to explicitly exclude the sandbox surface.

9. **Standing environment caveat.** No pass on this codebase has had a live PHP/MySQL/WordPress/browser environment, and this one is no exception. Reasoned against the actually-read shapes of the engine but **not executed.** Phase 0 is deliberately the smallest possible IA slice — confirm the two-tab shape on a live screen before building the fixture/linked-instance engine work on top of it.

---

### Critical files for implementation

- `own-ur-shit/includes/class-element.php` — the placement registry, tree, `render_placement()`/`render_slot()`, and REST bridge; gains the fixture-context passthrough, the linked-instance (`library_component_id`) render-merge branch, and the Library/state REST params.
- `own-ur-shit/includes/class-element-data.php` — `resolve()`'s single choke point; gains the ~10-line fixture-mode branch (§4.3).
- `own-ur-shit/includes/class-element-prefab.php` — becomes the Library Component store; `save_from_node()`/`instantiate()`/`render_definition()` reused for authoring, detached-copy, and linked-instance rendering; extended with fixture-state storage and link semantics.
- `own-ur-shit/includes/class-identity-activator.php` — the one schema pass: `library_component_id` on `bhcore_element_placements` and the `bhcore_element_states` table, next `DB_VERSION` bump.
- `own-ur-shit/assets/js/element-builder.js` + `assets/css/element-builder.css` — the two-tab shell, Library browser, state switcher, Fixtures inspector section, and place-linked-vs-detached picker; reuses the existing tree/inspector/add-child/reorder/`globalSave` and the shipped `.bhel-field-row` Controls grid. Hosted inside `class-style-gallery.php`'s Design Suite shell, never a standalone page.
