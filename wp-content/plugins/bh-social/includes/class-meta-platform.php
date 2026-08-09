<?php
if (!defined('ABSPATH')) exit;

/**
 * Meta Graph API (Instagram via a linked Facebook Page) — third
 * platform, and the first genuinely gated one: real production use
 * needs Meta's app-review process per scope (instagram_business_basic,
 * instagram_business_content_publish), each with its own screencast
 * submission and a 2-4 week turnaround — that's an external,
 * calendar-time step outside this codebase, not something this class
 * can shortcut. In Meta's own "Development Mode" (before review), the
 * exact same code below already works end-to-end against Instagram
 * accounts added as Test Users / roles on the app in Meta's dashboard —
 * useful for verifying this integration without waiting on review.
 *
 * Structurally different from YouTube/Twitch in one real way: the
 * Content Publishing API does NOT accept a direct file upload — it
 * takes a publicly reachable image_url/video_url that Meta's own
 * servers fetch. Since WordPress attachments already have public URLs
 * on a live site, cross_post() just passes wp_get_attachment_url()
 * through rather than uploading bytes anywhere itself.
 *
 * v1 auto-selects the FIRST Facebook Page (from /me/accounts) that has
 * a linked Instagram Business/Creator account, rather than presenting a
 * page-picker UI — a real simplification worth revisiting if an
 * artist's Facebook user ever manages more than one Page, but matches
 * this plugin's current single-artist, single-account scope.
 */
class BHSO_Meta implements BH_SocialPlatform {
    const STATS_CRON_HOOK = 'bhso_meta_pull_stats_cron';
    const CROSS_POST_JOB = 'bhso_meta_cross_post';
    const STATS_JOB = 'bhso_meta_pull_stats';

    const GRAPH_VERSION = 'v21.0';
    const AUTH_URL_BASE  = 'https://www.facebook.com';
    const GRAPH_URL_BASE = 'https://graph.facebook.com';

    // instagram_business_basic (read profile/media) and
    // instagram_business_content_publish (publish) are the two scopes
    // named explicitly in Meta's own review requirements; pages_show_list
    // and pages_read_engagement are needed just to resolve which Page's
    // linked IG account to use.
    const SCOPE = 'pages_show_list,pages_read_engagement,instagram_business_basic,instagram_business_content_publish,instagram_business_manage_insights';

    // Video containers process asynchronously on Meta's side — poll
    // status_code up to this many times before giving up, rather than
    // either blocking forever or assuming success.
    const CONTAINER_POLL_ATTEMPTS = 10;
    const CONTAINER_POLL_DELAY_SECONDS = 3;

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

    private static function graph_url(string $path): string {
        return self::GRAPH_URL_BASE . '/' . self::GRAPH_VERSION . $path;
    }

    /* ---------------- settings / tokens ---------------- */

    /** @return array<string, mixed> */
    public static function settings(): array {
        return get_option('bhso_meta_settings', [
            'app_id' => '', 'app_secret' => '',
            'page_access_token' => '', 'token_expires_at' => 0,
            'page_id' => '', 'page_name' => '',
            'ig_user_id' => '', 'ig_username' => '',
        ]);
    }

    public static function save_credentials(string $app_id, string $app_secret): void {
        $existing = self::settings();
        update_option('bhso_meta_settings', array_merge($existing, [
            'app_id'     => sanitize_text_field($app_id),
            'app_secret' => ($app_secret === '') ? $existing['app_secret'] : sanitize_text_field($app_secret),
        ]));
    }

