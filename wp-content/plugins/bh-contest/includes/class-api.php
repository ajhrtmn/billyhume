<?php
if (!defined('ABSPATH')) exit;

class BH_API {
    // WHY: job handlers register on 'init', not rest_api_init, because the
    // cron/Action Scheduler request that runs a queued job never fires rest_api_init.
    public static function init(): void {
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bh_contest_submission_confirmation_email', [self::class, 'send_submission_confirmation_email']);
        }
    }

    public static function register_routes(): void {
        $pub    = ['permission_callback' => '__return_true'];
        $auth   = ['permission_callback' => 'is_user_logged_in'];
        $admin  = ['permission_callback' => function () { return current_user_can('manage_options'); }];
        $idarg  = ['submission_id' => ['required' => true, 'sanitize_callback' => 'absint']];
        $carg   = [
            'contest'  => ['sanitize_callback' => 'sanitize_text_field'],
            // WHY: wrapped, because REST passes (value, request, key) and a bare
            // sanitize_title would take those as $fallback_title/$context.
            'category' => ['sanitize_callback' => function ($v) { return sanitize_title((string) $v); }],
        ];

        register_rest_route('bh/v1', '/tracks',  ['methods' => 'GET',  'callback' => [self::class, 'tracks'],  'args' => ['page' => ['sanitize_callback' => 'absint']] + $carg] + $pub);
        register_rest_route('bh/v1', '/play',    ['methods' => 'POST', 'callback' => [self::class, 'play'],    'args' => $idarg] + $pub);
        register_rest_route('bh/v1', '/results', ['methods' => 'GET',  'callback' => [self::class, 'results'], 'args' => $carg] + $pub);
        register_rest_route('bh/v1', '/vote',    ['methods' => 'POST', 'callback' => [self::class, 'vote'],    'args' => $idarg + $carg] + $auth);
        register_rest_route('bh/v1', '/submit',  ['methods' => 'POST', 'callback' => [self::class, 'submit'],  'args' => $carg] + $auth);
        // These two only require login here; ownership is checked inside each callback.
        register_rest_route('bh/v1', '/submissions/replace-audio', ['methods' => 'POST', 'callback' => [self::class, 'replace_audio'], 'args' => $idarg] + $auth);
        register_rest_route('bh/v1', '/submissions/edit-details', ['methods' => 'POST', 'callback' => [self::class, 'edit_details'], 'args' => $idarg + [
            'title'  => ['sanitize_callback' => 'sanitize_text_field'],
            'artist' => ['sanitize_callback' => 'sanitize_text_field'],
        ]] + $auth);
        // Admin-only live tally, independent of the "Publish Results" setting.
        register_rest_route('bh/v1', '/admin/live', ['methods' => 'GET', 'callback' => [self::class, 'admin_live'], 'args' => $carg] + $admin);
    }

    /** @param array<string, mixed> $d */
    private static function ok($d = []): \WP_REST_Response { return new WP_REST_Response(['success' => true] + $d, 200); }
    /** @param array<string, mixed> $d */
    private static function err(string $c, string $m, int $s, array $d = []): \WP_Error { return new WP_Error($c, $m, ['status' => $s] + $d); }

    /** @return \WP_REST_Response|\WP_Error */
    public static function tracks(\WP_REST_Request $req) {
        $page = max(1, (int) $req->get_param('page'));
        $cid  = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid) return self::err('no_contest', 'No matching contest.', 404);
        if (!OUS_Visibility::can_view_open_by_default($cid)) {
            return self::err('members_only', 'This contest is only visible to logged-in members.', 403);
        }

        $cats = BH_Helpers::categories($cid);

        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'post_status'    => 'publish',
            'posts_per_page' => 25,
            'paged'          => $page,
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
        ]);

        // The user's vote state for every category in one query, as
        // $mine[submission_id][category], so tab switches need no round trip.
        $mine = [];
        if (is_user_logged_in()) {
            global $wpdb;
            $t    = BH_Helpers::table();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT submission_id, category FROM $t WHERE user_id = %d AND contest_id = %d",
                get_current_user_id(), $cid
            ));
            foreach ($rows as $r) $mine[(int) $r->submission_id][$r->category] = true;
        }

        $out = [];
        foreach ($q->posts as $p) {
            $votes = [];
            if ($cats) {
                foreach ($cats as $c) $votes[$c['slug']] = isset($mine[$p->ID][$c['slug']]);
            } else {
                $votes[''] = isset($mine[$p->ID]['']);
            }
            $out[] = [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'src'    => wp_get_attachment_url(get_post_meta($p->ID, '_bh_audio_id', true)),
                'votes'  => $votes,
            ];
        }
        // Votes left per category, same math as vote()'s votes_left.
        $votes_remaining = [];
        $vote_limit = null;
        if (is_user_logged_in()) {
            $vote_limit = BH_Helpers::vote_limit(get_current_user_id(), $cid);
            if ($cats) {
                foreach ($cats as $c) {
                    $votes_remaining[$c['slug']] = max(0, $vote_limit - BH_Helpers::user_vote_count(get_current_user_id(), $cid, $c['slug']));
                }
            } else {
                $votes_remaining[''] = max(0, $vote_limit - BH_Helpers::user_vote_count(get_current_user_id(), $cid, ''));
            }
        }

        return self::ok([
            'tracks'            => $out,
            'total_pages'       => (int) $q->max_num_pages,
            'contest_id'        => $cid,
            'contest_title'     => get_the_title($cid),
            'categories'        => $cats,
            'contact_fields'    => BH_Helpers::contact_config($cid),
            'results_published' => get_post_meta($cid, '_bh_results_published', true) === '1',
            'vote_limit'        => $vote_limit,
            'votes_remaining'   => $votes_remaining,
            // The player uses this to disable vote buttons and show a closed banner.
            'voting_open'       => class_exists('BH_Rounds') ? BH_Rounds::is_voting_open($cid) : BH_Helpers::is_voting_open($cid),
        ]);
    }

    private static function client_ip(): string {
        return (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
            ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    // Long-lived per-browser cookie, a second signal beside IP for
    // BH_Helpers::suspicious_ip_clusters(): the same fingerprint under several
    // accounts is suspicious; a shared IP alone is not. Never used for auth.
    private static function voter_fingerprint(): string {
        if (!empty($_COOKIE['bh_vfp']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['bh_vfp'])) {
            return $_COOKIE['bh_vfp'];
        }
        $fp = bin2hex(random_bytes(16));
        // Best effort only; a vote never fails over a missing cookie.
        if (!headers_sent()) {
            setcookie('bh_vfp', $fp, time() + 5 * YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        return $fp;
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function play(\WP_REST_Request $req) {
        $sid = (int) $req->get_param('submission_id');
        if (get_post_type($sid) !== 'bh_submission') return self::err('bad', 'Invalid track.', 400);

        $ip = self::client_ip();
        $k = 'bh_play_' . md5($ip . '_' . $sid);

        if (!get_transient($k)) {
            set_transient($k, 1, 30 * MINUTE_IN_SECONDS);
            update_post_meta($sid, '_bh_play_count', (int) get_post_meta($sid, '_bh_play_count', true) + 1);
        }
        return self::ok();
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function vote(\WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!BHI_Auth::is_email_verified($uid)) {
            return self::err('unverified', 'Please confirm your email before voting — check your inbox for the verification link.', 403);
        }

        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        $voting_open = class_exists('BH_Rounds') ? BH_Rounds::is_voting_open($cid) : BH_Helpers::is_voting_open($cid);
        if (!$cid || !$voting_open) {
            return self::err('closed', 'Voting is not open right now.', 403);
        }
        $round = class_exists('BH_Rounds') ? BH_Rounds::active_round_index($cid) : 0;

        $cat = (string) $req->get_param('category');
        if ($cat === '' && $req->get_param('category') === null) $cat = BH_Helpers::default_category($cid);
        if (!BH_Helpers::is_valid_category($cid, $cat)) {
            return self::err('bad_category', 'That voting category does not exist.', 400);
        }

        global $wpdb;
        $t     = BH_Helpers::table();
        $sid   = (int) $req->get_param('submission_id');
        $limit = BH_Helpers::vote_limit($uid, $cid);

        // WHY: look up an existing vote BEFORE the validity gate, so a vote for
        // a since-rejected submission can always be freed. Only new votes are gated.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s AND submission_id = %d AND round = %d",
            $uid, $cid, $cat, $sid, $round
        ));

        // A new vote must target a published submission of THIS contest; otherwise
        // a guessed ID could cross-vote contests or vote for pending/rejected tracks.
        if (!$existing) {
            $sub = get_post($sid);
            if (!$sub || $sub->post_type !== 'bh_submission' || $sub->post_status !== 'publish'
                || (int) get_post_meta($sid, '_bh_contest_id', true) !== $cid) {
                return self::err('bad', 'That track does not belong to this contest.', 400);
            }
            // Entries cut in a prior round can't collect new votes.
            if (class_exists('BH_Rounds') && !BH_Rounds::is_eligible($sid, $cid)) {
                return self::err('eliminated', 'This entry did not advance to the current round.', 400);
            }
        }

        // Toggle off.
        if ($existing) {
            $deleted = $wpdb->delete($t, ['id' => $existing], ['%d']);
            if ($deleted === false && class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Vote removal DB delete failed.', [
                    'user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'vote_row_id' => $existing, 'db_error' => $wpdb->last_error,
                ], 'BH Contest Voting');
            }
            // Fire-and-forget activity event; never affects the tally.
            if (class_exists('BH_Event')) {
                BH_Event::emit('bh/vote', [
                    'user_id' => $uid,
                    'subject_type' => 'bh_submission', 'subject_id' => (int) $sid,
                    'payload' => ['contest_id' => $cid, 'category' => $cat, 'action' => 'removed'],
                ]);
            }
            return self::ok([
                'action'     => 'removed',
                'category'   => $cat,
                'votes_left' => max(0, $limit - BH_Helpers::user_vote_count($uid, $cid, $cat, $round)),
                'limit'      => $limit,
            ]);
        }

        // Toggle on — count + insert inside a transaction so two fast clicks
        // can't both slip past the limit (InnoDB row/gap lock via FOR UPDATE).
        $wpdb->query('START TRANSACTION');
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s AND round = %d FOR UPDATE",
            $uid, $cid, $cat, $round
        ));

        if ($used >= $limit) {
            $wpdb->query('ROLLBACK');
            $msg = $limit === 1
                ? 'You have used your vote in this category. Submit a track to unlock a second, or tap your pick to switch.'
                : 'You have used both your votes in this category. Tap one of your picks to free a vote, then choose again.';
            return self::err('limit', $msg, 403, ['votes_left' => 0, 'limit' => $limit]);
        }

        $inserted = $wpdb->insert(
            $t,
            ['user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'submission_id' => $sid, 'ip_address' => self::client_ip(), 'voter_fp' => self::voter_fingerprint(), 'round' => $round],
            ['%d', '%d', '%s', '%d', '%s', '%s', '%d']
        );
        if ($inserted === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Vote insert DB write failed — COMMIT proceeded anyway, response will incorrectly report success.', [
                'user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'submission_id' => $sid, 'db_error' => $wpdb->last_error,
            ], 'BH Contest Voting');
        }
        $wpdb->query('COMMIT');

        // WHY: emit after COMMIT, never inside the transaction, so it can't
        // delay the synchronous votes_left response.
        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/vote', [
                'user_id' => $uid,
                'subject_type' => 'bh_submission', 'subject_id' => (int) $sid,
                'payload' => ['contest_id' => $cid, 'category' => $cat, 'action' => 'added'],
            ]);
        }

        return self::ok([
            'action'     => 'added',
            'category'   => $cat,
            'votes_left' => max(0, $limit - $used - 1),
            'limit'      => $limit,
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function submit(\WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!BHI_Auth::is_email_verified($uid)) {
            return self::err('unverified', 'Please confirm your email before submitting — check your inbox for the verification link.', 403);
        }

        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));

        if (!$cid) return self::err('no_contest', 'No contest is accepting submissions right now.', 403);
        $submission_open = class_exists('BH_Rounds') ? BH_Rounds::is_new_submission_allowed($cid) : BH_Helpers::is_submission_open($cid);
        if (!$submission_open) {
            $status = BH_Helpers::submission_status($cid);
            $msg = $status === 'upcoming'
                ? 'Submissions haven\'t opened yet.'
                : 'Submissions have closed for this contest.';
            return self::err('sub_closed', $msg, 403);
        }
        // An unfinished draft (no audio yet) gets a "finish it" message instead.
        $existing_draft = BH_Helpers::draft_submission_for($uid, $cid);
        if ($existing_draft) {
            return self::err('needs_audio', 'You already started a submission for this contest — finish it by uploading your audio file from your account portal.', 409, ['submission_id' => $existing_draft]);
        }
        if (BH_Helpers::has_submitted($uid, $cid)) return self::err('duplicate', 'You have already submitted a track to this contest.', 403);

        // Save only the profile fields present in this request.
        $profile_fields = BHI_Profiles::from_request($req);
        if ($profile_fields) BHI_Profiles::save($uid, $profile_fields);

        $missing = BH_Helpers::missing_for_submission($uid, $cid);
        if ($missing) {
            return self::err(
                'profile_incomplete',
                'Please add your real name and at least one way to reach you (Discord, Twitch, or YouTube) before submitting.',
                400,
                ['missing' => $missing]
            );
        }

        // Per-contest opt-in: submit now, attach audio later from the portal
        // (via replace_audio()).
        $allow_draft = (bool) get_post_meta($cid, '_bh_allow_audio_optional', true);
        $f = $req->get_file_params();
        $has_file = !empty($f['audio']['tmp_name']);
        if (!$has_file && !$allow_draft) return self::err('file', 'Please attach an audio file.', 400);
        if ($has_file) {
            if ($f['audio']['size'] > BH_MAX_BYTES) return self::err('file', 'Audio file must be 20MB or smaller.', 400);
            // Server-side type gate — the client `accept` attribute is cosmetic.
            if (empty(wp_check_filetype($f['audio']['name'], BH_Helpers::allowed_audio())['ext'])) {
                return self::err('file', 'Only MP3 or M4A files are allowed.', 400);
            }
        }

        $title = sanitize_text_field($req->get_param('title'));
        if ($title === '') return self::err('title', 'Please enter a song title.', 400);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // WHY: has_submitted() above isn't race-safe; add_option() is atomic
        // (unique option_name), so this lock is the real duplicate guard.
        $lock_key = 'bh_sub_lock_' . $uid . '_' . $cid;
        if (!add_option($lock_key, time(), '', 'no')) {
            return self::err('duplicate', 'You have already submitted a track to this contest.', 403);
        }

        $pid = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'bh_submission',
            // 'draft' keeps a no-audio entry out of review and the bonus-vote
            // check; has_submitted() still counts it, blocking a second entry.
            'post_status' => $has_file ? 'pending' : 'draft',
            'post_author' => $uid,
        ], true);
        if (is_wp_error($pid)) {
            delete_option($lock_key);
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Contest submission wp_insert_post() failed.', [
                    'user_id' => $uid, 'contest_id' => $cid, 'wp_error' => $pid->get_error_message(),
                ], 'BH Contest Submission');
            }
            return self::err('save', 'Could not save your submission.', 500);
        }

        update_post_meta($pid, '_bh_contest_id', $cid);
        update_post_meta($pid, '_bh_artist_name', sanitize_text_field($req->get_param('artist')));
        update_post_meta($pid, '_bh_admin_note', sanitize_textarea_field($req->get_param('note')));

        if (!$has_file) {
            // Notification waits until audio arrives (see notify_submission_complete()).
            return self::ok([
                'submission_id' => $pid,
                'needs_audio' => true,
                'message' => "Submission started! Attach your audio file anytime before the deadline from your account portal to finish entering.",
            ]);
        }

        $aid = media_handle_sideload($f['audio'], $pid);
        if (is_wp_error($aid)) {
            wp_delete_post($pid, true); // roll back so a bad upload doesn't lock the user out
            delete_option($lock_key);   // upload genuinely failed — let them retry
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Contest submission media_handle_sideload() failed.', [
                    'user_id' => $uid, 'contest_id' => $cid, 'filename' => $f['audio']['name'] ?? '', 'wp_error' => $aid->get_error_message(),
                ], 'BH Contest Submission');
            }
            return self::err('upload', 'We could not process that audio file. Please try another.', 400);
        }
        update_post_meta($pid, '_bh_audio_id', $aid);
        self::notify_submission_complete($pid, $uid, $cid, $title);

        // Share-card URLs ride along so the player can offer them without a second request.
        $share = class_exists('BH_ShareCards') ? [
            'entered_card_url' => BH_ShareCards::entered_card_url($pid),
            'vote_card_url' => BH_ShareCards::vote_card_url($pid),
            'contest_page_url' => BH_ShareCards::contest_page_url($cid),
        ] : [];
        // Lets the share modal say "Voting closes X" (the reveal has no stored date).
        $vote_end = get_post_meta($cid, '_bh_end', true);
        return self::ok(['submission_id' => $pid, 'vote_end' => $vote_end ?: null] + $share);
    }

    // Fires the event + confirmation email once a submission has real audio;
    // shared by submit() and replace_audio()'s first-attach branch.
    private static function notify_submission_complete(int $pid, int $uid, int $cid, string $title): void {
        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_created', [
                'user_id' => $uid, 'subject_type' => 'bh_submission', 'subject_id' => $pid,
                'payload' => ['contest_id' => $cid, 'title' => $title],
            ]);
        }

        $user = get_userdata($uid);
        if ($user && $user->user_email) {
            $contest_title = get_the_title($cid);
            $subject = 'We got your submission — ' . get_bloginfo('name');
            $body = "Hi {$user->user_login},\n\nYour track \"{$title}\" for {$contest_title} has been received and is pending review. You'll hear from us once it's approved.\n\nThanks for entering!";

            // WHY: queued, not sent inline, so slow mail can't time out the upload request.
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::enqueue('bh_contest_submission_confirmation_email', [
                    'user_id' => $uid, 'submission_id' => $pid, 'email' => $user->user_email, 'subject' => $subject, 'body' => $body,
                ]);
            } else {
                // No job queue: send inline rather than drop the confirmation.
                if (class_exists('BH_Mail')) {
                    BH_Mail::send([
                        'to' => $user->user_email, 'user_id' => $uid, 'subject' => $subject, 'body' => $body,
                        'source' => 'BH Contest Submission', 'log_context' => ['submission_id' => $pid],
                    ]);
                } else {
                    wp_mail($user->user_email, $subject, $body);
                }
            }
        }
    }

    // OUS_Jobs handler registered in init().
    /** @param array<string, mixed> $args */
    public static function send_submission_confirmation_email($args): void {
        $email = $args['email'] ?? '';
        $subject = $args['subject'] ?? '';
        $body = $args['body'] ?? '';
        if (!$email || !$subject || !$body) return;

        if (class_exists('BH_Mail')) {
            BH_Mail::send([
                'to' => $email, 'user_id' => (int) ($args['user_id'] ?? 0), 'subject' => $subject, 'body' => $body,
                'source' => 'BH Contest Submission', 'log_context' => ['submission_id' => $args['submission_id'] ?? 0],
            ]);
            return;
        }
        wp_mail($email, $subject, $body);
    }

    /**
     * Owner/admin audio swap while submissions are open. The new file goes to
     * `_bh_pending_audio_id`, never `_bh_audio_id`, until an admin approves it
     * (BH_AdminModeration::handle_approve_swap()); only the newest pending swap is kept.
     */
    /** @return \WP_REST_Response|\WP_Error */
    public static function replace_audio(\WP_REST_Request $req) {
        $pid = (int) $req->get_param('submission_id');
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') {
            return self::err('not_found', 'Submission not found.', 404);
        }

        $uid = get_current_user_id();
        $is_owner = (int) $post->post_author === $uid;
        $is_admin = current_user_can('manage_options');
        if (!$is_owner && !$is_admin) {
            return self::err('forbidden', 'You can only replace your own submission.', 403);
        }

        $cid = (int) get_post_meta($pid, '_bh_contest_id', true);
        if (!$cid || !BH_Helpers::is_submission_open($cid)) {
            return self::err('sub_closed', 'Submissions are closed for this contest — the file can no longer be changed.', 403);
        }

        $f = $req->get_file_params();
        if (empty($f['audio']['tmp_name'])) return self::err('file', 'Please attach an audio file.', 400);
        if ($f['audio']['size'] > BH_MAX_BYTES) return self::err('file', 'Audio file must be 20MB or smaller.', 400);
        if (empty(wp_check_filetype($f['audio']['name'], BH_Helpers::allowed_audio())['ext'])) {
            return self::err('file', 'Only MP3 or M4A files are allowed.', 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $aid = media_handle_sideload($f['audio'], $pid);
        if (is_wp_error($aid)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Contest submission replace_audio() media_handle_sideload() failed.', [
                    'user_id' => $uid, 'submission_id' => $pid, 'filename' => $f['audio']['name'] ?? '', 'wp_error' => $aid->get_error_message(),
                ], 'BH Contest Submission');
            }
            return self::err('upload', 'We could not process that audio file. Please try another.', 400);
        }

        // A draft's first file is not a swap: it goes live and the draft moves to pending.
        if ($post->post_status === 'draft' && !get_post_meta($pid, '_bh_audio_id', true)) {
            update_post_meta($pid, '_bh_audio_id', $aid);
            wp_update_post(['ID' => $pid, 'post_status' => 'pending']);
            self::notify_submission_complete($pid, (int) $post->post_author, $cid, $post->post_title);
            return self::ok(['message' => 'Submission complete — your track is now pending review!']);
        }

        // Only the newest pending swap survives.
        $old_pending = (int) get_post_meta($pid, '_bh_pending_audio_id', true);
        if ($old_pending && $old_pending !== $aid) wp_delete_attachment($old_pending, true);

        update_post_meta($pid, '_bh_pending_audio_id', $aid);
        update_post_meta($pid, '_bh_pending_replaced_by', $uid);
        update_post_meta($pid, '_bh_pending_replaced_at', current_time('mysql'));
        // A new file sends a rejected submission back to review.
        if ($post->post_status === 'rejected') wp_update_post(['ID' => $pid, 'post_status' => 'pending']);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_file_replaced', [
                'user_id' => (int) $post->post_author, 'subject_type' => 'bh_submission', 'subject_id' => $pid,
                'payload' => ['contest_id' => $cid, 'replaced_by' => $uid, 'is_admin' => $is_admin],
            ]);
        }

        return self::ok(['message' => 'Your replacement file is uploaded and waiting for review — your original submission stays active until then.']);
    }

    /**
     * Owner/admin edit of song title + artist while submissions are open.
     * Does not re-open review, since the submitted audio is unchanged.
     */
    /** @return \WP_REST_Response|\WP_Error */
    public static function edit_details(\WP_REST_Request $req) {
        $pid = (int) $req->get_param('submission_id');
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') {
            return self::err('not_found', 'Submission not found.', 404);
        }

        $uid = get_current_user_id();
        $is_owner = (int) $post->post_author === $uid;
        $is_admin = current_user_can('manage_options');
        if (!$is_owner && !$is_admin) {
            return self::err('forbidden', 'You can only edit your own submission.', 403);
        }

        $cid = (int) get_post_meta($pid, '_bh_contest_id', true);
        if (!$cid || !BH_Helpers::is_submission_open($cid)) {
            return self::err('sub_closed', 'Submissions are closed for this contest — details can no longer be changed.', 403);
        }

        $title = sanitize_text_field($req->get_param('title'));
        if ($title === '') return self::err('title', 'Song title can\'t be empty.', 400);
        $artist = sanitize_text_field($req->get_param('artist'));

        wp_update_post(['ID' => $pid, 'post_title' => $title]);
        update_post_meta($pid, '_bh_artist_name', $artist);

        return self::ok(['message' => 'Saved.', 'title' => $title, 'artist' => $artist]);
    }

    // Always returns `categories`; an uncategorized contest gets one entry with slug ''.
    /** @return \WP_REST_Response|\WP_Error */
    public static function results(\WP_REST_Request $req) {
        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid || get_post_meta($cid, '_bh_results_published', true) !== '1') {
            return self::err('hidden', 'Results have not been published yet.', 403);
        }
        if (!OUS_Visibility::can_view_open_by_default($cid)) {
            return self::err('members_only', 'This contest is only visible to logged-in members.', 403);
        }

        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => '']];

        // 'judges' swaps the rubric score into `results`; 'hybrid' adds a separate
        // `judge_results` leaderboard rather than blending the two.
        $format = BH_Helpers::contest_format($cid);
        $out = [];
        foreach ($cats as $c) {
            $row = [
                'slug'    => $c['slug'],
                'name'    => $c['name'],
                'results' => $format === 'judges' ? BH_Judging::judge_results($cid, $c['slug']) : self::category_results($cid, $c['slug']),
            ];
            if ($format === 'hybrid') $row['judge_results'] = BH_Judging::judge_results($cid, $c['slug']);
            $out[] = $row;
        }
        return self::ok(['contest' => get_the_title($cid), 'categories' => $out, 'format' => $format]);
    }

    // Ranked results for one category; public so BH_Reveal shares the same query.
    // $round limits the tally to one round's votes; null counts every round.
    /** @return array<int, array<string, mixed>> */
    public static function category_results(int $cid, string $category, ?int $round = null): array {
        global $wpdb;
        $t    = BH_Helpers::table();
        $round_sql = $round !== null ? $wpdb->prepare('AND round = %d', (int) $round) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, COUNT(id) votes FROM $t
             WHERE contest_id = %d AND category = %s $round_sql GROUP BY submission_id ORDER BY votes DESC LIMIT 10",
            $cid, $category
        ));

        $valid = [];
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p || $p->post_status !== 'publish') continue; // skip pending/deleted
            $valid[] = ['post' => $p, 'votes' => (int) $r->votes];
        }

        $ranks = BH_Helpers::competition_ranks(array_column($valid, 'votes'));

        $out = [];
        foreach ($valid as $i => $v) {
            $p = $v['post'];
            $out[] = [
                'rank'   => $ranks[$i],
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'votes'  => $v['votes'],
                'plays'  => (int) get_post_meta($p->ID, '_bh_play_count', true),
            ];
        }
        return $out;
    }

    // Admin-only live tally with turnout and last-vote time, published or not.
    // category=all returns one row per submission+category.
    /** @return \WP_REST_Response|\WP_Error */
    public static function admin_live(\WP_REST_Request $req) {
        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid) return self::err('no_contest', 'No matching contest.', 404);

        // Also check the raw query value for 'all', in case sanitizing ever alters it.
        $raw   = $req->get_query_params();
        $param = $req->get_param('category');
        $all   = ($param === 'all') || (($raw['category'] ?? '') === 'all');
        $cat   = $all ? null : (($param === null || $param === '') ? BH_Helpers::default_category($cid) : $param);

        global $wpdb;
        $t = BH_Helpers::table();

        if ($all) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT submission_id, category, COUNT(id) votes FROM $t
                 WHERE contest_id = %d GROUP BY submission_id, category ORDER BY votes DESC",
                $cid
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT submission_id, COUNT(id) votes FROM $t
                 WHERE contest_id = %d AND category = %s GROUP BY submission_id ORDER BY votes DESC",
                $cid, $cat
            ));
        }

        $cat_names = [];
        foreach (BH_Helpers::categories($cid) as $c) $cat_names[$c['slug']] = $c['name'];

        $valid = [];
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p) continue;
            $valid[] = ['post' => $p, 'votes' => (int) $r->votes, 'category' => $r->category ?? null];
        }
        $ranks = BH_Helpers::competition_ranks(array_column($valid, 'votes'));

        $out = [];
        $top = !empty($valid) ? max(1, $valid[0]['votes']) : 1;
        foreach ($valid as $i => $v) {
            $p = $v['post'];
            $row = [
                'rank'   => $ranks[$i],
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'status' => $p->post_status,
                'votes'  => $v['votes'],
                'plays'  => (int) get_post_meta($p->ID, '_bh_play_count', true),
                'pct'    => round(($v['votes'] / $top) * 100, 1),
            ];
            if ($all) $row['category'] = $v['category'] === '' ? '—' : ($cat_names[$v['category']] ?? $v['category']);
            $out[] = $row;
        }

        // Totals are contest-wide for "all", category-scoped otherwise.
        if ($all) {
            $total_votes   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE contest_id = %d", $cid));
            $unique_voters = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $t WHERE contest_id = %d", $cid));
            $last_vote_at  = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM $t WHERE contest_id = %d", $cid));
        } else {
            $total_votes   = array_sum(wp_list_pluck($rows, 'votes'));
            $unique_voters = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $t WHERE contest_id = %d AND category = %s", $cid, $cat));
            $last_vote_at  = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM $t WHERE contest_id = %d AND category = %s", $cid, $cat));
        }

        return self::ok([
            'contest_id'        => $cid,
            'contest'           => get_the_title($cid),
            'category'          => $all ? 'all' : $cat,
            'categories'        => BH_Helpers::categories($cid),
            'voting_open'       => BH_Helpers::is_voting_open($cid),
            'results_published' => get_post_meta($cid, '_bh_results_published', true) === '1',
            'total_votes'       => (int) $total_votes,
            'unique_voters'     => $unique_voters,
            'last_vote_at'      => $last_vote_at,
            'server_time'       => current_time('mysql'),
            'tracks'            => $out,
        ]);
    }
}
