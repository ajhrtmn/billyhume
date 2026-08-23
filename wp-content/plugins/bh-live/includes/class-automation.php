<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 2 of ROADMAP-obs-integration.md — event-driven OBS scene
 * automation. Storage is per-broadcaster (user meta), matching AJ's own
 * call: works correctly the moment a second real broadcaster uses
 * bh-live, not just Billy.
 *
 * The rule/event REST routes are two different trust models, not one:
 * `/automation/rules` is managed from wp-admin (a real login session,
 * `is_user_logged_in()` works fine there). `/automation/events` is read
 * by the automation-bridge overlay page (class-overlay.php), which runs
 * inside OBS's own embedded Chromium — a separate browser profile with
 * no WordPress login cookie, so `is_user_logged_in()` can never
 * authenticate it. That route is instead gated by a per-broadcaster
 * bearer token in the URL, the same "secret in the URL, not a session"
 * pattern this ecosystem already uses for RTMP stream keys.
 */
class BHL_Automation {
    // event_type => human label. The full set of triggers this bridge
    // actually knows how to see — every real BH_Event::emit() call site
    // in the ecosystem (confirmed via direct grep, not guessed) plus
    // bh_contest_round_advanced (class-rounds.php's own new hook).
    // Deliberately NOT including invented triggers like "highest bid"
    // or "course live session start" — neither exists in this codebase
    // yet (see ROADMAP-obs-integration.md's Phase 2 correction note).
    const EVENT_TYPES = [
        'bh_contest_round_advanced' => 'Contest round advances',
        'bh/vote'                   => 'A vote is cast',
        'bh/submission_created'     => 'A contest submission is created',
        'bhc/enroll'                => 'A student enrolls in a course',
        'bhc/course_completed'      => 'A student completes a course',
        'bhm/wallet_credit'         => 'A wallet is credited (purchase)',
        'bhm/purchase_anchored'     => 'A purchase is ledger-anchored',
        'bhs/play'                  => 'A track starts playing',
    ];

    // The one trigger above NOT stored in wp_bhcore_events — logged
    // directly to bhl_automation_log by log_round_advanced() below.
    const NON_CORE_TYPES = ['bh_contest_round_advanced'];

    const ACTIONS = ['switch_scene' => 'Switch to scene', 'toggle_source' => 'Enable a source (Scene:Source)'];

    public static function init(): void {
        add_action('bh_contest_round_advanced', [self::class, 'log_round_advanced'], 10, 2);
    }

