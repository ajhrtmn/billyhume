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
 * DATA MODEL: unchanged from class-projects.php's own docblock — a
 * sticky card's sub-tasks are a BH_Content tree of 'bhcrm/sub-card'
 * blocks (title/notes/done), stored at ('bh_element',
 * $card['content_context_id']). This class adds exactly one new schema
 * attr to that block type, 'uid' (a short random string, assigned once
 * at creation) — the ONE piece the existing schema was missing to
 * support real addressing: without a stable per-node id, there is no
 * way to say "the third child of the second child" that survives a
 * reorder or an edit elsewhere in the tree. See
 * BHCRM_Projects::register_content_block_type() for the schema itself.
 *
 * NAVIGATION: a $path (array of uids from the card's own root down to
 * the currently-viewed node) drives which level of the tree is shown —
 * AJ's own ask, "a good UX for moving up and down the trees of
 * boards." Carried as a comma-joined 'subtask_path' query arg. A real
 * breadcrumb (render_breadcrumb()) shows the full chain back to the
 * card itself, each segment a real link.
 *
 * PROGRESS ROLLUP: reuses BHCRM_Projects::rollup_counts() exactly —
 * that method already walks a raw tree counting done/total
 * recursively; this class just calls it at whatever depth is currently
 * being viewed, so "the parent's progress = aggregate of its children"
 * is true at EVERY level, not just the top sticky-card (which already
 * showed a rollup of its immediate BH_Content tree — this extends that
 * same math to any sub-level).
 *
 * SCOPING NOTE, disclosed rather than silently half-built: AJ also
 * asked for "details sections that allow links to people and things."
 * A sub-task has no stable INTEGER id of its own (BHCRM_Links'
 * from_id/to_id columns are bigint, and a BH_Content tree node's only
 * stable identifier is the new string 'uid' this pass adds) — bridging
 * that cleanly (a lookup table mapping uid -> a real link-table row, or
 * widening BHCRM_Links to accept a string key) is a real, separate
 * design decision that deserves its own careful pass rather than a
 * rushed schema patch bolted onto this one. Notes (freeform detail
 * text) ARE fully supported here today, via the block's own existing
 * 'notes' attr — linking to people/projects specifically is the one
 * piece intentionally left for a follow-up.
 */
class BHCRM_Subtasks {
    public static function init() {
        add_action('admin_post_bhcrm_subtask_add', [self::class, 'handle_add']);
        add_action('admin_post_bhcrm_subtask_toggle', [self::class, 'handle_toggle']);
        add_action('admin_post_bhcrm_subtask_save', [self::class, 'handle_save']);
        add_action('admin_post_bhcrm_subtask_delete', [self::class, 'handle_delete']);
    }

    private static function path_from_string($raw) {
        $raw = trim((string) $raw);
        return $raw === '' ? [] : array_values(array_filter(array_map('sanitize_text_field', explode(',', $raw))));
    }

    private static function path_to_string(array $path) {
        return implode(',', $path);
    }

    /**
     * Locates the node at $path within $tree (by uid chain) and
     * returns a REFERENCE to it so the caller can mutate its
     * 'children' array in place before the whole tree is re-saved as
     * one document (BH_Content's own storage contract — see that
     * class's docblock: one JSON blob per context, not per-node rows).
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

    /**
     * QA fix, caught live: a placement's decoded 'config' isn't a flat
     * {title: 'x'} map — BH_Element stores each attr as {literal: 'x'}
     * (or {source: ...} for a bound value), confirmed directly against
     * a real row: {"attrs":{"title":{"literal":"QA Test Card"},...}}.
     * Reading $card['config']['title'] directly always silently missed,
     * falling through to the fallback text every time.
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

        echo '<p><a href="' . esc_url(remove_query_arg(['card_id', 'subtask_path'])) . '">&larr; Back to board</a></p>';
        echo '<h2>' . esc_html(self::card_title($card) ?: 'Sub-tasks') . '</h2>';

        self::render_breadcrumb($project_id, $uid, $card_id, $card, $tree, $path);

        $children = &self::children_at($tree, $path);
        [$done_count, $total_count] = class_exists('BHCRM_Projects') ? BHCRM_Projects::rollup_counts($children) : [0, 0];
        if ($total_count > 0) {
            echo '<p class="description">' . (int) $done_count . '/' . (int) $total_count . ' sub-tasks done at this level (recursively).</p>';
        }

        echo '<div class="bhy-card">';
        if (!$children) {
            echo '<p class="description">No sub-tasks here yet.</p>';
        } else {
            foreach ($children as $node) {
                self::render_row($project_id, $uid, $card_id, $path, $node);
            }
        }
        echo '</div>';

        self::render_add_form($project_id, $uid, $card_id, $path);
    }

    /** Full chain from the card itself down to the current $path, each segment a real link — AJ's own "good UX for moving up and down the trees of boards." */
    private static function render_breadcrumb($project_id, $uid, $card_id, $card, array $tree, array $path) {
        $base = self::base_url($project_id, $uid, $card_id);
        $crumbs = ['<a href="' . esc_url($base) . '">' . esc_html(self::card_title($card) ?: 'Card') . '</a>'];

        $walked = [];
        $level = $tree;
        foreach ($path as $seg) {
            $walked[] = $seg;
            $node = null;
            foreach ($level as $n) {
                if (($n['attrs']['uid'] ?? '') === $seg) { $node = $n; break; }
            }
            if (!$node) break;
            $url = $base . '&subtask_path=' . urlencode(self::path_to_string($walked));
            $crumbs[] = '<a href="' . esc_url($url) . '">' . esc_html($node['attrs']['title'] ?? 'Sub-task') . '</a>';
            $level = $node['children'] ?? [];
        }

        echo '<p class="description">' . implode(' &rsaquo; ', $crumbs) . '</p>';
    }

