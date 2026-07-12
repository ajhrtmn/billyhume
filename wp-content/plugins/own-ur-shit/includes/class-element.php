<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element — the placement/capability/render layer of the "element
 * builder" (ELEMENT-BUILDER-DESIGN-PLAN.md Section 3.1). Implements
 * Phase 1 (registry + one static slot, no GUI) and Phase 2 (real data
 * binding via BH_Element_Data) of that doc's phased build order — see
 * the doc's Section 6. The visual drag/drop builder GUI (the doc's
 * Phase "GUI" step) is NOT part of this pass; placements are managed
 * here through a bare Debug Tools list (add/remove/reorder), same as
 * the doc's Phase 1 says: "Placements managed via a bare Debug Tools
 * list (add/remove/reorder). This proves the storage + render +
 * capability spine end-to-end with the least surface area."
 *
 * Element TYPES are registered in code (register_type()), never in the
 * DB — same as BH_Content block types and BH_Event event types. A type
 * ships with a plugin and degrades gracefully (renders nothing) if that
 * plugin later deactivates, exactly like BH_Content::render() skips an
 * unregistered block type.
 *
 * Element PLACEMENTS are stored in bhcore_element_placements (see
 * class-identity-activator.php DB_VERSION 1.9) — "this type, configured
 * this way, sits in this slot on this surface for this context, at this
 * position."
 *
 * NOT runtime-verified: reasoned through against BH_Content/BH_Event's
 * existing, working shape, and brace/logic-checked, but no live PHP/
 * MySQL/WordPress execution is available in this environment. Please
 * smoke-test register_type()+render_slot()+save_placement() against a
 * real dashboard load before relying on this in production.
 *
 * 3.4.22 adds the design doc's §3.4 REST bridge (read/write placements,
 * list types/surfaces/sources, a preview-render endpoint) and wires the
 * `bh_crm_profile` surface (design doc §5.2) — see this class's
 * register_routes()/rest_*() methods below and BHCRM_People::render_detail()
 * (bh-crm/includes/class-people.php) for the surface call sites. This is
 * still NOT the visual drag/drop builder GUI (§4 of the doc) — the REST
 * routes exist so a future GUI (or any REST client) can read/write
 * placements; there is still no JS canvas shipped this pass. Placements on
 * the new surface are managed exactly as before: the bare Debug Tools list,
 * now scoped-selectable per surface/context (see render_debug_section()).
 *
 * 3.4.34 activates the `parent_placement_id` seam that class-identity-
 * activator.php's DB_VERSION 1.9 table definition already reserved but
 * left unused (see that file's own updated comment on the column) — a
 * real, shallow tree of placements WITHIN one surface/slot, closing the
 * "Pages" rail's honestly-disclosed flat-list gap (DESIGN-SUITE-
 * UNIFICATION-PLAN.md §2). `save_placement()` now enforces two invariants
 * on a non-zero `parent_placement_id`: the parent must live in the exact
 * same (surface, surface_context_id, slot) — no cross-slot trees — and
 * (on an update) accepting the new parent must not create a cycle (see
 * `would_create_cycle()`), fail-closed (the save is rejected, returns
 * false) rather than silently accepting either violation.
 * `render_slot()`/`render_placement()` are now tree-aware: `render_slot()`
 * fetches every placement for the slot in ONE query (unchanged —
 * `get_placements()` already did this), builds an in-memory parent id =>
 * children array map, and renders only the roots (parent_placement_id
 * === 0), with `render_placement()` recursing into each node's own
 * children and appending their rendered HTML inside a `.bh-element-
 * children` wrapper — never a second query per tree level. This
 * placement-nesting tree is a SEPARATE, shallower mechanism from the
 * pre-existing `bh/container` -> `BH_Content` bridge (a container's
 * "nested content" still opens the Content Studio canvas for its own
 * `BH_Content` sub-tree, addressed by `content_context_id`, untouched by
 * this pass) — the two nesting mechanisms coexist and are never
 * conflated. REST: `GET .../placements` is UNCHANGED (still the flat,
 * grouped-by-slot shape every existing consumer — `BH_Element_Prefab`,
 * the bare Debug Tools list, `element-builder.js`'s own state array —
 * already depends on); each row already carries its own real
 * `parent_placement_id` column value (a `SELECT *`), so the CLIENT builds
 * the tree from the flat array it already receives rather than this
 * route growing a second, parallel nested shape. `POST .../placements`
 * now reads and persists a `parent_placement_id` per entry and computes
 * `position` PER PARENT GROUP (sibling-scoped) instead of by raw array
 * index — see `rest_save_placements()`'s own comment for the exact
 * contract. `delete_placement()` now promotes any children of a deleted
 * placement back to root (parent_placement_id reset to 0) rather than
 * leaving them pointing at a now-nonexistent parent id.
 *
 * 3.4.36 implements the DESIGN-SUITE-UNIFICATION-PLAN.md "FINAL
 * ARCHITECTURE" note: one left-rail tree per surface, rooted at a
 * synthetic client-side "Site" node (this class emits no new row/id for
 * it — see element-builder.js's own updated file docblock for exactly
 * how the client recognizes it; server-side, `parent_placement_id === 0`
 * still means "root of this surface's tree", unchanged). This class
 * itself only gained two small read helpers this pass — `get_placement()`
 * (single-row fetch) and `get_subtree()` (root-first flat walk of a node
 * plus every descendant) — to back `BH_Element_Prefab`'s new full-subtree
 * snapshot/restore. Every write path above (save_placement(),
 * delete_placement(), reorder(), the REST routes) is UNCHANGED.
 */
class BH_Element {
    /** @var array<string, array> slug => manifest (including the 'render' callable) */
    private static $types = [];

    /** @var array<string, array> surface slug => ['group'=>,'label'=>,'slots'=>[...],'context'=>,'preview_ctx'=>callable] */
    private static $surfaces_cache = null;

