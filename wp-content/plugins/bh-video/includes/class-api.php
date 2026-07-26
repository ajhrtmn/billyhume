<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only public catalog API, mirroring BHS_API's shape
 * (bh-streaming/includes/class-api.php) — /bhv/v1/videos (list) and
 * /bhv/v1/videos/{id} (single). No play-count/likes/monetization-gate
 * concepts yet — those exist for audio because a monetization plugin
 * already gates them there; add the same 'bhv_video_access_allowed'
 * extension point later if/when bh-monetization-woo grows a video
 * gating story, following bhs_track_access_allowed's exact shape.
 */
class BHV_API {
    public static function register_routes() {
        register_rest_route('bhv/v1', '/videos', [
            'methods' => 'GET', 'callback' => [self::class, 'get_videos'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhv/v1', '/videos/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_video'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function video_url_for($post_id) {
        $aid = (int) get_post_meta($post_id, '_bhv_attachment_id', true);
        return $aid ? wp_get_attachment_url($aid) : '';
    }

    public static function video_payload($post) {
        $track_id = (int) get_post_meta($post->ID, '_bhv_track_id', true);
        $genres = wp_get_post_terms($post->ID, 'bhv_genre', ['fields' => 'names']);

        return [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'url'      => self::video_url_for($post->ID),
            'genres'   => is_wp_error($genres) ? [] : $genres,
            'track_id' => $track_id ?: null,
            // 'chapters'/'resumeSeconds' land here once BHS_Chapters is
            // ported (see bh-video's own class-chapters.php, not yet
            // built — task #71 in the video/live scoping plan).
        ];
    }

    public static function get_videos() {
        $posts = get_posts(['post_type' => 'bhv_video', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order date', 'order' => 'ASC']);
        $out = [];
        foreach ($posts as $p) {
            $payload = self::video_payload($p);
            if (!$payload['url']) continue; // no attachment yet — nothing playable to list
            $out[] = $payload;
        }
        return new WP_REST_Response(['success' => true, 'videos' => $out], 200);
    }

    private static function find_video($id) {
        $post = get_post((int) $id);
        if (!$post || $post->post_type !== 'bhv_video' || $post->post_status !== 'publish') return null;
        return $post;
    }

    public static function get_video($req) {
        $post = self::find_video($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Video not found.', ['status' => 404]);
        return new WP_REST_Response(['success' => true, 'video' => self::video_payload($post)], 200);
    }
}
