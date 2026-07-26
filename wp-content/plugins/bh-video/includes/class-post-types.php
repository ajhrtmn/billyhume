<?php
if (!defined('ABSPATH')) exit;

/**
 * bhv_video — one post per video in the standalone catalog. Own
 * top-level admin menu (unlike bh-streaming's bhs_video, which hangs
 * off bhs_track's menu as a release-attachment wrapper). Meta:
 *   _bhv_attachment_id   the uploaded video file (a real WP attachment
 *                        — wp_get_attachment_url() gets CDN offload for
 *                        free once Advanced Media Offloader is
 *                        configured, no bespoke storage code needed)
 *   _bhv_track_id        optional nullable link back to a bhs_track,
 *                        for "this is the official video for this
 *                        song" — most videos will have one, but the
 *                        catalog itself never requires it (AJ's own
 *                        call, scoping pass 2026-07-26: keep non-music
 *                        video content from being forced into a fake
 *                        track relationship)
 *   _bhv_duration_secs   cached video length, same shape as
 *                        bh-streaming's own track-duration caching
 *
 * bhv_genre is a plain WP taxonomy on bhv_video, same "just the
 * built-in taxonomy system" call bh-streaming's own bhs_genre makes.
 */
class BHV_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bhv_video';

    public static function register() {
        register_post_type('bhv_video', [
            'labels' => [
                'name' => 'Videos', 'menu_name' => 'Video', 'singular_name' => 'Video',
                'add_new_item' => 'Add New Video', 'edit_item' => 'Edit Video', 'all_items' => 'All Videos',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'menu_icon' => 'dashicons-video-alt3', 'supports' => ['title'], 'capability_type' => 'post',
        ]);

        register_taxonomy('bhv_genre', 'bhv_video', [
            'labels' => ['name' => 'Genres', 'singular_name' => 'Genre'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => self::MENU_PARENT,
            'hierarchical' => false, 'show_in_rest' => true,
        ]);
    }
}
