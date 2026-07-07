<?php
if (!defined('ABSPATH')) exit;

/**
 * "Related tracks" — deliberately content-based (same artist, same
 * release, shared genre tags), not collaborative-filtering or ML-driven
 * personalization. That's an honest scoping choice, not a placeholder:
 * a real personalization engine needs actual usage data at real scale
 * to be meaningfully better than these simple, explainable rules, and
 * claiming "smart recommendations" without that would be overselling
 * what this actually does. This is the genuinely useful, correct-for-
 * right-now version.
 */
class BHS_Recommendations {
    public static function register_routes() {
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/related', [
            'methods' => 'GET', 'callback' => [self::class, 'get_related'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function get_related($req) {
        $track_id = (int) $req->get_param('id');
        $track = get_post($track_id);
        if (!$track || $track->post_type !== 'bhs_track') {
            return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        }

        $artist = get_post_meta($track_id, '_bhs_artist', true);
        $release_id = (int) get_post_meta($track_id, '_bhs_release_id', true);
        $genre_ids = wp_get_post_terms($track_id, 'bhs_genre', ['fields' => 'ids']);

        // Score every other published track by how many of these three
        // things it shares with the seed track. Simple, transparent,
        // and cheap enough to just run over the whole catalog rather
        // than needing its own index — fine at the scale this plugin
        // will realistically see for a while.
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

        $out = [];
        foreach ($top_ids as $id) {
            $p = get_post($id);
            $aid = (int) get_post_meta($id, '_bhs_audio_id', true);
            $art = (int) get_post_meta($id, '_bhs_artwork_id', true);
            if (!$aid) continue;
            $out[] = [
                'id' => $p->ID, 'title' => $p->post_title, 'artist' => get_post_meta($id, '_bhs_artist', true),
                'url' => wp_get_attachment_url($aid),
                'artwork' => $art ? wp_get_attachment_image_url($art, 'medium') : BHS_PWA::placeholder_artwork_url(),
            ];
        }

        return new WP_REST_Response(['success' => true, 'related' => $out], 200);
    }
}
