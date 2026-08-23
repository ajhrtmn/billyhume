<?php
if (!defined('ABSPATH')) exit;

/**
 * The fan-facing half of the federated-library vision (AJ's own direct
 * description, 2026-08-21): admins curate the site's own shared catalog
 * via bh-registry's "Browse Registry" (one-click, creates a real
 * bhs_feed_source, visible to everyone); fans curate a PERSONAL,
 * platform-independent library from the same global registry, without
 * that ever polluting the shared local catalog. Deliberately a
 * separate table from bhs_likes/bhs_playlists (both keyed to a real
 * local bhs_track post ID) — a track a fan adds here may never become
 * a permanent local post at all. Same "never re-host, just point at
 * the real source" principle bh-streaming's own admin-side feed import
 * already follows, just personal instead of shared.
 */
class BHS_FanLibrary {
    public static function register_routes(): void {
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhs/v1', '/fan-library', ['methods' => 'GET', 'callback' => [self::class, 'get_library']] + $auth);
        register_rest_route('bhs/v1', '/fan-library', ['methods' => 'POST', 'callback' => [self::class, 'add_item']] + $auth);
        register_rest_route('bhs/v1', '/fan-library/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [self::class, 'remove_item']] + $auth);
    }

    private static function table(): string {
        return BHS_Tables::fan_library();
    }

    public static function get_library(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, feed_url, track_guid, title, artist, audio_url, artwork_url, duration, added_at
             FROM ' . self::table() . ' WHERE user_id = %d ORDER BY added_at DESC', $uid
        ), ARRAY_A);
        $out = array_map(function ($r) {
            $r['id'] = (int) $r['id'];
            return $r;
        }, $rows);
        return new WP_REST_Response(['success' => true, 'items' => $out], 200);
    }

    // Real validation on every field a client could get wrong or send
    // maliciously — this endpoint takes arbitrary strings describing a
    // REMOTE track (there's no local post to validate against the way
    // toggle_like() checks get_post_type()), so it has to do more work
    // itself than a same-site endpoint would.
    /** @return \WP_REST_Response|\WP_Error */
    public static function add_item(\WP_REST_Request $req) {
        $feed_url  = esc_url_raw((string) $req->get_param('feed_url'));
        $track_guid = sanitize_text_field((string) $req->get_param('track_guid'));
        $title     = sanitize_text_field((string) $req->get_param('title'));
        $artist    = sanitize_text_field((string) $req->get_param('artist'));
        $audio_url = esc_url_raw((string) $req->get_param('audio_url'));
        $artwork_url = esc_url_raw((string) $req->get_param('artwork_url'));
        $duration  = sanitize_text_field((string) $req->get_param('duration'));

        if ($feed_url === '' || $track_guid === '' || $title === '' || $audio_url === '') {
            return new WP_Error('missing_fields', 'A library item needs at least a feed URL, track ID, title, and audio URL.', ['status' => 400]);
        }
        if (strlen($feed_url) > 191 || strlen($track_guid) > 191) {
            return new WP_Error('too_long', 'That feed URL or track ID is longer than this library can store.', ['status' => 400]);
        }

        global $wpdb;
        $uid = get_current_user_id();
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND feed_url = %s AND track_guid = %s',
            $uid, $feed_url, $track_guid
        ));
        if ($existing) {
            return new WP_Error('already_added', 'That track is already in your library.', ['status' => 409]);
        }

        $inserted = $wpdb->insert(self::table(), [
            'user_id' => $uid, 'feed_url' => $feed_url, 'track_guid' => $track_guid,
            'title' => $title, 'artist' => $artist, 'audio_url' => $audio_url,
            'artwork_url' => $artwork_url, 'duration' => $duration,
        ]);
        if (!$inserted) {
            return new WP_Error('db_error', 'Could not save that track — ' . $wpdb->last_error, ['status' => 500]);
        }

        return new WP_REST_Response(['success' => true, 'id' => (int) $wpdb->insert_id], 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function remove_item(\WP_REST_Request $req) {
        global $wpdb;
        $uid = get_current_user_id();
        $id = (int) $req->get_param('id');

        // Ownership checked in the WHERE clause itself, not a separate
        // read-then-check — a delete matching zero rows (someone else's
        // item, or one that never existed) can't be distinguished from
        // "not yours" by the caller either way, and shouldn't be.
        $deleted = $wpdb->delete(self::table(), ['id' => $id, 'user_id' => $uid], ['%d', '%d']);
        if (!$deleted) {
            return new WP_Error('not_found', 'No such item in your library.', ['status' => 404]);
        }
        return new WP_REST_Response(['success' => true], 200);
    }
}
