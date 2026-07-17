<?php
if (!defined('ABSPATH')) exit;

class BH_API {
    public static function register_routes() {
        $pub    = ['permission_callback' => '__return_true'];
        $auth   = ['permission_callback' => 'is_user_logged_in'];
        $admin  = ['permission_callback' => function () { return current_user_can('manage_options'); }];
        $idarg  = ['submission_id' => ['required' => true, 'sanitize_callback' => 'absint']];
        $carg   = [
            'contest'  => ['sanitize_callback' => 'sanitize_text_field'],
            // Not a bare 'sanitize_title' reference: WordPress's REST
            // framework calls sanitize_callback as (value, request, key) —
            // sanitize_title() declares 3 params too, so those extra
            // arguments silently land in its $fallback_title/$context
            // slots instead of being ignored. Wrapping it forces exactly
            // one argument through.
            'category' => ['sanitize_callback' => function ($v) { return sanitize_title((string) $v); }],
        ];

        register_rest_route('bh/v1', '/tracks',  ['methods' => 'GET',  'callback' => [self::class, 'tracks'],  'args' => ['page' => ['sanitize_callback' => 'absint']] + $carg] + $pub);
        register_rest_route('bh/v1', '/play',    ['methods' => 'POST', 'callback' => [self::class, 'play'],    'args' => $idarg] + $pub);
        register_rest_route('bh/v1', '/results', ['methods' => 'GET',  'callback' => [self::class, 'results'], 'args' => $carg] + $pub);
        register_rest_route('bh/v1', '/vote',    ['methods' => 'POST', 'callback' => [self::class, 'vote'],    'args' => $idarg + $carg] + $auth);
        register_rest_route('bh/v1', '/submit',  ['methods' => 'POST', 'callback' => [self::class, 'submit'],  'args' => $carg] + $auth);
        // Contestant self-service "wrong file uploaded" fix — AJ's own
        // ask this session. Ownership is enforced INSIDE replace_audio()
        // (post_author === current user), not just is_user_logged_in()
        // here, same "auth tier here + real ownership check in the
        // callback" pattern bh-monetization-woo's admin_post_
        // bhm_manage_subscription handler already uses.
        register_rest_route('bh/v1', '/submissions/replace-audio', ['methods' => 'POST', 'callback' => [self::class, 'replace_audio'], 'args' => $idarg] + $auth);
        // Admin-only live tally. Completely separate gate from /results —
        // always reflects the true current count regardless of the "Publish
        // Results" checkbox, and only ever answers manage_options users.
        register_rest_route('bh/v1', '/admin/live', ['methods' => 'GET', 'callback' => [self::class, 'admin_live'], 'args' => $carg] + $admin);
    }

    private static function ok($d = [])              { return new WP_REST_Response(['success' => true] + $d, 200); }
    private static function err($c, $m, $s, $d = []) { return new WP_Error($c, $m, ['status' => $s] + $d); }

    public static function tracks($req) {
        $page = max(1, (int) $req->get_param('page'));
        $cid  = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid) return self::err('no_contest', 'No matching contest.', 404);

