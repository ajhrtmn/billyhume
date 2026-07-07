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

        return $rows;
    }
}
