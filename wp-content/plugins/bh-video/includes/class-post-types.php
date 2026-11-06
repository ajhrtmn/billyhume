<?php
if (!defined('ABSPATH')) exit;

/**
 * bhv_video CPT (own top-level menu) and bhv_genre taxonomy. Meta:
 *   _bhv_attachment_id   the video file (a standard attachment)
 *   _bhv_track_id        optional, nullable link to a bhs_track
 * Duration is read client-side from loadedmetadata; nothing server-side can probe it.
 */
class BHV_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bhv_video';

    public static function register(): void {
        register_post_type('bhv_video', [
            'labels' => [
                'name' => 'Videos', 'menu_name' => 'Video', 'singular_name' => 'Video',
                'add_new_item' => 'Add New Video', 'edit_item' => 'Edit Video', 'all_items' => 'All Videos',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'menu_icon' => 'dashicons-video-alt3', 'supports' => ['title'], 'capability_type' => 'post',
        ]);

        // WHY: a taxonomy's show_in_menu is a plain bool (no parent slug, unlike post types);
        // true nests it under the bhv_video menu.
        register_taxonomy('bhv_genre', 'bhv_video', [
            'labels' => ['name' => 'Genres', 'singular_name' => 'Genre'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'hierarchical' => false, 'show_in_rest' => true,
        ]);
    }
}