    const VALID_ATTR_KINDS = ['scalar', 'list', 'richtext', 'url', 'series'];

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_element_debug_action', [self::class, 'handle_debug_post']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        self::register_default_types();
    }

    /**
     * First-party element types own-ur-shit itself ships (design doc
     * §6 Phase 1's 'bh/note' plus Phase 2's demonstration 'bh/stat-
     * card'). Peer plugins register their OWN types the same
     * class_exists('BH_Element')-guarded way from their own bootstrap —
     * this method only covers the two types this pass needs to prove
     * the spine end-to-end; it is deliberately not an exhaustive
     * element library.
     */
    private static function register_default_types() {
        self::register_type('bh/note', [
            'label'    => 'Note',
            'category' => 'text',
            'icon'     => 'dashicons-media-text',
            'surfaces' => '*', // generic static text, valid anywhere — Phase 1's minimal proof element
            'container' => false,
            'schema' => [
                'text' => ['type' => 'html', 'default' => '', 'bindable' => false],
            ],
            'style' => ['color_accent', 'radius'],
            // §2.6 — a generic content block: several structural tags make
            // sense, no link-shaped attrs are meaningful.
            'tags'  => ['div', 'section', 'article', 'aside'],
            'attrs' => ['id' => true, 'class' => true, 'title' => true, 'aria-label' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                // $attrs['text'] was already wp_kses_post()-coerced by
                // BH_Element::coerce_attr() per its schema 'type' => 'html'
                // above — safe to print without a second escape pass (an
                // additional esc_html() here would mangle the allowed
                // markup kses already sanitized down to).
                $text = $attrs['text'] !== '' ? $attrs['text'] : '<em>(empty note)</em>';
                return '<div class="bh-el-note">' . $text . '</div>';
            },
        ]);

        // The design doc's §1.1 hybrid-nesting bridge, built out this
        // pass: a container element owns no attrs of its own beyond an
        // optional heading — its real "content" is an embedded BH_Content
        // subtree at ('bh_element', content_context_id), edited via the
        // EXISTING BH_Studio canvas (ous/v1/studio/bh_element/{id}), not a
        // second tree editor. save_placement() below auto-assigns
        // content_context_id = the placement's own id the first time a
        // container placement is saved (see save_placement()'s docblock),
        // so there is exactly one BH_Content document per container
        // placement, addressable the moment the placement itself exists.
        self::register_type('bh/container', [
            'label'    => 'Container',
            'category' => 'layout',
            'icon'     => 'dashicons-layout',
            'surfaces' => '*', // a generic layout shell — valid on every surface, same as bh/note
            'container' => true,
            'schema' => [
                'heading' => ['type' => 'string', 'default' => '', 'bindable' => false],
            ],
            'style' => ['color_accent', 'radius', 'space_scale'],
            // §2.6 — a layout shell: 'section'/'article' are both
            // reasonable semantic choices for a themed content grouping.
            'tags'  => ['div', 'section', 'article'],
            'attrs' => ['id' => true, 'class' => true, 'aria-label' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                $heading = trim((string) $attrs['heading']);
                $heading_html = $heading !== '' ? '<h3 class="bh-el-container-heading">' . esc_html($heading) . '</h3>' : '';
                // $instance['content'] is already-rendered HTML from
                // BH_Content::render() on this placement's nested tree —
                // see BH_Element::render_placement()'s container branch.
                // It is deliberately NOT re-escaped here (it's a rendered
                // HTML fragment, not a text node), matching the same
                // reasoning bh/note's docblock gives for its own 'html'
                // schema-typed attr.
                return '<div class="bh-el-container">' . $heading_html . $instance['content'] . '</div>';
            },
        ]);

        self::register_type('bh/stat-card', [
            'label'    => 'Stat card',
            'category' => 'data',
            'icon'     => 'dashicons-chart-bar',
            'surfaces' => ['dashboard', 'bh_crm_profile'], // per design doc §5.1/§5.2's named targets
            'container' => false,
            'schema' => [
                'label' => ['type' => 'string', 'default' => 'Stat',    'bindable' => false],
                'value' => ['type' => 'string', 'default' => '—',       'bindable' => true, 'kind' => 'scalar'],
            ],
            'style' => ['color_accent', 'radius', 'space_scale'],
            // §2.6's demonstration of tag-choice + href/target: a stat
            // card is a plausible "click through to the detail view" CTA,
            // so it opts into rendering as an <a> with href/target/rel —
            // while still defaulting to a plain <div> for the common
            // non-linked case (tags' first entry is always the default).
            'tags'  => ['div', 'a'],
            'attrs' => [
                'id' => true, 'class' => true, 'aria-label' => true,
                'href'   => ['type' => 'url'],
                'target' => ['enum' => ['_self', '_blank']],
                'rel'    => true,
            ],
            // §3.2 v1 — this is THE live demo element the design doc's
            // Phase 5 "testable" line names ("a live stat-card refreshes
            // its bound count without a page reload"). Its 'value' attr
            // is already the one thing on this whole install genuinely
            // bound to a live-changing source (bhcore_events.count —
            // see render_add_bound_demo_button()'s handler below), which is
            // exactly why it was picked rather than inventing a new
            // element just for this.
            'live' => true,
            'render' => function (array $attrs, array $ctx, array $instance) {
                // 'label' is schema type 'string' -> coerce_attr() already
                // cast it to a plain string, but a string coercion is NOT
                // an escape — esc_html() both attrs here explicitly, since
                // this render callable is the actual HTML text-node output
                // boundary BH_Element_Data::resolve()'s docblock says is
                // the caller's job, not the resolver's.
                //
                // 'data-bhel-bind="value"' is the ONLY new thing this
                // pass adds to this render callable — a stable, specific
                // hook for element-live.js to patch in place (querying
                // for it inside this placement's data-placement-id
                // wrapper) rather than replacing the whole wrapper's
                // innerHTML, which would also stomp the label.
                return '<div class="bh-el-stat-card">'
                    . '<div class="bh-el-stat-card-value" data-bhel-bind="value">' . esc_html((string) $attrs['value']) . '</div>'
                    . '<div class="bh-el-stat-card-label">' . esc_html((string) $attrs['label']) . '</div>'
                    . '</div>';
            },
        ]);

        self::register_generic_primitives();
    }

    /**
     * Direct response to AJ's own framing, verbatim: "everything is
     * custom and not preregistered unless it's from a plugin and is just
     * being styled and not defined." Before this, own-ur-shit's own
     * built-in palette was exactly two generic primitives (bh/note,
     * bh/container above) plus two plugin-owned, genuinely DATA-BOUND
     * widgets (bh/stat-card here, bh-crm's bh/sticky-card) — nowhere
     * near enough to build a real page without reaching for a plugin
     * that has no business owning a plain heading or button. register_
     * type() itself isn't going away (a real data-bound widget like
     * stat-card legitimately needs PHP: a schema, a bindable attr, a
     * render callable), but that mechanism should be reserved for things
     * that actually need code behind them — not for basic building
     * blocks every builder ships intrinsically (Wix/Webflow/HubSpot all
     * have a small fixed set of true primitives — Div, Text, Image,
     * Button, Section — that were never something a "plugin" declared).
     *
     * These five are that fixed set for THIS ecosystem: heading, image,
     * button, divider, and a plain generic block (bh/note, above,
     * already covers freeform rich text). All 'surfaces' => '*' (usable
     * everywhere, no plugin opt-in needed), all schema attrs 'bindable'
     * => false EXCEPT where a real content field makes sense to someday
     * bind (image src, button label/href) — left false for now since
     * data-binding v1 (BH_Element_Data) only resolves scalar 'value'-
     * shaped binds today; flip these on later without any other change
     * once that's proven out further, per the design doc's own v2/v3
     * status notes.
     */
    private static function register_generic_primitives() {
        self::register_type('bh/heading', [
            'label'    => 'Heading',
            'category' => 'text',
            'icon'     => 'dashicons-heading',
            'surfaces' => '*',
            'container' => false,
            'schema' => [
                'text' => ['type' => 'string', 'default' => 'Heading', 'bindable' => false],
            ],
            'style' => ['color_accent', 'space_scale'],
            // §2.6 — the tag picker IS the "h1 vs h2 vs h3" choice; no
            // separate 'level' attr needed, same mechanism bh/stat-card
            // already uses to let a placement choose div vs a.
            'tags'  => ['h2', 'h1', 'h3', 'h4', 'h5', 'h6'],
            'attrs' => ['id' => true, 'class' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                return esc_html((string) $attrs['text']);
            },
        ]);

        self::register_type('bh/image', [
            'label'    => 'Image',
            'category' => 'media',
            'icon'     => 'dashicons-format-image',
            'surfaces' => '*',
            'container' => false,
            'schema' => [
                'src' => ['type' => 'url',    'default' => '', 'bindable' => false],
                'alt' => ['type' => 'string', 'default' => '', 'bindable' => false],
            ],
            'style' => ['radius'],
            'tags'  => ['div'], // the <img> itself is the render output; the wrapper tag has no other reasonable choice
            'attrs' => ['id' => true, 'class' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                $src = (string) $attrs['src'];
                if ($src === '') return '<div class="bh-el-image bh-el-image-empty">' . esc_html__('No image set', 'own-ur-shit') . '</div>';
                return '<img class="bh-el-image" src="' . esc_url($src) . '" alt="' . esc_attr((string) $attrs['alt']) . '">';
            },
        ]);

        self::register_type('bh/button', [
            'label'    => 'Button',
            'category' => 'text',
            'icon'     => 'dashicons-button',
            'surfaces' => '*',
            'container' => false,
            'schema' => [
                'label' => ['type' => 'string', 'default' => 'Click here', 'bindable' => false],
            ],
            'style' => ['color_accent', 'radius'],
            // §2.6 — a button is the canonical "sometimes a link, usually
            // an actual button" case: 'a' first (the common CTA use),
            // 'button' available when it should submit/trigger JS instead
            // of navigating.
            'tags'  => ['a', 'button'],
            'attrs' => [
                'id' => true, 'class' => true, 'aria-label' => true,
                'href'   => ['type' => 'url'],
                'target' => ['enum' => ['_self', '_blank']],
                'rel'    => true,
            ],
            'render' => function (array $attrs, array $ctx, array $instance) {
                return esc_html((string) $attrs['label']);
            },
        ]);

        self::register_type('bh/divider', [
            'label'    => 'Divider',
            'category' => 'layout',
            'icon'     => 'dashicons-minus',
            'surfaces' => '*',
            'container' => false,
            'schema' => [],
            'style' => ['color_accent', 'space_scale'],
            'tags'  => ['hr'],
            'attrs' => ['id' => true, 'class' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                return ''; // <hr> is a void element — wrap_placement_html()'s new void-tag branch renders it with no closing tag/children regardless of what's returned here
            },
        ]);
    }

    /* =================================================================
     * §3.1 — type registry
     * ================================================================= */

    /**
     * @param string $slug Namespaced type, e.g. 'bh/stat-card', 'bh/note'.
     * @param array  $args {
     *   'label'     => 'Stat card',
     *   'category'  => 'data'|'layout'|'text'|'media',
     *   'icon'      => 'dashicons-chart-bar',
     *   'surfaces'  => ['dashboard','bh_crm_profile'] | '*',   // §1.3 capability manifest
     *   'container' => false,                                  // does it own a BH_Content subtree?
     *   'schema'    => [
     *        'value' => ['type' => 'string', 'default' => '', 'bindable' => true,  'kind' => 'scalar'],
     *        'label' => ['type' => 'string', 'default' => '', 'bindable' => false],
     *   ],
     *   'style'     => ['color_accent','radius','space_scale'],
     *   'attrs'     => [                                        // §2.6 — HTML-attribute manifest, additive/optional
     *        'id' => true, 'class' => true, 'aria-label' => true,
     *        'href' => ['type' => 'url'], 'target' => ['enum' => ['_self','_blank']],
     *        'data-status' => ['enum' => ['todo','in_progress','done']], // a pre-declared, structured data-* attr
     *        'data-*' => true,                                   // opts into the freeform custom data-attr row editor
     *   ],
     *   'tags'      => ['div','section','article'],              // §2.6 — allowed semantic tags; first = default. Omitted -> ['div'] (today's implicit behavior, unchanged)
     *   'render'    => function(array $attrs, array $ctx, array $instance): string { ... },
     * }
     * @return bool true if accepted, false if rejected (missing 'render' callable — logged, never fatal).
     */
    public static function register_type($slug, array $args) {
        $slug = trim((string) $slug);
        if ($slug === '') return false;

        if (empty($args['render']) || !is_callable($args['render'])) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', "register_type($slug): registered without a callable 'render' — type will not be usable until fixed", [], 'bh-element');
            }
            return false;
        }

        $surfaces = $args['surfaces'] ?? [];
        if ($surfaces !== '*' && !is_array($surfaces)) $surfaces = [];

        $tags = (!empty($args['tags']) && is_array($args['tags'])) ? array_values(array_map('sanitize_key', $args['tags'])) : ['div'];
        if (!$tags) $tags = ['div']; // never allow an empty tag list to collapse resolve_tag() to nothing

        self::$types[$slug] = [
            'label'     => (string) ($args['label'] ?? $slug),
            'category'  => (string) ($args['category'] ?? 'layout'),
            'icon'      => (string) ($args['icon'] ?? 'dashicons-admin-generic'),
            'surfaces'  => $surfaces,
            'container' => !empty($args['container']),
            'schema'    => is_array($args['schema'] ?? null) ? $args['schema'] : [],
            'style'     => is_array($args['style'] ?? null) ? $args['style'] : [],
            // §2.6 — per-type HTML-attribute allowlist. A type with no
            // 'attrs' key gets a minimal default (id/class/aria-label);
            // it never gets href/data-* etc. unless it opts in — same
            // "manifest opt-in" pattern the existing 'style' list uses.
            'attrs'     => is_array($args['attrs'] ?? null) ? $args['attrs'] : ['id' => true, 'class' => true, 'aria-label' => true],
            'tags'      => $tags,
            'render'    => $args['render'],
            // DESIGN-SUITE-UNIFICATION-PLAN.md §3.2 v1 — opt-in manifest
            // flag: does this type's rendered wrapper get a data-bhel-
            // live="1" marker (wrap_placement_html()) so the front-end
            // JS helper (assets/js/element-live.js) periodically re-
            // POSTs to POST ous/v1/elements/resolve and patches its
            // bound value in place, without a full page reload? Most
            // types stay non-live (cheap, static) — only ones that
            // genuinely benefit opt in, same "manifest opt-in" posture
            // 'attrs'/'tags' already use.
            'live'      => !empty($args['live']),
        ];
        return true;
    }

    public static function registered_types() {
        return self::$types;
    }

    public static function get_type($slug) {
        return self::$types[(string) $slug] ?? null;
    }

    public static function is_container($slug) {
        $type = self::get_type($slug);
        return $type ? $type['container'] : false;
    }

    /** Manifest-admitted types for a surface — the single source of truth the palette (and Phase-1's Debug Tools "add" form) reads. */
    public static function types_for_surface($surface) {
        $surface = (string) $surface;
        $out = [];
        foreach (self::$types as $slug => $type) {
            $admitted = $type['surfaces'] === '*' || in_array($surface, $type['surfaces'], true);
            if (!$admitted) continue;
            if (!apply_filters('bh_element_can_place', true, $slug, $surface, null)) continue;
            $out[$slug] = $type;
        }
        return $out;
    }

    /* =================================================================
     * §3.3 — surface + capability contract
     * ================================================================= */

    /** Surfaces self-register their own slots via this filter — same shape as bhy_style_surfaces/bhi_portal_panels. Cached per-request (the filter's registrants don't change mid-request). */
    public static function registered_surfaces() {
        if (self::$surfaces_cache === null) {
            self::$surfaces_cache = apply_filters('bh_element_surfaces', []);
            if (!is_array(self::$surfaces_cache)) self::$surfaces_cache = [];
        }
        return self::$surfaces_cache;
    }

    public static function get_surface($surface) {
        $surfaces = self::registered_surfaces();
        return $surfaces[(string) $surface] ?? null;
    }

    /* =================================================================
     * §2.1 — placement storage (thin wrappers over bhcore_element_placements)
     * ================================================================= */

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_element_placements';
    }

    /**
     * @param string   $surface
     * @param int      $context_id 0 for a singleton surface (global dashboard); else the entity id.
     * @param string|null $slot    Restrict to one slot, or null for every slot on this (surface, context).
     * @return array Ordered rows (position ASC), each with 'config' already json_decode()d to an array.
     */
    public static function get_placements($surface, $context_id, $slot = null) {
        global $wpdb;
        $table = self::table();
        $context_id = (int) $context_id;

        if ($slot !== null) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE surface = %s AND surface_context_id = %d AND slot = %s AND enabled = 1 ORDER BY position ASC, id ASC",
                (string) $surface, $context_id, (string) $slot
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE surface = %s AND surface_context_id = %d AND enabled = 1 ORDER BY slot ASC, position ASC, id ASC",
                (string) $surface, $context_id
            ), ARRAY_A);
        }

        if (!$rows) return [];

        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['config'], true);
            $row['config'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    /**
     * Single-row fetch by id, config already json_decode()d — the
     * counterpart to get_placements()' multi-row form, added 3.4.36 for
     * the subtree-prefab work (BH_Element_Prefab::save_from_node()) and
     * any future single-node lookup (e.g. resolving a right-click
     * "add child" target). Returns null on an unknown id.
     */
    public static function get_placement($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id
        ), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode((string) $row['config'], true);
        $row['config'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /**
     * Full subtree of $id (itself + every recursive descendant),
     * root-first (parent always appears before any of its children) —
     * added 3.4.36 for BH_Element_Prefab's full-subtree snapshot/restore
     * (DESIGN-SUITE-UNIFICATION-PLAN.md's "FINAL ARCHITECTURE" note,
     * decision #3). Fetches the WHOLE (surface, surface_context_id, slot)
     * placement set in ONE query (get_placements()) and walks the same
     * in-memory parent=>children map render_slot() already builds — no
     * per-level query, same performance posture as rendering.
     *
     * @param int $id
     * @return array Root-first flat list of rows (each with 'config' decoded), or [] if $id is unknown.
     */
    public static function get_subtree($id) {
        $root = self::get_placement($id);
        if (!$root) return [];

        $all = self::get_placements($root['surface'], (int) $root['surface_context_id'], $root['slot']);
        $children_by_parent = self::group_by_parent($all);

        $out = [];
        $stack = [$root];
        while ($stack) {
            $node = array_shift($stack);
            $out[] = $node;
            $node_id = (int) $node['id'];
            if (!empty($children_by_parent[$node_id])) {
                // Prepend children right after their parent so the overall
                // list stays root-first / depth-first (array_shift() above
                // always consumes from the front) — order among siblings
                // preserved from group_by_parent()'s own position-ordered input.
                array_splice($stack, 0, 0, $children_by_parent[$node_id]);
            }
        }
        return $out;
    }

    /**
     * Insert or update a placement row. $placement may include 'id' to
     * update an existing row; otherwise a new row is inserted.
     * 'config' may be passed as an array (encoded here) or a JSON string.
     * Container placements (type's 'container' === true) whose
     * content_context_id is still 0 get one auto-assigned on insert,
     * using the placement's OWN new id as the BH_Content context id —
     * see the trailing block below. This means a container placement is
     * addressable at BH_Content context ('bh_element', $id) the moment it
     * exists, with no separate "allocate a content context" step the
     * caller (REST client or Debug Tools form) would otherwise have to
     * orchestrate itself.
     *
     * @return int|false the placement id, or false on failure.
     */
    public static function save_placement(array $placement) {
        global $wpdb;
        $table = self::table();

        $config = $placement['config'] ?? [];

        // AJ's own ask, folded into the bh-contest conversion: real JS
        // scripting via the builder, not raw-PHP authoring (that second
        // one stays a hard no — see this pass's chat response for why:
        // arbitrary server-side code from an admin text field is how a
        // site gets owned). config['custom_js'] IS a real capability now
        // (wrap_placement_html() renders it, scoped to this one
        // placement's own DOM node) — but it's the single most
        // dangerous thing a placement can carry, since it runs as real
        // JavaScript on the live site for every visitor. Gated here, at
        // the ONE write path every caller (REST save, Debug Tools,
        // prefab apply) funnels through, not just in the GUI — a
        // non-privileged caller can never smuggle a script in by
        // crafting the request directly, even if the inspector's own UI
        // gate (checked client-side too, for a better error message) is
        // bypassed entirely.
        if (is_array($config) && !empty($config['custom_js']) && !current_user_can('bhcore_author_custom_js')) {
            $config['custom_js'] = '';
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'save_placement(): custom_js stripped — current user lacks bhcore_author_custom_js', ['surface' => $placement['surface'] ?? ''], 'BH_Element');
            }
        }

        if (is_array($config)) $config = wp_json_encode($config);
        if (!is_string($config)) $config = '{}';

        $data = [
            'surface'             => sanitize_key($placement['surface'] ?? ''),
            'surface_context_id'  => (int) ($placement['surface_context_id'] ?? 0),
            'slot'                => sanitize_key($placement['slot'] ?? ''),
            'position'            => (int) ($placement['position'] ?? 0),
            'element_type'        => trim((string) ($placement['element_type'] ?? '')),
            'config'              => $config,
            'content_context_id'  => (int) ($placement['content_context_id'] ?? 0),
            'enabled'             => !empty($placement['enabled']) ? 1 : (isset($placement['enabled']) ? 0 : 1),
            'parent_placement_id' => (int) ($placement['parent_placement_id'] ?? 0), // 3.4.34 — the real placement-tree seam; 0 = root/top-level within the slot, enforced/validated below
            'revision_of'         => (int) ($placement['revision_of'] ?? 0),          // unused seam, §2.3 — always 0 today
        ];

        if (!$data['surface'] || !$data['slot'] || !$data['element_type']) return false;

        // 3.4.34 — parent_placement_id invariants, enforced HERE (the one
        // write path both the REST save route and the Debug Tools/prefab
        // callers all funnel through), fail-closed on either violation:
        //   1. A non-root parent must live in the EXACT same (surface,
        //      surface_context_id, slot) as this placement — the tree is
        //      scoped to one slot, never cross-slot.
        //   2. Accepting the new parent must not create a cycle (this
        //      placement becoming its own ancestor) — only checked on an
        //      UPDATE (a brand-new insert, id === 0, can never already be
        //      an ancestor of anything).
        if ($data['parent_placement_id'] > 0) {
            $parent_row = $wpdb->get_row($wpdb->prepare(
                "SELECT surface, surface_context_id, slot FROM $table WHERE id = %d",
                $data['parent_placement_id']
            ), ARRAY_A);
            if (!$parent_row
                || $parent_row['surface'] !== $data['surface']
                || (int) $parent_row['surface_context_id'] !== $data['surface_context_id']
                || $parent_row['slot'] !== $data['slot']
            ) {
                return false; // unknown parent, or a parent living in a different surface/context/slot — never a supported shape
            }
            if (!empty($placement['id']) && self::would_create_cycle((int) $placement['id'], $data['parent_placement_id'])) {
                return false; // this update would make the placement its own ancestor — rejected, not silently truncated/ignored
            }
        }

        $type = self::get_type($data['element_type']);

        if (!empty($placement['id'])) {
            $id = (int) $placement['id'];
            // A container placement that somehow still has no
            // content_context_id on an UPDATE (e.g. a hand-crafted REST
            // call) gets the same self-id backfill as the insert path —
            // never left at 0 for a container type once it has a real id.
            if ($type && $type['container'] && $data['content_context_id'] === 0) {
                $data['content_context_id'] = $id;
            }
            $ok = $wpdb->update($table, $data, ['id' => $id]);
            return $ok !== false ? $id : false;
        }

        $ok = $wpdb->insert($table, $data);
        if (!$ok) return false;
        $id = (int) $wpdb->insert_id;

        if ($type && $type['container'] && $data['content_context_id'] === 0) {
            $wpdb->update($table, ['content_context_id' => $id], ['id' => $id]);
        }

        return $id;
    }

    /**
     * 3.4.34 — any child of a deleted placement (a row whose
     * parent_placement_id === $id) is promoted to root (reset to 0)
     * BEFORE the row itself is deleted, so a delete never leaves a
     * dangling parent_placement_id pointing at a now-nonexistent row.
     */
    public static function delete_placement($id) {
        global $wpdb;
        $id = (int) $id;
        $table = self::table();
        $wpdb->update($table, ['parent_placement_id' => 0], ['parent_placement_id' => $id]);
        return (bool) $wpdb->delete($table, ['id' => $id]);
    }

    /**
     * True if $proposed_parent_id IS $placement_id, or is a descendant of
     * it walked the OTHER direction (i.e. accepting $proposed_parent_id as
     * $placement_id's new parent would make $placement_id its own
     * ancestor). Walks parent_placement_id upward from $proposed_parent_id
     * with a hard hop cap (200) — never an infinite loop even against
     * already-corrupted data, and fails safe (returns false, i.e. "not a
     * cycle") on an unresolved/broken chain rather than refusing every
     * future parent assignment because of one bad row elsewhere.
     */
    private static function would_create_cycle($placement_id, $proposed_parent_id) {
        $placement_id = (int) $placement_id;
        $proposed_parent_id = (int) $proposed_parent_id;
        if ($placement_id <= 0 || $proposed_parent_id <= 0) return false;
        if ($placement_id === $proposed_parent_id) return true;

        global $wpdb;
        $table = self::table();
        $seen = [];
        $current = $proposed_parent_id;
        $hops = 0;
        while ($current && $hops < 200) {
            if ($current === $placement_id) return true;
            if (isset($seen[$current])) return false; // an existing cycle elsewhere in the data — not this call's problem to solve, bail out rather than loop forever
            $seen[$current] = true;
            $current = (int) $wpdb->get_var($wpdb->prepare("SELECT parent_placement_id FROM $table WHERE id = %d", $current));
            $hops++;
        }
        return false;
    }

    /** Groups an already-fetched flat placement array by parent_placement_id (0 = root) — the in-memory map render_slot()/build_tree() build ONCE per call rather than re-querying per tree level. */
    private static function group_by_parent(array $placements) {
        $map = [];
        foreach ($placements as $p) {
            $parent = (int) ($p['parent_placement_id'] ?? 0);
            $map[$parent][] = $p;
        }
        return $map;
    }

    /**
     * Rewrites 'position' for every id in $ordered_ids (0-based, array
     * order) within one (surface, context, slot, parent) — 3.4.34 scopes
     * reordering to SIBLINGS under the same parent (default $parent_id 0 =
     * root), not the whole slot, so moving elements around under one
     * parent never touches position values under a different parent. IDs
     * not in $ordered_ids are left untouched.
     */
    public static function reorder($surface, $context_id, $slot, array $ordered_ids, $parent_id = 0) {
        global $wpdb;
        $table = self::table();
        $surface = sanitize_key($surface);
        $slot = sanitize_key($slot);
        $context_id = (int) $context_id;
        $parent_id = (int) $parent_id;

        foreach (array_values($ordered_ids) as $position => $id) {
            $wpdb->update(
                $table,
                ['position' => (int) $position],
                ['id' => (int) $id, 'surface' => $surface, 'surface_context_id' => $context_id, 'slot' => $slot, 'parent_placement_id' => $parent_id]
            );
        }
        return true;
    }

    /* =================================================================
     * §3.1 — rendering. This is the binding-resolution call site — see
     * BH_Element_Data::resolve()'s own docblock for the fallback
     * contract this leans on. Every failure mode here is designed to
     * degrade to "this one placement renders nothing" or "renders its
     * static fallback", never a fatal error taking the whole surface
     * down with it.
     * ================================================================= */

    /**
     * Resolve every attr on one placement row against $ctx (bindings via
     * BH_Element_Data::resolve(), literals verbatim, schema defaults for
     * anything absent from config.attrs entirely), then call the type's
     * registered 'render' callable.
     *
     * @param array $placement          One row as returned by get_placements() (config already decoded).
     * @param array $ctx                Render context — user_id, post_id, entity_id, viewer_id, etc.
     * @param array $children_by_parent 3.4.34 — parent_placement_id => [child rows] map, already built ONCE by
     *                                  render_slot() (see that method) — this method never re-queries per level,
     *                                  it just looks up its own id in the map and recurses into whatever it finds.
     * @return string HTML, or '' if the element_type isn't registered (graceful degrade — matches BH_Content::render() skipping unregistered block types) or the type's render callable itself throws.
     */
    public static function render_placement(array $placement, array $ctx = [], array $children_by_parent = []) {
        $type_slug = $placement['element_type'] ?? '';
        $type = self::get_type($type_slug);
        if (!$type) return ''; // unregistered/deactivated plugin — silent, expected, not an error

        $config = $placement['config'] ?? [];
        $attr_values = is_array($config['attrs'] ?? null) ? $config['attrs'] : [];

        $resolved_attrs = [];
        foreach ($type['schema'] as $key => $def) {
            $schema_default = $def['default'] ?? '';
            $raw = $attr_values[$key] ?? null;

            if (is_array($raw) && (isset($raw['bind']) || isset($raw['literal']))) {
                if (!empty($def['bindable']) && class_exists('BH_Element_Data')) {
                    $resolved_attrs[$key] = BH_Element_Data::resolve($raw, $ctx, $schema_default);
                } elseif (isset($raw['literal'])) {
                    // Non-bindable attr but a literal wrapper was still used —
                    // honor the literal rather than discarding authored content
                    // just because the schema never opted this attr into
                    // binding.
                    $resolved_attrs[$key] = $raw['literal'];
                } else {
                    // A 'bind' was stored against a non-bindable attr (stale
                    // config from a schema that changed, or a hand-crafted
                    // placement) — never resolve it, fail safe to the schema
                    // default rather than silently rendering unintended data.
                    $resolved_attrs[$key] = $schema_default;
                }
            } elseif ($raw !== null) {
                // Plain (non-descriptor) stored value — treat as a literal
                // for backward-compatibility with simple hand-authored config.
                $resolved_attrs[$key] = $raw;
            } else {
                $resolved_attrs[$key] = $schema_default;
            }

            $resolved_attrs[$key] = self::coerce_attr($resolved_attrs[$key], $def['type'] ?? 'string');
        }

        // Container elements' inner content lives in BH_Content at
        // ('bh_element', content_context_id) — §2.2. Rendered here and
        // handed to the type's renderer as $instance['content'] so a
        // container 'render' callable can place it however it wants
        // (wrap it, ignore it, etc.) without this class assuming layout.
        //
        // 'content_tree' (checked first, §3.2 Gutenberg-block v1 addition
        // — class-element-prefab.php's render_definition()) is an INLINE
        // tree array, not a content_context_id lookup — used only when
        // rendering a prefab definition that was never persisted as real
        // placement rows (a Gutenberg block preview has no DB-backed
        // content_context_id to fetch). Real DB-backed placements never
        // carry this key, so this branch is a pure no-op for every
        // existing call site (render_slot(), the builder GUI, etc.) —
        // additive only, backward-compatible.
        $inner_html = '';
        if ($type['container'] && isset($placement['content_tree']) && is_array($placement['content_tree']) && class_exists('BH_Content')) {
            $inner_html = BH_Content::render($placement['content_tree']);
        } elseif ($type['container'] && !empty($placement['content_context_id']) && class_exists('BH_Content')) {
            $tree = BH_Content::get('bh_element', (int) $placement['content_context_id']);
            $inner_html = BH_Content::render($tree);
        }

        $instance = [
            'id'      => (int) ($placement['id'] ?? 0),
            'type'    => $type_slug,
            'content' => $inner_html,
            'style'   => is_array($config['style'] ?? null) ? $config['style'] : [],
        ];

        try {
            $inner = (string) call_user_func($type['render'], $resolved_attrs, $ctx, $instance);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', "render_placement() failed for type '$type_slug' (placement #{$instance['id']}): " . $e->getMessage(), [], 'bh-element');
            }
            return ''; // one broken element never takes the whole slot/surface down
        }

        // 3.4.34 — recurse into this placement's OWN children (the
        // parent_placement_id tree — a SEPARATE, shallower mechanism from
        // the bh/container -> BH_Content bridge above, which is keyed off
        // content_context_id, not parent_placement_id; the two coexist and
        // are never conflated). Appended AFTER the type's own render
        // output, inside a distinct '.bh-element-children' wrapper, so a
        // type's render callable never has to know or care whether it has
        // nested placement children — this class owns that concern
        // entirely, same posture as the container/BH_Content split above.
        $placement_id = (int) ($placement['id'] ?? 0);
        if ($placement_id && !empty($children_by_parent[$placement_id])) {
            $children_html = '';
            foreach ($children_by_parent[$placement_id] as $child) {
                $children_html .= self::render_placement($child, $ctx, $children_by_parent);
            }
            if ($children_html !== '') {
                $inner .= '<div class="bh-element-children">' . $children_html . '</div>';
            }
        }

        // §2.3/§2.6 — apply THIS placement's per-instance style +
        // htmlAttrs to its OWN wrapper element (never to $inner, which
        // is the type's own render output and stays exactly what it
        // returned). Inline style="" was chosen over emitting a
        // per-placement <style> block: data-placement-id already gives
        // every wrapper a unique, stable selector, so a <style> block
        // would need to duplicate that same scoping with none of the
        // benefit — an inline attribute is simpler and colocates a
        // placement's override with the element it applies to.
        return self::wrap_placement_html($type, $type_slug, $instance['id'], $config, $inner);
    }

    /**
     * Builds the placement's wrapper tag (per §2.6's semantic-tag-choice
     * feature) around $inner, carrying data-placement-id/data-type, the
     * resolved per-instance style (BHY_Style::scoped_inline_style()),
     * and the strictly-allowlisted htmlAttrs (§2.6). This is the wrapper
     * render_slot() used to build inline as a hardcoded <div> — moved
     * here so REST preview (rest_preview()) gets the identical wrapper
     * a real render_slot() call would produce.
     */
    private static function wrap_placement_html(array $type, $type_slug, $placement_id, array $config, $inner) {
        $style_map  = is_array($config['style'] ?? null) ? $config['style'] : [];
        $html_attrs = is_array($config['htmlAttrs'] ?? null) ? $config['htmlAttrs'] : [];

        $tag = self::resolve_tag($type, $html_attrs);
        $style_decls = ($style_map && class_exists('BHY_Style')) ? BHY_Style::scoped_inline_style($style_map) : '';

        $attr_parts = self::build_html_attrs($type, $html_attrs, $tag);

        // AJ's own ask: "add arbitrary class names and custom CSS to
        // things as needed" — the real, persisted version of that for a
        // placement. custom_class isn't gated behind the type's own
        // 'attrs' => ['class' => true] opt-in the way html_attrs['class']
        // is above (that gate is for AUTHOR-facing "does this type even
        // expose a class field" schema decisions) — this is a Design
        // Suite-level style override, same trust level as every other
        // p.config.style[...] value already going through unescaped here.
        // Appended to whichever class="..." build_html_attrs() already
        // built (always present — 'bh-element' at minimum) rather than
        // emitting a second class attribute, which the browser would
        // silently let the LAST one win, dropping 'bh-element' whenever a
        // custom class was set.
        if (!empty($style_map['custom_class'])) {
            $extra = preg_split('/\s+/', trim((string) $style_map['custom_class']));
            $extra = array_filter(array_map('sanitize_html_class', $extra));
            if ($extra) {
                foreach ($attr_parts as $i => $part) {
                    if (strpos($part, 'class="') === 0) {
                        // 3.4.58 — real bug, caught by this session's own
                        // audit pass: trim($part, 'class="') does NOT
                        // strip the literal prefix "class=\"" — trim()'s
                        // second argument is a CHARACTER MASK, so this
                        // was stripping any leading/trailing run of
                        // c/l/a/s/=/" characters. It happened to work on
                        // the front because every char in "class=\"" is
                        // in the mask, but on the back it could eat real
                        // class-name characters too (e.g. a class ending
                        // "...-class" would have "-class" stripped
                        // entirely, silently corrupting the attribute).
                        // substr() with fixed offsets (7 = strlen('class="'),
                        // -1 to drop the trailing quote) is the correct,
                        // non-mask-based fix.
                        $current = substr($part, 7, -1);
                        $attr_parts[$i] = 'class="' . esc_attr(trim(html_entity_decode($current) . ' ' . implode(' ', $extra))) . '"';
                        break;
                    }
                }
            }
        }

        $attr_parts[] = 'data-placement-id="' . (int) $placement_id . '"';
        $attr_parts[] = 'data-type="' . esc_attr($type_slug) . '"';
        // §3.2 v1 — opt-in marker (register_type()'s new 'live' key) that
        // assets/js/element-live.js scans for on page load. Placement id
        // is already emitted above unconditionally; this only adds the
        // one extra boolean flag a live type needs.
        if (!empty($type['live'])) $attr_parts[] = 'data-bhel-live="1"';

        // custom_css is raw author-entered `property: value;` text, not a
        // token BHY_Style::scoped_inline_style() knows how to resolve —
        // appended straight onto whatever scoped/resolved style
        // declarations that function already produced, so both compose
        // (custom_css wins on a literal property clash, same "later
        // declaration wins" rule normal CSS cascade already uses).
        if (!empty($style_map['custom_css'])) {
            $custom_css = rtrim(trim((string) $style_map['custom_css']), ';') . ';';
            $style_decls = rtrim($style_decls, ';') . ($style_decls !== '' ? ';' : '') . $custom_css;
        }
        if ($style_decls !== '') $attr_parts[] = 'style="' . esc_attr($style_decls) . '"';

        // $tag is ALWAYS one of $type['tags'] (validated in resolve_tag()
        // — never a raw client-supplied string), so it's safe to
        // interpolate directly into the opening/closing tag here.
        //
        // Void elements (bh/divider's 'hr' being the first real one this
        // registry ships) have no legal closing tag or children at all —
        // browsers silently tolerate a stray '</hr>' by ignoring it, but
        // "tolerated" isn't the same as correct, so this emits genuinely
        // valid markup instead of relying on that leniency.
        $void_tags = ['hr', 'br', 'img', 'input', 'meta', 'link'];
        if (in_array($tag, $void_tags, true)) {
            $html = '<' . $tag . ' ' . implode(' ', $attr_parts) . '>';
        } else {
            $html = '<' . $tag . ' ' . implode(' ', $attr_parts) . '>' . $inner . '</' . $tag . '>';
        }

        // AJ's own ask, folded into the bh-contest conversion: real JS
        // authoring via the builder. save_placement() already gates WHO
        // can ever get a non-empty config['custom_js'] persisted in the
        // first place (bhcore_author_custom_js, administrator-only by
        // default) — by the time execution reaches HERE, at render time,
        // for every visitor loading the page, that gate has already done
        // its job; this only has to worry about SCOPING the script to
        // this one placement's own element, not re-checking who wrote it.
        // Scoped via this placement's own data-placement-id (already
        // emitted above, always unique per row) rather than a class or
        // nth-child guess — an IIFE receiving that one element as `el`,
        // matching the same "the script gets handed its own root, never
        // reaches for document/global selectors itself" contract element-
        // live.js's own live-type scripts already follow.
        // AJ's own ask, same conversation: "easy ways to wire up UI
        // events to actions... 'On click' could trigger UI and server
        // side stuff via fetch." This is the CODELESS, safe answer —
        // config['actions'] is a plain array of {trigger, action, ...}
        // entries, each one hand-mapped below to a small, fixed,
        // reviewed JS snippet — never raw author-entered script, so this
        // needs NO capability gate the way custom_js does. Anyone who can
        // edit a placement at all can wire up a click-to-toggle-class or
        // click-to-fetch-and-refresh without ever touching code, the same
        // trust level as every other placement config field.
        if (!empty($config['actions']) && is_array($config['actions'])) {
            $action_js = self::build_actions_js($config['actions'], $placement_id);
            if ($action_js !== '') {
                $html .= '<script>(function(el){if(!el)return;' . $action_js . '})(document.querySelector(\'[data-placement-id="' . (int) $placement_id . '"]\'));</script>';
            }
        }

        if (!empty($config['custom_js']) && is_string($config['custom_js'])) {
            // A literal "</script>" inside the author's own JS (even
            // inside a string literal or comment) would close this tag
            // early in the BROWSER'S HTML PARSER, which doesn't know or
            // care about JS syntax — the classic "can't put arbitrary
            // text inside a script tag" trap. Splitting the sequence
            // defeats the parser's literal match without changing the
            // script's actual runtime behavior (the closing carat is
            // still there for the browser to eventually find, just not
            // contiguous with "</script").
            $safe_js = str_ireplace('</script', '<\/script', $config['custom_js']);
            $html .= '<script>(function(el){if(!el)return;' . $safe_js . '})(document.querySelector(\'[data-placement-id="' . (int) $placement_id . '"]\'));</script>';
        }

        return $html;
    }

    // Fixed vocabulary of allowlisted action KINDS, each hand-mapped to a
    // small, reviewed JS snippet — an entry's own params (selectors,
    // class names, URLs) are escaped/validated per-kind below, but the
    // JS SHAPE itself is never author-controlled, unlike custom_js. This
    // is deliberately small — three kinds, not a general-purpose DSL —
    // because every kind added here is new surface to review once, not
    // something an author can expand on their own the way a raw-JS field
    // could. Add more kinds by hand as real needs come up, not by making
    // this generic.
    private static function build_actions_js(array $actions, $placement_id) {
        $out = '';
        foreach ($actions as $action) {
            if (!is_array($action)) continue;
            $trigger = sanitize_key($action['trigger'] ?? 'click');
            // Same allowlist reasoning as $void_tags/$tags above — never
            // interpolate a client-supplied event name directly into JS,
            // even sanitize_key()'d (sanitize_key() makes it SAFE
            // syntactically, not necessarily a REAL DOM event).
            $allowed_triggers = ['click', 'mouseenter', 'mouseleave', 'submit'];
            if (!in_array($trigger, $allowed_triggers, true)) continue;

            $kind = sanitize_key($action['action'] ?? '');
            $handler = '';

            if ($kind === 'toggle_class') {
                $class = sanitize_html_class((string) ($action['class'] ?? ''));
                if ($class === '') continue;
                // 'self' (default) or a CSS selector scoped to a
                // querySelector FROM el (never document-wide) — same
                // "never reach outside your own root" contract every
                // other placement script here follows.
                $target_sel = trim((string) ($action['target'] ?? ''));
                $target_js = ($target_sel === '' || $target_sel === 'self')
                    ? 'el' : 'el.querySelector(' . wp_json_encode($target_sel) . ')';
                $handler = '(function(t){if(t)t.classList.toggle(' . wp_json_encode($class) . ');})(' . $target_js . ')';
            } elseif ($kind === 'fetch') {
                $url = esc_url_raw((string) ($action['url'] ?? ''));
                if ($url === '') continue;
                $method = in_array(strtoupper((string) ($action['method'] ?? 'GET')), ['GET', 'POST'], true) ? strtoupper((string) $action['method']) : 'GET';
                $then = sanitize_key($action['then'] ?? 'none');
                $then_js = $then === 'reload' ? 'function(){window.location.reload();}' : 'function(){}';
                $handler = 'fetch(' . wp_json_encode($url) . ',{method:' . wp_json_encode($method) . '}).then(' . $then_js . ').catch(function(){})';
            } elseif ($kind === 'navigate') {
                $url = esc_url_raw((string) ($action['url'] ?? ''));
                if ($url === '') continue;
                $handler = 'window.location.href=' . wp_json_encode($url);
            } else {
                continue; // unrecognized kind — skip silently, same "unregistered = no-op, never fatal" posture BH_Element_Data's resolve() already follows
            }

            $out .= 'el.addEventListener(' . wp_json_encode($trigger) . ',function(ev){if(' . wp_json_encode($trigger) . '===\'submit\')ev.preventDefault();' . $handler . ';});';
        }
        return $out;
    }

    /** Validates a requested htmlAttrs.tag against the type's OWN declared 'tags' allowlist — never trusts an arbitrary client-supplied tag name. Falls back to the type's first (default) tag on no match/no request. */
    private static function resolve_tag(array $type, array $html_attrs) {
        $tags = !empty($type['tags']) && is_array($type['tags']) ? array_values($type['tags']) : ['div'];
        $requested = isset($html_attrs['tag']) ? sanitize_key((string) $html_attrs['tag']) : '';
        return in_array($requested, $tags, true) ? $requested : $tags[0];
    }

    /**
     * Builds the wrapper's attribute-string PARTS (excluding data-
     * placement-id/data-type/style, which the caller appends) from a
     * placement's htmlAttrs map, STRICTLY allowlisted against the
     * element type's own declared 'attrs' schema (register_type()'s
     * $args['attrs'], §2.6). This is a real security boundary, not
     * tidiness: an attribute (or a data-* key) the type never declared
     * is NEVER emitted, no matter what a client sends — every value
     * that IS emitted goes through esc_attr()/esc_url()/
     * sanitize_html_class() or an explicit enum check first.
     */
    private static function build_html_attrs(array $type, array $html_attrs, $tag) {
        $schema = is_array($type['attrs'] ?? null) ? $type['attrs'] : [];
        $out = [];

        // class — 'bh-element' is always present (existing convention,
        // §2.3); additional author-chosen class tokens are appended only
        // if the type opted 'class' into its attrs manifest.
        $classes = ['bh-element'];
        if (!empty($schema['class']) && !empty($html_attrs['class'])) {
            $extra = preg_split('/\s+/', trim((string) $html_attrs['class']));
            $extra = array_filter(array_map('sanitize_html_class', $extra));
            $classes = array_merge($classes, $extra);
        }
        $out[] = 'class="' . esc_attr(implode(' ', array_unique($classes))) . '"';

        if (!empty($schema['id']) && !empty($html_attrs['id'])) {
            // A real HTML id: letters/digits/hyphen/underscore only, must
            // start with a letter (a leading digit is technically legal
            // in HTML5 but breaks CSS #id selectors/querySelector, so
            // this stays conservative).
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $html_attrs['id']);
            if ($id !== '' && preg_match('/^[A-Za-z]/', $id)) $out[] = 'id="' . esc_attr($id) . '"';
        }

        if (!empty($schema['title']) && !empty($html_attrs['title'])) {
            $out[] = 'title="' . esc_attr((string) $html_attrs['title']) . '"';
        }

        if (!empty($schema['aria-label']) && !empty($html_attrs['aria-label'])) {
            $out[] = 'aria-label="' . esc_attr((string) $html_attrs['aria-label']) . '"';
        }

        // href/target/rel are ONLY meaningful — and ONLY ever emitted —
        // when BOTH the type declared them in 'attrs' AND the author
        // actually chose a link-capable tag ('a') for this instance
        // (§2.6's explicit cross-wiring rule: these controls are hidden/
        // inert in the inspector unless tag === 'a', and the render path
        // enforces the same rule server-side, never trusting the client
        // alone).
        if ($tag === 'a') {
            if (!empty($schema['href']) && !empty($html_attrs['href'])) {
                $url = esc_url((string) $html_attrs['href']);
                if ($url !== '') $out[] = 'href="' . $url . '"';
            }
            if (!empty($schema['target']) && !empty($html_attrs['target'])) {
                $allowed_targets = (is_array($schema['target']) && !empty($schema['target']['enum'])) ? $schema['target']['enum'] : ['_self', '_blank'];
                $target = self::safe_enum_fallback($html_attrs['target'], $allowed_targets);
                if ($target !== null) $out[] = 'target="' . esc_attr($target) . '"';
            }
            if (!empty($schema['rel']) && !empty($html_attrs['rel'])) {
                $allowed_rel = ['noopener', 'noreferrer', 'nofollow', 'sponsored', 'ugc'];
                $rel_tokens = array_intersect(preg_split('/\s+/', trim((string) $html_attrs['rel'])), $allowed_rel);
                if ($rel_tokens) $out[] = 'rel="' . esc_attr(implode(' ', $rel_tokens)) . '"';
            }
        }

        // Custom data-* attributes — a repeatable key/value map stored at
        // htmlAttrs.custom (§2.6). Each key is reduced to [a-z0-9-] (a
        // valid, safe 'data-<key>' attribute name — nothing else can
        // reach the output string), each value is esc_attr()'d. A key
        // the type PRE-DECLARED as a structured attr (e.g.
        // 'data-status' => ['enum' => [...]]) is additionally enum-
        // validated and fails closed (dropped) on an invalid value;
        // anything else is emitted only if the type opted into the
        // blanket 'data-*' => true freeform escape hatch.
        $custom = is_array($html_attrs['custom'] ?? null) ? $html_attrs['custom'] : [];
        if ($custom) {
            $wildcard_ok = !empty($schema['data-*']);
            foreach ($custom as $raw_key => $raw_val) {
                $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $raw_key));
                if ($key === '') continue;
                $attr_name = 'data-' . $key;

                $declared = $schema[$attr_name] ?? null;
                if ($declared === null && !$wildcard_ok) continue; // never emit an undeclared data-* on a type that didn't opt in

                $value = $raw_val;
                if (is_array($declared) && !empty($declared['enum'])) {
                    if (self::safe_enum_fallback($value, $declared['enum']) === null) continue; // fail closed on an invalid pre-declared enum value
                }
                if (is_array($value) || is_object($value)) $value = wp_json_encode($value);
                if (!is_scalar($value)) continue;

                $out[] = esc_attr($attr_name) . '="' . esc_attr((string) $value) . '"';
            }
        }

        return $out;
    }

    /** Thin wrapper over BHY_Style::safe_enum() when available, degrading to a plain in_array() if class-style.php somehow hasn't loaded yet — this class never assumes BHY_Style's load order, same defensive posture as its other class_exists('BHY_Style') checks. */
    private static function safe_enum_fallback($val, array $allowed) {
        if (class_exists('BHY_Style')) return BHY_Style::safe_enum($val, $allowed);
        return in_array($val, $allowed, true) ? $val : null;
    }

    private static function coerce_attr($value, $type) {
        switch ($type) {
            case 'int':   return (int) $value;
            case 'bool':  return (bool) $value;
            case 'array': return is_array($value) ? $value : [];
            case 'html':  return wp_kses_post((string) $value);
            case 'url':   return esc_url_raw((string) $value);
            default:      return is_scalar($value) ? (string) $value : '';
        }
    }

    /**
     * The surface-facing entry point every integration calls (§5). Loads
     * enabled placements for (surface, context_id, slot) ordered by
     * position and renders each, concatenated. A surface can safely call
     * this unconditionally even before anyone has placed an element
     * there — an empty slot still returns its wrapper div, just with no
     * children (see the 3.4.49 fix note below for why that wrapper is
     * no longer skipped).
     *
     * 3.4.49 — real bug fix, found while diagnosing why a brand-new
     * unsaved placement's live preview (element-builder.js's
     * applyLivePreview(), also new in 3.4.49) never appeared on an
     * EMPTY slot: this method used to `return ''` immediately for a
     * slot with zero saved placements — no wrapper `.bh-element-slot`
     * div at all, not even an empty one. That meant the live-preview
     * patch (which anchors on `.bh-element-slot[data-surface][data-slot]`
     * to insert a not-yet-saved node) had nowhere to insert into for the
     * single most common case there is: any slot nobody has saved
     * anything into yet, i.e. the very first edit on any fresh page.
     * Confirmed via live screenshot: typing into a brand-new Note's text
     * field produced no server error, no JS error, and no visible
     * change — the append silently no-op'd exactly the way this
     * function's own (now removed) early-return guaranteed it would.
     * Every existing call site (dashboard, portal, CRM, LMS lesson, this
     * plugin's own bh-crm-profile-live story) already echoes this return
     * value unconditionally rather than branching on its truthiness, so
     * always emitting the wrapper (harmless — an empty div — for a
     * still-empty slot) is safe with zero caller changes needed.
     */
    public static function render_slot($surface, $context_id, $slot, array $ctx = []) {
        $placements = self::get_placements($surface, $context_id, $slot);

        // 3.4.34 — ONE query (get_placements() above) for the whole slot,
        // then one in-memory parent=>children map (group_by_parent()) —
        // render_placement()'s recursion below reads this map, it never
        // issues a query per tree level. Only ROOT placements
        // (parent_placement_id === 0) are iterated here; every non-root
        // placement is rendered exactly once, from inside its parent's
        // own render_placement() call, never also as a top-level sibling.
        $children_by_parent = $placements ? self::group_by_parent($placements) : [];
        $roots = $children_by_parent[0] ?? [];

        $out = '<div class="bh-element-slot" data-surface="' . esc_attr($surface) . '" data-slot="' . esc_attr($slot) . '">';
        foreach ($roots as $placement) {
            // render_placement() now returns the placement's FULLY
            // wrapped element itself — tag (§2.6 semantic-tag choice),
            // class="bh-element", data-placement-id/data-type, any
            // per-instance style/htmlAttrs, and (3.4.34) its own nested
            // placement children — so this loop no longer builds its own
            // wrapper <div> around it (it used to; that responsibility
            // moved into render_placement() so REST preview gets the
            // identical wrapper) and never iterates non-root placements
            // directly (those render from inside their parent instead).
            $html = self::render_placement($placement, $ctx, $children_by_parent);
            if ($html === '') continue;
            $out .= $html;
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Generic Design Suite canvas preview for ANY registered surface —
     * added to close a real, live-confirmed bug: the canvas's "Preview
     * surface" iframes only ever existed for surfaces some plugin
     * separately hand-registered into `bhy_style_surfaces` under ITS OWN
     * key (e.g. bh-crm's class-style-surface.php registers
     * 'bh-crm-profile-live', a totally different string from this
     * surface's real registered slug 'bh_crm_profile'). The tree's own
     * selection sync (element-builder.js's fireSelectionEvent()) always
     * fires the REAL surface slug, so it could only ever find a matching
     * `.bhy-story-btn[data-surface="..."]` for the one surface whose
     * hand-authored key happened not to matter (the default/first-active
     * story never actually got re-selected — clicking any OTHER tree
     * node, e.g. bhcrm_project_board or bh_courses_lesson, silently did
     * nothing, since no story was ever registered under either of THOSE
     * exact keys at all). Confirmed via live screenshot: switching tree
     * nodes never updated the canvas except by coincidence.
     *
     * Fix: every registered BH_Element surface now gets its own canvas
     * story automatically, keyed by its REAL slug — no plugin has to
     * hand-author a mirror registration (bh-crm's class-style-surface.php
     * profile_preview() one-off wrapper is now redundant, but left alone
     * this pass rather than ripped out, since it's harmless and still
     * renders correctly under its own separate key). Loops every slot
     * the surface declares and concatenates each one's real render_slot()
     * output, in declaration order, with a plain heading per slot so a
     * multi-slot surface (there are none left after CRM's own 1.3.3
     * collapse, but a future plugin could still declare more than one)
     * still reads sensibly.
     */
    public static function render_surface_preview($surface_slug, $context_id = 0) {
        $surface = self::get_surface($surface_slug);
        if (!$surface) return '';
        $ctx_callable = $surface['preview_ctx'] ?? null;
        $ctx = is_callable($ctx_callable) ? (array) call_user_func($ctx_callable) : [];
        $slots = is_array($surface['slots'] ?? null) ? $surface['slots'] : [];
        if (!$slots) return '<p style="color:var(--bh-text-dim);">This surface has no registered slots.</p>';

        $out = '';
        foreach ($slots as $slot_slug => $slot_def) {
            $label = is_array($slot_def) ? ($slot_def['label'] ?? $slot_slug) : $slot_slug;
            $out .= count($slots) > 1 ? '<div class="bh-element-slot-heading" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--bh-text-dim);margin:0 0 6px;">' . esc_html($label) . '</div>' : '';
            $out .= self::render_slot($surface_slug, (int) $context_id, (string) $slot_slug, $ctx);
        }
        return $out;
    }

    /* =================================================================
     * §3.4 — REST bridge for the builder GUI. Mirrors BH_Studio::
     * register_routes() exactly (own-ur-shit/includes/class-studio.php):
     * same 'ous/v1' namespace, same manage_options-only permission_callback
     * posture (no separate nonce check here either — REST's own cookie
     * auth + the X-WP-Nonce header WordPress's JS REST client sends
     * automatically is the same implicit contract BH_Studio's routes
     * rely on; this is a deliberate mirror, not an oversight). No route
     * here does anything BH_Element's own public methods above don't
     * already do — every callback is a thin HTTP-shaped wrapper.
     *
     * NOT runtime-verified: no live WordPress REST dispatch available in
     * this environment; reasoned through against BH_Studio's working
     * routes only. Smoke-test each route against a real install before
     * wiring a GUI to it.
     * ================================================================= */

    public static function register_routes() {
        register_rest_route('ous/v1', '/elements/surfaces', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_get_surfaces'],
        ]);

        register_rest_route('ous/v1', '/elements/types', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_get_types'],
        ]);

        register_rest_route('ous/v1', '/elements/sources', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_get_sources'],
        ]);

        // 3.4.27 — the inspector's Style-section preset-picker data
        // source (§2.6/BHY_Style::style_schema_for_js()) — added so the
        // GUI can build every property group's controls dynamically
        // instead of hardcoding PROPERTY_MAP's shape client-side.
        register_rest_route('ous/v1', '/elements/style-schema', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_get_style_schema'],
        ]);

        // 3.4.36 — the FINAL-ARCHITECTURE Site root's inspector data
        // source. Selecting the tree's synthetic "Site" node in
        // element-builder.js renders THESE global tokens (BHY_Style's
        // own option row — the exact same data BHY_Gallery's Site Styles
        // form already reads/writes), not a placement — this is what
        // "the Library is contextual, Global Styles is what the one
        // inspector shows for Site" collapses to on the wire: one small
        // read/write pair, reusing BHY_Style's OWN sanitizers
        // (safe_color/safe_number), same posture as
        // BHY_Gallery::save()'s existing admin-post handler (class-
        // style-gallery.php), just REST-shaped so this JS file never has
        // to leave the ous/v1/elements/ namespace it already talks to.
        register_rest_route('ous/v1', '/elements/site-tokens', [
            [
                'methods'             => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_get_site_tokens'],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_save_site_tokens'],
            ],
        ]);

        register_rest_route('ous/v1', '/elements/placements/(?P<surface>[\w-]+)/(?P<context_id>\d+)', [
            [
                'methods'             => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_get_placements'],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_save_placements'],
            ],
        ]);

        register_rest_route('ous/v1', '/elements/preview', [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_preview'],
        ]);

        // A REAL delete route — added this pass alongside the prefab work
        // (per this pass's own scope note: "the visual GUI works around
        // this by disabling placements instead of deleting them; you may
        // add a real DELETE route now if it's clean to do so"). This does
        // NOT change the existing GUI's disable-instead-of-delete
        // behavior (element-builder.js's "✕" button) — that JS is left
        // exactly as-is; this route exists so a future GUI pass or any
        // other REST client can do a true delete, and so the bare Debug
        // Tools list's own "Remove" admin-post action has a REST-shaped
        // sibling. Deliberately a single-id delete, not a bulk op.
        register_rest_route('ous/v1', '/elements/placements/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_delete_placement'],
        ]);

        // §3.2 v1 — runtime re-resolution. DELIBERATE deviation from
        // the design doc's originally-sketched body shape (a client-
        // supplied array of {source,args,subject} bindings): that shape
        // would let any authenticated caller ask the server to resolve
        // an ARBITRARY source+args pair, which is more trust than a
        // "refresh what this placement already shows" feature actually
        // needs. Instead this takes only a placement_id, re-reads that
        // placement's OWN already-stored, already-authored bind
        // descriptors server-side, and re-resolves exactly those — the
        // client can never point this route at a source/args combination
        // that placement wasn't already configured with. Recorded here
        // (not silently done) since it's a real, judgment-call departure
        // from §3.3's sketched contract, made for a concrete security
        // reason, not convenience.
        register_rest_route('ous/v1', '/elements/resolve', [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_resolve'],
        ]);
    }

    /**
     * POST /elements/resolve — body: { "placement_id": 123 }. Re-runs
     * BH_Element_Data::resolve() fresh for every bindable+bound attr on
     * that placement's CURRENT stored config (never a client-supplied
     * binding — see register_routes()'s comment on this route). $ctx is
     * rebuilt server-side as ['user_id' => get_current_user_id()] —
     * the requesting admin's own id, matching how the dashboard's own
     * bound demo placement resolves at normal render time (class-
     * dashboard.php passes the same shape) — never trusted from the
     * request body, so a caller cannot ask this route to resolve on
     * behalf of a different user.
     *
     * Response: { "attrs": { "value": "1.2k", ... } } — only bindable
     * attrs whose stored config actually has a 'bind' descriptor are
     * included; literal/unbound attrs are omitted (nothing to refresh).
     */
    public static function rest_resolve(\WP_REST_Request $req) {
        $id = (int) $req->get_param('placement_id');
        if ($id <= 0) {
            return new \WP_Error('bh_element_bad_id', 'A valid placement_id is required.', ['status' => 400]);
        }

        $placement = self::get_placement($id);
        if (!$placement) {
            return new \WP_Error('bh_element_not_found', 'No such placement.', ['status' => 404]);
        }

        $type = self::get_type($placement['element_type'] ?? '');
        if (!$type) {
            return new \WP_Error('bh_element_unknown_type', 'This placement\'s element type is not registered.', ['status' => 404]);
        }

        $config = $placement['config'] ?? [];
        $attr_values = is_array($config['attrs'] ?? null) ? $config['attrs'] : [];
        $ctx = ['user_id' => get_current_user_id()];

        $out = [];
        foreach ($type['schema'] as $key => $def) {
            if (empty($def['bindable'])) continue;
            $raw = $attr_values[$key] ?? null;
            if (!is_array($raw) || !isset($raw['bind']) || !class_exists('BH_Element_Data')) continue;

            $schema_default = $def['default'] ?? '';
            $out[$key] = self::coerce_attr(BH_Element_Data::resolve($raw, $ctx, $schema_default), $def['type'] ?? 'string');
        }

        return new \WP_REST_Response(['attrs' => $out], 200);
    }

    /** DELETE /elements/placements/{id} — a true row delete (see register_routes()'s comment above this route). */
    public static function rest_delete_placement(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        if ($id <= 0) {
            return new \WP_Error('bh_element_bad_id', 'A valid placement id is required.', ['status' => 400]);
        }
        $ok = self::delete_placement($id);
        return new \WP_REST_Response(['deleted' => $ok, 'id' => $id], 200);
    }

    /** GET /elements/surfaces — registered surfaces + slots (§3.4 bullet 1). */
    public static function rest_get_surfaces(\WP_REST_Request $req) {
        $out = [];
        foreach (self::registered_surfaces() as $slug => $surface) {
            // 'preview_ctx' and any other callables in a surface's manifest
            // are deliberately dropped here — a stored/serialized callable
            // has no meaning to a REST JSON client, same reasoning as
            // BH_Element_Data::registered_sources() dropping 'resolve'.
            $out[$slug] = [
                'group' => $surface['group'] ?? '',
                'label' => $surface['label'] ?? $slug,
                'slots' => is_array($surface['slots'] ?? null) ? $surface['slots'] : [],
            ];
        }
        return new \WP_REST_Response($out, 200);
    }

    /** GET /elements/types?surface= — types_for_surface(), the palette (§3.4 bullet 2). */
    public static function rest_get_types(\WP_REST_Request $req) {
        $surface = (string) $req->get_param('surface');
        $types = $surface !== '' ? self::types_for_surface($surface) : self::registered_types();

        $out = [];
        foreach ($types as $slug => $type) {
            // Same drop-the-callable rule as above — 'render' has no
            // meaning to a REST client, only the manifest metadata does.
            $out[$slug] = [
                'label'     => $type['label'],
                'category'  => $type['category'],
                'icon'      => $type['icon'],
                'surfaces'  => $type['surfaces'],
                'container' => $type['container'],
                'schema'    => $type['schema'],
                'style'     => $type['style'],
                // §2.6 — exposed so the inspector JS can build the Style/
                // HTML-Attributes sections' controls DYNAMICALLY per
                // selected type, instead of hardcoding per-type UI logic.
                'attrs'     => $type['attrs'],
                'tags'      => $type['tags'],
            ];
        }
        return new \WP_REST_Response($out, 200);
    }

    /** GET /elements/sources?kind= — bindable data sources for the inspector dropdown (§3.4 bullet 5). */
    public static function rest_get_sources(\WP_REST_Request $req) {
        if (!class_exists('BH_Element_Data')) return new \WP_REST_Response([], 200);
        $kind = (string) $req->get_param('kind');
        $sources = $kind !== '' ? BH_Element_Data::sources_for_kind($kind) : BH_Element_Data::registered_sources();
        return new \WP_REST_Response($sources, 200);
    }

    /** GET /elements/style-schema — §2.6 property-group preset tables for the inspector's Style section (BHY_Style::style_schema_for_js()). */
    public static function rest_get_style_schema(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) return new \WP_REST_Response(['groups' => [], 'colorTokens' => []], 200);
        return new \WP_REST_Response(BHY_Style::style_schema_for_js(), 200);
    }

    /** GET /elements/site-tokens — the global BHY_Style option row, unfiltered by any entity override (BHY_Style::get() with no entity_id, the same call BHY_Gallery's own settings form makes). */
    public static function rest_get_site_tokens(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) return new \WP_REST_Response([], 200);
        return new \WP_REST_Response(BHY_Style::get(), 200);
    }

    /**
     * POST /elements/site-tokens — writes the global BHY_Style option row.
     * Body: { "tokens": { "color_accent": "#ff3366", "radius": "12", ... } }.
     * Mirrors BHY_Gallery::save()'s existing admin-post handler field-by-
     * field (class-style-gallery.php) — same sanitizers
     * (safe_color/safe_number), same DEFAULTS-driven key allowlist (a key
     * not in BHY_Style::DEFAULTS is never written, no matter what the
     * client sends) — this is a second, REST-shaped entry point to the
     * SAME option row and validation rules, not a parallel/looser one.
     */
    public static function rest_save_site_tokens(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) {
            return new \WP_Error('bh_element_style_unavailable', 'BHY_Style is not loaded.', ['status' => 500]);
        }
        $body = json_decode($req->get_body(), true);
        $incoming = is_array($body['tokens'] ?? null) ? $body['tokens'] : [];

        $data = [];
        $data['brand_part1'] = sanitize_text_field($incoming['brand_part1'] ?? BHY_Style::DEFAULTS['brand_part1']);
        $data['brand_part2'] = sanitize_text_field($incoming['brand_part2'] ?? BHY_Style::DEFAULTS['brand_part2']);
        $data['brand_logo_id'] = isset($incoming['brand_logo_id']) ? (int) $incoming['brand_logo_id'] : 0;
        foreach (BHY_Style::DEFAULTS as $key => $default) {
            if (strpos($key, 'color_') !== 0 && strpos($key, 'cat_color_') !== 0) continue;
            $val = isset($incoming[$key]) ? sanitize_text_field($incoming[$key]) : $default;
            $data[$key] = BHY_Style::safe_color($val);
        }
        foreach (['font_display', 'font_body'] as $key) {
            $picked = sanitize_text_field($incoming[$key] ?? BHY_Style::DEFAULTS[$key]);
            $data[$key] = (array_key_exists($picked, BHY_Style::FONT_OPTIONS) || $picked === 'Custom') ? $picked : BHY_Style::DEFAULTS[$key];
            $data[$key . '_custom'] = sanitize_text_field($incoming[$key . '_custom'] ?? '');
        }
        $data['font_scale']  = BHY_Style::safe_number($incoming['font_scale']  ?? null, 0.75, 1.6, 1);
        $data['space_scale'] = BHY_Style::safe_number($incoming['space_scale'] ?? null, 0.6, 1.8, 1);
        $data['radius']      = BHY_Style::safe_number($incoming['radius']      ?? null, 0, 32, 12);
        $data['radius_sm']   = BHY_Style::safe_number($incoming['radius_sm']   ?? null, 0, 24, 8);
        $data['bar_height']  = BHY_Style::safe_number($incoming['bar_height']  ?? null, 56, 140, 84);

        update_option(BHY_Style::OPTION, $data);
        return new \WP_REST_Response(BHY_Style::get(), 200);
    }

    /** GET /elements/placements/{surface}/{context_id} — all placements grouped by slot (§3.4 bullet 3). */
    public static function rest_get_placements(\WP_REST_Request $req) {
        $surface = (string) $req->get_param('surface');
        $context_id = (int) $req->get_param('context_id');

        if (!self::get_surface($surface)) {
            return new \WP_Error('bh_element_unknown_surface', "Surface '$surface' is not registered.", ['status' => 404]);
        }

        $grouped = [];
        foreach (self::get_placements($surface, $context_id) as $placement) {
            $grouped[$placement['slot']][] = $placement;
        }
        return new \WP_REST_Response($grouped, 200);
    }

    /**
     * POST /elements/placements/{surface}/{context_id} — upsert/reorder a
     * slot's placements (§3.4 bullet 4). Body: { "slot": "main", "placements":
     * [ { "id": 12, "element_type": "bh/note", "config": {...}, "enabled": true,
     *     "parent_placement_id": 0 }, ... ] }
     * in the desired final order — this both upserts every row (save_placement()
     * per entry, same contract as the Debug Tools "add" form uses, including
     * 3.4.34's parent_placement_id same-slot + cycle validation) and sets
     * 'position', mirroring reorder()'s semantics but as a single request
     * instead of two round trips (upsert then reorder). Any entry without a
     * truthy 'id' is inserted as new; every entry must include 'element_type'
     * and a registered type or it's skipped (never fatal); a save_placement()
     * rejection (bad/cyclic parent) also just skips that one entry.
     *
     * 3.4.34 — 'position' is now computed PER PARENT GROUP (sibling-scoped),
     * not from the raw array index: entries are still submitted in one flat
     * array in the client's intended final order, but this loop keeps a
     * running counter PER distinct parent_placement_id value seen so far and
     * assigns position from that counter — the relative order of same-parent
     * entries in the submitted array is what determines their sibling
     * position, exactly mirroring reorder()'s own per-parent scoping. This
     * keeps the wire format a single flat array (no client-side nested JSON
     * required) while still producing correct per-parent position values.
     */
    public static function rest_save_placements(\WP_REST_Request $req) {
        $surface = (string) $req->get_param('surface');
        $context_id = (int) $req->get_param('context_id');

        if (!self::get_surface($surface)) {
            return new \WP_Error('bh_element_unknown_surface', "Surface '$surface' is not registered.", ['status' => 404]);
        }

        $body = json_decode($req->get_body(), true);
        $slot = sanitize_key($body['slot'] ?? '');
        $placements = is_array($body['placements'] ?? null) ? $body['placements'] : [];

        if ($slot === '') {
            return new \WP_Error('bh_element_missing_slot', "'slot' is required.", ['status' => 400]);
        }

        $saved_ids = [];
        $position_by_parent = [];
        foreach ($placements as $entry) {
            if (!is_array($entry) || empty($entry['element_type']) || !self::get_type((string) $entry['element_type'])) {
                continue; // unregistered/malformed entry — skip, never fatal for the rest of the batch
            }

            $parent_id = (int) ($entry['parent_placement_id'] ?? 0);
            $position_by_parent[$parent_id] = ($position_by_parent[$parent_id] ?? -1) + 1;

            $id = self::save_placement([
                'id'                  => !empty($entry['id']) ? (int) $entry['id'] : 0,
                'surface'             => $surface,
                'surface_context_id'  => $context_id,
                'slot'                => $slot,
                'position'            => $position_by_parent[$parent_id],
                'element_type'        => (string) $entry['element_type'],
                'config'              => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'content_context_id'  => (int) ($entry['content_context_id'] ?? 0),
                'enabled'             => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : true,
                'parent_placement_id' => $parent_id,
            ]);
            if ($id) $saved_ids[] = $id;
        }

        return new \WP_REST_Response([
            'saved'      => $saved_ids,
            'placements' => self::get_placements($surface, $context_id, $slot),
        ], 200);
    }

    /**
     * POST /elements/preview — resolve one placement against a supplied
     * context and return rendered HTML for the live canvas (§3.4 bullet 6).
     * Body: { "element_type": "bh/stat-card", "config": {...}, "ctx": {"user_id": 5} }.
     * Deliberately renders an EPHEMERAL placement (never touches the DB) —
     * this is a preview of unsaved inspector edits, not a placement lookup.
     */
    public static function rest_preview(\WP_REST_Request $req) {
        $body = json_decode($req->get_body(), true);
        $element_type = (string) ($body['element_type'] ?? '');
        $config = is_array($body['config'] ?? null) ? $body['config'] : [];
        $ctx = is_array($body['ctx'] ?? null) ? $body['ctx'] : [];

        if (!self::get_type($element_type)) {
            return new \WP_Error('bh_element_unknown_type', "Element type '$element_type' is not registered.", ['status' => 404]);
        }

        $html = self::render_placement([
            'id'                 => 0,
            'element_type'       => $element_type,
            'config'             => $config,
            'content_context_id' => (int) ($body['content_context_id'] ?? 0),
        ], $ctx);

        return new \WP_REST_Response(['html' => $html], 200);
    }

    /* =================================================================
     * Debug Tools — Phase 1's "bare list (add/remove/reorder)" placement
     * manager, doubling as the "why doesn't my element show up on X"
     * registered-types inspector the design doc calls mandatory (§3.1).
     * ================================================================= */

    public static function register_debug_section($tools) {
        $tools['bh-element'] = [
            'label'  => 'Element Builder',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        if (isset($_GET['ous_element_msg'])) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(wp_unslash($_GET['ous_element_msg'])) . '</p></div>';
        }

        echo '<h4>Registered element types</h4>';
        if (!self::$types) {
            echo '<p class="description">No element types registered yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Surfaces</th><th>Container?</th><th>Live placements</th></tr></thead><tbody>';
            foreach (self::$types as $slug => $type) {
                $surfaces_label = $type['surfaces'] === '*' ? 'any' : implode(', ', $type['surfaces']);
                $count = self::count_placements_for_type($slug);
                echo '<tr><td><code>' . esc_html($slug) . '</code></td><td>' . esc_html($type['label']) . '</td><td>' . esc_html($surfaces_label ?: '(none declared)') . '</td><td>' . ($type['container'] ? 'yes' : 'no') . '</td><td>' . (int) $count . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h4 style="margin-top:20px;">Registered surfaces</h4>';
        $surfaces = self::registered_surfaces();
        if (!$surfaces) {
            echo '<p class="description">No surfaces registered yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Slots</th></tr></thead><tbody>';
            foreach ($surfaces as $slug => $s) {
                $slots = is_array($s['slots'] ?? null) ? implode(', ', array_keys($s['slots'])) : '';
                echo '<tr><td><code>' . esc_html($slug) . '</code></td><td>' . esc_html($s['label'] ?? $slug) . '</td><td>' . esc_html($slots) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h4 style="margin-top:20px;">Placements — dashboard / main slot</h4>';
        echo '<p class="description">Phase 1\'s bare add/remove/reorder list, scoped to the one surface/slot wired this pass (<code>dashboard</code> / <code>main</code>, context 0). The visual builder GUI (§4 of the design doc) is a later phase, not built here.</p>';
        self::render_dashboard_placement_list();
        self::render_add_placement_form();
        self::render_add_bound_demo_button();
    }

    // Phase 2's data-binding proof-of-concept, one click: adds a
    // 'bh/stat-card' placement whose 'value' attr is a REAL bind
    // descriptor against the 'bhcore_events.count' source (§3.2),
    // rather than a typed literal — this is the end-to-end slice the
    // build pass is meant to demonstrate (a dashboard widget reading
    // live bhcore_events data through BH_Element_Data::resolve()).
    private static function render_add_bound_demo_button() {
        if (!self::get_type('bh/stat-card') || !class_exists('BH_Element_Data') || !BH_Element_Data::is_registered('bhcore_events.count')) {
            return; // stat-card type or its data source isn't registered this request — nothing sane to offer
        }
        $nonce = wp_create_nonce('ous_element_debug_add_bound');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="ous_element_debug_action">';
        echo '<input type="hidden" name="op" value="add_bound_demo">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<button class="button">Add live stat-card (bound to bhcore_events.count, last 30 days, current user)</button>';
        echo '</form>';
    }

    private static function count_placements_for_type($slug) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE element_type = %s AND enabled = 1",
            $slug
        ));
    }

    private static function render_dashboard_placement_list() {
        $placements = self::get_placements('dashboard', 0, 'main');
        if (!$placements) {
            echo '<p class="description">No placements in dashboard/main yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Type</th><th>Position</th><th>Config</th><th>Actions</th></tr></thead><tbody>';
        foreach ($placements as $p) {
            echo '<tr><td>' . (int) $p['id'] . '</td><td><code>' . esc_html($p['element_type']) . '</code></td><td>' . (int) $p['position'] . '</td><td><code style="font-size:11px;">' . esc_html(wp_json_encode($p['config'])) . '</code></td><td>';

            $nonce = wp_create_nonce('ous_element_debug_' . $p['id']);
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
            echo '<input type="hidden" name="action" value="ous_element_debug_action">';
            echo '<input type="hidden" name="op" value="delete">';
            echo '<input type="hidden" name="id" value="' . (int) $p['id'] . '">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
            echo '<button class="button button-secondary" onclick="return confirm(\'Remove this placement?\');">Remove</button>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
            echo '<input type="hidden" name="action" value="ous_element_debug_action">';
            echo '<input type="hidden" name="op" value="move_up">';
            echo '<input type="hidden" name="id" value="' . (int) $p['id'] . '">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
            echo '<button class="button">&uarr;</button>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
            echo '<input type="hidden" name="action" value="ous_element_debug_action">';
            echo '<input type="hidden" name="op" value="move_down">';
            echo '<input type="hidden" name="id" value="' . (int) $p['id'] . '">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
            echo '<button class="button">&darr;</button>';
            echo '</form>';

            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // A bare "add" form scoped to whatever's registered for the
    // 'dashboard' surface — deliberately minimal (element_type dropdown
    // + one free-text "value literal" field), Phase 1's stated scope.
    private static function render_add_placement_form() {
        $types = self::types_for_surface('dashboard');
        if (!$types) {
            echo '<p class="description">No element types registered for the dashboard surface yet.</p>';
            return;
        }

        $nonce = wp_create_nonce('ous_element_debug_add');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="ous_element_debug_action">';
        echo '<input type="hidden" name="op" value="add">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<select name="element_type">';
        foreach ($types as $slug => $type) {
            echo '<option value="' . esc_attr($slug) . '">' . esc_html($type['label']) . ' (' . esc_html($slug) . ')</option>';
        }
        echo '</select> ';
        echo '<input type="text" name="literal_value" placeholder="static value / label (optional — leave blank to use each attr\'s schema default; this form only sets literals, not bindings)" style="width:340px;"> ';
        echo '<button class="button button-primary">Add to dashboard / main</button>';
        echo '</form>';
    }

    public static function handle_debug_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $op = sanitize_key($_POST['op'] ?? '');
        $msg = 'No change.';

        if ($op === 'add') {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_element_debug_add')) wp_die('Bad nonce.');
            $type = trim((string) ($_POST['element_type'] ?? ''));
            $literal = sanitize_text_field(wp_unslash($_POST['literal_value'] ?? ''));

            if ($type && self::get_type($type)) {
                $existing = self::get_placements('dashboard', 0, 'main');
                $next_position = $existing ? (max(array_column($existing, 'position')) + 1) : 0;

                $attrs = [];
                $type_schema = self::get_type($type)['schema'];
                foreach ($type_schema as $key => $def) {
                    // First bindable string attr, if any, gets left UNSET so
                    // its schema default / a future GUI binding applies;
                    // everything else (and the bindable attr too, if a
                    // literal was actually typed in) gets the typed literal
                    // wrapped as {"literal": ...} — this is the Phase 1
                    // "static content, opt-in binding" contract in miniature.
                    if ($literal !== '') {
                        $attrs[$key] = ['literal' => $literal];
                    }
                }

                $id = self::save_placement([
                    'surface'            => 'dashboard',
                    'surface_context_id' => 0,
                    'slot'               => 'main',
                    'position'           => $next_position,
                    'element_type'       => $type,
                    'config'             => ['attrs' => $attrs],
                ]);
                $msg = $id ? "Added placement #$id." : 'Failed to add placement — check surface/slot/type.';
            } else {
                $msg = 'Unknown or unregistered element type.';
            }
        } elseif ($op === 'add_bound_demo') {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_element_debug_add_bound')) wp_die('Bad nonce.');

            if (self::get_type('bh/stat-card')) {
                $existing = self::get_placements('dashboard', 0, 'main');
                $next_position = $existing ? (max(array_column($existing, 'position')) + 1) : 0;

                $id = self::save_placement([
                    'surface'            => 'dashboard',
                    'surface_context_id' => 0,
                    'slot'               => 'main',
                    'position'           => $next_position,
                    'element_type'       => 'bh/stat-card',
                    'config'             => [
                        'attrs' => [
                            'label' => ['literal' => 'My events, last 30 days'],
                            // §3.2 v1 — 'format' added so this demo also
                            // exercises the new formatter step, not just
                            // re-resolution: a raw integer count renders
                            // as e.g. "1.2k" instead of "1204".
                            'value' => ['bind' => [
                                'source' => 'bhcore_events.count',
                                'args'   => ['since' => 'P30D'],
                                'subject' => 'context.user_id',
                                'format' => 'compact_number',
                            ]],
                        ],
                    ],
                ]);
                $msg = $id ? "Added bound placement #$id (bh/stat-card -> bhcore_events.count)." : 'Failed to add bound demo placement.';
            } else {
                $msg = "'bh/stat-card' type isn't registered.";
            }
        } elseif (in_array($op, ['delete', 'move_up', 'move_down'], true)) {
            $id = (int) ($_POST['id'] ?? 0);
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_element_debug_' . $id)) wp_die('Bad nonce.');

            if ($op === 'delete') {
                $msg = self::delete_placement($id) ? "Removed placement #$id." : 'Nothing removed.';
            } else {
                $msg = self::move_placement('dashboard', 0, 'main', $id, $op === 'move_up' ? -1 : 1)
                    ? 'Reordered.'
                    : 'Could not reorder (already at that end?).';
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'ous-debug', 'ous_element_msg' => rawurlencode($msg)], admin_url('admin.php')) . '#ous-section-bh-element');
        exit;
    }

    // Swaps $id's position with its immediate neighbor in $direction
    // (-1 up, +1 down) within one (surface, context, slot). Simple
    // adjacent-swap rather than a full renumber — fine at Phase 1's
    // "bare list" scale. 3.4.34 — scoped to SIBLINGS (same
    // parent_placement_id as $id's own row), not the whole flat slot list,
    // so this stays correct now that a slot can hold a real tree; the
    // bare Debug Tools list itself is still flat top-to-bottom (Phase 1's
    // stated scope, unchanged), but this keeps it from corrupting sibling
    // order on any slot that DOES have a tree in it (e.g. built via the
    // Design Suite GUI) if this admin-post action is ever invoked against
    // one.
    private static function move_placement($surface, $context_id, $slot, $id, $direction) {
        $placements = self::get_placements($surface, $context_id, $slot);
        $target = null;
        foreach ($placements as $p) {
            if ((int) $p['id'] === (int) $id) { $target = $p; break; }
        }
        if (!$target) return false;
        $parent_id = (int) ($target['parent_placement_id'] ?? 0);

        $siblings = array_values(array_filter($placements, function ($p) use ($parent_id) {
            return (int) ($p['parent_placement_id'] ?? 0) === $parent_id;
        }));
        $ids = array_column($siblings, 'id');
        $index = array_search((int) $id, $ids, true);
        if ($index === false) return false;

        $swap_index = $index + $direction;
        if ($swap_index < 0 || $swap_index >= count($ids)) return false;

        $ordered = $ids;
        [$ordered[$index], $ordered[$swap_index]] = [$ordered[$swap_index], $ordered[$index]];
        return self::reorder($surface, $context_id, $slot, $ordered, $parent_id);
    }
}
