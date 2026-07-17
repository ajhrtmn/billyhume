<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's `bhi_portal_panels`
 * filter — see class-portal.php over there) — "access... their LMS stuff"
 * from AJ's own ask. Lists every course the current user is actually
 * enrolled in (bhc_enrollments — see BHC_Progress::enroll_if_needed()),
 * not every published course, with a real completion percent per course
 * and a direct link back into the course itself to continue.
 */
class BHC_PortalPanel {
    public static function init() {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
    }

    public static function register_panel($panels) {
        $panels[] = [
            'id' => 'courses',
            'label' => 'My Courses',
            'icon' => 'dashicons-welcome-learn-more',
            'render' => [self::class, 'render'],
            'priority' => 30,
        ];
        return $panels;
    }

    private static function enrolled_course_ids($user_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT course_id FROM {$wpdb->prefix}bhc_enrollments WHERE user_id = %d ORDER BY enrolled_at DESC",
            $user_id
        ));
    }

    public static function render() {
        $user_id = get_current_user_id();
        echo '<h1>My Courses</h1>';

        if (!class_exists('BHC_Progress')) {
            echo '<p>Course progress is unavailable right now.</p>';
            return;
        }

        $course_ids = self::enrolled_course_ids($user_id);
        if (!$course_ids) {
            echo '<p>You\'re not enrolled in any courses yet.</p>';
            return;
        }

        echo '<div class="bhi-portal-course-list">';
        foreach ($course_ids as $course_id) {
            $course = get_post($course_id);
            if (!$course || $course->post_status !== 'publish') continue;

            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $completed = class_exists('BHC_Progress') && method_exists('BHC_Progress', 'is_course_completed')
                ? BHC_Progress::is_course_completed($user_id, $course_id) : ($percent >= 100);

            echo '<div class="bhi-portal-course-card">';
            echo '<h3>' . esc_html($course->post_title) . '</h3>';
            echo '<div class="bhi-portal-progress-bar"><div class="bhi-portal-progress-fill" style="width:' . (int) $percent . '%;"></div></div>';
            echo '<p>' . (int) $percent . '% complete' . ($completed ? ' — <strong>Completed</strong>' : '') . '</p>';
            echo '<p><a class="button" href="' . esc_url(get_permalink($course_id)) . '">' . ($completed ? 'Review' : 'Continue') . '</a></p>';
            echo '</div>';
        }
        echo '</div>';
    }
}
