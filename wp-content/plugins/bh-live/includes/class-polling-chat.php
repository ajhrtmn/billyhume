<?php
if (!defined('ABSPATH')) exit;

/**
 * The free, engine-agnostic BHL_Chat implementation — same proven
 * 2-4s-interval client polling pattern as bh-streaming's own
 * class-jam.php (chosen there, and here, specifically because it
 * "runs on ordinary shared hosting," per Jam's own docblock), rather
 * than anything requiring its own always-on process. Unlike Owncast's
 * bundled chat (tied to that one server) or BHL_WorkersChat (needs a
 * paid Cloudflare plan), this needs zero external dependency at all —
 * available regardless of which video engine is active, and the
 * default chat for the 'cloudflare' engine key specifically because
 * Cloudflare Stream Live has no chat of its own.
 *
 * Messages are scoped to a bhl_stream post id, so a broadcast's chat
 * history stays attached to that specific stream rather than one
 * global firehose. Moderation mirrors Jam's own kick mechanism
 * exactly: a transient-based block, not a permanent ban record — same
 * "cheap, good-enough, no new schema" call Jam already made.
 */
class BHL_PollingChat implements BHL_Chat {
    const MUTE_SECONDS = 600; // 10 minutes, same order of magnitude as Jam's KICK_REJOIN_BLOCK_SECONDS

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public function is_configured(): bool {
        return true; // no external account/credentials needed at all
    }

    /** @return array{type:string, html:string} */
    public function render(int $stream_id): array {
        return ['type' => 'native', 'html' => '<div class="bhl-native-chat" data-stream-id="' . $stream_id . '"></div>'];
    }

