<?php
if (!defined('ABSPATH')) exit;

/**
 * Closes the "no way back in" gap the LMS audit flagged: previously the
 * only lifecycle notification anywhere in this plugin was on course
 * completion (see class-crm-integration.php) — a student who enrolled,
 * did one lesson, and quietly vanished got nothing, ever, pulling them
 * back. This is a daily job (own-ur-shit's shared OUS_Jobs queue —
 * see that class's docblock for why: no new cron/queue infrastructure
 * of this plugin's own) that finds students stalled 14+ days on an
 * unfinished course and sends exactly one nudge, throttled so the same
 * student never gets nagged more than once per stall window.
 *
 * Deliberately self-rescheduling (each run enqueues the next one) —
 * same pattern as this ecosystem has zero prior examples of, but it's
 * the simplest way to get a recurring OUS_Jobs job without a second
 * piece of cron-schedule-registration plumbing bh-crm's reminder job
 * (class-notes.php) didn't need since that one is a genuine one-shot
 * delayed job, not a recurring sweep.
 */
class BHC_Nudges {
    const JOB_HOOK = 'bhc_check_stalled_students';
    const INTERVAL = DAY_IN_SECONDS;
    const STALLED_DAYS = 14;
    // A user is only nudged again after this many days since their last
    // nudge for the SAME course — independent of whether they're still
    // stalled, so a student who ignores one nudge doesn't get a fresh
    // one every single day this job runs.
    const RENUDGE_DAYS = 14;

    public static function init() {
        if (!class_exists('OUS_Jobs')) return; // no queue infra, no job — same guard bh-crm/bh-streaming's job registrations use
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    // Runs once ever per site (guarded by an option flag, not a
    // wp_next_scheduled() check — OUS_Jobs' queue isn't real WP-Cron,
    // it's Action Scheduler or a fallback table, see that class) —
    // avoids re-enqueueing a duplicate first run on every single
    // request before the job has had a chance to run once and
    // reschedule itself.
    public static function maybe_schedule_first_run() {
        if (get_option('bhc_nudge_job_scheduled')) return;
        update_option('bhc_nudge_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::INTERVAL);
    }

    public static function run($args = []) {
        // Reschedule first — a fatal error partway through the sweep
        // below shouldn't silently kill the recurring job forever.
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
