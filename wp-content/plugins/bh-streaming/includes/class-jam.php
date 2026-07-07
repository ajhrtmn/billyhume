<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared listening — a host's queue/position mirrored to everyone else
 * in the session. Deliberately polling-first rather than a realtime
 * relay (WebSocket/SSE server): this ecosystem runs on ordinary shared
 * hosting, where a long-lived socket server usually isn't an option and
 * often isn't even allowed. A ~2s poll (see player.js's jamPoll) is
 * cheap, works on any host that can run WordPress at all, and is
 * "good enough" sync for shared listening — it's not a rhythm game.
 * The one thing designed in from the start for a real-time upgrade
 * later: state carries a server-timestamped position
 * (position/position_updated_at) rather than just "the position at
 * last poll," so a participant can locally project "what second is it
 * really at right now" between polls instead of only updating on
 * arrival — the exact interpolation trick a realtime transport would
 * also want, so swapping the transport later doesn't touch this piece.
 *
 * Control model (task 33): 'host' (default) — only the session creator
 * drives play/pause/seek/skip/queue/shuffle, matching how someone
 * actually listens together in a room. 'vote_skip' — anyone can vote to
 * skip the current track; once votes clear a majority of the room, it
 * auto-advances. This is the explicitly-requested "better than Spotify
 * Jam" middle ground: Spotify Jam only ever gives the host control;
 * this adds an escape hatch for a track nobody in the room wants to
 * sit through, without going all the way to "everyone can do anything"
 * (which stops being "shared listening" and starts being chaos).
 */
class BHS_Jam {
    const SKIP_VOTE_RATIO = 0.5; // votes needed = ceil(participant_count * this), min 1
    const STALE_AFTER_SECONDS = 6 * HOUR_IN_SECONDS; // an abandoned session ages out rather than lingering forever

