<?php
use PHPUnit\Framework\TestCase;

/**
 * BHC_Steps::save() is the actual security boundary for lesson content
 * — it's what stands between a raw admin-form POST and what gets
 * stored (and later echoed back out to every student) as a lesson's
 * body. These tests focus on the SHAPE of the sanitization (does an
 * invalid/incomplete step get dropped rather than stored broken? does
 * an out-of-range index get clamped rather than crashing something
 * downstream?) rather than WordPress's exact escaping output, which
 * belongs in a real WP-integration suite — see tests/bootstrap.php's
 * docblock for why.
 */
final class StepsSanitizationTest extends TestCase
{
    public function testUnknownStepTypeIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'not_a_real_type', 'content' => 'hi']]);
        $this->assertSame([], $result, 'A step type outside VALID_TYPES must never survive into storage — this is the one thing standing between a crafted POST and an unrecognized step type breaking the front-end renderer, which has a fixed set of type branches.');
    }

    public function testStepWithNoTypeAtAllIsDropped()
    {
        $result = BHC_Steps::save(1, [['content' => 'no type key at all']]);
        $this->assertSame([], $result);
    }

    public function testTextStepSurvivesWithContent()
    {
        $result = BHC_Steps::save(1, [['type' => 'text', 'content' => '<p>Hello</p>']]);
        $this->assertCount(1, $result);
        $this->assertSame('text', $result[0]['type']);
        $this->assertStringContainsString('Hello', $result[0]['content']);
    }

    public function testTextStepStripsScriptTags()
    {
        $result = BHC_Steps::save(1, [['type' => 'text', 'content' => '<p>Safe</p><script>alert(1)</script>']]);
        $this->assertStringNotContainsString('<script>', $result[0]['content']);
    }

    public function testImageStepFiltersOutZeroAndNegativeAttachmentIds()
    {
        // array_filter() on ints drops FALSY values — 0 is falsy, so an
        // attachment_id of 0 (a crafted or malformed entry) must not
        // survive as a "real" attachment reference downstream, where
        // wp_get_attachment_image(0, ...) would just silently render
        // nothing useful.
        $result = BHC_Steps::save(1, [['type' => 'image', 'attachment_ids' => [5, 0, '7', 'not-a-number'], 'caption' => '']]);
        $this->assertSame([5, 7], array_values($result[0]['attachment_ids']));
    }

    public function testVideoUrlSourceWithEmptyUrlIsDropped()
    {
        // A URL-source video step with no actual URL renders nothing —
        // storing it at all would just be silent dead weight in the
        // lesson's step array that class-render.php has to defensively
        // handle for no reason.
        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'url', 'video_url' => '']]);
        $this->assertSame([], $result);
    }

    public function testVideoUrlSourceWithInvalidUrlIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'url', 'video_url' => 'not a url at all']]);
        $this->assertSame([], $result);
    }

    public function testVideoUploadSourceWithNoAttachmentIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'upload', 'attachment_id' => 0]]);
        $this->assertSame([], $result);
    }

    public function testVideoDefaultsToUploadSourceWhenSourceOmitted()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42]]);
        $this->assertSame('upload', $result[0]['source']);
    }

    public function testQuizQuestionWithNoValidChoicesIsDropped()
    {
        // All-blank choices (e.g. an admin added a question row then
        // never filled in any answer options) leaves nothing a student
        // could actually select — this question must not survive into
        // the stored quiz at all, rather than shipping a broken,
        // unanswerable question.
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [
            ['question' => 'Broken?', 'choices' => ['', '  '], 'correct_index' => 0],
        ]]]);
        $this->assertSame([], $result, 'A quiz step whose only question has zero valid choices must be dropped entirely (not stored as an empty/unanswerable quiz).');
    }

    public function testQuizWithMixOfValidAndInvalidQuestionsKeepsOnlyValid()
    {
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [
            ['question' => 'Good one', 'choices' => ['A', 'B'], 'correct_index' => 0],
            ['question' => 'Broken one', 'choices' => [], 'correct_index' => 0],
        ]]]);
        $this->assertCount(1, $result[0]['questions']);
        $this->assertSame('Good one', $result[0]['questions'][0]['question']);
    }

    // A crafted or stale correct_index pointing past the actual choice
    // list (e.g. a choice got removed in the editor but correct_index
    // wasn't updated) must clamp into range rather than leaving an
    // index that would make score_quiz()'s comparison always false —
    // silently making the question unanswerable-correctly is worse than
    // clamping to a sane value an author can then visibly fix.
    public function testQuizCorrectIndexOutOfRangeClampsToLastChoice()
    {
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [
            ['question' => 'Q', 'choices' => ['A', 'B', 'C'], 'correct_index' => 99],
        ]]]);
        $this->assertSame(2, $result[0]['questions'][0]['correct_index'], 'An out-of-range correct_index must clamp to the last valid choice index, not silently store an unreachable index.');
    }

    public function testQuizNegativeCorrectIndexClampsToZero()
    {
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'questions' => [
            ['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => -5],
        ]]]);
        $this->assertSame(0, $result[0]['questions'][0]['correct_index']);
    }

    public function testQuizPassingScoreClampsToZeroToOneHundredRange()
    {
        $questions = [['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => 0]];
        $tooHigh = BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => 500, 'questions' => $questions]]);
        $tooLow  = BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => -20, 'questions' => $questions]]);
        $this->assertSame(100, $tooHigh[0]['passing_score']);
        $this->assertSame(0, $tooLow[0]['passing_score']);
    }

    public function testQuizWithNoQuestionsAtAllIsDroppedEntirely()
    {
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'passing_score' => 70, 'questions' => []]]);
        $this->assertSame([], $result, 'A quiz step authored with zero questions must not be stored as a step at all — nothing for a student to answer.');
    }

    public function testMaxAttemptsNegativeClampsToZero()
    {
        $result = BHC_Steps::save(1, [['type' => 'quiz', 'max_attempts' => -3, 'questions' => [
            ['question' => 'Q', 'choices' => ['A', 'B'], 'correct_index' => 0],
        ]]]);
        $this->assertSame(0, $result[0]['max_attempts'], 'A negative max_attempts must clamp to 0 (unlimited), not silently mean "zero attempts allowed," which would lock every student out of a quiz step entirely.');
    }

    public function testMultiStepLessonPreservesAuthoredOrder()
    {
        // Order is the entire point of a "multistep" lesson — text,
        // then an image, then a quiz, in that specific sequence — so
        // save() must not reorder, dedupe by type, or otherwise
        // reshuffle steps relative to how they were authored.
        $result = BHC_Steps::save(1, [
            ['type' => 'text', 'content' => 'first'],
            ['type' => 'image', 'attachment_ids' => [1]],
            ['type' => 'text', 'content' => 'third'],
        ]);
        $this->assertSame(['text', 'image', 'text'], array_column($result, 'type'));
        $this->assertStringContainsString('first', $result[0]['content']);
        $this->assertStringContainsString('third', $result[2]['content']);
    }
}
