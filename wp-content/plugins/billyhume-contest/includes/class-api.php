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
            'results_published' => get_post_meta($cid, '_bh_results_published', true) === '1',
        ]);
    }

    public static function play($req) {
        $sid = (int) $req->get_param('submission_id');
        if (get_post_type($sid) !== 'bh_submission') return self::err('bad', 'Invalid track.', 400);

        $ip = (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
            ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $k = 'bh_play_' . md5($ip . '_' . $sid);

        if (!get_transient($k)) {
            set_transient($k, 1, 30 * MINUTE_IN_SECONDS);
            update_post_meta($sid, '_bh_play_count', (int) get_post_meta($sid, '_bh_play_count', true) + 1);
        }
        return self::ok();
    }

    public static function vote($req) {
        $cid = BH_Helpers::resolve_contest($req->get_param('contest'));
        if (!$cid || !BH_Helpers::is_voting_open($cid)) {
            return self::err('closed', 'Voting is not open right now.', 403);
        }

        $cat = (string) $req->get_param('category');
        if ($cat === '' && $req->get_param('category') === null) $cat = BH_Helpers::default_category($cid);
        if (!BH_Helpers::is_valid_category($cid, $cat)) {
            return self::err('bad_category', 'That voting category does not exist.', 400);
        }

        global $wpdb;
        $t     = BH_Helpers::table();
        $uid   = get_current_user_id();
        $sid   = (int) $req->get_param('submission_id');
        $limit = BH_Helpers::vote_limit($uid, $cid);

        // The track must actually belong to THIS contest — without this
        // check a client could vote on a submission from a different
        // contest while pointed at this one, corrupting both tallies.
        if (get_post_type($sid) !== 'bh_submission' || (int) get_post_meta($sid, '_bh_contest_id', true) !== $cid) {
            return self::err('bad', 'That track does not belong to this contest.', 400);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s AND submission_id = %d",
            $uid, $cid, $cat, $sid
        ));

        // Toggle off.
        if ($existing) {
            $wpdb->delete($t, ['id' => $existing], ['%d']);
            return self::ok([
                'action'     => 'removed',
                'category'   => $cat,
                'votes_left' => max(0, $limit - BH_Helpers::user_vote_count($uid, $cid, $cat)),
                'limit'      => $limit,
            ]);
        }

        // Toggle on — count + insert inside a transaction so two fast clicks
        // can't both slip past the limit (InnoDB row/gap lock via FOR UPDATE).
        $wpdb->query('START TRANSACTION');
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s FOR UPDATE",
            $uid, $cid, $cat
        ));

        if ($used >= $limit) {
            $wpdb->query('ROLLBACK');
            $msg = $limit === 1
                ? 'You have used your vote in this category. Submit a track to unlock a second, or tap your pick to switch.'
                : 'You have used both your votes in this category. Tap one of your picks to free a vote, then choose again.';
            return self::err('limit', $msg, 403, ['votes_left' => 0, 'limit' => $limit]);
        }

        $wpdb->insert($t, ['user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'submission_id' => $sid], ['%d', '%d', '%s', '%d']);
        $wpdb->query('COMMIT');

        return self::ok([
            'action'     => 'added',
            'category'   => $cat,
            'votes_left' => max(0, $limit - $used - 1),
            'limit'      => $limit,
        ]);
    }

    public static function submit($req) {
        $uid = get_current_user_id();
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
        // present in THIS request gets written (see BH_Profiles::save).
        $profile_fields = BH_Profiles::from_request($req);
        if ($profile_fields) BH_Profiles::save($uid, $profile_fields);

        $missing = BH_Profiles::missing_for_submission($uid);
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

        $pid = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'bh_submission',
            'post_status' => 'pending',
            'post_author' => $uid,
        ], true);
        if (is_wp_error($pid)) return self::err('save', 'Could not save your submission.', 500);

        update_post_meta($pid, '_bh_contest_id', $cid);
        update_post_meta($pid, '_bh_artist_name', sanitize_text_field($req->get_param('artist')));
        update_post_meta($pid, '_bh_admin_note', sanitize_textarea_field($req->get_param('note')));

        $aid = media_handle_sideload($f['audio'], $pid);
        if (is_wp_error($aid)) {
            wp_delete_post($pid, true); // roll back so a bad upload doesn't lock the user out
            return self::err('upload', 'We could not process that audio file. Please try another.', 400);
        }
        update_post_meta($pid, '_bh_audio_id', $aid);
        return self::ok();
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

        $out = [];
        foreach ($cats as $c) {
            $out[] = [
                'slug'    => $c['slug'],
                'name'    => $c['name'],
                'results' => self::category_results($cid, $c['slug']),
            ];
        }
        return self::ok(['contest' => get_the_title($cid), 'categories' => $out]);
    }

    private static function category_results($cid, $category) {
        global $wpdb;
        $t    = BH_Helpers::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, COUNT(id) votes FROM $t
             WHERE contest_id = %d AND category = %s GROUP BY submission_id ORDER BY votes DESC LIMIT 10",
            $cid, $category
        ));

        $out = [];
        $rank = 0;
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p || $p->post_status !== 'publish') continue; // skip pending/deleted
            $out[] = [
                'rank'   => ++$rank,
                'id'     => (int) $r->submission_id,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'votes'  => (int) $r->votes,
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

        $out = [];
        $rank = 0;
        $top = !empty($rows) ? max(1, (int) $rows[0]->votes) : 1;
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p) continue;
            $votes = (int) $r->votes;
            $row = [
                'rank'   => ++$rank,
                'id'     => (int) $r->submission_id,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'status' => $p->post_status,
                'votes'  => $votes,
                'plays'  => (int) get_post_meta($p->ID, '_bh_play_count', true),
                'pct'    => round(($votes / $top) * 100, 1),
            ];
            if ($all) $row['category'] = $r->category === '' ? '—' : ($cat_names[$r->category] ?? $r->category);
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
