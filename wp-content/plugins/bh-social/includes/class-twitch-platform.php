<?php
if (!defined('ABSPATH')) exit;

/**
 * Twitch Helix API — second platform, reusing BHSO_YouTube's exact
 * OAuth shape (id.twitch.tv/oauth2/{authorize,token,validate}) but a
 * genuinely different use-case shape underneath: Twitch has no
 * generic "post content" endpoint the way YouTube's videos.insert is,
 * so cross_post() here means sending a real chat announcement to the
 * artist's own channel (the closest real analog to "push an update
 * out"), not uploading media. Stats are live status + follower count.
 *
 * Confirmed against Twitch's own documented Helix API as of the scoping
 * pass this plugin started from — re-verify scope names/endpoints
 * against dev.twitch.tv/docs/api before relying on this in production,
 * since Twitch has changed follower-related scopes before (the
 * followers endpoint now requires moderator:read:followers, not the
 * older public follower count it once exposed).
 */
class BHSO_Twitch implements BH_SocialPlatform {
    const STATS_CRON_HOOK = 'bhso_twitch_pull_stats_cron';
    const CROSS_POST_JOB = 'bhso_twitch_cross_post';
    const STATS_JOB = 'bhso_twitch_pull_stats';

    const AUTH_URL     = 'https://id.twitch.tv/oauth2/authorize';
    const TOKEN_URL    = 'https://id.twitch.tv/oauth2/token';
    const VALIDATE_URL = 'https://id.twitch.tv/oauth2/validate';
    const HELIX_BASE   = 'https://api.twitch.tv/helix';

