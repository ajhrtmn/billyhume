<?php
if (!defined('ABSPATH')) exit;

/**
 * Kanban project tracker built on core's element system (BH_Element / BH_Content).
 *
 * Data model:
 * - A project is a row in BHCRM_Tables::projects() (name, columns_config JSON, scene).
 * - Its board is the 'bhcrm_project_board' element surface (context id = project id),
 *   one 'board' slot holding one 'bh/sticky-card' placement per card.
 * - WHY: a card's column is a plain config attr ('column'), not a slot per column —
 *   placements have a single fixed 'slot' key, and column sets are configurable per project.
 * - bh/sticky-card is a container; its BH_Content tree holds recursive 'bhcrm/sub-card' nodes.
 * - Roll-up completion is computed at render time (rollup_counts()), never stored; a parent's
 *   own 'done' flag is never auto-toggled by its children.
 * - kanban-board.js saves through core's generic ous/v1/elements placements REST routes.
 */
class BHCRM_Projects {
    const DB_VERSION = '1.2';
    const STALL_DAYS = 5; // a card whose most recent logged move is at least this many days ago shows the "hasn't moved" badge
    const DEFAULT_COLUMNS = ['To Do', 'In Progress', 'Review', 'Done'];

    public static function init(): void {
        // Runs inside bh-crm.php's 'plugins_loaded' bootstrap; deploys are file replaces, so no activation hook fires.
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
        // Stall analytics: logs column moves on every placement save.
        add_action('bhcore_element_placement_saved', [self::class, 'on_placement_saved'], 10, 3);
    }

