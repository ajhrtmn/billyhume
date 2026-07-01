<?php
if (!defined('ABSPATH')) exit;

class BH_PostTypes {
    // Both CPTs live under one top-level admin menu, anchored on bh_contest,
    // so the whole plugin reads as a single tab: Contests / Submissions /
    // Results / Debug Tools, instead of two separate top-level menu items.
    const MENU_PARENT = 'edit.php?post_type=bh_contest';

    public static function register() {
        register_post_type('bh_contest', [
            'labels'       => ['name' => 'BillyHume Contest', 'menu_name' => 'BillyHume Contest', 'singular_name' => 'Contest', 'all_items' => 'Contests'],
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
