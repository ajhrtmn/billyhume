# The `BH_Element` builder — a styleable, data-bindable element system across every surface

A design pass for the "element builder" AJ asked for directly: *"adopt the GUI ideas of the Style editor to the content studio block/widget builder, and have an 'element builder' that's styleable and can decide what type of container it is, and what goes in it datawise, and where it can be used — not just for front-end widgets, but for backend stuff like building CRM profile pages or dashboards, and for the LMS builder tools and front-end interfaces for that and the portal. I like the StorybookUI + Elementor vibes… it's more of a visual CSS designer than a page builder, but it needs some page-build-lite elements probably."*

This doc is grounded in the code that already ships, cited by file and class, in the same call-site-first spirit as `LMS-AUTHORING-DESIGN-PLAN.md` and `EVENT-TRACKING-ARCHITECTURE-PLAN.md`. It deliberately reuses three systems that already exist rather than inventing parallels: `BHY_Style`/`BHY_Gallery` (the "Storybook" token editor the user wants the GUI to feel like), `BH_Content`/`BH_Studio` (the Gutenberg-primitives block canvas), and the ecosystem's standing "any plugin contributes via a filter, zero central registration" pattern (`bhy_style_surfaces`, `bh_studio_block_types`, `bhi_portal_panels`, `ous_debug_tools`, `bh_crm_activity_summary`, `BH_Event::register_event_type()`).

## 0. The finding that frames the design

The user's ask sounds like "build Elementor," but reading the codebase, **most of it already exists in pieces and the real work is a thin binding layer that unifies them**, not a new engine:

- The **"Style editor GUI"** the user wants to emulate is `BHY_Gallery` (`own-ur-shit/includes/class-style-gallery.php`): a three-pane Storybook — a grouped sidebar of registered surfaces, an iframe canvas rendering the selected one, and a controls panel of token pickers (`BHY_UI::swatch_field()`, `font_field()`, `slider_row()`) that live-edit CSS custom properties across every surface at once. This is the exact "visual CSS designer" interaction model to reuse.
- The **"content studio block/widget builder"** is `BH_Studio` (`class-studio.php` + `assets/js/studio.js`): a no-build `@wordpress/block-editor` canvas over `BH_Content`'s `{type, attrs, children}` tree, with a filterable palette (`bh_studio_block_types`). This is the "page-build-lite" composition engine — it already does arbitrary nested trees, `RichText`, media, and semantic (non-absolute-positioned) output.
- The **"styleable" part** is `BHY_Style`: design tokens (`--bh-bg`, `--bh-accent`, `--bh-font-display`, `--bh-radius`, …), per-entity overrides via `_bhy_style_override`/`_bhy_style_json` postmeta (`BHY_Style::entity_overrides()`), and a JSON payload for scoped client application (`BHY_Style::entity_style_payload()`).
- The **"where it can be used" / "what goes in it datawise"** parts are what genuinely **don't** exist yet. There is no concept of a reusable, placeable *element* that (a) declares which surfaces it is valid on and (b) binds to live ecosystem data.

So the element builder is **not** a fourth content system. It is: a **placement + capability + data-binding layer** that sits *on top of* `BH_Content` (for intra-element composition), reuses `BHY_Style` (for styling), and is edited through a GUI cloned from `BHY_Gallery` (for the Storybook feel). The design below keeps every existing contract intact and adds exactly one new registry class (`BH_Element`), one new data-binding resolver (`BH_Element_Data`), and one new storage table (`bhcore_element_placements`).

---

## 1. The three judgment calls

The user was asked three questions and did not answer them. Each is resolved below with reasoning tied to the actual code, and each is **flagged for user confirmation** — these are the load-bearing decisions and are cheap to reverse now, expensive later.

### 1.1 Nesting model → **hybrid: fixed named slots at the surface level, arbitrary `BH_Content` nesting *inside* container elements**

**Decision.** A surface (dashboard, CRM profile, lesson interface, portal panel) declares a fixed set of **named slots** (regions). Elements are placed into a slot and ordered within it — they do **not** nest arbitrarily at the top level. However, an element may declare itself a **container**, and a container element owns its own `BH_Content` subtree that *is* edited with the existing `BH_Studio` arbitrary-nesting canvas. So arbitrary Elementor-style nesting exists — scoped *inside* a container element — while top-level page structure stays predictable and per-surface.

