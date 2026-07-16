<?php
if (!defined('ABSPATH')) exit;

/**
 * BHCRM_Subtasks — a real, dedicated nested-tracking view for a sticky
 * card's sub-tasks, replacing Content Studio for this purpose entirely
 * (AJ's own call: "it replaces content studio entirely, not related in
 * the slightest"). Content Studio is a generic WordPress block-editor
 * canvas with no board/column concept and no rollup display — a
 * mismatch for "track progress on nested boards," which is what this
 * class actually builds.
 *
 * REAL KANBAN AT EVERY LEVEL, not a flat checklist — a course
 * correction, AJ's own words: "I thought each subtask would be its
 * own kanban board of tasks." Every level of a card's sub-task tree
 * renders as a full multi-column board (drag between columns, per-
 * column add), reusing the exact same visual/interaction language as
 * the top-level project board (kanban-board.css/.js) rather than
 * inventing a second look. All levels share ONE column vocabulary —
 * the parent project's own columns_config
 * (BHCRM_Projects::get($project_id)['columns_config']) — not a
 * separately configurable column set per nesting level; that's a
 * deliberate scoping choice (one shared set of stages for the whole
 * project, matching how the top-level board's own columns already
 * work), not an oversight.
 *
 * DATA MODEL: unchanged from class-projects.php's own docblock — a
 * sticky card's sub-tasks are a BH_Content tree of 'bhcrm/sub-card'
 * blocks (title/notes/done/column), stored at ('bh_element',
 * $card['content_context_id']). This class adds two new schema attrs
 * to that block type: 'uid' (a short random string, assigned once at
 * creation — the stable per-node identifier a BH_Content tree
 * otherwise lacks entirely) and 'column' (which of the project's
 * columns this node currently sits in). See
 * BHCRM_Projects::register_content_block_type() for the schema itself.
 *
 * NAVIGATION: a $path (array of uids from the card's own root down to
 * the currently-viewed node) drives which level of the tree is shown —
 * AJ's own ask, "a good UX for moving up and down the trees of
 * boards." Carried as a comma-joined 'subtask_path' query arg. A real
 * breadcrumb (render_breadcrumb()) shows the full chain back to the
 * card itself, collapsing past BREADCRUMB_COLLAPSE_AT segments so it
 * never grows unbounded sideways.
 *
 * PROGRESS ROLLUP: reuses BHCRM_Projects::rollup_counts() exactly —
 * that method already walks a raw tree counting done/total
 * recursively regardless of which column a node sits in; this class
 * just calls it at whatever depth is currently being viewed, so "the
 * parent's progress = aggregate of its children" is true at EVERY
 * level.
 *
 * SCOPING NOTE, disclosed rather than silently half-built: AJ also
 * asked for "details sections that allow links to people and things."
 * A sub-task has no stable INTEGER id of its own (BHCRM_Links'
 * from_id/to_id columns are bigint, and a BH_Content tree node's only
 * stable identifier is the string 'uid' this pass adds) — bridging
 * that cleanly is a real, separate design decision left for a
 * follow-up. Notes (freeform detail) are fully supported today via
 * the block's own existing 'notes' attr.
 */
class BHCRM_Subtasks {
    // A quiet heads-up once a card's WHOLE tree (every level, every
    // column) gets big enough that it's probably time to split into a
    // separate project — never a hard limit, there is no max depth.
    const SIZE_WARNING_THRESHOLD = 50;
    // Past this many breadcrumb segments the middle collapses to "…".
    const BREADCRUMB_COLLAPSE_AT = 5;

