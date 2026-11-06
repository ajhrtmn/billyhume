<?php
use PHPUnit\Framework\TestCase;

/**
 * BHC_Steps::save() is the security boundary for lesson content. These test the shape of
 * sanitization (dropping/clamping), not WordPress's exact escaping (see tests/bootstrap.php).
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
        // An attachment_id of 0 (or non-numeric) must not survive.
        $result = BHC_Steps::save(1, [['type' => 'image', 'attachment_ids' => [5, 0, '7', 'not-a-number'], 'caption' => '']]);
        $this->assertSame([5, 7], array_values($result[0]['attachment_ids']));
    }

    public function testVideoUrlSourceWithEmptyUrlIsDropped()
    {
        // A URL-source video with no URL is dropped.
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

    public function testVideoChaptersSurviveWithValidTimeAndTitle()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42, 'chapters' => [
            ['time' => 45, 'title' => 'EQ Bands Explained'],
            ['time' => 0, 'title' => 'Intro'],
        ]]]);
        $this->assertCount(2, $result[0]['chapters'], 'Both chapters have a real title and a real time — neither should be dropped.');
    }

    public function testVideoChaptersAreSortedByTimeRegardlessOfAuthoringOrder()
    {
        // Chapters must be stored in ascending time order.
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42, 'chapters' => [
            ['time' => 90, 'title' => 'Common Mistakes'],
            ['time' => 0, 'title' => 'Intro'],
            ['time' => 45, 'title' => 'EQ Bands Explained'],
        ]]]);
        $this->assertSame([0, 45, 90], array_column($result[0]['chapters'], 'time'));
    }

    public function testVideoChapterWithNoTitleIsDropped()
    {
        // Untitled chapters are dropped.
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42, 'chapters' => [
            ['time' => 10, 'title' => ''],
            ['time' => 20, 'title' => '   '],
        ]]]);
        $this->assertSame([], $result[0]['chapters']);
    }

    public function testVideoChapterWithNegativeTimeIsClampedToZero()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42, 'chapters' => [
            ['time' => -30, 'title' => 'Somehow negative'],
        ]]]);
        $this->assertSame(0, $result[0]['chapters'][0]['time']);
    }

    public function testVideoChapterTitleIsSanitizedAgainstMarkup()
    {
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42, 'chapters' => [
            ['time' => 0, 'title' => '<script>alert(1)</script>Intro'],
        ]]]);
        $this->assertStringNotContainsString('<script>', $result[0]['chapters'][0]['title']);
    }

    public function testVideoWithNoChaptersAttributeDefaultsToEmptyArray()
    {
        // No chapters still stores an empty array, not a missing key.
        $result = BHC_Steps::save(1, [['type' => 'video', 'attachment_id' => 42]]);
        $this->assertSame([], $result[0]['chapters']);
    }

    public function testCloudflareStreamVideoDoesNotStoreChapters()
    {
        // A Cloudflare Stream step never stores chapters.
        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'cloudflare_stream', 'stream_uid' => str_repeat('a', 32), 'chapters' => [
            ['time' => 0, 'title' => 'Intro'],
        ]]]);
        $this->assertArrayNotHasKey('chapters', $result[0]);
    }

    public function testUrlSourceVideoKeepsChapters()
    {
        // YouTube/Vimeo URLs are Plyr-controllable, so chapters are kept.
        $result = BHC_Steps::save(1, [['type' => 'video', 'source' => 'url', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'chapters' => [
            ['time' => 60, 'title' => 'Bridge'],
            ['time' => 0, 'title' => 'Cold open'],
        ]]]);
        $this->assertSame('url', $result[0]['source']);
        $this->assertSame([0, 60], array_column($result[0]['chapters'], 'time'));
    }

    public function testQuizQuestionWithNoValidChoicesIsDropped()
    {
        // A question with all-blank choices is dropped.
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

    // An out-of-range correct_index clamps into range rather than becoming unanswerable.
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

    // ---- each newer step type's "drop dead step" branch ----

    public function testEmptyCalloutIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'callout', 'content' => '   ', 'variant' => 'tip']]);
        $this->assertSame([], $result, 'A callout with no real content (after stripping tags) must not be stored as a dead step.');
    }

    public function testCalloutWithInvalidVariantFallsBackToTip()
    {
        $result = BHC_Steps::save(1, [['type' => 'callout', 'content' => 'Heads up', 'variant' => 'not_a_real_variant']]);
        $this->assertSame('tip', $result[0]['variant']);
    }

    public function testEmptyChecklistIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'checklist', 'title' => 'Steps', 'items' => ['', '   ']]]);
        $this->assertSame([], $result, 'A checklist with no non-blank items has nothing to check and must not be stored.');
    }

    public function testChecklistFiltersOutBlankItemsButKeepsRealOnes()
    {
        $result = BHC_Steps::save(1, [['type' => 'checklist', 'items' => ['Real item', '', '  ', 'Another']]]);
        $this->assertSame(['Real item', 'Another'], $result[0]['items']);
    }

    public function testEmptyChordChartIsDropped()
    {
        $result = BHC_Steps::save(1, [['type' => 'chord-chart', 'title' => 'Verse', 'content' => "   \n  "]]);
        $this->assertSame([], $result, 'A chord chart with no real content must not be stored as a dead step.');
    }

    public function testAudioCompareMissingEitherClipIsDropped()
    {
        $onlyA = BHC_Steps::save(1, [['type' => 'audio-compare', 'attachment_id_a' => 5, 'attachment_id_b' => 0]]);
        $onlyB = BHC_Steps::save(1, [['type' => 'audio-compare', 'attachment_id_a' => 0, 'attachment_id_b' => 7]]);
        $this->assertSame([], $onlyA, 'A comparison with only clip A must be dropped, not rendered as a lonely single player.');
        $this->assertSame([], $onlyB, 'A comparison with only clip B must be dropped, not rendered as a lonely single player.');
    }

    public function testAudioCompareWithBothClipsDefaultsLabelsWhenBlank()
    {
        $result = BHC_Steps::save(1, [['type' => 'audio-compare', 'attachment_id_a' => 5, 'attachment_id_b' => 7, 'label_a' => '', 'label_b' => '']]);
        $this->assertSame('A', $result[0]['label_a']);
        $this->assertSame('B', $result[0]['label_b']);
    }

    public function testMultiStepLessonPreservesAuthoredOrder()
    {
        // save() must preserve authored step order.
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