    public static function register_routes(): void {
        register_rest_route('bhl/v1', '/automation/rules', [
            'methods' => 'GET', 'callback' => [self::class, 'get_rules'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/automation/rules', [
            'methods' => 'POST', 'callback' => [self::class, 'save_rule'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/automation/rules/(?P<rule_id>[a-zA-Z0-9]+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'delete_rule'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/automation/settings', [
            'methods' => 'GET', 'callback' => [self::class, 'get_settings'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/automation/settings', [
            'methods' => 'POST', 'callback' => [self::class, 'save_settings'], 'permission_callback' => 'is_user_logged_in',
        ]);
        // Token-gated, not session-gated — see class docblock.
        register_rest_route('bhl/v1', '/automation/events', [
            'methods' => 'GET', 'callback' => [self::class, 'get_events'], 'permission_callback' => '__return_true',
        ]);
        // ROADMAP-obs-integration.md Phase 3 — Twitch channel / YouTube
        // API key + video ID, each independently optional. Session-gated
        // like /automation/settings above (managed from wp-admin); the
        // bridge page itself gets these via render_automation_bridge()'s
        // own inline data, not a separate authenticated fetch.
        register_rest_route('bhl/v1', '/automation/chat-sources', [
            'methods' => 'GET', 'callback' => [self::class, 'get_chat_sources'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/automation/chat-sources', [
            'methods' => 'POST', 'callback' => [self::class, 'save_chat_sources'], 'permission_callback' => 'is_user_logged_in',
        ]);
    }

    private static function log_table(): string {
        return BHL_Tables::automation_log();
    }

    /** @param mixed $round unused — signature matches the do_action('bh_contest_round_advanced', $cid, $round) call site */
    public static function log_round_advanced(int $cid, $round): void {
        global $wpdb;
        $wpdb->insert(self::log_table(), [
            'type' => 'bh_contest_round_advanced', 'subject_id' => $cid,
        ]);
    }

    /* ---------------- per-broadcaster storage ---------------- */

    /** @return array<int, array<string, mixed>> */
    private static function rules(int $user_id): array {
        $r = get_user_meta($user_id, 'bhl_obs_rules', true);
        return is_array($r) ? $r : [];
    }

    // OBS connection details PLUS the bearer token the automation-bridge
    // overlay page authenticates with. Plaintext in user meta, same
    // storage posture bh-live's own Cloudflare/Fly settings already use
    // for their own API credentials (get_option(), no encryption layer
    // exists anywhere in this ecosystem to lean on) — not a new,
    // inconsistent security bar for this one feature to invent.
    /** @return array<string, mixed> */
    public static function settings(int $user_id): array {
        $s = get_user_meta($user_id, 'bhl_obs_settings', true);
        $s = is_array($s) ? $s : [];
        $s = wp_parse_args($s, ['host' => '127.0.0.1', 'port' => 4455, 'password' => '', 'token' => '']);
        if (!$s['token']) {
            $s['token'] = wp_generate_password(32, false);
            update_user_meta($user_id, 'bhl_obs_settings', $s);
        }
        return $s;
    }

    // Public wrapper — class-overlay.php's automation-bridge page needs
    // this same token→broadcaster resolution to know whose OBS
    // host/port/password to hand the client-side JS.
    public static function user_for_token(string $token): int {
        return self::resolve_user_by_token($token);
    }

    private static function resolve_user_by_token(string $token): int {
        if (!$token) return 0;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'bhl_obs_settings'
        ));
        foreach ($rows as $r) {
            $s = maybe_unserialize($r->meta_value);
            if (is_array($s) && isset($s['token']) && hash_equals((string) $s['token'], $token)) {
                return (int) $r->user_id;
            }
        }
        return 0;
    }

    /* ---------------- rules REST ---------------- */

    public static function get_rules(): \WP_REST_Response {
        return new WP_REST_Response(['success' => true, 'rules' => self::rules(get_current_user_id()), 'event_types' => self::EVENT_TYPES, 'actions' => self::ACTIONS], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function save_rule(\WP_REST_Request $req) {
        $user_id = get_current_user_id();
        $event_type = sanitize_text_field((string) $req->get_param('event_type'));
        if (!isset(self::EVENT_TYPES[$event_type])) return new WP_Error('bad_event', 'Unknown event type.', ['status' => 400]);
        $action = sanitize_text_field((string) $req->get_param('action'));
        if (!isset(self::ACTIONS[$action])) return new WP_Error('bad_action', 'Unknown action.', ['status' => 400]);
        $target = trim(sanitize_text_field((string) $req->get_param('target')));
        if ($target === '') return new WP_Error('missing_target', 'Scene/source name is required.', ['status' => 400]);

        $rules = self::rules($user_id);
        $rules[] = ['id' => wp_generate_password(8, false), 'event_type' => $event_type, 'action' => $action, 'target' => $target];
        update_user_meta($user_id, 'bhl_obs_rules', $rules);
        return new WP_REST_Response(['success' => true, 'rules' => $rules], 200);
    }

    public static function delete_rule(\WP_REST_Request $req): \WP_REST_Response {
        $user_id = get_current_user_id();
        $rid = sanitize_text_field((string) $req->get_param('rule_id'));
        $rules = array_values(array_filter(self::rules($user_id), fn($r) => $r['id'] !== $rid));
        update_user_meta($user_id, 'bhl_obs_rules', $rules);
        return new WP_REST_Response(['success' => true, 'rules' => $rules], 200);
    }

    /* ---------------- settings REST ---------------- */

    public static function get_settings(): \WP_REST_Response {
        $s = self::settings(get_current_user_id());
        unset($s['password']); // never echo the OBS password back over REST — write-only from the admin form
        return new WP_REST_Response(['success' => true, 'settings' => $s], 200);
    }

    public static function save_settings(\WP_REST_Request $req): \WP_REST_Response {
        $user_id = get_current_user_id();
        $existing = self::settings($user_id);
        $host = sanitize_text_field((string) $req->get_param('host'));
        $port = (int) $req->get_param('port');
        $password = (string) $req->get_param('password'); // may be empty = "leave unchanged"
        update_user_meta($user_id, 'bhl_obs_settings', [
            'host' => $host ?: '127.0.0.1',
            'port' => $port ?: 4455,
            'password' => $password !== '' ? $password : $existing['password'],
            'token' => $existing['token'],
        ]);
        return new WP_REST_Response(['success' => true], 200);
    }

    /* ---------------- chat sources (Phase 3) ---------------- */

    // Twitch needs no credential at all (anonymous justinfan IRC read —
    // see ROADMAP-obs-integration.md's Phase 3 correction note), so
    // 'twitch_channel' alone is enough to enable that source. YouTube
    // read-only chat needs a plain API key (no OAuth — that's only
    // required to POST to chat) plus the live video's own ID.
    /** @return array<string, mixed> */
    public static function chat_sources(int $user_id): array {
        $s = get_user_meta($user_id, 'bhl_chat_sources', true);
        $s = is_array($s) ? $s : [];
        return wp_parse_args($s, ['twitch_channel' => '', 'youtube_api_key' => '', 'youtube_video_id' => '']);
    }

    public static function get_chat_sources(): \WP_REST_Response {
        $s = self::chat_sources(get_current_user_id());
        $out = $s;
        unset($out['youtube_api_key']); // write-only from the admin form, same posture as the OBS password
        $out['youtube_api_key_set'] = $s['youtube_api_key'] !== '';
        return new WP_REST_Response(['success' => true, 'sources' => $out], 200);
    }

    public static function save_chat_sources(\WP_REST_Request $req): \WP_REST_Response {
        $user_id = get_current_user_id();
        $existing = self::chat_sources($user_id);
        $api_key = (string) $req->get_param('youtube_api_key'); // empty = "leave unchanged"
        update_user_meta($user_id, 'bhl_chat_sources', [
            'twitch_channel'   => sanitize_text_field((string) $req->get_param('twitch_channel')),
            'youtube_api_key'  => $api_key !== '' ? $api_key : $existing['youtube_api_key'],
            'youtube_video_id' => sanitize_text_field((string) $req->get_param('youtube_video_id')),
        ]);
        return new WP_REST_Response(['success' => true], 200);
    }

    /* ---------------- events REST (token-gated) ---------------- */

    /** @return \WP_REST_Response|\WP_Error */
    public static function get_events(\WP_REST_Request $req) {
        $user_id = self::resolve_user_by_token((string) $req->get_param('token'));
        if (!$user_id) return new WP_Error('bad_token', 'Invalid or missing token.', ['status' => 403]);

        $rules = self::rules($user_id);
        $types = array_values(array_unique(array_column($rules, 'event_type')));
        if (!$types) return new WP_REST_Response(['success' => true, 'events' => []], 200);

        $after = sanitize_text_field((string) $req->get_param('after_ts'));
        if (!$after) $after = gmdate('Y-m-d H:i:s', time() - 60); // first poll: only the last minute, not all history

        global $wpdb;
        $out = [];

        $core_types = array_values(array_diff($types, self::NON_CORE_TYPES));
        if ($core_types && class_exists('BH_Event')) {
            $t = BH_Event::table();
            $placeholders = implode(',', array_fill(0, count($core_types), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT type, subject_id, occurred_at FROM $t WHERE occurred_at > %s AND type IN ($placeholders) ORDER BY occurred_at ASC LIMIT 50",
                array_merge([$after], $core_types)
            ));
            foreach ($rows as $r) $out[] = ['type' => $r->type, 'subject_id' => (int) $r->subject_id, 'occurred_at' => $r->occurred_at];
        }

        $non_core = array_values(array_intersect($types, self::NON_CORE_TYPES));
        if ($non_core) {
            $placeholders = implode(',', array_fill(0, count($non_core), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT type, subject_id, occurred_at FROM " . self::log_table() . " WHERE occurred_at > %s AND type IN ($placeholders) ORDER BY occurred_at ASC LIMIT 50",
                array_merge([$after], $non_core)
            ));
            foreach ($rows as $r) $out[] = ['type' => $r->type, 'subject_id' => (int) $r->subject_id, 'occurred_at' => $r->occurred_at];
        }

        usort($out, fn($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));
        $out = array_slice($out, 0, 50);

        // Attach every rule matching this event type — a broadcaster can
        // stack more than one action on the same trigger.
        foreach ($out as &$row) {
            $row['rules'] = array_values(array_filter($rules, fn($r) => $r['event_type'] === $row['type']));
        }

        return new WP_REST_Response(['success' => true, 'events' => $out], 200);
    }
}
