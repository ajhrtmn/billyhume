<?php
if (!defined('ABSPATH')) exit;

/**
 * Playlists — an ordered list of track IDs stored as post meta on a
 * bhs_playlist post, owned by whoever created it (post_author). Any
 * logged-in visitor can make one, not just the site admin — this is
 * listener-facing, not catalog management.
 *
 * Sharing: a playlist is private by default (_bhs_playlist_public unset)
 * — visible only to its owner. The owner can explicitly share it, which
 * generates a random, unguessable token (_bhs_playlist_share_token) and
 * flips it public; the token is what a share link actually uses, not
 * the raw numeric post ID, so a shared link can be revoked/rotated
 * (unshare, then share again) without that ever being confused with
 * "delete and recreate the playlist." /playlists/shared/{token} is the
 * one read-only, no-auth endpoint an unauthenticated recipient's
 * browser actually calls.
 */
class BHS_Playlists {
    public static function register_routes() {
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhs/v1', '/playlists', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_mine']] + $auth,
            ['methods' => 'POST', 'callback' => [self::class, 'create']] + $auth,
        ]);
        register_rest_route('bhs/v1', '/playlists/shared/(?P<token>[A-Za-z0-9]{20,64})', [
            'methods' => 'GET', 'callback' => [self::class, 'get_shared'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_one'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)/tracks', [
            'methods' => 'POST', 'callback' => [self::class, 'add_track'],
        ] + $auth);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)/share', [
            'methods' => 'POST', 'callback' => [self::class, 'share'],
        ] + $auth);
        register_rest_route('bhs/v1', '/playlists/(?P<id>\d+)/unshare', [
            'methods' => 'POST', 'callback' => [self::class, 'unshare'],
        ] + $auth);
    }

    private static function owns($playlist_id, $uid) {
        $p = get_post($playlist_id);
        return $p && $p->post_type === 'bhs_playlist' && (int) $p->post_author === $uid;
    }

    private static function payload($post, $include_share_url = false) {
        $ids = json_decode((string) get_post_meta($post->ID, '_bhs_track_ids', true), true);
        $out = [
            'id' => $post->ID, 'title' => $post->post_title,
            'owner_id' => (int) $post->post_author,
            'track_ids' => is_array($ids) ? array_map('intval', $ids) : [],
            'is_public' => (bool) get_post_meta($post->ID, '_bhs_playlist_public', true),
        ];
        if ($include_share_url && $out['is_public']) {
            $token = get_post_meta($post->ID, '_bhs_playlist_share_token', true);
            if ($token) $out['share_url'] = esc_url_raw(rest_url('bhs/v1/playlists/shared/' . $token));
        }
        return $out;
    }

    public static function get_mine() {
        $posts = get_posts(['post_type' => 'bhs_playlist', 'author' => get_current_user_id(), 'posts_per_page' => -1, 'post_status' => 'publish']);
        return new WP_REST_Response(['success' => true, 'playlists' => array_map(function ($p) { return self::payload($p, true); }, $posts)], 200);
    }

    // Public-by-ID lookup, but only actually returns anything if the
    // playlist is either shared (is_public) or the requester happens to
    // be its own owner — a private playlist's numeric ID being
    // guessable must not leak its contents.
    public static function get_one($req) {
        $post = get_post((int) $req->get_param('id'));
        if (!$post || $post->post_type !== 'bhs_playlist' || $post->post_status !== 'publish') {
            return new WP_Error('not_found', 'Playlist not found.', ['status' => 404]);
        }
        $is_public = (bool) get_post_meta($post->ID, '_bhs_playlist_public', true);
        $is_owner = is_user_logged_in() && (int) $post->post_author === get_current_user_id();
        if (!$is_public && !$is_owner) {
            return new WP_Error('forbidden', 'This playlist is private.', ['status' => 403]);
        }
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload($post, $is_owner)], 200);
    }

    // The actual link a recipient's browser hits — token-keyed rather
    // than ID-keyed so it can be revoked (unshare, re-share) independent
    // of the playlist's own identity.
    public static function get_shared($req) {
        global $wpdb;
        $token = sanitize_text_field((string) $req->get_param('token'));
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bhs_playlist_share_token' AND meta_value = %s LIMIT 1",
            $token
        ));
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'bhs_playlist' || $post->post_status !== 'publish'
            || !get_post_meta($post->ID, '_bhs_playlist_public', true)) {
            return new WP_Error('not_found', 'This shared playlist link is invalid or has been revoked.', ['status' => 404]);
        }
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload($post)], 200);
    }

    public static function create($req) {
        $title = sanitize_text_field((string) $req->get_param('title')) ?: 'New Playlist';
        $id = wp_insert_post(['post_title' => $title, 'post_type' => 'bhs_playlist', 'post_status' => 'publish', 'post_author' => get_current_user_id()], true);
        if (is_wp_error($id)) return new WP_Error('save_failed', 'Could not create playlist.', ['status' => 500]);
        update_post_meta($id, '_bhs_track_ids', wp_json_encode([]));
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($id), true)], 200);
    }

    public static function add_track($req) {
        $playlist_id = (int) $req->get_param('id');
        $track_id = (int) $req->get_param('track_id');
        if (!self::owns($playlist_id, get_current_user_id())) {
            return new WP_Error('forbidden', 'Not your playlist.', ['status' => 403]);
        }
        if (get_post_type($track_id) !== 'bhs_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

        $ids = json_decode((string) get_post_meta($playlist_id, '_bhs_track_ids', true), true);
        if (!is_array($ids)) $ids = [];
        if (!in_array($track_id, $ids, true)) $ids[] = $track_id;
        update_post_meta($playlist_id, '_bhs_track_ids', wp_json_encode($ids));

        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($playlist_id), true)], 200);
    }

    public static function share($req) {
        $playlist_id = (int) $req->get_param('id');
        if (!self::owns($playlist_id, get_current_user_id())) {
            return new WP_Error('forbidden', 'Not your playlist.', ['status' => 403]);
        }
        $token = get_post_meta($playlist_id, '_bhs_playlist_share_token', true);
        if (!$token) {
            $token = wp_generate_password(40, false, false);
            update_post_meta($playlist_id, '_bhs_playlist_share_token', $token);
        }
        update_post_meta($playlist_id, '_bhs_playlist_public', '1');
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($playlist_id), true)], 200);
    }

    // Flips back to private. Deliberately does NOT clear the stored
    // token — re-sharing later reuses it. A site that wants a link to
    // stop working PERMANENTLY (not just "until re-shared") should
    // delete the meta directly; the common case here is "I made this
    // public by accident, take it back down," where reusing the same
    // token on the next share is the least surprising behavior.
    public static function unshare($req) {
        $playlist_id = (int) $req->get_param('id');
        if (!self::owns($playlist_id, get_current_user_id())) {
            return new WP_Error('forbidden', 'Not your playlist.', ['status' => 403]);
        }
        delete_post_meta($playlist_id, '_bhs_playlist_public');
        return new WP_REST_Response(['success' => true, 'playlist' => self::payload(get_post($playlist_id))], 200);
    }
}
