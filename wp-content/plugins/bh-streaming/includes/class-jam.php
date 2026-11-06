<?php
if (!defined('ABSPATH')) exit;

/**
 * Jam shared listening: the host's queue/position mirrored to participants.
 *
 * WHY: polling-first (~2s, see player.ts jamPoll) since shared hosting can't
 * run a socket server. State carries a server-timestamped position so clients
 * project the real position between polls. Control modes: 'host' (default,
 * host-only transport) and 'vote_skip' (majority vote skips the track).
 */
class BHS_Jam {
    const SKIP_VOTE_RATIO = 0.5; // votes needed = ceil(participant_count * this), min 1
    const STALE_AFTER_SECONDS = 6 * HOUR_IN_SECONDS; // an abandoned session ages out rather than lingering forever

    public static function register_routes(): void {
        $auth = ['permission_callback' => 'is_user_logged_in'];

        register_rest_route('bhs/v1', '/jam', [
            'methods' => 'POST', 'callback' => [self::class, 'create'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/join', [
            'methods' => 'POST', 'callback' => [self::class, 'join'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/state', [
            'methods' => 'GET', 'callback' => [self::class, 'get_state'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/host-state', [
            'methods' => 'POST', 'callback' => [self::class, 'push_host_state'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/vote-skip', [
            'methods' => 'POST', 'callback' => [self::class, 'vote_skip'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/leave', [
            'methods' => 'POST', 'callback' => [self::class, 'leave'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/kick', [
            'methods' => 'POST', 'callback' => [self::class, 'kick'],
        ] + $auth);

        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/approve', [
            'methods' => 'POST', 'callback' => [self::class, 'approve'],
        ] + $auth);
        register_rest_route('bhs/v1', '/jam/(?P<code>[A-Za-z0-9]{4,12})/deny', [
            'methods' => 'POST', 'callback' => [self::class, 'deny'],
        ] + $auth);
    }

    /* ---------------- helpers ---------------- */

    private static function table(): string { return BHS_Tables::jam_sessions(); }
    private static function ptable(): string { return BHS_Tables::jam_participants(); }

    // WHY: a 6-char code is guessable by an unthrottled script, so join/create
    // attempts are rate-limited per user via a transient.
    private static function rate_limited(string $action, int $limit = 20, int $window = 60): bool {
        $key = 'bhs_jam_rl_' . $action . '_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= $limit) return true;
        set_transient($key, $count + 1, $window);
        return false;
    }

    private static function generate_code(): string {
        // Unambiguous charset (no 0/O/1/I): codes are read aloud and typed.
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $chars[wp_rand(0, strlen($chars) - 1)];
        return $code;
    }

    /** @return array<string, mixed>|null */
    private static function get_session_row(string $code): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE invite_code = %s AND status = 'active'", $code
        ), ARRAY_A);
    }

    /** @param array<string, mixed> $session */
    private static function is_participant(array $session, int $uid): bool {
        if ((int) $session['host_user_id'] === $uid) return true;
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::ptable() . " WHERE session_id = %d AND user_id = %d", $session['id'], $uid
        ));
        return (bool) $row;
    }

    private static function participant_count(int $session_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::ptable() . " WHERE session_id = %d", $session_id
        ));
    }

    // Single source for the majority threshold; max(1, ...) so an empty
    // session can never pass with zero votes.
    private static function skip_votes_needed(int $session_id): int {
        return max(1, (int) ceil(self::participant_count($session_id) * self::SKIP_VOTE_RATIO));
    }

    /** @return array<int, array<string, mixed>> */
    private static function participants_list(int $session_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, display_name FROM " . self::ptable() . " WHERE session_id = %d ORDER BY joined_at ASC", $session_id
        ), ARRAY_A);
    }

    // Queue tracks go through BHS_API::track_payload so gated tracks stay
    // locked inside a Jam exactly as elsewhere.
    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function tracks_payload(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post) continue;
            $out[] = BHS_API::track_payload($post);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode_state(array $row): array {
        $state = json_decode((string) $row['state_json'], true);
        $defaults = [
            'queue' => [], 'index' => 0, 'playing' => false, 'position' => 0, 'position_updated_at' => time(),
            'skip_votes' => [], 'max_participants' => 0, 'require_approval' => false, 'pending' => [],
        ];
        return is_array($state) ? array_merge($defaults, $state) : $defaults;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function respond(array $session, array $state, int $uid): array {
        $is_host = (int) $session['host_user_id'] === $uid;
        $out = [
            'code' => $session['invite_code'],
            'is_host' => $is_host,
            'control_mode' => $session['control_mode'],
            'queue' => self::tracks_payload($state['queue']),
            'index' => (int) $state['index'],
            'playing' => (bool) $state['playing'],
            'position' => (float) $state['position'],
            'position_updated_at' => (int) $state['position_updated_at'],
            'skip_votes_count' => count($state['skip_votes'] ?? []),
            'skip_votes_needed' => self::skip_votes_needed($session['id']),
            'i_voted_skip' => in_array($uid, $state['skip_votes'] ?? [], true),
            'participants' => self::participants_list($session['id']),
            'max_participants' => (int) $state['max_participants'],
            'require_approval' => (bool) $state['require_approval'],
        ];
        // Pending joiners are only exposed to the host.
        if ($is_host && !empty($state['pending'])) {
            $out['pending'] = array_map(function ($uid) {
                $u = get_userdata($uid);
                return ['user_id' => $uid, 'display_name' => $u ? $u->display_name : ('User #' . $uid)];
            }, $state['pending']);
        }
        return $out;
    }

    private static function maybe_expire_stale(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'ended' WHERE status = 'active' AND updated_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS)
        ));
    }

    /* ---------------- endpoints ---------------- */

    /** @return \WP_REST_Response|\WP_Error */
    public static function create(\WP_REST_Request $req) {
        if (self::rate_limited('create', 10, 60)) {
            return new WP_Error('rate_limited', 'Too many Jam sessions started — wait a moment and try again.', ['status' => 429]);
        }
        self::maybe_expire_stale();

        $ids = array_map('intval', (array) $req->get_param('track_ids'));
        if (!$ids) return new WP_Error('empty_queue', 'Start a Jam from a non-empty queue.', ['status' => 400]);
        $index = max(0, (int) $req->get_param('start_index'));
        $mode = in_array($req->get_param('control_mode'), ['host', 'vote_skip'], true) ? $req->get_param('control_mode') : 'host';
        // 0 = unlimited; capped regardless, to keep a leaked code from making an open room.
        $max_participants = max(0, min(200, (int) $req->get_param('max_participants')));
        $require_approval = (bool) $req->get_param('require_approval');

        global $wpdb;
        $uid = get_current_user_id();
        $code = self::generate_code();
        // Retry on the rare unique-key clash rather than overwrite a session.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $state = [
                'queue' => $ids, 'index' => $index, 'playing' => true,
                'position' => 0, 'position_updated_at' => time(), 'skip_votes' => [],
                'max_participants' => $max_participants, 'require_approval' => $require_approval, 'pending' => [],
            ];
            $inserted = $wpdb->insert(self::table(), [
                'invite_code' => $code, 'host_user_id' => $uid, 'control_mode' => $mode,
                'state_json' => wp_json_encode($state), 'status' => 'active',
            ]);
            if ($inserted) break;
            $code = self::generate_code();
        }
        if (!$inserted) return new WP_Error('jam_create_failed', 'Could not start a Jam right now.', ['status' => 500]);

        $session_id = $wpdb->insert_id;
        $user = get_userdata($uid);
        // Logged, not thrown: the session exists, but a missing host
        // participant row breaks is_participant()/participant_count().
        if (!$wpdb->insert(self::ptable(), [
            'session_id' => $session_id, 'user_id' => $uid, 'display_name' => $user ? $user->display_name : '',
        ]) && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Jam create(): host participant insert failed.', [
                'session_id' => $session_id, 'user_id' => $uid, 'db_error' => $wpdb->last_error,
            ], 'BH Streaming Jam');
        }

        $session = self::get_session_row($code);
        return rest_ensure_response(self::respond($session, self::decode_state($session), $uid));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function join(\WP_REST_Request $req) {
        if (self::rate_limited('join', 20, 60)) {
            return new WP_Error('rate_limited', 'Too many join attempts — wait a moment and try again.', ['status' => 429]);
        }
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam code is invalid or has ended.', ['status' => 404]);

        global $wpdb;
        $uid = get_current_user_id();
        if (get_transient('bhs_jam_kicked_' . $session['id'] . '_' . $uid)) {
            return new WP_Error('kicked', 'You were removed from this Jam by the host.', ['status' => 403]);
        }
        $user = get_userdata($uid);

        // Existing participants always rejoin; cap/approval only gate new entry.
        if (self::is_participant($session, $uid)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::ptable() . " SET last_seen_at = %s WHERE session_id = %d AND user_id = %d",
                current_time('mysql'), $session['id'], $uid
            ));
            return rest_ensure_response(self::respond($session, self::decode_state($session), $uid));
        }

        $state = self::decode_state($session);

        if ($state['max_participants'] > 0 && self::participant_count($session['id']) >= $state['max_participants']) {
            return new WP_Error('jam_full', 'This Jam is full.', ['status' => 403]);
        }

        if ($state['require_approval']) {
            if (!in_array($uid, $state['pending'], true)) {
                $state['pending'][] = $uid;
                $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);
            }
            return new WP_REST_Response([
                'pending' => true, 'code' => $session['invite_code'],
                'message' => 'Waiting for the host to let you in…',
            ], 202);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::ptable() . " (session_id, user_id, display_name, joined_at, last_seen_at)
             VALUES (%d, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE last_seen_at = %s",
            $session['id'], $uid, $user ? $user->display_name : '', current_time('mysql'), current_time('mysql'), current_time('mysql')
        ));

        return rest_ensure_response(self::respond($session, self::decode_state($session), $uid));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function approve(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if ((int) $session['host_user_id'] !== $uid) {
            return new WP_Error('not_host', 'Only the Jam host can approve joins.', ['status' => 403]);
        }
        $target = (int) $req->get_param('user_id');

        $state = self::decode_state($session);
        $state['pending'] = array_values(array_diff($state['pending'], [$target]));

        global $wpdb;
        $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);

        $user = get_userdata($target);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::ptable() . " (session_id, user_id, display_name) VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE last_seen_at = %s",
            $session['id'], $target, $user ? $user->display_name : '', current_time('mysql')
        ));

        return rest_ensure_response(self::respond($session, $state, $uid));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function deny(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if ((int) $session['host_user_id'] !== $uid) {
            return new WP_Error('not_host', 'Only the Jam host can manage join requests.', ['status' => 403]);
        }
        $target = (int) $req->get_param('user_id');

        $state = self::decode_state($session);
        $state['pending'] = array_values(array_diff($state['pending'], [$target]));

        global $wpdb;
        $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);

        return rest_ensure_response(self::respond($session, $state, $uid));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function get_state(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if (!self::is_participant($session, $uid)) {
            return new WP_Error('not_participant', 'Join this Jam before polling its state.', ['status' => 403]);
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::ptable() . " SET last_seen_at = %s WHERE session_id = %d AND user_id = %d",
            current_time('mysql'), $session['id'], $uid
        ));

        return rest_ensure_response(self::respond($session, self::decode_state($session), $uid));
    }

    // The only endpoint that mutates playback state; non-hosts get a 403, not
    // a silent no-op, so the client can tell "not allowed" from "failed".
    /** @return \WP_REST_Response|\WP_Error */
    public static function push_host_state(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if ((int) $session['host_user_id'] !== $uid) {
            return new WP_Error('not_host', 'Only the Jam host can control playback.', ['status' => 403]);
        }

        $state = self::decode_state($session);
        $queue = $req->get_param('queue');
        if (is_array($queue) && $queue) $state['queue'] = array_map('intval', $queue);
        // With an empty queue the clamp yields index 0, a safe value to store.
        if ($req->get_param('index') !== null) $state['index'] = max(0, min(count($state['queue']) - 1, (int) $req->get_param('index')));
        if ($req->get_param('playing') !== null) $state['playing'] = (bool) $req->get_param('playing');
        if ($req->get_param('position') !== null) $state['position'] = max(0, (float) $req->get_param('position'));
        $state['position_updated_at'] = time();
        // Any host-pushed state clears in-flight skip votes.
        $state['skip_votes'] = [];

        global $wpdb;
        $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);

        $session['state_json'] = wp_json_encode($state);
        return rest_ensure_response(self::respond($session, $state, $uid));
    }

    // Open to any participant; majority-gated so one person can't skip for everyone.
    /** @return \WP_REST_Response|\WP_Error */
    public static function vote_skip(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if (!self::is_participant($session, $uid)) {
            return new WP_Error('not_participant', 'Join this Jam first.', ['status' => 403]);
        }
        if ($session['control_mode'] !== 'vote_skip') {
            return new WP_Error('wrong_mode', 'This Jam is host-controlled — only the host can skip.', ['status' => 400]);
        }

        $state = self::decode_state($session);
        $votes = $state['skip_votes'] ?? [];
        if (!in_array($uid, $votes, true)) $votes[] = $uid;
        $state['skip_votes'] = $votes;

        $needed = self::skip_votes_needed($session['id']);
        if (count($votes) >= $needed) {
            // Recorded only for vote-skips (the room rejected the track), not
            // plain "next" presses. Feeds BHS_Stats' skip-rate table.
            if (class_exists('BHS_Stats') && isset($state['queue'][$state['index']])) {
                BHS_Stats::record_skip((int) $state['queue'][$state['index']]);
            }
            $state['index'] = min(count($state['queue']) - 1, $state['index'] + 1);
            $state['position'] = 0;
            $state['position_updated_at'] = time();
            $state['skip_votes'] = [];
        }

        global $wpdb;
        $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);

        return rest_ensure_response(self::respond($session, $state, $uid));
    }

    // Host moderation: removes a participant and sets a short rejoin block
    // (transient) so they can't immediately rejoin with the same code.
    const KICK_REJOIN_BLOCK_SECONDS = 10 * MINUTE_IN_SECONDS;

    /** @return \WP_REST_Response|\WP_Error */
    public static function kick(\WP_REST_Request $req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if ((int) $session['host_user_id'] !== $uid) {
            return new WP_Error('not_host', 'Only the Jam host can remove a participant.', ['status' => 403]);
        }
        $target = (int) $req->get_param('user_id');
        if (!$target || $target === $uid) return new WP_Error('bad_target', 'Invalid participant.', ['status' => 400]);

        global $wpdb;
        $wpdb->delete(self::ptable(), ['session_id' => $session['id'], 'user_id' => $target]);
        set_transient('bhs_jam_kicked_' . $session['id'] . '_' . $target, 1, self::KICK_REJOIN_BLOCK_SECONDS);

        return rest_ensure_response(['ok' => true]);
    }

    public static function leave(\WP_REST_Request $req): \WP_REST_Response {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return rest_ensure_response(['ok' => true]); // already gone, nothing to undo
        $uid = get_current_user_id();

        global $wpdb;
        $wpdb->delete(self::ptable(), ['session_id' => $session['id'], 'user_id' => $uid]);

        if ((int) $session['host_user_id'] === $uid) {
            $remaining = self::participants_list($session['id']);
            if ($remaining) {
                // Hand host to the longest-present participant so the session survives.
                $wpdb->update(self::table(), ['host_user_id' => $remaining[0]['user_id']], ['id' => $session['id']]);
            } else {
                $wpdb->update(self::table(), ['status' => 'ended'], ['id' => $session['id']]);
            }
        } elseif (self::participant_count($session['id']) === 0) {
            $wpdb->update(self::table(), ['status' => 'ended'], ['id' => $session['id']]);
        }

        return rest_ensure_response(['ok' => true]);
    }
}
