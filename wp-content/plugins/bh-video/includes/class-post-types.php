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
 *
 * Duration isn't cached server-side (no ffprobe-equivalent in this
 * stack) — the player reads it client-side off the <video> element's
 * own loadedmetadata event, same as any plain HTML5 video.
 *
 * bhv_genre is a plain WP taxonomy on bhv_video, same "just the
 * built-in taxonomy system" call bh-streaming's own bhs_genre makes.
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

        // PHPStan-caught real (if minor) bug: register_taxonomy()'s
        // 'show_in_menu' is a plain bool for taxonomies, unlike post
        // types — confirmed by reading wp-admin/menu.php's real
        // consumption of it directly (`! $taxonomy->show_in_menu`, no
        // string-parent-slug handling at all, unlike post types' own
        // `true !== $post_type_obj->show_in_menu` special-case a few
        // lines away in that same file). Passing self::MENU_PARENT here
        // just evaluated truthy — harmless in that it didn't break
        // anything, but not doing what it looked like it was doing.
        // WP's real, documented behavior already nests a taxonomy under
        // its associated post type's own menu automatically when
        // show_in_menu is true, given the 'bhv_video' association above
        // — true achieves the exact same real placement, correctly.
        register_taxonomy('bhv_genre', 'bhv_video', [
            'labels' => ['name' => 'Genres', 'singular_name' => 'Genre'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'hierarchical' => false, 'show_in_rest' => true,
        ]);
    }
}