    public static function register_routes(): void {
        register_rest_route('bhl/v1', '/chat/(?P<stream_id>\d+)/messages', [
            'methods' => 'GET', 'callback' => [self::class, 'get_messages'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhl/v1', '/chat/(?P<stream_id>\d+)/messages', [
            'methods' => 'POST', 'callback' => [self::class, 'post_message'], 'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhl/v1', '/chat/(?P<stream_id>\d+)/mute', [
            'methods' => 'POST', 'callback' => [self::class, 'mute_user'],
            // Same capability this ecosystem already uses to gate
            // moderation-adjacent actions elsewhere (comment moderation
            // is the closest built-in WP equivalent to "can silence
            // another user's public post") — not a bespoke new role.
            'permission_callback' => fn() => current_user_can('moderate_comments'),
        ]);
        // ROADMAP-obs-integration.md Phase 3 — the automation bridge
        // relays Twitch/YouTube chat here. Token-gated like Phase 2's
        // /automation/events, not is_user_logged_in(): the bridge runs
        // inside OBS's own embedded Chromium, no WP session to check.
        register_rest_route('bhl/v1', '/chat/(?P<stream_id>\d+)/relay', [
            'methods' => 'POST', 'callback' => [self::class, 'relay_message'], 'permission_callback' => '__return_true',
        ]);
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bhl_chat_messages';
    }

    private static function rate_limited(string $action, int $limit = 20, int $window = 60): bool {
        $key = 'bhl_chat_rl_' . $action . '_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= $limit) return true;
        set_transient($key, $count + 1, $window);
        return false;
    }

    public static function get_messages(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $stream_id = (int) $req->get_param('stream_id');
        $after_id = (int) $req->get_param('after_id');

        if ($after_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, user_id, display_name, message, source, created_at FROM ' . self::table() . ' WHERE stream_id = %d AND id > %d ORDER BY id ASC',
                $stream_id, $after_id
            ));
        } else {
            // First fetch — most recent 50, then re-ordered oldest-first
            // for display, same "show recent history, not the whole
            // stream's chat since the beginning" convention any chat UI uses.
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, user_id, display_name, message, source, created_at FROM ' . self::table() . ' WHERE stream_id = %d ORDER BY id DESC LIMIT 50',
                $stream_id
            ));
            $rows = array_reverse($rows);
        }

        $out = array_map(fn($r) => [
            'id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'display_name' => $r->display_name,
            'message' => $r->message, 'source' => $r->source ?: 'ecosystem', 'created_at' => $r->created_at,
        ], $rows);
        return new WP_REST_Response(['success' => true, 'messages' => $out], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function post_message(\WP_REST_Request $req) {
        $stream_id = (int) $req->get_param('stream_id');
        if (get_post_type($stream_id) !== 'bhl_stream') {
            return new WP_Error('not_found', 'Stream not found.', ['status' => 404]);
        }

        $user_id = get_current_user_id();
        if (get_transient('bhl_chat_muted_' . $user_id)) {
            return new WP_Error('muted', 'You\'ve been muted in this chat.', ['status' => 403]);
        }
        if (self::rate_limited('post', 15, 30)) {
            return new WP_Error('rate_limited', 'You\'re sending messages too quickly — slow down a bit.', ['status' => 429]);
        }

        $message = trim(sanitize_text_field((string) $req->get_param('message')));
        if ($message === '') return new WP_Error('empty_message', 'Message can\'t be empty.', ['status' => 400]);
        $message = mb_substr($message, 0, 300); // matches the column's own varchar(300) cap

        global $wpdb;
        $user = get_userdata($user_id);
        $wpdb->insert(self::table(), [
            'stream_id' => $stream_id, 'user_id' => $user_id,
            'display_name' => $user ? $user->display_name : 'Guest', 'message' => $message,
        ]);
        return new WP_REST_Response(['success' => true, 'id' => (int) $wpdb->insert_id], 200);
    }

    // Same transient-based block Jam's own kick() uses (class-jam.php)
    // rather than a permanent ban record — cheap, good-enough, and
    // self-expiring, matching Jam's own reasoning exactly.
    /** @return \WP_REST_Response|\WP_Error */
    public static function mute_user(\WP_REST_Request $req) {
        $target = (int) $req->get_param('target_user_id');
        if (!$target) return new WP_Error('missing_target', 'No user specified.', ['status' => 400]);
        set_transient('bhl_chat_muted_' . $target, 1, self::MUTE_SECONDS);
        return new WP_REST_Response(['success' => true], 200);
    }

    const RELAY_SOURCES = ['twitch', 'youtube'];

    // Phase 3's own insertion point for externally-sourced chat — see
    // register_routes()' own comment for why this is token-gated rather
    // than the logged-in-user path post_message() uses. rate_limited()
    // above keys off get_current_user_id(), meaningless for a relay
    // with no WP user behind it, so this uses its own flat per-stream
    // ceiling instead — bounds worst-case abuse if a bridge page/token
    // is ever compromised, without needing per-external-user identity
    // this ecosystem has no way to verify anyway.
    /** @return \WP_REST_Response|\WP_Error */
    public static function relay_message(\WP_REST_Request $req) {
        $stream_id = (int) $req->get_param('stream_id');
        if (get_post_type($stream_id) !== 'bhl_stream') {
            return new WP_Error('not_found', 'Stream not found.', ['status' => 404]);
        }

        $token = (string) $req->get_param('token');
        $user_id = class_exists('BHL_Automation') ? BHL_Automation::user_for_token($token) : 0;
        if (!$user_id) return new WP_Error('bad_token', 'Invalid or missing token.', ['status' => 403]);

        $source = sanitize_key((string) $req->get_param('source'));
        if (!in_array($source, self::RELAY_SOURCES, true)) {
            return new WP_Error('bad_source', 'Unknown chat source.', ['status' => 400]);
        }

        $key = 'bhl_chat_relay_rl_' . $stream_id;
        $count = (int) get_transient($key);
        if ($count >= 120) return new WP_Error('rate_limited', 'Relay rate limit reached for this stream.', ['status' => 429]);
        set_transient($key, $count + 1, 60);

        $display_name = trim(sanitize_text_field((string) $req->get_param('display_name')));
        if ($display_name === '') $display_name = ucfirst($source) . ' viewer';
        $display_name = mb_substr($display_name, 0, 60);

        $message = trim(sanitize_text_field((string) $req->get_param('message')));
        if ($message === '') return new WP_Error('empty_message', 'Message can\'t be empty.', ['status' => 400]);
        $message = mb_substr($message, 0, 300);

        global $wpdb;
        $wpdb->insert(self::table(), [
            'stream_id' => $stream_id, 'user_id' => 0,
            'display_name' => $display_name, 'message' => $message, 'source' => $source,
        ]);
        return new WP_REST_Response(['success' => true, 'id' => (int) $wpdb->insert_id], 200);
    }
}
