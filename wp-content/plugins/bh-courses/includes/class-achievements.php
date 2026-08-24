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

    public static function init(): void {
        add_action('bhc_course_completed', [self::class, 'on_course_completed'], 10, 2);

        // Depth-of-magic Phase 4 — the same `bhi_profile_badges` filter
        // the-self-hosted-self's public-profile view already renders through
        // (confirmed contract: `['label' => string, 'url' => string|null]`,
        // display-only, no persistence of its own — see
        // BHI_Profiles::badges_for()'s own docblock). Harmless no-op if
        // the-self-hosted-self's profile view never calls apply_filters() on it,
        // same "optional cross-plugin touch" posture every other filter
        // hook in this ecosystem takes.
        add_filter('bhi_profile_badges', [self::class, 'register_profile_badges'], 10, 2);
    }

    // A course_distinction badge names the specific course (a student can
    // earn it more than once — one per course cleared, unlike the two
    // global achievements below, which the table's own 0-course-id
    // sentinel + UNIQUE KEY guarantee only ever exist once), and links to
    // that course's own page. first_quiz_aced/courses_mastered_3 are
    // global (course_id 0) — generic label, no link.
    /**
     * @param array<int, array<string, mixed>> $badges
     * @return array<int, array<string, mixed>>
     */
    public static function register_profile_badges($badges, int $user_id): array {
        foreach (self::all_for_user($user_id) as $row) {
            $key = $row['achievement_key'];
            $meta = self::LABELS[$key] ?? null;
            if (!$meta) continue;

            if ($key === self::COURSE_DISTINCTION && (int) $row['course_id'] > 0) {
                $course_title = get_the_title((int) $row['course_id']);
                $badges[] = [
                    'label' => $meta['label'] . ($course_title ? ': ' . $course_title : ''),
                    'url' => get_permalink((int) $row['course_id']) ?: null,
                ];
            } else {
                $badges[] = ['label' => $meta['label'], 'url' => null];
            }
        }
        return $badges;
    }

    private static function table(): string {
        return BHC_Tables::achievements();
    }

    // INSERT IGNORE against the table's own UNIQUE KEY is what actually
    // enforces "only once" — same atomic-write-decides pattern
    // maybe_fire_course_completed() already uses for bhc_completions, not
    // an application-level "have I awarded this already" check. Returns
    // true only when this call is the one that actually newly earned it
    // (rows_affected === 1) — callers use that to decide whether a
    // dependent achievement (courses_mastered_3) needs re-checking.
    public static function award(int $user_id, string $achievement_key, int $course_id = 0): bool {
        if (!$user_id || !isset(self::LABELS[$achievement_key])) return false;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table() . " (user_id, achievement_key, course_id) VALUES (%d, %s, %d)",
            $user_id, $achievement_key, (int) $course_id
        ));
        return (int) $wpdb->rows_affected === 1;
    }

    public static function has(int $user_id, string $achievement_key, int $course_id = 0): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND achievement_key = %s AND course_id = %d",
            $user_id, $achievement_key, (int) $course_id
        ));
    }

    public static function count(int $user_id, string $achievement_key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND achievement_key = %s",
            $user_id, $achievement_key
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public static function all_for_user(int $user_id): array {
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
    public static function maybe_award_quiz_aced(int $user_id, int $score): void {
        if ((int) $score !== 100) return;
        self::award($user_id, self::FIRST_QUIZ_ACED);
    }

    // Hooked on 'bhc_course_completed' ($user_id, $course_id) — fires
    // exactly once per student per course (see maybe_fire_course_completed()'s
    // own docblock). Reuses the exact same threshold filter/default the
    // certificate's own "with distinction" branch already uses (Phase
    // 1e) — one distinction bar for the whole plugin, not two that could
    // silently drift apart.
    public static function on_course_completed(int $user_id, int $course_id): void {
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
