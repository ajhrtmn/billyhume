<?php
if (!defined('ABSPATH')) exit;

/**
 * Reads/writes bhc_progress. A content step completes on an explicit "mark complete";
 * a quiz step only via a submitted attempt that clears its passing_score. Also owns
 * enrollment (bhc_enrollments) and course completion (bhc_completions + bhc_course_completed).
 */
class BHC_Progress {
    public static function init(): void {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhc/enroll', ['course_id' => 'int']);
            BH_Event::register_event_type('bhc/step_completed', ['lesson_id' => 'int', 'step_index' => 'int', 'score' => 'int|null', 'passed' => 'bool|null']);
            BH_Event::register_event_type('bhc/course_completed', ['course_id' => 'int']);
        }
    }

    private static function table(): string {
        return BHC_Tables::progress();
    }

    /**
     * $sub_index 0 is the step's own row; an in-video annotation gets its own row at
     * sub_index = annotation index + 1.
     */
    /** @return array<string, mixed>|null */
    public static function step_status(int $user_id, int $lesson_id, int $step_index, int $sub_index = 0): ?array {
        global $wpdb;
        if (!$user_id) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d AND step_index = %d AND sub_index = %d",
            $user_id, $lesson_id, $step_index, $sub_index
        ), ARRAY_A);
    }

    public static function is_step_complete(int $user_id, int $lesson_id, int $step_index, int $sub_index = 0): bool {
        $row = self::step_status($user_id, $lesson_id, $step_index, $sub_index);
        // Failed quiz attempts still write a row but aren't complete.
        // passed = NULL (non-quiz) means complete once a row exists.
        if (!$row) return false;
        return $row['passed'] === null ? true : (bool) $row['passed'];
    }

    // Step indexes the user has cleared in this lesson (sub_index 0 only).
    /** @return array<int, int> */
    public static function completed_steps(int $user_id, int $lesson_id): array {
        global $wpdb;
        if (!$user_id) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT step_index, passed FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d AND sub_index = 0 ORDER BY step_index",
            $user_id, $lesson_id
        ), ARRAY_A);
        $done = [];
        foreach ($rows as $row) {
            if ($row['passed'] === null || (int) $row['passed'] === 1) $done[] = (int) $row['step_index'];
        }
        return $done;
    }

    // Annotation sub_indexes completed within one step (excludes the step row itself).
    /** @return array<int, int> */
    public static function completed_annotations(int $user_id, int $lesson_id, int $step_index): array {
        global $wpdb;
        if (!$user_id) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sub_index, passed FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d AND step_index = %d AND sub_index > 0 ORDER BY sub_index",
            $user_id, $lesson_id, $step_index
        ), ARRAY_A);
        $done = [];
        foreach ($rows as $row) {
            if ($row['passed'] === null || (int) $row['passed'] === 1) $done[] = (int) $row['sub_index'];
        }
        return $done;
    }

    public static function attempts(int $user_id, int $lesson_id, int $step_index, int $sub_index = 0): int {
        $row = self::step_status($user_id, $lesson_id, $step_index, $sub_index);
        return $row ? (int) $row['attempts'] : 0;
    }

    // $answers_json: pre-encoded per-question snapshot from BHC_Steps::score_quiz(), or null.
    public static function mark_step_complete(int $user_id, int $lesson_id, int $step_index, ?int $score = null, ?bool $passed = null, ?string $answers_json = null, int $sub_index = 0): void {
        global $wpdb;
        // WHY: $wpdb->prepare() casts a PHP null to ''/0, so NULL is inlined into the SQL.
        // Non-quiz steps must store passed = NULL or is_step_complete() reads them as incomplete.
        $score_sql   = $score === null ? 'NULL' : '%d';
        $passed_sql  = $passed === null ? 'NULL' : '%d';
        $answers_sql = $answers_json === null ? 'NULL' : '%s';
        $values = [$user_id, $lesson_id, $step_index, $sub_index];
        if ($score !== null) $values[] = (int) $score;
        if ($passed !== null) $values[] = (int) $passed;
        if ($answers_json !== null) $values[] = (string) $answers_json;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, lesson_id, step_index, sub_index, score, passed, attempts, answers)
             VALUES (%d, %d, %d, %d, $score_sql, $passed_sql, 1, $answers_sql)
             ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, score = VALUES(score), passed = VALUES(passed), answers = VALUES(answers), attempts = attempts + 1",
            $values
        ));
        if ($result === false && class_exists('OUS_DebugLog')) {
            // The AJAX handlers don't check this write, so log it at least.
            OUS_DebugLog::log('error', 'mark_step_complete() DB write failed — student will still be told the step completed.', [
                'user_id' => $user_id, 'lesson_id' => $lesson_id, 'step_index' => $step_index, 'sub_index' => $sub_index, 'db_error' => $wpdb->last_error,
            ], 'BH Courses Progress');
        }

        // Append-only (no dedup_key), like the table's own ON DUPLICATE KEY UPDATE.
        if (($passed === null || $passed) && class_exists('BH_Event')) {
            BH_Event::emit('bhc/step_completed', [
                'user_id' => $user_id,
                'subject_type' => 'bhc_lesson', 'subject_id' => (int) $lesson_id,
                'payload' => ['step_index' => (int) $step_index, 'score' => $score, 'passed' => $passed, 'sub_index' => $sub_index],
            ]);
        }

        if ($score !== null && class_exists('BHC_Achievements')) {
            BHC_Achievements::maybe_award_quiz_aced($user_id, $score);
        }

        // Only the step's own row (sub_index 0) can complete a course, never an annotation.
        if ($sub_index === 0 && ($passed === null || $passed)) {
            self::maybe_fire_course_completed($user_id, BHC_PostTypes::course_for_lesson($lesson_id));
        }
    }

    public static function watched_percent(int $user_id, int $lesson_id, int $step_index, int $sub_index = 0): int {
        $row = self::step_status($user_id, $lesson_id, $step_index, $sub_index);
        return $row && $row['watched_percent'] !== null ? (int) $row['watched_percent'] : 0;
    }

    // Throttled progress ping from the front-end video listener. Auto-completes the step
    // via mark_step_complete() once $percent clears the step's watch_threshold.
    public static function update_watch_progress(int $user_id, int $lesson_id, int $step_index, int $percent): bool {
        global $wpdb;
        $percent = max(0, min(100, (int) $percent));

        // GREATEST() so rewinding never regresses recorded progress; attempts untouched.
        // WHY: passed = 0 on insert and never updated here. A NULL would make
        // is_step_complete() treat the video as done after the first ping.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, lesson_id, step_index, watched_percent, passed, attempts)
             VALUES (%d, %d, %d, %d, 0, 0)
             ON DUPLICATE KEY UPDATE watched_percent = GREATEST(COALESCE(watched_percent, 0), VALUES(watched_percent))",
            $user_id, $lesson_id, $step_index, $percent
        ));

        $step = BHC_Steps::get_step($lesson_id, $step_index);
        $threshold = (int) ($step['watch_threshold'] ?? 0);
        if ($threshold > 0 && $percent >= $threshold && !self::is_step_complete($user_id, $lesson_id, $step_index)) {
            self::mark_step_complete($user_id, $lesson_id, $step_index);
            return true; // tells the AJAX caller the step just auto-completed
        }
        return false;
    }

    // Decoded per-question snapshot for a quiz step, or null. Used to render a review of a
    // passed quiz without re-deriving from possibly edited step content.
    /** @return array<string, mixed>|null */
    public static function stored_answers(int $user_id, int $lesson_id, int $step_index, int $sub_index = 0): ?array {
        $row = self::step_status($user_id, $lesson_id, $step_index, $sub_index);
        if (!$row || empty($row['answers'])) return null;
        $decoded = json_decode($row['answers'], true);
        return is_array($decoded) ? $decoded : null;
    }

    // Percent of a whole course completed, across every lesson's steps.
    public static function course_percent(int $user_id, int $course_id): int {
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

    // Average latest quiz score across attempted quiz steps (only quizzes write a score).
    // Returns null, not 0, when no quiz has been attempted.
    public static function course_quiz_average(int $user_id, int $course_id): ?int {
        if (!$user_id) return null;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return null;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        $scores = $wpdb->get_col($wpdb->prepare(
            "SELECT score FROM " . self::table() . " WHERE user_id = %d AND lesson_id IN ($placeholders) AND score IS NOT NULL",
            array_merge([$user_id], $lesson_ids)
        ));
        if (!$scores) return null;
        $scores = array_map('floatval', $scores);
        return (int) round(array_sum($scores) / count($scores));
    }

    // Every user with a progress row on any lesson in this course.
    /** @return array<int, int> */
    public static function students_for_course(int $course_id): array {
        global $wpdb;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return [];
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM " . BHC_Tables::progress() . " WHERE lesson_id IN ($placeholders) ORDER BY user_id",
            $lesson_ids
        )));
    }

    public static function last_activity_for_course(int $user_id, int $course_id): ?string {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return null;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        return $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(completed_at) FROM " . BHC_Tables::progress() . " WHERE user_id = %d AND lesson_id IN ($placeholders)",
            array_merge([$user_id], $lesson_ids)
        ));
    }

    // One-query batch for the admin Student Progress page, which otherwise ran
    // per-student/per-lesson queries.
    /** @return array<string, mixed> */
    public static function course_progress_matrix(int $course_id): array {
        global $wpdb;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return ['user_ids' => [], 'completed' => [], 'last_activity' => [], 'quiz_scores' => []];

        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, lesson_id, step_index, passed, score, completed_at FROM " . self::table() . " WHERE lesson_id IN ($placeholders)",
            $lesson_ids
        ), ARRAY_A);

        $user_ids = [];
        $completed = [];      // [user_id][lesson_id] => [step_index, ...]
        $last_activity = [];  // [user_id] => latest completed_at string
        $quiz_scores = [];    // [lesson_id][step_index] => [score, ...]

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $lid = (int) $row['lesson_id'];
            $idx = (int) $row['step_index'];
            $user_ids[$uid] = true;

            // Same completion rule as is_step_complete()/completed_steps():
            // passed IS NULL means "non-quiz, complete on write".
            if ($row['passed'] === null || (int) $row['passed'] === 1) {
                $completed[$uid][$lid][] = $idx;
            }
            if ($row['completed_at'] && (!isset($last_activity[$uid]) || strtotime($row['completed_at']) > strtotime($last_activity[$uid]))) {
                $last_activity[$uid] = $row['completed_at'];
            }
            if ($row['score'] !== null) {
                $quiz_scores[$lid][$idx][] = (float) $row['score'];
            }
        }

        $user_ids = array_map('intval', array_keys($user_ids));
        sort($user_ids); // matches students_for_course()'s own ORDER BY user_id

        return ['user_ids' => $user_ids, 'completed' => $completed, 'last_activity' => $last_activity, 'quiz_scores' => $quiz_scores];
    }

    // Batched sibling of is_course_completed().
    /**
     * @param array<int, int> $user_ids
     * @return array<int, int>
     */
    public static function completed_user_ids(int $course_id, array $user_ids): array {
        if (!$user_ids) return [];
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . BHC_Tables::completions() . " WHERE course_id = %d AND user_id IN ($placeholders)",
            array_merge([$course_id], $user_ids)
        ));
        return array_flip(array_map('intval', $rows));
    }

    // First lesson the user can resume (published, drip-open, has steps, not done),
    // or null when every open lesson is done.
    public static function first_incomplete_lesson(int $user_id, int $course_id): ?int {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        foreach ($lesson_ids as $lesson_id) {
            if (get_post_status($lesson_id) !== 'publish') continue;
            if (class_exists('BHC_Gate') && !BHC_Gate::lesson_is_open($user_id, $lesson_id)) continue;
            $step_count = BHC_Steps::count($lesson_id);
            if (!$step_count) continue;
            $done_count = $user_id ? count(self::completed_steps($user_id, $lesson_id)) : 0;
            if ($done_count < $step_count) return $lesson_id;
        }
        return null;
    }

    /* ---------------- enrollment (drip scheduling's clock-start) ---------------- */

    // Cheap no-op on repeat visits (INSERT IGNORE). Returns true only on the first-ever
    // enrollment, so callers can show a one-time orientation.
    public static function enroll_if_needed(int $user_id, int $course_id): bool {
        if (!$user_id || !$course_id) return false;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . BHC_Tables::enrollments() . " (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
        // dedup_key: a repeat call never records a second event.
        if ($wpdb->rows_affected === 1) {
            if (class_exists('BH_Event')) {
                BH_Event::emit('bhc/enroll', [
                    'user_id' => $user_id,
                    'subject_type' => 'bhc_course', 'subject_id' => (int) $course_id,
                    'dedup_key' => "bhc/enroll:$user_id:$course_id",
                ]);
            }
            // Only on the actual INSERT; drives the enrollment confirmation notification.
            do_action('bhc_enrolled', $user_id, $course_id);
            return true;
        }
        return false;
    }

    /**
     * Count of enrolled-but-not-completed courses. Lives here so cross-plugin callers
     * don't reimplement the join against this plugin's schema.
     */
    public static function in_progress_count(int $user_id): int {
        global $wpdb;
        $enrollments = BHC_Tables::enrollments();
        $completions = BHC_Tables::completions();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $enrollments e
             LEFT JOIN $completions c ON c.user_id = e.user_id AND c.course_id = e.course_id
             WHERE e.user_id = %d AND c.course_id IS NULL",
            $user_id
        ));
    }

    /** Most recently enrolled course the user hasn't finished, for "continue learning". */
    public static function most_recent_in_progress_course(int $user_id): ?int {
        global $wpdb;
        $enrollments = BHC_Tables::enrollments();
        $completions = BHC_Tables::completions();
        $course_id = $wpdb->get_var($wpdb->prepare(
            "SELECT e.course_id FROM $enrollments e
             LEFT JOIN $completions c ON c.user_id = e.user_id AND c.course_id = e.course_id
             WHERE e.user_id = %d AND c.course_id IS NULL
             ORDER BY e.enrolled_at DESC LIMIT 1",
            $user_id
        ));
        return $course_id ? (int) $course_id : null;
    }

    public static function enrolled_at(int $user_id, int $course_id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT enrolled_at FROM " . BHC_Tables::enrollments() . " WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    // Every enrolled user. Unlike students_for_course(), includes users with no progress
    // rows yet (needed by class-drip-nudges.php).
    /** @return array<int, int> */
    public static function enrolled_user_ids(int $course_id): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . BHC_Tables::enrollments() . " WHERE course_id = %d",
            $course_id
        )));
    }

    // Catalog "popular" sort: course_id => enrollment count; missing key means 0.
    /** @return array<int, int> */
    public static function enrollment_counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT course_id, COUNT(*) AS c FROM " . BHC_Tables::enrollments() . " GROUP BY course_id",
            ARRAY_A
        );
        $counts = [];
        foreach ($rows as $row) $counts[(int) $row['course_id']] = (int) $row['c'];
        return $counts;
    }

    /* ---------------- completion (bhc_course_completed) ---------------- */

    // Fires 'bhc_course_completed' once per user/course. Dedup is the atomic INSERT IGNORE
    // on bhc_completions' UNIQUE KEY, so racing last steps can't double-fire.
    private static function maybe_fire_course_completed(int $user_id, int $course_id): void {
        if (!$user_id || !$course_id) return;
        if (self::course_percent($user_id, $course_id) < 100) return;

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . BHC_Tables::completions() . " (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
        if ((int) $wpdb->rows_affected === 1) {
            do_action('bhc_course_completed', $user_id, $course_id);
            // dedup_key enforces the same once-only rule at the event-table level.
            if (class_exists('BH_Event')) {
                BH_Event::emit('bhc/course_completed', [
                    'user_id' => $user_id,
                    'subject_type' => 'bhc_course', 'subject_id' => (int) $course_id,
                    'dedup_key' => "bhc/course_completed:$user_id:$course_id",
                ]);
            }
        }
    }

    // WHY: requires course_percent() === 100 as well as the completion row, since lessons
    // added after completion would otherwise leave the student "complete" with unseen content.
    // The row is kept so course_completed_at() stays historically accurate.
    public static function is_course_completed(int $user_id, int $course_id): bool {
        global $wpdb;
        $has_completion_row = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . BHC_Tables::completions() . " WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
        if (!$has_completion_row) return false;
        return self::course_percent($user_id, $course_id) === 100;
    }

    /** Null if not yet completed — same shape as enrolled_at(), for the completion screen's "time invested" stat. */
    public static function course_completed_at(int $user_id, int $course_id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT completed_at FROM " . BHC_Tables::completions() . " WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    // Distinct quiz steps passed in this course; separate from the average on purpose.
    public static function quizzes_passed_count(int $user_id, int $course_id): int {
        if (!$user_id) return 0;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return 0;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND lesson_id IN ($placeholders) AND score IS NOT NULL AND passed = 1",
            array_merge([$user_id], $lesson_ids)
        ));
    }

    /* ---------------- AJAX handlers (logged-in students) ---------------- */

    public static function ajax_mark_complete(): void {
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

        // Return the fresh course percent so the front end can update the sidebar.
        $course_id = class_exists('BHC_PostTypes') ? BHC_PostTypes::course_for_lesson($lesson_id) : 0;
        wp_send_json_success([
            'step_index' => $step_index,
            'course_id' => $course_id,
            'course_percent' => $course_id ? self::course_percent($user_id, $course_id) : null,
        ]);
    }

    const VIDEO_POSITIONS_META = 'bhc_video_positions';
    const VIDEO_POSITIONS_KEEP = 30;

    /** Saved playback position in seconds for one video step, 0 when none. */
    public static function video_position(int $user_id, int $lesson_id, int $step_index): int {
        if (!$user_id) return 0;
        $all = get_user_meta($user_id, self::VIDEO_POSITIONS_META, true);
        return is_array($all) ? (int) ($all[$lesson_id . ':' . $step_index] ?? 0) : 0;
    }

    // One small map per user, most recent last, capped so it never grows with the catalog.
    public static function save_video_position(int $user_id, int $lesson_id, int $step_index, int $seconds): void {
        $all = get_user_meta($user_id, self::VIDEO_POSITIONS_META, true);
        $all = is_array($all) ? $all : [];
        $key = $lesson_id . ':' . $step_index;
        unset($all[$key]);
        if ($seconds > 0) $all[$key] = $seconds;
        update_user_meta($user_id, self::VIDEO_POSITIONS_META, array_slice($all, -self::VIDEO_POSITIONS_KEEP, null, true));
    }

    // seconds = 0 clears the position (the video was finished).
    public static function ajax_save_video_position(): void {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'video') wp_send_json_error(['message' => 'Invalid video step.'], 400);
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) wp_send_json_error(['message' => 'Access required.'], 403);

        self::save_video_position($user_id, $lesson_id, $step_index, max(0, min(86400, (int) ($_POST['seconds'] ?? 0))));
        wp_send_json_success();
    }

    public static function ajax_update_watch_progress(): void {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $percent = (int) ($_POST['percent'] ?? 0);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'video') {
            wp_send_json_error(['message' => 'Invalid video step.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        $auto_completed = self::update_watch_progress($user_id, $lesson_id, $step_index, $percent);

        // Course percent only on the tick that auto-completes.
        $course_id = 0;
        $course_percent = null;
        if ($auto_completed && class_exists('BHC_PostTypes')) {
            $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
            if ($course_id) $course_percent = self::course_percent($user_id, $course_id);
        }
        wp_send_json_success(['step_index' => $step_index, 'auto_completed' => $auto_completed, 'course_id' => $course_id, 'course_percent' => $course_percent]);
    }

    // Persists a 'question' annotation answer at sub_index = annotation index + 1.
    // WHY: re-scored server-side from the stored correct_index, never a client "correct" flag.
    public static function ajax_mark_annotation(): void {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $annotation_index = (int) ($_POST['annotation_index'] ?? -1);
        $chosen_index = isset($_POST['chosen_index']) ? (int) $_POST['chosen_index'] : null;

        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'video') {
            wp_send_json_error(['message' => 'Invalid video step.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }
        $annotations = (array) ($step['annotations'] ?? []);
        $annotation = $annotations[$annotation_index] ?? null;
        if (!$annotation || ($annotation['type'] ?? '') !== 'question') {
            wp_send_json_error(['message' => 'Invalid annotation.'], 400);
        }

        $correct_index = $annotation['payload']['correct_index'] ?? null;
        $passed = $chosen_index !== null && $correct_index !== null && $chosen_index === (int) $correct_index;

        self::mark_step_complete($user_id, $lesson_id, $step_index, null, $passed, null, $annotation_index + 1);

        wp_send_json_success(['step_index' => $step_index, 'annotation_index' => $annotation_index, 'passed' => $passed]);
    }

    public static function ajax_submit_quiz(): void {
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
            // Name the missed questions from the last stored attempt, for a later request
            // against an already-exhausted quiz.
            $snapshot = self::stored_answers($user_id, $lesson_id, $step_index);
            $missed = [];
            if (is_array($snapshot['questions'] ?? null)) {
                foreach ($snapshot['questions'] as $q) {
                    if (($q['chosen_index'] ?? null) !== ($q['correct_index'] ?? null) && !empty($q['q'])) {
                        $missed[] = $q['q'];
                    }
                }
            }
            $message = $missed
                ? "Out of attempts ($max_attempts allowed). Before moving on, review: " . implode('; ', $missed) . '.'
                : "No attempts remaining ($max_attempts allowed).";
            wp_send_json_error([
                'message' => $message,
                'attempts_used' => $attempts_so_far, 'max_attempts' => $max_attempts,
            ], 403);
        }

        // WHY: a passed quiz is never rescored, so a replayed POST can't overwrite a pass
        // with a fail. Return the stored snapshot instead.
        if ($already_passed) {
            $snapshot = self::stored_answers($user_id, $lesson_id, $step_index);
            $course_id = class_exists('BHC_PostTypes') ? BHC_PostTypes::course_for_lesson($lesson_id) : 0;
            wp_send_json_success([
                'score' => (int) $existing['score'], 'passed' => true,
                'total' => count($step['questions'] ?? []), 'correct' => null,
                'questions' => $snapshot['questions'] ?? null,
                'attempts_used' => $attempts_so_far, 'max_attempts' => $max_attempts,
                'attempts_remaining' => $max_attempts > 0 ? max(0, $max_attempts - $attempts_so_far) : null,
                'already_passed' => true,
                'course_id' => $course_id,
                'course_percent' => $course_id ? self::course_percent($user_id, $course_id) : null,
            ]);
        }

        $raw_answers = (array) ($_POST['answers'] ?? []);
        $answers = [];
        foreach ($raw_answers as $q_index => $choice_index) {
            $answers[(int) $q_index] = (int) $choice_index;
        }

        $result = BHC_Steps::score_quiz($step, $answers);

        // Self-contained snapshot (question text, choices, correct_index, score) so later
        // edits to the quiz can't change what the review shows.
        $answers_json = wp_json_encode([
            'score' => $result['score'],
            'passed' => $result['passed'],
            'passing_score' => (int) ($step['passing_score'] ?? 70),
            'questions' => $result['questions'],
        ]);
        self::mark_step_complete($user_id, $lesson_id, $step_index, $result['score'], $result['passed'], $answers_json);

        $result['attempts_used'] = $attempts_so_far + 1;
        $result['max_attempts'] = $max_attempts;
        $result['attempts_remaining'] = $max_attempts > 0 ? max(0, $max_attempts - $result['attempts_used']) : null;

        $course_id = class_exists('BHC_PostTypes') ? BHC_PostTypes::course_for_lesson($lesson_id) : 0;
        $result['course_id'] = $course_id;
        $result['course_percent'] = $course_id ? self::course_percent($user_id, $course_id) : null;
        wp_send_json_success($result);
    }
}
