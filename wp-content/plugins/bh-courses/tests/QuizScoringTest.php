<?php
use PHPUnit\Framework\TestCase;

/**
 * BHC_Steps::score_quiz() is pure PHP and gates student progress, so its edge cases matter.
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

    // Pins the rounding behavior; at a threshold it decides pass vs. fail.
    public function testPartialCreditRoundsToNearestPercent()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(0), $this->question(0), $this->question(0)]];
        // 1 of 3 correct = 33.33...% -> rounds to 33
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 9, 2 => 9]);
        $this->assertSame(33, $result['score']);
        $this->assertSame(1, $result['correct']);
    }

    // Exactly at the threshold must pass (">=" not ">").
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

    // A skipped question counts as wrong, not excluded from the denominator.
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

    // No questions must neither divide by zero nor pass.
    public function testNoQuestionsAtAllDoesNotDivideByZeroAndDoesNotPass()
    {
        $step = ['passing_score' => 70, 'questions' => []];
        $result = BHC_Steps::score_quiz($step, []);
        $this->assertSame(0, $result['score']);
        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['passed']);
    }

    // $_POST answers arrive as strings; confirms the (int) casts on both sides.
    public function testStringAnswerIndicesMatchIntegerCorrectIndex()
    {
        $step = ['passing_score' => 70, 'questions' => [$this->question(1)]];
        $result = BHC_Steps::score_quiz($step, ['0' => '1']); // both question index and answer arrive as strings
        $this->assertSame(1, $result['correct']);
        $this->assertTrue($result['passed']);
    }

    // passing_score defaults to 70 when the key is missing.
    public function testMissingPassingScoreDefaultsToSeventy()
    {
        $step = ['questions' => [$this->question(0), $this->question(0), $this->question(0)]];
        $result = BHC_Steps::score_quiz($step, [0 => 0, 1 => 0, 2 => 9]); // 2 of 3 = 67%, below default 70%
        $this->assertFalse($result['passed']);
    }
}
