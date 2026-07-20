<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-admin.php (DRY/SOLID audit Phase 3b) — the
 * wp-admin list-table columns for the bh_contest and bh_submission post
 * lists (status pills, shortcode, page links, quick stats, contest
 * filter dropdown). No settings or moderation logic here.
 */
class BH_AdminListTables {
    public static function init() {
        // Contest list table: status pill, copyable shortcode, quick stats.
        add_filter('manage_bh_contest_posts_columns', [self::class, 'contest_columns']);
        add_action('manage_bh_contest_posts_custom_column', [self::class, 'contest_column_content'], 10, 2);

        // Submissions list: which contest each one belongs to, plus a filter
        // dropdown — the flat approval queue is unreadable once more than
        // one contest has submissions in it.
        add_filter('manage_bh_submission_posts_columns', [self::class, 'submission_columns']);
        add_action('manage_bh_submission_posts_custom_column', [self::class, 'submission_column_content'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'submission_contest_filter']);
        add_action('pre_get_posts', [self::class, 'apply_submission_contest_filter']);
    }

    /* ================= Contest list table ================= */

    public static function contest_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['bh_status']    = 'Status';
                $new['bh_shortcode'] = 'Shortcode';
                $new['bh_page']      = 'Page';
                $new['bh_stats']     = 'Submissions / Votes';
            }
        }
        return $new;
    }

    public static function contest_column_content($col, $post_id) {
        if ($col === 'bh_status') {
            // Submissions pill — "unscheduled" here genuinely means
            // "always open" (see BH_Helpers::is_submission_open), so it's
            // shown as green "Open", not the gray/ambiguous label voting
            // uses for the same raw status string.
            $sub = BH_Helpers::submission_status($post_id);
            $sub_map   = ['open' => '#1DB954', 'unscheduled' => '#1DB954', 'upcoming' => '#8a8a8a', 'closed' => '#b3261e'];
            $sub_label = ['open' => 'Open', 'unscheduled' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed'];
            echo '<div style="margin-bottom:4px;"><span style="display:inline-block;width:62px;font-size:10px;color:#787c82;text-transform:uppercase;">Submit</span> '
               . '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . $sub_map[$sub] . ';color:#fff;font-size:11px;font-weight:600;">' . esc_html($sub_label[$sub]) . '</span></div>';

            $s = BH_Helpers::contest_status($post_id);
            $map   = ['open' => '#1DB954', 'upcoming' => '#8a8a8a', 'closed' => '#b3261e', 'unscheduled' => '#8a8a8a'];
            $label = ['open' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed', 'unscheduled' => 'Not scheduled'];
            echo '<div><span style="display:inline-block;width:62px;font-size:10px;color:#787c82;text-transform:uppercase;">Vote</span> '
               . '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . $map[$s] . ';color:#fff;font-size:11px;font-weight:600;">' . esc_html($label[$s]) . '</span>';

            // One-click override so switching a contest's phase doesn't
            // require opening the edit screen and hand-editing date pickers.
            // Plain links, not forms — this cell sits inside the list
            // table's own <form>, and nested <form> elements are invalid
            // HTML that browsers "fix" by hijacking the outer submit.
            $quick = function ($which, $text) use ($post_id) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=bh_quick_schedule&contest_id=' . (int) $post_id . '&which=' . $which),
                    'bh_quick_schedule'
                );
                echo ' <a href="' . esc_url($url) . '" style="font-size:11px;">' . esc_html($text) . '</a>';
            };
            if ($s === 'upcoming' || $s === 'unscheduled') $quick('start', 'Start now');
            if ($s === 'open') $quick('end', 'End now');
            echo '</div>';
        }
        if ($col === 'bh_shortcode') {
            $sc = BH_Helpers::shortcode_for($post_id);
            echo '<code style="user-select:all;font-size:12px;cursor:text;" title="Click and copy">' . esc_html($sc) . '</code>';
        }
        if ($col === 'bh_page') {
            echo BH_AdminMenus::page_links_html($post_id);
        }
        if ($col === 'bh_stats') {
            $subs  = BH_Helpers::submission_count($post_id);
            $votes = BH_Helpers::vote_count($post_id);
            $url   = admin_url(BH_PostTypes::MENU_PARENT . '&page=bh-results&contest_id=' . $post_id);
            echo esc_html($subs) . ' subs · <a href="' . esc_url($url) . '">' . esc_html($votes) . ' votes</a>';
        }
    }

    /* ================= Submissions list table ================= */

    public static function submission_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['bh_contest'] = 'Contest';
                $new['bh_notes']   = 'Notes';
            }
        }
        return $new;
    }

    public static function submission_column_content($col, $post_id) {
        if ($col === 'bh_contest') {
            $cid = (int) get_post_meta($post_id, '_bh_contest_id', true);
            if (!$cid || !get_post($cid)) { echo '<em>—</em>'; return; }
            $url = get_edit_post_link($cid);
            echo $url ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title($cid)) . '</a>' : esc_html(get_the_title($cid));
        }
        if ($col === 'bh_notes') {
            $note = get_post_meta($post_id, '_bh_admin_note', true);
            if ($note === '') { echo '<em>—</em>'; return; }
            // Full text on hover via title attribute — no need to open the
            // submission just to read a note during a live contest stream.
            echo '<span title="' . esc_attr($note) . '">' . esc_html(wp_html_excerpt($note, 60, '…')) . '</span>';
        }
    }

    public static function submission_contest_filter($post_type) {
        if ($post_type !== 'bh_submission') return;
        $contests = BH_Helpers::all_contests();
        if (!$contests) return;
        $current = isset($_GET['bh_contest_filter']) ? (int) $_GET['bh_contest_filter'] : 0;
        echo '<select name="bh_contest_filter"><option value="">All contests</option>';
        foreach ($contests as $c) {
            echo '<option value="' . (int) $c->ID . '" ' . selected($current, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_submission_contest_filter($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'bh_submission') return;
        if (empty($_GET['bh_contest_filter'])) return;

        $query->set('meta_query', [[
            'key'   => '_bh_contest_id',
            'value' => (int) $_GET['bh_contest_filter'],
        ]]);
    }
}
