<?php
if (!defined('ABSPATH')) exit;

class BH_PostTypes {
    // Both CPTs share one top-level admin menu, anchored on bh_contest.
    const MENU_PARENT = 'edit.php?post_type=bh_contest';

    public static function register(): void {
        register_post_type('bh_contest', [
            'labels'       => ['name' => BHCON_Words::get('admin.contest_library.contests'), 'menu_name' => BHCON_Words::get('admin.contest_library.contests'), 'singular_name' => BHCON_Words::get('admin.crm_integration.contest'), 'all_items' => BHCON_Words::get('admin.contest_library.contests'), 'edit_item' => BHCON_Words::get('admin.admin_menus.edit_contest'), 'add_new_item' => BHCON_Words::get('admin.post_types.add_new_contest'), 'new_item' => BHCON_Words::get('admin.post_types.new_contest'), 'view_item' => BHCON_Words::get('admin.post_types.view_contest')],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true, // this CPT IS the top-level anchor
            'menu_icon'    => OUS_MenuIcons::contests(),
            // No 'editor': all contest config lives in its meta boxes.
            'supports'     => ['title'],
        ]);

        register_post_type('bh_submission', [
            'labels'       => ['name' => BHCON_Words::get('admin.admin_metaboxes.submissions'), 'singular_name' => BHCON_Words::get('admin.post_types.submission'), 'menu_name' => BHCON_Words::get('admin.admin_metaboxes.submissions'), 'edit_item' => BHCON_Words::get('admin.post_types.review_submission'), 'add_new_item' => BHCON_Words::get('admin.post_types.add_new_submission'), 'view_item' => BHCON_Words::get('admin.post_types.view_submission')],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => self::MENU_PARENT, // nested under Contests, not its own top-level item
            'supports'     => ['title', 'author'],
        ]);

        // WHY: exclude_from_search must stay false: for custom statuses it also drops
        // them from post_status => 'any' queries, which this plugin relies on.
        // Safe, since bh_submission itself is not public.
        register_post_status('rejected', [
            'label'                     => BHCON_Words::get('admin.admin_metaboxes.rejected'),
            'internal'                  => false,
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: number of rejected submissions.
            'label_count'               => _n_noop((BHCON_Words::get('admin.admin_metaboxes.rejected') . ' <span class="count">(%s)</span>'), (BHCON_Words::get('admin.admin_metaboxes.rejected') . ' <span class="count">(%s)</span>')),
        ]);
    }
}