    /** @param int|string $expires_in */
    private static function save_connection(string $page_access_token, $expires_in, string $page_id, string $page_name, string $ig_user_id, string $ig_username): void {
        $existing = self::settings();
        update_option('bhso_meta_settings', array_merge($existing, [
            'page_access_token' => $page_access_token,
            'token_expires_at'  => time() + (int) $expires_in,
            'page_id'           => $page_id,
            'page_name'         => $page_name,
            'ig_user_id'        => $ig_user_id,
            'ig_username'       => $ig_username,
        ]));
    }

    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['app_id']) && !empty($s['app_secret']);
    }

    public function is_connected(): bool {
        $s = self::settings();
        return !empty($s['page_access_token']) && !empty($s['ig_user_id']);
    }

    public function get_status(): string {
        if (!$this->is_configured()) return 'not_configured';
        if (!$this->is_connected()) return 'not_connected';
        // Page access tokens obtained via a long-lived user token don't
        // themselves expire on a fixed schedule the way YouTube/Twitch
        // tokens do, but token_expires_at is still tracked (from the
        // long-lived user-token exchange) as an honest "reconnect by"
        // signal rather than assuming permanence.
        $s = self::settings();
        if (!empty($s['token_expires_at']) && $s['token_expires_at'] < time()) return 'needs_reauth';
        return 'connected';
    }

    public function disconnect(): bool {
        $s = self::settings();
        update_option('bhso_meta_settings', array_merge($s, [
            'page_access_token' => '', 'token_expires_at' => 0,
            'page_id' => '', 'page_name' => '', 'ig_user_id' => '', 'ig_username' => '',
        ]));
        return true;
    }

    /* ---------------- OAuth flow ---------------- */

    private static function redirect_uri(): string {
        return admin_url('admin-post.php?action=bhso_meta_oauth_callback');
    }

    /** @return string|\WP_Error */
    public static function authorize_url() {
        $s = self::settings();
        if (empty($s['app_id'])) return new WP_Error('not_configured', 'Enter an App ID and App Secret first.');

        $state = wp_generate_password(32, false);
        set_transient('bhso_meta_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        return self::AUTH_URL_BASE . '/' . self::GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id'     => $s['app_id'],
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    public static function handle_oauth_callback(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $expected = get_transient('bhso_meta_oauth_state_' . get_current_user_id());
        delete_transient('bhso_meta_oauth_state_' . get_current_user_id());

        if (empty($state) || !hash_equals((string) $expected, $state)) {
            self::redirect_with_notice('Meta connect failed: state mismatch — the link may have expired. Try connecting again.');
            return;
        }

        if (!empty($_GET['error'])) {
            self::redirect_with_notice('Meta connect cancelled: ' . sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])));
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            self::redirect_with_notice('Meta connect failed: no authorization code returned.');
            return;
        }

        $s = self::settings();

        $short = wp_remote_get(self::graph_url('/oauth/access_token') . '?' . http_build_query([
            'client_id'     => $s['app_id'],
            'client_secret' => $s['app_secret'],
            'redirect_uri'  => self::redirect_uri(),
            'code'          => $code,
        ]), ['timeout' => 20]);
        if (is_wp_error($short)) { self::redirect_with_notice('Meta connect failed: ' . $short->get_error_message()); return; }
        $short_data = json_decode(wp_remote_retrieve_body($short), true);
        if (empty($short_data['access_token'])) {
            self::redirect_with_notice('Meta connect failed: ' . (self::extract_error($short_data) ?: 'no access_token in response'));
            return;
        }

        // Short-lived user token (~1-2hr) exchanged for a long-lived one
        // (~60 days) — required for anything that isn't a throwaway
        // test connection, per Meta's own documented token flow.
        $long = wp_remote_get(self::graph_url('/oauth/access_token') . '?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $s['app_id'],
            'client_secret'     => $s['app_secret'],
            'fb_exchange_token' => $short_data['access_token'],
        ]), ['timeout' => 20]);
        if (is_wp_error($long)) { self::redirect_with_notice('Meta connect failed: ' . $long->get_error_message()); return; }
        $long_data = json_decode(wp_remote_retrieve_body($long), true);
        if (empty($long_data['access_token'])) {
            self::redirect_with_notice('Meta connect failed: ' . (self::extract_error($long_data) ?: 'long-lived token exchange failed'));
            return;
        }
        $user_token = $long_data['access_token'];
        $expires_in = $long_data['expires_in'] ?? (60 * DAY_IN_SECONDS);

        // Resolve which Page (and its linked IG Business account) to
        // use — the first one that actually has instagram_business_account
        // set, since a Facebook user can manage Pages with no linked IG
        // account at all.
        $pages = wp_remote_get(self::graph_url('/me/accounts') . '?' . http_build_query([
            'fields'       => 'name,access_token,instagram_business_account',
            'access_token' => $user_token,
        ]), ['timeout' => 20]);
        if (is_wp_error($pages)) { self::redirect_with_notice('Meta connect failed: ' . $pages->get_error_message()); return; }
        $pages_data = json_decode(wp_remote_retrieve_body($pages), true);
        if (!empty($pages_data['error'])) {
            self::redirect_with_notice('Meta connect failed: ' . self::extract_error($pages_data));
            return;
        }

        $page = null;
        foreach ($pages_data['data'] ?? [] as $candidate) {
            if (!empty($candidate['instagram_business_account']['id'])) { $page = $candidate; break; }
        }
        if (!$page) {
            self::redirect_with_notice('Meta connect failed: no Facebook Page with a linked Instagram Business/Creator account was found on this account. Link one in Meta Business Suite first, then reconnect.');
            return;
        }

        $ig_user_id = $page['instagram_business_account']['id'];
        $page_access_token = $page['access_token'];

        $ig_profile = wp_remote_get(self::graph_url('/' . $ig_user_id) . '?' . http_build_query([
            'fields' => 'username', 'access_token' => $page_access_token,
        ]), ['timeout' => 20]);
        $ig_username = '';
        if (!is_wp_error($ig_profile)) {
            $ig_profile_data = json_decode(wp_remote_retrieve_body($ig_profile), true);
            $ig_username = $ig_profile_data['username'] ?? '';
        }

        self::save_connection($page_access_token, $expires_in, $page['id'] ?? '', $page['name'] ?? '', $ig_user_id, $ig_username);
        self::redirect_with_notice('Meta connected successfully.');
    }

    /** @param mixed $data */
    private static function extract_error($data): string {
        return is_array($data) && !empty($data['error']['message']) ? $data['error']['message'] : '';
    }

    private static function redirect_with_notice(string $msg): void {
        if (class_exists('OUS_Toast')) {
            $is_failure = stripos($msg, 'fail') !== false || stripos($msg, 'cancel') !== false;
            OUS_Toast::queue($msg, $is_failure ? 'error' : 'success');
        }
        wp_safe_redirect(add_query_arg(['page' => 'bh-social'], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string, mixed> $body_params
     * @return array<string, mixed>|\WP_Error
     */
    private function graph_request(string $method, string $path, array $body_params = []) {
        $s = self::settings();
        if (empty($s['page_access_token'])) return new WP_Error('not_connected', 'Meta isn\'t connected yet.');

        $url = self::graph_url($path);
        $body_params['access_token'] = $s['page_access_token'];

        if ($method === 'GET') {
            $response = wp_remote_get($url . '?' . http_build_query($body_params), ['timeout' => 30]);
        } else {
            $response = wp_remote_post($url, ['timeout' => 60, 'body' => $body_params]);
        }
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !empty($data['error'])) {
            return new WP_Error('meta_api_error', self::extract_error($data) ?: ('Meta API returned HTTP ' . $code));
        }
        return is_array($data) ? $data : [];
    }

    /* ---------------- cross-post: Content Publishing API ---------------- */

    /**
     * $args: ['attachment_id' => int, 'caption' => string]
     * The attachment must already be publicly reachable at its own WP
     * media URL — Meta's servers fetch the file themselves rather than
     * accepting an uploaded body, so a private/local-only attachment
     * URL will fail on Meta's end with a fetch error, not a bug here.
     */
    /** @param array<string, mixed> $args */
    public function cross_post($args) {
        $s = self::settings();
        if (empty($s['ig_user_id'])) return new WP_Error('not_connected', 'Meta isn\'t connected yet.');

        $attachment_id = (int) ($args['attachment_id'] ?? 0);
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        if (!$url) return new WP_Error('no_attachment', 'No such attachment (attachment_id: ' . $attachment_id . ').');

        $mime = get_post_mime_type($attachment_id) ?: '';
        $is_video = strpos($mime, 'video/') === 0;

        $container_params = ['caption' => (string) ($args['caption'] ?? '')];
        $container_params[$is_video ? 'video_url' : 'image_url'] = $url;
        if ($is_video) $container_params['media_type'] = 'REELS'; // Meta's current default publishable video type for Business accounts

        $container = $this->graph_request('POST', '/' . $s['ig_user_id'] . '/media', $container_params);
        if (is_wp_error($container)) return $container;
        if (empty($container['id'])) return new WP_Error('no_container', 'Meta accepted the request but returned no container id.');

        if ($is_video) {
            $ready = $this->poll_container_ready($container['id']);
            if (is_wp_error($ready)) return $ready;
        }

        $publish = $this->graph_request('POST', '/' . $s['ig_user_id'] . '/media_publish', ['creation_id' => $container['id']]);
        if (is_wp_error($publish)) return $publish;

        if (!empty($publish['id'])) update_post_meta($attachment_id, '_bhso_meta_media_id', $publish['id']);
        return true;
    }

    /** @return true|\WP_Error */
    private function poll_container_ready(string $container_id) {
        for ($i = 0; $i < self::CONTAINER_POLL_ATTEMPTS; $i++) {
            $status = $this->graph_request('GET', '/' . $container_id, ['fields' => 'status_code']);
            if (is_wp_error($status)) return $status;

            $code = $status['status_code'] ?? '';
            if ($code === 'FINISHED') return true;
            if ($code === 'ERROR') return new WP_Error('container_error', 'Meta failed to process the uploaded media.');

            sleep(self::CONTAINER_POLL_DELAY_SECONDS);
        }
        return new WP_Error('container_timeout', 'Meta is still processing this media after ' . (self::CONTAINER_POLL_ATTEMPTS * self::CONTAINER_POLL_DELAY_SECONDS) . 's — try publishing again shortly.');
    }

    /** @return true|\WP_Error|int|false */
    public static function enqueue_cross_post(int $attachment_id, string $caption = '') {
        $args = ['attachment_id' => (int) $attachment_id, 'caption' => $caption];
        if (class_exists('OUS_Jobs')) {
            return OUS_Jobs::enqueue(self::CROSS_POST_JOB, $args);
        }
        return (new self())->cross_post($args);
    }

    /* ---------------- stats pull ---------------- */

    public function pull_stats() {
        $s = self::settings();
        if (empty($s['ig_user_id'])) return new WP_Error('not_connected', 'Meta isn\'t connected yet.');

        $profile = $this->graph_request('GET', '/' . $s['ig_user_id'], ['fields' => 'followers_count,media_count']);
        if (is_wp_error($profile)) return $profile;

        global $wpdb;
        $table = $wpdb->prefix . 'bhso_platform_stats';
        $now = current_time('mysql', true);
        foreach (['followers_count' => 'followers', 'media_count' => 'media_count'] as $api_key => $metric_key) {
            if (!isset($profile[$api_key])) continue;
            $wpdb->insert($table, ['platform' => 'meta', 'metric_key' => $metric_key, 'metric_value' => (int) $profile[$api_key], 'recorded_at' => $now]);
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
            'meta', 'meta'
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) $out[$r['metric_key']] = ['value' => (int) $r['metric_value'], 'recorded_at' => $r['recorded_at']];
        return $out;
    }
}