    public static function init() {
        add_action('admin_post_bhcrm_subtask_add', [self::class, 'handle_add']);
        add_action('admin_post_bhcrm_subtask_bulk_add', [self::class, 'handle_bulk_add']);
        add_action('admin_post_bhcrm_subtask_toggle', [self::class, 'handle_toggle']);
        add_action('admin_post_bhcrm_subtask_save', [self::class, 'handle_save']);
        add_action('admin_post_bhcrm_subtask_delete', [self::class, 'handle_delete']);
        add_action('admin_post_bhcrm_subtask_reorder', [self::class, 'handle_reorder']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    /** Only on this exact view (?page=bh-crm&project_id=&card_id=) — same gate posture BHCRM_Projects::maybe_enqueue() already uses. */
    public static function maybe_enqueue($hook) {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['card_id'])) return;
        // Reuses the top-level board's own stylesheet — same
        // .bhcrm-kanban-* classes, so a sub-task board looks and
        // behaves identically to the project board, not a second
        // visual language.
        wp_enqueue_style('bhcrm-kanban-board', BHCRM_URL . 'assets/css/kanban-board.css', [], BHCRM_VER);
        wp_enqueue_script('sortablejs', BHCRM_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        wp_enqueue_script('bhcrm-subtasks', BHCRM_URL . 'assets/js/subtasks.js', ['sortablejs'], BHCRM_VER, true);
        wp_localize_script('bhcrm-subtasks', 'bhcrmSubtasksConfig', [
            'ajaxUrl' => esc_url_raw(admin_url('admin-post.php')),
            'nonce'   => wp_create_nonce('bhcrm_subtask_reorder'),
        ]);
    }

    private static function path_from_string($raw) {
        $raw = trim((string) $raw);
        return $raw === '' ? [] : array_values(array_filter(array_map('sanitize_text_field', explode(',', $raw))));
    }

    private static function path_to_string(array $path) {
        return implode(',', $path);
    }

    /** The project's own column vocabulary — shared by every nesting level (see class docblock). */
    private static function project_columns($project_id) {
        $project = class_exists('BHCRM_Projects') ? BHCRM_Projects::get($project_id) : null;
        $columns = $project['columns_config'] ?? [];
        return $columns ?: (class_exists('BHCRM_Projects') ? BHCRM_Projects::DEFAULT_COLUMNS : ['To Do', 'In Progress', 'Review', 'Done']);
    }

    /**
     * Locates the node at $path within $tree (by uid chain) and
     * returns a REFERENCE to it so the caller can mutate its
     * 'children' array in place before the whole tree is re-saved as
     * one document (BH_Content's own storage contract).
     */
    private static function &find_node(array &$tree, array $path) {
        $null = null;
        if (!$path) return $null;
        $head = array_shift($path);
        foreach ($tree as &$node) {
            if (($node['attrs']['uid'] ?? '') === $head) {
                if (!$path) return $node;
                if (!isset($node['children']) || !is_array($node['children'])) $node['children'] = [];
                return self::find_node($node['children'], $path);
            }
        }
        unset($node);
        return $null;
    }

    /** The children array AT $path — the root document's own top-level array when $path is empty. */
    private static function &children_at(array &$tree, array $path) {
        if (!$path) return $tree;
        $node = &self::find_node($tree, $path);
        $empty = [];
        if ($node === null) return $empty;
        if (!isset($node['children']) || !is_array($node['children'])) $node['children'] = [];
        return $node['children'];
    }

    /** Total node count across the WHOLE tree (every level, every column) — the size-warning signal. */
    private static function total_node_count(array $tree) {
        $count = 0;
        foreach ($tree as $node) {
            $count++;
            if (!empty($node['children']) && is_array($node['children'])) {
                $count += self::total_node_count($node['children']);
            }
        }
        return $count;
    }

    /**
     * QA fix, caught live: a placement's decoded 'config' isn't a flat
     * {title: 'x'} map — BH_Element stores each attr as {literal: 'x'}
     * (or {source: ...} for a bound value), confirmed directly against
     * a real row: {"attrs":{"title":{"literal":"QA Test Card"},...}}.
     */
    private static function card_title($card) {
        return (string) ($card['config']['attrs']['title']['literal'] ?? '');
    }

    private static function load_card($project_id, $card_id) {
        if (!class_exists('BH_Element')) return null;
        foreach (BH_Element::get_placements('bhcrm_project_board', (int) $project_id, 'board') as $p) {
            if ((int) $p['id'] === (int) $card_id) return $p;
        }
        return null;
    }

    private static function base_url($project_id, $uid, $card_id) {
        return admin_url('admin.php?page=bh-crm&user_id=' . (int) $uid . '&project_id=' . (int) $project_id . '&card_id=' . (int) $card_id);
    }

    /* =================================================================
     * Render
     * ================================================================= */

    public static function render($project_id, $uid, $card_id, $path) {
        $card = self::load_card($project_id, $card_id);
        if (!$card) {
            echo '<p>Card not found.</p>';
            return;
        }
        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = class_exists('BH_Content') ? BH_Content::get('bh_element', $content_id) : [];
        $columns = self::project_columns($project_id);

        echo '<p><a href="' . esc_url(remove_query_arg(['card_id', 'subtask_path'])) . '">&larr; Back to board</a></p>';
        echo '<h2>' . esc_html(self::card_title($card) ?: 'Sub-tasks') . '</h2>';

        $whole_tree_count = self::total_node_count($tree);
        if ($whole_tree_count >= self::SIZE_WARNING_THRESHOLD) {
            echo '<p class="description" style="color:var(--bhy-warning,#b45309);">&#9888; This card has ' . (int) $whole_tree_count . ' sub-tasks across every level — worth considering whether this has grown into its own separate project instead of one card\'s tree.</p>';
        }

        self::render_breadcrumb($project_id, $uid, $card_id, $card, $tree, $path);

        $children = &self::children_at($tree, $path);
        [$done_count, $total_count] = class_exists('BHCRM_Projects') ? BHCRM_Projects::rollup_counts($children) : [0, 0];
        if ($total_count > 0) {
            echo '<p class="description">' . (int) $done_count . '/' . (int) $total_count . ' sub-tasks done at this level (recursively).</p>';
        }

        self::render_board($project_id, $uid, $card_id, $path, $children, $columns);
        self::render_bulk_add_form($project_id, $uid, $card_id, $path, $columns);
    }

    /**
     * Full chain from the card itself down to the current $path — past
     * BREADCRUMB_COLLAPSE_AT segments the middle collapses to a single
     * "…" (itself a link back to the first collapsed level, not a dead
     * end).
     */
    private static function render_breadcrumb($project_id, $uid, $card_id, $card, array $tree, array $path) {
        $base = self::base_url($project_id, $uid, $card_id);
        $crumbs = [['label' => self::card_title($card) ?: 'Card', 'url' => $base]];

        $walked = [];
        $level = $tree;
        foreach ($path as $seg) {
            $walked[] = $seg;
            $node = null;
            foreach ($level as $n) {
                if (($n['attrs']['uid'] ?? '') === $seg) { $node = $n; break; }
            }
            if (!$node) break;
            $crumbs[] = ['label' => $node['attrs']['title'] ?? 'Sub-task', 'url' => $base . '&subtask_path=' . urlencode(self::path_to_string($walked))];
            $level = $node['children'] ?? [];
        }

        $html_crumbs = array_map(fn($c) => '<a href="' . esc_url($c['url']) . '">' . esc_html($c['label']) . '</a>', $crumbs);

        if (count($html_crumbs) > self::BREADCRUMB_COLLAPSE_AT) {
            $tail = array_slice($html_crumbs, -2);
            $collapsed_url = $crumbs[count($crumbs) - 3]['url'];
            $html_crumbs = [$html_crumbs[0], '<a href="' . esc_url($collapsed_url) . '" title="' . esc_attr((count($crumbs) - 3) . ' level(s) hidden') . '">&hellip;</a>', ...$tail];
        }

        echo '<p class="description">' . implode(' &rsaquo; ', $html_crumbs) . '</p>';
    }

    /** The board itself — one column per project column, cards grouped by their own 'column' attr, drag between columns via subtasks.js. */
    private static function render_board($project_id, $uid, $card_id, array $path, array $children, array $columns) {
        $by_column = array_fill_keys($columns, []);
        foreach ($children as $node) {
            $col = $node['attrs']['column'] ?? '';
            if (!isset($by_column[$col])) $col = $columns[0]; // unknown/blank column (e.g. pre-column-attr data) falls back to the first column rather than being silently dropped
            $by_column[$col][] = $node;
        }

        echo '<div class="bhcrm-kanban-board"><div class="bhcrm-kanban-grid" id="bhcrm-subtask-board"'
           . ' data-reorder-nonce="' . esc_attr(wp_create_nonce('bhcrm_subtask_reorder')) . '"'
           . ' data-project-id="' . (int) $project_id . '" data-user-id="' . (int) $uid . '" data-card-id="' . (int) $card_id . '"'
           . ' data-subtask-path="' . esc_attr(self::path_to_string($path)) . '">';

        foreach ($columns as $col) {
            $cards_in_col = $by_column[$col];
            echo '<div class="bhcrm-kanban-column" data-column="' . esc_attr($col) . '">';
            echo '<div class="bhcrm-kanban-column-header">' . esc_html($col) . ' <span class="bhcrm-kanban-column-count">(' . count($cards_in_col) . ')</span></div>';
            echo '<div class="bhcrm-kanban-column-cards" data-column="' . esc_attr($col) . '">';
            foreach ($cards_in_col as $node) {
                self::render_card($project_id, $uid, $card_id, $path, $node);
            }
            echo '</div>';
            self::render_add_form($project_id, $uid, $card_id, $path, $col);
            echo '</div>';
        }

        echo '</div></div>';
    }

    private static function render_card($project_id, $uid, $card_id, array $path, array $node) {
        $node_uid = $node['attrs']['uid'] ?? '';
        $title = $node['attrs']['title'] ?? '(untitled)';
        $notes = $node['attrs']['notes'] ?? '';
        $done = !empty($node['attrs']['done']);
        $children = $node['children'] ?? [];
        [$child_done, $child_total] = class_exists('BHCRM_Projects') ? BHCRM_Projects::rollup_counts($children) : [0, 0];

        $base = self::base_url($project_id, $uid, $card_id);
        $child_path_str = self::path_to_string(array_merge($path, [$node_uid]));

        // data-node-uid is what subtasks.js reads on drag-end to
        // report each column's new order/membership back to the server.
        echo '<div class="bhcrm-kanban-card' . ($done ? ' is-done' : '') . '" data-node-uid="' . esc_attr($node_uid) . '">';
        echo '<div class="bhcrm-kanban-card-drag-handle" title="Drag to reorder or move columns">&#8942;&#8942;</div>';

        echo '<div class="bhcrm-kanban-card-title-row">';
        $toggle_nonce = wp_nonce_url($base, 'bhcrm_subtask_' . $node_uid);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_toggle">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<button type="submit" class="button button-small" title="Toggle done" style="padding:0 4px;min-height:auto;line-height:1.6;">' . ($done ? '&#9745;' : '&#9744;') . '</button>';
        echo '</form>';
        echo ' <strong' . ($done ? ' style="text-decoration:line-through;color:var(--bhy-ink-dim,#777);"' : '') . '>' . esc_html($title) . '</strong>';
        echo '</div>';

        if ($child_total > 0) echo '<div class="description" style="font-size:11px;margin:2px 0;">' . (int) $child_done . '/' . (int) $child_total . ' sub-tasks done</div>';
        if ($notes) echo '<div class="bhcrm-sub-card-notes">' . wp_kses_post($notes) . '</div>';

        echo '<div class="bhcrm-kanban-card-actions">';
        echo '<a class="button button-small" href="' . esc_url($base . '&subtask_path=' . urlencode($child_path_str)) . '">Open board &rarr;</a>';

        echo '<details style="display:inline-block;"><summary style="cursor:pointer;font-size:11px;display:inline;">Edit</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_save">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<p><input type="text" name="title" value="' . esc_attr($title) . '" style="width:100%;"></p>';
        echo '<p><textarea name="notes" rows="2" style="width:100%;" placeholder="Details, links, context…">' . esc_textarea(wp_strip_all_tags($notes)) . '</textarea></p>';
        echo '<button type="submit" class="button button-small">Save</button>';
        echo '</form></details>';

        $delete_url = wp_nonce_url($base . '&subtask_path=' . urlencode(self::path_to_string($path)), 'bhcrm_subtask_delete_' . $node_uid);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" onsubmit="return confirm(\'Delete this sub-task and everything nested under it?\');">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_delete">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<button type="submit" class="button button-small" style="color:#b32d2e;">Delete</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private static function render_add_form($project_id, $uid, $card_id, array $path, $column) {
        $base = self::base_url($project_id, $uid, $card_id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bhcrm-kanban-add-card">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_add">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="column" value="' . esc_attr($column) . '">';
        wp_nonce_field('bhcrm_subtask_add');
        echo '<input type="text" name="title" placeholder="+ Add card…" required>';
        echo '<button type="submit" class="button button-small">Add</button>';
        echo '</form>';
    }

    /**
     * Bulk add — a "one per line" textarea, all landing in one chosen
     * column, matching the pattern already used elsewhere in this
     * codebase (segment conditions, project columns).
     */
    private static function render_bulk_add_form($project_id, $uid, $card_id, array $path, array $columns) {
        $base = self::base_url($project_id, $uid, $card_id);
        echo '<details style="margin-top:10px;"><summary style="cursor:pointer;font-size:13px;">+ Add several at once</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_bulk_add">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        wp_nonce_field('bhcrm_subtask_bulk_add');
        echo '<p class="description">One sub-task title per line, all added to:</p>';
        echo '<p><select name="column">';
        foreach ($columns as $col) echo '<option value="' . esc_attr($col) . '">' . esc_html($col) . '</option>';
        echo '</select></p>';
        echo '<textarea name="titles" rows="5" style="width:100%;max-width:400px;" placeholder="First sub-task&#10;Second sub-task&#10;Third sub-task"></textarea>';
        echo '<p><button type="submit" class="button">Add all</button></p>';
        echo '</form></details>';
    }

    /* =================================================================
     * Handlers
     * ================================================================= */

    private static function require_access($project_id, $card_id) {
        if (class_exists('OUS_Audit')) {
            OUS_Audit::require_cap('bhcore_manage_crm');
        } elseif (!current_user_can('bhcore_manage_crm')) {
            wp_die('Not allowed.');
        }
        $card = self::load_card($project_id, $card_id);
        if (!$card) wp_die('Card not found.');
        return $card;
    }

    private static function redirect_back($project_id, $uid, $card_id, $path) {
        wp_safe_redirect(self::base_url($project_id, $uid, $card_id) . '&subtask_path=' . urlencode(self::path_to_string($path)));
        exit;
    }

    public static function handle_add() {
        check_admin_referer('bhcrm_subtask_add');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $card = self::require_access($project_id, $card_id);

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if ($title === '') self::redirect_back($project_id, $uid, $card_id, $path);

        $columns = self::project_columns($project_id);
        $column = sanitize_text_field(wp_unslash($_POST['column'] ?? ''));
        if (!in_array($column, $columns, true)) $column = $columns[0];

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $children = &self::children_at($tree, $path);
        $new_uid = wp_generate_password(12, false);
        $children[] = ['type' => 'bhcrm/sub-card', 'attrs' => ['uid' => $new_uid, 'title' => $title, 'notes' => '', 'done' => false, 'column' => $column], 'children' => []];
        BH_Content::save('bh_element', $content_id, $tree);

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    public static function handle_bulk_add() {
        check_admin_referer('bhcrm_subtask_bulk_add');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $card = self::require_access($project_id, $card_id);

        $columns = self::project_columns($project_id);
        $column = sanitize_text_field(wp_unslash($_POST['column'] ?? ''));
        if (!in_array($column, $columns, true)) $column = $columns[0];

        $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['titles'] ?? ''));
        $titles = array_values(array_filter(array_map(function ($l) {
            return sanitize_text_field(trim($l));
        }, $lines), fn($l) => $l !== ''));

        if ($titles) {
            $content_id = (int) ($card['content_context_id'] ?: $card['id']);
            $tree = BH_Content::get('bh_element', $content_id);
            $children = &self::children_at($tree, $path);
            foreach ($titles as $title) {
                $children[] = ['type' => 'bhcrm/sub-card', 'attrs' => ['uid' => wp_generate_password(12, false), 'title' => $title, 'notes' => '', 'done' => false, 'column' => $column], 'children' => []];
            }
            BH_Content::save('bh_element', $content_id, $tree);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    /**
     * Cross-column drag-reorder — called via fetch() from subtasks.js
     * after a SortableJS drop, NOT a plain form (this view can render
     * inside admin contexts where a nested &lt;form&gt; silently breaks
     * — see bh-contest's reject-form bug this same session for the
     * exact failure mode). Takes 'layout', a JSON array of
     * {uid, column} pairs covering every card currently on the board,
     * in final on-screen order (column loop, then per-column DOM
     * order — same reconstruction kanban-board.js's own
     * reorderFromDom() uses for the top-level board), and rebuilds
     * this level's children array to match: each node's 'column' attr
     * is set from the posted layout, and array order follows it too.
     * Any uid NOT present in the posted layout (shouldn't normally
     * happen — the client posts every visible card — but guards a
     * stale/partial request racing a concurrent add()) is appended at
     * the end rather than silently dropped.
     */
    public static function handle_reorder() {
        check_ajax_referer('bhcrm_subtask_reorder', 'nonce');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $card = self::require_access($project_id, $card_id);

        $layout = json_decode((string) wp_unslash($_POST['layout'] ?? ''), true);
        if (!is_array($layout)) wp_send_json_error(['message' => 'No layout given.']);

        $columns = self::project_columns($project_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $children = &self::children_at($tree, $path);

        $by_uid = [];
        foreach ($children as $node) $by_uid[$node['attrs']['uid'] ?? ''] = $node;

        $reordered = [];
        foreach ($layout as $entry) {
            $node_uid = sanitize_text_field($entry['uid'] ?? '');
            $column = sanitize_text_field($entry['column'] ?? '');
            if (!isset($by_uid[$node_uid])) continue;
            if (!in_array($column, $columns, true)) $column = $columns[0];
            $node = $by_uid[$node_uid];
            $node['attrs']['column'] = $column;
            $reordered[] = $node;
            unset($by_uid[$node_uid]);
        }
        foreach ($by_uid as $leftover) $reordered[] = $leftover;
        $children = $reordered;

        BH_Content::save('bh_element', $content_id, $tree);
        wp_send_json_success();
    }

    public static function handle_toggle() {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        check_admin_referer('bhcrm_subtask_' . $node_uid);
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $node = &self::find_node($tree, array_merge($path, [$node_uid]));
        if ($node !== null) {
            $node['attrs']['done'] = empty($node['attrs']['done']);
            BH_Content::save('bh_element', $content_id, $tree);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    public static function handle_save() {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        check_admin_referer('bhcrm_subtask_' . $node_uid);
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $node = &self::find_node($tree, array_merge($path, [$node_uid]));
        if ($node !== null) {
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            if ($title !== '') $node['attrs']['title'] = $title;
            $node['attrs']['notes'] = wp_kses_post(wp_unslash($_POST['notes'] ?? ''));
            BH_Content::save('bh_element', $content_id, $tree);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    public static function handle_delete() {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        check_admin_referer('bhcrm_subtask_' . $node_uid);
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $siblings = &self::children_at($tree, $path);
        foreach ($siblings as $i => $sib) {
            if (($sib['attrs']['uid'] ?? '') === $node_uid) {
                array_splice($siblings, $i, 1);
                break;
            }
        }
        BH_Content::save('bh_element', $content_id, $tree);
        if (class_exists('OUS_Audit')) {
            OUS_Audit::log('subtask_deleted', 'bhcrm_project', $project_id, ['card_id' => $card_id, 'node_uid' => $node_uid]);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }
}
