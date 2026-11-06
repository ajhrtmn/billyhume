<?php
if (!defined('ABSPATH')) exit;

/**
 * Catalog post types and taxonomy:
 *
 * - bhs_track: one song. Artist is plain text, since feed tracks may have no local account.
 * - bhs_release: an album/EP/single grouping tracks.
 * - bhs_playlist: an ordered set of tracks, owned by any logged-in user.
 * - bhs_genre: a plain tag-style taxonomy on tracks.
 * - bhs_feed_source: an admin-chosen external feed (see class-feeds.php).
 *
 * _bhs_source marks a track 'external' (from a feed) or 'local-import'
 * (uploaded by a listener); downstream code treats all tracks the same.
 */
class BHS_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bhs_track';

    public static function register(): void {
        // CPTs always register; BHS_FORCE_HIDDEN (class-env.php) only hides their admin UI.
        $visible = !BHS_Env::hidden_in_production();

        register_post_type('bhs_track', [
            'labels' => [
                // No "OUS ·" prefix: the shared menu icon signals ecosystem membership.
                'name' => 'Tracks', 'menu_name' => 'Streaming', 'singular_name' => 'Track', 'add_new_item' => 'Add New Track',
                'edit_item' => 'Edit Track', 'all_items' => 'All Tracks',
            ],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible,
            'menu_icon' => OUS_MenuIcons::streaming(), 'supports' => ['title'], 'capability_type' => 'post',
        ]);

        register_post_type('bhs_release', [
            'labels' => [
                'name' => 'Releases', 'singular_name' => 'Release', 'add_new_item' => 'Add New Release',
                'edit_item' => 'Edit Release', 'all_items' => 'All Releases',
            ],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible ? self::MENU_PARENT : false,
            'supports' => ['title'], 'capability_type' => 'post',
        ]);

        register_post_type('bhs_playlist', [
            'labels' => [
                'name' => 'Playlists', 'singular_name' => 'Playlist', 'add_new_item' => 'Add New Playlist',
                'edit_item' => 'Edit Playlist', 'all_items' => 'All Playlists',
            ],
            // Author-owned: any logged-in visitor can build a playlist.
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible ? self::MENU_PARENT : false,
            'supports' => ['title', 'author'], 'capability_type' => 'post',
        ]);

        register_post_type('bhs_feed_source', [
            'labels' => [
                'name' => 'Feed Sources', 'singular_name' => 'Feed Source', 'add_new_item' => 'Add New Feed Source',
                'edit_item' => 'Edit Feed Source', 'all_items' => 'All Feed Sources',
            ],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible ? self::MENU_PARENT : false,
            'supports' => ['title'], 'capability_type' => 'post',
        ]);

        register_taxonomy('bhs_genre', 'bhs_track', [
            'labels' => ['name' => 'Genres', 'singular_name' => 'Genre'],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible ? self::MENU_PARENT : false,
            'hierarchical' => false, 'show_in_rest' => true,
        ]);
    }
}
