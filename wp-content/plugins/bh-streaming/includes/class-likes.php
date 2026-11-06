<?php
if (!defined('ABSPATH')) exit;

/**
 * Likes, in a dedicated table (not user meta) so per-track counts and
 * per-user lists are real queries.
 */
class BHS_Likes {
    public static function register_routes(): void {
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhs/v1', '/likes', ['methods' => 'GET', 'callback' => [self::class, 'get_liked']] + $auth);
        register_rest_route('bhs/v1', '/likes/(?P<track_id>\d+)', [
            'methods' => 'POST', 'callback' => [self::class, 'toggle_like'],
        ] + $auth);
    }

    public static function get_liked(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $t = BHS_Tables::likes();
        $uid = get_current_user_id();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT track_id FROM $t WHERE user_id = %d ORDER BY created_at DESC", $uid));
        return new WP_REST_Response(['success' => true, 'track_ids' => array_map('intval', $ids)], 200);
    }

    // One toggle endpoint; the client already knows the current state.
    /** @return \WP_REST_Response|\WP_Error */
    public static function toggle_like(\WP_REST_Request $req) {
        global $wpdb;
        $t = BHS_Tables::likes();
        $uid = get_current_user_id();
        $track_id = (int) $req->get_param('track_id');
        if (get_post_type($track_id) !== 'bhs_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

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

    public static function count_for_track(int $track_id): int {
        global $wpdb;
        $t = BHS_Tables::likes();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE track_id = %d", $track_id));
    }
}
