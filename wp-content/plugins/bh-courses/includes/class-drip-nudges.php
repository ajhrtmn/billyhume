<?php
if (!defined('ABSPATH')) exit;

/**
 * Daily self-rescheduling OUS_Jobs sweep that notifies students when a drip-scheduled
 * lesson unlocks; once per user+lesson.
 */
class BHC_DripNudges {
    const JOB_HOOK = 'bhc_check_drip_unlocks';
    const INTERVAL = DAY_IN_SECONDS;

    public static function init(): void {
        if (!class_exists('OUS_Jobs')) return; // no queue infra, no job
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    public static function maybe_schedule_first_run(): void {
        if (get_option('bhc_drip_nudge_job_scheduled')) return;
        update_option('bhc_drip_nudge_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);
    }

    /** @param array<string, mixed> $args */
    public static function run($args = []): void {
        // Reschedule first so a fatal mid-sweep can't kill the recurring job.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);

        if (!class_exists('OUS_Notifications')) return;

        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => 'publish']);

        foreach ($courses as $course) {
            $lesson_ids = BHC_PostTypes::lesson_order($course->ID);
            // Only lessons with a drip rule have an unlock moment.
            $drip_lesson_ids = array_filter($lesson_ids, function ($lid) {
                return BHC_Gate::drip_after_days($lid) !== null || BHC_Gate::drip_on_date($lid) !== null;
            });
            if (!$drip_lesson_ids) continue;

            $enrolled_ids = BHC_Progress::enrolled_user_ids($course->ID);
            if (!$enrolled_ids) continue;

            foreach ($enrolled_ids as $user_id) {
                // Skip students who've lost course access.
                if (!BHC_Gate::user_can_access_course($user_id, $course->ID)) continue;

                foreach ($drip_lesson_ids as $lesson_id) {
                    if (!BHC_Gate::lesson_is_open($user_id, $lesson_id)) continue;

                    $meta_key = '_bhc_drip_notified_' . $lesson_id;
                    if (get_user_meta($user_id, $meta_key, true)) continue; // already notified

                    OUS_Notifications::notify(
                        $user_id,
                        'course_lesson_unlocked',
                        'New lesson unlocked: ' . get_the_title($lesson_id),
                        'In "' . $course->post_title . '" — ready whenever you are.',
                        get_permalink($lesson_id),
                        'BH Courses'
                    );
                    update_user_meta($user_id, $meta_key, time());
                }
            }
        }
    }
}
