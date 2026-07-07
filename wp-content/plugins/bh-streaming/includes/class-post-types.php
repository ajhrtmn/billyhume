<?php
if (!defined('ABSPATH')) exit;

/**
 * Post types and taxonomy for the full catalog:
 *
 * - bhs_track: one song. Artist stays a plain text field rather than a
 *   link to a real account — a track from an aggregated external feed
 *   has an artist name that's just descriptive text, not necessarily
 *   anyone with a local account at all, so a hard link wouldn't always
 *   make sense here.
 * - bhs_release: an album/EP/single grouping tracks together.
 * - bhs_playlist: an ordered, curated set of tracks (owned by whoever
 *   created it — any logged-in WP user, not just admins).
 * - bhs_genre: a plain WP taxonomy on tracks — deliberately just the
 *   built-in taxonomy system, not a custom admin UI, since WordPress
 *   already has a perfectly good tag-style manager for this.
 * - bhs_feed_source: an external RSS/Podcasting-2.0 feed an admin has
 *   explicitly chosen to feature. This is the actual "aggregator, but
 *   gatekept by the artist" mechanism — see class-feeds.php. Tracks
 *   pulled from a feed source become real bhs_track posts flagged with
 *   _bhs_source = 'external', so the rest of the catalog/player code
 *   never needs to know the difference between a local and an
 *   aggregated track. A third value, _bhs_source = 'local-import' (see
 *   class-import.php), marks a track a logged-in listener uploaded of
 *   their own local files, owned by that listener rather than the site
 *   admin — same "just a track" treatment downstream, same field.
 */
class BHS_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bhs_track';

    public static function register() {
        // CPTs (and their data) always register — hidden-in-production
        // (class-env.php) only ever toggles admin UI/menu visibility,
        // never whether the post type or its rows exist. $visible is
        // false only when wp_get_environment_type() reports production
        // (or is unset), same fail-safe rule as OUS_Debug::is_locked().
        $visible = !BHS_Env::hidden_in_production();

        register_post_type('bhs_track', [
            'labels' => [
                // menu_name carries the "OUS ·" prefix so this top-level
                // menu reads as part of the Own Ur Shit ecosystem even
                // though it has to stay its own top-level item (see the
                // core's class-registry.php docblock on why CPT
                // list-tables aren't relocated) — the rest of the labels
                // stay plain since they show up in body text ("Add New
                // Track," "All Tracks"), where a prefix would read oddly.
                'name' => 'Tracks', 'menu_name' => 'OUS · Streaming', 'singular_name' => 'Track', 'add_new_item' => 'Add New Track',
                'edit_item' => 'Edit Track', 'all_items' => 'All Tracks',
            ],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible,
            'menu_icon' => 'dashicons-format-audio', 'supports' => ['title'], 'capability_type' => 'post',
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
            // public + author-owned: any logged-in visitor can build their
            // own playlist, not just admins managing the catalog. This is
            // the one post type here that ISN'T purely an admin concern.
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
