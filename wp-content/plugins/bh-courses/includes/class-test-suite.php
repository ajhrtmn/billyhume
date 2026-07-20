<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see own-ur-shit's class-test-runner.php) version of
 * tests/QuizScoringTest.php and tests/StepsSanitizationTest.php — same
 * cases and reasoning, runnable straight from Debug Tools on this
 * site's own PHP with no CLI/PHPUnit needed. class_exists()-guarded
 * registration below means an older core without OUS_TestRunner just
 * never sees this suite offered — harmless no-op, same as every other
 * optional integration in this ecosystem.
 */
class BHC_TestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-courses'] = ['label' => 'BH Courses', 'callback' => [self::class, 'run']];
        return $suites;
    }

    private static function q($correct_index, $choices = ['A', 'B', 'C']) {
        return ['question' => 'Q', 'choices' => $choices, 'correct_index' => $correct_index];
    }

    public static function run() {
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

        return $rows;
    }

    private static function run_gate_drip_tests() {
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
        $enroll_table = $wpdb->prefix . 'bhc_enrollments';
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
        BHC_Progress::mark_step_complete($uid, $undripped_lesson, 0, 80, 1);
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
        $progress_table = $wpdb->prefix . 'bhc_progress';
        $wpdb->delete($progress_table, ['user_id' => $uid, 'lesson_id' => $undripped_lesson]);
        $wpdb->delete($enroll_table, ['user_id' => $uid, 'course_id' => $course_id]);
        wp_delete_post($undripped_lesson, true);
        wp_delete_post($delay_lesson, true);
        wp_delete_post($date_lesson, true);
        wp_delete_post($course_id, true);

        return $rows;
    }

    private static function run_catalog_tests() {
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
        // BHY_Style is always loaded in a real environment (own-ur-shit
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

    private static function run_progress_tests() {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhc_progress_suite', false);
        $lesson_id = 999999001; // a fake lesson ID — bhc_progress has no FK constraint to bh_lesson, so this is safe and avoids needing a real post fixture
        $table = $wpdb->prefix . 'bhc_progress';

        // Clean slate for this fake user/lesson pair before asserting anything.
        $wpdb->delete($table, ['user_id' => $uid, 'lesson_id' => $lesson_id]);

        $answers_payload = wp_json_encode([
            'score' => 100, 'passed' => true, 'passing_score' => 70,
            'questions' => [['q' => 'Q1', 'choices' => ['A', 'B'], 'correct_index' => 0, 'chosen_index' => 0]],
        ]);
        BHC_Progress::mark_step_complete($uid, $lesson_id, 0, 100, 1, $answers_payload);

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
        BHC_Progress::mark_step_complete($uid, $lesson_id, 0, 50, 0, wp_json_encode(['score' => 50, 'passed' => false, 'questions' => []]));
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
}
