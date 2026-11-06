<?php
if (!defined('ABSPATH')) exit;

/**
 * "Related tracks": content-based (same artist, release, genres), not
 * collaborative filtering, which would need real usage data at scale.
 */
class BHS_Recommendations {
    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/related', [
            'methods' => 'GET', 'callback' => [self::class, 'get_related'], 'permission_callback' => '__return_true',
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function get_related(\WP_REST_Request $req) {
        $track_id = (int) $req->get_param('id');
        $track = get_post($track_id);
        if (!$track || $track->post_type !== 'bhs_track') {
            return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        }

        $artist = get_post_meta($track_id, '_bhs_artist', true);
        $release_id = (int) get_post_meta($track_id, '_bhs_release_id', true);
        $genre_ids = wp_get_post_terms($track_id, 'bhs_genre', ['fields' => 'ids']);

        // Score every other published track on shared attributes; a full scan
        // is cheap enough at this catalog size.
        $candidates = get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
            'post__not_in' => [$track_id], 'fields' => 'ids',
        ]);

        $scored = [];
        foreach ($candidates as $cid) {
            $score = 0;
            if ($artist && get_post_meta($cid, '_bhs_artist', true) === $artist) $score += 3;
            if ($release_id && (int) get_post_meta($cid, '_bhs_release_id', true) === $release_id) $score += 4;
            if ($genre_ids) {
                $their_genres = wp_get_post_terms($cid, 'bhs_genre', ['fields' => 'ids']);
                $score += count(array_intersect($genre_ids, $their_genres));
            }
            if ($score > 0) $scored[$cid] = $score;
        }

        arsort($scored);
        $top_ids = array_slice(array_keys($scored), 0, 10, true);

        // WHY: go through BHS_API::track_payload() so locked tracks get no URL
        // and external tracks resolve, same as every other read path.
        $out = [];
        foreach ($top_ids as $id) {
            $p = get_post($id);
            $payload = BHS_API::track_payload($p);
            // Keep locked tracks (paywall notice); skip only url-less unlocked ones.
            if (!$payload['locked'] && !$payload['url']) continue;
            $out[] = [
                'id' => $payload['id'], 'title' => $payload['title'], 'artist' => $payload['artist'],
                'url' => $payload['url'],
                'locked' => $payload['locked'],
                'artwork' => $payload['artwork'],
            ];
        }

        return new WP_REST_Response(['success' => true, 'related' => $out], 200);
    }
}
