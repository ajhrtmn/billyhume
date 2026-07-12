<?php
if (!defined('ABSPATH')) exit;

// BHCRM_VER 1.3.4 — PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md (plugins
// root, new 2026-07-12) — AJ named a specific reference app, TrackIt
// (a macOS task tracker for music producers/labels/mastering
// engineers), and asked to duplicate its full feature set here: "I
// basically will want to duplicate all of Track Its functionality."
// That doc is a DETAILED PLAN ONLY, not built — reusable checklists,
// timestamped fixes, a feedback log, stall analytics, separate
// scenes/boards, and (honestly scoped as the least portable) linked
// local audio/MIDI files are all designed there with a phased build
// order, judgment calls, and an explicit "not ported" list (native DAW
// launching; a per-app theme builder, since this ecosystem already has
// one ecosystem-wide). Column customization and per-card completion
// roll-up already match TrackIt's own equivalent features — see that
// doc's §2 for the exact mapping against what's already built below.
// Read that doc before starting any of these — this class's own
// comment here is only a pointer, not a duplicate of the plan.
//
// BHCRM_VER 1.3.0 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (§1.5): new
// list_all() + render_boards() — a thin, real listing page for the new
// 'bh-crm-projects' submenu (class-hub.php's CRM top-level menu), giving
// Project Tracker a first-class menu entry instead of being reachable
// only through a project_id dispatch buried inside a person's profile.
// Purely additive: list_for_person()/render_board()/the existing
// project_id dispatch in BHCRM_People::render() are all untouched — a
// board is still reachable from a person too, per the design doc.

