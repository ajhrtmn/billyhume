<?php
if (!defined('ABSPATH')) exit;

/**
 * YouTube Data API v3 — chosen as the first platform because, unlike
 * Meta/TikTok, using it against your OWN channel needs no app-review
 * gate: create an OAuth client in Google Cloud Console, connect once,
 * done. (Confirmed against Google's own quota docs as of the scoping
 * pass this plugin started from: a default 10,000-unit daily quota,
 * with videos.insert now on its own separate ~100-call daily bucket —
 * re-verify current numbers against Google's live quota calculator
 * before assuming these are still exact.)
 *
 * cross_post() uses the simple `uploadType=multipart` endpoint (one
 * request, metadata + file body together) rather than the resumable
 * upload protocol — correct and sufficient for typical track/video
 * file sizes, but it holds the whole file in memory for that one
 * request and isn't resumable if the connection drops mid-upload.
 * Switching to chunked resumable uploads is a real future iteration
 * for large files, not implemented here.
 */
class BHSO_YouTube implements BH_SocialPlatform {
    const STATS_CRON_HOOK = 'bhso_youtube_pull_stats_cron';
    const CROSS_POST_JOB = 'bhso_youtube_cross_post';
    const STATS_JOB = 'bhso_youtube_pull_stats';

    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
    const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    const SCOPE = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public static function init() {
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

    public static function maybe_schedule_stats_cron() {
        if (!wp_next_scheduled(self::STATS_CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::STATS_CRON_HOOK);
        }
    }

    /* ---------------- settings / tokens (wp_options, same shape as BHL_FlyProvisioner) ---------------- */

    public static function settings() {
        return get_option('bhso_youtube_settings', [
            'client_id' => '', 'client_secret' => '',
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0,
            'channel_title' => '',
        ]);
    }

    public static function save_credentials($client_id, $client_secret) {
        $existing = self::settings();
        update_option('bhso_youtube_settings', array_merge($existing, [
            'client_id'     => sanitize_text_field($client_id),
            // Blank field means "keep what's already saved" — same
            // convention BHL_FlyProvisioner::save_settings() uses.
            'client_secret' => ($client_secret === '') ? $existing['client_secret'] : sanitize_text_field($client_secret),
        ]));
    }

    private static function save_tokens($access_token, $refresh_token, $expires_in, $channel_title = null) {
        $existing = self::settings();
        $update = [
            'access_token' => $access_token,
            'expires_at'   => time() + (int) $expires_in,
        ];
        // Google only returns a refresh_token on the FIRST consent —
        // re-connecting without revoking access first won't send one
        // again, so an empty value here must not overwrite the one
        // already stored.
        if (!empty($refresh_token)) $update['refresh_token'] = $refresh_token;
        if ($channel_title !== null) $update['channel_title'] = $channel_title;
        update_option('bhso_youtube_settings', array_merge($existing, $update));
    }

    public function is_configured() {
        $s = self::settings();
        return !empty($s['client_id']) && !empty($s['client_secret']);
    }

    public function is_connected() {
        $s = self::settings();
        return !empty($s['refresh_token']);
    }

    public function get_status() {
        if (!$this->is_configured()) return 'not_configured';
        if (!$this->is_connected()) return 'not_connected';
        return 'connected';
    }

    public function disconnect() {
        $s = self::settings();
        update_option('bhso_youtube_settings', array_merge($s, [
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0, 'channel_title' => '',
        ]));
        return true;
    }

    /* ---------------- OAuth flow ---------------- */

    private static function redirect_uri() {
        return admin_url('admin-post.php?action=bhso_youtube_oauth_callback');
    }

    // The redirect flow has no form to attach wp_nonce_field() to —
    // Google itself round-trips the "state" param, so a transient
    // keyed to the current admin user stands in for a nonce, checked
    // on the way back in handle_oauth_callback().
    public static function authorize_url() {
        $s = self::settings();
        if (empty($s['client_id'])) return new WP_Error('not_configured', 'Enter a Client ID and Client Secret first.');

        $state = wp_generate_password(32, false);
        set_transient('bhso_youtube_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $s['client_id'],
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent', // forces a fresh refresh_token even on re-connect
            'state'         => $state,
        ]);
    }

    public static function handle_oauth_callback() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $expected = get_transient('bhso_youtube_oauth_state_' . get_current_user_id());
        delete_transient('bhso_youtube_oauth_state_' . get_current_user_id());

        if (empty($state) || !hash_equals((string) $expected, $state)) {
            self::redirect_with_notice('YouTube connect failed: state mismatch — the link may have expired. Try connecting again.');
            return;
        }

        if (!empty($_GET['error'])) {
            self::redirect_with_notice('YouTube connect cancelled: ' . sanitize_text_field(wp_unslash($_GET['error'])));
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            self::redirect_with_notice('YouTube connect failed: no authorization code returned.');
            return;
        }