    /**
     * Per-board routes for sub-task roll-ups and stall status, fetched once per board load;
     * core's generic placements endpoint doesn't return either.
     */
    public static function register_rest_routes(): void {
        register_rest_route('bh-crm/v1', '/rollups', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_rollups'],
            'permission_callback' => function () { return current_user_can('bhcore_manage_crm'); },
            'args' => ['project_id' => ['required' => true, 'sanitize_callback' => 'absint']],
        ]);
        register_rest_route('bh-crm/v1', '/stalled-cards', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_stalled_cards'],
            'permission_callback' => function () { return current_user_can('bhcore_manage_crm'); },
            'args' => ['project_id' => ['required' => true, 'sanitize_callback' => 'absint']],
        ]);
    }

    public static function rest_stalled_cards(\WP_REST_Request $req): \WP_REST_Response {
        return new WP_REST_Response(self::stalled_cards_for_board((int) $req->get_param('project_id')), 200);
    }

    public static function rest_rollups(\WP_REST_Request $req): \WP_REST_Response {
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
     * Activation / schema (versioned dbDelta, checked on every load)
     * ================================================================= */

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_projects_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhcrm_projects_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_projects_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
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

        // One row per column transition; card_placement_id = core placement id (no FK, core owns that table).
        $moves = BHCRM_Tables::project_card_moves();
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

    private static function moves_table(): string {
        return BHCRM_Tables::project_card_moves();
    }

    private static function table(): string {
        return BHCRM_Tables::projects();
    }

    /* =================================================================
     * Project CRUD
     * ================================================================= */

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode((string) $row['columns_config'], true);
        $row['columns_config'] = is_array($decoded) && $decoded ? array_values($decoded) : self::DEFAULT_COLUMNS;
        return $row;
    }

    /** Every project the person is linked to via BHCRM_Links, under any relation. */
    /** @return array<int, array<string, mixed>> */
    public static function list_for_person(int $person_id): array {
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

    /** Every project row, across every person. */
    /** @return array<int, array<string, mixed>> */
    public static function list_all(): array {
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

    /** 'bh-crm-projects' page: a cross-person index linking into the bh-crm board dispatch. */
    public static function render_boards(): void {
        echo '<div class="wrap">';
        echo '<h1>People &rsaquo; Project Tracker</h1>';
        echo '<p class="description">Every project board across the CRM. Opening one lands on the same kanban board view reachable from a person\'s own profile — this is just a cross-person index, not a second board implementation.</p>';

        // Projects need no owner; people are linked afterward on the board (render_people_panel()).
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

        // Scenes are purely organizational: they only pick the heading a row sits under.
        $groups = [];
        foreach ($rows as $p) {
            $scene = trim((string) $p['scene']);
            $groups[$scene === '' ? '' : $scene][] = $p;
        }
        // Named scenes first (alphabetical), "Unsorted" last.
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
                // Falls back to the legacy crm_person_id column when no links exist.
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

    /**
     * @param array<int, string> $columns
     * @return int|false
     */
    public static function create(string $name, int $person_id, array $columns = [], string $scene = '') {
        global $wpdb;
        $name = sanitize_text_field((string) $name);
        if ($name === '') $name = 'Untitled project';
        $columns = self::sanitize_columns($columns ?: self::DEFAULT_COLUMNS);
        $scene = sanitize_text_field((string) $scene);

        // crm_person_id is legacy; BHCRM_Links is the source of truth for who is on a project.
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

    /** @param array<int, string> $columns */
    public static function update_columns(int $id, array $columns): bool {
        global $wpdb;
        $columns = self::sanitize_columns($columns);
        return (bool) $wpdb->update(self::table(), [
            'columns_config' => wp_json_encode($columns),
            'updated_at'     => current_time('mysql'),
        ], ['id' => (int) $id]);
    }

    public static function update_scene(int $id, string $scene): bool {
        global $wpdb;
        return (bool) $wpdb->update(self::table(), [
            'scene'      => sanitize_text_field((string) $scene),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int) $id]);
    }

    // Distinct non-empty scenes, for the forms' <datalist> autocomplete.
    /** @return array<int, string> */
    public static function distinct_scenes(): array {
        global $wpdb;
        $scenes = $wpdb->get_col('SELECT DISTINCT scene FROM ' . self::table() . " WHERE scene != '' ORDER BY scene ASC");
        return array_map('strval', $scenes);
    }

    /* =================================================================
     * Stall analytics
     * ================================================================= */

    // Logs a move on column changes and on a card's first save, which is the baseline for "days since moved".
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $old_row
     */
    public static function on_placement_saved(int $id, array $data, ?array $old_row): void {
        if (($data['surface'] ?? '') !== 'bhcrm_project_board') return;
        if (($data['element_type'] ?? '') !== 'bh/sticky-card') return;

        $new_column = self::column_from_config(json_decode((string) ($data['config'] ?? ''), true));

        if ($old_row === null) {
            self::log_card_move((int) $id, $new_column);
            return;
        }

        $old_column = self::column_from_config(json_decode((string) ($old_row['config'] ?? ''), true));
        if ($old_column !== $new_column) {
            self::log_card_move((int) $id, $new_column);
        }
    }

    /**
     * Read 'column' from a raw (unresolved) placement config.
     * WHY: the value may be a plain string or a {"literal": "..."} binding wrapper.
     *
     * @param mixed $config
     */
    private static function column_from_config($config): string {
        if (!is_array($config)) return '';
        $col = $config['attrs']['column'] ?? '';
        if (is_array($col)) $col = $col['literal'] ?? '';
        return is_scalar($col) ? (string) $col : '';
    }

    public static function log_card_move(int $card_placement_id, string $column): void {
        global $wpdb;
        $wpdb->insert(self::moves_table(), [
            'card_placement_id' => (int) $card_placement_id,
            'column_label'      => sanitize_text_field((string) $column),
        ]);
    }

    // {placement_id => days_since_last_move} for cards idle >= STALL_DAYS; cards with no move rows are excluded.
    /** @return array<int, int> */
    public static function stalled_cards_for_board(int $project_id): array {
        global $wpdb;
        if (!class_exists('BH_Element')) return [];
        $card_ids = array_map(function ($p) { return (int) $p['id']; }, BH_Element::get_placements('bhcrm_project_board', (int) $project_id, 'board'));
        if (!$card_ids) return [];

        $ids_sql = implode(',', $card_ids);
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

    // Average hours per column over completed stays only; a card's current stay hasn't ended, so it's excluded.
    /** @return array<string, float> */
    public static function average_hours_per_column(int $project_id): array {
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

    /**
     * @param array<int, mixed> $columns
     * @return array<int, string>
     */
    private static function sanitize_columns(array $columns): array {
        $out = [];
        foreach ($columns as $c) {
            $c = sanitize_text_field((string) $c);
            if ($c !== '') $out[] = $c;
        }
        return $out ?: self::DEFAULT_COLUMNS;
    }

    /** Hard-deletes a project and every board placement and content document under it. */
    public static function delete(int $id): bool {
        global $wpdb;
        $id = (int) $id;
        if (class_exists('BH_Element')) {
            foreach (BH_Element::get_placements('bhcrm_project_board', $id, 'board') as $p) {
                if (!empty($p['content_context_id']) && class_exists('BH_Content')) {
                    // BH_Content has no delete(); saving an empty tree clears the content.
                    BH_Content::save('bh_element', (int) $p['content_context_id'], []);
                }
                BH_Element::delete_placement($p['id']);
            }
        }
        return (bool) $wpdb->delete(self::table(), ['id' => $id]);
    }

    /* =================================================================
     * the-self-hosted-self integration — element type + surface + content block
     * ================================================================= */

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface(array $surfaces): array {
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

    private static function register_element_type(): void {
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
                // Plain grouping key, not a slot (see class docblock).
                'column' => ['type' => 'string', 'default' => '',              'bindable' => false],
            ],
            'style' => ['color_accent', 'radius'],
            // 'data-status' is enum-validated server-side in BH_Element::build_html_attrs().
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
                        // WHY: inline styles, since this widget can render where kanban-board.css isn't loaded.
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

    private static function register_content_block_type(): void {
        BH_Content::register_block_type('bhcrm/sub-card', [
            // Stable node id that survives reorders; assigned once in BHCRM_Subtasks::handle_add().
            'uid'   => ['type' => 'string', 'default' => ''],
            'title' => ['type' => 'string', 'default' => 'Sub-task'],
            'notes' => ['type' => 'html',   'default' => ''],
            'done'  => ['type' => 'bool',   'default' => false],
            // Every nesting level shares the project's own columns_config.
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

    // Shared with BHCRM_Subtasks::render_progress_bar().
    public static function progress_percent(int $done, int $total): int {
        return $total > 0 ? (int) round(($done / $total) * 100) : 0;
    }

    /**
     * Recursively counts 'bhcrm/sub-card' nodes in a raw BH_Content tree; other block types are skipped.
     *
     * @param array<int, array<string, mixed>> $tree
     * @return array{0:int,1:int} [$done_count, $total_count]
     */
    public static function rollup_counts(array $tree): array {
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
     * WHY: rendered via ?page=bh-crm&project_id=, not a standalone admin page;
     * standalone pages have failed WP's page-hook resolution on this install.
     * ================================================================= */

    /** Called from BHCRM_People::render_detail($uid). */
    public static function render_projects_section(int $uid): void {
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

    public static function handle_create(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_create')) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['project_name'] ?? ''));
        $scene = sanitize_text_field(wp_unslash($_POST['project_scene'] ?? ''));
        $id = self::create($name, $uid, [], $scene);

        $msg = $id ? "Created project #$id." : 'Failed to create project.';
        // Land on the new board; $uid is 0 when created from the Project Tracker index.
        $args = ['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode($msg)];
        if ($id) $args['project_id'] = $id;
        if ($uid) $args['user_id'] = $uid;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_save_columns(): void {
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

    public static function handle_save_scene(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_project_scene_' . $project_id)) wp_die('Bad nonce.');

        $uid = (int) ($_POST['user_id'] ?? 0);
        self::update_scene($project_id, wp_unslash($_POST['scene'] ?? ''));

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $uid, 'project_id' => $project_id, 'bhcrm_msg' => 'Scene updated.'], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete(): void {
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

    /** Board view shell; kanban-board.js fills it in. Called from BHCRM_People::render() when project_id is set. */
    public static function render_board(int $project_id, int $uid = 0): void {
        $project = self::get($project_id);
        // $uid is 0 when opened from the Project Tracker index.
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

    /** Linked-people panel: current links with remove buttons, plus a form to link a CRM person. */
    private static function render_people_panel(int $project_id): void {
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

    private static function relation_label(string $relation): string {
        return BHCRM_Links::RELATIONS[$relation] ?? ucfirst($relation);
    }

    public static function handle_link_person(): void {
        check_admin_referer('bhcrm_project_link');
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

    public static function handle_unlink_person(): void {
        $link_id = (int) ($_GET['link_id'] ?? 0);
        check_admin_referer('bhcrm_project_unlink_' . $link_id);
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $project_id = (int) ($_GET['project_id'] ?? 0);
        if ($link_id) BHCRM_Links::unlink_by_id($link_id);
        $remaining = class_exists('BHCRM_Links') ? BHCRM_Links::people_for_project($project_id) : [];
        $uid = $remaining ? $remaining[0]['user_id'] : 0;
        wp_safe_redirect(admin_url('admin.php?page=bh-crm&user_id=' . $uid . '&project_id=' . $project_id));
        exit;
    }

    /** Enqueues the kanban board's own JS/CSS only when actually viewing a project board (?page=bh-crm&project_id=). */
    public static function maybe_enqueue(string $hook): void {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['project_id'])) return;
        if (!class_exists('BH_Element')) return;

        $project_id = (int) $_GET['project_id'];
        $project = self::get($project_id);
        if (!$project) return;

        wp_enqueue_style('bhcrm-kanban-board', BHCRM_URL . 'assets/css/kanban-board.css', [], BHCRM_VER);
        // SortableJS (MIT) is vendored, not CDN-loaded, so there's no third-party runtime dependency.
        wp_enqueue_script('sortablejs', BHCRM_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        wp_enqueue_script('bhcrm-kanban-board', BHCRM_URL . 'assets/js/kanban-board.js', ['sortablejs'], BHCRM_VER, true);

        wp_localize_script('bhcrm-kanban-board', 'bhcrmKanbanConfig', [
            'restUrl'    => esc_url_raw(rest_url('ous/v1/elements/')),
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
