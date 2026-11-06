<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional enrichment of bh-crm's person view via its documented filters; harmless if
 * bh-crm isn't installed. Also sends the course-completed / enrolled notifications and
 * contributes Metrics cards.
 */
class BHC_CrmIntegration {
    public static function init(): void {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    // Anyone with course activity qualifies for the CRM person list.
    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public static function active_user_ids($ids): array {
        global $wpdb;
        return array_merge($ids, $wpdb->get_col("SELECT DISTINCT user_id FROM " . BHC_Tables::progress()));
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<int, array<string, mixed>>
     */
    public static function activity_summary($sections, int $user_id): array {
        global $wpdb;
        $completed = $wpdb->get_results($wpdb->prepare(
            // id DESC tiebreak: completed_at has 1-second resolution.
            "SELECT course_id, completed_at FROM " . BHC_Tables::completions() . " WHERE user_id = %d ORDER BY completed_at DESC, id DESC",
            $user_id
        ), ARRAY_A);

        $in_progress_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT lesson_id) FROM " . BHC_Tables::progress() . " WHERE user_id = %d", $user_id
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

    /** @param array<int, array<string, mixed>> $completed */
    private static function render_detail(int $user_id, $completed): void {
        echo '<div class="bhy-table-wrap">';
        echo '<table class="widefat striped"><thead><tr><th>Course</th><th>Progress</th><th>Completed</th></tr></thead><tbody>';
        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);
        $completed_ids = array_column($completed, 'completed_at', 'course_id');
        foreach ($courses as $course) {
            $percent = BHC_Progress::course_percent($user_id, $course->ID);
            if (!$percent && !isset($completed_ids[$course->ID])) continue; // no activity on this course
            echo '<tr><td>' . esc_html($course->post_title) . '</td><td>' . (int) $percent . '%</td><td>'
               . (isset($completed_ids[$course->ID]) ? esc_html(mysql2date(get_option('date_format'), $completed_ids[$course->ID])) : '&#8212;')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

// Course-completed notification (in-app + email) via OUS_Notifications, when available.
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

// Enrollment confirmation notification.
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

// Metrics cards: two from BH_Event data, one quiz-score average straight from bhc_progress.
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
        // Only rows with a real score; non-quiz NULLs would drag AVG() toward 0.
        $avg = $wpdb->get_var(
            "SELECT AVG(score) FROM " . BHC_Tables::progress() . " WHERE score IS NOT NULL AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        OUS_Metrics::render_card('Avg. quiz score', $avg !== null ? round($avg) . '%' : '—', 'Last 30 days, all courses');
    }];
    return $widgets;
});