**Reasoning.**
- Every real target surface in this codebase already has *implicit* fixed regions, not a freeform tree: the CRM profile (`BH_CRM::render_detail()`, `bh-crm/includes/class-people.php`) renders identity header → profile-fields table → activity sections in a fixed order; the portal (`BHI_Portal`) renders a nav rail + one active panel body; the dashboard (`OUS_Dashboard::render()`) renders status cards. Named slots model what these surfaces *are* today with almost no disruption — each existing region becomes a named slot.
- Fixed slots make the **capability model (1.3) tractable**: "a stat-card is valid in `dashboard/main` and `bh_crm_profile/sidebar`" is a checkable statement; "a stat-card is valid anywhere in an infinite tree" is not.
- Arbitrary nesting is genuinely wanted for the "page-build-lite" case, but `BH_Studio`/`BH_Content` **already deliver it** and are already the sanctioned answer (per `LMS-AUTHORING-DESIGN-PLAN.md`). Re-implementing a second arbitrary tree at the element level would duplicate `BH_Content` — exactly the "second incompatible content model" that doc rejects. Scoping nesting to *inside a container element* reuses `BH_Content` verbatim.
- **Not a dead end for full arbitrary nesting later:** the placement row (§2) is keyed by `(surface, slot, position)`. A future "true tree" mode is a superset — a slot named `__root` plus a self-referential `parent_placement_id` column turns the flat slot list into a tree without a schema rewrite. The seam is a single nullable column left unused for now.

> **Judgment call — confirm with user.** If AJ actually wants Elementor-style free nesting at the *top* level of a surface (drag a column into a row into a section, anywhere), we flip to the tree model. The schema is designed to allow that later; the recommendation is slots-first because it matches what every surface already is and makes capability scoping real.

### 1.2 Data binding → **real, declarative binding to registered data sources — plus plain generic content when no binding is set**

**Decision.** An element attribute may carry a **binding descriptor** that references a named, self-registered **data source**. Sources are registered the same zero-central way event types are (`BH_Element_Data::register_source($slug, $args)`, mirroring `BH_Event::register_event_type()`). Bindings resolve **server-side at render time** against a **context** (`user_id`, `post_id`, `entity_id`, current viewer). Elements that set no binding fall back to a static literal value — so the same `bh/stat-card` element is a live "plays in last 30 days" widget on a dashboard and a hardcoded "1,204" on a marketing page, with no separate element type.

**Reasoning.**
- The user *explicitly named* "dashboards" and "CRM profile pages" as targets. A dashboard widget that can't read `bhcore_events` counts or a CRM field value is decoration, not a dashboard. The ecosystem already has the exact data sources such widgets would read: `BH_Event` (`bhcore_events`, with `get_results()` count-by-type/day/user helpers in `class-event.php` lines 186–255), `BH_Identity` (`client_ids_for_user()`), CRM profile fields (`BHI_Profiles`), and per-plugin stats (e.g. `BHS_Stats` in bh-streaming). These are the natural registered sources.
- The binding contract must be **declarative data**, not a callback stored in the DB (a stored PHP callable is a security and portability non-starter). So a binding is a small JSON object naming a registered source + args + context references — resolved by a resolver the *source's owning plugin* registered in code. This is exactly how `BH_Content` separates stored `attrs` (data) from the registered `renderer` (code), and how `BH_Event` separates the stored envelope from `register_event_type()`.
- Keeping generic/static content as the no-binding fallback preserves the "more of a visual CSS designer than a page builder" framing — most elements are just styled boxes/text/images; binding is opt-in per attribute.

