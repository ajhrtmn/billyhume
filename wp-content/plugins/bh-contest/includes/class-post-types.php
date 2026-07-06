<?php
if (!defined('ABSPATH')) exit;

class BH_PostTypes {
    // Both CPTs live under one top-level admin menu, anchored on bh_contest,
    // so the whole plugin reads as a single tab: Contests / Submissions /
    // Results / Debug Tools, instead of two separate top-level menu items.
    const MENU_PARENT = 'edit.php?post_type=bh_contest';

    public static function register() {
        register_post_type('bh_contest', [
            // menu_name carries the "OUS ·" prefix so this top-level menu
            // reads as part of the Own Ur Shit ecosystem even though it
            // has to stay its own top-level item (see class-registry.php's
            // docblock on why CPT list-tables aren't relocated) — name/
            // singular_name/all_items stay plain since those show up in
            // body text ("Add New Contest," "All Contests"), where a
            // prefix would read oddly.
            'labels'       => ['name' => 'Contests', 'menu_name' => 'OUS · Contest', 'singular_name' => 'Contest', 'all_items' => 'Contests'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true, // this CPT IS the top-level anchor
            'menu_icon'    => 'dashicons-awards',
            'supports'     => ['title', 'editor'],
        ]);

        register_post_type('bh_submission', [
            'labels'       => ['name' => 'Submissions', 'singular_name' => 'Submission', 'menu_name' => 'Submissions'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => self::MENU_PARENT, // nested under Contests, not its own top-level item
            'supports'     => ['title', 'author'],
        ]);
    }
}