    private static function render_row($project_id, $uid, $card_id, array $path, array $node) {
        $node_uid = $node['attrs']['uid'] ?? '';
        $title = $node['attrs']['title'] ?? '(untitled)';
        $notes = $node['attrs']['notes'] ?? '';
        $done = !empty($node['attrs']['done']);
        $children = $node['children'] ?? [];
        [$child_done, $child_total] = class_exists('BHCRM_Projects') ? BHCRM_Projects::rollup_counts($children) : [0, 0];

        $base = self::base_url($project_id, $uid, $card_id);
        $child_path_str = self::path_to_string(array_merge($path, [$node_uid]));

        echo '<div style="border-bottom:1px solid var(--bhy-border, #dcdcde);padding:10px 0;">';
        echo '<div style="display:flex;align-items:center;gap:8px;">';

        $toggle_url = wp_nonce_url($base . '&subtask_path=' . urlencode(self::path_to_string($path)) . '&action_target=' . urlencode($node_uid), 'bhcrm_subtask_' . $node_uid);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_toggle">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<button type="submit" class="button button-small" title="Toggle done">' . ($done ? '&#9745;' : '&#9744;') . '</button>';
        echo '</form>';

        echo '<strong' . ($done ? ' style="text-decoration:line-through;color:var(--bhy-ink-dim,#777);"' : '') . '>' . esc_html($title) . '</strong>';
        if ($child_total > 0) echo ' <span class="description">(' . (int) $child_done . '/' . (int) $child_total . ' sub-tasks done)</span>';

        echo ' <a href="' . esc_url($base . '&subtask_path=' . urlencode($child_path_str)) . '">View sub-tasks &rarr;</a>';
        echo '</div>';

        if ($notes) echo '<div class="description" style="margin:4px 0 0 32px;">' . wp_kses_post($notes) . '</div>';

        // Inline edit (title/notes) + delete — collapsed by default so
        // the list itself stays scannable.
        echo '<details style="margin:4px 0 0 32px;"><summary style="cursor:pointer;font-size:12px;">Edit / delete</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_save">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<p><input type="text" name="title" value="' . esc_attr($title) . '" style="width:300px;"></p>';
        echo '<p><textarea name="notes" rows="2" style="width:100%;max-width:500px;" placeholder="Details, links, context…">' . esc_textarea(wp_strip_all_tags($notes)) . '</textarea></p>';
        echo '<button type="submit" class="button">Save</button> ';
        echo '</form>';

        $delete_url = wp_nonce_url($base . '&subtask_path=' . urlencode(self::path_to_string($path)), 'bhcrm_subtask_delete_' . $node_uid);
        $delete_url = add_query_arg(['action_del' => $node_uid], $delete_url);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" onsubmit="return confirm(\'Delete this sub-task and everything nested under it?\');">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_delete">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        echo '<input type="hidden" name="node_uid" value="' . esc_attr($node_uid) . '">';
        wp_nonce_field('bhcrm_subtask_' . $node_uid);
        echo '<button type="submit" class="button" style="color:#b32d2e;">Delete</button>';
        echo '</form>';
        echo '</details>';
        echo '</div>';
    }

    private static function render_add_form($project_id, $uid, $card_id, array $path) {
        $base = self::base_url($project_id, $uid, $card_id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;display:flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="action" value="bhcrm_subtask_add">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="hidden" name="subtask_path" value="' . esc_attr(self::path_to_string($path)) . '">';
        wp_nonce_field('bhcrm_subtask_add');
        echo '<input type="text" name="title" placeholder="New sub-task…" required style="width:300px;">';
        echo '<button type="submit" class="button button-primary">Add sub-task</button>';
        echo '</form>';
    }

    /* =================================================================
     * Handlers — each loads the whole document, mutates in place via a
     * reference from find_node()/children_at(), then saves the WHOLE
     * tree back (BH_Content's own one-blob-per-context contract — see
     * class docblock).
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

        $content_id = (int) ($card['content_context_id'] ?: $card['id']);
        $tree = BH_Content::get('bh_element', $content_id);
        $children = &self::children_at($tree, $path);
        $new_uid = wp_generate_password(12, false);
        $children[] = ['type' => 'bhcrm/sub-card', 'attrs' => ['uid' => $new_uid, 'title' => $title, 'notes' => '', 'done' => false], 'children' => []];
        BH_Content::save('bh_element', $content_id, $tree);

        self::redirect_back($project_id, $uid, $card_id, $path);
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
        // Audit log — deleting a sub-task also deletes everything
        // nested under it, worth a record of who/what/when.
        if (class_exists('OUS_Audit')) {
            OUS_Audit::log('subtask_deleted', 'bhcrm_project', $project_id, ['card_id' => $card_id, 'node_uid' => $node_uid]);
        }

        self::redirect_back($project_id, $uid, $card_id, $path);
    }
}
