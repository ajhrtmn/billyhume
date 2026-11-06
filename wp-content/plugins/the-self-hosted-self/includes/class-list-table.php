<?php
if (!defined('ABSPATH')) exit;

/**
 * Wires the four WordPress hooks for extra admin list-table columns on a CPT
 * (posts_columns, custom_column, sortable_columns, pre_get_posts). Callers own labels and rendering.
 */
class OUS_ListTable {
    /**
     * @param string   $post_type The CPT slug (e.g. 'bh_contest').
     * @param array    $columns   Column key => label, inserted right after 'title'.
     * @param callable $render    function($column_key, $post_id): void — echoes the cell; called only for this call's keys.
     * @param array    $sortable  Optional. Column key => meta_key (string) or ['meta_key' => ..., 'type' => 'numeric'|'meta_value'|...].
     * @param array<string, string> $columns
     * @param array<string, mixed> $sortable
     */
    public static function register(string $post_type, array $columns, callable $render, array $sortable = []): void {
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
