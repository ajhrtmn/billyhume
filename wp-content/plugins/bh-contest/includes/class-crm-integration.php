<?php
if (!defined('ABSPATH')) exit;

/**
 * Adds contest activity to BH CRM's person view. Optional both ways: the filters
 * simply go unused when BH CRM is absent.
 */
class BH_CRMIntegration {
    public static function init(): void {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public static function active_user_ids($ids): array {
        global $wpdb;
        $voters = $wpdb->get_col("SELECT DISTINCT user_id FROM " . BH_Helpers::table());
        $submitters = $wpdb->get_col("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'bh_submission' AND post_status != 'trash'");
        return array_merge($ids, $voters, $submitters);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<int, array<string, mixed>>
     */
    public static function activity_summary($sections, int $user_id): array {
        $votes = (int) BH_Helpers::user_total_votes($user_id);
        $sub_count = count(get_posts(['post_type' => 'bh_submission', 'author' => $user_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
        if (!$votes && !$sub_count) return $sections;

        $sections[] = [
            'plugin'  => BHCON_Words::get('admin.admin_reports.bh_contest'),
            'summary' => "$sub_count submission" . ($sub_count === 1 ? '' : 's') . ", $votes vote" . ($votes === 1 ? '' : 's'),
            'render'  => fn() => self::render_detail($user_id),
        ];
        return $sections;
    }

    private static function render_detail(int $uid): void {
        $subs = get_posts(['post_type' => 'bh_submission', 'author' => $uid, 'post_status' => 'any', 'posts_per_page' => -1]);
        if ($subs) {
            echo '<div class="bhy-table-wrap">';
            echo ('<table class="wp-list-table widefat striped"><thead><tr><th>' . BHCON_Words::get('admin.crm_integration.title') . '</th><th>' . BHCON_Words::get('admin.crm_integration.contest') . '</th><th>' . BHCON_Words::get('admin.console.status') . '</th><th>' . BHCON_Words::get('admin.admin_reports.plays') . '</th></tr></thead><tbody>');
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
            echo ('<table class="wp-list-table widefat striped"><thead><tr><th>' . BHCON_Words::get('admin.console.track') . '</th><th>' . BHCON_Words::get('admin.admin_reports.category_3') . '</th><th>' . BHCON_Words::get('admin.crm_integration.when') . '</th></tr></thead><tbody>');
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

// Metrics dashboard cards, aggregated from the bh/vote and bh/submission_created events.
add_filter('bhcore_metrics_widgets', function ($widgets) {
    if (!class_exists('OUS_Metrics')) return $widgets;

    $widgets[] = ['source' => BHCON_Words::get('admin.admin_reports.bh_contest'), 'render' => function () {
        $trend = OUS_Metrics::event_trend('bh/submission_created', 30);
        OUS_Metrics::render_card('Submissions', array_sum($trend), BHCON_Words::get('admin.crm_integration.last_30_days'), $trend);
    }];
    $widgets[] = ['source' => BHCON_Words::get('admin.admin_reports.bh_contest'), 'render' => function () {
        $trend = OUS_Metrics::event_trend('bh/vote', 30);
        OUS_Metrics::render_card(BHCON_Words::get('admin.crm_integration.votes_cast'), array_sum($trend), BHCON_Words::get('admin.crm_integration.last_30_days_includes_un'), $trend);
    }];
    return $widgets;
});
