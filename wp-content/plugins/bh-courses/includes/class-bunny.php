<?php
if (!defined('ABSPATH')) exit;

/**
 * The lesson editor's bridge to a Bunny Stream library, so an author
 * never leaves the editor: browse existing videos, create + resumably
 * upload a new one (tus-js-client hits Bunny directly with a signature
 * this endpoint mints — the API key never reaches the browser), and get
 * a signed preview URL for scrubbing chapters.
 *
 * All of this is optional enhancement: the bunny_stream video step works
 * with a hand-pasted GUID and BHY_MediaToken alone. These routes only do
 * anything once the Bunny *API Key* is also set (Media & CDN Setup),
 * hence BHY_MediaToken::bunny_api_configured() guards every one.
 *
 * Bunny video-management API: https://docs.bunny.net/reference/video_getvideolist
 */
class BHC_Bunny {

    const NS = 'bhc/v1';
    const API_BASE = 'https://video.bunnycdn.com';

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $can = static fn() => current_user_can('edit_posts') && class_exists('BHY_MediaToken') && BHY_MediaToken::bunny_api_configured();

        register_rest_route(self::NS, '/bunny/videos', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_videos'],
            'permission_callback' => $can,
            'args'                => [
                'page'   => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'search' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
        register_rest_route(self::NS, '/bunny/video', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'create_video'],
            'permission_callback' => $can,
            'args'                => ['title' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field']],
        ]);
        register_rest_route(self::NS, '/bunny/upload-signature', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'upload_signature'],
            'permission_callback' => $can,
            'args'                => ['guid' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field']],
        ]);
        register_rest_route(self::NS, '/bunny/embed', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'embed_url'],
            // Preview only needs playback signing, not the API key.
            'permission_callback' => static fn() => current_user_can('edit_posts') && class_exists('BHY_MediaToken') && BHY_MediaToken::bunny_configured(),
            'args'                => ['guid' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field']],
        ]);
    }

    /* ---------------- handlers ---------------- */

    /** @return \WP_REST_Response|\WP_Error */
    public static function list_videos(\WP_REST_Request $req) {
        $args = ['page' => max(1, (int) $req['page']), 'itemsPerPage' => 40, 'orderBy' => 'date'];
        if ($req['search'] !== '') $args['search'] = $req['search'];
        $body = self::api('GET', '/library/' . self::lib() . '/videos?' . http_build_query($args));
        if (is_wp_error($body)) return $body;

        $items = [];
        foreach (($body['items'] ?? []) as $v) {
            $items[] = [
                'guid'      => (string) ($v['guid'] ?? ''),
                'title'     => (string) ($v['title'] ?? ''),
                'status'    => (int) ($v['status'] ?? 0),   // 4 = finished/playable
                'length'    => (int) ($v['length'] ?? 0),
                'thumbnail' => self::thumb_url((string) ($v['guid'] ?? ''), (string) ($v['thumbnailFileName'] ?? '')),
            ];
        }
        return new WP_REST_Response([
            'items'       => $items,
            'page'        => (int) ($body['currentPage'] ?? 1),
            'totalItems'  => (int) ($body['totalItems'] ?? count($items)),
        ], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function create_video(\WP_REST_Request $req) {
        $body = self::api('POST', '/library/' . self::lib() . '/videos', ['title' => (string) $req['title']]);
        if (is_wp_error($body)) return $body;
        $guid = (string) ($body['guid'] ?? '');
        if ($guid === '') return new WP_Error('bhc_bunny_no_guid', 'Bunny did not return a video GUID.', ['status' => 502]);
        return new WP_REST_Response(['guid' => $guid], 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function upload_signature(\WP_REST_Request $req) {
        $sig = BHY_MediaToken::bunny_upload_signature((string) $req['guid']);
        if (!$sig) return new WP_Error('bhc_bunny_bad_guid', 'Not a valid Bunny video GUID, or Bunny API is not configured.', ['status' => 400]);
        return new WP_REST_Response($sig, 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function embed_url(\WP_REST_Request $req) {
        $url = BHY_MediaToken::sign_bunny((string) $req['guid']);
        if (!$url) return new WP_Error('bhc_bunny_bad_guid', 'Not a valid Bunny video GUID.', ['status' => 400]);
        return new WP_REST_Response(['url' => $url], 200);
    }

    /* ---------------- helpers ---------------- */

    private static function lib(): string {
        return BHY_MediaToken::bunny_library_id();
    }

    /**
     * @param array<string,mixed>|null $json_body
     * @return array<string,mixed>|\WP_Error
     */
    private static function api(string $method, string $path, ?array $json_body = null) {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'AccessKey' => BHY_MediaToken::bunny_api_key(),
                'Accept'    => 'application/json',
            ],
        ];
        if ($json_body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($json_body);
        }
        $res = wp_remote_request(self::API_BASE . $path, $args);
        if (is_wp_error($res)) return new WP_Error('bhc_bunny_http', $res->get_error_message(), ['status' => 502]);

        $code = wp_remote_retrieve_response_code($res);
        $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? ($decoded['Message'] ?? $decoded['message'] ?? 'Bunny API error') : 'Bunny API error';
            return new WP_Error('bhc_bunny_api', $msg . " (HTTP $code)", ['status' => 502]);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function thumb_url(string $guid, string $file): string {
        if ($guid === '' || $file === '') return '';
        $host = self::library_cdn_hostname();
        if ($host === '') return '';
        return 'https://' . $host . '/' . rawurlencode($guid) . '/' . rawurlencode($file);
    }

    /** The library's own CDN hostname (e.g. vz-1a2b3c.b-cdn.net), needed
     *  to build thumbnail URLs. One API call, cached 12h. */
    private static function library_cdn_hostname(): string {
        $key = 'bhc_bunny_lib_host_' . self::lib();
        $cached = get_transient($key);
        if (is_string($cached)) return $cached;

        $lib = self::api('GET', '/library/' . self::lib());
        $host = '';
        if (is_array($lib)) {
            $pull = (string) ($lib['PullZoneUrl'] ?? '');
            $host = $pull !== '' ? (string) parse_url($pull, PHP_URL_HOST) : (string) ($lib['CdnHostname'] ?? '');
        }
        set_transient($key, $host, 12 * HOUR_IN_SECONDS);
        return $host;
    }
}
