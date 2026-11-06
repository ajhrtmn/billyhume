<?php
if (!defined('ABSPATH')) exit;

/**
 * Element placement registry, storage, rendering, and REST bridge.
 *
 * Element TYPES are registered in code (register_type()) and render nothing if their plugin is
 * inactive. PLACEMENTS live in bhcore_element_placements: "this type, configured this way, in
 * this slot on this surface for this context, at this position."
 *
 * Placements form a shallow tree per slot via parent_placement_id (0 = root). save_placement()
 * requires the parent to be in the same (surface, context, slot) and rejects cycles.
 * render_slot() fetches the slot in ONE query and recurses in memory. This tree is separate
 * from the bh/container -> BH_Content bridge (content_context_id). REST GET returns a flat
 * array (clients build the tree); delete_placement() promotes children to root.
 */
class BH_Element {
    /** @var array<string, array<string, mixed>> slug => manifest (including the 'render' callable) */
    private static $types = [];

    /** @var array<string, array<string, mixed>>|null surface slug => ['group'=>,'label'=>,'slots'=>[...],'context'=>,'preview_ctx'=>callable] */
    private static $surfaces_cache = null;

    const VALID_ATTR_KINDS = ['scalar', 'list', 'richtext', 'url', 'series'];

    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_element_debug_action', [self::class, 'handle_debug_post']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('bh_element_surfaces', [self::class, 'register_library_surface']);
        self::register_default_types();
    }

    /**
     * The internal "__library" sandbox surface, so the Library tab reuses the normal placement
     * editor for a Component. context_id is the Component (prefab) id. Flagged 'internal' so
     * rest_get_surfaces() excludes it from the Structure tree.
     *
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_library_surface($surfaces): array {
        $surfaces['__library'] = [
            'group'    => 'Library',
            'label'    => 'Library (sandbox)',
            'slots'    => ['root' => ['label' => 'Component root']],
            'internal' => true,
        ];
        return $surfaces;
    }

    /** First-party types: bh/note, bh/container, bh/stat-card. Peers register their own. */
    private static function register_default_types(): void {
        self::register_type('bh/note', [
            'label'    => 'Note',
            'category' => 'text',
            'icon'     => 'dashicons-media-text',
            'surfaces' => '*', // generic static text, valid anywhere
            'container' => false,
            'schema' => [
                'text' => ['type' => 'html', 'default' => '', 'bindable' => false],
            ],
            'style' => ['color_accent', 'radius'],
            'tags'  => ['div', 'section', 'article', 'aside'],
            'attrs' => ['id' => true, 'class' => true, 'title' => true, 'aria-label' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                // 'text' is already wp_kses_post()-coerced (schema type 'html'); escaping again would mangle it.
                $text = $attrs['text'] !== '' ? $attrs['text'] : '<em>(empty note)</em>';
                return '<div class="bh-el-note">' . $text . '</div>';
            },
        ]);

        // A container's content is an embedded BH_Content tree at ('bh_element', content_context_id),
        // edited in the BH_Studio canvas; save_placement() sets content_context_id to the placement's own id.
        self::register_type('bh/container', [
            'label'    => 'Container',
            'category' => 'layout',
            'icon'     => 'dashicons-layout',
            'surfaces' => '*', // generic layout shell, valid on every surface
            'container' => true,
            'schema' => [
                'heading' => ['type' => 'string', 'default' => '', 'bindable' => false],
            ],
            'style' => ['color_accent', 'radius', 'space_scale'],
            'tags'  => ['div', 'section', 'article'],
            'attrs' => ['id' => true, 'class' => true, 'aria-label' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                $heading = trim((string) $attrs['heading']);
                $heading_html = $heading !== '' ? '<h3 class="bh-el-container-heading">' . esc_html($heading) . '</h3>' : '';
                // $instance['content'] is already-rendered BH_Content HTML; deliberately not re-escaped.
                return '<div class="bh-el-container">' . $heading_html . $instance['content'] . '</div>';
            },
        ]);

        self::register_type('bh/stat-card', [
            'label'    => 'Stat card',
            'category' => 'data',
            'icon'     => 'dashicons-chart-bar',
            'surfaces' => ['dashboard', 'bh_crm_profile'],
            'container' => false,
            'schema' => [
                'label' => ['type' => 'string', 'default' => 'Stat',    'bindable' => false],
                'value' => ['type' => 'string', 'default' => '—',       'bindable' => true, 'kind' => 'scalar'],
            ],
            'style' => ['color_accent', 'radius', 'space_scale'],
            // Can render as <a> for a click-through CTA; the first tag ('div') is the default.
            'tags'  => ['div', 'a'],
            'attrs' => [
                'id' => true, 'class' => true, 'aria-label' => true,
                'href'   => ['type' => 'url'],
                'target' => ['enum' => ['_self', '_blank']],
                'rel'    => true,
            ],
            // Live type: its bound 'value' refreshes in place via element-live.js.
            'live' => true,
            'render' => function (array $attrs, array $ctx, array $instance) {
                // esc_html() here: coercion to string is not escaping, and this callable is the output boundary.
                // data-bhel-bind="value" lets element-live.js patch just the value, not the label.
                return '<div class="bh-el-stat-card">'
                    . '<div class="bh-el-stat-card-value" data-bhel-bind="value">' . esc_html((string) $attrs['value']) . '</div>'
                    . '<div class="bh-el-stat-card-label">' . esc_html((string) $attrs['label']) . '</div>'
                    . '</div>';
            },
        ]);

        self::register_generic_primitives();
    }

    /**
     * Built-in primitives usable on every surface: heading, image, button, divider (bh/note covers
     * rich text). Attrs are not bindable yet; BH_Element_Data only resolves scalar values.
     */
    private static function register_generic_primitives(): void {
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
            // The tag choice is the heading level; no separate 'level' attr.
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
            'tags'  => ['div'], // the <img> is the render output; the wrapper has no other sensible tag
            'attrs' => ['id' => true, 'class' => true],
            'render' => function (array $attrs, array $ctx, array $instance) {
                $src = (string) $attrs['src'];
                if ($src === '') return '<div class="bh-el-image bh-el-image-empty">' . esc_html__('No image set', 'the-self-hosted-self') . '</div>';
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
            // 'a' first (common CTA), 'button' when it should trigger JS rather than navigate.
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
                return ''; // <hr> is void: wrap_placement_html() renders it with no closing tag/children
            },
        ]);
    }

    /* ---------------- type registry ---------------- */

    /**
     * @param string $slug Namespaced type, e.g. 'bh/stat-card', 'bh/note'.
     * @param array  $args {
     *   'label'     => 'Stat card',
     *   'category'  => 'data'|'layout'|'text'|'media',
     *   'icon'      => 'dashicons-chart-bar',
     *   'surfaces'  => ['dashboard','bh_crm_profile'] | '*',   // capability manifest
     *   'container' => false,                                  // does it own a BH_Content subtree?
     *   'schema'    => [
     *        'value' => ['type' => 'string', 'default' => '', 'bindable' => true,  'kind' => 'scalar'],
     *        'label' => ['type' => 'string', 'default' => '', 'bindable' => false],
     *   ],
     *   'style'     => ['color_accent','radius','space_scale'],
     *   'attrs'     => [                                        // HTML-attribute manifest, optional
     *        'id' => true, 'class' => true, 'aria-label' => true,
     *        'href' => ['type' => 'url'], 'target' => ['enum' => ['_self','_blank']],
     *        'data-status' => ['enum' => ['todo','in_progress','done']], // a pre-declared, structured data-* attr
     *        'data-*' => true,                                   // opts into freeform custom data-* attrs
     *   ],
     *   'tags'      => ['div','section','article'],              // allowed semantic tags; first = default. Omitted -> ['div']
     *   'live'      => false,                                    // opt-in in-place refresh via element-live.js
     *   'states'    => [],                                       // optional default fixture states (seed only)
     *   'render'    => function(array $attrs, array $ctx, array $instance): string { ... },
     * }
     * @return bool true if accepted, false if rejected (missing 'render' callable — logged, never fatal).
     * @param array<string, mixed> $args
     */
    public static function register_type(string $slug, array $args): bool {
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

        self::$types[$slug] = [
            'label'     => (string) ($args['label'] ?? $slug),
            'category'  => (string) ($args['category'] ?? 'layout'),
            'icon'      => (string) ($args['icon'] ?? 'dashicons-admin-generic'),
            'surfaces'  => $surfaces,
            'container' => !empty($args['container']),
            'schema'    => is_array($args['schema'] ?? null) ? $args['schema'] : [],
            'style'     => is_array($args['style'] ?? null) ? $args['style'] : [],
            // No 'attrs' key -> minimal id/class/aria-label allowlist; href/data-* only by opt-in.
            'attrs'     => is_array($args['attrs'] ?? null) ? $args['attrs'] : ['id' => true, 'class' => true, 'aria-label' => true],
            'tags'      => $tags,
            'render'    => $args['render'],
            // Opt-in: adds data-bhel-live="1" so element-live.js re-POSTs /elements/resolve and patches in place.
            'live'      => !empty($args['live']),
            // Optional code-declared fixture states, seeded lazily by maybe_seed_default_states(); an
            // existing DB row always wins. Entry: ['name' => 'empty', 'label' => 'Empty', 'fixtures' => ['source.slug' => mock]].
            'states'    => is_array($args['states'] ?? null) ? $args['states'] : [],
        ];
        return true;
    }

    /**
     * Seeds a type's code-declared 'states' into BH_Element_State, only where no row exists yet
     * (never overwrites edits). Called lazily, not on every 'init', to avoid per-request writes.
     */
    public static function maybe_seed_default_states(string $slug): void {
        if (!class_exists('BH_Element_State')) return;
        $type = self::get_type($slug);
        if (!$type || empty($type['states']) || !is_array($type['states'])) return;

        $existing = BH_Element_State::for_owner('type', $slug);
        $existing_names = array_column($existing, 'name');

        foreach ($type['states'] as $state) {
            if (!is_array($state) || empty($state['name'])) continue;
            $name = sanitize_title((string) $state['name']);
            if ($name === '' || in_array($name, $existing_names, true)) continue;
            BH_Element_State::insert('type', $slug, $name, (string) ($state['label'] ?? ''), [
                'fixtures' => is_array($state['fixtures'] ?? null) ? $state['fixtures'] : [],
                'context'  => is_array($state['context'] ?? null) ? $state['context'] : [],
            ]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function registered_types(): array {
        return self::$types;
    }

    /** @return array<string, mixed>|null */
    public static function get_type(string $slug): ?array {
        return self::$types[(string) $slug] ?? null;
    }

    public static function is_container(string $slug): bool {
        $type = self::get_type($slug);
        return $type ? $type['container'] : false;
    }

    /**
     * Manifest-admitted types for a surface (the palette's source of truth).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function types_for_surface(string $surface): array {
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

    /* ---------------- surface + capability contract ---------------- */

    /**
     * Surfaces self-register via a filter; cached per request.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registered_surfaces(): array {
        if (self::$surfaces_cache === null) {
            self::$surfaces_cache = apply_filters('bh_element_surfaces', []);
            if (!is_array(self::$surfaces_cache)) self::$surfaces_cache = [];
        }
        return self::$surfaces_cache;
    }

    /** @return array<string, mixed>|null */
    public static function get_surface(string $surface): ?array {
        $surfaces = self::registered_surfaces();
        return $surfaces[(string) $surface] ?? null;
    }

    /* ---------------- placement storage ---------------- */

    private static function table(): string {
        return OUS_Tables::element_placements();
    }

    /**
     * @param string   $surface
     * @param int      $context_id 0 for a singleton surface (global dashboard); else the entity id.
     * @param string|null $slot    Restrict to one slot, or null for every slot on this (surface, context).
     * @return array<int, array<string, mixed>> Ordered rows (position ASC), 'config' decoded.
     */
    public static function get_placements(string $surface, int $context_id, ?string $slot = null): array {
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
            // WHY: cast ids to int; wpdb returns strings and the JS compares with ===.
            $row['library_component_id'] = (int) ($row['library_component_id'] ?? 0);
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['parent_placement_id'] = (int) ($row['parent_placement_id'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    /**
     * Single-row fetch by id, config decoded; null on an unknown id.
     *
     * @return array<string, mixed>|null
     */
    public static function get_placement(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id
        ), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode((string) $row['config'], true);
        $row['config'] = is_array($decoded) ? $decoded : [];
        $row['library_component_id'] = (int) ($row['library_component_id'] ?? 0); // int casts, same as get_placements()
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['parent_placement_id'] = (int) ($row['parent_placement_id'] ?? 0);
        return $row;
    }

    /**
     * $id plus every descendant, root-first, from one get_placements() query walked in memory.
     *
     * @param int $id
     * @return array<int, array<string, mixed>> Root-first flat list (config decoded), or [] if $id is unknown.
     */
    public static function get_subtree(int $id): array {
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
                // Prepend children right after their parent so the list stays depth-first, siblings in position order.
                array_splice($stack, 0, 0, $children_by_parent[$node_id]);
            }
        }
        return $out;
    }

    /**
     * Insert (no 'id') or update a placement row; 'config' may be an array or JSON string.
     * Container placements get content_context_id = their own id on insert.
     *
     * @param array<string, mixed> $placement
     * @return int|false the placement id, or false on failure.
     */
    public static function save_placement(array $placement) {
        global $wpdb;
        $table = self::table();

        $config = $placement['config'] ?? [];

        // WHY: custom_js runs for every visitor, so it is gated HERE, on the one write path every caller
        // uses, not only in the GUI. Raw-PHP authoring is never supported.
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
            'parent_placement_id' => (int) ($placement['parent_placement_id'] ?? 0), // 0 = root within the slot; validated below
            'revision_of'         => (int) ($placement['revision_of'] ?? 0),          // unused seam, always 0
        ];

        // Only touch library_component_id when the key is explicitly present, so a normal edit of a
        // linked instance never resets it to 0.
        if (array_key_exists('library_component_id', $placement)) {
            $data['library_component_id'] = (int) $placement['library_component_id'];
        }

        if (!$data['surface'] || !$data['slot'] || !$data['element_type']) return false;

        // Parent invariants, fail-closed: same (surface, context, slot) as this placement, and (on
        // update) no cycle.
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
                return false; // unknown parent, or one in a different surface/context/slot
            }
            if (!empty($placement['id']) && self::would_create_cycle((int) $placement['id'], $data['parent_placement_id'])) {
                return false; // would make the placement its own ancestor
            }
        }

        $type = self::get_type($data['element_type']);

        if (!empty($placement['id'])) {
            $id = (int) $placement['id'];
            // Fetched BEFORE the write so 'bhcore_element_placement_saved' listeners can diff old vs new config.
            $old_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
            // A container with no content_context_id on update gets the same self-id backfill.
            if ($type && $type['container'] && $data['content_context_id'] === 0) {
                $data['content_context_id'] = $id;
            }
            $ok = $wpdb->update($table, $data, ['id' => $id]);
            if ($ok === false) return false;
            do_action('bhcore_element_placement_saved', $id, $data, $old_row);
            return $id;
        }

        $ok = $wpdb->insert($table, $data);
        if (!$ok) return false;
        $id = (int) $wpdb->insert_id;

        if ($type && $type['container'] && $data['content_context_id'] === 0) {
            $wpdb->update($table, ['content_context_id' => $id], ['id' => $id]);
        }

        do_action('bhcore_element_placement_saved', $id, $data, null);
        return $id;
    }

    /** Promotes children to root before deleting, so no row keeps a dangling parent id. */
    public static function delete_placement(int $id): bool {
        global $wpdb;
        $id = (int) $id;
        $table = self::table();
        $wpdb->update($table, ['parent_placement_id' => 0], ['parent_placement_id' => $id]);
        return (bool) $wpdb->delete($table, ['id' => $id]);
    }

    // Deletes every placement for one (surface, context), all slots, so nothing is orphaned.
    public static function delete_context(string $surface, int $context_id): bool {
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->delete($table, [
            'surface' => sanitize_key($surface),
            'surface_context_id' => (int) $context_id,
        ]);
    }

    /**
     * True if accepting $proposed_parent_id would make $placement_id its own ancestor. Walks up with
     * a 200-hop cap; a broken chain returns false rather than blocking all future assignments.
     */
    private static function would_create_cycle(int $placement_id, int $proposed_parent_id): bool {
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
            if (isset($seen[$current])) return false; // pre-existing cycle elsewhere in the data: bail rather than loop
            $seen[$current] = true;
            $current = (int) $wpdb->get_var($wpdb->prepare("SELECT parent_placement_id FROM $table WHERE id = %d", $current));
            $hops++;
        }
        return false;
    }

    /**
     * Groups a flat placement array by parent_placement_id (0 = root).
     *
     * @param array<int, array<string, mixed>> $placements
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function group_by_parent(array $placements): array {
        $map = [];
        foreach ($placements as $p) {
            $parent = (int) ($p['parent_placement_id'] ?? 0);
            $map[$parent][] = $p;
        }
        return $map;
    }

    /**
     * Rewrites 'position' (0-based, array order) for $ordered_ids among siblings under one parent
     * in one (surface, context, slot). Other ids are untouched.
     *
     * @param array<int, int> $ordered_ids
     */
    public static function reorder(string $surface, int $context_id, string $slot, array $ordered_ids, int $parent_id = 0): bool {
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

    /* ---------------- rendering (failures degrade to an empty or fallback placement, never fatal) ---------------- */

    /**
     * Resolves every attr (bindings via BH_Element_Data::resolve(), literals verbatim, schema
     * defaults otherwise), calls the type's 'render', then recurses into children.
     *
     * @param array<string, mixed> $placement One row as returned by get_placements() (config decoded).
     * @param array<string, mixed> $ctx Render context — user_id, post_id, entity_id, viewer_id, etc.
     * @param array<int, array<int, array<string, mixed>>> $children_by_parent Map built once by render_slot().
     * @return string HTML, or '' if the type isn't registered or its render callable throws.
     */
    public static function render_placement(array $placement, array $ctx = [], array $children_by_parent = []): string {
        // A linked instance (library_component_id != 0) has no structure of its own: its config is a
        // leaf-override map and its element_type is synthetic, so delegate before get_type().
        if (!empty($placement['library_component_id']) && class_exists('BH_Element_Prefab')) {
            return BH_Element_Prefab::render_linked_instance($placement, $ctx);
        }

        $type_slug = $placement['element_type'] ?? '';
        $type = self::get_type($type_slug);
        if (!$type) return ''; // unregistered/deactivated plugin: silent, expected

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
                    // Non-bindable attr with a literal wrapper: honor the literal.
                    $resolved_attrs[$key] = $raw['literal'];
                } else {
                    // WHY: a 'bind' on a non-bindable attr is never resolved; use the schema default.
                    $resolved_attrs[$key] = $schema_default;
                }
            } elseif ($raw !== null) {
                // Plain stored value: treat as a literal.
                $resolved_attrs[$key] = $raw;
            } else {
                $resolved_attrs[$key] = $schema_default;
            }

            $resolved_attrs[$key] = self::coerce_attr($resolved_attrs[$key], $def['type'] ?? 'string');
        }

        // Container content lives in BH_Content at ('bh_element', content_context_id), passed as
        // $instance['content']. An inline 'content_tree' (unpersisted prefab definitions) is checked first.
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
            return ''; // one broken element never takes the whole slot down
        }

        // Nested placement children render after the type's output in '.bh-element-children', so
        // render callables never deal with them.
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

        // Per-instance style + htmlAttrs go on the wrapper (inline style; data-placement-id already scopes it).
        return self::wrap_placement_html($type, $type_slug, $instance['id'], $config, $inner);
    }

    /**
     * Builds the placement's wrapper tag around $inner: data-placement-id/data-type, scoped inline
     * style, and allowlisted htmlAttrs. Shared by render_slot() and REST preview.
     *
     * @param array<string, mixed> $type
     * @param array<string, mixed> $config
     */
    private static function wrap_placement_html(array $type, string $type_slug, int $placement_id, array $config, string $inner): string {
        $style_map  = is_array($config['style'] ?? null) ? $config['style'] : [];
        $html_attrs = is_array($config['htmlAttrs'] ?? null) ? $config['htmlAttrs'] : [];

        $tag = self::resolve_tag($type, $html_attrs);
        $style_decls = ($style_map && class_exists('BHY_Style')) ? BHY_Style::scoped_inline_style($style_map) : '';

        $attr_parts = self::build_html_attrs($type, $html_attrs, $tag);

        // custom_class is a Design Suite style override (not gated by the type's 'class' opt-in). Merged
        // into the existing class="" attribute; a second class attribute would drop 'bh-element'.
        if (!empty($style_map['custom_class'])) {
            $extra = preg_split('/\s+/', trim((string) $style_map['custom_class']));
            $extra = array_filter(array_map('sanitize_html_class', $extra));
            if ($extra) {
                foreach ($attr_parts as $i => $part) {
                    if (strpos($part, 'class="') === 0) {
                        // WHY: substr, not trim($part, 'class="') — trim's second argument is a character mask.
                        $current = substr($part, 7, -1);
                        $attr_parts[$i] = 'class="' . esc_attr(trim(html_entity_decode($current) . ' ' . implode(' ', $extra))) . '"';
                        break;
                    }
                }
            }
        }

        $attr_parts[] = 'data-placement-id="' . (int) $placement_id . '"';
        $attr_parts[] = 'data-type="' . esc_attr($type_slug) . '"';
        // Opt-in marker element-live.js scans for.
        if (!empty($type['live'])) $attr_parts[] = 'data-bhel-live="1"';

        // Raw author `property: value;` text appended after scoped styles, so it wins on a clash.
        if (!empty($style_map['custom_css'])) {
            $custom_css = rtrim(trim((string) $style_map['custom_css']), ';') . ';';
            $style_decls = rtrim($style_decls, ';') . ($style_decls !== '' ? ';' : '') . $custom_css;
        }
        if ($style_decls !== '') $attr_parts[] = 'style="' . esc_attr($style_decls) . '"';

        // $tag is always from $type['tags'] (resolve_tag()), so safe to interpolate. Void tags get no
        // closing tag or children.
        $void_tags = ['hr', 'br', 'img', 'input', 'meta', 'link'];
        if (in_array($tag, $void_tags, true)) {
            $html = '<' . $tag . ' ' . implode(' ', $attr_parts) . '>';
        } else {
            $html = '<' . $tag . ' ' . implode(' ', $attr_parts) . '>' . $inner . '</' . $tag . '>';
        }

        // custom_js is gated at save time (bhcore_author_custom_js); here it is only scoped to this
        // placement's element via data-placement-id, handed to an IIFE as `el`.
        // config['actions'] maps {trigger, action, ...} entries onto fixed reviewed JS snippets, so it
        // needs no capability gate.
        if (!empty($config['actions']) && is_array($config['actions'])) {
            $action_js = self::build_actions_js($config['actions'], $placement_id);
            if ($action_js !== '') {
                $html .= '<script>(function(el){if(!el)return;' . $action_js . '})(document.querySelector(\'[data-placement-id="' . (int) $placement_id . '"]\'));</script>';
            }
        }

        if (!empty($config['custom_js']) && is_string($config['custom_js'])) {
            // WHY: split "</script" so the HTML parser can't close the tag early; JS behavior is unchanged.
            $safe_js = str_ireplace('</script', '<\/script', $config['custom_js']);
            $html .= '<script>(function(el){if(!el)return;' . $safe_js . '})(document.querySelector(\'[data-placement-id="' . (int) $placement_id . '"]\'));</script>';
        }

        return $html;
    }

    /**
     * Fixed vocabulary of action kinds, each a hand-written JS snippet with params validated per kind.
     * The JS shape is never author-controlled; add kinds by hand, don't make this generic.
     *
     * @param array<int, array<string, mixed>> $actions
     */
    private static function build_actions_js(array $actions, int $placement_id): string {
        $out = '';
        foreach ($actions as $action) {
            if (!is_array($action)) continue;
            $trigger = sanitize_key($action['trigger'] ?? 'click');
            // Allowlist: never interpolate a client-supplied event name into JS.
            $allowed_triggers = ['click', 'mouseenter', 'mouseleave', 'submit'];
            if (!in_array($trigger, $allowed_triggers, true)) continue;

            $kind = sanitize_key($action['action'] ?? '');
            $handler = '';

            if ($kind === 'toggle_class') {
                $class = sanitize_html_class((string) ($action['class'] ?? ''));
                if ($class === '') continue;
                // 'self' (default) or a selector queried FROM el, never document-wide.
                $target_sel = trim((string) ($action['target'] ?? ''));
                $target_js = ($target_sel === '' || $target_sel === 'self')
                    ? 'el' : 'el.querySelector(' . wp_json_encode($target_sel) . ')';
                $handler = '(function(t){if(t)t.classList.toggle(' . wp_json_encode($class) . ');})(' . $target_js . ')';
            } elseif ($kind === 'fetch') {
                $url = esc_url_raw((string) ($action['url'] ?? ''));
                if ($url === '') continue;
                $method = in_array(strtoupper((string) ($action['method'] ?? 'GET')), ['GET', 'POST'], true) ? strtoupper((string) $action['method']) : 'GET';
                $then = sanitize_key($action['then'] ?? 'none');
                // Shows a toast on success/failure when BHCoreToast is loaded.
                $then_js = $then === 'reload'
                    ? 'function(){window.location.reload();}'
                    : 'function(){if(typeof BHCoreToast!==\'undefined\'){BHCoreToast.show(\'Done.\',\'success\');}}';
                $handler = 'fetch(' . wp_json_encode($url) . ',{method:' . wp_json_encode($method) . '}).then(' . $then_js . ').catch(function(){if(typeof BHCoreToast!==\'undefined\'){BHCoreToast.show(\'Something went wrong — check your connection and try again.\',\'error\');}})';
            } elseif ($kind === 'navigate') {
                $url = esc_url_raw((string) ($action['url'] ?? ''));
                if ($url === '') continue;
                $handler = 'window.location.href=' . wp_json_encode($url);
            } else {
                continue; // unrecognized kind: skip silently
            }

            $out .= 'el.addEventListener(' . wp_json_encode($trigger) . ',function(ev){if(' . wp_json_encode($trigger) . '===\'submit\')ev.preventDefault();' . $handler . ';});';
        }
        return $out;
    }

    /**
     * Validates a requested htmlAttrs.tag against the type's own 'tags'; falls back to the first.
     *
     * @param array<string, mixed> $type
     * @param array<string, mixed> $html_attrs
     */
    private static function resolve_tag(array $type, array $html_attrs): string {
        $tags = !empty($type['tags']) && is_array($type['tags']) ? array_values($type['tags']) : ['div'];
        $requested = isset($html_attrs['tag']) ? sanitize_key((string) $html_attrs['tag']) : '';
        return in_array($requested, $tags, true) ? $requested : $tags[0];
    }

    /**
     * Builds the wrapper's attribute parts from htmlAttrs. WHY: security boundary — only attributes
     * the type declared in 'attrs' are emitted, each escaped or enum-checked.
     *
     * @param array<string, mixed> $type
     * @param array<string, mixed> $html_attrs
     * @return array<int, string>
     */
    private static function build_html_attrs(array $type, array $html_attrs, string $tag): array {
        $schema = is_array($type['attrs'] ?? null) ? $type['attrs'] : [];
        $out = [];

        // 'bh-element' always; author classes only if the type opted into 'class'.
        $classes = ['bh-element'];
        if (!empty($schema['class']) && !empty($html_attrs['class'])) {
            $extra = preg_split('/\s+/', trim((string) $html_attrs['class']));
            $extra = array_filter(array_map('sanitize_html_class', $extra));
            $classes = array_merge($classes, $extra);
        }
        $out[] = 'class="' . esc_attr(implode(' ', array_unique($classes))) . '"';

        if (!empty($schema['id']) && !empty($html_attrs['id'])) {
            // Letters/digits/hyphen/underscore, starting with a letter (a leading digit breaks CSS selectors).
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $html_attrs['id']);
            if ($id !== '' && preg_match('/^[A-Za-z]/', $id)) $out[] = 'id="' . esc_attr($id) . '"';
        }

        if (!empty($schema['title']) && !empty($html_attrs['title'])) {
            $out[] = 'title="' . esc_attr((string) $html_attrs['title']) . '"';
        }

        if (!empty($schema['aria-label']) && !empty($html_attrs['aria-label'])) {
            $out[] = 'aria-label="' . esc_attr((string) $html_attrs['aria-label']) . '"';
        }

        // href/target/rel only when the type declared them AND the tag is 'a' (enforced server-side).
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

        // htmlAttrs.custom: keys reduced to [a-z0-9-], values esc_attr()'d. Pre-declared keys are
        // enum-validated (dropped if invalid); others need the type's 'data-*' => true opt-in.
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

    /**
     * BHY_Style::safe_enum() when loaded, else a plain in_array().
     *
     * @param mixed $val
     * @param array<int, string> $allowed
     */
    private static function safe_enum_fallback($val, array $allowed): ?string {
        if (class_exists('BHY_Style')) return BHY_Style::safe_enum($val, $allowed);
        return in_array($val, $allowed, true) ? (string) $val : null;
    }

    /**
     * @param mixed $value
     * @return array<mixed>|bool|int|string
     */
    private static function coerce_attr($value, string $type) {
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
     * Surface-facing entry point: renders a slot's enabled root placements in order.
     * WHY: always returns the .bh-element-slot wrapper, even when empty — live preview anchors on it.
     *
     * @param array<string, mixed> $ctx
     */
    public static function render_slot(string $surface, int $context_id, string $slot, array $ctx = []): string {
        $placements = self::get_placements($surface, $context_id, $slot);

        // One query for the slot, one in-memory map; only roots iterate here, children render inside parents.
        $children_by_parent = $placements ? self::group_by_parent($placements) : [];
        $roots = $children_by_parent[0] ?? [];

        $out = '<div class="bh-element-slot" data-surface="' . esc_attr($surface) . '" data-slot="' . esc_attr($slot) . '">';
        foreach ($roots as $placement) {
            // render_placement() returns the fully wrapped element, children included.
            $html = self::render_placement($placement, $ctx, $children_by_parent);
            if ($html === '') continue;
            $out .= $html;
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Design Suite canvas preview for any registered surface, keyed by its real slug: every slot's
     * render_slot() output in declaration order, with a heading per slot.
     */
    public static function render_surface_preview(string $surface_slug, int $context_id = 0): string {
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

    /**
     * REST bridge for the builder GUI. Same 'ous/v1' namespace and manage_options-only permission
     * as BH_Studio (REST cookie auth + X-WP-Nonce). Every callback is a thin wrapper over public methods.
     */

    public static function register_routes(): void {
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

        // Style-section preset data (BHY_Style::style_schema_for_js()).
        register_rest_route('ous/v1', '/elements/style-schema', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_get_style_schema'],
        ]);

        // Global site tokens for the tree's synthetic "Site" node, using BHY_Style's own sanitizers.
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

        // True single-id delete (the GUI itself disables instead of deleting).
        register_rest_route('ous/v1', '/elements/placements/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_delete_placement'],
        ]);

        // WHY: takes only a placement_id and re-resolves that placement's own stored binds, so a caller
        // can never resolve an arbitrary source/args pair.
        register_rest_route('ous/v1', '/elements/resolve', [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_resolve'],
        ]);
    }

    /**
     * POST /elements/resolve — body { "placement_id": 123 }. Re-resolves stored binds with
     * $ctx = ['user_id' => get_current_user_id()] (never from the request). Returns only bound attrs.
     *
     * @return \WP_REST_Response|\WP_Error
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

    /**
     * DELETE /elements/placements/{id}.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_delete_placement(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        if ($id <= 0) {
            return new \WP_Error('bh_element_bad_id', 'A valid placement id is required.', ['status' => 400]);
        }
        $ok = self::delete_placement($id);
        return new \WP_REST_Response(['deleted' => $ok, 'id' => $id], 200);
    }

    /**
     * GET /elements/surfaces — registered surfaces + slots.
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_surfaces(\WP_REST_Request $req) {
        $out = [];
        foreach (self::registered_surfaces() as $slug => $surface) {
            // 'internal' surfaces (__library) are excluded: the Structure tree loads every surface at context 0.
            if (!empty($surface['internal'])) continue;
            // Callables like 'preview_ctx' are dropped; they mean nothing to a JSON client.
            $out[$slug] = [
                'group' => $surface['group'] ?? '',
                'label' => $surface['label'] ?? $slug,
                'slots' => is_array($surface['slots'] ?? null) ? $surface['slots'] : [],
            ];
        }
        return new \WP_REST_Response($out, 200);
    }

    /**
     * GET /elements/types?surface= — the palette.
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_types(\WP_REST_Request $req) {
        $surface = (string) $req->get_param('surface');
        $types = $surface !== '' ? self::types_for_surface($surface) : self::registered_types();

        $out = [];
        foreach ($types as $slug => $type) {
            // drop the 'render' callable
            $out[$slug] = [
                'label'     => $type['label'],
                'category'  => $type['category'],
                'icon'      => $type['icon'],
                'surfaces'  => $type['surfaces'],
                'container' => $type['container'],
                'schema'    => $type['schema'],
                'style'     => $type['style'],
                // exposed so the inspector builds Style/HTML-attribute controls per type
                'attrs'     => $type['attrs'],
                'tags'      => $type['tags'],
            ];
        }
        return new \WP_REST_Response($out, 200);
    }

    /**
     * GET /elements/sources?kind= — bindable data sources.
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_sources(\WP_REST_Request $req) {
        if (!class_exists('BH_Element_Data')) return new \WP_REST_Response([], 200);
        $kind = (string) $req->get_param('kind');
        $sources = $kind !== '' ? BH_Element_Data::sources_for_kind($kind) : BH_Element_Data::registered_sources();
        return new \WP_REST_Response($sources, 200);
    }

    /**
     * GET /elements/style-schema — preset tables for the inspector's Style section.
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_style_schema(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) return new \WP_REST_Response(['groups' => [], 'colorTokens' => []], 200);
        return new \WP_REST_Response(BHY_Style::style_schema_for_js(), 200);
    }

    /**
     * GET /elements/site-tokens — the global BHY_Style option row (no entity override).
     *
     * @return \WP_REST_Response
     */
    public static function rest_get_site_tokens(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) return new \WP_REST_Response([], 200);
        return new \WP_REST_Response(BHY_Style::get(), 200);
    }

    /**
     * POST /elements/site-tokens — body { "tokens": { "color_accent": "#ff3366", ... } }. Same
     * sanitizers and BHY_Style::DEFAULTS key allowlist as the Site Styles form.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_save_site_tokens(\WP_REST_Request $req) {
        if (!class_exists('BHY_Style')) {
            return new \WP_Error('bh_element_style_unavailable', 'BHY_Style is not loaded.', ['status' => 500]);
        }
        $body = json_decode($req->get_body(), true);
        $incoming = is_array($body['tokens'] ?? null) ? $body['tokens'] : [];

        // Same save authority as BHY_Gallery::save(), so custom sliders are not dropped.
        $data = BHY_Style::save_from_input($incoming);

        update_option(BHY_Style::OPTION, $data);
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $data);
        }
        return new \WP_REST_Response(BHY_Style::get(), 200);
    }

    /**
     * GET /elements/placements/{surface}/{context_id} — placements grouped by slot.
     *
     * @return \WP_REST_Response|\WP_Error
     */
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
     * POST /elements/placements/{surface}/{context_id} — upsert + order one slot in one request.
     * Body: { "slot": "main", "placements": [ { "id": 12, "element_type": "bh/note", "config": {...},
     *         "enabled": true, "parent_placement_id": 0 }, ... ] } in final order.
     * Entries without 'id' are inserted; unregistered types or rejected parents are skipped.
     * 'position' is assigned per parent group from the submitted order (sibling-scoped).
     *
     * @return \WP_REST_Response|\WP_Error
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
                continue; // unregistered/malformed entry: skip, never fatal for the batch
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
     * POST /elements/preview — renders an EPHEMERAL placement (never touches the DB).
     * Body: { "element_type": "bh/stat-card", "config": {...}, "ctx": {"user_id": 5} }.
     *
     * @return \WP_REST_Response|\WP_Error
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

    /* ---------------- Debug Tools: placement list + registered-types inspector ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-element'] = [
            'label'  => 'Element Builder',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
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
        echo '<p class="description">Phase 1\'s bare add/remove/reorder list, scoped to the <code>dashboard</code> / <code>main</code> surface/slot (context 0). The visual builder GUI (§4 of the design doc) is a later phase, not built here.</p>';
        self::render_dashboard_placement_list();
        self::render_add_placement_form();
        self::render_add_bound_demo_button();
    }

    // One click: adds a bh/stat-card whose 'value' binds to bhcore_events.count.
    private static function render_add_bound_demo_button(): void {
        if (!self::get_type('bh/stat-card') || !class_exists('BH_Element_Data') || !BH_Element_Data::is_registered('bhcore_events.count')) {
            return; // stat-card type or its source isn't registered this request
        }
        $nonce = wp_create_nonce('ous_element_debug_add_bound');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="ous_element_debug_action">';
        echo '<input type="hidden" name="op" value="add_bound_demo">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<button class="button">Add live stat-card (bound to bhcore_events.count, last 30 days, current user)</button>';
        echo '</form>';
    }

    private static function count_placements_for_type(string $slug): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE element_type = %s AND enabled = 1",
            $slug
        ));
    }

    private static function render_debug_op_form(string $op, int $placement_id, string $button_html, string $style = ''): string {
        $nonce = wp_create_nonce('ous_element_debug_' . $placement_id);
        $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($style ? ' style="' . esc_attr($style) . '"' : '') . '>';
        $out .= '<input type="hidden" name="action" value="ous_element_debug_action">';
        $out .= '<input type="hidden" name="op" value="' . esc_attr($op) . '">';
        $out .= '<input type="hidden" name="id" value="' . (int) $placement_id . '">';
        $out .= '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        $out .= $button_html;
        $out .= '</form>';
        return $out;
    }

    private static function render_dashboard_placement_list(): void {
        $placements = self::get_placements('dashboard', 0, 'main');
        if (!$placements) {
            echo '<p class="description">No placements in dashboard/main yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Type</th><th>Position</th><th>Config</th><th>Actions</th></tr></thead><tbody>';
        foreach ($placements as $p) {
            echo '<tr><td>' . (int) $p['id'] . '</td><td><code>' . esc_html($p['element_type']) . '</code></td><td>' . (int) $p['position'] . '</td><td><code style="font-size:11px;">' . esc_html(wp_json_encode($p['config'])) . '</code></td><td>';

            echo self::render_debug_op_form('delete', $p['id'], '<button class="button button-secondary" onclick="return confirm(\'Remove this placement?\');">Remove</button>', 'display:inline-block;margin-right:6px;');
            echo self::render_debug_op_form('move_up', $p['id'], '<button class="button">&uarr;</button>', 'display:inline-block;margin-right:6px;');
            echo self::render_debug_op_form('move_down', $p['id'], '<button class="button">&darr;</button>', 'display:inline-block;');

            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // Minimal add form for the 'dashboard' surface: type dropdown + one literal value field.
    private static function render_add_placement_form(): void {
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

    public static function handle_debug_post(): void {
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
                    // With no literal typed, the first bindable string attr stays unset so its default applies;
                    // otherwise the literal is stored as {"literal": ...}.
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
                            // 'format' so the demo also exercises a formatter ("1.2k").
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

    // Swaps $id's position with its adjacent sibling (same parent) in $direction (-1 up, +1 down).
    private static function move_placement(string $surface, int $context_id, string $slot, int $id, int $direction): bool {
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
        $index = (int) $index;

        $swap_index = $index + $direction;
        if ($swap_index < 0 || $swap_index >= count($ids)) return false;

        $ordered = $ids;
        [$ordered[$index], $ordered[$swap_index]] = [$ordered[$swap_index], $ordered[$index]];
        return self::reorder($surface, $context_id, $slot, $ordered, $parent_id);
    }
}
