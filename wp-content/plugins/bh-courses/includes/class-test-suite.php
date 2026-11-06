<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner version of tests/QuizScoringTest.php and tests/StepsSanitizationTest.php,
 * runnable from Debug Tools without PHPUnit. Registration is skipped without OUS_TestRunner.
 */
class BHC_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['bh-courses'] = ['label' => 'BH Courses', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /**
     * @param array<int, string> $choices
     * @return array<string, mixed>
     */
    private static function q(int $correct_index, $choices = ['A', 'B', 'C']): array {
        return ['question' => 'Q', 'choices' => $choices, 'correct_index' => $correct_index];
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner')) return [];
        $rows = [];

        /* ---------- score_quiz() ---------- */

        $step = ['passing_score' => 70, 'questions' => [self::q(0), self::q(1)]];
        $r = BHC_Steps::score_quiz($step, [0 => 0, 1 => 1]);
        $rows[] = OUS_TestRunner::assert_same(100, $r['score'], 'All correct scores 100');
        $rows[] = OUS_TestRunner::assert_true($r['passed'], 'All correct passes');

        $r = BHC_Steps::score_quiz($step, [0 => 2, 1 => 2]);
        $rows[] = OUS_TestRunner::assert_same(0, $r['score'], 'All wrong scores 0');
        $rows[] = OUS_TestRunner::assert_false($r['passed'], 'All wrong fails');

        $step3 = ['passing_score' => 70, 'questions' => [self::q(0), self::q(0), self::q(0)]];
        $r = BHC_Steps::score_quiz($step3, [0 => 0, 1 => 9, 2 => 9]);
        $rows[] = OUS_TestRunner::assert_same(33, $r['score'], '1 of 3 correct rounds to 33% (not 33.33 truncated oddly, not 34)');

        $step2_50 = ['passing_score' => 50, 'questions' => [self::q(0), self::q(0)]];
        $r = BHC_Steps::score_quiz($step2_50, [0 => 0, 1 => 9]);
        $rows[] = OUS_TestRunner::assert_true($r['passed'], 'Score exactly at passing threshold (50%) passes, not fails');

        $r = BHC_Steps::score_quiz($step, [0 => 0]);
        $rows[] = OUS_TestRunner::assert_same(2, $r['total'], 'Unanswered question still counts toward the total');
        $rows[] = OUS_TestRunner::assert_same(50, $r['score'], 'Missing answer counts as incorrect, not excluded');

        $r = BHC_Steps::score_quiz(['passing_score' => 70, 'questions' => []], []);
        $rows[] = OUS_TestRunner::assert_same(0, $r['total'], 'Zero-question quiz does not divide by zero');
        $rows[] = OUS_TestRunner::assert_false($r['passed'], 'Zero-question quiz never auto-passes');

        $r = BHC_Steps::score_quiz(['questions' => [self::q(0), self::q(0), self::q(0)]], [0 => 0, 1 => 0, 2 => 9]);
        $rows[] = OUS_TestRunner::assert_false($r['passed'], 'Missing passing_score defaults to 70, not 0');

        /* ---------- save() sanitization ---------- */

        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['type' => 'not_a_real_type', 'content' => 'hi']]), 'Unknown step type is dropped entirely');
        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['content' => 'no type key']]), 'Step with no type key at all is dropped');

        $result = BHC_Steps::save(1, [['type' => 'text', 'content' => '<p>Safe</p><script>alert(1)</script>']]);
        $rows[] = OUS_TestRunner::assert_false(strpos($result[0]['content'] ?? '', '<script>') !== false, 'Text step strips <script> tags');

        $result = BHC_Steps::save(1, [['type' => 'image', 'attachment_ids' => [5, 0, '7', 'not-a-number'], 'caption' => '']]);
        $rows[] = OUS_TestRunner::assert_same([5, 7], array_values($result[0]['attachment_ids'] ?? []), 'Image step filters out zero/invalid attachment IDs');

        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['type' => 'video', 'source' => 'url', 'video_url' => '']]), 'Video URL step with empty URL is dropped');
        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['type' => 'video', 'source' => 'url', 'video_url' => 'not a url']]), 'Video URL step with invalid URL is dropped');

        /* ---------- video annotations ---------- */

        $video_step = ['type' => 'video', 'source' => 'upload', 'attachment_id' => 5, 'annotations' => [
            ['time' => 30, 'type' => 'note', 'payload' => ['text' => 'Watch the meter here']],
            ['time' => 5, 'type' => 'question', 'payload' => ['question' => 'What is this?', 'choices' => ['A', 'B'], 'correct_index' => 1]],
            ['time' => 10, 'type' => 'bogus_type', 'payload' => ['text' => 'dropped']],
            ['time' => 15, 'type' => 'note', 'payload' => ['text' => '']],
            ['time' => 20, 'type' => 'question', 'payload' => ['question' => 'No choices', 'choices' => [], 'correct_index' => 0]],
        ]];
        $result = BHC_Steps::save(1, [$video_step]);
        $annotations = $result[0]['annotations'] ?? [];
        $rows[] = OUS_TestRunner::assert_same(2, count($annotations), 'Video annotations: unknown type, empty text, and choiceless question are all dropped, leaving only the 2 valid ones');
        $rows[] = OUS_TestRunner::assert_same([5, 30], array_column($annotations, 'time'), 'Video annotations: sorted by time ascending regardless of authoring order');
        $rows[] = OUS_TestRunner::assert_same('question', $annotations[0]['type'] ?? null, 'Video annotations: the earlier (time=5) annotation is the question');

        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'upload', 'attachment_id' => 5, 'annotations' => [
            ['time' => 1, 'type' => 'question', 'payload' => ['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => 99]],
        ]]]);
        $rows[] = OUS_TestRunner::assert_same(1, $result[0]['annotations'][0]['payload']['correct_index'] ?? null, 'Video annotation question: out-of-range correct_index clamps to the last valid choice, same rule as a real quiz step');

        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['type' => 'video', 'source' => 'upload', 'attachment_id' => 5]])[0]['annotations'] ?? [], 'Video step with no annotations key at all defaults to an empty array, not an error');

        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'upload', 'attachment_id' => 5, 'annotations' => [
            ['time' => 8, 'type' => 'banner', 'payload' => ['text' => 'Fun fact!']],
        ]]]);
        $rows[] = OUS_TestRunner::assert_same('Fun fact!', $result[0]['annotations'][0]['payload']['text'] ?? null, 'Video annotation: banner type (TRL-style, non-blocking) sanitizes as plain text, same as note/hotspot');

        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [['question' => 'Q', 'choices' => ['A', 'B', 'C'], 'correct_index' => 99]]]]);
        $rows[] = OUS_TestRunner::assert_same(2, $result[0]['questions'][0]['correct_index'] ?? null, 'Out-of-range correct_index clamps to last valid choice');

        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => -5]]]]);
        $rows[] = OUS_TestRunner::assert_same(0, $result[0]['questions'][0]['correct_index'] ?? null, 'Negative correct_index clamps to zero');

        $questions = [['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => 0]];
        $too_high = BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => 500, 'questions' => $questions]]);
        $too_low = BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => -20, 'questions' => $questions]]);
        $rows[] = OUS_TestRunner::assert_same(100, $too_high[0]['passing_score'] ?? null, 'passing_score clamps to 100 max');
        $rows[] = OUS_TestRunner::assert_same(0, $too_low[0]['passing_score'] ?? null, 'passing_score clamps to 0 min');

        $rows[] = OUS_TestRunner::assert_same([], BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => 70, 'questions' => []]]), 'Quiz with zero questions is dropped entirely');

        $result = BHC_Steps::save(1, [['type' => 'quiz', 'max_attempts' => -3, 'questions' => $questions]]);
        $rows[] = OUS_TestRunner::assert_same(0, $result[0]['max_attempts'] ?? null, 'Negative max_attempts clamps to 0 (unlimited), never "zero attempts allowed"');

        $result = BHC_Steps::save(1, [
            ['type' => 'text', 'content' => 'first'],
            ['type' => 'image', 'attachment_ids' => [1]],
            ['type' => 'text', 'content' => 'third'],
        ]);
        $rows[] = OUS_TestRunner::assert_same(['text', 'image', 'text'], array_column($result, 'type'), 'Multistep lesson preserves authored order');

        /* ---------- quiz answer storage (BHC_Progress) ----------
         * Real tagged fake user + bhc_progress rows, cleaned up afterward. */
        if (class_exists('BHC_Progress') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_progress_tests());
            $rows = array_merge($rows, self::run_quiz_average_tests());
        }

        /* ---------- catalog search/sort (BHC_Render::render_catalog()) ----------
         * Integration test against two tagged fixture courses; render_catalog()
         * reads $_GET and queries real posts, so there's no smaller seam. */
        if (class_exists('BHC_Render')) {
            $rows = array_merge($rows, self::run_catalog_tests());
        }

        /* ---------- gate/drip/progress-matrix interaction ----------
         * Both drip shapes, the "no enrollment = fails open" rule, and the batched
         * Student Progress queries. Real fixture course/lessons/enrollment, cleaned up. */
        if (class_exists('BHC_Gate') && class_exists('BHC_Progress') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_gate_drip_tests());
        }

        /* ---------- reviews (BHC_Reviews) ---------- */
        if (class_exists('BHC_Reviews') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_review_tests());
        }

        /* ---------- achievements (BHC_Achievements) ----------
         * Calls award()/count()/on_course_completed() directly to avoid unrelated
         * course_percent()/BH_Event/notification side effects. */
        if (class_exists('BHC_Achievements') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_achievement_tests());
        }

        /* ---------- leaderboard (BHC_Leaderboard) ----------
         * Competition-rank tie handling (1, 1, 3 — not 1, 1, 2). */
        if (class_exists('BHC_Leaderboard') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_leaderboard_tests());
        }

        /* ---------- is_course_completed() after course content changes ----------
         * A lesson added after completion must make the course incomplete again.
         * Needs real lesson posts, since course_percent() reads real _bhc_steps. */
        if (class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_completion_consistency_tests());
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_completion_consistency_tests(): array {
        $rows = [];
        $uid = OUS_Debug::get_or_create_test_user('bhc_completion_consistency_suite', false);

        $course_id = wp_insert_post(['post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => 'BHC Test Suite Completion-Consistency Fixture'], true);
        $lesson_a = wp_insert_post(['post_type' => 'bhc_lesson', 'post_status' => 'publish', 'post_title' => 'Fixture Lesson A', 'meta_input' => ['_bhc_course_id' => $course_id]], true);
        if (is_wp_error($course_id) || is_wp_error($lesson_a)) {
            return [['name' => 'BHC_TestSuite completion-consistency fixture insert failed', 'pass' => false, 'message' => '']];
        }
        BHC_Steps::save($lesson_a, [['type' => 'text', 'content' => 'Step one']]);
        update_post_meta($course_id, '_bhc_lesson_order', [$lesson_a]);

        BHC_Progress::mark_step_complete($uid, $lesson_a, 0);
        $rows[] = OUS_TestRunner::assert_same(100, BHC_Progress::course_percent($uid, $course_id), 'course_percent(): 100 once the only lesson\'s only step is complete');
        $rows[] = OUS_TestRunner::assert_true(BHC_Progress::is_course_completed($uid, $course_id), 'is_course_completed(): true once course_percent() genuinely reads 100 and a completions row exists');

        // A lesson added after the student completed the course.
        $lesson_b = wp_insert_post(['post_type' => 'bhc_lesson', 'post_status' => 'publish', 'post_title' => 'Fixture Lesson B (added later)', 'meta_input' => ['_bhc_course_id' => $course_id]], true);
        BHC_Steps::save($lesson_b, [['type' => 'text', 'content' => 'Step one of the new lesson']]);
        update_post_meta($course_id, '_bhc_lesson_order', [$lesson_a, $lesson_b]);

        $rows[] = OUS_TestRunner::assert_same(50, BHC_Progress::course_percent($uid, $course_id), 'course_percent(): correctly recalculates against the CURRENT lesson set (drops to 50 once a second, unfinished lesson exists)');
        $rows[] = OUS_TestRunner::assert_false(BHC_Progress::is_course_completed($uid, $course_id), 'is_course_completed(): false now, even though a bhc_completions row still exists from before the new lesson was added — this is the exact bug the live walkthrough found');

        // Completing the new lesson too restores full completion.
        BHC_Progress::mark_step_complete($uid, $lesson_b, 0);
        $rows[] = OUS_TestRunner::assert_same(100, BHC_Progress::course_percent($uid, $course_id), 'course_percent(): back to 100 once the newly-added lesson is also completed');
        $rows[] = OUS_TestRunner::assert_true(BHC_Progress::is_course_completed($uid, $course_id), 'is_course_completed(): true again once the student has genuinely finished everything currently in the course');

        // Cleanup.
        global $wpdb;
        $wpdb->delete(BHC_Tables::progress(), ['user_id' => $uid, 'lesson_id' => $lesson_a]);
        $wpdb->delete(BHC_Tables::progress(), ['user_id' => $uid, 'lesson_id' => $lesson_b]);
        $wpdb->delete(BHC_Tables::completions(), ['user_id' => $uid, 'course_id' => $course_id]);
        wp_delete_post($lesson_a, true);
        wp_delete_post($lesson_b, true);
        wp_delete_post($course_id, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_review_tests(): array {
        $rows = [];
        global $wpdb;

        $course_id = wp_insert_post([
            'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_title' => 'Reviews Test Fixture Course', 'meta_input' => ['bhcore_is_test' => 'bhc_reviews_suite'],
        ], true);
        if (is_wp_error($course_id)) {
            return [['name' => 'Reviews test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bh_course post — skipping review tests.']];
        }

        $uid = OUS_Debug::get_or_create_test_user('bhc_reviews_suite', false);
        $enroll_table = BHC_Tables::enrollments();
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);
        $reviews_table = BHC_Tables::reviews();
        $wpdb->delete($reviews_table, ['user_id' => $uid, 'course_id' => $course_id]);

        // Not enrolled yet: some real access is still required.
        $result = BHC_Reviews::submit_review($uid, $course_id, 5, 'Great course!');
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($result), 'submit_review() rejects a user with no enrollment at all');

        // Enrolled but not completed: lands as 'pending' with completed_at_review = 0.
        $wpdb->insert($enroll_table, ['user_id' => $uid, 'course_id' => $course_id, 'enrolled_at' => current_time('mysql', true)]);
        $result = BHC_Reviews::submit_review($uid, $course_id, 5, 'Great course, still working through it.');
        $rows[] = OUS_TestRunner::assert_true($result === true, 'submit_review() succeeds for an enrolled (not yet completed) user');

        $mine = BHC_Reviews::user_review($uid, $course_id);
        $rows[] = OUS_TestRunner::assert_same('pending', $mine['status'] ?? null, 'A freshly submitted review starts as pending, never auto-approved');
        $rows[] = OUS_TestRunner::assert_same(0, (int) ($mine['completed_at_review'] ?? -1), 'completed_at_review is 0 for an enrolled-but-not-completed reviewer');

        // Rating out-of-range clamps rather than rejecting outright.
        BHC_Reviews::submit_review($uid, $course_id, 99, 'Rating way too high.');
        $clamped = BHC_Reviews::user_review($uid, $course_id);
        $rows[] = OUS_TestRunner::assert_same(5, (int) ($clamped['rating'] ?? 0), 'An out-of-range rating clamps to 5, not rejected or stored raw');

        // Not publicly counted until approved.
        $avg_before = BHC_Reviews::average_rating($course_id);
        $rows[] = OUS_TestRunner::assert_same(0, $avg_before['count'], 'A pending review does not count toward the public average rating');
        $visible = BHC_Reviews::reviews_for_course($course_id, 'approved');
        $rows[] = OUS_TestRunner::assert_same(0, count($visible), 'A pending review does not appear in the public (approved-only) review list');

        // Approved now counts; completed_at_review is a submission-time snapshot.
        $wpdb->update($reviews_table, ['status' => 'approved'], ['user_id' => $uid, 'course_id' => $course_id]);
        $avg_after = BHC_Reviews::average_rating($course_id);
        $rows[] = OUS_TestRunner::assert_same(1, $avg_after['count'], 'An approved review counts toward the public average rating');
        $rows[] = OUS_TestRunner::assert_same(5.0, $avg_after['average'], 'average_rating() reflects the single approved review\'s rating');

        // Resubmitting resets to pending and updates the same row (UNIQUE KEY user_course).
        BHC_Reviews::submit_review($uid, $course_id, 3, 'Revised opinion after finishing.');
        $after_edit = BHC_Reviews::user_review($uid, $course_id);
        $rows[] = OUS_TestRunner::assert_same('pending', $after_edit['status'] ?? null, 'Editing an approved review resets its status back to pending, not grandfathered in');
        $row_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $reviews_table WHERE user_id = %d AND course_id = %d", $uid, $course_id));
        $rows[] = OUS_TestRunner::assert_same(1, $row_count, 'Editing a review UPDATEs the existing row rather than inserting a second one');

        // Bulk average_ratings() must agree with average_rating().
        $wpdb->update($reviews_table, ['status' => 'approved'], ['user_id' => $uid, 'course_id' => $course_id]);
        $bulk = BHC_Reviews::average_ratings();
        $rows[] = OUS_TestRunner::assert_true(isset($bulk[$course_id]) && (float) $bulk[$course_id]['average'] === 3.0, 'average_ratings() bulk helper agrees with average_rating() for the same course');

        // Cleanup.
        $wpdb->delete($reviews_table, ['user_id' => $uid, 'course_id' => $course_id]);
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);
        wp_delete_post($course_id, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_gate_drip_tests(): array {
        $rows = [];
        global $wpdb;

        $course_id = wp_insert_post([
            'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_title' => 'Gate/Drip Test Fixture Course', 'meta_input' => ['bhcore_is_test' => 'bhc_gate_drip_suite'],
        ], true);
        if (is_wp_error($course_id)) {
            return [['name' => 'Gate/drip test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bh_course post — skipping gate/drip tests.']];
        }

        $undripped_lesson = wp_insert_post([
            'post_type' => 'bh_lesson', 'post_status' => 'publish',
            'post_title' => 'Undripped Fixture Lesson', 'meta_input' => ['bhcore_is_test' => 'bhc_gate_drip_suite', '_bhc_course_id' => $course_id],
        ], true);
        $delay_lesson = wp_insert_post([
            'post_type' => 'bh_lesson', 'post_status' => 'publish',
            'post_title' => 'Delay Fixture Lesson', 'meta_input' => [
                'bhcore_is_test' => 'bhc_gate_drip_suite', '_bhc_course_id' => $course_id, '_bhc_available_after_days' => 7,
            ],
        ], true);
        $date_lesson = wp_insert_post([
            'post_type' => 'bh_lesson', 'post_status' => 'publish',
            'post_title' => 'Date Fixture Lesson', 'meta_input' => [
                'bhcore_is_test' => 'bhc_gate_drip_suite', '_bhc_course_id' => $course_id,
                '_bhc_available_on_date' => gmdate('Y-m-d', strtotime('+3 days')),
            ],
        ], true);

        if (is_wp_error($undripped_lesson) || is_wp_error($delay_lesson) || is_wp_error($date_lesson)) {
            wp_delete_post($course_id, true);
            return [['name' => 'Gate/drip test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bh_lesson posts — skipping gate/drip tests.']];
        }

        update_post_meta($course_id, '_bhc_lesson_order', [$undripped_lesson, $delay_lesson, $date_lesson]);

        $uid = OUS_Debug::get_or_create_test_user('bhc_gate_drip_suite', false);

        // No enrollment yet: a relative-delay lesson fails open.
        $enroll_table = BHC_Tables::enrollments();
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);

        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $undripped_lesson), 'A lesson with no drip rule at all is always open');
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A relative-delay lesson is open for a not-yet-enrolled user (fails open, does not permanently lock)');

        // Now enroll them "today" — a 7-day delay lesson must be closed.
        $wpdb->insert($enroll_table, ['user_id' => $uid, 'course_id' => $course_id, 'enrolled_at' => current_time('mysql', true)]);
        $rows[] = OUS_TestRunner::assert_false(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A 7-day-delay lesson is locked immediately after enrollment');

        // Backdate the enrollment past the delay window (an UPDATE on the same row).
        $wpdb->update($enroll_table, ['enrolled_at' => gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS)], ['user_id' => $uid, 'course_id' => $course_id]);
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A 7-day-delay lesson opens once 8 days have passed since enrollment');

        // Fixed-date lesson: 3 days in the future must be closed; moving
        // the date to yesterday must open it.
        $rows[] = OUS_TestRunner::assert_false(BHC_Gate::lesson_is_open($uid, $date_lesson), 'A fixed-date lesson set 3 days in the future is closed');
        update_post_meta($date_lesson, '_bhc_available_on_date', gmdate('Y-m-d', strtotime('-1 day')));
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $date_lesson), 'A fixed-date lesson set to yesterday is open');

        // enrolled_user_ids() includes the fixture user and excludes a non-enrolled one.
        $enrolled_ids = BHC_Progress::enrolled_user_ids($course_id);
        $rows[] = OUS_TestRunner::assert_true(in_array((int) $uid, $enrolled_ids, true), 'enrolled_user_ids() includes a user with a real enrollment row');
        $rows[] = OUS_TestRunner::assert_false(in_array(999999999, $enrolled_ids, true), 'enrolled_user_ids() does not include an arbitrary non-enrolled user ID');

        // course_progress_matrix(): its three views must agree with a quiz-shaped write.
        BHC_Progress::mark_step_complete($uid, $undripped_lesson, 0, 80, true);
        $matrix = BHC_Progress::course_progress_matrix($course_id);
        $rows[] = OUS_TestRunner::assert_true(in_array((int) $uid, $matrix['user_ids'], true), 'course_progress_matrix() includes a user with a real progress row');
        $rows[] = OUS_TestRunner::assert_same([0], $matrix['completed'][$uid][$undripped_lesson] ?? null, 'course_progress_matrix() records the completed step index for the right user/lesson');
        $rows[] = OUS_TestRunner::assert_same([80.0], $matrix['quiz_scores'][$undripped_lesson][0] ?? null, 'course_progress_matrix() records the quiz score for the right lesson/step');
        $rows[] = OUS_TestRunner::assert_true(!empty($matrix['last_activity'][$uid]), 'course_progress_matrix() records a last-activity timestamp for the user');

        // Course not completed yet, so the user must not appear.
        $completed_ids = BHC_Progress::completed_user_ids($course_id, [$uid]);
        $rows[] = OUS_TestRunner::assert_false(isset($completed_ids[$uid]), 'completed_user_ids() does not mark a user complete who has not finished the course');

        // Cleanup: these are published posts that would otherwise show in the catalog.
        $progress_table = BHC_Tables::progress();
        $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $undripped_lesson]);
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);
        wp_delete_post($undripped_lesson, true);
        wp_delete_post($delay_lesson, true);
        wp_delete_post($date_lesson, true);
        wp_delete_post($course_id, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_catalog_tests(): array {
        $rows = [];
        $saved_get = $_GET;

        $course_a = wp_insert_post([
            'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_title' => 'Zebra Mixing Fundamentals', 'meta_input' => ['bhcore_is_test' => 'bhc_catalog_suite'],
        ], true);
        $course_b = wp_insert_post([
            'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_title' => 'Aardvark Mastering Basics', 'meta_input' => ['bhcore_is_test' => 'bhc_catalog_suite'],
        ], true);

        if (is_wp_error($course_a) || is_wp_error($course_b)) {
            return [['name' => 'Catalog test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bh_course posts — skipping catalog tests.']];
        }

        // Title sort must beat insertion/ID order (Zebra was inserted first).
        $_GET = ['bhc_sort' => 'alpha'];
        $html = BHC_Render::render_catalog();
        $pos_a = strpos($html, 'Aardvark Mastering Basics');
        $pos_z = strpos($html, 'Zebra Mixing Fundamentals');
        $rows[] = OUS_TestRunner::assert_true(
            $pos_a !== false && $pos_z !== false && $pos_a < $pos_z,
            'sort=alpha renders "Aardvark..." before "Zebra..." (real A-Z title order, not creation/ID order)'
        );

        // A keyword matching only one fixture excludes the other.
        $_GET = ['bhc_s' => 'Zebra'];
        $html_search = BHC_Render::render_catalog();
        $rows[] = OUS_TestRunner::assert_true(strpos($html_search, 'Zebra Mixing Fundamentals') !== false, 'search "Zebra" includes the matching course');
        $rows[] = OUS_TestRunner::assert_false(strpos($html_search, 'Aardvark Mastering Basics') !== false, 'search "Zebra" excludes the non-matching course');

        // No matches renders the shared BHY_Style::empty_state_html() component.
        $_GET = ['bhc_s' => 'ThisStringMatchesNoFixtureCourseTitleAtAll12345'];
        $html_empty = BHC_Render::render_catalog();
        $has_empty_state = strpos($html_empty, 'bhy-empty-state') !== false || strpos($html_empty, 'bhc-empty') !== false;
        $rows[] = OUS_TestRunner::assert_true($has_empty_state, 'a search matching nothing renders the empty-state message, not a fatal or the full unfiltered list');

        $_GET = $saved_get;

        // Cleanup: real published posts.
        wp_delete_post($course_a, true);
        wp_delete_post($course_b, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_progress_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhc_progress_suite', false);
        $lesson_id = 999999001; // a fake lesson ID — bhc_progress has no FK constraint to bh_lesson, so this is safe and avoids needing a real post fixture
        $table = BHC_Tables::progress();

        // Clean slate for this fake user/lesson pair before asserting anything.
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_id]);

        $answers_payload = wp_json_encode([
            'score' => 100, 'passed' => true, 'passing_score' => 70,
            'questions' => [['q' => 'Q1', 'choices' => ['A', 'B'], 'correct_index' => 0, 'chosen_index' => 0]],
        ]);
        BHC_Progress::mark_step_complete($uid, $lesson_id, 0, 100, true, $answers_payload);

        $row_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND lesson_id = %d AND step_index = %d", $uid, $lesson_id, 0
        ));
        $rows[] = OUS_TestRunner::assert_same(1, $row_exists, 'mark_step_complete() with an answers payload writes exactly one progress row');

        $stored = BHC_Progress::stored_answers($uid, $lesson_id, 0);
        $rows[] = OUS_TestRunner::assert_true(is_array($stored) && !empty($stored['questions']), 'stored_answers() decodes the JSON snapshot back into a real array with a questions key');
        $rows[] = OUS_TestRunner::assert_same(0, $stored['questions'][0]['chosen_index'] ?? null, 'stored_answers() round-trip preserves the exact chosen_index recorded at submission time');
        $rows[] = OUS_TestRunner::assert_same(100, $stored['score'] ?? null, 'stored_answers() round-trip preserves the score');

        // A plain step must store real SQL NULLs, and stored_answers() returns null.
        BHC_Progress::mark_step_complete($uid, $lesson_id, 1, null, null, null);
        $plain_passed = $wpdb->get_var($wpdb->prepare(
            "SELECT passed FROM $table WHERE user_id = %d AND lesson_id = %d AND step_index = %d", $uid, $lesson_id, 1
        ));
        $rows[] = OUS_TestRunner::assert_same(null, $plain_passed, 'A plain (non-quiz) step writes a real SQL NULL for passed, not 0');
        $rows[] = OUS_TestRunner::assert_same(null, BHC_Progress::stored_answers($uid, $lesson_id, 1), 'stored_answers() on a plain step with no answers column returns null, not an error');

        // A retry updates in place (latest-attempt-only), not a second row.
        BHC_Progress::mark_step_complete($uid, $lesson_id, 0, 50, false, wp_json_encode(['score' => 50, 'passed' => false, 'questions' => []]));
        $row_count_after_retry = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND lesson_id = %d AND step_index = %d", $uid, $lesson_id, 0
        ));
        $rows[] = OUS_TestRunner::assert_same(1, $row_count_after_retry, 'a second attempt at the same step updates the existing row rather than inserting a second one');
        $latest = BHC_Progress::stored_answers($uid, $lesson_id, 0);
        $rows[] = OUS_TestRunner::assert_same(50, $latest['score'] ?? null, 'after a retry, stored_answers() reflects the LATEST attempt, not the first');

        // Cleanup.
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_id]);

        return $rows;
    }

    // course_quiz_average() reads lesson IDs from a real course's _bhc_lesson_order,
    // so a real fixture course points at the fake lesson IDs.
    /** @return array<int, array<string, mixed>> */
    private static function run_quiz_average_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhc_quiz_average_suite', false);
        $table = BHC_Tables::progress();
        $lesson_a = 999999101;
        $lesson_b = 999999102;

        $course_id = wp_insert_post(['post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => 'BHC Test Suite Fixture Course'], true);
        if (is_wp_error($course_id)) {
            return [['name' => 'BHC_TestSuite quiz-average fixture course insert failed', 'pass' => false, 'message' => '']];
        }
        update_post_meta($course_id, '_bhc_lesson_order', [$lesson_a, $lesson_b]);

        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_a]);
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_b]);

        $rows[] = OUS_TestRunner::assert_same(
            null, BHC_Progress::course_quiz_average($uid, $course_id),
            'course_quiz_average(): a student with zero quiz attempts in this course returns null, never a bare 0%'
        );

        // Only the quiz step (non-NULL score) counts toward the average.
        BHC_Progress::mark_step_complete($uid, $lesson_a, 0, 80, true, wp_json_encode(['score' => 80, 'passed' => true, 'questions' => []]));
        BHC_Progress::mark_step_complete($uid, $lesson_a, 1, null, null, null);
        $rows[] = OUS_TestRunner::assert_same(
            80, BHC_Progress::course_quiz_average($uid, $course_id),
            'course_quiz_average(): a single scored quiz step is the average on its own; the plain (NULL-score) step is correctly excluded'
        );

        BHC_Progress::mark_step_complete($uid, $lesson_b, 0, 100, true, wp_json_encode(['score' => 100, 'passed' => true, 'questions' => []]));
        $rows[] = OUS_TestRunner::assert_same(
            90, BHC_Progress::course_quiz_average($uid, $course_id),
            'course_quiz_average(): averages across every quiz step in the course (80 + 100) / 2 = 90, catching an int-division or wrong-scope regression'
        );

        // A retry overwrites the same row; the average reflects the new score.
        BHC_Progress::mark_step_complete($uid, $lesson_a, 0, 60, false, wp_json_encode(['score' => 60, 'passed' => false, 'questions' => []]));
        $rows[] = OUS_TestRunner::assert_same(
            80, BHC_Progress::course_quiz_average($uid, $course_id),
            'course_quiz_average(): a retry replaces the prior score for that step rather than being averaged as a second data point (60 + 100) / 2 = 80'
        );

        // Cleanup.
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_a]);
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_b]);
        wp_delete_post($course_id, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_achievement_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhc_achievements_suite', false);
        $table = BHC_Tables::achievements();
        $wpdb->delete($table, ['user_id' => $uid]);

        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::award($uid, BHC_Achievements::FIRST_QUIZ_ACED),
            'award(): a brand-new achievement is newly earned (returns true)'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHC_Achievements::award($uid, BHC_Achievements::FIRST_QUIZ_ACED),
            'award(): awarding the same global achievement twice is a no-op the second time — the UNIQUE KEY, not an application-level check, is what enforces this'
        );
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::FIRST_QUIZ_ACED),
            'has(): reads back the award just made'
        );

        // maybe_award_quiz_aced() — the mark_step_complete() hook point.
        $wpdb->delete($table, ['user_id' => $uid, 'achievement_key' => BHC_Achievements::FIRST_QUIZ_ACED]);
        BHC_Achievements::maybe_award_quiz_aced($uid, 90);
        $rows[] = OUS_TestRunner::assert_false(
            BHC_Achievements::has($uid, BHC_Achievements::FIRST_QUIZ_ACED),
            'maybe_award_quiz_aced(): a 90% score does not award — only a perfect 100 counts as "aced"'
        );
        BHC_Achievements::maybe_award_quiz_aced($uid, 100);
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::FIRST_QUIZ_ACED),
            'maybe_award_quiz_aced(): a 100% score awards first_quiz_aced'
        );

        // on_course_completed(): per-course distinction, then "3 courses mastered" on the
        // third. One fixture course is deliberately below the threshold.
        $progress_table = BHC_Tables::progress();
        $course_ids = [];
        $lesson_ids = [999999201, 999999202, 999999203, 999999204];
        for ($i = 0; $i < 4; $i++) {
            $course_id = wp_insert_post(['post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => 'BHC Achievements Suite Fixture Course ' . $i], true);
            if (is_wp_error($course_id)) {
                return array_merge($rows, [['name' => 'BHC_TestSuite achievements fixture course insert failed', 'pass' => false, 'message' => '']]);
            }
            $course_ids[] = $course_id;
            update_post_meta($course_id, '_bhc_lesson_order', [$lesson_ids[$i]]);
            $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $lesson_ids[$i]]);
        }

        // Course 0: below the distinction threshold — no badge.
        BHC_Progress::mark_step_complete($uid, $lesson_ids[0], 0, 80, true, wp_json_encode(['score' => 80, 'passed' => true, 'questions' => []]));
        BHC_Achievements::on_course_completed($uid, $course_ids[0]);
        $rows[] = OUS_TestRunner::assert_false(
            BHC_Achievements::has($uid, BHC_Achievements::COURSE_DISTINCTION, $course_ids[0]),
            'on_course_completed(): an 80% quiz average (below the 90% default threshold) does not earn course_distinction'
        );

        // Distinction earned, but "3 mastered" waits for the third.
        BHC_Progress::mark_step_complete($uid, $lesson_ids[1], 0, 95, true, wp_json_encode(['score' => 95, 'passed' => true, 'questions' => []]));
        BHC_Achievements::on_course_completed($uid, $course_ids[1]);
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::COURSE_DISTINCTION, $course_ids[1]),
            'on_course_completed(): a 95% quiz average earns course_distinction for that course'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHC_Achievements::has($uid, BHC_Achievements::COURSES_MASTERED_3),
            'on_course_completed(): courses_mastered_3 does not fire after only 1 distinction'
        );

        BHC_Progress::mark_step_complete($uid, $lesson_ids[2], 0, 92, true, wp_json_encode(['score' => 92, 'passed' => true, 'questions' => []]));
        BHC_Achievements::on_course_completed($uid, $course_ids[2]);
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::COURSE_DISTINCTION, $course_ids[2]),
            'on_course_completed(): a 2nd course clears the threshold and earns its own distinction'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHC_Achievements::has($uid, BHC_Achievements::COURSES_MASTERED_3),
            'on_course_completed(): courses_mastered_3 still does not fire after only 2 distinctions'
        );

        BHC_Progress::mark_step_complete($uid, $lesson_ids[3], 0, 100, true, wp_json_encode(['score' => 100, 'passed' => true, 'questions' => []]));
        BHC_Achievements::on_course_completed($uid, $course_ids[3]);
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::COURSE_DISTINCTION, $course_ids[3]),
            'on_course_completed(): a 3rd course also clears the threshold and earns its own distinction'
        );
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Achievements::has($uid, BHC_Achievements::COURSES_MASTERED_3),
            'on_course_completed(): the 3rd distinction rolls up into courses_mastered_3'
        );

        // Cleanup.
        foreach ($lesson_ids as $lid) $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $lid]);
        foreach ($course_ids as $cid) wp_delete_post($cid, true);
        $wpdb->delete($table, ['user_id' => $uid]);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_leaderboard_tests(): array {
        $rows = [];
        global $wpdb;
        $progress_table = BHC_Tables::progress();
        $enroll_table = BHC_Tables::enrollments();
        $lesson_id = 999999301;

        $course_id = wp_insert_post(['post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => 'BHC Leaderboard Suite Fixture Course'], true);
        if (is_wp_error($course_id)) {
            return [['name' => 'BHC_TestSuite leaderboard fixture course insert failed', 'pass' => false, 'message' => '']];
        }
        update_post_meta($course_id, '_bhc_lesson_order', [$lesson_id]);

        $uid_a = OUS_Debug::get_or_create_test_user('bhc_leaderboard_a', false);
        $uid_b = OUS_Debug::get_or_create_test_user('bhc_leaderboard_b', false);
        $uid_c = OUS_Debug::get_or_create_test_user('bhc_leaderboard_c', false);
        foreach ([$uid_a, $uid_b, $uid_c] as $uid) $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $lesson_id]);

        $rows[] = OUS_TestRunner::assert_false(
            BHC_Leaderboard::is_enabled($course_id),
            'is_enabled(): off by default on a freshly-created course'
        );
        update_post_meta($course_id, '_bhc_leaderboard_enabled', 1);
        $rows[] = OUS_TestRunner::assert_true(
            BHC_Leaderboard::is_enabled($course_id),
            'is_enabled(): reads back the opt-in checkbox once set'
        );

        // A=100 (rank 1); B and C tie at 80 (both rank 2).
        $wpdb->insert($enroll_table, ['user_id' => $uid_a, 'course_id' => $course_id]);
        $wpdb->insert($enroll_table, ['user_id' => $uid_b, 'course_id' => $course_id]);
        $wpdb->insert($enroll_table, ['user_id' => $uid_c, 'course_id' => $course_id]);
        BHC_Progress::mark_step_complete($uid_a, $lesson_id, 0, 100, true, wp_json_encode(['score' => 100, 'passed' => true, 'questions' => []]));
        BHC_Progress::mark_step_complete($uid_b, $lesson_id, 0, 80, true, wp_json_encode(['score' => 80, 'passed' => true, 'questions' => []]));
        BHC_Progress::mark_step_complete($uid_c, $lesson_id, 0, 80, true, wp_json_encode(['score' => 80, 'passed' => true, 'questions' => []]));

        $scores = BHC_Leaderboard::top_scorers($course_id);
        $rows[] = OUS_TestRunner::assert_same(3, count($scores), 'top_scorers(): all 3 enrolled students who attempted a quiz appear');
        $rows[] = OUS_TestRunner::assert_same(1, $scores[0]['rank'] ?? null, 'top_scorers(): the 100% score is rank 1');
        $ranks_by_user = [];
        foreach ($scores as $s) $ranks_by_user[$s['user_id']] = $s['rank'];
        $rows[] = OUS_TestRunner::assert_same(2, $ranks_by_user[$uid_b] ?? null, 'top_scorers(): a tied 80% score is rank 2, not 3');
        $rows[] = OUS_TestRunner::assert_same(2, $ranks_by_user[$uid_c] ?? null, 'top_scorers(): both tied students share rank 2');

        // A lower score after a 2-way tie lands on rank 4.
        $uid_d = OUS_Debug::get_or_create_test_user('bhc_leaderboard_d', false);
        $wpdb->delete($progress_table, ['user_id' => $uid_d, 'lesson_id' => $lesson_id]);
        $wpdb->insert($enroll_table, ['user_id' => $uid_d, 'course_id' => $course_id]);
        BHC_Progress::mark_step_complete($uid_d, $lesson_id, 0, 50, false, wp_json_encode(['score' => 50, 'passed' => false, 'questions' => []]));
        $scores = BHC_Leaderboard::top_scorers($course_id);
        $ranks_by_user = [];
        foreach ($scores as $s) $ranks_by_user[$s['user_id']] = $s['rank'];
        $rows[] = OUS_TestRunner::assert_same(4, $ranks_by_user[$uid_d] ?? null, 'top_scorers(): the next distinct score after a 2-way tie is rank 4, correctly accounting for both tied positions above it');

        // A student with no quiz attempts doesn't occupy a slot.
        $uid_e = OUS_Debug::get_or_create_test_user('bhc_leaderboard_e', false);
        $wpdb->delete($progress_table, ['user_id' => $uid_e, 'lesson_id' => $lesson_id]);
        $wpdb->insert($enroll_table, ['user_id' => $uid_e, 'course_id' => $course_id]);
        $scores = BHC_Leaderboard::top_scorers($course_id);
        $rows[] = OUS_TestRunner::assert_same(4, count($scores), 'top_scorers(): an enrolled student with zero quiz attempts is excluded entirely, never a bare 0%');

        // Cleanup.
        foreach ([$uid_a, $uid_b, $uid_c, $uid_d, $uid_e] as $uid) {
            $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $lesson_id]);
            $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);
        }
        wp_delete_post($course_id, true);

        return $rows;
    }
}
