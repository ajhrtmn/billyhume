<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element_Prefab — a named, reusable, saved composition of one or more
 * BH_Element placements, addable to this session's build on top of
 * ELEMENT-BUILDER-DESIGN-PLAN.md's own §6 phases at AJ's direct request
 * ("create and populate prefabs to be edited"). This is NOT in the
 * original design doc — it is a genuine addition, noted honestly in that
 * doc's own status-note trail (see the entry dated the same day this
 * class landed) rather than silently retrofitted into §2/§3 as if it had
 * always been planned.
 *
 * A prefab is DEFINITIONAL data, stored separately from live placements
 * (bhcore_element_prefabs, not a flag on bhcore_element_placements — see
 * class-identity-activator.php DB_VERSION 1.10's own comment on why).
 * Instantiating a prefab into a surface/slot creates brand-new,
 * independent placement rows (and, for any container entry, a brand-new
 * independent BH_Content document) — editing the instantiated copy never
 * touches the prefab definition, and editing the prefab definition never
 * retroactively changes anything already instantiated from it. This is
 * the sane default the task brief itself calls out and asks to be
 * confirmed, not designed fresh here: deep-copy on instantiate, prefab
 * definitions are otherwise inert until instantiated again.
 *
 * Storage shape of the 'definition' column (a JSON array), one entry per
 * placement the prefab was saved from:
 *   {
 *     "element_type": "bh/stat-card",
 *     "config": { "attrs": {...}, "style": {...} },
 *     "enabled": true,
 *     "content_tree": [ ... ]   // ONLY present for container element types —
 *                                // a full BH_Content tree snapshot (see
 *                                // save_from_slot()), independent of any
 *                                // live content_context_id.
 *   }
 * Deliberately excludes 'id', 'surface', 'surface_context_id', 'slot',
 * 'position', and 'content_context_id' from each entry — those are all
 * INSTANCE state, meaningless (or actively wrong, if copied verbatim) for
 * a definition meant to be instantiated repeatedly into different
 * surfaces/slots/contexts. 'position' is reconstructed from array order
 * at instantiate time, exactly like BH_Element::rest_save_placements()
 * already does for a normal slot save.
 *
 * REST routes below follow the EXACT same auth/nonce pattern as
 * BH_Element::register_routes() (manage_options + REST's own wp_rest
 * cookie-nonce contract, same ous/v1 namespace) — no new auth mechanism.
 *
 * NOT runtime-verified: no live PHP/MySQL/WordPress/REST execution is
 * available in this sandbox. Reasoned through against BH_Element's own
 * already-working placement storage/REST shape and BH_Content's own
 * get()/save() contract, brace/logic-checked, but not smoke-tested.
 * Please verify save-from-slot -> list -> instantiate -> confirm
 * independence (edit the instance, reload the prefab, confirm it's
 * unchanged) against a real install before relying on this.
 *
 * 3.4.36 closes this class's own honestly-disclosed "flat/root-only"
 * gap referenced above: `save_from_node()` snapshots a SINGLE node PLUS
 * its full recursive subtree (via the new `BH_Element::get_subtree()`),
 * storing each entry's parent as a relative 'parent_ref' array index
 * rather than a real id; `instantiate()` now performs a REAL id-
 * remapping pass on restore, allocating brand-new ids for every entry
 * (root-first order, guaranteed by save_from_node()) and rewriting each
 * child's `parent_placement_id` to point at its NEWLY created parent —
 * never the stale prefab-time id. `save_from_slot()`'s original flat,
 * whole-slot definitions are UNCHANGED and still supported (their
 * entries simply carry no 'parent_ref', so instantiate() treats them
 * exactly as it always did — every entry lands at the target root). See
 * `save_from_node()` and `instantiate()`'s own updated docblocks for the
 * exact contract.
 */
class BH_Element_Prefab {
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_element_prefabs';
    }

    /* =================================================================
     * Storage
     * ================================================================= */

    /** @return array Every prefab row, 'definition' json_decode()d, newest first. */
    public static function all() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC, id DESC', ARRAY_A);
        if (!$rows) return [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['definition'], true);
            $row['definition'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode((string) $row['definition'], true);
        $row['definition'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /**
     * Builds a prefab definition from a slot's CURRENT live placements
     * ("Save as Prefab" in the builder GUI, §4/task-brief). Strips every
     * instance-specific column (§ class docblock above) and, for any
     * container placement, snapshots its BH_Content tree inline as
     * 'content_tree' — the deep-copy contract starts here: the prefab
     * never references the live content_context_id again after this
     * call, only a plain, disconnected copy of the tree as it stood at
     * save time.
     *
     * @param string $surface
     * @param int    $context_id
     * @param string $slot
     * @param string $name
     * @param string $description
     * @return int|false the new prefab id, or false (no placements found, or insert failed).
     */
    public static function save_from_slot($surface, $context_id, $slot, $name, $description = '') {
        if (!class_exists('BH_Element')) return false;
        $placements = BH_Element::get_placements($surface, $context_id, $slot);
        if (!$placements) return false;

        $definition = [];
        foreach ($placements as $p) {
            $type = BH_Element::get_type($p['element_type']);
            $entry = [
                'element_type' => $p['element_type'],
                'config'       => $p['config'],
                'enabled'      => !empty($p['enabled']),
            ];
            if ($type && $type['container'] && class_exists('BH_Content') && !empty($p['content_context_id'])) {
                $entry['content_tree'] = BH_Content::get('bh_element', (int) $p['content_context_id']);
            }
            $definition[] = $entry;
        }

        return self::insert($name, $description, $definition);
    }

    /**
     * 3.4.36 — the FINAL-ARCHITECTURE subtree prefab: snapshots ONE
     * placement PLUS every recursive descendant (BH_Element::get_subtree(),
     * root-first) as a single definition, instead of save_from_slot()'s
     * whole-slot-flat snapshot above. Each entry keeps the SAME shape
     * save_from_slot() produces ('element_type'/'config'/'enabled'/
     * 'content_tree'), plus one new key: 'parent_ref' — the ARRAY INDEX
     * (within this same definition, 0-based) of the entry's parent, or
     * null for the root (always index 0). Storing a relative index rather
     * than a real parent_placement_id is deliberate: real ids are
     * meaningless once this prefab is instantiated elsewhere (a fresh set
     * of ids is always allocated — see instantiate()'s remap pass below),
     * exactly the same "no instance-specific column" reasoning the class
     * docblock already gives for omitting id/surface/slot/position.
     *
     * @param int    $placement_id root of the subtree to snapshot
     * @param string $name
     * @param string $description
     * @return int|false the new prefab id, or false (unknown placement, or insert failed).
     */
    public static function save_from_node($placement_id, $name, $description = '') {
        if (!class_exists('BH_Element')) return false;
        $subtree = BH_Element::get_subtree((int) $placement_id);
        if (!$subtree) return false;

        // Map real placement id => its position (array index) in $subtree,
        // so each entry's real parent_placement_id can be translated into
        // a relative 'parent_ref' index. The root's own parent is outside
        // the subtree by definition, so it always gets parent_ref = null
        // regardless of its real parent_placement_id (which may be a real
        // ancestor placement, or 0/root-of-slot — either way, not part of
        // this snapshot).
        $id_to_index = [];
        foreach ($subtree as $i => $row) {
            $id_to_index[(int) $row['id']] = $i;
        }

        $definition = [];
        foreach ($subtree as $i => $p) {
            $type = BH_Element::get_type($p['element_type']);
            $parent_id = (int) ($p['parent_placement_id'] ?? 0);
            $entry = [
                'element_type' => $p['element_type'],
                'config'       => $p['config'],
                'enabled'      => !empty($p['enabled']),
                'parent_ref'   => ($i === 0) ? null : ($id_to_index[$parent_id] ?? null),
            ];
            if ($type && $type['container'] && class_exists('BH_Content') && !empty($p['content_context_id'])) {
                $entry['content_tree'] = BH_Content::get('bh_element', (int) $p['content_context_id']);
            }
            $definition[] = $entry;
        }

        return self::insert($name, $description, $definition);
    }

    /** Low-level insert — also used directly by rest_save() when the caller posts a definition array instead of a slot reference. */
    public static function insert($name, $description, array $definition) {
        global $wpdb;
        $name = sanitize_text_field((string) $name);
        if ($name === '') $name = 'Untitled prefab';

        $slug = sanitize_title($name);
        if ($slug === '') $slug = 'prefab';
        $slug = self::unique_slug($slug);

        $ok = $wpdb->insert(self::table(), [
            'slug'        => $slug,
            'name'        => $name,
            'description' => sanitize_textarea_field((string) $description),
            'definition'  => wp_json_encode(array_values($definition)),
            'created_by'  => get_current_user_id(),
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    private static function unique_slug($base) {
        global $wpdb;
        $slug = $base;
        $i = 2;
        while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE slug = %s', $slug))) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * Update an existing prefab's own name/description/definition — this
     * is "editing the prefab definition itself" per the task brief's
     * confirmed-sane-default: it does NOT touch any placement previously
     * instantiated from this prefab (those are independent rows with no
     * stored link back to this prefab id at all — see instantiate()'s
     * docblock for why no such link is kept). A future re-instantiation
     * picks up the update; nothing already placed does.
     */
    public static function update($id, $name, $description, array $definition) {
        global $wpdb;
        $id = (int) $id;
        if (!$id || !self::get($id)) return false;
        $ok = $wpdb->update(self::table(), [
            'name'        => sanitize_text_field((string) $name),
            'description' => sanitize_textarea_field((string) $description),
            'definition'  => wp_json_encode(array_values($definition)),
        ], ['id' => $id]);
        return $ok !== false;
    }

    public static function delete($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $id]);
    }

    /**
     * Instantiates a prefab into a target surface+slot as brand-new,
     * independent placement rows — the deep-copy contract in full:
     *   - Each definition entry becomes a NEW placement (id => 0, forcing
     *     BH_Element::save_placement()'s insert path, never an update of
     *     an existing row).
     *   - Container entries get a NEW BH_Content document too:
     *     save_placement() auto-assigns content_context_id = the new
     *     placement's own id (see that method's docblock), and THEN this
     *     method writes the definition's saved 'content_tree' into that
     *     brand-new context id via BH_Content::save() — a real copy, not
     *     a shared reference to whatever content_context_id the original
     *     source placement used.
     *   - No column anywhere records "instantiated from prefab #N" —
     *     deliberate: linking back would make the sane "editing the
     *     prefab doesn't retroactively change instances" contract subtle
     *     (a stray future feature could accidentally start reading that
     *     link to propagate changes). If a provenance/back-reference is
     *     wanted later, that is a real, separate design decision, not an
     *     accidental side effect of this table's schema.
     *
     * 3.4.36 — full-subtree restore. A definition entry MAY carry a
     * 'parent_ref' (save_from_node()'s relative index into this same
     * definition array — see that method's docblock). This method
     * performs a REAL id-remapping pass: entries are always processed in
     * their stored array order, which save_from_node() guarantees is
     * root-first (a parent's index is always lower than any of its
     * children's), so by the time an entry with parent_ref = N is
     * reached, index N has ALREADY been instantiated and its brand-new
     * real placement id is on hand in $index_to_new_id. That real id
     * becomes this entry's `parent_placement_id`, never the old prefab-
     * time id (which may not even exist anymore) and never the stale
     * relative index itself. Entries with no 'parent_ref' key at all
     * (save_from_slot()'s flat, whole-slot definitions — the pre-3.4.36
     * shape) behave exactly as before: every entry lands at the target
     * root (parent_placement_id 0, or $under_placement_id if given).
     *
     * @param int      $id
     * @param string   $surface
     * @param int      $context_id
     * @param string   $slot
     * @param int      $under_placement_id Optional — 0 means "instantiate at the slot root" (unchanged default behavior); a non-zero value is an EXISTING placement id to instantiate the whole subtree UNDER (the contextual "add child from prefab" flow), itself validated the same way save_placement() already validates any parent (same surface/context/slot, no cycle — impossible here since these are brand-new ids anyway).
     * @return array Ordered list of newly created placement ids ([] on failure/unknown prefab).
     */
    public static function instantiate($id, $surface, $context_id, $slot, $under_placement_id = 0) {
        if (!class_exists('BH_Element')) return [];
        $prefab = self::get($id);
        if (!$prefab) return [];

        if (!BH_Element::get_surface($surface)) return [];

        $under_placement_id = (int) $under_placement_id;

        $existing = BH_Element::get_placements($surface, $context_id, $slot);
        $next_position = $existing ? (max(array_column($existing, 'position')) + 1) : 0;

        $new_ids = [];
        $index_to_new_id = [];
        foreach ($prefab['definition'] as $index => $entry) {
            if (!is_array($entry) || empty($entry['element_type']) || !BH_Element::get_type((string) $entry['element_type'])) {
                continue; // unregistered/deactivated type since the prefab was saved — skip, never fatal for the rest of the batch
            }

            $parent_ref = array_key_exists('parent_ref', $entry) ? $entry['parent_ref'] : null;
            if ($parent_ref !== null && isset($index_to_new_id[$parent_ref])) {
                $parent_placement_id = $index_to_new_id[$parent_ref];
            } else {
                // Root of the subtree (parent_ref === null), OR a
                // parent_ref that failed to resolve (its own entry was
                // skipped above as unregistered) — falls back to the
                // instantiation's own target root rather than orphaning
                // the node with a dangling reference.
                $parent_placement_id = $under_placement_id;
            }

            $new_id = BH_Element::save_placement([
                'surface'             => $surface,
                'surface_context_id'  => $context_id,
                'slot'                => $slot,
                'position'            => $next_position++,
                'element_type'        => (string) $entry['element_type'],
                'config'              => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'content_context_id'  => 0, // always 0 on instantiate — save_placement() auto-assigns a NEW id for container types, never reusing the prefab's own snapshot's original context
                'enabled'             => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : true,
                'parent_placement_id' => $parent_placement_id,
            ]);
            if (!$new_id) continue;

            $index_to_new_id[$index] = $new_id;

            if (!empty($entry['content_tree']) && is_array($entry['content_tree']) && class_exists('BH_Content')) {
                // The new placement, if it's a container type, now has
                // content_context_id === $new_id (save_placement()'s
                // auto-assign) — write the prefab's saved tree snapshot
                // into that brand-new, independent context.
                BH_Content::save('bh_element', $new_id, $entry['content_tree']);
            }

            $new_ids[] = $new_id;
        }

        return $new_ids;
    }

    /**
     * 3.4.47 — §5 "no special-cased pages," Gutenberg block v1 (class-
     * gutenberg-block.php's OUS_Gutenberg_Block). Renders a prefab's
     * definition READ-ONLY, IN MEMORY, with ZERO database writes — the
     * opposite of instantiate() above, which always persists brand-new
     * placement rows. A Gutenberg post has no BH_Element surface/slot of
     * its own to instantiate into (and re-instantiating on every single
     * page view would leak a new row per view, which is exactly the bug
     * this method exists to avoid) — this just asks "what would this
     * prefab's tree render as right now?" without ever touching
     * bhcore_element_placements.
     *
     * Mechanically: mirrors instantiate()'s own parent_ref -> real-id
     * remap pass, except the "ids" allocated are NEGATIVE, in-memory-only
     * sequence numbers (never real, never looked up against the DB) —
     * negative specifically so BH_Element::render_placement()'s
     * data-placement-id wrapper attribute can never collide with, or be
     * confused for, a real row's positive autoincrement id (relevant if
     * a type here happens to be marked 'live' => true — §3.2's resolve
     * REST route does a real DB lookup by id, which simply 404s
     * harmlessly for a negative id rather than risking a coincidental
     * collision with an unrelated real placement). Container entries'
     * 'content_tree' is passed straight through on the fake placement
     * array — render_placement()'s own updated branch (this session's
     * 3.4.47 pass) renders it inline instead of trying to look up a
     * (nonexistent, for these fake rows) content_context_id.
     *
     * @param int   $id  prefab id
     * @param array $ctx render context passed straight through to
     *                    render_placement() (e.g. ['user_id' => ...]) —
     *                    same shape a real render_slot() call would build.
     * @return string concatenated HTML of every ROOT entry (parent_ref
     *                 === null) in this prefab, each with its own
     *                 descendants nested inside via the same children-by-
     *                 parent mechanism render_placement() already uses
     *                 for the real parent_placement_id tree. Empty string
     *                 on any failure (unknown prefab id, BH_Element
     *                 absent) — same silent-degrade posture every other
     *                 render path in this ecosystem uses.
     */
    public static function render_definition($id, array $ctx = []) {
        if (!class_exists('BH_Element')) return '';
        $prefab = self::get($id);
        if (!$prefab || !is_array($prefab['definition'])) return '';

        $fake_placements = [];      // fake_id => placement-shaped array
        $index_to_fake_id = [];
        $roots = [];                // fake_ids with no parent
        $children_by_parent = [];   // parent fake_id => [child placement, ...]
        $next_fake_id = -1;

        foreach ($prefab['definition'] as $index => $entry) {
            if (!is_array($entry) || empty($entry['element_type']) || !BH_Element::get_type((string) $entry['element_type'])) {
                continue; // unregistered/deactivated type — skip, same as instantiate()
            }
            // Disabled entries (and therefore their whole subtree, since
            // they're never added to $index_to_fake_id and so can never
            // be resolved as anyone's parent_ref) are excluded entirely
            // here, not just filtered at the root loop below — matching
            // the real DB path, where get_placements()'s own WHERE
            // enabled=1 means a disabled placement and its descendants
            // never even enter render_slot()'s children-by-parent map to
            // begin with.
            if (array_key_exists('enabled', $entry) && !$entry['enabled']) continue;

            $fake_id = $next_fake_id--;
            $index_to_fake_id[$index] = $fake_id;

            $placement = [
                'id'            => $fake_id,
                'element_type'  => (string) $entry['element_type'],
                'config'        => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'enabled'       => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : true,
            ];
            if (!empty($entry['content_tree']) && is_array($entry['content_tree'])) {
                $placement['content_tree'] = $entry['content_tree']; // render_placement()'s new inline branch
            }
            $fake_placements[$fake_id] = $placement;

            $parent_ref = array_key_exists('parent_ref', $entry) ? $entry['parent_ref'] : null;
            if ($parent_ref !== null && isset($index_to_fake_id[$parent_ref])) {
                $parent_fake_id = $index_to_fake_id[$parent_ref];
                $children_by_parent[$parent_fake_id][] = $placement;
            } else {
                $roots[] = $fake_id; // null parent_ref, or a ref that didn't resolve (its own entry skipped above) — treat as a root, same "never orphan" posture instantiate() uses
            }
        }

        // Every entry that reached this point is already enabled (the
        // loop above excludes disabled entries and their descendants
        // entirely) — nothing left to filter, just render each root.
        $html = '';
        foreach ($roots as $fake_id) {
            $html .= BH_Element::render_placement($fake_placements[$fake_id], $ctx, $children_by_parent);
        }
        return $html;
    }

    /* =================================================================
     * REST bridge — same manage_options + wp_rest cookie-nonce contract
     * as BH_Element::register_routes(), same 'ous/v1' namespace.
     * ================================================================= */

    public static function register_routes() {
        register_rest_route('ous/v1', '/elements/prefabs', [
            [
                'methods'             => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_list'],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_save'],
            ],
        ]);

        register_rest_route('ous/v1', '/elements/prefabs/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_get_one'],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_update'],
            ],
            [
                'methods'             => 'DELETE',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback'            => [self::class, 'rest_delete'],
            ],
        ]);

        register_rest_route('ous/v1', '/elements/prefabs/(?P<id>\d+)/instantiate', [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'callback'            => [self::class, 'rest_instantiate'],
        ]);
    }

    /**
     * GET /elements/prefabs — list, summarized (id/slug/name/description
     * plus a lightweight "what's inside" count-of-element-types summary,
     * per the task brief's "listed/browsed ... maybe a thumbnail-less
     * summary of what element types it contains") — the full 'definition'
     * payload is NOT sent here (it can be large and isn't needed for a
     * picker list); GET .../prefabs/{id} returns the full definition for
     * when it's actually needed (edit view, or client-side instantiate
     * preview).
     */
    public static function rest_list(\WP_REST_Request $req) {
        $out = [];
        foreach (self::all() as $row) {
            $types = [];
            foreach ($row['definition'] as $entry) {
                if (!empty($entry['element_type'])) $types[] = $entry['element_type'];
            }
            $out[] = [
                'id'          => (int) $row['id'],
                'slug'        => $row['slug'],
                'name'        => $row['name'],
                'description' => $row['description'],
                'element_count' => count($row['definition']),
                'element_types' => array_values(array_unique($types)),
                'updated_at'  => $row['updated_at'],
            ];
        }
        return new \WP_REST_Response($out, 200);
    }

    public static function rest_get_one(\WP_REST_Request $req) {
        $prefab = self::get((int) $req->get_param('id'));
        if (!$prefab) return new \WP_Error('bh_element_prefab_not_found', 'Prefab not found.', ['status' => 404]);
        return new \WP_REST_Response($prefab, 200);
    }

    /**
     * POST /elements/prefabs — save a new prefab. Body is ONE OF:
     *   { "surface": "dashboard", "context_id": 0, "slot": "main", "name": "...", "description": "..." }
     *     — whole-slot flat snapshot (save_from_slot(), unchanged pre-3.4.36 shape).
     *   { "placement_id": 42, "name": "...", "description": "..." }
     *     — 3.4.36: save ONE node plus its full subtree (save_from_node()) —
     *       the "save as prefab" action from the contextual node context
     *       menu in the new unified tree, per DESIGN-SUITE-UNIFICATION-
     *       PLAN.md's "FINAL ARCHITECTURE" note.
     *   { "name": "...", "description": "...", "definition": [ ...entries... ] }
     *     — a raw definition array, for a REST client that already has entry shapes on hand.
     */
    public static function rest_save(\WP_REST_Request $req) {
        $body = json_decode($req->get_body(), true);
        $body = is_array($body) ? $body : [];
        $name = (string) ($body['name'] ?? '');
        $description = (string) ($body['description'] ?? '');

        if (isset($body['definition']) && is_array($body['definition'])) {
            $id = self::insert($name, $description, $body['definition']);
        } elseif (!empty($body['placement_id'])) {
            $id = self::save_from_node((int) $body['placement_id'], $name, $description);
        } elseif (!empty($body['surface']) && !empty($body['slot'])) {
            $id = self::save_from_slot(
                (string) $body['surface'],
                (int) ($body['context_id'] ?? 0),
                (string) $body['slot'],
                $name,
                $description
            );
        } else {
            return new \WP_Error('bh_element_prefab_bad_request', "Provide 'definition', 'placement_id', or 'surface'+'slot'.", ['status' => 400]);
        }

        if (!$id) {
            return new \WP_Error('bh_element_prefab_save_failed', 'Could not save prefab (empty slot, or insert failed).', ['status' => 400]);
        }
        return new \WP_REST_Response(self::get($id), 200);
    }

    /** POST /elements/prefabs/{id} — update the prefab's own definition (task brief's "editing the prefab definition itself" path; does not touch existing instances — see update()'s docblock). */
    public static function rest_update(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        $body = json_decode($req->get_body(), true);
        $body = is_array($body) ? $body : [];

        $existing = self::get($id);
        if (!$existing) return new \WP_Error('bh_element_prefab_not_found', 'Prefab not found.', ['status' => 404]);

        $name = array_key_exists('name', $body) ? (string) $body['name'] : $existing['name'];
        $description = array_key_exists('description', $body) ? (string) $body['description'] : $existing['description'];
        $definition = isset($body['definition']) && is_array($body['definition']) ? $body['definition'] : $existing['definition'];

        $ok = self::update($id, $name, $description, $definition);
        if (!$ok) return new \WP_Error('bh_element_prefab_update_failed', 'Update failed.', ['status' => 500]);
        return new \WP_REST_Response(self::get($id), 200);
    }

    public static function rest_delete(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        return new \WP_REST_Response(['deleted' => self::delete($id), 'id' => $id], 200);
    }

    /**
     * POST /elements/prefabs/{id}/instantiate — copy the prefab into a
     * target surface+slot as fresh, independent placements (instantiate()
     * above). Body: { "surface": "dashboard", "context_id": 0, "slot": "main",
     * "under_placement_id": 0 }. 3.4.36 — 'under_placement_id' is optional;
     * omitted/0 keeps the pre-3.4.36 behavior (land at the slot root),
     * a non-zero value instantiates the whole prefab (subtree or flat) as
     * children of that existing placement — the contextual "add child ->
     * from prefab" action in the new unified tree.
     */
    public static function rest_instantiate(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        $body = json_decode($req->get_body(), true);
        $body = is_array($body) ? $body : [];

        $surface = (string) ($body['surface'] ?? '');
        $slot = (string) ($body['slot'] ?? '');
        $context_id = (int) ($body['context_id'] ?? 0);
        $under_placement_id = (int) ($body['under_placement_id'] ?? 0);

        if (!$surface || !$slot) {
            return new \WP_Error('bh_element_prefab_bad_request', "'surface' and 'slot' are required.", ['status' => 400]);
        }
        if (class_exists('BH_Element') && !BH_Element::get_surface($surface)) {
            return new \WP_Error('bh_element_unknown_surface', "Surface '$surface' is not registered.", ['status' => 404]);
        }

        $new_ids = self::instantiate($id, $surface, $context_id, $slot, $under_placement_id);
        if (!$new_ids) {
            return new \WP_Error('bh_element_prefab_instantiate_failed', 'Nothing was created — unknown prefab, empty definition, or every entry\'s element type is no longer registered.', ['status' => 400]);
        }

        return new \WP_REST_Response([
            'created'    => $new_ids,
            'placements' => class_exists('BH_Element') ? BH_Element::get_placements($surface, $context_id, $slot) : [],
        ], 200);
    }
}
