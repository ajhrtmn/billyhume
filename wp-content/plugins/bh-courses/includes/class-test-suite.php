<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see the-self-hosted-self's class-test-runner.php) version of
 * tests/QuizScoringTest.php and tests/StepsSanitizationTest.php — same
 * cases and reasoning, runnable straight from Debug Tools on this
 * site's own PHP with no CLI/PHPUnit needed. class_exists()-guarded
 * registration below means an older core without OUS_TestRunner just
 * never sees this suite offered — harmless no-op, same as every other
 * optional integration in this ecosystem.
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

        /* ---------- video annotations (ROADMAP-lms-v3.md Section 1) ---------- */

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
         * No coverage existed for this before — mark_step_complete()'s
         * answers-JSON persistence and stored_answers()'s round-trip
         * were added this session (the quiz-review UX feature) and had
         * zero test coverage until now. Runs against a real, tagged
         * fake user + real bhc_progress rows, cleaned up afterward. */
        if (class_exists('BHC_Progress') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_progress_tests());
            $rows = array_merge($rows, self::run_quiz_average_tests());
        }

        /* ---------- catalog search/sort (BHC_Render::render_catalog()) ----------
         * No coverage existed for this before — the whole search/filter/
         * sort/pagination rebuild this session had zero test coverage.
         * render_catalog() reads $_GET directly and queries real
         * bh_course posts, so this is a real integration test against
         * two tagged fixture courses rather than a pure-logic unit test
         * — there's no smaller seam to test this through without
         * duplicating WP_Query's own behavior in a mock. */
        if (class_exists('BHC_Render')) {
            $rows = array_merge($rows, self::run_catalog_tests());
        }

        /* ---------- gate/drip/progress-matrix interaction ----------
         * The plugin's own audit flagged this as the highest-blast-
         * radius, least-tested surface: BHC_Gate::lesson_is_open()'s
         * two drip shapes (relative delay vs. fixed date), the "no
         * enrollment yet = fails open" rule, and the batched
         * course_progress_matrix()/completed_user_ids()/
         * enrolled_user_ids() queries added for the Student Progress
         * admin page's N+1 fix — none of it had any test coverage
         * before this. Runs against a real, tagged fixture course +
         * two fixture lessons + a real enrollment row, cleaned up
         * afterward. */
        if (class_exists('BHC_Gate') && class_exists('BHC_Progress') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_gate_drip_tests());
        }

        /* ---------- reviews (BHC_Reviews) ----------
         * New this pass — course reviews/ratings, previously explicitly
         * deferred with no data model at all. Runs against a real,
         * tagged fixture course + a real enrollment row, cleaned up
         * afterward. */
        if (class_exists('BHC_Reviews') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_review_tests());
        }

        /* ---------- achievements (BHC_Achievements) ----------
         * LMS depth-of-magic Phase 3 — the first genuinely new schema
         * this plugin's added all session (bhc_achievements). Runs
         * directly against the class's own award()/count()/
         * on_course_completed() methods (not through the real AJAX/
         * action-firing path) — same isolated-unit-test posture
         * run_quiz_average_tests() already uses for course_quiz_average(),
         * to avoid dragging in course_percent()/BH_Event/notification
         * side effects unrelated to what's actually being tested here.
         * Real, tagged fixture user + real bhc_achievements rows,
         * cleaned up afterward. */
        if (class_exists('BHC_Achievements') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_achievement_tests());
        }

        /* ---------- leaderboard (BHC_Leaderboard) ----------
         * LMS depth-of-magic Phase 4 — the tie-handling competition-rank
         * logic (1, 1, 3 — not 1, 1, 2) is the one piece of this feature
         * worth real test coverage; the enrollment/quiz-average plumbing
         * around it is already covered by run_quiz_average_tests() and
         * run_gate_drip_tests(). Real, tagged fixture users + a real
         * fixture course, cleaned up afterward. */
        if (class_exists('BHC_Leaderboard') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_leaderboard_tests());
        }

        /* ---------- is_course_completed() vs a course whose content changed after completion ----------
         * Bug found via live walkthrough (2026-07-26): a bhc_completions
         * row is permanent once written, so adding a new lesson to an
         * already-completed course left is_course_completed() (and
         * every one of its 7 callers — certificate download, share
         * cards, the completion-screen banner, etc.) still reporting
         * "done" against content the student never saw. Fixed by
         * requiring course_percent() === 100 too, not just the row's
         * existence. Real fixture course + real lesson posts (course_
         * percent() needs BHC_Steps::count() to read real _bhc_steps
         * postmeta, unlike run_quiz_average_tests()'s fake lesson IDs
         * further up this file, which only ever exercise bhc_progress
         * directly). */
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

        // The bug scenario: a new lesson gets added to the course AFTER
        // this student already completed it.
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

        // Not enrolled yet — eligibility is enrollment, not completion,
        // but SOME real access is still required (not a free-for-all).
        $result = BHC_Reviews::submit_review($uid, $course_id, 5, 'Great course!');
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($result), 'submit_review() rejects a user with no enrollment at all');

        // Enroll (not completing the course) and submit — should
        // succeed, land as 'pending', and record completed_at_review=0
        // since this user has not finished the course.
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

        // A review isn't publicly visible (average/reviews_for_course)
        // until approved — the moderation gate is real, not cosmetic.
        $avg_before = BHC_Reviews::average_rating($course_id);
        $rows[] = OUS_TestRunner::assert_same(0, $avg_before['count'], 'A pending review does not count toward the public average rating');
        $visible = BHC_Reviews::reviews_for_course($course_id, 'approved');
        $rows[] = OUS_TestRunner::assert_same(0, count($visible), 'A pending review does not appear in the public (approved-only) review list');

        // Approve it (simulating the admin moderation action) — now it
        // should count, and completed_at_review should NOT retroactively
        // change even if the user completes the course afterward
        // (it's a snapshot at submission time, not a live recompute).
        $wpdb->update($reviews_table, ['status' => 'approved'], ['user_id' => $uid, 'course_id' => $course_id]);
        $avg_after = BHC_Reviews::average_rating($course_id);
        $rows[] = OUS_TestRunner::assert_same(1, $avg_after['count'], 'An approved review counts toward the public average rating');
        $rows[] = OUS_TestRunner::assert_same(5.0, $avg_after['average'], 'average_rating() reflects the single approved review\'s rating');

        // Editing/resubmitting an already-approved review resets it back
        // to pending (re-moderation), and UPDATEs the same row rather
        // than creating a second one (UNIQUE KEY user_course).
        BHC_Reviews::submit_review($uid, $course_id, 3, 'Revised opinion after finishing.');
        $after_edit = BHC_Reviews::user_review($uid, $course_id);
        $rows[] = OUS_TestRunner::assert_same('pending', $after_edit['status'] ?? null, 'Editing an approved review resets its status back to pending, not grandfathered in');
        $row_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $reviews_table WHERE user_id = %d AND course_id = %d", $uid, $course_id));
        $rows[] = OUS_TestRunner::assert_same(1, $row_count, 'Editing a review UPDATEs the existing row rather than inserting a second one');

        // average_ratings() — the bulk sibling used by the catalog's
        // "Highest rated" sort — must agree with the single-course
        // average_rating() once the edited review above is re-approved.
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

        // No enrollment recorded yet — a relative-delay lesson must fail
        // OPEN (not locked), per lesson_is_open()'s own documented
        // reasoning: nothing to count the delay from yet, so it must
        // not permanently lock someone the system never enrolled.
        $enroll_table = BHC_Tables::enrollments();
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);

        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $undripped_lesson), 'A lesson with no drip rule at all is always open');
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A relative-delay lesson is open for a not-yet-enrolled user (fails open, does not permanently lock)');

        // Now enroll them "today" — a 7-day delay lesson must be closed.
        $wpdb->insert($enroll_table, ['user_id' => $uid, 'course_id' => $course_id, 'enrolled_at' => current_time('mysql', true)]);
        $rows[] = OUS_TestRunner::assert_false(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A 7-day-delay lesson is locked immediately after enrollment');

        // Backdate the enrollment past the delay window — same row,
        // same UNIQUE KEY (user_id, course_id), so this is an UPDATE.
        $wpdb->update($enroll_table, ['enrolled_at' => gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS)], ['user_id' => $uid, 'course_id' => $course_id]);
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $delay_lesson), 'A 7-day-delay lesson opens once 8 days have passed since enrollment');

        // Fixed-date lesson: 3 days in the future must be closed; moving
        // the date to yesterday must open it.
        $rows[] = OUS_TestRunner::assert_false(BHC_Gate::lesson_is_open($uid, $date_lesson), 'A fixed-date lesson set 3 days in the future is closed');
        update_post_meta($date_lesson, '_bhc_available_on_date', gmdate('Y-m-d', strtotime('-1 day')));
        $rows[] = OUS_TestRunner::assert_true(BHC_Gate::lesson_is_open($uid, $date_lesson), 'A fixed-date lesson set to yesterday is open');

        // enrolled_user_ids() — the helper added for BHC_DripNudges/the
        // Student Progress N+1 fix — must include this fixture user and
        // must NOT include an arbitrary user who was never enrolled.
        $enrolled_ids = BHC_Progress::enrolled_user_ids($course_id);
        $rows[] = OUS_TestRunner::assert_true(in_array((int) $uid, $enrolled_ids, true), 'enrolled_user_ids() includes a user with a real enrollment row');
        $rows[] = OUS_TestRunner::assert_false(in_array(999999999, $enrolled_ids, true), 'enrolled_user_ids() does not include an arbitrary non-enrolled user ID');

        // course_progress_matrix() — mark one step complete on the
        // undripped lesson (a quiz-shaped write, since that's the only
        // way this table records a score) and confirm the matrix's
        // three views (completed/last_activity/quiz_scores) all agree
        // with what was just written, matching what the per-user
        // methods (completed_steps()/course_percent()) would report.
        BHC_Progress::mark_step_complete($uid, $undripped_lesson, 0, 80, true);
        $matrix = BHC_Progress::course_progress_matrix($course_id);
        $rows[] = OUS_TestRunner::assert_true(in_array((int) $uid, $matrix['user_ids'], true), 'course_progress_matrix() includes a user with a real progress row');
        $rows[] = OUS_TestRunner::assert_same([0], $matrix['completed'][$uid][$undripped_lesson] ?? null, 'course_progress_matrix() records the completed step index for the right user/lesson');
        $rows[] = OUS_TestRunner::assert_same([80.0], $matrix['quiz_scores'][$undripped_lesson][0] ?? null, 'course_progress_matrix() records the quiz score for the right lesson/step');
        $rows[] = OUS_TestRunner::assert_true(!empty($matrix['last_activity'][$uid]), 'course_progress_matrix() records a last-activity timestamp for the user');

        // completed_user_ids() — course not actually completed yet, so
        // this user must NOT show up as completed.
        $completed_ids = BHC_Progress::completed_user_ids($course_id, [$uid]);
        $rows[] = OUS_TestRunner::assert_false(isset($completed_ids[$uid]), 'completed_user_ids() does not mark a user complete who has not finished the course');

        // Cleanup — real posts/rows, not just meta tags, since these
        // are published posts that would otherwise appear in the real
        // catalog/course list.
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

        // Alphabetical sort: 'Aardvark...' must render before 'Zebra...'
        // in the returned HTML string, regardless of which was created
        // first (post ID order would put Zebra first, since it was
        // inserted first above — this specifically catches a sort that
        // silently fell back to date/ID order instead of title).
        $_GET = ['bhc_sort' => 'alpha'];
        $html = BHC_Render::render_catalog();
        $pos_a = strpos($html, 'Aardvark Mastering Basics');
        $pos_z = strpos($html, 'Zebra Mixing Fundamentals');
        $rows[] = OUS_TestRunner::assert_true(
            $pos_a !== false && $pos_z !== false && $pos_a < $pos_z,
            'sort=alpha renders "Aardvark..." before "Zebra..." (real A-Z title order, not creation/ID order)'
        );

        // Search: a keyword matching only one fixture's title should
        // exclude the other from the rendered output entirely.
        $_GET = ['bhc_s' => 'Zebra'];
        $html_search = BHC_Render::render_catalog();
        $rows[] = OUS_TestRunner::assert_true(strpos($html_search, 'Zebra Mixing Fundamentals') !== false, 'search "Zebra" includes the matching course');
        $rows[] = OUS_TestRunner::assert_false(strpos($html_search, 'Aardvark Mastering Basics') !== false, 'search "Zebra" excludes the non-matching course');

        // A search matching nothing at all should render the empty-state
        // message, not a fatal or an unfiltered full list. Stale test,
        // caught by this exact assertion actually failing in a real
        // environment: render_catalog()'s empty branch was upgraded to
        // the shared BHY_Style::empty_state_html() component a while
        // back (real title/description/CTA, not a bare message) —
        // BHY_Style is always loaded in a real environment (the-self-hosted-self
        // is a hard dependency), so the fallback '<p class="bhc-empty">'
        // markup this assertion was still checking for never actually
        // renders anymore. Checks the real component's class now,
        // rather than reverting working, better production code to
        // satisfy a stale check.
        $_GET = ['bhc_s' => 'ThisStringMatchesNoFixtureCourseTitleAtAll12345'];
        $html_empty = BHC_Render::render_catalog();
        $has_empty_state = strpos($html_empty, 'bhy-empty-state') !== false || strpos($html_empty, 'bhc-empty') !== false;
        $rows[] = OUS_TestRunner::assert_true($has_empty_state, 'a search matching nothing renders the empty-state message, not a fatal or the full unfiltered list');

        $_GET = $saved_get;

        // Cleanup — real wp_delete_post(), not just a meta tag, since
        // these are real published posts that would otherwise show up
        // in the actual site catalog.
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

        // A plain (non-quiz) step — score/passed/answers all null — must
        // still correctly write real SQL NULLs (see mark_step_complete()'s
        // own docblock re: the %d/%s NULL-passthrough bug this exact
        // shape was written to catch) and stored_answers() must degrade
        // to null, not throw or return a malformed array.
        BHC_Progress::mark_step_complete($uid, $lesson_id, 1, null, null, null);
        $plain_passed = $wpdb->get_var($wpdb->prepare(
            "SELECT passed FROM $table WHERE user_id = %d AND lesson_id = %d AND step_index = %d", $uid, $lesson_id, 1
        ));
        $rows[] = OUS_TestRunner::assert_same(null, $plain_passed, 'A plain (non-quiz) step writes a real SQL NULL for passed, not 0');
        $rows[] = OUS_TestRunner::assert_same(null, BHC_Progress::stored_answers($uid, $lesson_id, 1), 'stored_answers() on a plain step with no answers column returns null, not an error');

        // A retry (same user/lesson/step) should UPDATE in place (latest-
        // attempt-only semantics — see the answers column's own docblock
        // in class-activator.php for why this is deliberately NOT an
        // append-only log), not create a second row.
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

    // course_quiz_average() (the "Depth of Magic" certificate-distinction
    // / mastery-signal feature) — fake lesson IDs same as
    // run_progress_tests() above (bhc_progress has no FK to bh_lesson),
    // but this method reads lesson IDs from a real course's own
    // _bhc_lesson_order postmeta (BHC_PostTypes::lesson_order()), so a
    // real fixture bh_course post is needed to point at them.
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

        // A quiz step (real score) and a plain step (score stays NULL —
        // see mark_step_complete()'s own NULL-passthrough fix) in the
        // same course: only the quiz step should count toward the average.
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

        // A retry overwrites the same row (latest-attempt-only semantics,
        // same rule run_progress_tests() above already covers) — the
        // average must reflect the NEW score, not double-count the old one.
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

        // on_course_completed() — distinction per course, then the
        // rolled-up "3 courses mastered" once a 3rd distinct course
        // clears the same bar. Four real, tagged fixture courses: one
        // deliberately below the threshold (proves distinction isn't
        // handed out for free), three above it (the actual countdown to
        // the rollup) — since course_quiz_average() (which this method
        // calls) reads real bhc_progress rows scoped by the course's own
        // lesson order.
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

        // Courses 1 and 2: at/above threshold — distinction earned, but
        // "3 mastered" should not fire until the THIRD one lands.
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

        // A scores 100 (rank 1), B and C tie at 80 (both rank 2 — a real
        // tie must NOT skip to rank 3 for the second one).
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

        // A 4th, lower score after a 2-way tie must land on rank 4, not
        // rank 3 — the tie above consumed both the "2" and "3" positions.
        $uid_d = OUS_Debug::get_or_create_test_user('bhc_leaderboard_d', false);
        $wpdb->delete($progress_table, ['user_id' => $uid_d, 'lesson_id' => $lesson_id]);
        $wpdb->insert($enroll_table, ['user_id' => $uid_d, 'course_id' => $course_id]);
        BHC_Progress::mark_step_complete($uid_d, $lesson_id, 0, 50, false, wp_json_encode(['score' => 50, 'passed' => false, 'questions' => []]));
        $scores = BHC_Leaderboard::top_scorers($course_id);
        $ranks_by_user = [];
        foreach ($scores as $s) $ranks_by_user[$s['user_id']] = $s['rank'];
        $rows[] = OUS_TestRunner::assert_same(4, $ranks_by_user[$uid_d] ?? null, 'top_scorers(): the next distinct score after a 2-way tie is rank 4, correctly accounting for both tied positions above it');

        // A student who never attempted a quiz doesn't occupy a slot —
        // enroll a 5th student, don't score them at all.
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
