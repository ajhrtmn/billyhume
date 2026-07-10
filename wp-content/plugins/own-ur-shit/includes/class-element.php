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
            'render' => function (array $attrs, array $ctx, array $instance) {
                // 'label' is schema type 'string' -> coerce_attr() already
                // cast it to a plain string, but a string coercion is NOT
                // an escape — esc_html() both attrs here explicitly, since
                // this render callable is the actual HTML text-node output
                // boundary BH_Element_Data::resolve()'s docblock says is
                // the caller's job, not the resolver's.
                return '<div class="bh-el-stat-card">'
                    . '<div class="bh-el-stat-card-value">' . esc_html((string) $attrs['value']) . '</div>'
                    . '<div class="bh-el-stat-card-label">' . esc_html((string) $attrs['label']) . '</div>'
                    . '</div>';
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

        self::$types[$slug] = [
            'label'     => (string) ($args['label'] ?? $slug),
            'category'  => (string) ($args['category'] ?? 'layout'),
            'icon'      => (string) ($args['icon'] ?? 'dashicons-admin-generic'),
            'surfaces'  => $surfaces,
            'container' => !empty($args['container']),
            'schema'    => is_array($args['schema'] ?? null) ? $args['schema'] : [],
            'style'     => is_array($args['style'] ?? null) ? $args['style'] : [],
            'render'    => $args['render'],
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
     * Insert or update a placement row. $placement may include 'id' to
     * update an existing row; otherwise a new row is inserted.
     * 'config' may be passed as an array (encoded here) or a JSON string.
     * @return int|false the placement id, or false on failure.
     */
    public static function save_placement(array $placement) {
        global $wpdb;
        $table = self::table();

        $config = $placement['config'] ?? [];
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
            'parent_placement_id' => (int) ($placement['parent_placement_id'] ?? 0), // unused seam, §1.1 — always 0 today
            'revision_of'         => (int) ($placement['revision_of'] ?? 0),          // unused seam, §2.3 — always 0 today
        ];

        if (!$data['surface'] || !$data['slot'] || !$data['element_type']) return false;

        if (!empty($placement['id'])) {
            $id = (int) $placement['id'];
            $ok = $wpdb->update($table, $data, ['id' => $id]);
            return $ok !== false ? $id : false;
        }

        $ok = $wpdb->insert($table, $data);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function delete_placement($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $id]);
    }

    /** Rewrites 'position' for every id in $ordered_ids (0-based, array order) within one (surface, context, slot). IDs not in $ordered_ids are left untouched. */
    public static function reorder($surface, $context_id, $slot, array $ordered_ids) {
        global $wpdb;
        $table = self::table();
        $surface = sanitize_key($surface);
        $slot = sanitize_key($slot);
        $context_id = (int) $context_id;

        foreach (array_values($ordered_ids) as $position => $id) {
            $wpdb->update(
                $table,
                ['position' => (int) $position],
                ['id' => (int) $id, 'surface' => $surface, 'surface_context_id' => $context_id, 'slot' => $slot]
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
     * @param array $placement One row as returned by get_placements() (config already decoded).
     * @param array $ctx       Render context — user_id, post_id, entity_id, viewer_id, etc.
     * @return string HTML, or '' if the element_type isn't registered (graceful degrade — matches BH_Content::render() skipping unregistered block types) or the type's render callable itself throws.
     */
    public static function render_placement(array $placement, array $ctx = []) {
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
        $inner_html = '';
        if ($type['container'] && !empty($placement['content_context_id']) && class_exists('BH_Content')) {
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
            return (string) call_user_func($type['render'], $resolved_attrs, $ctx, $instance);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', "render_placement() failed for type '$type_slug' (placement #{$instance['id']}): " . $e->getMessage(), [], 'bh-element');
            }
            return ''; // one broken element never takes the whole slot/surface down
        }
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
     * position and renders each, concatenated. An empty/no-placements
     * slot renders '' (nothing) — a surface can safely call this
     * unconditionally even before anyone has placed an element there.
     */
    public static function render_slot($surface, $context_id, $slot, array $ctx = []) {
        $placements = self::get_placements($surface, $context_id, $slot);
        if (!$placements) return '';

        $out = '<div class="bh-element-slot" data-surface="' . esc_attr($surface) . '" data-slot="' . esc_attr($slot) . '">';
        foreach ($placements as $placement) {
            $html = self::render_placement($placement, $ctx);
            if ($html === '') continue;
            $out .= '<div class="bh-element" data-placement-id="' . (int) $placement['id'] . '" data-type="' . esc_attr($placement['element_type']) . '">' . $html . '</div>';
        }
        $out .= '</div>';
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
     * [ { "id": 12, "element_type": "bh/note", "config": {...}, "enabled": true }, ... ] }
     * in the desired final order — this both upserts every row (save_placement()
     * per entry, same contract as the Debug Tools "add" form uses) and sets
     * 'position' from array order in one call, mirroring reorder()'s semantics
     * but as a single request instead of two round trips (upsert then reorder).
     * Any entry without a truthy 'id' is inserted as new; every entry must
     * include 'element_type' and a registered type or it's skipped (never fatal).
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
        foreach ($placements as $position => $entry) {
            if (!is_array($entry) || empty($entry['element_type']) || !self::get_type((string) $entry['element_type'])) {
                continue; // unregistered/malformed entry — skip, never fatal for the rest of the batch
            }

            $id = self::save_placement([
                'id'                  => !empty($entry['id']) ? (int) $entry['id'] : 0,
                'surface'             => $surface,
                'surface_context_id'  => $context_id,
                'slot'                => $slot,
                'position'            => (int) $position,
                'element_type'        => (string) $entry['element_type'],
                'config'              => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'content_context_id'  => (int) ($entry['content_context_id'] ?? 0),
                'enabled'             => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : true,
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
                            'value' => ['bind' => [
                                'source' => 'bhcore_events.count',
                                'args'   => ['since' => 'P30D'],
                                'subject' => 'context.user_id',
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
    // "bare list" scale.
    private static function move_placement($surface, $context_id, $slot, $id, $direction) {
        $placements = self::get_placements($surface, $context_id, $slot);
        $ids = array_column($placements, 'id');
        $index = array_search((int) $id, $ids, true);
        if ($index === false) return false;

        $swap_index = $index + $direction;
        if ($swap_index < 0 || $swap_index >= count($ids)) return false;

        $ordered = $ids;
        [$ordered[$index], $ordered[$swap_index]] = [$ordered[$swap_index], $ordered[$index]];
        return self::reorder($surface, $context_id, $slot, $ordered);
    }
}
