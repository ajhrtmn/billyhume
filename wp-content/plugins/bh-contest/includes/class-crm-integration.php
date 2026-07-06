<?php
if (!defined('ABSPATH')) exit;

/**
 * Optionally enriches the BH CRM plugin's person view with this
 * plugin's own activity data — entirely one-directional and entirely
 * optional. If BH CRM isn't installed, these add_filter() calls just
 * sit unused; nothing in this plugin needs BH CRM to exist, and BH CRM
 * never needs this plugin to exist either. This replaces what used to
 * be a whole separate Participants admin page — same underlying data,
 * now surfaced through the CRM's shared person view instead of a
 * second, contest-specific one.
 */
class BH_CRMIntegration {
    public static function init() {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    public static function active_user_ids($ids) {
        global $wpdb;
        $voters = $wpdb->get_col("SELECT DISTINCT user_id FROM " . BH_Helpers::table());
        $submitters = $wpdb->get_col("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'bh_submission' AND post_status != 'trash'");
        return array_merge($ids, $voters, $submitters);
    }

    public static function activity_summary($sections, $user_id) {
        $votes = (int) BH_Helpers::user_total_votes($user_id);
        $sub_count = count(get_posts(['post_type' => 'bh_submission', 'author' => $user_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
        if (!$votes && !$sub_count) return $sections;

        $sections[] = [
            'plugin'  => 'BH Contest',
            'summary' => "$sub_count submission" . ($sub_count === 1 ? '' : 's') . ", $votes vote" . ($votes === 1 ? '' : 's'),
            'render'  => fn() => self::render_detail($user_id),
        ];
        return $sections;
    }

    private static function render_detail($uid) {
        $subs = get_posts(['post_type' => 'bh_submission', 'author' => $uid, 'post_status' => 'any', 'posts_per_page' => -1]);
        if ($subs) {
            echo '<div class="bhy-table-wrap">';
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
            echo '</tbody></table></div>';
        }

        global $wpdb;
        $votes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BH_Helpers::table() . " WHERE user_id = %d ORDER BY contest_id, created_at DESC", $uid
        ));
        if (!$votes) return;

        $grouped = [];
        foreach ($votes as $v) $grouped[(int) $v->contest_id][] = $v;

        foreach ($grouped as $cid => $vs) {
            $title = get_post($cid) ? get_the_title($cid) : "Contest #$cid";
            echo '<p><strong>' . esc_html($title) . '</strong> (' . count($vs) . ' vote' . (count($vs) === 1 ? '' : 's') . ')</p>';

            $cat_names = [];
            foreach (BH_Helpers::categories($cid) as $c) $cat_names[$c['slug']] = $c['name'];

            echo '<div class="bhy-table-wrap">';
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
            echo '</tbody></table></div>';
        }
    }
}
