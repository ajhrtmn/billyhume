<?php
if (!defined('ABSPATH')) exit;

class BHS_API {
    // Public, read-only GET routes that are safe and useful to expose
    // cross-origin — a native app, another site's "add this artist's
    // feed" flow, or anything else pulling just one narrow piece of
    // metadata (lyrics, quality list) instead of the whole /tracks
    // payload. Deliberately does NOT include /play, /likes, /playlists,
    // or /import — those either write data or rely on WP's cookie+nonce
    // auth, which isn't meaningful cross-origin anyway (a third-party
    // origin can't present this site's session cookie), so there's no
    // reason to widen CORS for them. See add_cors_headers() below.
    const CORS_ROUTES = [
        '#^/bhs/v1/tracks$#',
        '#^/bhs/v1/tracks/\d+$#',
        '#^/bhs/v1/tracks/\d+/lyrics$#',
        '#^/bhs/v1/tracks/\d+/qualities$#',
        '#^/bhs/v1/tracks/\d+/related$#',
        '#^/bhs/v1/releases$#',
        '#^/bhs/v1/feed\.xml$#',
    ];

    public static function register_routes() {
        register_rest_route('bhs/v1', '/tracks', [
            'methods' => 'GET', 'callback' => [self::class, 'get_tracks'], 'permission_callback' => '__return_true',
        ]);
        // Single-track lookup — the narrow-fetch counterpart to the full
        // list, for a client (native app, another site) that already
        // knows the ID and just wants one track's current metadata
        // without re-pulling the whole catalog.
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_track'], 'permission_callback' => '__return_true',
        ]);
        // Dedicated metadata-only endpoints: a client that only cares
        // about lyrics (e.g. a lyrics-display widget) or only cares
        // about available quality encodes (e.g. deciding what to show
        // in a download-quality picker) doesn't need to fetch, parse,
        // and discard the entire track object to get one field.
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

    // Widens CORS for exactly the read-only metadata routes listed in
    // CORS_ROUTES above — everything else (likes, playlists, import,
    // play-count) stays same-origin-only by omission, which is the
    // existing, correct default for WordPress's REST API. Uses
    // rest_pre_serve_request rather than a blanket 'Access-Control-Allow-
    // Origin: *' sent for the whole site, so this plugin only ever
    // widens exposure for the specific endpoints it has actually
    // reasoned about being safe to expose.
    public static function add_cors_headers() {
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

    // A track's audio URL is either a local attachment (_bhs_audio_id)
    // or a remote URL from an aggregated feed (_bhs_external_audio_url)
    // — resolved here once, so nothing downstream (the player, the
    // recommendations engine, the feed exporter) needs to know which
    // kind of track it's looking at.
    //
    // $quality optionally selects one of several encodes stored in
    // _bhs_audio_qualities (a JSON map of label => attachment ID, e.g.
    // {"lossless": 41, "standard": 42}) — set via the track's Quality
    // Encodes metabox (see class-admin.php). Falls back to the single
    // _bhs_audio_id a track has always had, so a track with only one
    // encode (the overwhelming majority, especially anything imported
    // from a feed or a local upload) behaves exactly as before.
    public static function audio_url_for($post_id, $quality = null) {
        if ($quality) {
            $qualities = self::qualities_for($post_id);
            if (isset($qualities[$quality])) return wp_get_attachment_url($qualities[$quality]['attachment_id']);
        }
        $aid = (int) get_post_meta($post_id, '_bhs_audio_id', true);
        if ($aid) return wp_get_attachment_url($aid);
        return get_post_meta($post_id, '_bhs_external_audio_url', true);
    }

    // Returns a label => {attachment_id, url, filesize} map. Always
    // includes the track's default/primary encode under whatever label
    // it was tagged with (or 'standard' if never explicitly labeled),
    // so the player always has at least one entry to show even for a
    // track nobody has bothered adding extra encodes to.
    public static function qualities_for($post_id) {
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

    public static function track_payload($post) {
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

        // Monetization gating extension point: no-op (always true, i.e.
        // fully open) unless a monetization plugin (bh-monetization-woo)
        // hooks in with its own tier/purchase check for this specific
        // track. This is the ONE place bh-streaming ever asks "is this
        // locked" — it never knows what tiers, prices, or entitlements
        // even are, just whether the current visitor can hear it.
        $access_allowed = apply_filters('bhs_track_access_allowed', true, $post->ID);

        return [
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'artist'     => get_post_meta($post->ID, '_bhs_artist', true),
            // A locked track never exposes a real, playable URL over the
            // API at all — gating that only hid the player button while
            // still shipping the audio URL to every visitor's browser
            // wouldn't actually restrict anything.
            'url'        => $access_allowed ? self::audio_url_for($post->ID) : null,
            'qualities'  => $access_allowed ? $quality_out : [], // empty array = only the one default encode exists (see $url above)
            'artwork'    => $art ? wp_get_attachment_image_url($art, 'medium') : BHS_PWA::placeholder_artwork_url(),
            'genres'     => is_wp_error($genres) ? [] : $genres,
            'release_id' => $release_id ?: null,
            'plays'      => (int) get_post_meta($post->ID, '_bhs_play_count', true),
            'likes'      => BHS_Likes::count_for_track($post->ID),
            'external'   => get_post_meta($post->ID, '_bhs_source', true) === 'external',
            // Only ever meaningful for an externally-aggregated track —
            // see BHS_Feeds::check_external_track_health(). A locally-
            // hosted track has no separate "source" to go down
            // independent of this site itself, so it's always 'ok'.
            'source_health' => get_post_meta($post->ID, '_bhs_source', true) === 'external' ? BHS_Feeds::source_health($post->ID) : 'ok',
            'locked'     => !$access_allowed,
            // A ready-to-render paywall notice (HTML string) whenever
            // locked, same extension-point pattern: empty string if
            // nothing's hooked in, so the player has SOMETHING sane to
            // show even before a monetization plugin decides what.
            'lock_notice' => $access_allowed ? null : apply_filters('bhs_track_lock_notice', '<p>This track requires supporter access.</p>', $post->ID),
            'lyrics'     => [
                // 'synced' is LRC text ([mm:ss.xx]line per line) if the
                // source provided timing data — the player parses it
                // client-side rather than this endpoint pre-parsing it,
                // so the raw LRC stays available for anything else that
                // wants it (export, another client) without re-fetching.
                // Withheld for locked tracks along with the audio itself
                // — lyrics for paywalled content are part of what's paywalled.
                'synced' => $access_allowed ? ($lrc ?: null) : null,
                'plain'  => $access_allowed ? ($plain ?: null) : null,
            ],
        ];
    }

    public static function get_tracks() {
        $posts = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order date', 'order' => 'ASC']);
        $out = [];
        foreach ($posts as $p) {
            $payload = self::track_payload($p);
            // A locked track has no url for a DIFFERENT reason than a
            // dead/misconfigured one (missing audio attachment) — it
            // should still show up in the catalog (so a fan can see it
            // exists and unlock it), just without playable audio. Only
            // skip the genuinely-broken case: no url AND not locked.
            if (!$payload['url'] && !$payload['locked']) continue;
            $out[] = $payload;
        }
        return new WP_REST_Response(['success' => true, 'tracks' => $out], 200);
    }

    private static function find_track($id) {
        $post = get_post((int) $id);
        if (!$post || $post->post_type !== 'bhs_track' || $post->post_status !== 'publish') return null;
        return $post;
    }

    public static function get_track($req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        return new WP_REST_Response(['success' => true, 'track' => self::track_payload($post)], 200);
    }

    // Narrow, lyrics-only payload — the whole point is a client that
    // wants just this one field without pulling (and re-parsing) the
    // full track object.
    public static function get_lyrics($req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        $lrc = (string) get_post_meta($post->ID, '_bhs_lyrics_lrc', true);
        $plain = (string) get_post_meta($post->ID, '_bhs_lyrics_text', true);
        return new WP_REST_Response(['success' => true, 'lyrics' => ['synced' => $lrc ?: null, 'plain' => $plain ?: null]], 200);
    }

    public static function get_qualities($req) {
        $post = self::find_track($req->get_param('id'));
        if (!$post) return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        $qualities = self::qualities_for($post->ID);
        $out = [];
        foreach ($qualities as $label => $info) {
            $out[] = ['label' => $label, 'url' => $info['url'], 'filesize' => $info['filesize']];
        }
        return new WP_REST_Response(['success' => true, 'qualities' => $out, 'default_url' => self::audio_url_for($post->ID)], 200);
    }

    public static function get_releases() {
        $releases = get_posts(['post_type' => 'bhs_release', 'post_status' => 'publish', 'posts_per_page' => -1]);
        $out = [];
        foreach ($releases as $r) {
            $art = (int) get_post_meta($r->ID, '_bhs_release_artwork_id', true);
            $tracks = get_posts([
                'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
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
    //
    // Pay-per-play gating hook: mirrors bhs_track_access_allowed exactly,
    // but at the moment of an actual PLAY rather than at catalog-listing
    // time — a monetization plugin can veto (insufficient wallet credit)
    // without bh-streaming knowing anything about wallets, prices, or
    // credits. No-op (always true) unless something hooks in.
    public static function record_play($req) {
        $id = (int) $req->get_param('id');
        if (get_post_type($id) !== 'bhs_track') return new WP_Error('not_found', 'Track not found.', ['status' => 404]);

        $allowed = apply_filters('bhs_track_play_allowed', true, $id, get_current_user_id());
        if (!$allowed) {
            return new WP_Error('payment_required', apply_filters('bhs_track_play_denied_message', 'This track requires payment to play.', $id), ['status' => 402]);
        }

        $count = (int) get_post_meta($id, '_bhs_play_count', true);
        update_post_meta($id, '_bhs_play_count', $count + 1);

        // Aggregate-only rollup for the artist metrics dashboard (see
        // class-stats.php) — never a per-listener record, just a daily
        // bucketed count. Kept as its own call rather than folded into
        // the increment above so the metrics feature can be understood
        // (and, if ever wanted, disabled) as one clearly separate thing.
        if (class_exists('BHS_Stats')) BHS_Stats::record_play($id, $req);

        return new WP_REST_Response(['success' => true], 200);
    }
}