    public static function register_routes() {
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

    private static function table() { global $wpdb; return $wpdb->prefix . 'bhs_jam_sessions'; }
    private static function ptable() { global $wpdb; return $wpdb->prefix . 'bhs_jam_participants'; }

    // A 6-char code from a 32-char set is ~1 billion combinations —
    // fine against a casual guess, not fine against an unthrottled
    // script trying thousands a minute to find someone else's live
    // session (join has no other secret gating it once you're logged
    // in). Same transient-based per-user throttle bh-registry's own
    // submission/verify endpoints use for the same reason: cheap,
    // no extra table, and it's the ATTEMPT rate that matters here, not
    // a hard cap on legitimate joins/creates.
    private static function rate_limited($action, $limit = 20, $window = 60) {
        $key = 'bhs_jam_rl_' . $action . '_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= $limit) return true;
        set_transient($key, $count + 1, $window);
        return false;
    }

    private static function generate_code() {
        // Unambiguous charset (no 0/O/1/I) — this gets read aloud/typed
        // by a listener joining a friend's Jam, same reasoning as any
        // human-entered invite code.
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $chars[wp_rand(0, strlen($chars) - 1)];
        return $code;
    }

    private static function get_session_row($code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE invite_code = %s AND status = 'active'", $code
        ), ARRAY_A);
    }

    private static function is_participant($session, $uid) {
        if ((int) $session['host_user_id'] === $uid) return true;
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::ptable() . " WHERE session_id = %d AND user_id = %d", $session['id'], $uid
        ));
        return (bool) $row;
    }

    private static function participant_count($session_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::ptable() . " WHERE session_id = %d", $session_id
        ));
    }

    private static function participants_list($session_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, display_name FROM " . self::ptable() . " WHERE session_id = %d ORDER BY joined_at ASC", $session_id
        ), ARRAY_A);
    }

    // Every track referenced in a Jam's queue is expanded through
    // bh-streaming's OWN existing per-track payload/access logic
    // (BHS_API::track_payload) rather than duplicated here — a locked/
    // monetization-gated track behaves identically inside a Jam as it
    // does everywhere else in the player (still locked for whoever
    // doesn't have access, even mid-session).
    private static function tracks_payload($ids) {
        $out = [];
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post) continue;
            $out[] = BHS_API::track_payload($post);
        }
        return $out;
    }

    private static function decode_state($row) {
        $state = json_decode((string) $row['state_json'], true);
        $defaults = [
            'queue' => [], 'index' => 0, 'playing' => false, 'position' => 0, 'position_updated_at' => time(),
            'skip_votes' => [], 'max_participants' => 0, 'require_approval' => false, 'pending' => [],
        ];
        return is_array($state) ? array_merge($defaults, $state) : $defaults;
    }

    private static function respond($session, $state, $uid) {
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
            'skip_votes_needed' => max(1, (int) ceil(self::participant_count($session['id']) * self::SKIP_VOTE_RATIO)),
            'i_voted_skip' => in_array($uid, $state['skip_votes'] ?? [], true),
            'participants' => self::participants_list($session['id']),
            'max_participants' => (int) $state['max_participants'],
            'require_approval' => (bool) $state['require_approval'],
        ];
        // A pending-joiner's own name/id, so only the host ever sees who
        // else is waiting — anyone else polling this same session has
        // no business seeing a list of people who haven't been let in.
        if ($is_host && !empty($state['pending'])) {
            $out['pending'] = array_map(function ($uid) {
                $u = get_userdata($uid);
                return ['user_id' => $uid, 'display_name' => $u ? $u->display_name : ('User #' . $uid)];
            }, $state['pending']);
        }
        return $out;
    }

    private static function maybe_expire_stale() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'ended' WHERE status = 'active' AND updated_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS)
        ));
    }

    /* ---------------- endpoints ---------------- */

    public static function create($req) {
        if (self::rate_limited('create', 10, 60)) {
            return new WP_Error('rate_limited', 'Too many Jam sessions started — wait a moment and try again.', ['status' => 429]);
        }
        self::maybe_expire_stale();

        $ids = array_map('intval', (array) $req->get_param('track_ids'));
        if (!$ids) return new WP_Error('empty_queue', 'Start a Jam from a non-empty queue.', ['status' => 400]);
        $index = max(0, (int) $req->get_param('start_index'));
        $mode = in_array($req->get_param('control_mode'), ['host', 'vote_skip'], true) ? $req->get_param('control_mode') : 'host';
        // 0 = unlimited. Capped at a sane upper bound regardless of what's
        // requested — this is "protect a leaked invite code from turning
        // into an open room," not a general-purpose capacity control.
        $max_participants = max(0, min(200, (int) $req->get_param('max_participants')));
        $require_approval = (bool) $req->get_param('require_approval');

        global $wpdb;
        $uid = get_current_user_id();
        $code = self::generate_code();
        // Practically un-collidable at 6 chars from a 32-char set, but
        // don't trust practically — retry on the rare unique-key clash
        // rather than letting a collision silently overwrite someone
        // else's session.
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
        $wpdb->insert(self::ptable(), [
            'session_id' => $session_id, 'user_id' => $uid, 'display_name' => $user ? $user->display_name : '',
        ]);

        $session = self::get_session_row($code);
        return rest_ensure_response(self::respond($session, self::decode_state($session), $uid));
    }

    public static function join($req) {
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

        // Already a participant (or the host) — always let a rejoin/
        // re-poll through regardless of cap/approval, those only gate
        // NEW entry, not someone who's already in the room refreshing.
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

    public static function approve($req) {
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

    public static function deny($req) {
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

    public static function get_state($req) {
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

    // The one endpoint that actually mutates playback state, and the
    // one place host-only enforcement matters most — a non-host caller
    // gets a plain 403, never a silent no-op, so the client can tell
    // the difference between "you're not allowed" and "request failed."
    public static function push_host_state($req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return new WP_Error('not_found', 'That Jam has ended.', ['status' => 404]);
        $uid = get_current_user_id();
        if ((int) $session['host_user_id'] !== $uid) {
            return new WP_Error('not_host', 'Only the Jam host can control playback.', ['status' => 403]);
        }

        $state = self::decode_state($session);
        $queue = $req->get_param('queue');
        if (is_array($queue) && $queue) $state['queue'] = array_map('intval', $queue);
        if ($req->get_param('index') !== null) $state['index'] = max(0, min(count($state['queue']) - 1, (int) $req->get_param('index')));
        if ($req->get_param('playing') !== null) $state['playing'] = (bool) $req->get_param('playing');
        if ($req->get_param('position') !== null) $state['position'] = max(0, (float) $req->get_param('position'));
        $state['position_updated_at'] = time();
        // A host-pushed state (any deliberate action — play/pause/seek/
        // skip/queue change) always clears in-flight skip votes; the
        // vote was about the track that situation applied to, not
        // whatever the host does next.
        $state['skip_votes'] = [];

        global $wpdb;
        $wpdb->update(self::table(), ['state_json' => wp_json_encode($state)], ['id' => $session['id']]);

        $session['state_json'] = wp_json_encode($state);
        return rest_ensure_response(self::respond($session, $state, $uid));
    }

    // vote_skip mode's whole point: available to ANY participant
    // (deliberately not host-only), majority-gated so one person can't
    // unilaterally skip everyone else's pick.
    public static function vote_skip($req) {
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

        $needed = max(1, (int) ceil(self::participant_count($session['id']) * self::SKIP_VOTE_RATIO));
        if (count($votes) >= $needed) {
            // The one real, event-driven "people didn't want this track"
            // signal available anywhere in the ecosystem — recorded here,
            // not on every plain "next" button press (which just means
            // "I'm done with this one," not "the room rejected it").
            // Feeds BHS_Stats's metrics dashboard skip-rate table.
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

    // A host's own basic moderation tool — no report/appeal process
    // behind it (this is a live listening session, not a forum), just
    // the ability to stop hearing someone's disruption in real time.
    // The short rejoin-block (kept in a transient, not the DB — this
    // is a soft, session-scoped measure, not a permanent ban record)
    // stops the exact "immediately rejoin with the same code" loop
    // that would otherwise make kicking pointless.
    const KICK_REJOIN_BLOCK_SECONDS = 10 * MINUTE_IN_SECONDS;

    public static function kick($req) {
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

    public static function leave($req) {
        $session = self::get_session_row($req->get_param('code'));
        if (!$session) return rest_ensure_response(['ok' => true]); // already gone, nothing to undo
        $uid = get_current_user_id();

        global $wpdb;
        $wpdb->delete(self::ptable(), ['session_id' => $session['id'], 'user_id' => $uid]);

        if ((int) $session['host_user_id'] === $uid) {
            $remaining = self::participants_list($session['id']);
            if ($remaining) {
                // Host hand-off to whoever's been in the room longest —
                // simple, predictable, and keeps the session alive
                // instead of stranding everyone else mid-song just
                // because the person who happened to start it left.
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