/**
 * BHCRM_Projects — the kanban-style nested-sticky-note project tracker,
 * built ON TOP OF own-ur-shit's existing "element builder" system
 * (BH_Element / BH_Element_Data / BH_Content / BH_Studio) rather than as
 * a bespoke parallel data model. Added 1.2.0 at direct request: "a
 * kanban-like nested-sticky-note project tracker... for tracking
 * commissioned art project roadmaps."
 *
 * ============================================================
 * DATA MODEL — read this before touching anything below
 * ============================================================
 *
 * A "project" is a lightweight row in this plugin's OWN table,
 * {$wpdb->prefix}bhcrm_projects (id, name, crm_person_id, columns_config
 * JSON array of column-label strings, created_at, updated_at). This is
 * genuinely bh-crm's own concern (a project belongs to a CRM person),
 * matching class-people.php's own precedent of owning CRM-specific
 * tables/registrations directly rather than pushing them into
 * own-ur-shit's core.
 *
 * A project's BOARD is an own-ur-shit element surface,
 * 'bhcrm_project_board' (registered below via the SAME
 * 'bh_element_surfaces' filter BHCRM_People::register_element_surface()
 * already uses for 'bh_crm_profile' — no new registration mechanism),
 * with surface_context_id = the project's own id (mirrors
 * BHCRM_People's own surface_context_id = user_id convention) and one
 * slot, 'board', holding 'bh/sticky-card' BH_Element placements — one
 * placement per kanban card, at the top level.
 *
 * KANBAN-COLUMN JUDGMENT CALL (documented in more depth in
 * own-ur-shit/ELEMENT-BUILDER-DESIGN-PLAN.md's new "§7 Project Tracker"
 * section, added alongside this pass): each card's column is a plain
 * schema attribute ('column', a literal string) on the bh/sticky-card
 * placement itself — NOT a separate slot per column. This was CONFIRMED,
 * not just assumed, by actually reading class-element.php's placement
 * storage (save_placement()/get_placements()/reorder()): a placement row
 * has exactly one 'slot' column (a fixed string key on a surface's fixed
 * manifest) and a JSON 'config' blob with no other structured/queryable
 * column — there is no placement-level "grouping key" independent of
 * slot at all. Since this tracker's column SETS are configurable per
 * project (columns_config), modeling each column as its own slot would
 * require either (a) dynamically registering slots per project into a
 * surface's otherwise-static 'slots' manifest (registered_surfaces() is
 * a request-cached array from a filter — plausible but adds real
 * plumbing BH_Element never anticipated), or (b) a fixed universal slot
 * set ("todo"/"in-progress"/"review"/"done") that can't actually be
 * renamed/reordered/added-to per project, contradicting the brief's
 * "columns_config JSON... configurable per project" requirement outright.
 * A plain config.attrs.column literal has neither problem: BH_Element's
 * REST bridge (rest_save_placements()) already round-trips arbitrary
 * config JSON with zero changes needed to class-element.php, and
 * reordering WITHIN a column is just this same slot's normal
 * 'position' field (client sorts/regroups by column client-side, same
 * as the visual builder GUI already groups-and-renders one slot's
 * placements by whatever key it likes).
 *
 * RECURSIVE SUB-TASK NESTING: bh/sticky-card is a CONTAINER element type
 * (same 'container' => true contract as bh/container in
 * class-element.php) — its content_context_id addresses a BH_Content
 * document at ('bh_element', content_context_id), auto-assigned to the
 * placement's own id by BH_Element::save_placement() the same way every
 * other container placement gets one. That BH_Content tree holds
 * 'bhcrm/sub-card' blocks (registered below via BH_Content::
 * register_block_type()) — title/notes/done, WITH NATIVE RECURSIVE
 * children (BH_Content's tree shape already supports 'children' => [...]
 * at any depth, for any registered type, with zero extra work from this
 * class), giving the Godot-scene-tree-style "sub-tasks can themselves
 * have sub-tasks" nesting the brief asks for, entirely for free from
 * BH_Content's own existing recursion in validate()/render(). This is
 * the SAME nesting bridge bh/container already uses — genuinely reused,
 * not reinvented.
 *
 * Sub-task editing itself happens through the EXISTING BH_Studio canvas
 * (admin.php?page=bh-studio&context_type=bh_element&context_id={placement_id}),
 * exactly the way element-builder.js's own inspector already tells a
 * user to "open Content Studio separately" for any container element —
 * this pass does NOT build a bespoke recursive drag-tree editor inside
 * the kanban board itself (real scope; the board links out instead).
 * The kanban board's OWN bespoke UI (kanban-board.js/.css) covers what a
 * generic three-pane builder does badly: a real two-axis (column x
 * position) drag-and-drop board view — but it SAVES through the exact
 * same POST ous/v1/elements/placements/{surface}/{context_id} route the
 * generic builder GUI uses (rest_save_placements() — a full-slot
 * upsert), and deletes through the same DELETE ous/v1/elements/
 * placements/{id} route. It is a thin presentation layer over the same
 * data, not a parallel data model — no bhcrm-owned table stores card
 * content anywhere.
 *
 * ROLL-UP COMPLETION: computed at RENDER time (rollup_counts() below),
 * walking the container placement's live BH_Content tree recursively
 * and counting 'bhcrm/sub-card' nodes with attrs.done === true against
 * the total found — nothing is cached/stored. CHOSEN SEMANTICS: a
 * parent card's OWN 'done' checkbox is never auto-toggled by its
 * children's completion state — the roll-up is purely an informational
 * "3/5 sub-tasks done" label next to the card's own, independently-set
 * done flag. Auto-completing the parent when every child is done was
 * considered and deliberately NOT implemented: it would require a write
 * on every render (or a separate save-time hook) just to keep a
 * DERIVABLE fact in sync, which is exactly the redundant-stored-roll-up
 * problem the task brief said to avoid. A future pass could add an
 * explicit "auto-complete parent" opt-in without changing this file's
 * read path at all.
 *
 * NOT runtime-verified: no live PHP/MySQL/WordPress/REST/browser
 * execution is available in this environment. Reasoned through against
 * BH_Element/BH_Element_Prefab/BH_Content's own already-read, working
 * shapes, and brace/logic-checked, but the full round trip (create
 * project -> add card -> drag between columns -> nest a sub-card -> see
 * roll-up update) has not been smoke-tested against a real install.
 */
class BHCRM_Projects {
    const DB_VERSION = '1.0';
    const DEFAULT_COLUMNS = ['To Do', 'In Progress', 'Review', 'Done'];

