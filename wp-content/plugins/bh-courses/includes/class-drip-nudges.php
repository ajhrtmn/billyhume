<?php
if (!defined('ABSPATH')) exit;

/**
 * A real, un-admitted gap the LMS audit flagged: drip scheduling
 * (class-gate.php) and lifecycle notifications (class-nudges.php,
 * class-crm-integration.php) were both fully built but never talked to
 * each other — a student on a drip schedule got no signal when a new
 * lesson actually unlocked for them; they'd only find out by manually
 * revisiting the course page. This closes that gap the same way
 * class-nudges.php already does: a daily, self-rescheduling OUS_Jobs
 * sweep (no new cron/queue infrastructure of this plugin's own), one
 * notification per lesson-unlock event, deduped per user+lesson so it
 * only ever fires once.
 */
class BHC_DripNudges {
    const JOB_HOOK = 'bhc_check_drip_unlocks';
    const INTERVAL = DAY_IN_SECONDS;

    public static function init() {
        if (!class_exists('OUS_Jobs')) return; // no queue infra, no job — same guard class-nudges.php's own registration uses
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    public static function maybe_schedule_first_run() {
        if (get_option('bhc_drip_nudge_job_scheduled')) return;
        update_option('bhc_drip_nudge_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);
    }

    public static function run($args = []) {
        // Reschedule first — a fatal error partway through the sweep
        // below shouldn't silently kill the recurring job forever, same
        // reasoning as class-nudges.php's own run().
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);

        if (!class_exists('OUS_Notifications')) return;

        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => 'publish']);

        foreach ($courses as $course) {
            $lesson_ids = BHC_PostTypes::lesson_order($course->ID);
            // Only lessons with an actual drip rule set are candidates —
            // an un-dripped lesson is already open the moment its course
            // is, so there's no "just unlocked" moment to notify about.
            $drip_lesson_ids = array_filter($lesson_ids, function ($lid) {
                return BHC_Gate::drip_after_days($lid) !== null || BHC_Gate::drip_on_date($lid) !== null;
            });
            if (!$drip_lesson_ids) continue;

            $enrolled_ids = BHC_Progress::enrolled_user_ids($course->ID);
            if (!$enrolled_ids) continue;

            foreach ($enrolled_ids as $user_id) {
                // Access gate first (tier/benefit) — if they've lost
                // course access entirely, an "unlocked" email about
                // content they can no longer see would be actively
                // misleading, not just unhelpful.
                if (!BHC_Gate::user_can_access_course($user_id, $course->ID)) continue;

                foreach ($drip_lesson_ids as $lesson_id) {
                    if (!BHC_Gate::lesson_is_open($user_id, $lesson_id)) continue;

                    $meta_key = '_bhc_drip_notified_' . $lesson_id;
                    if (get_user_meta($user_id, $meta_key, true)) continue; // already told them once — never repeat

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
