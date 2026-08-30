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
        // After a lesson saves (content-bridge has written _bhc_steps by
        // priority 20), push each Bunny step's chapters up to Bunny so
        // they render on Bunny's OWN player scrub bar — the one custom
        // feature the cross-origin iframe can't paint itself.
        add_action('save_post_bh_lesson', [self::class, 'sync_lesson_chapters'], 30, 1);
        add_action('rest_after_insert_bh_lesson', function ($post) { self::sync_lesson_chapters($post->ID); }, 30);
    }

    /** @param int $lesson_id */
    public static function sync_lesson_chapters($lesson_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!class_exists('BHY_MediaToken') || !BHY_MediaToken::bunny_api_configured()) return;
        if (!class_exists('BHC_Steps')) return;
        foreach (BHC_Steps::get((int) $lesson_id) as $step) {
            if (($step['type'] ?? '') !== 'video' || ($step['source'] ?? '') !== 'bunny_stream') continue;
            $guid = (string) ($step['bunny_guid'] ?? '');
            if ($guid === '') continue;
            self::sync_chapters($guid, is_array($step['chapters'] ?? null) ? $step['chapters'] : []);
        }
    }

    /** Bump when the request shape below changes, so the first save on a
     *  new plugin version always re-pushes once even if the chapter set
     *  itself is byte-identical to what a previous (possibly broken)
     *  version already "synced". */
    const CHAPTER_SYNC_SCHEMA = 2;

    /**
     * Replace a Bunny video's chapter list with ours via the Update
     * Video endpoint (POST /library/{id}/videos/{guid}, `chapters`
     * array — verified against Bunny's OpenAPI: there is no `/chapters`
     * sub-route, and ChapterModel is {title (required, minLen 1), start,
     * end} in whole seconds). Ours is [{time, title}]: end = the next
     * chapter's start, last runs to the video's own length.
     *
     * Deduped by a stored hash so an unchanged set costs no API call —
     * EXCEPT the hash isn't stored while the video's length is still
     * unknown (0), because the last chapter's `end` is a guess until
     * Bunny finishes encoding; leaving the hash unstored lets the next
     * lesson save correct it.
     *
     * @param array<int, array{time:int, title:string}> $chapters
     * @return true|\WP_Error|null null = Bunny API not configured
     */
    public static function sync_chapters(string $guid, array $chapters) {
        if (!class_exists('BHY_MediaToken') || !BHY_MediaToken::bunny_api_configured()) return null;
        $guid = trim($guid);
        if (!preg_match('/^[a-f0-9-]{20,64}$/i', $guid)) return new WP_Error('bhc_bunny_bad_guid', 'Not a valid Bunny GUID.');

        $opt  = 'bhc_bunny_chapters_' . md5($guid);
        $hash = md5(self::CHAPTER_SYNC_SCHEMA . '|' . wp_json_encode($chapters));
        if (get_option($opt) === $hash) return true;

        $len = 0;
        $meta = self::api('GET', '/library/' . self::lib() . '/videos/' . rawurlencode($guid));
        if (is_array($meta)) $len = (int) ($meta['length'] ?? 0);

        $payload = [];
        $n = count($chapters);
        foreach (array_values($chapters) as $i => $c) {
            $start = max(0, (int) ($c['time'] ?? 0));
            $end   = ($i + 1 < $n) ? max(0, (int) ($chapters[$i + 1]['time'] ?? 0)) : ($len > $start ? $len : $start + 1);
            if ($end <= $start) $end = $start + 1;
            // Bunny rejects the whole request if any chapter title is
            // empty (min length 1), so never send a blank one.
            $title = trim((string) ($c['title'] ?? ''));
            if ($title === '') $title = 'Chapter ' . ($i + 1);
            $payload[] = ['title' => $title, 'start' => $start, 'end' => $end];
        }

        $res = self::api('POST', '/library/' . self::lib() . '/videos/' . rawurlencode($guid), ['chapters' => $payload]);
        if (is_wp_error($res)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Bunny chapter sync failed.', ['guid' => $guid, 'error' => $res->get_error_message(), 'payload' => $payload], 'BHC_Bunny');
            }
            return $res;
        }
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Bunny chapters synced.', ['guid' => $guid, 'count' => count($payload), 'video_length' => $len], 'BHC_Bunny');
        }
        // Only remember this as "done" once the end times are real.
        if ($len > 0 || $n <= 1) update_option($opt, $hash, false);
        return true;
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
        register_rest_route(self::NS, '/bunny/sync-chapters', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'rest_sync_chapters'],
            'permission_callback' => $can,
            'args'                => [
                'guid'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'chapters' => ['required' => true],
            ],
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

    /**
     * Force a chapter push for one video, straight from the editor —
     * so an author can confirm Bunny's own scrub bar picked them up
     * without waiting on a full lesson save. Accepts the block's live
     * chapter list ([{time,title}]) so it works before the lesson is
     * even saved. Bypasses the dedupe hash on purpose.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_sync_chapters(\WP_REST_Request $req) {
        $guid = (string) $req['guid'];
        $raw  = $req['chapters'];
        if (is_string($raw)) $raw = json_decode($raw, true);
        $chapters = [];
        foreach ((array) $raw as $c) {
            if (!is_array($c)) continue;
            $chapters[] = ['time' => max(0, (int) ($c['time'] ?? 0)), 'title' => (string) ($c['title'] ?? '')];
        }
        usort($chapters, static fn($a, $b) => $a['time'] <=> $b['time']);

        delete_option('bhc_bunny_chapters_' . md5(trim($guid))); // force, don't trust the dedupe cache
        $res = self::sync_chapters($guid, $chapters);
        if (is_wp_error($res)) return $res;
        if ($res === null) return new WP_Error('bhc_bunny_no_api', 'Set the Bunny API key in Media &amp; CDN Setup first.', ['status' => 400]);
        return new WP_REST_Response(['synced' => count($chapters)], 200);
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
