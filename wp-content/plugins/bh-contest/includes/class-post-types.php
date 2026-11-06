<?php
if (!defined('ABSPATH')) exit;

class BH_PostTypes {
    // Both CPTs share one top-level admin menu, anchored on bh_contest.
    const MENU_PARENT = 'edit.php?post_type=bh_contest';

    public static function register(): void {
        register_post_type('bh_contest', [
            'labels'       => ['name' => 'Contests', 'menu_name' => 'Contests', 'singular_name' => 'Contest', 'all_items' => 'Contests', 'edit_item' => 'Edit Contest', 'add_new_item' => 'Add New Contest', 'new_item' => 'New Contest', 'view_item' => 'View Contest'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true, // this CPT IS the top-level anchor
            'menu_icon'    => OUS_MenuIcons::contests(),
            // No 'editor': all contest config lives in its meta boxes.
            'supports'     => ['title'],
        ]);

        register_post_type('bh_submission', [
            'labels'       => ['name' => 'Submissions', 'singular_name' => 'Submission', 'menu_name' => 'Submissions', 'edit_item' => 'Review Submission', 'add_new_item' => 'Add New Submission', 'view_item' => 'View Submission'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => self::MENU_PARENT, // nested under Contests, not its own top-level item
            'supports'     => ['title', 'author'],
        ]);

        // WHY: exclude_from_search must stay false: for custom statuses it also drops
        // them from post_status => 'any' queries, which this plugin relies on.
        // Safe, since bh_submission itself is not public.
        register_post_status('rejected', [
            'label'                     => 'Rejected',
            'internal'                  => false,
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: number of rejected submissions.
            'label_count'               => _n_noop('Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>'),
        ]);
    }
}