    public static function init() {
        // Called directly from bh-crm.php's own 'plugins_loaded' bootstrap
        // closure (not re-hooked to 'plugins_loaded' itself) — this method
        // IS already running during that hook's dispatch, so a cheap
        // version-gated upgrade check here runs once per request at the
        // same point BHR_Activator::maybe_upgrade() runs for bh-registry.
        self::maybe_upgrade();

        if (class_exists('BH_Element')) {
            add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
            self::register_element_type();
        }
        if (class_exists('BH_Content')) {
            self::register_content_block_type();
        }

        add_action('admin_post_bhcrm_project_create', [self::class, 'handle_create']);
        add_action('admin_post_bhcrm_project_save_columns', [self::class, 'handle_save_columns']);
        add_action('admin_post_bhcrm_project_delete', [self::class, 'handle_delete']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    /* =================================================================
     * Activation / schema — bh-crm's own DB_VERSION option, separate
     * from own-ur-shit's identity-activator DB_VERSION, same pattern
     * bh-registry's BHR_Activator establishes (versioned dbDelta, cheap
     * early-return, runs on every 'plugins_loaded' not just real
     * activation since a file-replace deploy never fires WP's own
     * activation hook).
     * ================================================================= */

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_projects_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhcrm_projects_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_projects_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            crm_person_id bigint(20) unsigned NOT NULL DEFAULT 0,
            columns_config longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY crm_person_id (crm_person_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_projects';
    }

    /* =================================================================
     * Project CRUD
     * ================================================================= */

    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode((string) $row['columns_config'], true);
        $row['columns_config'] = is_array($decoded) && $decoded ? array_values($decoded) : self::DEFAULT_COLUMNS;
        return $row;
    }

    public static function list_for_person($person_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE crm_person_id = %d ORDER BY updated_at DESC, id DESC',
            (int) $person_id
        ), ARRAY_A);
        if (!$rows) return [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['columns_config'], true);
            $row['columns_config'] = is_array($decoded) && $decoded ? array_values($decoded) : self::DEFAULT_COLUMNS;
        }
        unset($row);
        return $rows;
    }

    /**
     * Every project row, across every person — backs the new
     * 'bh-crm-projects' top-level submenu (render_boards() below). Same
     * shape as list_for_person(), just without the WHERE clause.
     */
    public static function list_all() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC, id DESC', ARRAY_A);
        if (!$rows) return [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['columns_config'], true);
            $row['columns_config'] = is_array($decoded) && $decoded ? array_values($decoded) : self::DEFAULT_COLUMNS;
        }
        unset($row);
        return $rows;
    }

    /**
     * The 'bh-crm-projects' submenu's real callback (class-registry.php's
     * bh-crm admin_menus entry, relocated by OUS_MenuMerge under the new
     * 'bh-crm-hub' top-level menu). Deliberately a THIN listing that links
     * each project into the EXISTING, already-working board dispatch
     * (admin.php?page=bh-crm&user_id=&project_id=, BHCRM_People::render())
     * rather than a new board-rendering code path — no board/kanban logic
     * is duplicated here, this only adds a cross-person index page.
     */
    public static function render_boards() {
        echo '<div class="wrap">';
        echo '<h1>Project Tracker</h1>';
        echo '<p class="description">Every project board across the CRM. Opening one lands on the same kanban board view reachable from a person\'s own profile — this is just a cross-person index, not a second board implementation.</p>';

        $rows = self::list_all();
        if (!$rows) {
            echo '<p>No projects yet. Create one from a person\'s profile in <a href="' . esc_url(admin_url('admin.php?page=bh-crm-hub')) . '">People</a>.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Project</th><th>Person</th><th>Cards</th><th>Updated</th><th></th></tr></thead><tbody>';
        foreach ($rows as $p) {
            $uid = (int) $p['crm_person_id'];
            $user = get_userdata($uid);
            $person_label = $user ? $user->display_name : ('User #' . $uid);
            $card_count = class_exists('BH_Element') ? count(BH_Element::get_placements('bhcrm_project_board', (int) $p['id'], 'board')) : 0;
            $board_url = admin_url('admin.php?page=bh-crm&user_id=' . $uid . '&project_id=' . (int) $p['id']);

            echo '<tr>';
            echo '<td><a href="' . esc_url($board_url) . '"><strong>' . esc_html($p['name']) . '</strong></a></td>';
            echo '<td>' . esc_html($person_label) . '</td>';
            echo '<td>' . (int) $card_count . '</td>';
            echo '<td>' . esc_html(mysql2date('M j, Y', $p['updated_at'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($board_url) . '">Open board</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public static function create($name, $person_id, array $columns = []) {
        global $wpdb;
        $name = sanitize_text_field((string) $name);
        if ($name === '') $name = 'Untitled project';
        $columns = self::sanitize_columns($columns ?: self::DEFAULT_COLUMNS);

        $ok = $wpdb->insert(self::table(), [
            'name'           => $name,
            'crm_person_id'  => (int) $person_id,
            'columns_config' => wp_json_encode($columns),
            'updated_at'     => current_time('mysql'),
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function update_columns($id, array $columns) {
        global $wpdb;
        $columns = self::sanitize_columns($columns);
        return (bool) $wpdb->update(self::table(), [
            'columns_config' => wp_json_encode($columns),
            'updated_at'     => current_time('mysql'),
        ], ['id' => (int) $id]);
    }

    private static function sanitize_columns(array $columns) {
        $out = [];
        foreach ($columns as $c) {
            $c = sanitize_text_field((string) $c);
            if ($c !== '') $out[] = $c;
        }
        return $out ?: self::DEFAULT_COLUMNS;
    }

    /**
     * Deletes a project row AND every board placement/content document
     * that belongs to it — a project has no other cross-references
     * anywhere else in this codebase (no other table stores a project
     * id), so this is a real, safe, full delete, not a soft-delete.
     */
    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if (class_exists('BH_Element')) {
            foreach (BH_Element::get_placements('bhcrm_project_board', $id, 'board') as $p) {
                if (!empty($p['content_context_id']) && class_exists('BH_Content')) {
                    // BH_Content has no delete() of its own (see
                    // class-content.php) — an orphaned bhcore_content row
                    // is harmless (never re-addressed once the owning
                    // placement is gone) but we null it out via save()
                    // with an empty tree to avoid leaving stale content
                    // sitting around indefinitely.
                    BH_Content::save('bh_element', (int) $p['content_context_id'], []);
                }
                BH_Element::delete_placement($p['id']);
            }
        }
        return (bool) $wpdb->delete(self::table(), ['id' => $id]);
    }

    /* =================================================================
     * own-ur-shit integration — element type + surface + content block
     * ================================================================= */

    public static function register_element_surface($surfaces) {
        $surfaces['bhcrm_project_board'] = [
            'group' => 'CRM',
            'label' => 'Project tracker board',
            'slots' => [
                'board' => ['label' => 'Board'],
            ],
            'context' => ['type' => 'project', 'param' => 'project_id'],
            'preview_ctx' => function () { return ['project_id' => 0]; },
        ];
        return $surfaces;
    }

    private static function register_element_type() {
        BH_Element::register_type('bh/sticky-card', [
            'label'    => 'Sticky card',
            'category' => 'data',
            'icon'     => 'dashicons-index-card',
            'surfaces' => ['bhcrm_project_board'],
            'container' => true, // nested sub-tasks live in this placement's own BH_Content tree — see class docblock
            'schema' => [
                'title'  => ['type' => 'string', 'default' => 'Untitled task', 'bindable' => false],
                'notes'  => ['type' => 'html',   'default' => '',              'bindable' => false],
                'done'   => ['type' => 'bool',   'default' => false,           'bindable' => false],
                // Plain literal grouping key, not a bindable data-source
                // attr — see class docblock's "KANBAN-COLUMN JUDGMENT
                // CALL" for why this is a config attr, not a slot.
                'column' => ['type' => 'string', 'default' => '',              'bindable' => false],
            ],
            'style' => ['color_accent', 'radius'],
            // DESIGN-SUITE-UNIFICATION-PLAN.md §2.6 — a sticky card is
            // always a <div>/<article> (never link-shaped), but it DOES
            // demonstrate a pre-declared, STRUCTURED custom data-attr
            // (the doc's own worked example): 'data-status' renders as
            // an enum picker in the inspector rather than free text, and
            // is enum-validated server-side in
            // BH_Element::build_html_attrs() regardless of what the
            // client sends.
            'tags'  => ['div', 'article'],
            'attrs' => [
                'id' => true, 'class' => true, 'aria-label' => true,
                'data-status' => ['enum' => ['todo', 'in_progress', 'done']],
            ],
            'render' => function (array $attrs, array $ctx, array $instance) {
                $title = esc_html((string) $attrs['title']);
                $notes = (string) $attrs['notes']; // already wp_kses_post()-coerced by BH_Element::coerce_attr() ('html' schema type)
                $done  = !empty($attrs['done']);
                $column = esc_attr((string) $attrs['column']);

                $rollup_html = '';
                if (class_exists('BH_Content') && !empty($instance['id'])) {
                    $tree = BH_Content::get('bh_element', (int) $instance['id']);
                    [$done_count, $total] = BHCRM_Projects::rollup_counts($tree);
                    if ($total > 0) {
                        $rollup_html = '<div class="bhcrm-sticky-card-rollup">' . (int) $done_count . '/' . (int) $total . ' sub-tasks done</div>';
                    }
                }

                $children_html = $instance['content'] !== '' ? '<div class="bhcrm-sticky-card-children">' . $instance['content'] . '</div>' : '';

                return '<div class="bhcrm-sticky-card' . ($done ? ' is-done' : '') . '" data-column="' . $column . '" data-placement-id="' . (int) $instance['id'] . '">'
                     . '<div class="bhcrm-sticky-card-title">' . ($done ? '&#9989; ' : '') . $title . '</div>'
                     . ($notes !== '' ? '<div class="bhcrm-sticky-card-notes">' . $notes . '</div>' : '')
                     . $rollup_html
                     . $children_html
                     . '</div>';
            },
        ]);
    }

    private static function register_content_block_type() {
        BH_Content::register_block_type('bhcrm/sub-card', [
            'title' => ['type' => 'string', 'default' => 'Sub-task'],
            'notes' => ['type' => 'html',   'default' => ''],
            'done'  => ['type' => 'bool',   'default' => false],
        ], function (array $attrs, $rendered_children, array $block) {
            $title = esc_html((string) $attrs['title']);
            $notes = (string) $attrs['notes'];
            $done  = !empty($attrs['done']);
            $children_html = $rendered_children !== '' ? '<div class="bhcrm-sub-card-children">' . $rendered_children . '</div>' : '';
            return '<div class="bhcrm-sub-card' . ($done ? ' is-done' : '') . '">'
                 . '<div class="bhcrm-sub-card-title">' . ($done ? '&#9745;' : '&#9744;') . ' ' . $title . '</div>'
                 . ($notes !== '' ? '<div class="bhcrm-sub-card-notes">' . $notes . '</div>' : '')
                 . $children_html
                 . '</div>';
        });
    }

    /**
     * Recursively counts 'bhcrm/sub-card' nodes in a raw (un-rendered)
     * BH_Content tree — the render-time roll-up this class's docblock
     * documents. Any OTHER registered content block type mixed into the
     * tree (shouldn't normally happen, since only 'bhcrm/sub-card' is
     * ever inserted by this class's own UI, but a hand-crafted REST call
     * could add one) is silently skipped for counting purposes, same
     * graceful-degrade posture as BH_Content::render() itself.
     *
     * @return array{0:int,1:int} [$done_count, $total_count]
     */
    public static function rollup_counts(array $tree) {
        $done = 0;
        $total = 0;
        foreach ($tree as $node) {
            if (($node['type'] ?? '') === 'bhcrm/sub-card') {
                $total++;
                if (!empty($node['attrs']['done'])) $done++;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                [$child_done, $child_total] = self::rollup_counts($node['children']);
                $done += $child_done;
                $total += $child_total;
            }
        }
        return [$done, $total];
    }

    /* =================================================================
     * CRM person page integration — "Projects" section + board view.
     * Both are rendered from BHCRM_People's existing single dispatch
     * page (admin.php?page=bh-crm) via a project_id query arg, NOT a
     * new standalone admin page — this install has a documented
     * WordPress-core hook-resolution bug that broke standalone pages
     * (see class-element-builder.php's docblock for the incident); the
     * bare Debug Tools seed action below follows the same proven Debug
     * Tools SECTION pattern for the same reason, and this board view
     * rides on bh-crm's ALREADY-WORKING single-page dispatch instead of
     * registering a second page of its own.
     * ================================================================= */

    /** Called from BHCRM_People::render_detail($uid) — additive section, same posture as the existing tags/notes editors. */
    public static function render_projects_section($uid) {
        $projects = self::list_for_person($uid);

        echo '<h3>Projects</h3>';
        if ($projects) {
            echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Columns</th><th>Cards</th><th>Updated</th><th></th></tr></thead><tbody>';
            foreach ($projects as $p) {
                $card_count = class_exists('BH_Element') ? count(BH_Element::get_placements('bhcrm_project_board', (int) $p['id'], 'board')) : 0;
                $board_url = add_query_arg(['user_id' => $uid, 'project_id' => $p['id']]);
                echo '<tr>';
                echo '<td><a href="' . esc_url($board_url) . '"><strong>' . esc_html($p['name']) . '</strong></a></td>';
                echo '<td>' . esc_html(implode(', ', $p['columns_config'])) . '</td>';
                echo '<td>' . (int) $card_count . '</td>';
                echo '<td>' . esc_html(mysql2date('M j, Y', $p['updated_at'])) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($board_url) . '">Open board</a> ';
                $del_nonce = wp_create_nonce('bhcrm_project_delete_' . $p['id']);
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
                echo '<input type="hidden" name="action" value="bhcrm_project_delete">';
                echo '<input type="hidden" name="project_id" value="' . (int) $p['id'] . '">';
                echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($del_nonce) . '">';
                echo '<button class="button button-small" onclick="return confirm(\'Delete this project and every card on its board? This cannot be undone.\');">Delete</button>';
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="description">No projects yet for this person.</p>';
        }

        $nonce = wp_create_nonce('bhcrm_project_create');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="bhcrm_project_create">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="text" name="project_name" placeholder="New project name (e.g. \'Fenwick — full character commission\')" style="width:360px;"> ';
        echo '<button class="button button-primary">Create project</button>';
        echo '</form>';
    }

    public static function handle_create() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_create')) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['project_name'] ?? ''));
        $id = self::create($name, $uid);

        $msg = $id ? "Created project #$id." : 'Failed to create project.';
        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'bhcrm_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public static function handle_save_columns() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_columns_' . $project_id)) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        $raw = sanitize_textarea_field(wp_unslash($_POST['columns'] ?? ''));
        $columns = array_filter(array_map('trim', explode("\n", $raw)));
        self::update_columns($project_id, $columns);

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'project_id' => $project_id, 'bhcrm_msg' => 'Columns updated.'], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_delete_' . $project_id)) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        self::delete($project_id);

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'bhcrm_msg' => 'Project deleted.'], admin_url('admin.php')));
        exit;
    }

    /** Renders the board view — the bespoke presentation layer's PHP shell; kanban-board.js fills it in against the standard BH_Element REST bridge. Called from BHCRM_People::render() when $_GET['project_id'] is set. */
    public static function render_board($project_id, $uid) {
        $project = self::get($project_id);
        echo '<p><a href="' . esc_url(remove_query_arg('project_id')) . '">&larr; Back to ' . esc_html(get_userdata($uid) ? get_userdata($uid)->display_name : 'person') . '</a></p>';

        if (!$project) {
            echo '<p>Project not found.</p>';
            return;
        }

        echo '<h2>' . esc_html($project['name']) . '</h2>';

        echo '<details style="margin-bottom:14px;"><summary style="cursor:pointer;">Edit columns</summary>';
        $nonce = wp_create_nonce('bhcrm_project_columns_' . $project_id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bhcrm_project_save_columns">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<p class="description">One column label per line, in the order they should appear on the board.</p>';
        echo '<textarea name="columns" rows="5" style="width:300px;">' . esc_textarea(implode("\n", $project['columns_config'])) . '</textarea><br>';
        echo '<button class="button">Save columns</button>';
        echo '</form></details>';

        echo '<noscript><p class="description">The kanban board requires JavaScript.</p></noscript>';
        echo '<div id="bhcrm-kanban-board" class="bhcrm-kanban-board" data-loading="1"><p class="description">Loading board&hellip;</p></div>';
    }

    /** Enqueues the kanban board's own JS/CSS only when actually viewing a project board (?page=bh-crm&project_id=). */
    public static function maybe_enqueue($hook) {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['project_id'])) return;
        if (!class_exists('BH_Element')) return;

        $project_id = (int) $_GET['project_id'];
        $project = self::get($project_id);
        if (!$project) return;

        wp_enqueue_style('bhcrm-kanban-board', BHCRM_URL . 'assets/css/kanban-board.css', [], BHCRM_VER);
        wp_enqueue_script('bhcrm-kanban-board', BHCRM_URL . 'assets/js/kanban-board.js', [], BHCRM_VER, true);

        wp_localize_script('bhcrm-kanban-board', 'bhcrmKanbanConfig', [
            // Same 'ous/v1/elements/' REST bridge + wp_rest cookie-nonce
            // contract element-builder.js already uses — no new route,
            // no new auth mechanism.
            'restUrl'    => esc_url_raw(rest_url('ous/v1/elements/')),
            'studioUrl'  => esc_url_raw(admin_url('admin.php?page=bh-studio')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'surface'    => 'bhcrm_project_board',
            'projectId'  => $project_id,
            'columns'    => $project['columns_config'],
        ]);
    }
}
