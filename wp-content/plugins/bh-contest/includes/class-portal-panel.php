<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's `bhi_portal_panels`
 * filter — see class-portal.php over there) — "access and edit their
 * contest submissions" from AJ's own ask. Lists every bh_submission post
 * authored by the current user (across every contest, not just the
 * active one), with its status and live vote count, and a real edit link
 * for anything still in a submittable state.
 */
class BH_PortalPanel {
    public static function init() {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
    }

    public static function register_panel($panels) {
        $panels[] = [
            'id' => 'submissions',
            'label' => 'Contest Submissions',
            'icon' => 'dashicons-microphone',
            'render' => [self::class, 'render'],
            'priority' => 40,
        ];
        return $panels;
    }

    private static function vote_count_for($submission_id) {
        global $wpdb;
        $t = class_exists('BH_Helpers') ? BH_Helpers::table() : $wpdb->prefix . 'bh_votes';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE submission_id = %d", $submission_id));
    }

    public static function render() {
        $user_id = get_current_user_id();
        echo '<h1>Contest Submissions</h1>';

        $submissions = get_posts([
            'post_type' => 'bh_submission',
            'author' => $user_id,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!$submissions) {
            echo '<p>You haven\'t submitted anything to a contest yet.</p>';
            return;
        }

        echo '<table class="bhi-portal-table"><thead><tr><th>Submission</th><th>Contest</th><th>Status</th><th>Votes</th><th></th></tr></thead><tbody>';
        foreach ($submissions as $sub) {
            $contest_id = (int) get_post_meta($sub->ID, '_bh_contest_id', true);
            $contest = $contest_id ? get_post($contest_id) : null;
            $votes = self::vote_count_for($sub->ID);

            $status_label = ucfirst($sub->post_status);
            $editable = in_array($sub->post_status, ['draft', 'pending'], true);

            echo '<tr>';
            echo '<td>' . esc_html($sub->post_title ?: '(untitled)') . '</td>';
            echo '<td>' . esc_html($contest ? $contest->post_title : '(unknown contest)') . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td>' . (int) $votes . '</td>';
            echo '<td>';
            // NOTE: deliberately NOT linking to get_edit_post_link() here.
            // That resolves to a wp-admin post.php URL, and contestants
            // hold the subscriber role — already excluded from wp-admin
            // by BH_Admin::restrict_dashboard_access() before this pass,
            // and now doubly so by BHI_Portal's hard lockout. No front-end
            // submission-edit shortcode/form exists anywhere in this
            // plugin today (checked — bh-contest has no [bh_submit]-style
            // form), so "editable" submissions currently have no real
            // edit path a contestant can actually reach. Surfacing that
            // honestly here rather than linking to a dead end; a real
            // front-end edit form is a genuine gap for a follow-up pass,
            // not something this handoff invented a fake fix for.
            if ($editable) {
                echo '<span class="description">Editing needs a front-end submission form (not built yet) — contact the organizer to make changes.</span>';
            } else {
                echo '<a class="button" href="' . esc_url(get_permalink($sub->ID)) . '">View</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
