<?php
if (!defined('ABSPATH')) exit;

/**
 * TikTok Content Posting API — fourth platform, and the smallest of
 * the four: TikTok has no broad public analytics API the way
 * YouTube/Twitch do (per the scoping research this plugin started
 * from), so pull_stats() here is limited to the creator's own basic
 * profile counts via /v2/user/info/, not the deeper engagement metrics
 * the other platforms expose. cross_post() is the real feature.
 *
 * Two real, honestly-documented gates on this integration, neither
 * fixable from inside this codebase:
 *
 * 1. TikTok requires an HTTPS redirect URI on a domain it has
 *    verified for the app — a `localhost` dev install genuinely cannot
 *    complete this OAuth flow; the code path is correct and will work
 *    once deployed to a real HTTPS domain registered with the app.
 * 2. Before TikTok's own app review completes, this app runs in
 *    "unaudited" mode: published posts are forced to SELF_ONLY privacy
 *    and only up to 5 TikTok accounts explicitly added as sandbox
 *    testers in the developer portal can authorize at all. Both
 *    defaults below reflect that constraint rather than fighting it.
 *
 * Uses the FILE_UPLOAD source (this plugin uploads the raw bytes
 * directly to TikTok's returned upload_url) rather than PULL_FROM_URL,
 * which requires separate TikTok domain-ownership verification of
 * wherever the file is hosted — FILE_UPLOAD has no such prerequisite.
 * v1 sends the whole file as a single chunk (correct and sufficient
 * for typical short-form video sizes; TikTok's own chunking is only
 * required past a much larger size threshold).
 */
class BHSO_TikTok implements BH_SocialPlatform {
    const STATS_CRON_HOOK = 'bhso_tiktok_pull_stats_cron';
    const CROSS_POST_JOB = 'bhso_tiktok_cross_post';
    const STATS_JOB = 'bhso_tiktok_pull_stats';

    const AUTH_URL  = 'https://www.tiktok.com/v2/auth/authorize/';
    const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
    const API_BASE  = 'https://open.tiktokapis.com/v2';

    const SCOPE = 'user.info.basic,user.info.stats,video.publish';

    const PUBLISH_POLL_ATTEMPTS = 10;
    const PUBLISH_POLL_DELAY_SECONDS = 3;

