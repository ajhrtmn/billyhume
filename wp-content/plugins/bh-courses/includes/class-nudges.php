<?php
if (!defined('ABSPATH')) exit;

/**
 * Daily OUS_Jobs sweep that sends one nudge to students stalled 14+ days on an unfinished
 * course, throttled per course. Self-rescheduling (each run enqueues the next).
 */
class BHC_Nudges {
    const JOB_HOOK = 'bhc_check_stalled_students';
    const INTERVAL = DAY_IN_SECONDS;
    const STALLED_DAYS = 14;
    // Re-nudge only after this many days since the last nudge for the same course.
    const RENUDGE_DAYS = 14;

    public static function init(): void {
        if (!class_exists('OUS_Jobs')) return; // no queue infra, no job
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    // Enqueues the first run once, guarded by an option (OUS_Jobs isn't WP-Cron).
    public static function maybe_schedule_first_run(): void {
        if (get_option('bhc_nudge_job_scheduled')) return;
        update_option('bhc_nudge_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);
    }

    /** @param array<string, mixed> $args */
    public static function run($args = []): void {
        // Reschedule first so a fatal mid-sweep can't kill the recurring job.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);

        if (!class_exists('OUS_Notifications')) return;

        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => 'publish']);
        $cutoff = time() - (self::STALLED_DAYS * DAY_IN_SECONDS);

        foreach ($courses as $course) {
            $students = BHC_Progress::students_for_course($course->ID);
            foreach ($students as $user_id) {
                if (BHC_Progress::is_course_completed($user_id, $course->ID)) continue;
                $last = BHC_Progress::last_activity_for_course($user_id, $course->ID);
                if (!$last || strtotime($last) >= $cutoff) continue;

                $meta_key = '_bhc_last_nudge_' . $course->ID;
                $last_nudge = (int) get_user_meta($user_id, $meta_key, true);
                if ($last_nudge && $last_nudge > time() - (self::RENUDGE_DAYS * DAY_IN_SECONDS)) continue;

                OUS_Notifications::notify(
                    $user_id,
                    'course_stalled',
                    'Still with "' . $course->post_title . '"?',
                    'You started this course but haven\'t made progress in a while — pick up right where you left off.',
                    get_permalink($course->ID),
                    'BH Courses'
                );
                update_user_meta($user_id, $meta_key, time());
            }
        }
    }
}
