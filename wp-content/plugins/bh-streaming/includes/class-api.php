<?php
if (!defined('ABSPATH')) exit;

class BHS_API {
    // Read-only GET routes safe to expose cross-origin. WHY: write routes and
    // cookie+nonce routes are excluded; cross-origin can't use them anyway.
    const CORS_ROUTES = [
        '#^/bhs/v1/tracks$#',
        '#^/bhs/v1/tracks/\d+$#',
        '#^/bhs/v1/tracks/\d+/lyrics$#',
        '#^/bhs/v1/tracks/\d+/qualities$#',
        '#^/bhs/v1/tracks/\d+/related$#',
        '#^/bhs/v1/releases$#',
        '#^/bhs/v1/feed\.xml$#',
    ];

    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/tracks', [
            'methods' => 'GET', 'callback' => [self::class, 'get_tracks'], 'permission_callback' => '__return_true',
        ]);
        // Single-track lookup for clients that already know the ID.
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_track'], 'permission_callback' => '__return_true',
        ]);
        // Metadata-only endpoints (lyrics, qualities) for clients needing one field.
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/lyrics', [
            'methods' => 'GET', 'callback' => [self::class, 'get_lyrics'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/qualities', [
            'methods' => 'GET', 'callback' => [self::class, 'get_qualities'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/releases', [
            'methods' => 'GET', 'callback' => [self::class, 'get_releases'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/play', [
            'methods' => 'POST', 'callback' => [self::class, 'record_play'], 'permission_callback' => '__return_true',
        ]);
    }

    // Adds CORS headers only for CORS_ROUTES via rest_pre_serve_request, not a
    // site-wide Access-Control-Allow-Origin; everything else stays same-origin.
    public static function add_cors_headers(): void {
        add_filter('rest_pre_serve_request', function ($served, $result, $request) {
            $route = $request->get_route();
            foreach (self::CORS_ROUTES as $pattern) {
                if (preg_match($pattern, $route)) {
                    header('Access-Control-Allow-Origin: *');
                    header('Access-Control-Allow-Methods: GET');
                    break;
                }
            }
            return $served;
        }, 10, 3);
    }

    // Resolves a local attachment (_bhs_audio_id) or remote feed URL
    // (_bhs_external_audio_url) so callers needn't care which. $quality picks
    // an encode from _bhs_audio_qualities, falling back to _bhs_audio_id.
    public static function audio_url_for(int $post_id, ?string $quality = null): ?string {
        if ($quality) {
            $qualities = self::qualities_for($post_id);
            if (isset($qualities[$quality])) return wp_get_attachment_url($qualities[$quality]['attachment_id']);
        }
        $aid = (int) get_post_meta($post_id, '_bhs_audio_id', true);
        if ($aid) return wp_get_attachment_url($aid);
        return get_post_meta($post_id, '_bhs_external_audio_url', true);
    }

    // label => {attachment_id, url, filesize}; always includes the default
    // encode (labeled 'standard' if untagged).
    /** @return array<string, array<string, mixed>> */
    public static function qualities_for(int $post_id): array {
        $raw = json_decode((string) get_post_meta($post_id, '_bhs_audio_qualities', true), true);
        $map = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($map as $label => $attachment_id) {
            $attachment_id = (int) $attachment_id;
            $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
            if (!$url) continue;
            $file = get_attached_file($attachment_id);
            $out[$label] = [
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'filesize'      => ($file && file_exists($file)) ? filesize($file) : null,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function track_payload(\WP_Post $post): array {
        $art = (int) get_post_meta($post->ID, '_bhs_artwork_id', true);
        $genres = wp_get_post_terms($post->ID, 'bhs_genre', ['fields' => 'names']);
        $release_id = (int) get_post_meta($post->ID, '_bhs_release_id', true);

        $qualities = self::qualities_for($post->ID);
        $quality_out = [];
        foreach ($qualities as $label => $info) {
            $quality_out[] = ['label' => $label, 'url' => $info['url'], 'filesize' => $info['filesize']];
        }

        $lrc = (string) get_post_meta($post->ID, '_bhs_lyrics_lrc', true);
        $plain = (string) get_post_meta($post->ID, '_bhs_lyrics_text', true);

        // Gating extension point: true unless a monetization plugin hooks in.
        // bh-streaming only asks "can this visitor hear it", nothing about tiers.
        $access_allowed = apply_filters('bhs_track_access_allowed', true, $post->ID);

        return [
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'artist'     => get_post_meta($post->ID, '_bhs_artist', true),
            // WHY: a locked track never exposes its audio URL; hiding only the
            // button would restrict nothing.
            'url'        => $access_allowed ? self::audio_url_for($post->ID) : null,
            'qualities'  => $access_allowed ? $quality_out : [], // empty array = only the one default encode exists (see $url above)
            'artwork'    => $art ? wp_get_attachment_image_url($art, 'medium') : BHS_PWA::placeholder_artwork_url(),
            'genres'     => is_wp_error($genres) ? [] : $genres,
            'release_id' => $release_id ?: null,
            'plays'      => (int) get_post_meta($post->ID, '_bhs_play_count', true),
            'likes'      => BHS_Likes::count_for_track($post->ID),
            'external'   => get_post_meta($post->ID, '_bhs_source', true) === 'external',
            // Only external tracks can have a down source; local ones are always 'ok'.
            'source_health' => get_post_meta($post->ID, '_bhs_source', true) === 'external' ? BHS_Feeds::source_health($post->ID) : 'ok',
            'locked'     => !$access_allowed,
            // Paywall notice HTML when locked; empty string if nothing hooks in.
            'lock_notice' => $access_allowed ? null : apply_filters('bhs_track_lock_notice', '<p>This track requires supporter access.</p>', $post->ID),
            'lyrics'     => [
                // 'synced' is raw LRC, parsed client-side. Withheld for locked
                // tracks along with the audio.
                'synced' => $access_allowed ? ($lrc ?: null) : null,
                'plain'  => $access_allowed ? ($plain ?: null) : null,
            ],
            // Chapters are artist-authored {time, label}; resumeSeconds is this
            // listener's own position (0 when logged out).
            'chapters' => $access_allowed && class_exists('BHS_Chapters') ? BHS_Chapters::get($post->ID) : [],
            'resumeSeconds' => $access_allowed && is_user_logged_in() && class_exists('BHS_Chapters') ? BHS_Chapters::resume_position($post->ID, get_current_user_id()) : 0,
        ];
    }

    public static function get_tracks(): \WP_REST_Response {
        $posts = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order date', 'order' => 'ASC']);
        $out = [];
        foreach ($posts as $p) {
            $payload = self::track_payload($p);
            // Locked tracks stay listed without audio; skip only tracks with no
            // url that aren't locked (genuinely broken).
            if (!$payload['url'] && !$payload['locked']) continue;
            $out[] = $payload;
        }
        return new WP_REST_Response(['success' => true, 'tracks' => $out], 200);
    }

    private static function find_track(int $id): ?\WP_Post {
        $post = get_post((int) $id);
        if (!$post || $post->post_type !== 'bhs_track' || $post->post_status !== 'publish') return null;
        return $post;
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function get_track(\WP_REST_Request $req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        return new WP_REST_Response(['success' => true, 'track' => self::track_payload($post)], 200);
    }

    // Lyrics-only payload.
    /** @return \WP_REST_Response|\WP_Error */
    public static function get_lyrics(\WP_REST_Request $req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        $lrc = (string) get_post_meta($post->ID, '_bhs_lyrics_lrc', true);
        $plain = (string) get_post_meta($post->ID, '_bhs_lyrics_text', true);
        return new WP_REST_Response(['success' => true, 'lyrics' => ['synced' => $lrc ?: null, 'plain' => $plain ?: null]], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function get_qualities(\WP_REST_Request $req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        $qualities = self::qualities_for($post->ID);
        $out = [];
        foreach ($qualities as $label => $info) {
            $out[] = ['label' => $label, 'url' => $info['url'], 'filesize' => $info['filesize']];
        }
        return new WP_REST_Response(['success' => true, 'qualities' => $out, 'default_url' => self::audio_url_for($post->ID)], 200);
    }

    public static function get_releases(): \WP_REST_Response {
        $releases = get_posts(['post_type' => 'bhs_release', 'post_status' => 'publish', 'posts_per_page' => -1]);
        $out = [];
        foreach ($releases as $r) {
            $art = (int) get_post_meta($r->ID, '_bhs_release_artwork_id', true);
            $tracks = get_posts([
                'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => '_bhs_release_id', 'meta_value' => (string) $r->ID, 'orderby' => 'menu_order', 'order' => 'ASC',
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

    // No auth required to count a play (low-stakes if gamed). The
    // bhs_track_play_allowed hook lets a monetization plugin veto the play
    // (e.g. insufficient wallet credit); true unless something hooks in.
    /** @return \WP_REST_Response|\WP_Error */
    public static function record_play(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        if (get_post_type($id) !== 'bhs_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

        $allowed = apply_filters('bhs_track_play_allowed', true, $id, get_current_user_id());
        if (!$allowed) {
            return new WP_Error('payment_required', apply_filters('bhs_track_play_denied_message', 'This track requires payment to play.', $id), ['status' => 402]);
        }

        $count = (int) get_post_meta($id, '_bhs_play_count', true);
        update_post_meta($id, '_bhs_play_count', $count + 1);

        // Aggregate-only daily rollup for the metrics dashboard (class-stats.php).
        if (class_exists('BHS_Stats')) BHS_Stats::record_play($id, $req);

        return new WP_REST_Response(['success' => true], 200);
    }
}
