<?php
if (!defined('ABSPATH')) exit;

// BHCRM_VER 1.3.4 — PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md (plugins
// root, new 2026-07-12) — AJ named a specific reference app, TrackIt
// (a macOS task tracker for music producers/labels/mastering
// engineers), and asked to duplicate its full feature set here: "I
// basically will want to duplicate all of Track Its functionality."
// Column customization and per-card completion roll-up already match
// TrackIt's own equivalent features — see that doc's §2 for the exact
// mapping against what's already built below.
//
// STATUS UPDATE (2026-07-25 audit pass — this is the SECOND time this
// comment has gone stale, see plugins/STATUS.md for the standing note
// about that): Phase A (see 2026-07-21 note below, still accurate) plus
// Phases B, C, and D are ALL built, not "genuinely unbuilt" as the prior
// version of this comment claimed. Phase B (timestamped fixes/feedback
// log) and Phase D (Idea Drop — linked files/uploads) both live in
// class-card-log.php (BHCRM_CardLog). Phase C (stall analytics) lives
// right in THIS file — see stalled_cards_for_board() and the
// bh-crm/v1/stalled-cards REST route below. "Scenes" (part of Phase E,
// separate boards) is also real — see distinct_scenes() below. Given
// this comment has drifted stale twice on the same file, treat
// plugins/STATUS.md as the source of truth for what's actually shipped
// going forward rather than trusting this local comment at face value —
// it's a pointer, not guaranteed current.
//
// STATUS UPDATE (2026-07-21, see plugins/STATUS.md): the line that used
// to sit here calling the whole plan "DETAILED PLAN ONLY, NOT BUILT" is
// stale — class-subtasks.php since shipped a real, substantial nested
// sub-task system (full kanban boards, not flat checklists, at every
// nesting level; versions 1.8.0-2.4.6). That's a genuinely different,
// arguably more capable mechanism than the plan's own Phase A (reusable
// checklist templates), not a literal implementation of it — read
// class-subtasks.php's own docblock for what actually shipped there.
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
 * own-ur-shit/ELEMENT-BUILDER-DESIGN-PLAN.md's "§7 Project Tracker"
 * section): each card's column is a plain
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
 * no bespoke recursive drag-tree editor inside the kanban board itself
 * (real scope; the board links out instead).
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
    const DB_VERSION = '1.2'; // 1.1 — PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md Phase E: bhcrm_projects.scene, a free-text, user-defined organizational grouping (same "no fixed enum" posture columns_config already uses) — render_boards() groups its listing by it, purely organizational, no context-moving semantics. 1.2 — Phase C: bhcrm_project_card_moves, one row per real kanban-column transition (or a card's very first column on creation) — see the 'bhcore_element_placement_saved' hook handler below.
    const STALL_DAYS = 5; // a card whose most recent logged move is at least this many days ago shows the "hasn't moved" badge
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
        add_action('admin_post_bhcrm_project_save_scene', [self::class, 'handle_save_scene']);
        add_action('admin_post_bhcrm_project_delete', [self::class, 'handle_delete']);
        add_action('admin_post_bhcrm_project_link', [self::class, 'handle_link_person']);
        add_action('admin_post_bhcrm_project_unlink', [self::class, 'handle_unlink_person']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        // Phase C stall analytics — own-ur-shit's ONE placement-save
        // write path (BH_Element::save_placement()) fires this generic
        // hook after every insert/update; this is a bh-crm-specific
        // consumer of it, not a change to how that hook itself works.
        add_action('bhcore_element_placement_saved', [self::class, 'on_placement_saved'], 10, 3);
    }

    /**
     * The top-level interactive board (kanban-board.js) never showed a
     * card's own recursive sub-task rollup at all — a real gap AJ
     * caught: "each card should track the total progress of everything
     * under it... display it back up on the card itself." The rollup
     * math itself (rollup_counts()) was already fully recursive from
     * day one; what was missing is a way for the CLIENT-SIDE board (a
     * thin presentation layer over BH_Element's generic REST bridge,
     * which returns each placement's own config/attrs but never its
     * BH_Content tree) to actually get those numbers without a
     * separate per-card round trip. One small bh-crm-owned route,
     * fetched once per board load, keeps this bh-crm-specific concern
     * out of own-ur-shit's generic placements endpoint entirely.
     */
    public static function register_rest_routes() {
        register_rest_route('bh-crm/v1', '/rollups', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_rollups'],
            'permission_callback' => function () { return current_user_can('bhcore_manage_crm'); },
            'args' => ['project_id' => ['required' => true, 'sanitize_callback' => 'absint']],
        ]);
        // Phase C — same "one small bh-crm-owned route, fetched once
        // per board load" shape as /rollups above, for the same reason:
        // stall status isn't part of the generic BH_Element placements
        // response, and doesn't need to be.
        register_rest_route('bh-crm/v1', '/stalled-cards', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_stalled_cards'],
            'permission_callback' => function () { return current_user_can('bhcore_manage_crm'); },
            'args' => ['project_id' => ['required' => true, 'sanitize_callback' => 'absint']],
        ]);
    }

    public static function rest_stalled_cards($req) {
        return new WP_REST_Response(self::stalled_cards_for_board((int) $req->get_param('project_id')), 200);
    }

    public static function rest_rollups($req) {
        $project_id = (int) $req->get_param('project_id');
        $out = [];
        if (class_exists('BH_Element') && class_exists('BH_Content')) {
            foreach (BH_Element::get_placements('bhcrm_project_board', $project_id, 'board') as $p) {
                $content_id = (int) ($p['content_context_id'] ?: $p['id']);
                $tree = BH_Content::get('bh_element', $content_id);
                [$done, $total] = self::rollup_counts($tree);
                if ($total > 0) $out[$p['id']] = [$done, $total];
            }
        }
        return new WP_REST_Response($out, 200);
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
            scene varchar(190) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY crm_person_id (crm_person_id),
            KEY scene (scene)
        ) $charset;");

        // Phase C — stall analytics. One row per real column
        // transition (card_placement_id = a bh/sticky-card's
        // bhcore_element_placements.id — no FK, since that table lives
        // in own-ur-shit and this one shouldn't hard-depend on its
        // exact engine/charset). "Most recent row per card" is the
        // card's current column + when it landed there; the gap
        // between consecutive rows for the same card is real
        // time-in-column, feeding average_time_in_column() below.
        $moves = $wpdb->prefix . 'bhcrm_project_card_moves';
        dbDelta("CREATE TABLE $moves (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_placement_id bigint(20) unsigned NOT NULL,
            column_label varchar(190) NOT NULL DEFAULT '',
            entered_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY card_placement_id (card_placement_id, entered_at)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $moves)) === $moves;
    }

    private static function moves_table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_project_card_moves';
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

    /**
     * QA fix — was a direct WHERE crm_person_id = %d query (a project
     * has exactly one hard-coded owner). Now backed by BHCRM_Links: a
     * person sees every project they're linked to under ANY relation
     * (owner, collaborator, watcher), matching the Jira/DevOps-style
     * "a project can have multiple people attached" model AJ asked for.
     */
    public static function list_for_person($person_id) {
        global $wpdb;
        $ids = class_exists('BHCRM_Links') ? BHCRM_Links::project_ids_for_person($person_id) : [];
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE id IN ($placeholders) ORDER BY updated_at DESC, id DESC",
            ...$ids
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
        echo '<h1>People &rsaquo; Project Tracker</h1>';
        echo '<p class="description">Every project board across the CRM. Opening one lands on the same kanban board view reachable from a person\'s own profile — this is just a cross-person index, not a second board implementation.</p>';

        // QA fix: project creation used to be reachable ONLY from a
        // specific person's profile page (their user_id was baked into
        // the create form's hidden field, back when a project required
        // exactly one owner at creation time). A project doesn't need
        // an owner to exist now (BHCRM_Links handles
        // ownership as an optional link, not a required column), so
        // creation belongs here, at the top level, same as any other
        // "Add new" entry point. Linking a person happens afterward on
        // the board itself (render_people_panel()), same as it would
        // for a second/third person on an existing project.
        $nonce = wp_create_nonce('bhcrm_project_create');
        $scene_suggestions = self::distinct_scenes();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;display:flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="action" value="bhcrm_project_create">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="text" name="project_name" placeholder="New project name (e.g. \'Fenwick — full character commission\')" style="width:360px;max-width:100%;">';
        echo '<input type="text" name="project_scene" list="bhcrm-scene-suggestions" placeholder="Scene (optional)" style="width:180px;max-width:100%;">';
        if ($scene_suggestions) {
            echo '<datalist id="bhcrm-scene-suggestions">';
            foreach ($scene_suggestions as $s) echo '<option value="' . esc_attr($s) . '">';
            echo '</datalist>';
        }
        echo '<button class="button button-primary">Create project</button>';
        echo '</form>';

        $rows = self::list_all();
        if (!$rows) {
            echo '<p>No projects yet — create one above, then link people to it from the board.</p>';
            echo '</div>';
            return;
        }

        // PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md Phase E — purely
        // organizational grouping, no context-moving semantics: a
        // scene only changes which heading a project's row sits under
        // in THIS listing, nothing about the project/board itself.
        // Every project already carries its own scene value (or none),
        // grouped here in PHP rather than a second query per scene.
        $groups = [];
        foreach ($rows as $p) {
            $scene = trim((string) $p['scene']);
            $groups[$scene === '' ? '' : $scene][] = $p;
        }
        // Named scenes first (alphabetical), "Unsorted" (no scene) last
        // — an unsorted project shouldn't visually lead the list.
        ksort($groups);
        if (isset($groups[''])) {
            $unsorted = $groups[''];
            unset($groups['']);
            $groups[''] = $unsorted;
        }

        foreach ($groups as $scene => $scene_rows) {
            echo '<h2>' . esc_html($scene !== '' ? $scene : 'Unsorted') . '</h2>';
            echo '<table class="widefat striped"><thead><tr><th>Project</th><th>People</th><th>Cards</th><th>Updated</th><th></th></tr></thead><tbody>';
            foreach ($scene_rows as $p) {
                // QA fix: a project can now be linked to multiple people
                // under different relations (BHCRM_Links) — show all of
                // them, not just the legacy single crm_person_id owner.
                $linked = class_exists('BHCRM_Links') ? BHCRM_Links::people_for_project($p['id']) : [];
                if ($linked) {
                    $labels = array_map(function ($l) {
                        $name = $l['user'] ? $l['user']->display_name : ('User #' . $l['user_id']);
                        return esc_html($name) . ' <span class="description">(' . esc_html(BHCRM_Links::RELATIONS[$l['relation']] ?? $l['relation']) . ')</span>';
                    }, $linked);
                    $person_label = implode(', ', $labels);
                } else {
                    $uid = (int) $p['crm_person_id'];
                    $user = $uid ? get_userdata($uid) : null;
                    $person_label = $user ? esc_html($user->display_name) : '<span class="description">No one linked</span>';
                }
                $board_uid = $linked ? $linked[0]['user_id'] : (int) $p['crm_person_id'];
                $card_count = class_exists('BH_Element') ? count(BH_Element::get_placements('bhcrm_project_board', (int) $p['id'], 'board')) : 0;
                $board_url = admin_url('admin.php?page=bh-crm&user_id=' . $board_uid . '&project_id=' . (int) $p['id']);

                echo '<tr>';
                echo '<td><a href="' . esc_url($board_url) . '"><strong>' . esc_html($p['name']) . '</strong></a></td>';
                echo '<td>' . $person_label . '</td>';
                echo '<td>' . (int) $card_count . '</td>';
                echo '<td>' . esc_html(mysql2date('M j, Y', $p['updated_at'])) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($board_url) . '">Open board</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function create($name, $person_id, array $columns = [], $scene = '') {
        global $wpdb;
        $name = sanitize_text_field((string) $name);
        if ($name === '') $name = 'Untitled project';
        $columns = self::sanitize_columns($columns ?: self::DEFAULT_COLUMNS);
        $scene = sanitize_text_field((string) $scene);

        // crm_person_id is still written for anyone reading the raw
        // table directly, but it's no longer the source of truth for
        // ownership — the real link is BHCRM_Links::link_project_person()
        // below, which is what list_for_person()/people_for_project()
        // actually read. A project can have zero, one, or many linked
        // people (owner/collaborator/watcher) — this initial link is
        // just the creating person getting 'owner' by default.
        $ok = $wpdb->insert(self::table(), [
            'name'           => $name,
            'crm_person_id'  => (int) $person_id,
            'columns_config' => wp_json_encode($columns),
            'scene'          => $scene,
            'updated_at'     => current_time('mysql'),
        ]);
        if (!$ok) return false;
        $id = (int) $wpdb->insert_id;
        if ($person_id && class_exists('BHCRM_Links')) {
            BHCRM_Links::link_project_person($id, (int) $person_id, 'owner');
        }
        return $id;
    }

    public static function update_columns($id, array $columns) {
        global $wpdb;
        $columns = self::sanitize_columns($columns);
        return (bool) $wpdb->update(self::table(), [
            'columns_config' => wp_json_encode($columns),
            'updated_at'     => current_time('mysql'),
        ], ['id' => (int) $id]);
    }

    public static function update_scene($id, $scene) {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), [
            'scene'      => sanitize_text_field((string) $scene),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int) $id]);
    }

    // Every distinct, non-empty scene currently in use — feeds a
    // <datalist> suggestion list on the create/edit forms, same
    // "freeform text with autocomplete over existing values" posture
    // class-tags.php's all_in_use() already established for tags,
    // rather than a fixed enum anywhere.
    public static function distinct_scenes() {
        global $wpdb;
        $scenes = $wpdb->get_col('SELECT DISTINCT scene FROM ' . self::table() . " WHERE scene != '' ORDER BY scene ASC");
        return array_map('strval', $scenes);
    }

    /* =================================================================
     * Phase C — stall analytics (PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md)
     * ================================================================= */

    // Reacts to ANY placement save (own-ur-shit's generic
    // 'bhcore_element_placement_saved' hook), not just bh-crm's own —
    // scoped to bh/sticky-card placements on the 'bhcrm_project_board'
    // surface specifically, a no-op for every other surface/element
    // type this hook also fires for (bh_crm_profile's own placements,
    // Design Suite pages, etc.). Logs a move on real column changes,
    // AND on a card's very first save (no $old_row at all) — the
    // initial column is itself a "move" for time-in-column purposes
    // (without it, a never-since-moved card would have no baseline row
    // to measure "days since" against).
    public static function on_placement_saved($id, $data, $old_row) {
        if (($data['surface'] ?? '') !== 'bhcrm_project_board') return;
        if (($data['element_type'] ?? '') !== 'bh/sticky-card') return;

        $new_config = json_decode((string) ($data['config'] ?? ''), true);
        $new_column = is_array($new_config) ? (string) ($new_config['attrs']['column'] ?? '') : '';

        if ($old_row === null) {
            self::log_card_move((int) $id, $new_column);
            return;
        }

        $old_config = json_decode((string) ($old_row['config'] ?? ''), true);
        $old_column = is_array($old_config) ? (string) ($old_config['attrs']['column'] ?? '') : '';
        if ($old_column !== $new_column) {
            self::log_card_move((int) $id, $new_column);
        }
    }

    public static function log_card_move($card_placement_id, $column) {
        global $wpdb;
        $wpdb->insert(self::moves_table(), [
            'card_placement_id' => (int) $card_placement_id,
            'column_label'      => sanitize_text_field((string) $column),
        ]);
    }

    // {placement_id => days_since_last_move} for every card on this
    // board whose most recent logged move is at least STALL_DAYS ago —
    // a card with NO move rows at all (shouldn't normally happen once
    // Phase C is live, but a pre-existing card from before this
    // feature shipped would have none) is excluded rather than treated
    // as infinitely stalled, since "we genuinely don't know" and
    // "definitely stalled" are different claims.
    public static function stalled_cards_for_board($project_id) {
        global $wpdb;
        if (!class_exists('BH_Element')) return [];
        $card_ids = array_map(function ($p) { return (int) $p['id']; }, BH_Element::get_placements('bhcrm_project_board', (int) $project_id, 'board'));
        if (!$card_ids) return [];

        $ids_sql = implode(',', $card_ids);
        // One row per card: its own most recent entered_at.
        $rows = $wpdb->get_results(
            "SELECT card_placement_id, MAX(entered_at) AS last_moved_at FROM " . self::moves_table() . "
             WHERE card_placement_id IN ($ids_sql) GROUP BY card_placement_id",
            ARRAY_A
        );

        $now = current_time('timestamp');
        $out = [];
        foreach ($rows as $row) {
            $days = (int) floor(($now - strtotime($row['last_moved_at'])) / DAY_IN_SECONDS);
            if ($days >= self::STALL_DAYS) $out[(int) $row['card_placement_id']] = $days;
        }
        return $out;
    }

    // Average real time-in-column (hours) across every COMPLETED
    // column stay for this board — i.e. every move row that has a
    // later move row after it for the same card. A card's CURRENT
    // (most recent) stay is deliberately excluded: it hasn't ended yet,
    // so counting it would understate real time-in-column for
    // whichever column things currently pile up in.
    public static function average_hours_per_column($project_id) {
        global $wpdb;
        if (!class_exists('BH_Element')) return [];
        $card_ids = array_map(function ($p) { return (int) $p['id']; }, BH_Element::get_placements('bhcrm_project_board', (int) $project_id, 'board'));
        if (!$card_ids) return [];
        $ids_sql = implode(',', $card_ids);

        $rows = $wpdb->get_results(
            "SELECT card_placement_id, column_label, entered_at FROM " . self::moves_table() . "
             WHERE card_placement_id IN ($ids_sql) ORDER BY card_placement_id ASC, entered_at ASC",
            ARRAY_A
        );

        $by_card = [];
        foreach ($rows as $row) $by_card[(int) $row['card_placement_id']][] = $row;

        $totals = []; // column_label => ['hours' => sum, 'count' => n]
        foreach ($by_card as $moves) {
            for ($i = 0; $i < count($moves) - 1; $i++) {
                $col = $moves[$i]['column_label'];
                $hours = (strtotime($moves[$i + 1]['entered_at']) - strtotime($moves[$i]['entered_at'])) / HOUR_IN_SECONDS;
                if (!isset($totals[$col])) $totals[$col] = ['hours' => 0.0, 'count' => 0];
                $totals[$col]['hours'] += $hours;
                $totals[$col]['count']++;
            }
        }

        $out = [];
        foreach ($totals as $col => $t) {
            $out[$col] = round($t['hours'] / $t['count'], 1);
        }
        return $out;
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
                        // Track-It-style bar, same as BHCRM_Subtasks::
                        // render_progress_bar()'s mini variant — this
                        // renderer is a separate, server-only code path
                        // (the interactive kanban-board.js draws its own
                        // card DOM client-side and doesn't call this),
                        // so the bar markup is duplicated inline here
                        // rather than shared, matching this render
                        // callback's existing self-contained style.
                        $pct = self::progress_percent($done_count, $total);
                        $rollup_html = '<div class="bhcrm-sticky-card-rollup">'
                            . '<div class="bhcrm-progress-bar-track" style="height:5px;background:#dcdcde;border-radius:999px;overflow:hidden;margin-bottom:2px;">'
                            . '<div class="bhcrm-progress-bar-fill' . ($pct >= 100 ? ' is-complete' : '') . '" style="height:100%;width:' . $pct . '%;background:' . ($pct >= 100 ? '#00a32a' : '#2271b1') . ';"></div>'
                            . '</div>' . (int) $done_count . '/' . (int) $total . ' sub-tasks done (' . $pct . '%)</div>';
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
            // 'uid' — stable per-node identifier, added alongside the
            // new BHCRM_Subtasks nested tracking view (see that class's
            // own docblock): without it, there was no way to address
            // "this specific sub-task" that survives a reorder or a
            // sibling edit — a BH_Content tree node otherwise has no id
            // of its own at all. Assigned once at creation
            // (BHCRM_Subtasks::handle_add()), never regenerated.
            'uid'   => ['type' => 'string', 'default' => ''],
            'title' => ['type' => 'string', 'default' => 'Sub-task'],
            'notes' => ['type' => 'html',   'default' => ''],
            'done'  => ['type' => 'bool',   'default' => false],
            // 'column' — added alongside BHCRM_Subtasks' rebuild into a
            // REAL kanban board at every nesting level, not a flat
            // checklist. Every level of a card's sub-task tree shares the SAME column
            // vocabulary as the parent project's own board
            // (BHCRM_Projects::get($project_id)['columns_config']) —
            // one shared set of stages for the whole project, not a
            // separately configurable column set per nesting level,
            // same judgment call this class's own docblock already
            // makes for why 'column' is a plain literal attr and not
            // its own slot.
            'column' => ['type' => 'string', 'default' => ''],
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

    // PHPStan-caught real bug: this method's @return docblock had ended
    // up misattached to progress_percent() below instead of
    // rollup_counts() (the method it actually describes) — the
    // intervening "Audit fix" comment block broke the natural
    // docblock-immediately-precedes-function association. PHPStan
    // trusted the wrong (array{0:int,1:int}) return type for
    // progress_percent() (which genuinely returns a plain int), and
    // propagated that bad type to every real call site — not a runtime
    // bug (the actual code was always correct), but a real
    // documentation error worth fixing on its own. Moved to its correct
    // place, immediately above rollup_counts().
    //
    // Audit fix (2026-07-25): $done_count/$total_count -> percent was
    // duplicated between this file's own bh/sticky-card render callback
    // and BHCRM_Subtasks::render_progress_bar() — sharing THIS
    // calculation (pure logic, zero coupling) rather than the actual
    // HTML/CSS markup, which is deliberately NOT shared between them
    // (see the render callback's own comment: sticky-card is a portable
    // BH_Element widget that can render anywhere, including contexts
    // where kanban-board.css isn't loaded, so it uses fully inline
    // styles rather than depending on that admin-only stylesheet).
    public static function progress_percent($done, $total) {
        return $total > 0 ? (int) round(($done / $total) * 100) : 0;
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
        echo '<input type="text" name="project_name" placeholder="New project name (e.g. \'Fenwick — full character commission\')" style="width:360px;max-width:100%;"> ';
        echo '<button class="button button-primary">Create project</button>';
        echo '</form>';
    }

    public static function handle_create() {
        // QA fix: matches the CRM menu's own bhcore_manage_crm gate — creating a project isn't destructive.
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_create')) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['project_name'] ?? ''));
        $scene = sanitize_text_field(wp_unslash($_POST['project_scene'] ?? ''));
        $id = self::create($name, $uid, [], $scene);

        $msg = $id ? "Created project #$id." : 'Failed to create project.';
        // QA fix: redirect straight to the new project's own board
        // (now reachable with $uid === 0, see render_board()'s own
        // fix) instead of back to a person page — correct whether this
        // came from a person's profile (uid set) or the top-level
        // Project Tracker create form (uid always 0 there).
        $args = ['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode($msg)];
        if ($id) $args['project_id'] = $id;
        if ($uid) $args['user_id'] = $uid;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_save_columns() {
        // QA fix: matches the CRM menu's own bhcore_manage_crm gate.
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_columns_' . $project_id)) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        $raw = sanitize_textarea_field(wp_unslash($_POST['columns'] ?? ''));
        $columns = array_filter(array_map('trim', explode("\n", $raw)));
        self::update_columns($project_id, $columns);

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'project_id' => $project_id, 'bhcrm_msg' => 'Columns updated.'], admin_url('admin.php')));
        exit;
    }

    public static function handle_save_scene() {
        // QA fix: matches the CRM menu's own bhcore_manage_crm gate.
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_scene_' . $project_id)) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        self::update_scene($project_id, wp_unslash($_POST['scene'] ?? ''));

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'project_id' => $project_id, 'bhcrm_msg' => 'Scene updated.'], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_delete_' . $project_id)) wp_die('Bad nonce.');
        if (class_exists('OUS_Audit')) {
            OUS_Audit::require_cap('manage_options');
        } elseif (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $uid = (int) ($_POST['user_id'] ?? 0);
        // Audit log — capture the name before it's gone.
        $project = self::get($project_id);
        if ($project && class_exists('OUS_Audit')) {
            OUS_Audit::log('project_deleted', 'bhcrm_project', $project_id, ['name' => $project['name']]);
        }
        self::delete($project_id);

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'bhcrm_msg' => 'Project deleted.'], admin_url('admin.php')));
        exit;
    }

    /** Renders the board view — the bespoke presentation layer's PHP shell; kanban-board.js fills it in against the standard BH_Element REST bridge. Called from BHCRM_People::render() when $_GET['project_id'] is set. */
    public static function render_board($project_id, $uid = 0) {
        $project = self::get($project_id);
        // QA fix: $uid is now optional (a project can be opened
        // straight from the Project Tracker index with no person
        // context at all) — fall back to a plain "back to Project
        // Tracker" link instead of assuming a person is always known.
        $user = $uid ? get_userdata($uid) : null;
        if ($user) {
            echo '<p><a href="' . esc_url(remove_query_arg('project_id')) . '">&larr; Back to ' . esc_html($user->display_name) . '</a></p>';
        } else {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=bh-crm-projects')) . '">&larr; Back to Project Tracker</a></p>';
        }

        if (!$project) {
            echo '<p>Project not found.</p>';
            return;
        }

        echo '<h2>' . esc_html($project['name']) . '</h2>';

        self::render_people_panel($project_id);

        echo '<details style="margin-bottom:14px;"><summary style="cursor:pointer;">Edit columns</summary>';
        $nonce = wp_create_nonce('bhcrm_project_columns_' . $project_id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bhcrm_project_save_columns">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<p class="description">One column label per line, in the order they should appear on the board.</p>';
        echo '<textarea name="columns" rows="5" style="width:300px;max-width:100%;">' . esc_textarea(implode("\n", $project['columns_config'])) . '</textarea><br>';
        echo '<button class="button">Save columns</button>';
        echo '</form></details>';

        echo '<details style="margin-bottom:14px;"><summary style="cursor:pointer;">Edit scene</summary>';
        $scene_nonce = wp_create_nonce('bhcrm_project_scene_' . $project_id);
        $scene_suggestions = self::distinct_scenes();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bhcrm_project_save_scene">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($scene_nonce) . '">';
        echo '<p class="description">Purely organizational — groups this project under a heading on the Project Tracker index. Leave blank for "Unsorted".</p>';
        echo '<input type="text" name="scene" list="bhcrm-scene-suggestions" value="' . esc_attr($project['scene']) . '" style="width:220px;max-width:100%;"><br>';
        if ($scene_suggestions) {
            echo '<datalist id="bhcrm-scene-suggestions">';
            foreach ($scene_suggestions as $s) echo '<option value="' . esc_attr($s) . '">';
            echo '</datalist>';
        }
        echo '<button class="button">Save scene</button>';
        echo '</form></details>';

        echo '<noscript><p class="description">The kanban board requires JavaScript.</p></noscript>';
        echo '<div id="bhcrm-kanban-board" class="bhcrm-kanban-board" data-loading="1"><p class="description">Loading board&hellip;</p></div>';
    }

    /**
     * The Jira/DevOps-style "linked people" panel on a project's board
     * view — every person currently linked (with their relation), a
     * remove link per row, and a form to link someone new under a
     * chosen relation. Only CRM people (BHCRM_People::active_user_ids())
     * are offered, same population every other CRM person-picker uses.
     */
    private static function render_people_panel($project_id) {
        $linked = class_exists('BHCRM_Links') ? BHCRM_Links::people_for_project($project_id) : [];

        echo '<div class="bhy-card">';
        echo '<h3>People</h3>';
        if ($linked) {
            echo '<ul style="margin:0 0 12px;">';
            foreach ($linked as $l) {
                $name = $l['user'] ? $l['user']->display_name : ('User #' . $l['user_id']);
                $remove_url = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_project_unlink&link_id=' . $l['link_id'] . '&project_id=' . (int) $project_id), 'bhcrm_project_unlink_' . $l['link_id']);
                echo '<li>' . esc_html($name) . ' — ' . esc_html(self::relation_label($l['relation'])) . ' &middot; <a href="' . esc_url($remove_url) . '" onclick="return confirm(\'Remove this link?\');">Remove</a></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="description">No one linked to this project yet.</p>';
        }

        $people_ids = class_exists('BHCRM_People') ? BHCRM_People::active_user_ids() : [];
        if ($people_ids) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
            wp_nonce_field('bhcrm_project_link');
            echo '<input type="hidden" name="action" value="bhcrm_project_link">';
            echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
            echo '<select name="person_id" required><option value="">Choose a person&hellip;</option>';
            foreach ($people_ids as $pid) {
                $u = get_userdata($pid);
                if ($u) echo '<option value="' . (int) $pid . '">' . esc_html($u->display_name) . '</option>';
            }
            echo '</select>';
            echo '<select name="relation">';
            foreach (BHCRM_Links::RELATIONS as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button">Link person</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    private static function relation_label($relation) {
        return BHCRM_Links::RELATIONS[$relation] ?? ucfirst($relation);
    }

    public static function handle_link_person() {
        check_admin_referer('bhcrm_project_link');
        // QA fix: matches the CRM menu's own bhcore_manage_crm gate.
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $person_id = (int) ($_POST['person_id'] ?? 0);
        $relation = sanitize_key($_POST['relation'] ?? 'owner');
        if (!isset(BHCRM_Links::RELATIONS[$relation])) $relation = 'owner';
        if ($project_id && $person_id) {
            BHCRM_Links::link_project_person($project_id, $person_id, $relation);
        }
        wp_safe_redirect(admin_url('admin.php?page=bh-crm&user_id=' . $person_id . '&project_id=' . $project_id));
        exit;
    }

    public static function handle_unlink_person() {
        $link_id = (int) ($_GET['link_id'] ?? 0);
        check_admin_referer('bhcrm_project_unlink_' . $link_id);
        // QA fix: matches the CRM menu's own bhcore_manage_crm gate.
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_GET['project_id'] ?? 0);
        if ($link_id) BHCRM_Links::unlink_by_id($link_id);
        $remaining = class_exists('BHCRM_Links') ? BHCRM_Links::people_for_project($project_id) : [];
        $uid = $remaining ? $remaining[0]['user_id'] : 0;
        wp_safe_redirect(admin_url('admin.php?page=bh-crm&user_id=' . $uid . '&project_id=' . $project_id));
        exit;
    }

    /** Enqueues the kanban board's own JS/CSS only when actually viewing a project board (?page=bh-crm&project_id=). */
    public static function maybe_enqueue($hook) {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['project_id'])) return;
        if (!class_exists('BH_Element')) return;

        $project_id = (int) $_GET['project_id'];
        $project = self::get($project_id);
        if (!$project) return;

        wp_enqueue_style('bhcrm-kanban-board', BHCRM_URL . 'assets/css/kanban-board.css', [], BHCRM_VER);
        // Vendored, not npm — this ecosystem's own no-build-step
        // convention (see kanban-board.js's own docblock for why:
        // replaces that file's hand-rolled HTML5 drag-and-drop, which
        // only ever supported append-to-end-of-column, never a real
        // same-column reorder). MIT-licensed, single file, no
        // transitive dependencies — vendored here rather than via a
        // CDN so this plugin has zero runtime dependency on a third
        // party staying up.
        wp_enqueue_script('sortablejs', BHCRM_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        wp_enqueue_script('bhcrm-kanban-board', BHCRM_URL . 'assets/js/kanban-board.js', ['sortablejs'], BHCRM_VER, true);

        wp_localize_script('bhcrm-kanban-board', 'bhcrmKanbanConfig', [
            // Same 'ous/v1/elements/' REST bridge + wp_rest cookie-nonce
            // contract element-builder.js already uses — no new route,
            // no new auth mechanism.
            'restUrl'    => esc_url_raw(rest_url('ous/v1/elements/')),
            // bh-crm's own small rollups route (rest_rollups() above) —
            // a separate namespace from the generic BH_Element bridge
            // above, same wp_rest cookie-nonce.
            'rollupsUrl' => esc_url_raw(rest_url('bh-crm/v1/rollups')),
            'stalledCardsUrl' => esc_url_raw(rest_url('bh-crm/v1/stalled-cards')),
            'studioUrl'  => esc_url_raw(admin_url('admin.php?page=bh-studio')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'surface'    => 'bhcrm_project_board',
            'projectId'  => $project_id,
            'columns'    => $project['columns_config'],
        ]);
    }
}
