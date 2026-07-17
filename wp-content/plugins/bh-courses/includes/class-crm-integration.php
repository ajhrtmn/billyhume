<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional, one-directional enrichment of bh-crm's person detail view —
 * the exact filter contract bh-crm's own class-people.php docblock
 * documents for any third party to use, with zero changes needed to
 * bh-crm itself. Registering these filters is harmless even if bh-crm
 * is never installed (an add_filter() on a filter nobody applies just
 * sits unused), so this stays a genuine peer relationship, not a
 * dependency.
 *
 * Also the first real consumer of `bhc_course_completed` (see
 * class-progress.php) — a completed course becomes an activity line on
 * that student's CRM detail page. Naming this the "first" consumer
 * deliberately: a certificate email/PDF, a supporter-tier upsell
 * nudge, etc. are all future listeners on the same action, not
 * something this class needs to know about.
 */
class BHC_CrmIntegration {
    public static function init() {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    // Anyone with course activity qualifies for the CRM person list even
    // without profile data filled in — same reasoning bh-contest's own
    // filter callback uses for voters.
    public static function active_user_ids($ids) {
        global $wpdb;
        return array_merge($ids, $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}bhc_progress"));
    }

    public static function activity_summary($sections, $user_id) {
        global $wpdb;
        $completed = $wpdb->get_results($wpdb->prepare(
            // id DESC as a tiebreaker — completed_at only has 1-second
            // resolution, and a bulk/legacy import of completion records
            // could plausibly land several in the same second; this
            // list is read top-to-bottom in the CRM activity summary,
            // same class of bug already caught and fixed in bh-crm's
            // own notes feature and bh-contest's vote export.
            "SELECT course_id, completed_at FROM {$wpdb->prefix}bhc_completions WHERE user_id = %d ORDER BY completed_at DESC, id DESC",
            $user_id
        ), ARRAY_A);

        $in_progress_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT lesson_id) FROM {$wpdb->prefix}bhc_progress WHERE user_id = %d", $user_id
        ));

        if (!$completed && !$in_progress_count) return $sections;

        $summary_bits = [];
        if ($completed) $summary_bits[] = count($completed) . ' course(s) completed';
        if ($in_progress_count) $summary_bits[] = $in_progress_count . ' lesson(s) with recorded activity';

        $sections[] = [
            'plugin' => 'BH Courses',
            'summary' => implode(', ', $summary_bits),
            'render' => function () use ($completed, $user_id) {
                self::render_detail($user_id, $completed);
            },
        ];
        return $sections;
    }

    private static function render_detail($user_id, $completed) {
        echo '<div class="bhy-table-wrap">';
        echo '<table class="widefat striped"><thead><tr><th>Course</th><th>Progress</th><th>Completed</th></tr></thead><tbody>';
        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);
        $completed_ids = array_column($completed, 'completed_at', 'course_id');
        foreach ($courses as $course) {
            $percent = BHC_Progress::course_percent($user_id, $course->ID);
            if (!$percent && !isset($completed_ids[$course->ID])) continue; // no activity on this course at all — skip
            echo '<tr><td>' . esc_html($course->post_title) . '</td><td>' . (int) $percent . '%</td><td>'
               . (isset($completed_ids[$course->ID]) ? esc_html(mysql2date(get_option('date_format'), $completed_ids[$course->ID])) : '&#8212;')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

// A completed course landing as a CRM activity line is driven by the
// row bhc_completions already holds (activity_summary() above reads it
// directly) — no separate listener needed just to persist the fact of
// completion, since class-progress.php's own INSERT IGNORE already is
// that persistence.
//
// This IS, though, the first real consumer of the notification system
// (own-ur-shit's OUS_Notifications — see that class's docblock): a
// student finishing a course now actually gets told about it, in-app
// and by email, with one call and zero new infrastructure of this
// plugin's own. class_exists()-guarded since notifications shipped in
// core version 3.2.0 — an ecosystem running an older core still works
// fine, it just doesn't get this particular notification.
add_action('bhc_course_completed', function ($user_id, $course_id) {
    if (!class_exists('OUS_Notifications')) return;
    $course = get_post($course_id);
    if (!$course) return;

    OUS_Notifications::notify(
        $user_id,
        'course_completed',
        'Course complete: ' . $course->post_title,
        'You finished every lesson in "' . $course->post_title . '". Nice work.',
        get_permalink($course_id),
        'BH Courses'
    );
}, 10, 2);

// The enrollment-side counterpart to the completion notification above
// — same pattern, same class_exists() guard. Previously a student got
// zero confirmation that enrolling worked, only silent access; this is
// the first thing they hear from the course.
add_action('bhc_enrolled', function ($user_id, $course_id) {
    if (!class_exists('OUS_Notifications')) return;
    $course = get_post($course_id);
    if (!$course) return;

    OUS_Notifications::notify(
        $user_id,
        'course_enrolled',
        'Enrolled: ' . $course->post_title,
        'You\'re in — start whenever you\'re ready.',
        get_permalink($course_id),
        'BH Courses'
    );
}, 10, 2);

// First real bh-courses contributor to the shared Metrics dashboard
// (own-ur-shit's OUS_Metrics, class-metrics.php) — AJ's own ask to
// build this in tandem with the LMS/contest hardening rather than as a
// later bolt-on. Two cards from BH_Event data (already flowing —
// bhc/enroll and bhc/course_completed have been real, live events
// since earlier this session, not new instrumentation added just for
// this), one from a direct bhc_progress query for the metric BH_Event
// doesn't carry (score isn't part of the enroll/complete event
// payloads, and re-deriving it from events would just be a slower,
// more roundabout version of the same query bhc_progress already
// answers directly).
add_filter('bhcore_metrics_widgets', function ($widgets) {
    if (!class_exists('OUS_Metrics')) return $widgets;

    $widgets[] = ['source' => 'BH Courses', 'render' => function () {
        $trend = OUS_Metrics::event_trend('bhc/enroll', 30);
        OUS_Metrics::render_card('Enrollments', array_sum($trend), 'Last 30 days', $trend);
    }];
    $widgets[] = ['source' => 'BH Courses', 'render' => function () {
        $trend = OUS_Metrics::event_trend('bhc/course_completed', 30);
        OUS_Metrics::render_card('Course completions', array_sum($trend), 'Last 30 days', $trend);
    }];
    $widgets[] = ['source' => 'BH Courses', 'render' => function () {
        global $wpdb;
        // Only rows with a real score (quiz steps) — plain text/image
        // steps store score=NULL (see class-progress.php's own
        // NULL-vs-placeholder handling), which would silently drag a
        // naive AVG() toward 0 if included.
        $avg = $wpdb->get_var(
            "SELECT AVG(score) FROM {$wpdb->prefix}bhc_progress WHERE score IS NOT NULL AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        OUS_Metrics::render_card('Avg. quiz score', $avg !== null ? round($avg) . '%' : '—', 'Last 30 days, all courses');
    }];
    return $widgets;
});
