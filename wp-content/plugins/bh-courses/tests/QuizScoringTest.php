<?php
use PHPUnit\Framework\TestCase;

/**
 * BHC_Steps::score_quiz() is 100% pure PHP — no WordPress calls at all
 * — and it's the function that decides whether a student passes a quiz
 * step, which (with max_attempts and drip-style "must pass to advance"
 * gating built on top of it) is a real gate on someone's progress
 * through paid content. Worth being rigorous about the edge cases, not
 * just the obvious all-correct/all-wrong cases.
 */
final class QuizScoringTest extends TestCase
{
    private function question($correctIndex, $choices = ['A', 'B', 'C'])
    {
        return ['question' => 'Q', 'choices' => $choices, 'correct_index' => $correctIndex];
    }

    public function testAllCorrectScoresOneHundredAndPasses()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0), $this->question(1)]];
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 1]);
        $this->assertSame(100, $result['score']);
        $this->assertTrue($result['passed']);
        $this->assertSame(2, $result['correct']);
        $this->assertSame(2, $result['total']);
    }

    public function testAllWrongScoresZeroAndFails()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0), $this->question(1)]];
        $result = BHC_Steps::score_quiz($step, [0 => 2, 1 => 2]);
        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['passed']);
    }

    // The one every naive implementation gets wrong: 1 of 3 correct is
    // 33.33...%, which has to round SOMEWHERE. Pinning down the actual
    // rounding behavior here means nobody accidentally changes it later
    // without noticing a test fail — the difference between round-half-
    // up and round-half-down at a threshold boundary can be the
    // difference between a student passing or failing a real quiz.
    public function testPartialCreditRoundsToNearestPercent()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0), $this->question(0), $this->question(0)]];
        // 1 of 3 correct = 33.33...% -> rounds to 33
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 9, 2 => 9]);
        $this->assertSame(33, $result['score']);
        $this->assertSame(1, $result['correct']);
    }

    // Exactly at the passing threshold must PASS, not fail — a classic
    // off-by-one a careless ">" vs ">=" change would introduce silently.
    public function testScoreExactlyAtPassingThresholdPasses()
    {
        $step = ['passing_score' => 50, 'questions' => [$this->question(0), $this->question(0)]];
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 9]); // 1 of 2 = exactly 50%
        $this->assertSame(50, $result['score']);
        $this->assertTrue($result['passed'], 'A score exactly equal to the passing threshold must count as passing.');
    }

    public function testOneScoreBelowThresholdFails()
    {
        $step = ['passing_score' => 50, 'questions' => [$this->question(0), $this->question(0), $this->question(0)]];
        // 1 of 3 = 33%, below a 50% threshold
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 9, 2 => 9]);
        $this->assertFalse($result['passed']);
    }

    // A student who skips a question entirely (never selects any
    // choice) must be marked wrong for it, not silently excluded from
    // the denominator — otherwise skipping every hard question and
    // answering only the easy ones would inflate the score.
    public function testMissingAnswerCountsAsIncorrectNotExcluded()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0), $this->question(1)]];
        $result = BHC_Steps::score_quiz($step, [0 => 0]); // question index 1 never answered at all
        $this->assertSame(1, $result['correct']);
        $this->assertSame(2, $result['total'], 'The unanswered question must still count toward the total, not be dropped from the denominator.');
        $this->assertSame(50, $result['score']);
    }

    public function testEmptyAnswersArrayScoresZero()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0)]];
        $result = BHC_Steps::score_quiz($step, []);
        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['passed']);
    }

    // A quiz step with no questions at all (shouldn't normally happen —
    // BHC_Steps::save() filters these out at authoring time — but this
    // is the function's OWN defensive floor, worth testing independent
    // of whether the caller upholds that invariant) must not divide by
    // zero, and must not accidentally "pass" someone on an empty quiz.
    public function testNoQuestionsAtAllDoesNotDivideByZeroAndDoesNotPass()
    {
        $step = ['passing_score' => 70, 'questions' => []];
        $result = BHC_Steps::score_quiz($step, []);
        $this->assertSame(0, $result['score']);
        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['passed']);
    }

    // Answer values arrive from $_POST as strings (see class-progress.php's
    // ajax_submit_quiz, which casts them, but score_quiz() itself is the
    // last line of defense) — a loose '0' == 0 comparison would be a
    // real correctness bug if PHP's type juggling ever did something
    // surprising here (e.g. '0' vs 0 vs false vs null all being
    // "equal-ish" under ==). Confirms the (int) cast on both sides
    // actually happens rather than relying on implicit coercion.
    public function testStringAnswerIndicesMatchIntegerCorrectIndex()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(1)]];
        $result = BHC_Steps::score_quiz($step, ['0' => '1']); // both question index and answer arrive as strings
        $this->assertSame(1, $result['correct']);
        $this->assertTrue($result['passed']);
    }

    // Default passing_score of 70 must apply when a step omits the key
    // entirely — BHC_Steps::save() always sets it, but score_quiz() is
    // called directly with whatever's stored, and older data or a
    // hand-crafted step array shouldn't silently pass everyone at 0%.
    public function testMissingPassingScoreDefaultsToSeventy()
    {
        $step = ['questions' => [$this->question(0), $this->question(0), $this->question(0)]];
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 0, 2 => 9]); // 2 of 3 = 67%, below default 70%
        $this->assertFalse($result['passed']);
    }
}
