<?php
if (!defined('ABSPATH')) exit;

class BHS_API {
    public static function register_routes() {
        register_rest_route('bhs/v1', '/tracks', [
            'methods' => 'GET', 'callback' => [self::class, 'get_tracks'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/releases', [
            'methods' => 'GET', 'callback' => [self::class, 'get_releases'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/play', [
            'methods' => 'POST', 'callback' => [self::class, 'record_play'], 'permission_callback' => '__return_true',
        ]);
    }

    // A track's audio URL is either a local attachment (_bhs_audio_id)
    // or a remote URL from an aggregated feed (_bhs_external_audio_url)
    // — resolved here once, so nothing downstream (the player, the
    // recommendations engine, the feed exporter) needs to know which
    // kind of track it's looking at.
    public static function audio_url_for($post_id) {
        $aid = (int) get_post_meta($post_id, '_bhs_audio_id', true);
        if ($aid) return wp_get_attachment_url($aid);
        return get_post_meta($post_id, '_bhs_external_audio_url', true);
    }

    public static function track_payload($post) {
        $art = (int) get_post_meta($post->ID, '_bhs_artwork_id', true);
        $genres = wp_get_post_terms($post->ID, 'bhs_genre', ['fields' => 'names']);
        $release_id = (int) get_post_meta($post->ID, '_bhs_release_id', true);

        return [
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'artist'     => get_post_meta($post->ID, '_bhs_artist', true),
            'url'        => self::audio_url_for($post->ID),
            'artwork'    => $art ? wp_get_attachment_image_url($art, 'medium') : BHS_PWA::placeholder_artwork_url(),
            'genres'     => is_wp_error($genres) ? [] : $genres,
            'release_id' => $release_id ?: null,
            'plays'      => (int) get_post_meta($post->ID, '_bhs_play_count', true),
            'likes'      => BHS_Likes::count_for_track($post->ID),
            'external'   => get_post_meta($post->ID, '_bhs_source', true) === 'external',
        ];
    }

    public static function get_tracks() {
        $posts = get_posts(['post_type' => 'bh_track', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order date', 'order' => 'ASC']);
        $out = [];
        foreach ($posts as $p) {
            $payload = self::track_payload($p);
            if (!$payload['url']) continue; // no playable audio either way — skip a dead entry
            $out[] = $payload;
        }
        return new WP_REST_Response(['success' => true, 'tracks' => $out], 200);
    }

    public static function get_releases() {
        $releases = get_posts(['post_type' => 'bh_release', 'post_status' => 'publish', 'posts_per_page' => -1]);
        $out = [];
        foreach ($releases as $r) {
            $art = (int) get_post_meta($r->ID, '_bhs_release_artwork_id', true);
            $tracks = get_posts([
                'post_type' => 'bh_track', 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => '_bhs_release_id', 'meta_value' => $r->ID, 'orderby' => 'menu_order', 'order' => 'ASC',
            ]);
            $out[] = [
                'id' => $r->ID, 'title' => $r->post_title,
                'artist' => get_post_meta($r->ID, '_bhs_release_artist', true),
                'artwork' => $art ? wp_get_attachment_image_url($art, 'medium') : BHS_PWA::placeholder_artwork_url(),
                'track_ids' => array_map(fn($t) => $t->ID, $tracks),
            ];
        }
        return new WP_REST_Response(['success' => true, 'releases' => $out], 200);
    }

    // Fire-and-forget, no auth required (matching how obviously every
    // streaming service counts anonymous listens) — a play count being
    // slightly gameable by refresh-spamming is a low-stakes problem, not
    // worth the friction of requiring an account just to listen.
    public static function record_play($req) {
        $id = (int) $req->get_param('id');
        if (get_post_type($id) !== 'bh_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        $count = (int) get_post_meta($id, '_bhs_play_count', true);
        update_post_meta($id, '_bhs_play_count', $count + 1);
        return new WP_REST_Response(['success' => true], 200);
    }
}
