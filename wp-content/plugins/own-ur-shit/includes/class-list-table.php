<?php
if (!defined('ABSPATH')) exit;

/**
 * DRY/SOLID audit Phase 4: every CPT-owning plugin in this ecosystem
 * (bh-contest's bh_contest/bh_submission, bh-courses' bh_course/
 * bh_lesson, bh-streaming's bhs_track, bh-monetization-woo's bhm_tier)
 * hand-rolled the exact same four-hook boilerplate to add extra
 * columns to its own admin list table: manage_{cpt}_posts_columns,
 * manage_{cpt}_posts_custom_column, manage_edit-{cpt}_sortable_columns,
 * and (for a sortable column) pre_get_posts. This is that boilerplate,
 * written once — each plugin still owns its own column labels and
 * render logic entirely; this only wires the WordPress hooks.
 *
 * Deliberately NOT a rewrite of any existing plugin's columns —
 * consuming plugins opt in by calling ::register() from their own
 * init(), same as they'd call add_filter()/add_action() directly.
 */
class OUS_ListTable {
    /**
     * @param string   $post_type   The CPT slug (e.g. 'bh_contest').
     * @param array    $columns     Assoc array of new column key => label,
     *                              inserted immediately after the 'title'
     *                              column (WordPress's own convention —
     *                              every existing hand-rolled version in
     *                              this ecosystem already does this).
     * @param callable $render      function($column_key, $post_id): void
     *                              — echoes the cell content. Only called
     *                              for the column keys passed in $columns
     *                              above (WordPress fires the custom-
     *                              column action for every registered
     *                              column, including ones another plugin
     *                              added — this class already narrows it
     *                              to just this call's own keys).
     * @param array    $sortable    Optional. Assoc array of column key =>
     *                              meta_key to sort by (string) or
     *                              ['meta_key' => ..., 'type' => 'numeric'|'meta_value'|...]
     *                              for a pre_get_posts-driven sort — same
     *                              shape bhm_tier's own price-sort already
     *                              used.
     */
    public static function register($post_type, array $columns, callable $render, array $sortable = []) {
        add_filter("manage_{$post_type}_posts_columns", function ($cols) use ($columns) {
            $new = [];
            foreach ($cols as $key => $label) {
                $new[$key] = $label;
                if ($key === 'title') {
                    foreach ($columns as $col_key => $col_label) {
                        $new[$col_key] = $col_label;
                    }
                }
            }
            return $new;
        });

        add_action("manage_{$post_type}_posts_custom_column", function ($column, $post_id) use ($columns, $render) {
            if (isset($columns[$column])) $render($column, $post_id);
        }, 10, 2);

        if (!$sortable) return;

        add_filter("manage_edit-{$post_type}_sortable_columns", function ($cols) use ($sortable) {
            foreach ($sortable as $col_key => $meta) {
                $cols[$col_key] = $col_key;
            }
            return $cols;
        });

        add_action('pre_get_posts', function ($query) use ($post_type, $sortable) {
            if (!is_admin() || !$query->is_main_query()) return;
            if ($query->get('post_type') !== $post_type) return;
            $orderby = $query->get('orderby');
            if (!isset($sortable[$orderby])) return;

            $meta = $sortable[$orderby];
            $meta_key = is_array($meta) ? ($meta['meta_key'] ?? '') : $meta;
            $type = is_array($meta) ? ($meta['type'] ?? 'numeric') : 'numeric';
            if (!$meta_key) return;

            $query->set('meta_key', $meta_key);
            $query->set('orderby', $type === 'meta_value' ? 'meta_value' : 'meta_value_num');
        });
    }
}