        $s = self::settings();
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'code'          => $code,
                'client_id'     => $s['client_id'],
                'client_secret' => $s['client_secret'],
                'redirect_uri'  => self::redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            self::redirect_with_notice('YouTube connect failed: ' . $response->get_error_message());
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            $msg = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : 'no access_token in response';
            self::redirect_with_notice('YouTube connect failed: ' . $msg);
            return;
        }

        self::save_tokens($data['access_token'], $data['refresh_token'] ?? '', $data['expires_in'] ?? 3600);

        // Fetch the channel title now, while we have a guaranteed-fresh
        // token, so the settings screen can show "Connected as X"
        // instead of just "Connected".
        $instance = new self();
        $channel = $instance->api_get(self::CHANNELS_URL . '?part=snippet&mine=true');
        if (!is_wp_error($channel) && !empty($channel['items'][0]['snippet']['title'])) {
            self::save_tokens($data['access_token'], '', $data['expires_in'] ?? 3600, $channel['items'][0]['snippet']['title']);
        }

        self::redirect_with_notice('YouTube connected successfully.');
    }

    private static function redirect_with_notice($msg) {
        if (class_exists('OUS_Toast')) {
            $is_failure = stripos($msg, 'fail') !== false || stripos($msg, 'cancel') !== false;
            OUS_Toast::queue($msg, $is_failure ? 'error' : 'success');
        }
        wp_safe_redirect(add_query_arg(['page' => 'bh-social'], admin_url('admin.php')));
        exit;
    }

    // Refreshes the access token if it's expired (or about to be),
    // using the stored refresh_token — called before every API request
    // rather than trusting a caller to check first, same
    // "self-healing" posture BHL_FlyProvisioner's request() takes.
    private function ensure_fresh_token() {
        $s = self::settings();
        if (empty($s['refresh_token'])) return new WP_Error('not_connected', 'YouTube isn\'t connected yet.');
        if (!empty($s['access_token']) && $s['expires_at'] > time() + 60) return $s['access_token'];

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'client_id'     => $s['client_id'],
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

        self::save_tokens($data['access_token'], '', $data['expires_in'] ?? 3600);
        return $data['access_token'];
    }

    private function api_get($url) {
        $token = $this->ensure_fresh_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['error']['message']) ? $data['error']['message'] : ('YouTube API returned HTTP ' . $code);
            return new WP_Error('youtube_api_error', $message);
        }
        return is_array($data) ? $data : [];
    }

    /* ---------------- cross-post ---------------- */

    /**
     * $args: ['attachment_id' => int, 'title' => string, 'description' => string, 'privacy' => 'private'|'unlisted'|'public']
     * Attachment must be a real video file already in the media library
     * (bh-video's own upload metabox, or any other plugin's video
     * attachment) — this class never fetches remote files.
     */
    public function cross_post($args) {
        $token = $this->ensure_fresh_token();
        if (is_wp_error($token)) return $token;

        $attachment_id = (int) ($args['attachment_id'] ?? 0);
        $file_path = $attachment_id ? get_attached_file($attachment_id) : false;
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('no_file', 'No such attachment, or the file is missing on disk (attachment_id: ' . $attachment_id . ').');
        }

        $metadata = [
            'snippet' => [
                'title'       => substr((string) ($args['title'] ?? get_the_title($attachment_id) ?: 'Untitled'), 0, 100),
                'description' => (string) ($args['description'] ?? ''),
            ],
            'status' => [
                'privacyStatus' => in_array($args['privacy'] ?? 'unlisted', ['private', 'unlisted', 'public'], true) ? $args['privacy'] : 'unlisted',
            ],
        ];

        // A hand-built multipart/related body per YouTube's own
        // documented uploadType=multipart shape: one JSON part (the
        // video resource metadata), one binary part (the file), joined
        // by a boundary — there's no wp_remote_* helper for multipart
        // bodies, so this is built directly.
        $boundary = 'bhso_' . wp_generate_password(16, false);
        $mime = get_post_mime_type($attachment_id) ?: 'video/*';

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode($metadata) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $mime\r\n\r\n";
        $body .= file_get_contents($file_path) . "\r\n";
        $body .= "--$boundary--";

        $response = wp_remote_post(self::UPLOAD_URL . '?uploadType=multipart&part=snippet,status', [
            'timeout' => 300, // real video files take a while
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => "multipart/related; boundary=$boundary",
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['error']['message']) ? $data['error']['message'] : ('YouTube upload returned HTTP ' . $code);
            return new WP_Error('youtube_upload_error', $message);
        }

        if (!empty($data['id'])) {
            update_post_meta($attachment_id, '_bhso_youtube_video_id', $data['id']);
        }

        return true;
    }

    public static function enqueue_cross_post($attachment_id, $title = '', $description = '', $privacy = 'unlisted') {
        $args = ['attachment_id' => (int) $attachment_id, 'title' => $title, 'description' => $description, 'privacy' => $privacy];
        if (class_exists('OUS_Jobs')) {
            return OUS_Jobs::enqueue(self::CROSS_POST_JOB, $args);
        }
        return (new self())->cross_post($args);
    }

    /* ---------------- stats pull ---------------- */

    public function pull_stats() {
        $data = $this->api_get(self::CHANNELS_URL . '?part=statistics&mine=true');
        if (is_wp_error($data)) return $data;

        $stats = $data['items'][0]['statistics'] ?? null;
        if (!$stats) return new WP_Error('no_channel', 'No YouTube channel found for the connected account.');

        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $now = current_time('mysql', true);
        foreach (['subscriberCount' => 'subscribers', 'viewCount' => 'views', 'videoCount' => 'videos'] as $api_key => $metric_key) {
            if (!isset($stats[$api_key])) continue;
            $wpdb->insert($table, [
                'platform'     => 'youtube',
                'metric_key'   => $metric_key,
                'metric_value' => (int) $stats[$api_key],
                'recorded_at'  => $now,
            ]);
        }
        return true;
    }

    public static function latest_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t1.metric_key, t1.metric_value, t1.recorded_at FROM $table t1
             INNER JOIN (SELECT metric_key, MAX(recorded_at) AS max_recorded FROM $table WHERE platform = %s GROUP BY metric_key) t2
             ON t1.metric_key = t2.metric_key AND t1.recorded_at = t2.max_recorded WHERE t1.platform = %s",
            'youtube', 'youtube'
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) $out[$r['metric_key']] = ['value' => (int) $r['metric_value'], 'recorded_at' => $r['recorded_at']];
        return $out;
    }
}
