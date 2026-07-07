<?php
if (!defined('ABSPATH')) exit;

/**
 * Reads/writes bhc_progress (see class-activator.php). A plain content
 * step (text/image) completes on an explicit "mark complete" click from
 * the front end; a quiz step only completes via a submitted attempt,
 * and only counts as "passed" if the score clears the step's own
 * passing_score. Also owns enrollment recording (bhc_enrollments — see
 * class-gate.php's drip-scheduling use of it) and course-completion
 * detection (bhc_completions + the bhc_course_completed action).
 */
class BHC_Progress {
    public static function init() {
        // Nothing to hook here beyond the two AJAX actions registered
        // in the main bootstrap file — kept as an init() entry point
        // anyway for consistency with every other class in this
        // ecosystem.
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhc_progress';
    }

    public static function step_status($user_id, $lesson_id, $step_index) {
        global $wpdb;
        if (!$user_id) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d AND step_index = %d",
            $user_id, $lesson_id, $step_index
        ), ARRAY_A);
    }

    public static function is_step_complete($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        // A quiz step only counts as "complete" (i.e. the walker can
        // advance past it) once passed — a failed attempt still writes
        // a row (so attempts/score are tracked), but shouldn't read as
        // done. A non-quiz row has passed = NULL, which the ?? true
        // treats as "nothing to pass, so complete on write" — unchanged
        // behavior from before max_attempts existed.
        if (!$row) return false;
        return $row['passed'] === null ? true : (bool) $row['passed'];
    }

    // Highest step index a user has actually cleared in this lesson,
    // i.e. how far they can navigate to (next-uncompleted-step gating
    // lives in class-render.php, not here — this is just the data).
    // Only counts steps that are genuinely done per is_step_complete()'s
    // rule above (a failed, attempts-exhausted quiz never counts).
    public static function completed_steps($user_id, $lesson_id) {
        global $wpdb;
        if (!$user_id) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT step_index, passed FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d ORDER BY step_index",
            $user_id, $lesson_id
        ), ARRAY_A);
        $done = [];
        foreach ($rows as $row) {
            if ($row['passed'] === null || (int) $row['passed'] === 1) $done[] = (int) $row['step_index'];
        }
        return $done;
    }

    public static function attempts($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        return $row ? (int) $row['attempts'] : 0;
    }

    public static function mark_step_complete($user_id, $lesson_id, $step_index, $score = null, $passed = null) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, lesson_id, step_index, score, passed, attempts)
             VALUES (%d, %d, %d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, score = VALUES(score), passed = VALUES(passed), attempts = attempts + 1",
            $user_id, $lesson_id, $step_index, $score, $passed === null ? null : (int) $passed
        ));

        if ($passed === null || $passed) {
            self::maybe_fire_course_completed($user_id, BHC_PostTypes::course_for_lesson($lesson_id));
        }
    }

    // Percent of a whole COURSE a user has completed — steps across
    // every lesson in the course's own order, not just one lesson.
    public static function course_percent($user_id, $course_id) {
        if (!$user_id) return 0;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return 0;

        $total = 0;
        $done = 0;
        foreach ($lesson_ids as $lesson_id) {
            $step_count = BHC_Steps::count($lesson_id);
            $total += $step_count;
            $done += count(self::completed_steps($user_id, $lesson_id));
        }
        return $total ? (int) round(($done / $total) * 100) : 0;
    }

    /* ---------------- enrollment (drip scheduling's clock-start) ---------------- */

    // Called once access is confirmed (class-render.php, on viewing the
    // course or a lesson in it) — cheap no-op on every repeat visit
    // thanks to INSERT IGNORE, so callers don't need their own "have I
    // already enrolled this person" check.
    public static function enroll_if_needed($user_id, $course_id) {
        if (!$user_id || !$course_id) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bhc_enrollments (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
    }

    public static function enrolled_at($user_id, $course_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT enrolled_at FROM {$wpdb->prefix}bhc_enrollments WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    /* ---------------- completion (bhc_course_completed) ---------------- */

    // Fires 'bhc_course_completed' ($user_id, $course_id) the first
    // time — and only the first time — a student clears 100% of a
    // course. Dedup is enforced by bhc_completions' own UNIQUE KEY (an
    // atomic INSERT that either succeeds once or silently no-ops on
    // every later call), not by an application-level "have I fired this
    // already" check, so a race between two nearly-simultaneous last
    // steps can't double-fire it either.
    private static function maybe_fire_course_completed($user_id, $course_id) {
        if (!$user_id || !$course_id) return;
        if (self::course_percent($user_id, $course_id) < 100) return;

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bhc_completions (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
        if ((int) $wpdb->rows_affected === 1) {
            do_action('bhc_course_completed', $user_id, $course_id);
        }
    }

    public static function is_course_completed($user_id, $course_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhc_completions WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    /* ---------------- AJAX handlers (logged-in students) ---------------- */

    public static function ajax_mark_complete() {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] === 'quiz') {
            wp_send_json_error(['message' => 'Invalid step, or this step requires quiz submission instead.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        self::mark_step_complete($user_id, $lesson_id, $step_index);
        wp_send_json_success(['step_index' => $step_index]);
    }

    public static function ajax_submit_quiz() {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'quiz') {
            wp_send_json_error(['message' => 'Invalid quiz step.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        $max_attempts = (int) ($step['max_attempts'] ?? 0);
        $existing = self::step_status($user_id, $lesson_id, $step_index);
        $already_passed = $existing && (int) $existing['passed'] === 1;
        $attempts_so_far = $existing ? (int) $existing['attempts'] : 0;

        if (!$already_passed && $max_attempts > 0 && $attempts_so_far >= $max_attempts) {
            wp_send_json_error([
                'message' => "No attempts remaining ($max_attempts allowed).",
                'attempts_used' => $attempts_so_far, 'max_attempts' => $max_attempts,
            ], 403);
        }

        $raw_answers = (array) ($_POST['answers'] ?? []);
        $answers = [];
        foreach ($raw_answers as $q_index => $choice_index) {
            $answers[(int) $q_index] = (int) $choice_index;
        }

        $result = BHC_Steps::score_quiz($step, $answers);
        self::mark_step_complete($user_id, $lesson_id, $step_index, $result['score'], $result['passed']);

        $result['attempts_used'] = $attempts_so_far + 1;
        $result['max_attempts'] = $max_attempts;
        $result['attempts_remaining'] = $max_attempts > 0 ? max(0, $max_attempts - $result['attempts_used']) : null;
        wp_send_json_success($result);
    }
}
