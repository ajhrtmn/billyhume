<?php
if (!defined('ABSPATH')) exit;

/**
 * Nested kanban view for a sticky card's sub-tasks: every tree level renders as a full board.
 *
 * - Sub-tasks are 'bhcrm/sub-card' nodes in the card's BH_Content tree ('bh_element', content_context_id),
 *   each with a stable 'uid' attr; all levels share the project's columns_config.
 * - The viewed level is a uid path, carried as the comma-joined 'subtask_path' query arg.
 * - Sub-cards have no timestamps, so there is no stalled badge here; they can't be linked via
 *   BHCRM_Links either (its ids are integers, a node only has a string uid).
 */
class BHCRM_Subtasks {
    // Soft warning when a card's whole tree is big enough to be its own project; not a limit.
    const SIZE_WARNING_THRESHOLD = 50;
    // Past this many breadcrumb segments the middle collapses to "…".
    const BREADCRUMB_COLLAPSE_AT = 5;

    public static function init(): void {
        add_action('admin_post_bhcrm_subtask_add', [self::class, 'handle_add']);
        add_action('admin_post_bhcrm_subtask_bulk_add', [self::class, 'handle_bulk_add']);
        add_action('admin_post_bhcrm_subtask_toggle', [self::class, 'handle_toggle']);
        add_action('admin_post_bhcrm_subtask_save', [self::class, 'handle_save']);
        add_action('admin_post_bhcrm_subtask_delete', [self::class, 'handle_delete']);
        add_action('admin_post_bhcrm_subtask_reorder', [self::class, 'handle_reorder']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    /** Only on ?page=bh-crm&card_id=. Reuses the top-level board's stylesheet. */
    public static function maybe_enqueue(string $hook): void {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['card_id'])) return;
        wp_enqueue_style('bhcrm-kanban-board', BHCRM_URL . 'assets/css/kanban-board.css', [], BHCRM_VER);
        wp_enqueue_script('sortablejs', BHCRM_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        wp_enqueue_script('bhcrm-subtasks', BHCRM_URL . 'assets/js/subtasks.js', ['sortablejs'], BHCRM_VER, true);
        wp_localize_script('bhcrm-subtasks', 'bhcrmSubtasksConfig', [
            'ajaxUrl' => esc_url_raw(admin_url('admin-post.php')),
            'nonce'   => wp_create_nonce('bhcrm_subtask_reorder'),
        ]);
    }

    /** @return array<int, string> */
    private static function path_from_string(string $raw): array {
        $raw = trim((string) $raw);
        return $raw === '' ? [] : array_values(array_filter(array_map('sanitize_text_field', explode(',', $raw))));
    }

    /** @param array<int, string> $path */
    private static function path_to_string(array $path): string {
        return implode(',', $path);
    }

    /** The project's columns, shared by every nesting level. */
    /** @return array<int, string> */
    private static function project_columns(int $project_id): array {
        $project = class_exists('BHCRM_Projects') ? BHCRM_Projects::get($project_id) : null;
        $columns = $project['columns_config'] ?? [];
        return $columns ?: (class_exists('BHCRM_Projects') ? BHCRM_Projects::DEFAULT_COLUMNS : ['To Do', 'In Progress', 'Review', 'Done']);
    }

    /** Returns a reference to the node at $path so callers can mutate it before re-saving the whole tree. */
    /**
     * @param array<int, array<string, mixed>> $tree
     * @param array<int, string> $path
     * @return array<string, mixed>|null
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
    /**
     * @param array<int, array<string, mixed>> $tree
     * @param array<int, string> $path
     * @return array<int, array<string, mixed>>
     */
    private static function &children_at(array &$tree, array $path) {
        if (!$path) return $tree;
        $node = &self::find_node($tree, $path);
        $empty = [];
        if ($node === null) return $empty;
        if (!isset($node['children']) || !is_array($node['children'])) $node['children'] = [];
        return $node['children'];
    }

    /**
     * Assigns a uid to any node missing one.
     * WHY: blank uids collide as array keys when children are indexed by uid, dropping siblings on save.
     */
    /** @param array<int, array<string, mixed>> $tree */
    private static function backfill_uids(array &$tree): bool {
        $changed = false;
        foreach ($tree as &$node) {
            if (empty($node['attrs']['uid'])) {
                $node['attrs']['uid'] = wp_generate_password(12, false);
                $changed = true;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                if (self::backfill_uids($node['children'])) $changed = true;
            }
        }
        unset($node);
        return $changed;
    }

    /** Loads the card's tree and persists a uid backfill if one was needed. */
    /** @return array<int, array<string, mixed>> */
    private static function load_tree(int $content_id): array {
        $tree = class_exists('BH_Content') ? BH_Content::get('bh_element', $content_id) : [];
        if (!is_array($tree)) $tree = [];
        if (self::backfill_uids($tree)) {
            BH_Content::save('bh_element', $content_id, $tree);
        }
        return $tree;
    }

    /**
     * Rebuilds $children to match the posted {uid, column} layout; marks done anything dropped in the last column.
     * Nodes missing from the layout, and blank-uid nodes (never keyed by ''), are appended rather than dropped.
     */
    /**
     * @param array<int, array<string, mixed>> $children
     * @param array<int, array<string, mixed>> $layout
     * @param array<int, string> $columns
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private static function apply_reorder(array $children, array $layout, array $columns): array {
        $by_uid = [];
        $blank_uid_nodes = [];
        foreach ($children as $node) {
            $node_uid = $node['attrs']['uid'] ?? '';
            if ($node_uid === '') {
                $blank_uid_nodes[] = $node;
                continue;
            }
            $by_uid[$node_uid] = $node;
        }

        // WHY: one-directional — dropping into the last column marks done; dragging out never un-marks.
        $done_column = end($columns);

        $reordered = [];
        $state_changed = false; // did any card's done-ness flip?
        foreach ($layout as $entry) {
            $node_uid = sanitize_text_field($entry['uid'] ?? '');
            $column = sanitize_text_field($entry['column'] ?? '');
            if (!isset($by_uid[$node_uid])) continue;
            if (!in_array($column, $columns, true)) $column = $columns[0];
            $node = $by_uid[$node_uid];
            $was_done = !empty($node['attrs']['done']);
            $node['attrs']['column'] = $column;
            if ($column === $done_column) $node['attrs']['done'] = true;
            if (!$was_done && !empty($node['attrs']['done'])) $state_changed = true;
            $reordered[] = $node;
            unset($by_uid[$node_uid]);
        }
        foreach ($by_uid as $leftover) $reordered[] = $leftover;
        foreach ($blank_uid_nodes as $leftover) $reordered[] = $leftover;

        return [$reordered, $state_changed];
    }

    /** Total node count across the WHOLE tree (every level, every column) — the size-warning signal. */
    /** @param array<int, array<string, mixed>> $tree */
    private static function total_node_count(array $tree): int {
        $count = 0;
        foreach ($tree as $node) {
            $count++;
            if (!empty($node['children']) && is_array($node['children'])) {
                $count += self::total_node_count($node['children']);
            }
        }
        return $count;
    }

    /** WHY: BH_Element stores each attr as {literal: 'x'} (or {source: ...}), not a flat value. */
    /** @param array<string, mixed> $card */
    private static function card_title(array $card): string {
        return (string) ($card['config']['attrs']['title']['literal'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private static function load_card(int $project_id, int $card_id): ?array {
        if (!class_exists('BH_Element')) return null;
        foreach (BH_Element::get_placements('bhcrm_project_board', (int) $project_id, 'board') as $p) {
            if ((int) $p['id'] === (int) $card_id) return $p;
        }
        return null;
    }

    private static function base_url(int $project_id, int $uid, int $card_id): string {
        return admin_url('admin.php?page=bh-crm&user_id=' . (int) $uid . '&project_id=' . (int) $project_id . '&card_id=' . (int) $card_id);
    }

    /* =================================================================
     * Render
     * ================================================================= */

    /** @param array<int, string> $path */
    public static function render(int $project_id, int $uid, int $card_id, array $path): void {
        $card = self::load_card($project_id, $card_id);
        if (!$card) {
            echo '<p>Card not found.</p>';
            return;
        }
        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = self::load_tree($content_id);
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
            self::render_progress_bar($done_count, $total_count);
        }

        // The card log belongs to the card, so it only renders at the tree root.
        if (!$path && class_exists('BHCRM_CardLog')) {
            BHCRM_CardLog::render($project_id, $uid, $card_id);
        }

        self::render_board($project_id, $uid, $card_id, $path, $children, $columns);
        self::render_bulk_add_form($project_id, $uid, $card_id, $path, $columns);
    }

    /** Progress bar with done/total count; $mini is the in-card variant. */
    private static function render_progress_bar(int $done, int $total, bool $mini = false): void {
        if ($total <= 0) return;
        $pct = class_exists('BHCRM_Projects') ? BHCRM_Projects::progress_percent($done, $total) : (int) round(($done / $total) * 100);
        $class = 'bhcrm-progress-bar' . ($mini ? ' bhcrm-progress-bar-mini' : '');
        echo '<div class="' . esc_attr($class) . '">'
           . '<div class="bhcrm-progress-bar-track"><div class="bhcrm-progress-bar-fill' . ($pct >= 100 ? ' is-complete' : '') . '" style="width:' . $pct . '%;"></div></div>'
           . '<span class="bhcrm-progress-bar-label">' . (int) $done . '/' . (int) $total . ' &middot; ' . $pct . '%</span>'
           . '</div>';
    }

    /** Breadcrumb from the card down to $path; long chains collapse the middle to a linked "…". */
    /**
     * @param array<string, mixed> $card
     * @param array<int, array<string, mixed>> $tree
     * @param array<int, string> $path
     */
    private static function render_breadcrumb(int $project_id, int $uid, int $card_id, array $card, array $tree, array $path): void {
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

    /** One column per project column; drag handled by subtasks.js. */
    /**
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $children
     * @param array<int, string> $columns
     */
    private static function render_board(int $project_id, int $uid, int $card_id, array $path, array $children, array $columns): void {
        $by_column = array_fill_keys($columns, []);
        foreach ($children as $node) {
            $col = $node['attrs']['column'] ?? '';
            if (!isset($by_column[$col])) $col = $columns[0]; // unknown/blank column falls back to the first, never dropped
            $by_column[$col][] = $node;
        }

        echo '<div class="bhcrm-kanban-board"><div class="bhcrm-kanban-grid" id="bhcrm-subtask-board"'
           . ' data-reorder-nonce="' . esc_attr(wp_create_nonce('bhcrm_subtask_reorder')) . '"'
           . ' data-project-id="' . (int) $project_id . '" data-user-id="' . (int) $uid . '" data-card-id="' . (int) $card_id . '"'
           . ' data-subtask-path="' . esc_attr(self::path_to_string($path)) . '"'
           . ' data-done-column="' . esc_attr(end($columns)) . '">';

        $done_column = end($columns);
        reset($columns);
        foreach ($columns as $col) {
            $cards_in_col = $by_column[$col];
            echo '<div class="bhcrm-kanban-column" data-column="' . esc_attr($col) . '">';
            echo '<div class="bhcrm-kanban-column-header">' . esc_html($col) . ' <span class="bhcrm-kanban-column-count">(' . count($cards_in_col) . ')</span>';
            if ($col === $done_column) {
                echo ' <span class="bhcrm-kanban-column-hint" title="Dropping a card here marks it done. Dragging it back out does not un-mark it.">&#9432;</span>';
            }
            echo '</div>';
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

    /**
     * @param array<int, string> $path
     * @param array<string, mixed> $node
     */
    private static function render_card(int $project_id, int $uid, int $card_id, array $path, array $node): void {
        $node_uid = $node['attrs']['uid'] ?? '';
        $title = $node['attrs']['title'] ?? '(untitled)';
        $notes = $node['attrs']['notes'] ?? '';
        $done = !empty($node['attrs']['done']);
        $children = $node['children'] ?? [];
        [$child_done, $child_total] = class_exists('BHCRM_Projects') ? BHCRM_Projects::rollup_counts($children) : [0, 0];

        $base = self::base_url($project_id, $uid, $card_id);
        $child_path_str = self::path_to_string(array_merge($path, [$node_uid]));

        // subtasks.js reads data-node-uid for both reorder and inline-edit saves.
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
        // Saved on change via fetch() by subtasks.js.
        echo '<input type="text" class="bhcrm-subtask-title-input' . ($done ? ' is-done' : '') . '" value="' . esc_attr($title) . '">';
        echo '</div>';

        if ($child_total > 0) self::render_progress_bar($child_done, $child_total, true);

        // The block's 'notes' attr.
        echo '<textarea class="bhcrm-subtask-desc-input" rows="2" placeholder="Add a description…">' . esc_textarea(wp_strip_all_tags($notes)) . '</textarea>';
        echo '<span class="bhcrm-subtask-save-status description"></span>';

        echo '<div class="bhcrm-kanban-card-actions">';
        echo '<a class="button button-small" href="' . esc_url($base . '&subtask_path=' . urlencode($child_path_str)) . '">Open board (' . (int) $child_total . ') &rarr;</a>';

        $delete_url = wp_nonce_url($base . '&subtask_path=' . urlencode(self::path_to_string($path)), 'bhcrm_subtask_delete_' . $node_uid);
        // Two-click arm/confirm handled in subtasks.js; no native confirm().
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bhcrm-subtask-delete-form" style="display:inline;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_delete">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<button type="submit" class="button button-small bhcrm-subtask-delete-btn" style="color:#b32d2e;">Delete</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /** @param array<int, string> $path */
    private static function render_add_form(int $project_id, int $uid, int $card_id, array $path, string $column): void {
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

    /** Bulk add: one title per line, all into one chosen column. */
    /**
     * @param array<int, string> $path
     * @param array<int, string> $columns
     */
    private static function render_bulk_add_form(int $project_id, int $uid, int $card_id, array $path, array $columns): void {
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

    /** @return array<string, mixed> */
    private static function require_access(int $project_id, int $card_id): array {
        if (class_exists('OUS_Audit')) {
            OUS_Audit::require_cap('bhcore_manage_crm');
        } elseif (!current_user_can('bhcore_manage_crm')) {
            wp_die('Not allowed.');
        }
        $card = self::load_card($project_id, $card_id);
        if (!$card) wp_die('Card not found.');
        return $card;
    }

    /** @param array<int, string> $path     */
    private static function redirect_back(int $project_id, int $uid, int $card_id, array $path): void {
        wp_safe_redirect(self::base_url($project_id, $uid, $card_id) . '&subtask_path=' . urlencode(self::path_to_string($path)));
        exit;
    }

    public static function handle_add(): void {
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
        $tree = self::load_tree($content_id);
        $children = &self::children_at($tree, $path);
        $new_uid = wp_generate_password(12, false);
        $children[] = ['type' => 'bhcrm/sub-card', 'attrs' => ['uid' => $new_uid, 'title' => $title, 'notes' => '', 'done' => false, 'column' => $column], 'children' => []];
        BH_Content::save('bh_element', $content_id, $tree);

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    public static function handle_bulk_add(): void {
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
            $tree = self::load_tree($content_id);
            $children = &self::children_at($tree, $path);
            foreach ($titles as $title) {
                $children[] = ['type' => 'bhcrm/sub-card', 'attrs' => ['uid' => wp_generate_password(12, false), 'title' => $title, 'notes' => '', 'done' => false, 'column' => $column], 'children' => []];
            }
            BH_Content::save('bh_element', $content_id, $tree);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    /**
     * Drag-reorder via fetch() from subtasks.js. 'layout' is a JSON array of {uid, column}
     * for every card in on-screen order; see apply_reorder().
     */
    public static function handle_reorder(): void {
        check_ajax_referer('bhcrm_subtask_reorder', 'nonce');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $card = self::require_access($project_id, $card_id);

        $layout = json_decode((string) wp_unslash($_POST['layout'] ?? ''), true);
        if (!is_array($layout)) wp_send_json_error(['message' => 'No layout given.']);

        $columns = self::project_columns($project_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = self::load_tree($content_id);
        $children = &self::children_at($tree, $path);

        [$reordered, $state_changed] = self::apply_reorder($children, $layout, $columns);
        $children = $reordered;

        BH_Content::save('bh_element', $content_id, $tree);

        // state_changed = a card was auto-completed, so the client reloads to refresh ancestor progress.
        wp_send_json_success(['state_changed' => $state_changed]);
    }

    public static function handle_toggle(): void {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        check_admin_referer('bhcrm_subtask_' . $node_uid);
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = self::load_tree($content_id);
        $node = &self::find_node($tree, array_merge($path, [$node_uid]));
        if ($node !== null) {
            $node['attrs']['done'] = empty($node['attrs']['done']);
            BH_Content::save('bh_element', $content_id, $tree);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }

    /** Fetch-based save for inline title/notes edits; shares the per-page reorder nonce. */
    public static function handle_save(): void {
        check_ajax_referer('bhcrm_subtask_reorder', 'nonce');
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = self::load_tree($content_id);
        $node = &self::find_node($tree, array_merge($path, [$node_uid]));
        if ($node === null) wp_send_json_error(['message' => 'Sub-task not found.']);

        if (isset($_POST['title'])) {
            $title = sanitize_text_field(wp_unslash($_POST['title']));
            if ($title !== '') $node['attrs']['title'] = $title;
        }
        if (isset($_POST['notes'])) {
            $node['attrs']['notes'] = wp_kses_post(wp_unslash($_POST['notes']));
        }
        BH_Content::save('bh_element', $content_id, $tree);
        wp_send_json_success(['title' => $node['attrs']['title']]);
    }

    public static function handle_delete(): void {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);
        $card_id = (int) ($_POST['card_id'] ?? 0);
        $path = self::path_from_string($_POST['subtask_path'] ?? '');
        $node_uid = sanitize_text_field($_POST['node_uid'] ?? '');
        check_admin_referer('bhcrm_subtask_' . $node_uid);
        $card = self::require_access($project_id, $card_id);

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = self::load_tree($content_id);
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
