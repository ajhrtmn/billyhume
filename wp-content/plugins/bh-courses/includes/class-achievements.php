<?php
if (!defined('ABSPATH')) exit;

/**
 * Persistent cross-course badges (bhc_achievements), from a small fixed set of keys.
 * Awarded from the existing quiz-score path and the bhc_course_completed action.
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

        // Display-only profile badges (`['label' => string, 'url' => string|null]`).
        add_filter('bhi_profile_badges', [self::class, 'register_profile_badges'], 10, 2);
    }

    // course_distinction is per course and links to it; first_quiz_aced/courses_mastered_3
    // are global (course_id 0) with no link.
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

    // INSERT IGNORE on the UNIQUE KEY enforces once-only. Returns true only when newly
    // earned, so callers know to re-check dependent achievements.
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

    // Called from mark_step_complete() on any 100% quiz score; global (course_id 0).
    public static function maybe_award_quiz_aced(int $user_id, int $score): void {
        if ((int) $score !== 100) return;
        self::award($user_id, self::FIRST_QUIZ_ACED);
    }

    // On 'bhc_course_completed'. Uses the same distinction threshold filter as certificates.
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
