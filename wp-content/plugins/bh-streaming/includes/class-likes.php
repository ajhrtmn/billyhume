<?php
if (!defined('ABSPATH')) exit;

/**
 * Likes — a dedicated table (see class-activator.php) rather than user
 * meta, matching the same reasoning bh-contest's votes table uses: a
 * JSON blob in user meta gets slow and awkward to query the moment you
 * need "how many people liked this track" or "show me this user's
 * liked tracks" as a real query instead of unpacking a blob every time.
 */
class BHS_Likes {
    public static function register_routes() {
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhs/v1', '/likes', ['methods' => 'GET', 'callback' => [self::class, 'get_liked']] + $auth);
        register_rest_route('bhs/v1', '/likes/(?P<track_id>\d+)', [
            'methods' => 'POST', 'callback' => [self::class, 'toggle_like'],
        ] + $auth);
    }

    public static function get_liked($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhs_likes';
        $uid = get_current_user_id();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT track_id FROM $t WHERE user_id = %d ORDER BY created_at DESC", $uid));
        return new WP_REST_Response(['success' => true, 'track_ids' => array_map('intval', $ids)], 200);
    }

    // Toggle rather than separate like/unlike endpoints — the client
    // always knows its own current state from the /tracks or /likes
    // response, so one endpoint that flips it is simpler than two that
    // both need "already in that state" guards.
    public static function toggle_like($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhs_likes';
        $uid = get_current_user_id();
        $track_id = (int) $req->get_param('track_id');
        if (get_post_type($track_id) !== 'bh_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE user_id = %d AND track_id = %d", $uid, $track_id));
        if ($existing) {
            $wpdb->delete($t, ['id' => $existing], ['%d']);
            $liked = false;
        } else {
            $wpdb->insert($t, ['user_id' => $uid, 'track_id' => $track_id], ['%d', '%d']);
            $liked = true;
        }

        return new WP_REST_Response(['success' => true, 'liked' => $liked], 200);
    }

    public static function count_for_track($track_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhs_likes';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE track_id = %d", $track_id));
    }
}