    // moderator:read:followers and moderator:manage:announcements both
    // work for a broadcaster acting as their own moderator (Twitch
    // treats the channel owner as implicitly having moderator rights
    // over their own channel for these scopes).
    const SCOPE = 'channel:read:stream_key moderator:read:followers moderator:manage:announcements';

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
        return get_option('bhso_twitch_settings', [
            'client_id' => '', 'client_secret' => '',
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0,
            'broadcaster_id' => '', 'broadcaster_login' => '',
        ]);
    }

    public static function save_credentials(string $client_id, string $client_secret): void {
        $existing = self::settings();
        update_option('bhso_twitch_settings', array_merge($existing, [
            'client_id'     => sanitize_text_field($client_id),
            'client_secret' => ($client_secret === '') ? $existing['client_secret'] : sanitize_text_field($client_secret),
        ]));
    }

    /** @param int|string $expires_in seconds until expiry, as returned by the token endpoint (int or numeric string depending on response) */
    private static function save_tokens(string $access_token, string $refresh_token, $expires_in, ?string $broadcaster_id = null, ?string $broadcaster_login = null): void {
        $existing = self::settings();
        $update = ['access_token' => $access_token, 'expires_at' => time() + (int) $expires_in];
        if (!empty($refresh_token)) $update['refresh_token'] = $refresh_token;
        if ($broadcaster_id !== null) $update['broadcaster_id'] = $broadcaster_id;
        if ($broadcaster_login !== null) $update['broadcaster_login'] = $broadcaster_login;
        update_option('bhso_twitch_settings', array_merge($existing, $update));
    }

    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['client_id']) && !empty($s['client_secret']);
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
        update_option('bhso_twitch_settings', array_merge($s, [
            'access_token' => '', 'refresh_token' => '', 'expires_at' => 0,
            'broadcaster_id' => '', 'broadcaster_login' => '',
        ]));
        return true;
    }

    /* ---------------- OAuth flow ---------------- */

    private static function redirect_uri(): string {
        return admin_url('admin-post.php?action=bhso_twitch_oauth_callback');
    }

    /** @return string|\WP_Error */
    public static function authorize_url() {
        $s = self::settings();
        if (empty($s['client_id'])) return new WP_Error('not_configured', 'Enter a Client ID and Client Secret first.');

        $state = wp_generate_password(32, false);
        set_transient('bhso_twitch_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $s['client_id'],
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    public static function handle_oauth_callback(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $expected = get_transient('bhso_twitch_oauth_state_' . get_current_user_id());
        delete_transient('bhso_twitch_oauth_state_' . get_current_user_id());

        if (empty($state) || !hash_equals((string) $expected, $state)) {
            self::redirect_with_notice('Twitch connect failed: state mismatch — the link may have expired. Try connecting again.');
            return;
        }

        if (!empty($_GET['error'])) {
            self::redirect_with_notice('Twitch connect cancelled: ' . sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])));
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            self::redirect_with_notice('Twitch connect failed: no authorization code returned.');
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
            self::redirect_with_notice('Twitch connect failed: ' . $response->get_error_message());
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'no access_token in response';
            self::redirect_with_notice('Twitch connect failed: ' . $msg);
            return;
        }

        self::save_tokens($data['access_token'], $data['refresh_token'] ?? '', $data['expires_in'] ?? 3600);

        // /oauth2/validate is the documented way to resolve the
        // connected user's own id/login from a fresh token — cheaper
        // than a separate Helix /users call and doesn't need Client-Id.
        $instance = new self();
        $validated = $instance->validate_token($data['access_token']);
        if (!is_wp_error($validated) && !empty($validated['user_id'])) {
            self::save_tokens($data['access_token'], '', $data['expires_in'] ?? 3600, $validated['user_id'], $validated['login'] ?? '');
        }

        self::redirect_with_notice('Twitch connected successfully.');
    }

    private static function redirect_with_notice(string $msg): void {
        if (class_exists('OUS_Toast')) {
            $is_failure = stripos($msg, 'fail') !== false || stripos($msg, 'cancel') !== false;
            OUS_Toast::queue($msg, $is_failure ? 'error' : 'success');
        }
        wp_safe_redirect(add_query_arg(['page' => 'bh-social'], admin_url('admin.php')));
        exit;
    }

    /** @return array<string, mixed>|\WP_Error */
    private function validate_token(string $token) {
        $response = wp_remote_get(self::VALIDATE_URL, [
            'timeout' => 15,
            'headers' => ['Authorization' => 'OAuth ' . $token],
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) return new WP_Error('twitch_validate_error', 'Token validation returned HTTP ' . $code);
        return is_array($data) ? $data : [];
    }

    /** @return string|\WP_Error */
    private function ensure_fresh_token() {
        $s = self::settings();
        if (empty($s['refresh_token'])) return new WP_Error('not_connected', 'Twitch isn\'t connected yet.');
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
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'token refresh failed';
            return new WP_Error('refresh_failed', $msg);
        }

        self::save_tokens($data['access_token'], $data['refresh_token'] ?? '', $data['expires_in'] ?? 3600);
        return $data['access_token'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|\WP_Error
     */
    private function helix_request(string $method, string $path, ?array $body = null) {
        $token = $this->ensure_fresh_token();
        if (is_wp_error($token)) return $token;
        $s = self::settings();

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Client-Id'      => $s['client_id'],
                'Authorization'  => 'Bearer ' . $token,
                'Content-Type'   => 'application/json',
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $response = wp_remote_request(self::HELIX_BASE . $path, $args);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['message']) ? $data['message'] : ('Twitch API returned HTTP ' . $code);
            return new WP_Error('twitch_api_error', $message);
        }
        return is_array($data) ? $data : [];
    }

    /* ---------------- cross-post: chat announcement ---------------- */

    /**
     * $args: ['message' => string, 'color' => 'primary'|'blue'|'green'|'orange'|'purple']
     * The Twitch analog of "push an update to this platform" — no
     * generic content-posting endpoint exists here the way it does for
     * YouTube uploads, so a chat announcement is the real, closest
     * equivalent: a visible, timestamped push to the artist's own
     * channel, usable for "new track is out" / "going live in 10" type
     * announcements.
     */
    /** @param array<string, mixed> $args */
    public function cross_post($args) {
        $s = self::settings();
        if (empty($s['broadcaster_id'])) return new WP_Error('not_connected', 'Twitch isn\'t connected yet.');

        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') return new WP_Error('empty_message', 'Announcement message can\'t be empty.');

        $color = in_array($args['color'] ?? 'primary', ['primary', 'blue', 'green', 'orange', 'purple'], true) ? $args['color'] : 'primary';

        $result = $this->helix_request(
            'POST',
            '/chat/announcements?broadcaster_id=' . urlencode($s['broadcaster_id']) . '&moderator_id=' . urlencode($s['broadcaster_id']),
            ['message' => substr($message, 0, 500), 'color' => $color]
        );

        return is_wp_error($result) ? $result : true;
    }

    /** @return true|\WP_Error|int|false */
    public static function enqueue_cross_post(string $message, string $color = 'primary') {
        $args = ['message' => $message, 'color' => $color];
        if (class_exists('OUS_Jobs')) {
            return OUS_Jobs::enqueue(self::CROSS_POST_JOB, $args);
        }
        return (new self())->cross_post($args);
    }

    /* ---------------- stats pull: live status + follower count ---------------- */

    public function pull_stats() {
        $s = self::settings();
        if (empty($s['broadcaster_id'])) return new WP_Error('not_connected', 'Twitch isn\'t connected yet.');

        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $now = current_time('mysql', true);

        $followers = $this->helix_request('GET', '/channels/followers?broadcaster_id=' . urlencode($s['broadcaster_id']));
        if (is_wp_error($followers)) return $followers;
        if (isset($followers['total'])) {
            $wpdb->insert($table, ['platform' => 'twitch', 'metric_key' => 'followers', 'metric_value' => (int) $followers['total'], 'recorded_at' => $now]);
        }

        $streams = $this->helix_request('GET', '/streams?user_id=' . urlencode($s['broadcaster_id']));
        if (is_wp_error($streams)) return $streams;
        $is_live = !empty($streams['data']) ? 1 : 0;
        $wpdb->insert($table, ['platform' => 'twitch', 'metric_key' => 'is_live', 'metric_value' => $is_live, 'recorded_at' => $now]);
        if ($is_live && isset($streams['data'][0]['viewer_count'])) {
            $wpdb->insert($table, ['platform' => 'twitch', 'metric_key' => 'live_viewers', 'metric_value' => (int) $streams['data'][0]['viewer_count'], 'recorded_at' => $now]);
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
            'twitch', 'twitch'
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) $out[$r['metric_key']] = ['value' => (int) $r['metric_value'], 'recorded_at' => $r['recorded_at']];
        return $out;
    }
}
