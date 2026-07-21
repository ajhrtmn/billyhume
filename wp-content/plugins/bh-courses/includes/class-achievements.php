<?php
if (!defined('ABSPATH')) exit;

/**
 * LMS depth-of-magic Phase 3 — real, persistent cross-course mastery
 * badges (bhc_achievements — see class-activator.php 1.5). Deliberately
 * a small, fixed set of KEYS rather than a free-text achievement system:
 * same "don't expand the palette" posture this ecosystem's design system
 * already follows for callout variants/certificate tiers.
 *
 * Award triggers hook the two events that already exist for other
 * reasons (mark_step_complete()'s quiz-score path, the bhc_course_completed
 * action) — no new detection logic, just a new persistent record of
 * moments that were already real.
 */
class BHC_Achievements {
    const FIRST_QUIZ_ACED = 'first_quiz_aced';
    const COURSE_DISTINCTION = 'course_distinction';
    const COURSES_MASTERED_3 = 'courses_mastered_3';

    const LABELS = [
        self::FIRST_QUIZ_ACED => ['label' => 'First Quiz Aced', 'description' => 'Scored 100% on a quiz for the first time.'],
        self::COURSE_DISTINCTION => ['label' => 'Completed with Distinction', 'description' => 'Finished a course with a strong quiz average.'],
        self::COURSES_MASTERED_3 => ['label' => '3 Courses Mastered', 'description' => 'Completed three courses with distinction.'],
    ];

    public static function init() {
        add_action('bhc_course_completed', [self::class, 'on_course_completed'], 10, 2);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhc_achievements';
    }

    // INSERT IGNORE against the table's own UNIQUE KEY is what actually
    // enforces "only once" — same atomic-write-decides pattern
    // maybe_fire_course_completed() already uses for bhc_completions, not
    // an application-level "have I awarded this already" check. Returns
    // true only when this call is the one that actually newly earned it
    // (rows_affected === 1) — callers use that to decide whether a
    // dependent achievement (courses_mastered_3) needs re-checking.
    public static function award($user_id, $achievement_key, $course_id = 0) {
        if (!$user_id || !isset(self::LABELS[$achievement_key])) return false;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table() . " (user_id, achievement_key, course_id) VALUES (%d, %s, %d)",
            $user_id, $achievement_key, (int) $course_id
        ));
        return (int) $wpdb->rows_affected === 1;
    }

    public static function has($user_id, $achievement_key, $course_id = 0) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND achievement_key = %s AND course_id = %d",
            $user_id, $achievement_key, (int) $course_id
        ));
    }

    public static function count($user_id, $achievement_key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND achievement_key = %s",
            $user_id, $achievement_key
        ));
    }

    public static function all_for_user($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT achievement_key, course_id, earned_at FROM " . self::table() . " WHERE user_id = %d ORDER BY earned_at ASC",
            $user_id
        ), ARRAY_A);
    }

    // Hooked from mark_step_complete() — the single funnel every quiz
    // submission already passes through — the moment a fresh 100% score
    // is written for any quiz step, in any course. A global achievement
    // (course_id 0, the "no specific course" sentinel — see
    // class-activator.php's own comment on why 0 rather than NULL), since
    // "first" is a once-ever-across-the-whole-site milestone, not a
    // per-course one.
    public static function maybe_award_quiz_aced($user_id, $score) {
        if ((int) $score !== 100) return;
        self::award($user_id, self::FIRST_QUIZ_ACED);
    }

    // Hooked on 'bhc_course_completed' ($user_id, $course_id) — fires
    // exactly once per student per course (see maybe_fire_course_completed()'s
    // own docblock). Reuses the exact same threshold filter/default the
    // certificate's own "with distinction" branch already uses (Phase
    // 1e) — one distinction bar for the whole plugin, not two that could
    // silently drift apart.
    public static function on_course_completed($user_id, $course_id) {
        if (!$user_id || !$course_id || !class_exists('BHC_Progress')) return;

        $avg = BHC_Progress::course_quiz_average($user_id, $course_id);
        $threshold = (int) apply_filters('bhc_certificate_distinction_threshold', 90);
        if ($avg === null || $avg < $threshold) return;

        $newly_earned = self::award($user_id, self::COURSE_DISTINCTION, $course_id);
        if (!$newly_earned) return;

        if (self::count($user_id, self::COURSE_DISTINCTION) >= 3) {
            self::award($user_id, self::COURSES_MASTERED_3);
        }
    }
}
