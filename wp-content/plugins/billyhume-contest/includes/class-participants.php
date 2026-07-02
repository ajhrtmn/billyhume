<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only aggregation of who's participating, built entirely from data
 * we already have: WordPress's own wp_users table (name, email, registered
 * date — no duplicated identity data) plus our votes table and
 * bh_submission posts. No new database tables, no editable CRM fields —
 * just visibility into what's already there.
 */
class BH_Participants {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);

        // A couple of extra columns on the native Users list so the
        // aggregate numbers are visible without leaving a screen that's
        // already part of the normal WordPress workflow.
        add_filter('manage_users_columns', [self::class, 'user_columns']);
        add_filter('manage_users_custom_column', [self::class, 'user_column_content'], 10, 3);
    }

    public static function add_menu() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Participants', 'Participants', 'manage_options', 'bh-participants',
            [self::class, 'render']
        );
    }

    /* ================= Users list columns ================= */

    public static function user_columns($cols) {
        $cols['bh_submissions'] = 'Submissions';
        $cols['bh_votes']       = 'Votes';
        return $cols;
    }

    public static function user_column_content($output, $col, $user_id) {
        if ($col === 'bh_submissions') {
            $n = count(get_posts(['post_type' => 'bh_submission', 'author' => $user_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
            return $n ? '<a href="' . esc_url(self::detail_url($user_id)) . '">' . $n . '</a>' : '0';
        }
        if ($col === 'bh_votes') {
            $n = BH_Helpers::user_total_votes($user_id);
            return $n ? '<a href="' . esc_url(self::detail_url($user_id)) . '">' . $n . '</a>' : '0';
        }
        return $output;
    }

    private static function detail_url($user_id) {
        return admin_url(BH_PostTypes::MENU_PARENT . '&page=bh-participants&user_id=' . (int) $user_id);
    }

    /* ================= Page ================= */

    public static function render() {
        $uid = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        echo '<div class="wrap"><h1>Participants</h1>';
        $uid ? self::render_detail($uid) : self::render_list();
        echo '</div>';
    }

    private static function render_list() {
        global $wpdb;
        $table = BH_Helpers::table();

        $sort_map   = ['name' => 'u.display_name', 'email' => 'u.user_email', 'submissions' => 'sub_count', 'contests' => 'contest_count', 'votes' => 'vote_count', 'registered' => 'u.user_registered'];
        $orderby_key = sanitize_key($_GET['orderby'] ?? 'votes');
        $orderby    = $sort_map[$orderby_key] ?? 'vote_count';
        $order      = (($_GET['order'] ?? '') === 'asc') ? 'ASC' : 'DESC';

        $rows = $wpdb->get_results(
            "SELECT u.ID, u.display_name, u.user_email, u.user_registered,
                COALESCE(v.vote_count, 0) AS vote_count,
                COALESCE(v.contest_count, 0) AS contest_count,
                COALESCE(s.sub_count, 0) AS sub_count
             FROM {$wpdb->users} u
             LEFT JOIN (
                 SELECT user_id, COUNT(*) vote_count, COUNT(DISTINCT contest_id) contest_count
                 FROM $table GROUP BY user_id
             ) v ON v.user_id = u.ID
             LEFT JOIN (
                 SELECT post_author, COUNT(*) sub_count
                 FROM {$wpdb->posts} WHERE post_type = 'bh_submission' AND post_status != 'trash'
                 GROUP BY post_author
             ) s ON s.post_author = u.ID
             WHERE COALESCE(v.vote_count, 0) > 0 OR COALESCE(s.sub_count, 0) > 0
             ORDER BY $orderby $order
             LIMIT 300"
        );

        if (!$rows) {
            echo '<p>No one has voted or submitted a track yet. This list fills in as soon as they do — nothing to configure.</p>';
            return;
        }

        echo '<p>Anyone who has cast a vote or submitted a track, across every contest. Click a name for their full activity. Capped at the 300 most active.</p>';

        $sort_link = function ($key, $label) use ($orderby_key, $order) {
            $next  = ($orderby_key === $key && $order === 'DESC') ? 'asc' : 'desc';
            $url   = add_query_arg(['orderby' => $key, 'order' => $next]);
            $arrow = $orderby_key === $key ? ($order === 'DESC' ? ' ↓' : ' ↑') : '';
            echo '<th><a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a></th>';
        };

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        $sort_link('name', 'Name');
        $sort_link('email', 'Email');
        $sort_link('submissions', 'Submissions');
        $sort_link('contests', 'Contests Voted In');
        $sort_link('votes', 'Total Votes');
        $sort_link('registered', 'Registered');
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr>'
               . '<td><a href="' . esc_url(self::detail_url($r->ID)) . '"><strong>' . esc_html($r->display_name) . '</strong></a></td>'
               . '<td>' . esc_html($r->user_email) . '</td>'
               . '<td>' . (int) $r->sub_count . '</td>'
               . '<td>' . (int) $r->contest_count . '</td>'
               . '<td>' . (int) $r->vote_count . '</td>'
               . '<td>' . esc_html(mysql2date('M j, Y', $r->user_registered)) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table>';
    }

    // Real name / platform handles / consent flags, admin-only. Never
    // exposed anywhere public — see BH_Profiles for that guarantee.
    private static function render_profile($uid) {
        $p = BH_Profiles::get($uid);
        $rows = [
            ['Real name', $p['real_name'], $p['real_name_public']],
            ['Discord',   $p['discord_name'], $p['discord_public']],
            ['Twitch',    $p['twitch_name'], $p['twitch_public']],
            ['YouTube',   $p['youtube_name'], $p['youtube_public']],
        ];
        $rows = array_filter($rows, fn($r) => $r[1] !== '');

        echo '<h3>Fan Profile</h3>';
        if (!$rows && !$p['typical_platform']) {
            echo '<p><em>No profile data collected yet.</em></p>';
            return;
        }
        if (!$rows) {
            echo '<p><em>No names on file yet.</em></p>';
        } else {
            echo '<table class="wp-list-table widefat striped"><thead><tr><th>Field</th><th>Value</th><th>Consent to share</th></tr></thead><tbody>';
            foreach ($rows as [$label, $value, $public]) {
                echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($value) . '</td>'
                   . '<td>' . ($public ? '&#10003; OK to share' : 'Keep private') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if ($p['typical_platform']) {
            echo '<p><strong>Usually watches on:</strong> ' . esc_html(ucfirst($p['typical_platform'])) . '</p>';
        }
    }

    private static function render_detail($uid) {
        $user = get_userdata($uid);
        if (!$user) { echo '<p>User not found.</p>'; return; }

        echo '<p><a href="' . esc_url(remove_query_arg('user_id')) . '">&larr; All participants</a></p>';
        echo '<h2>' . esc_html($user->display_name) . '</h2>';
        echo '<p>' . esc_html($user->user_email) . ' &middot; Registered ' . esc_html(mysql2date('M j, Y', $user->user_registered))
           . ' &middot; <a href="' . esc_url(get_edit_user_link($uid)) . '">Edit WordPress profile</a></p>';

        self::render_profile($uid);

        // Submissions
        $subs = get_posts(['post_type' => 'bh_submission', 'author' => $uid, 'post_status' => 'any', 'posts_per_page' => -1]);
        echo '<h3>Submissions (' . count($subs) . ')</h3>';
        if (!$subs) {
            echo '<p><em>None.</em></p>';
        } else {
            echo '<table class="wp-list-table widefat striped"><thead><tr><th>Title</th><th>Contest</th><th>Status</th><th>Plays</th></tr></thead><tbody>';
            foreach ($subs as $p) {
                $cid   = (int) get_post_meta($p->ID, '_bh_contest_id', true);
                $cname = ($cid && get_post($cid)) ? get_the_title($cid) : '—';
                echo '<tr>'
                   . '<td><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title) . '</a></td>'
                   . '<td>' . esc_html($cname) . '</td>'
                   . '<td>' . esc_html(ucfirst($p->post_status)) . '</td>'
                   . '<td>' . (int) get_post_meta($p->ID, '_bh_play_count', true) . '</td>'
                   . '</tr>';
            }
            echo '</tbody></table>';
        }

        // Votes, grouped by contest
        global $wpdb;
        $votes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BH_Helpers::table() . " WHERE user_id = %d ORDER BY contest_id, created_at DESC", $uid
        ));
        echo '<h3>Votes (' . count($votes) . ')</h3>';
        if (!$votes) { echo '<p><em>None.</em></p>'; return; }

        $grouped = [];
        foreach ($votes as $v) $grouped[(int) $v->contest_id][] = $v;

        foreach ($grouped as $cid => $vs) {
            $title = get_post($cid) ? get_the_title($cid) : "Contest #$cid";
            echo '<h4>' . esc_html($title) . ' (' . count($vs) . ' vote' . (count($vs) === 1 ? '' : 's') . ')</h4>';

            $cat_names = [];
            foreach (BH_Helpers::categories($cid) as $c) $cat_names[$c['slug']] = $c['name'];

            echo '<table class="wp-list-table widefat striped"><thead><tr><th>Track</th><th>Category</th><th>When</th></tr></thead><tbody>';
            foreach ($vs as $v) {
                $track    = get_post($v->submission_id);
                $cat_name = $v->category === '' ? '—' : ($cat_names[$v->category] ?? $v->category);
                echo '<tr>'
                   . '<td>' . ($track ? esc_html($track->post_title) : '<em>deleted track</em>') . '</td>'
                   . '<td>' . esc_html($cat_name) . '</td>'
                   . '<td>' . esc_html(mysql2date('M j, Y g:ia', $v->created_at)) . '</td>'
                   . '</tr>';
            }
            echo '</tbody></table>';
        }
    }
}