    public static function init(): void {
        add_action('init', [self::class, 'maybe_schedule_stats_cron']);
        add_action(self::STATS_CRON_HOOK, function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::enqueue(self::STATS_JOB);
            } else {
                (new self())->pull_stats();
            }
        });

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register(self::CROSS_POST_JOB, function ($args) {
                $result = (new self())->cross_post($args);
                if (is_wp_error($result)) throw new Exception($result->get_error_message());
            });
            OUS_Jobs::register(self::STATS_JOB, function () {
                $result = (new self())->pull_stats();
                if (is_wp_error($result)) throw new Exception($result->get_error_message());
            });
        }
    }

    public static function maybe_schedule_stats_cron(): void {
        if (!wp_next_scheduled(self::STATS_CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::STATS_CRON_HOOK);
        }
    }

    /* ---------------- settings / tokens ---------------- */

    /** @return array<string, mixed> */
    public static function settings(): array {
        return get_option('bhso_tiktok_settings', [
            'client_key' => '', 'client_secret' => '',
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0,
            'display_name' => '',
        ]);
    }

    public static function save_credentials(string $client_key, string $client_secret): void {
        $existing = self::settings();
        update_option('bhso_tiktok_settings', array_merge($existing, [
            'client_key'    => sanitize_text_field($client_key),
            'client_secret' => ($client_secret === '') ? $existing['client_secret'] : sanitize_text_field($client_secret),
        ]));
    }

    /** @param int|string $expires_in */
    private static function save_tokens(string $access_token, string $refresh_token, $expires_in, ?string $display_name = null): void {
        $existing = self::settings();
        $update = ['access_token' => $access_token, 'expires_at' => time() + (int) $expires_in];
        if (!empty($refresh_token)) $update['refresh_token'] = $refresh_token;
        if ($display_name !== null) $update['display_name'] = $display_name;
        update_option('bhso_tiktok_settings', array_merge($existing, $update));
    }

    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['client_key']) && !empty($s['client_secret']);
    }

    public function is_connected(): bool {
        $s = self::settings();
        return !empty($s['refresh_token']);
    }

    public function get_status(): string {
        if (!$this->is_configured()) return 'not_configured';
        if (!$this->is_connected()) return 'not_connected';
        return 'connected';
    }

    public function disconnect(): bool {
        $s = self::settings();
        update_option('bhso_tiktok_settings', array_merge($s, [
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0, 'display_name' => '',
        ]));
        return true;
    }

    /* ---------------- OAuth flow ---------------- */

    private static function redirect_uri(): string {
        return admin_url('admin-post.php?action=bhso_tiktok_oauth_callback');
    }

    /** @return string|\WP_Error */
    public static function authorize_url() {
        $s = self::settings();
        if (empty($s['client_key'])) return new WP_Error('not_configured', 'Enter a Client Key and Client Secret first.');

        $redirect = self::redirect_uri();
        if (strpos($redirect, 'https://') !== 0) {
            return new WP_Error('https_required', 'TikTok requires an HTTPS redirect URI on a domain registered with your app — this only works once the site is live on HTTPS, not on a local/http dev install.');
        }

        $state = wp_generate_password(32, false);
        set_transient('bhso_tiktok_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        return self::AUTH_URL . '?' . http_build_query([
            'client_key'    => $s['client_key'],
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    public static function handle_oauth_callback(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $expected = get_transient('bhso_tiktok_oauth_state_' . get_current_user_id());
        delete_transient('bhso_tiktok_oauth_state_' . get_current_user_id());

        if (empty($state) || !hash_equals((string) $expected, $state)) {
            self::redirect_with_notice('TikTok connect failed: state mismatch — the link may have expired. Try connecting again.');
            return;
        }

        if (!empty($_GET['error'])) {
            self::redirect_with_notice('TikTok connect cancelled: ' . sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])));
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            self::redirect_with_notice('TikTok connect failed: no authorization code returned.');
            return;
        }

        $s = self::settings();
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'client_key'    => $s['client_key'],
                'client_secret' => $s['client_secret'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => self::redirect_uri(),
            ],
        ]);

        if (is_wp_error($response)) {
            self::redirect_with_notice('TikTok connect failed: ' . $response->get_error_message());
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            $msg = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : 'no access_token in response';
            self::redirect_with_notice('TikTok connect failed: ' . $msg);
            return;
        }

        self::save_tokens($data['access_token'], $data['refresh_token'] ?? '', $data['expires_in'] ?? 86400);

        $instance = new self();
        $profile = $instance->api_get('/user/info/', ['fields' => 'display_name']);
        if (!is_wp_error($profile) && !empty($profile['data']['user']['display_name'])) {
            self::save_tokens($data['access_token'], '', $data['expires_in'] ?? 86400, $profile['data']['user']['display_name']);
        }

        self::redirect_with_notice('TikTok connected successfully.');
    }

    private static function redirect_with_notice(string $msg): void {
        if (class_exists('OUS_Toast')) {
            $is_failure = stripos($msg, 'fail') !== false || stripos($msg, 'cancel') !== false;
            OUS_Toast::queue($msg, $is_failure ? 'error' : 'success');
        }
        wp_safe_redirect(add_query_arg(['page' => 'bh-social'], admin_url('admin.php')));
        exit;
    }

    /** @return string|\WP_Error */
    private function ensure_fresh_token() {
        $s = self::settings();
        if (empty($s['refresh_token'])) return new WP_Error('not_connected', 'TikTok isn\'t connected yet.');
        if (!empty($s['access_token']) && $s['expires_at'] > time() + 60) return $s['access_token'];

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'client_key'    => $s['client_key'],
                'client_secret' => $s['client_secret'],
                'refresh_token' => $s['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ]);
        if (is_wp_error($response)) return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            $msg = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : 'token refresh failed';
            return new WP_Error('refresh_failed', $msg);
        }

        self::save_tokens($data['access_token'], $data['refresh_token'] ?? '', $data['expires_in'] ?? 86400);
        return $data['access_token'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|\WP_Error
     */
    private function api_get(string $path, array $query = []) {
        $token = $this->ensure_fresh_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_get(self::API_BASE . $path . '?' . http_build_query($query), [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        return $this->parse_response($response);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\WP_Error
     */
    private function api_post_json(string $path, array $body) {
        $token = $this->ensure_fresh_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_post(self::API_BASE . $path, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=UTF-8'],
            'body' => wp_json_encode($body),
        ]);
        return $this->parse_response($response);
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, mixed>|\WP_Error
     */
    private function parse_response($response) {
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || (!empty($data['error']['code']) && $data['error']['code'] !== 'ok')) {
            $message = is_array($data) && !empty($data['error']['message']) ? $data['error']['message'] : ('TikTok API returned HTTP ' . $code);
            return new WP_Error('tiktok_api_error', $message);
        }
        return is_array($data) ? $data : [];
    }

    /* ---------------- cross-post: video publish ---------------- */

    /**
     * $args: ['attachment_id' => int, 'title' => string, 'privacy_level' => string]
     * privacy_level defaults to SELF_ONLY — the only option unaudited
     * apps are permitted to use; pass a wider value only once real app
     * review has actually completed for this app.
     */
    /** @param array<string, mixed> $args */
    public function cross_post($args) {
        $attachment_id = (int) ($args['attachment_id'] ?? 0);
        $file_path = $attachment_id ? get_attached_file($attachment_id) : false;
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('no_file', 'No such attachment, or the file is missing on disk (attachment_id: ' . $attachment_id . ').');
        }

        $size = filesize($file_path);
        $privacy = $args['privacy_level'] ?? 'SELF_ONLY';

        $init = $this->api_post_json('/post/publish/video/init/', [
            'post_info' => [
                'title'         => substr((string) ($args['title'] ?? get_the_title($attachment_id) ?: ''), 0, 150),
                'privacy_level' => $privacy,
            ],
            'source_info' => [
                'source'           => 'FILE_UPLOAD',
                'video_size'       => $size,
                'chunk_size'       => $size,
                'total_chunk_count'=> 1,
            ],
        ]);
        if (is_wp_error($init)) return $init;

        $publish_id = $init['data']['publish_id'] ?? '';
        $upload_url = $init['data']['upload_url'] ?? '';
        if (!$publish_id || !$upload_url) return new WP_Error('no_upload_url', 'TikTok accepted the init request but returned no upload_url.');

        $mime = get_post_mime_type($attachment_id) ?: 'video/mp4';
        $upload = wp_remote_request($upload_url, [
            'method'  => 'PUT',
            'timeout' => 300,
            'headers' => [
                'Content-Type'  => $mime,
                'Content-Range' => 'bytes 0-' . ($size - 1) . '/' . $size,
            ],
            'body' => file_get_contents($file_path),
        ]);
        if (is_wp_error($upload)) return $upload;
        $upload_code = wp_remote_retrieve_response_code($upload);
        if ($upload_code < 200 || $upload_code >= 300) {
            return new WP_Error('upload_failed', 'Uploading the video bytes to TikTok returned HTTP ' . $upload_code . '.');
        }

        $status = $this->poll_publish_status($publish_id);
        if (is_wp_error($status)) return $status;

        update_post_meta($attachment_id, '_bhso_tiktok_publish_id', $publish_id);
        return true;
    }

    /** @return true|\WP_Error */
    private function poll_publish_status(string $publish_id) {
        for ($i = 0; $i < self::PUBLISH_POLL_ATTEMPTS; $i++) {
            $result = $this->api_post_json('/post/publish/status/fetch/', ['publish_id' => $publish_id]);
            if (is_wp_error($result)) return $result;

            $status = $result['data']['status'] ?? '';
            if ($status === 'PUBLISH_COMPLETE') return true;
            if ($status === 'FAILED') {
                $reason = $result['data']['fail_reason'] ?? 'unknown reason';
                return new WP_Error('publish_failed', 'TikTok reported the publish failed: ' . $reason);
            }

            sleep(self::PUBLISH_POLL_DELAY_SECONDS);
        }
        return new WP_Error('publish_timeout', 'TikTok is still processing this video after ' . (self::PUBLISH_POLL_ATTEMPTS * self::PUBLISH_POLL_DELAY_SECONDS) . 's — check status manually or try again shortly.');
    }

    /** @return true|\WP_Error|int|false */
    public static function enqueue_cross_post(int $attachment_id, string $title = '', string $privacy_level = 'SELF_ONLY') {
        $args = ['attachment_id' => (int) $attachment_id, 'title' => $title, 'privacy_level' => $privacy_level];
        if (class_exists('OUS_Jobs')) {
            return OUS_Jobs::enqueue(self::CROSS_POST_JOB, $args);
        }
        return (new self())->cross_post($args);
    }

    /* ---------------- stats pull (limited — no broad analytics API) ---------------- */

    public function pull_stats() {
        $profile = $this->api_get('/user/info/', ['fields' => 'follower_count,likes_count,video_count']);
        if (is_wp_error($profile)) return $profile;

        $user = $profile['data']['user'] ?? [];
        if (!$user) return new WP_Error('no_profile', 'TikTok returned no user profile data.');

        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $now = current_time('mysql', true);
        foreach (['follower_count' => 'followers', 'likes_count' => 'likes', 'video_count' => 'videos'] as $api_key => $metric_key) {
            if (!isset($user[$api_key])) continue;
            $wpdb->insert($table, ['platform' => 'tiktok', 'metric_key' => $metric_key, 'metric_value' => (int) $user[$api_key], 'recorded_at' => $now]);
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function latest_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t1.metric_key, t1.metric_value, t1.recorded_at FROM $table t1
             INNER JOIN (SELECT metric_key, MAX(recorded_at) AS max_recorded FROM $table WHERE platform = %s GROUP BY metric_key) t2
             ON t1.metric_key = t2.metric_key AND t1.recorded_at = t2.max_recorded WHERE t1.platform = %s",
            'tiktok', 'tiktok'
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) $out[$r['metric_key']] = ['value' => (int) $r['metric_value'], 'recorded_at' => $r['recorded_at']];
        return $out;
    }
}
