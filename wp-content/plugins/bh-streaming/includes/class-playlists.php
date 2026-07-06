<?php
if (!defined('ABSPATH')) exit;

/**
 * Playlists — an ordered list of track IDs stored as post meta on a
 * bh_playlist post, owned by whoever created it (post_author). Any
 * logged-in visitor can make one, not just the site admin — this is
 * listener-facing, not catalog management.
 */
class BHS_Playlists {
    public static function register_routes() {
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhs/v1', '/playlists', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_mine']] + $auth,
            ['methods' => 'POST', 'callback' => [self::class, 'create']] + $auth,
        ]);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_one'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)/tracks', [
            'methods' => 'POST', 'callback' => [self::class, 'add_track'],
        ] + $auth);
    }

    private static function owns($playlist_id, $uid) {
        $p = get_post($playlist_id);
        return $p && $p->post_type === 'bh_playlist' && (int) $p->post_author === $uid;
    }

    private static function payload($post) {
        $ids = json_decode((string) get_post_meta($post->ID, '_bhs_track_ids', true), true);
        return [
            'id' => $post->ID, 'title' => $post->post_title,
            'owner_id' => (int) $post->post_author,
            'track_ids' => is_array($ids) ? array_map('intval', $ids) : [],
        ];
    }

    public static function get_mine() {
        $posts = get_posts(['post_type' => 'bh_playlist', 'author' => get_current_user_id(), 'posts_per_page' => -1, 'post_status' => 'publish']);
        return new WP_REST_Response(['success' => true, 'playlists' => array_map([self::class, 'payload'], $posts)], 200);
    }

    public static function get_one($req) {
        $post = get_post((int) $req->get_param('id'));
        if (!$post || $post->post_type !== 'bh_playlist' || $post->post_status !== 'publish') {
            return new WP_Error('not_found', 'Playlist not found.', ['status' => 404]);
        }
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload($post)], 200);
    }

    public static function create($req) {
        $title = sanitize_text_field((string) $req->get_param('title')) ?: 'New Playlist';
        $id = wp_insert_post(['post_title' => $title, 'post_type' => 'bh_playlist', 'post_status' => 'publish', 'post_author' => get_current_user_id()], true);
        if (is_wp_error($id)) return new WP_Error('save_failed', 'Could not create playlist.', ['status' => 500]);
        update_post_meta($id, '_bhs_track_ids', wp_json_encode([]));
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($id))], 200);
    }

    public static function add_track($req) {
        $playlist_id = (int) $req->get_param('id');
        $track_id = (int) $req->get_param('track_id');
        if (!self::owns($playlist_id, get_current_user_id())) {
            return new WP_Error('forbidden', 'Not your playlist.', ['status' => 403]);
        }
        if (get_post_type($track_id) !== 'bh_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

        $ids = json_decode((string) get_post_meta($playlist_id, '_bhs_track_ids', true), true);
        if (!is_array($ids)) $ids = [];
        if (!in_array($track_id, $ids, true)) $ids[] = $track_id;
        update_post_meta($playlist_id, '_bhs_track_ids', wp_json_encode($ids));

        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($playlist_id))], 200);
    }
}