**Binding descriptor shape (stored inside a placement's `config` JSON):**

```json
{
  "attrs": {
    "value": { "bind": {
        "source": "bhcore_events.count",
        "args":   { "type": "bhs/play", "since": "P30D" },
        "subject": "context.user_id"
    } },
    "label": { "literal": "Plays this month" }
  }
}
```

- `source` — a registered `BH_Element_Data` source slug (namespaced like block/event types).
- `args` — source-specific static args (validated by the source's own schema).
- `subject`/other `context.*` tokens — resolved from the render context: `context.user_id`, `context.post_id`, `context.entity_id`, `context.viewer_id`. Any attr value is either `{ "bind": {...} }` or `{ "literal": ... }`.
- A source declares its **return kind** (`scalar` | `list` | `richtext` | `url` | `series`) so the GUI only offers bindable sources whose kind matches the target attr's kind (a stat-card `value` accepts `scalar`; a recent-activity list accepts `list`).

> **Judgment call — confirm with user.** This makes elements first-class *dashboard widgets*, not just page-builder content. If AJ wants the simpler "generic content only, bind nothing" scope for v1, Phase 3 (binding) simply doesn't ship and everything else stands. Recommendation is real binding because the two named targets (dashboards, CRM profiles) are meaningless without it.

### 1.3 Capability / placement scoping → **declarative per-element manifest, surfaces self-register their slots, both via filters**

**Decision.** Every element type declares, in its `register_type()` manifest, which surfaces (and optionally which slots) it is valid on, via a `'surfaces'` key (`['dashboard', 'bh_crm_profile']` or `'*'`). Surfaces self-register their own slots via a `bh_element_surfaces` filter — the same pattern as `bhy_style_surfaces`/`bhi_portal_panels`. The builder GUI only offers, in a given surface's palette, the element types whose manifest admits that surface. A final `apply_filters('bh_element_can_place', $bool, ...)` gate lets any plugin veto/allow edge cases.

**Reasoning.** This is the ecosystem's established idiom applied verbatim: `bhy_style_surfaces` already has plugins self-register renderable surfaces from their own bootstrap; `bhi_portal_panels` already has them self-register placeable panels with `id`/`label`/`render`/`priority`. Making elements self-declare placement is the same shape, and it makes the palette *correct by construction* per surface instead of relying on admin discipline. Unrestricted placement was the alternative; it's rejected because "put a lesson-progress bar on the login screen" should be impossible, not merely discouraged.

> **Judgment call — confirm with user.** Declarative capability adds a small authoring burden (each element names its surfaces). If AJ prefers "place anything anywhere, trust the admin," drop the `'surfaces'` enforcement and treat it as advisory sorting only. Recommendation is declarative because it matches every other registry here and keeps surface palettes sane.

---

## 2. Data model

Two kinds of thing, mirroring `BH_Content`'s split of code-registered *block types* vs. stored *documents*:

1. **Element types** — registered **in code** (`BH_Element::register_type()`), never in the DB. Same as `BH_Content` block types and `BH_Event` event types. A type is a manifest + a renderer; it ships with a plugin and degrades gracefully if that plugin deactivates (unknown type → placement renders nothing, exactly like `BH_Content::render()` skips unregistered types).
2. **Element placements** — stored **in the DB**: "this element type, configured this way, sits in this slot on this surface for this context, at this position."

### 2.1 New table: `bhcore_element_placements`

Added to `own-ur-shit/includes/class-identity-activator.php` as the next `DB_VERSION` bump (current is `1.8`; this becomes `1.9`), alongside `bhcore_content` and `bhcore_events`:

| column | type | notes |
|---|---|---|
| `id` | bigint unsigned PK auto | |
| `surface` | varchar(60) | registered surface slug, e.g. `dashboard`, `bh_crm_profile`, `bhc_lesson_ui`, `portal_panel` |
| `surface_context_id` | bigint unsigned, default 0 | `0` = singleton surface (global dashboard); else the entity id (CRM = user_id, lesson = lesson post id, portal panel = 0/site) |
| `slot` | varchar(60) | registered slot within the surface, e.g. `main`, `sidebar`, `header` |
| `position` | int unsigned, default 0 | order within `(surface, surface_context_id, slot)` |
| `element_type` | varchar(100) | registered type slug, e.g. `bh/stat-card` |
| `config` | longtext | JSON: `attrs` (each `{literal}` or `{bind}`), `style` (token overrides), `content_ref` flag |
| `content_context_id` | bigint unsigned, default 0 | if the type is a container, its inner `BH_Content` subtree lives at `BH_Content` context `('bh_element', content_context_id)`; `0` if not a container |
| `enabled` | tinyint(1), default 1 | soft on/off without deleting |
| `parent_placement_id` | bigint unsigned, default 0 | **unused seam for §1.1's future tree mode**; always `0` today |
| `revision_of` | bigint unsigned, default 0 | **unused seam for §2.3 version history**; always `0` today |
| `updated_at` | datetime, `ON UPDATE CURRENT_TIMESTAMP` | matches `bhcore_content` |

Index: `KEY place (surface, surface_context_id, slot, position)` — the one query render does (`WHERE surface=? AND surface_context_id=? AND slot=? ORDER BY position`).

Note there is deliberately **no `UNIQUE`** constraint on `(surface, context, slot, element_type)` — the same element type can legitimately appear multiple times in one slot (two stat-cards bound to different metrics).

### 2.2 Reuse of `bhcore_content` for container innards

A container element does **not** store its inner tree in `config`. It stores it through the existing `BH_Content` contract at context `('bh_element', content_context_id)`, so the existing `BH_Studio` canvas (`ous/v1/studio/bh_element/{id}`) edits it with zero new storage code. This is the concrete mechanism that gives §1.1's "arbitrary nesting inside a container" for free.

### 2.3 Version-history seam (do NOT design it here)

`ROADMAP-platform-evolution.md` §7a names "the new visual element builder for styleable containers/widgets" as an explicit future target of the in-admin version-history feature, and says it should "probably be one shared service … rather than each plugin inventing its own versioning." So this schema must not *preclude* that, but must not *build* it. The clean seam:

- The `revision_of` column above exists but is always `0` today. When version history ships, a saved snapshot is a new placement row with `revision_of` = the live row's id and `enabled = 0`; "restore" copies it back. That is the entire seam — no versioning logic is written now.
- Because placements are plain rows keyed by `(surface, context, slot)`, they are also trivially serviceable by a *dedicated external revisions table* keyed by `object_type='bh_element_placement' + object_id + version` (the roadmap's other candidate shape) without any change here — the schema is agnostic to which of the two the future service picks.

**Explicitly deferred:** which storage the version service uses, diffing, and the restore UI. Named, seam left clean, not designed.

---

## 3. PHP API contract

Static-method classes, `BH_`/`BHY_`/`OUS_` prefixes, `class_exists()`-guarded degrade — matching `BH_Content`, `BH_Event`, `BHY_Style`.

### 3.1 `BH_Element` — the type registry + renderer (`own-ur-shit/includes/class-element.php`)

```php
/**
 * @param string $slug  Namespaced type, e.g. 'bh/stat-card', 'bhcrm/field-value'.
 * @param array  $args {
 *   'label'      => 'Stat card',
 *   'category'   => 'data'|'layout'|'text'|'media',   // palette grouping (Storybook sidebar groups)
 *   'icon'       => 'dashicons-chart-bar',
 *   'surfaces'   => ['dashboard','bh_crm_profile'] | '*',   // §1.3 capability manifest
 *   'container'  => false,                            // §1.1 does it own a BH_Content subtree?
 *   'schema'     => [                                 // same shape as BH_Content::register_block_type() schema
 *        'value' => ['type' => 'string', 'default' => '', 'bindable' => true,  'kind' => 'scalar'],
 *        'label' => ['type' => 'string', 'default' => '', 'bindable' => false],
 *   ],
 *   'style'      => ['color_accent','radius','space_scale'], // which BHY_Style tokens the inspector exposes for this element
 *   'render'     => function(array $attrs, array $ctx, array $instance): string { ... },
 * }
 */
public static function register_type($slug, array $args);

public static function registered_types();                 // slug => manifest
public static function get_type($slug);
public static function types_for_surface($surface);        // manifest filter by 'surfaces' — powers the palette
public static function is_container($slug);
```

Rendering:

```php
// Resolve every bound attr against $ctx, apply style overrides, render inner
// content tree for containers, then call the type's 'render' callable.
public static function render_placement(array $placement, array $ctx): string;

// The surface-facing entry point every integration calls (§5). Loads enabled
// placements for (surface, context_id, slot) ordered by position and renders each.
public static function render_slot($surface, $context_id, $slot, array $ctx = []): string;
```

Placement storage (thin wrappers over `bhcore_element_placements`):

```php
public static function get_placements($surface, $context_id, $slot = null);   // ordered
public static function save_placement(array $placement);                      // insert/update, returns id
public static function delete_placement($id);
public static function reorder($surface, $context_id, $slot, array $ordered_ids);
```

Debug Tools section (mandatory, matching every other registry — `BH_Studio::register_debug_section()`, `BHI_Portal`, `BH_Event`): a table of every registered type, its declared surfaces, whether it's a container, and a per-surface count of live placements, so "why doesn't my element show up on X" has a one-click answer.

### 3.2 `BH_Element_Data` — the data-binding resolver (`own-ur-shit/includes/class-element-data.php`)

```php
/**
 * @param string $slug  Namespaced source, e.g. 'bhcore_events.count', 'bhcrm.field'.
 * @param array  $args {
 *   'label'    => 'Event count',
 *   'kind'     => 'scalar'|'list'|'richtext'|'url'|'series',   // matched against attr 'kind' in the GUI
 *   'requires' => ['user_id'],                                 // which context tokens the resolver needs
 *   'arg_schema' => ['type' => ['type'=>'string'], 'since' => ['type'=>'string','default'=>'P30D']],
 *   'resolve'  => function(array $args, array $ctx) { return 1204; },   // registered in code, never stored
 * }
 */
public static function register_source($slug, array $args);
public static function registered_sources();          // slug => manifest (minus the callable) for the GUI picker
public static function sources_for_kind($kind);       // powers the inspector's source dropdown

// The contract §1.2 defines. Reads {source,args,subject/context.*}, pulls the
// registered resolver, feeds it resolved context tokens, returns the value.
// Unknown/failed source -> returns the attr's literal fallback (graceful degrade).
public static function resolve(array $binding, array $ctx);
```

**First-party sources own-ur-shit registers (Phase 3):**
- `bhcore_events.count` — wraps `BH_Event`'s existing `get_results()` count query (`class-event.php`); args `type`, `since`, optional `subject_type`; `kind=scalar`.
- `bhcore_events.recent` — recent rows for `context.user_id`; `kind=list`.
- `bhcrm.field` — a CRM/`BHI_Profiles` field value for `context.user_id`; `kind=scalar`/`richtext`.
- `bhcrm.activity_summary` — reuses the existing `bh_crm_activity_summary` filter output; `kind=list`.

Peer plugins register their own from their own bootstrap (e.g. bh-streaming registers `bhs.play_count` wrapping `BHS_Stats`), guarded by `class_exists('BH_Element_Data')` — same as bh-monetization-woo guards `bh_studio_block_types` with `class_exists('BH_Studio')`.

### 3.3 Surface + capability contract (filters)

```php
// Surfaces self-register their slots — same shape as bhy_style_surfaces/bhi_portal_panels.
add_filter('bh_element_surfaces', function ($surfaces) {
    $surfaces['bh_crm_profile'] = [
        'group'   => 'CRM',
        'label'   => 'CRM profile page',
        'slots'   => [
            'header'  => ['label' => 'Header'],
            'main'    => ['label' => 'Main column'],
            'sidebar' => ['label' => 'Sidebar'],
        ],
        // How the builder canvas gets a representative context to preview against,
        // and how a live render resolves its context (§5). Returns ['user_id'=>..].
        'context'      => ['type' => 'user', 'param' => 'user_id'],
        'preview_ctx'  => function () { return ['user_id' => get_current_user_id()]; },
    ];
    return $surfaces;
});

// Final gate — lets a plugin veto/allow a specific placement beyond the manifest.
$ok = apply_filters('bh_element_can_place', true, $element_type, $surface, $slot);
```

`BH_Element::types_for_surface($surface)` = the registered types whose `'surfaces'` manifest admits `$surface`, intersected with `bh_element_can_place`. This is the single source of truth the palette reads.

### 3.4 REST bridge for the builder GUI

Mirrors `BH_Studio::register_routes()` exactly (`manage_options`, `wp_rest` nonce, `ous/v1` namespace):

- `GET  ous/v1/elements/surfaces` → registered surfaces + slots.
- `GET  ous/v1/elements/types?surface=` → `types_for_surface()` (the palette).
- `GET  ous/v1/elements/placements/{surface}/{context_id}` → all placements grouped by slot.
- `POST ous/v1/elements/placements/{surface}/{context_id}` → upsert/reorder a slot's placements.
- `GET  ous/v1/elements/sources?kind=` → bindable sources for the inspector dropdown.
- `POST ous/v1/elements/preview` → resolve one placement against `preview_ctx` and return rendered HTML for the live canvas.

Container inner trees reuse the **existing** `ous/v1/studio/bh_element/{content_context_id}` route — no new endpoint.

---

## 4. The editing GUI — "StorybookUI + Elementor vibes, more visual-CSS-designer than page builder"

The GUI is a **clone of `BHY_Gallery`'s three-pane layout**, not a new UX. It is a new admin page (`BH_Element_Builder::render()`, submenu under `own-ur-shit`, same as Style and Content Studio) authored in plain PHP + vanilla JS with the same no-build convention (`assets/js/element-builder.js`, enqueued like `studio.js`; the token pickers are the existing `BHY_UI` helpers, which are already pure PHP-render + `BHY_UI::swatch_js()` vanilla wiring).

**Layout (left → right), reusing `BHY_Gallery`'s CSS classes where possible:**

1. **Left rail — the element palette (the "Storybook" list).** Grouped by `category` exactly like `BHY_Gallery::render_sidebar()` groups surfaces. Populated from `GET types?surface=` so it only shows elements valid on the surface being edited (§1.3). A surface switcher at the top (`bh_crm_profile ▾`, `dashboard`, `bhc_lesson_ui`, `portal_panel`) mirrors the gallery's story buttons. Clicking a palette element inserts it into the currently-selected slot.

2. **Center — the live canvas.** An `<iframe srcdoc>` rendered by the **same `preview_doc()` technique** as `BHY_Gallery::preview_doc()`: the surface's real CSS, the current `BHY_Style` tokens injected as `<style id="bhy-vars">`, and the surface's slots rendered with their placements (via the `preview` REST call). Because tokens are injected the same way, **editing a style token in the right rail live-updates the canvas exactly like the Style editor already does** (`refreshAllFrames()` → rewrite `#bhy-vars` textContent). Slots are visually outlined; elements within a slot are drag-reorderable (`position`). This is the "visual CSS designer" feel the user called out.

3. **Right rail — the inspector.** Three stacked sections for the selected element:
   - **Content/attrs** — one control per `schema` attr. Text/number/checkbox by `type`; `richtext`/container attrs get a "Edit content →" button that opens the **existing `BH_Studio` canvas** for that element's `('bh_element', content_context_id)` tree (this is the "page-build-lite" bridge — no second rich-text editor is built; §7).
   - **Style** — for each token in the element's `'style'` manifest list, the **actual existing `BHY_UI` picker**: `BHY_UI::swatch_field()` for colors, `BHY_UI::slider_row()` for radius/scale, `BHY_UI::font_field()` for fonts. These write per-element token overrides into `config.style`, applied as a scoped `:root`-style block on that element's wrapper (same override-the-CSS-var mechanism as `BHY_Style::entity_style_payload()`/`inline_css()`). The user's "adopt the GUI ideas of the Style editor" is satisfied literally: it is the same widgets.
   - **Data** — for each `bindable` attr, a source dropdown (`GET sources?kind=` filtered to the attr's `kind`, §1.2), plus argument fields from the source's `arg_schema`, plus a context-token selector (`context.user_id`, etc.). Toggle "Bind / Literal" per attr. A tiny inline "resolves to: 1,204" preview calls `POST preview`.

**"Page-build-lite" resolution.** The user says it "needs some page-build-lite elements." Those are exactly the **container elements** (`bh/section`, `bh/columns`, `bh/card`) whose innards are the `BH_Studio` canvas. The element builder is the *chrome and placement designer*; `BH_Studio` is the *free-form body editor*; they compose. This is why we don't rebuild a page builder — we reuse the one that ships.

---

## 5. Integration points (per surface: what exists, what changes, what stays)

Each integration is one line — a surface registers its slots and calls `BH_Element::render_slot()` where it wants elements. Nothing about the surface's existing rendering is thrown away; slots are *added around/between* current output.

### 5.1 Dashboard (`OUS_Dashboard`, `class-dashboard.php`) — **the Phase 1 target**
- **Today:** `render()` prints registry cards + a hardcoded `render_status_block()`. No plugin-contributed widgets.
- **Change:** register surface `dashboard` with slots `main`/`sidebar`; call `BH_Element::render_slot('dashboard', 0, 'main')` above the status block. Context is global (`surface_context_id = 0`).
- **Stays:** all existing cards/status output untouched — elements render *in addition*.

### 5.2 CRM profile (`BH_CRM::render_detail()`, `bh-crm/includes/class-people.php`)
- **Today:** fixed order — `render_identity_header()` → `render_profile()` (fields table) → `bh_crm_activity_summary` sections.
- **Change:** register surface `bh_crm_profile` with slots `header`/`main`/`sidebar`, context `user_id`; call `render_slot('bh_crm_profile', $uid, 'sidebar')` etc. New elements (`bhcrm/field-value`, `bh/stat-card` bound to `bhcore_events.count` for that user) become placeable.
- **Stays:** the existing `bh_crm_activity_summary` filter and profile table remain — in fact `bhcrm.activity_summary` data source just re-exposes that filter to elements. No CRM rewrite.

### 5.3 LMS (bh-courses) — **interop, do NOT subsume**
- **The explicit decision:** the element builder does **not** replace `BH_Content`/`BH_Studio` lesson *body* authoring (that's `LMS-AUTHORING-DESIGN-PLAN.md`'s job and already half-built via `BHC_ContentBridge`). Duplicating it would recreate the "second content model" that doc rejects.
- **What the element builder adds:** the lesson **front-end interface / chrome** — progress bar, "next up," gate/unlock notices, portal-style surrounds — as surface `bhc_lesson_ui` (context = lesson post id), with data-bound elements reading `BHC_Progress`. The authored lesson body renders *inside* a `bh/lesson-body` element that simply calls `BH_Content::render()` on the lesson's existing `bhc_lesson` tree.
- **Stays:** `BHC_Progress`, `BHC_Render`, `BHC_Gate`, the whole bridge — untouched. Element builder frames them; it doesn't author them.

### 5.4 Portal (`BHI_Portal`, `class-portal.php`)
- **Today:** panels self-register via `bhi_portal_panels` (`id`/`label`/`render`/`priority`/`icon`); one active panel body renders.
- **Change:** ship one new panel type — an **element-composed panel** whose `render` callback calls `BH_Element::render_slot('portal_panel', $panel_context, 'body')`. Admins compose that panel's contents from elements. Register surface `portal_panel` accordingly.
- **Stays:** the entire `bhi_portal_panels` contract and every existing hand-coded panel. Element panels are just one more registrant among them — matching the ecosystem's "one real migrated example, not every consumer at once" discipline (exactly how the portal shipped with a single migrated profile panel).

---

## 6. Phased build order

Each phase is independently shippable and testable, smallest real slice first — mirroring `LMS-AUTHORING-DESIGN-PLAN.md`'s sequencing discipline.

1. **Registry + one static slot on the dashboard, no GUI, no binding.** Ship `BH_Element` (`register_type`, `render_slot`, `get/save_placement`), the `bhcore_element_placements` table (`DB_VERSION` 1.9), the `bh_element_surfaces` filter, one element type `bh/note` (static rich text, `surfaces=['dashboard']`), and one `render_slot('dashboard',0,'main')` call. Placements managed via a bare Debug Tools list (add/remove/reorder). This proves the storage + render + capability spine end-to-end with the least surface area. **Testable:** a note added via Debug Tools appears on the dashboard.

2. **The builder GUI.** Clone `BHY_Gallery`'s three-pane page: palette (from `types_for_surface`), live iframe canvas (`preview_doc` technique), inspector with the **Style section reusing `BHY_UI` pickers** and the attrs section. Drag-reorder within a slot. REST routes from §3.4. Still generic/static elements only. **Testable:** author a note visually, restyle it with the token pickers, see the canvas update live.

3. **Data binding.** Ship `BH_Element_Data`, register the four first-party sources (§3.2) wrapping `BH_Event`/`BHI_Profiles`, add the inspector Data section + `POST preview`, and the first bound element `bh/stat-card`. **Testable:** a stat-card on the dashboard shows a real `bhcore_events` count; bound to `context.user_id` it shows a per-user count.

4. **Surface expansion.** Register `bh_crm_profile` (§5.2) and `portal_panel` (§5.4); ship `bhcrm/field-value` and the element-composed portal panel. **Testable:** a CRM profile sidebar stat-card and a portal panel built from elements.

5. **Container elements + `BH_Content` bridge (page-build-lite).** Ship `bh/section`/`bh/card` containers whose `content_context_id` opens the existing `BH_Studio` canvas; ship `bhc_lesson_ui` with the `bh/lesson-body` element (§5.3). **Testable:** a container element holding a freely-composed `BH_Content` subtree renders inside a slot; a lesson front-end frame wraps the authored body.

6. **Version-history seam only.** Confirm `revision_of`/`parent_placement_id` columns are wired for no-op today and documented for the future §7a service. **No versioning logic** — this phase is a checkpoint, not a build.

---

## 7. Out of scope / not decided here

Named honestly, in the "left alone, with reasoning" spirit of the sibling docs:

- **The in-admin version-history system itself (§2.3 / ROADMAP §7a).** Only the schema seam (`revision_of`) is provided. Storage choice, diffing, and restore UI are explicitly a separate future pass.
- **Rich WYSIWYG text editing built from scratch.** Deliberately avoided — richtext/container content routes to the **existing** `BH_Studio` canvas (`RichText`), so no second text editor exists. If a lighter inline editor is ever wanted, that's a separate call.
- **Front-end (non-`manage_options`) authoring.** All REST routes gate on `manage_options`, matching `BH_Studio`. "Let a supporter design their own profile/landing page" (roadmap ambition) needs a deliberate per-context capability decision and is **not** loosened here by accident — same explicit boundary `LMS-AUTHORING-DESIGN-PLAN.md` drew.
- **True top-level arbitrary nesting (§1.1's tree mode).** The `parent_placement_id` seam is present but unbuilt; flip only on user confirmation.
- **Binding write-back / interactive elements.** Sources are **read-only** — elements display data, they don't submit forms or mutate CRM records. Interactive/form elements are a later, separate capability.
- **Per-visitor personalization beyond context tokens.** Bindings resolve against `user_id`/`post_id`/`viewer_id`; segment/audience targeting is out of scope.
- **Wholesale migration of existing hardcoded surfaces.** Per the ecosystem's "one real example, not every consumer at once" rule, each surface adopts slots incrementally; nothing forces the dashboard's existing cards or CRM's existing tables to become elements.

---

## Critical files for implementation

- `own-ur-shit/includes/class-style-gallery.php` + `class-ui.php` — the Storybook three-pane GUI and the exact token-picker widgets (`swatch_field`/`font_field`/`slider_row`/`swatch_js`) the builder's inspector reuses verbatim.
- `own-ur-shit/includes/class-studio.php` + `assets/js/studio.js` — the no-build Gutenberg-primitives canvas the container elements embed for page-build-lite nesting, and the REST-route pattern the element builder's routes copy.
- `own-ur-shit/includes/class-content.php` — the `{type,attrs,children}` tree/schema/storage contract container innards reuse (`bh_element` context) and whose `register_block_type()` shape `BH_Element::register_type()` mirrors.
- `own-ur-shit/includes/class-event.php` + `class-style.php` — the real data sources bound elements read (`bhcore_events` counts) and the design-token system (`--bh-*` vars, per-entity overrides) elements are styled with.
- `own-ur-shit/includes/class-identity-activator.php` — where the new `bhcore_element_placements` table and `DB_VERSION` 1.9 bump land, alongside `bhcore_content`/`bhcore_events`.