        $cats = BH_Helpers::categories($cid);

        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'post_status'    => 'publish',
            'posts_per_page' => 25,
            'paged'          => $page,
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
        ]);

        // Every category's vote state for the current user, fetched in one
        // query and keyed as $mine[submission_id][category] — lets the
        // front end switch between category tabs instantly with no extra
        // round trip per tab.
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
        return self::ok([
            'tracks'            => $out,
            'total_pages'       => (int) $q->max_num_pages,
            'contest_id'        => $cid,
            'contest_title'     => get_the_title($cid),
            'categories'        => $cats,
            'contact_fields'    => BH_Helpers::contact_config($cid),
            'results_published' => get_post_meta($cid, '_bh_results_published', true) === '1',
        ]);
    }

    // Shared with vote() below — same validate-before-trust pattern this
    // endpoint already used for its own rate-limit key.
    private static function client_ip() {
        return (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
            ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2c: a first-party,
    // long-lived cookie distinguishing browsers independent of which
    // account is logged in — a second signal alongside IP for
    // BH_Helpers::suspicious_ip_clusters(), since a shared IP alone
    // (a household, a campus, a VPN exit node) is common and NOT itself
    // suspicious; several DIFFERENT fingerprints voting from the same IP
    // is normal, while the same fingerprint reappearing under several
    // DIFFERENT accounts from that IP is the real signal. Generated once
    // per browser, not per vote — set via a plain httponly cookie, never
    // read back into anything auth-related.
    private static function voter_fingerprint() {
        if (!empty($_COOKIE['bh_vfp']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['bh_vfp'])) {
            return $_COOKIE['bh_vfp'];
        }
        $fp = bin2hex(random_bytes(16));
        // REST responses haven't sent headers yet at this point in the
        // callback in a real request — setcookie() here lands in the
        // same response the vote confirmation does, no extra round trip.
        // headers_sent() guard is defensive only (some hosting setups
        // buffer/flush unusually) — this is a best-effort second signal,
        // never something a vote should fail over.
        if (!headers_sent()) {
            setcookie('bh_vfp', $fp, time() + 5 * YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        return $fp;
    }

    public static function play($req) {
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

    public static function vote($req) {
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

        // The track must actually belong to THIS contest — without this
        // check a client could vote on a submission from a different
        // contest while pointed at this one, corrupting both tallies.
        // QA fix: also require post_status === 'publish'. Without it, a
        // user who knows/guesses a pending or rejected submission's ID
        // could vote for it directly via this endpoint (the UI only ever
        // lists published tracks via /tracks) — the vote would silently
        // never appear in results/reveal (those both already filter by
        // publish status) but would still consume one of the user's
        // limited votes in that category, and would retroactively count
        // if the submission were approved later, without the voter
        // having chosen against the actual field of approved tracks.
        $sub = get_post($sid);
        if (!$sub || $sub->post_type !== 'bh_submission' || $sub->post_status !== 'publish'
            || (int) get_post_meta($sid, '_bh_contest_id', true) !== $cid) {
            return self::err('bad', 'That track does not belong to this contest.', 400);
        }
        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: an entry
        // that didn't survive a prior cut can't collect new votes in the
        // current round — no-op check for a single-round contest, where
        // every submission is always eligible for round 0.
        if (class_exists('BH_Rounds') && !BH_Rounds::is_eligible($sid, $cid)) {
            return self::err('eliminated', 'This entry did not advance to the current round.', 400);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s AND submission_id = %d AND round = %d",
            $uid, $cid, $cat, $sid, $round
        ));

        // Toggle off.
        if ($existing) {
            $deleted = $wpdb->delete($t, ['id' => $existing], ['%d']);
            if ($deleted === false && class_exists('OUS_DebugLog')) {
                // Previously unchecked — the response always claimed
                // "removed" regardless of whether the row actually went
                // away, so a failed delete looked identical to a
                // successful one to both the voter and anyone debugging
                // a "my vote count looks wrong" report later.
                OUS_DebugLog::log('error', 'Vote removal DB delete failed.', [
                    'user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'vote_row_id' => $existing, 'db_error' => $wpdb->last_error,
                ], 'BH Contest Voting');
            }
            // BH_Event: fire-and-forget, after this DB write (no
            // transaction wraps the removal path) — purely for the
            // activity-stream/CRM side, never touching the vote-tallying
            // logic above. See EVENT-TRACKING-ARCHITECTURE-PLAN.md
            // Section 5 item 4.
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
            // Previously unchecked and the transaction still COMMITted
            // regardless — a failed insert told the voter "added" with
            // no actual vote recorded, and nothing anywhere would have
            // explained a later "why is my vote count wrong" report.
            OUS_DebugLog::log('error', 'Vote insert DB write failed — COMMIT proceeded anyway, response will incorrectly report success.', [
                'user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'submission_id' => $sid, 'db_error' => $wpdb->last_error,
            ], 'BH Contest Voting');
        }
        $wpdb->query('COMMIT');

        // BH_Event: fired here, AFTER the transaction commits — must
        // NOT move inside the transaction or onto the queue in a way
        // that could delay/block the synchronous votes_left response
        // the caller needs immediately. See
        // EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 5 item 4.
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

    public static function submit($req) {
        $uid = get_current_user_id();
        if (!BHI_Auth::is_email_verified($uid)) {
            return self::err('unverified', 'Please confirm your email before submitting — check your inbox for the verification link.', 403);
        }

        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));

        if (!$cid) return self::err('no_contest', 'No contest is accepting submissions right now.', 403);
        if (!BH_Helpers::is_submission_open($cid)) {
            $status = BH_Helpers::submission_status($cid);
            $msg = $status === 'upcoming'
                ? 'Submissions haven\'t opened yet.'
                : 'Submissions have closed for this contest.';
            return self::err('sub_closed', $msg, 403);
        }
        if (BH_Helpers::has_submitted($uid, $cid)) return self::err('duplicate', 'You have already submitted a track to this contest.', 403);

        // Fold in whatever profile fields rode along with this submission —
        // registration may have already captured some of these; only what's
        // present in THIS request gets written (see BHI_Profiles::save).
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

        $f = $req->get_file_params();
        if (empty($f['audio']['tmp_name'])) return self::err('file', 'Please attach an audio file.', 400);
        if ($f['audio']['size'] > BH_MAX_BYTES) return self::err('file', 'Audio file must be 20MB or smaller.', 400);
        // Server-side type gate — the client `accept` attribute is cosmetic.
        if (empty(wp_check_filetype($f['audio']['name'], BH_Helpers::allowed_audio())['ext'])) {
            return self::err('file', 'Only MP3 or M4A files are allowed.', 400);
        }

        $title = sanitize_text_field($req->get_param('title'));
        if ($title === '') return self::err('title', 'Please enter a song title.', 400);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // The has_submitted() check above is a fast, friendly early
        // rejection — good UX, but not race-safe on its own: two
        // near-simultaneous requests from the same user (double-clicked
        // submit, a retried request) could both pass it before either
        // has actually inserted a post. add_option() is genuinely atomic
        // (option_name is a unique key at the database level), so this
        // is the real guarantee — the second concurrent request to reach
        // this point fails here even though it already passed the
        // earlier check.
        $lock_key = 'bh_sub_lock_' . $uid . '_' . $cid;
        if (!add_option($lock_key, time(), '', 'no')) {
            return self::err('duplicate', 'You have already submitted a track to this contest.', 403);
        }

        $pid = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'bh_submission',
            'post_status' => 'pending',
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

        $aid = media_handle_sideload($f['audio'], $pid);
        if (is_wp_error($aid)) {
            wp_delete_post($pid, true); // roll back so a bad upload doesn't lock the user out
            delete_option($lock_key);   // upload genuinely failed — let them retry
            if (class_exists('OUS_DebugLog')) {
                // Previously the cause was discarded entirely — a bad
                // file, a disk/permission problem, and a corrupt upload
                // all produced the identical generic client-facing
                // message with nothing anywhere to distinguish them
                // later (e.g. "several submissions failing" vs. "this
                // one user's file was bad").
                OUS_DebugLog::log('warning', 'Contest submission media_handle_sideload() failed.', [
                    'user_id' => $uid, 'contest_id' => $cid, 'filename' => $f['audio']['name'] ?? '', 'wp_error' => $aid->get_error_message(),
                ], 'BH Contest Submission');
            }
            return self::err('upload', 'We could not process that audio file. Please try another.', 400);
        }
        update_post_meta($pid, '_bh_audio_id', $aid);

        // Feeds the CRM's unified per-person activity timeline
        // (BHCRM's render_timeline(), own-ur-shit's BH_Event).
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
            $sent = wp_mail(
                $user->user_email,
                $subject,
                "Hi {$user->user_login},\n\nYour track \"{$title}\" for {$contest_title} has been received and is pending review. You'll hear from us once it's approved.\n\nThanks for entering!"
            );
            if ($sent && class_exists('BH_Event')) {
                BH_Event::emit('bhcore/email_sent', [
                    'user_id' => $uid, 'subject_type' => 'email', 'subject_id' => 0,
                    'payload' => ['title' => $subject],
                ]);
            } elseif (!$sent && class_exists('OUS_DebugLog')) {
                // Debug-log wiring pass — previously silent on failure.
                OUS_DebugLog::log('warning', 'Submission-received confirmation email failed to send (wp_mail() returned false).', [
                    'user_id' => $uid, 'submission_id' => $pid,
                ], 'BH Contest Submission');
            }
        }

        // submission_id + the two share-card URLs ride along on the
        // success response so the submit form's own JS can offer
        // "Get shareable image" immediately, without a second request —
        // same shape class-share-cards.php's card_url() builds, just
        // called from here since this is a REST context, not a
        // template_redirect one.
        $share = class_exists('BH_ShareCards') ? [
            'entered_card_url' => BH_ShareCards::entered_card_url($pid),
            'vote_card_url' => BH_ShareCards::vote_card_url($pid),
            'contest_page_url' => BH_ShareCards::contest_page_url($cid),
        ] : [];
        return self::ok(['submission_id' => $pid] + $share);
    }

    /**
     * "Wrong file uploaded" fix — AJ's own ask this session. Available
     * to the submission's OWN author (self-service) or an admin, any
     * time the contest's submission window is still open — same
     * BH_Helpers::is_submission_open() gate submit() itself uses, so a
     * swap can never happen after the window a fresh submission would
     * also be rejected in.
     *
     * The new file goes into `_bh_pending_audio_id`, NEVER directly
     * into `_bh_audio_id` — the currently-live file (whatever's
     * actually being played/voted on right now) keeps serving
     * unchanged until an admin reviews and approves the swap
     * (BH_Admin::handle_approve_swap()). If a pending swap already
     * exists (the contestant swapped more than once before review),
     * the previous pending attachment is deleted and replaced — only
     * the newest ever needs review, per AJ's own instruction.
     */
    public static function replace_audio($req) {
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

        // Only the newest pending swap survives — delete a prior
        // unreviewed one rather than stacking pending files.
        $old_pending = (int) get_post_meta($pid, '_bh_pending_audio_id', true);
        if ($old_pending && $old_pending !== $aid) wp_delete_attachment($old_pending, true);

        update_post_meta($pid, '_bh_pending_audio_id', $aid);
        update_post_meta($pid, '_bh_pending_replaced_by', $uid);
        update_post_meta($pid, '_bh_pending_replaced_at', current_time('mysql'));
        // A prior rejection is no longer the last word once a new file
        // shows up — put it back in front of an admin.
        if ($post->post_status === 'rejected') wp_update_post(['ID' => $pid, 'post_status' => 'pending']);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_file_replaced', [
                'user_id' => (int) $post->post_author, 'subject_type' => 'bh_submission', 'subject_id' => $pid,
                'payload' => ['contest_id' => $cid, 'replaced_by' => $uid, 'is_admin' => $is_admin],
            ]);
        }

        return self::ok(['message' => 'Your replacement file is uploaded and waiting for review — your original submission stays active until then.']);
    }

    // Always returns a `categories` array — a contest with no named
    // categories gets a single entry (slug '') so the client can treat
    // both shapes uniformly and only render tabs when there's more than one.
    public static function results($req) {
        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid || get_post_meta($cid, '_bh_results_published', true) !== '1') {
            return self::err('hidden', 'Results have not been published yet.', 403);
        }

        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => '']];

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: 'judges'
        // replaces the public vote tally everywhere a leaderboard is
        // read; 'hybrid' adds a SECOND leaderboard rather than blending
        // the two into one score (the roadmap doc's own direct decision)
        // — a 'judge_results' key is only present at all for a
        // judges/hybrid contest, so an existing front end reading only
        // 'results' keeps working unmodified for every 'public' contest,
        // which is every contest that predates this feature.
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

    // Public so BH_Reveal can reuse the exact same ranked-results query
    // rather than re-deriving it — one source of truth for "who's
    // currently winning a category."
    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: $round scopes
    // the tally to one round's votes only (each round's votes are
    // independent rows — class-activator.php 1.7) — null (the default,
    // every pre-existing call site) means "every vote regardless of
    // round," which is exactly today's unchanged behavior for a
    // single-round contest, since every one of its votes carries
    // round = 0 anyway.
    public static function category_results($cid, $category, $round = null) {
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

    // Admin-only: the true current tally for a SPECIFIC contest, live,
    // regardless of whether results have been published. Includes vote
    // velocity (last vote timestamp) and voter turnout so admins can gauge
    // how a contest is going without tipping anything off publicly.
    // category=all returns every category's rows together (one row per
    // submission+category, with the category name attached) instead of a
    // single category's leaderboard.
    public static function admin_live($req) {
        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid) return self::err('no_contest', 'No matching contest.', 404);

        // Check the raw query value directly for the 'all' sentinel rather
        // than only the sanitized param — belt-and-suspenders against any
        // future sanitize_callback change silently breaking this specific
        // comparison the way the unwrapped sanitize_title() reference did.
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
