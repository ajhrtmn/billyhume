<?php
if (!defined('ABSPATH')) exit;

/**
 * Placement storage and REST bridge behind the bh-crm project board.
 *
 * Element TYPES are registered in code (register_type()); PLACEMENTS live in
 * bhcore_element_placements: "this type, configured this way, in this slot on this
 * surface for this context, at this position." Surfaces register via the
 * 'bh_element_surfaces' filter. Placements form a shallow tree per slot via
 * parent_placement_id (0 = root); save_placement() keeps the parent in the same
 * (surface, context, slot) and rejects cycles; delete_placement() promotes children
 * to root. The board itself renders cards client-side from REST data.
 */
class BH_Element {
    /** @var array<string, array<string, mixed>> slug => normalized type manifest */
    private static $types = [];

    /** @var array<string, array<string, mixed>>|null surface slug => surface manifest */
    private static $surfaces_cache = null;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /* ---------------- type registry ---------------- */

    /**
     * @param string $slug Namespaced type, e.g. 'bh/sticky-card'.
     * @param array<string, mixed> $args {
     *   'label'     => 'Sticky card',
     *   'surfaces'  => ['bhcrm_project_board'] | '*',
     *   'container' => false,   // owns a BH_Content subtree (content_context_id)?
     *   'schema'    => ['title' => ['type' => 'string', 'default' => '']],  // types: string|html|bool|int|url|array
     * }
     */
    public static function register_type(string $slug, array $args): bool {
        $slug = trim($slug);
        if ($slug === '') return false;

        $surfaces = $args['surfaces'] ?? [];
        if ($surfaces !== '*' && !is_array($surfaces)) $surfaces = [];

        self::$types[$slug] = [
            'label'     => (string) ($args['label'] ?? $slug),
            'surfaces'  => $surfaces,
            'container' => !empty($args['container']),
            'schema'    => is_array($args['schema'] ?? null) ? $args['schema'] : [],
        ];
        return true;
    }

    /** @return array<string, mixed>|null */
    public static function get_type(string $slug): ?array {
        return self::$types[(string) $slug] ?? null;
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
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['parent_placement_id'] = (int) ($row['parent_placement_id'] ?? 0);
        }
        unset($row);
        return $rows;
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

        $type = self::get_type(trim((string) ($placement['element_type'] ?? '')));
        $config = $type ? self::sanitize_config($type, $placement['config'] ?? []) : ($placement['config'] ?? []);

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
     * WHY: config is stored data the board edits as plain values, never trusted markup. Keeps only
     * the type's declared schema attrs, each coerced to its declared type (html -> wp_kses_post);
     * everything else (bindings, htmlAttrs, custom_js, unknown keys) is dropped.
     *
     * @param array<string, mixed> $type
     * @param mixed $config array or JSON string
     * @return array{attrs: array<string, array{literal: mixed}>}
     */
    private static function sanitize_config(array $type, $config): array {
        if (is_string($config)) $config = json_decode($config, true);
        $attrs = [];
        foreach ((array) ((is_array($config) ? $config : [])['attrs'] ?? []) as $key => $value) {
            $def = $type['schema'][$key] ?? null;
            if (!is_array($def)) continue;
            if (is_array($value)) {
                if (!array_key_exists('literal', $value)) continue;
                $value = $value['literal'];
            }
            $attrs[(string) $key] = ['literal' => self::coerce_attr($value, (string) ($def['type'] ?? 'string'))];
        }
        return ['attrs' => $attrs];
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

    /* ---------------- REST (admin-only, cookie auth + X-WP-Nonce) ---------------- */

    public static function register_routes(): void {
        $admin_only = function () { return current_user_can('manage_options'); };
        register_rest_route('ous/v1', '/elements/placements/(?P<surface>[\w-]+)/(?P<context_id>\d+)', [
            ['methods' => 'GET',  'permission_callback' => $admin_only, 'callback' => [self::class, 'rest_get_placements']],
            ['methods' => 'POST', 'permission_callback' => $admin_only, 'callback' => [self::class, 'rest_save_placements']],
        ]);
        register_rest_route('ous/v1', '/elements/placements/(?P<id>\d+)', [
            'methods' => 'DELETE', 'permission_callback' => $admin_only, 'callback' => [self::class, 'rest_delete_placement'],
        ]);
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
     * Body: { "slot": "main", "placements": [ { "id": 12, "element_type": "bh/sticky-card", "config": {...},
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

}
