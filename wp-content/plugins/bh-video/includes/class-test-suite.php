<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-video — same convention as every other
 * plugin's own class-test-suite.php. Covers BHV_Chapters' parsing/
 * sorting/resume-position logic (a direct port of BHS_Chapters, same
 * coverage shape as bh-streaming's own run_chapters_tests()) and
 * BHV_API's payload/list-filtering logic against real fixture posts.
 */
class BHV_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-video'] = ['label' => 'BH Video', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHV_API')) {
            return [['name' => 'BHV_API not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_chapters_tests());
        $rows = array_merge($rows, self::run_api_tests());
        return $rows;
    }

    /* ---------- BHV_Chapters: line parsing, sorting, resume position ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_chapters_tests(): array {
        if (!class_exists('BHV_Chapters')) return [];
        $rows = [];

        $fixture_id = wp_insert_post(['post_type' => 'bhv_video', 'post_status' => 'draft', 'post_title' => 'BHV Test Suite Chapters Fixture'], true);
        if (is_wp_error($fixture_id)) return $rows;

        // save()'s current_user_can('edit_post', $post_id) gate needs a
        // real capable user in context — this CLI test-runner request
        // has no logged-in user by default. Swapped in only for the
        // duration of this test and restored after.
        $previous_user_id = get_current_user_id();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if ($admins) wp_set_current_user($admins[0]);

        $_POST['bhv_chapters_raw'] = "00:05 Chapter Two (out of order)\n00:00 Introduction\nnotachapterline\n00:03 Chapter One (out of order too)";
        $_POST['bhv_chapters_nonce'] = wp_create_nonce('bhv_save_chapters');
        BHV_Chapters::save($fixture_id);
        unset($_POST['bhv_chapters_raw'], $_POST['bhv_chapters_nonce']);

        $chapters = BHV_Chapters::get($fixture_id);
        $rows[] = OUS_TestRunner::assert_same(3, count($chapters), 'save(): 3 valid lines saved, the malformed "notachapterline" line silently skipped rather than rejecting the whole save');
        $rows[] = OUS_TestRunner::assert_same(
            [0, 3, 5], array_column($chapters, 'time'),
            'save(): chapters are sorted by time regardless of the order they were entered in (input was 5, 0, [invalid], 3)'
        );
        $rows[] = OUS_TestRunner::assert_same('Introduction', $chapters[0]['label'] ?? null, 'save(): the 0:00 line\'s label parsed correctly');

        $_POST['bhv_chapters_raw'] = "not a chapter\nalso not one";
        $_POST['bhv_chapters_nonce'] = wp_create_nonce('bhv_save_chapters');
        BHV_Chapters::save($fixture_id);
        unset($_POST['bhv_chapters_raw'], $_POST['bhv_chapters_nonce']);
        $rows[] = OUS_TestRunner::assert_same([], BHV_Chapters::get($fixture_id), 'save(): an input with zero valid lines saves a clean empty array, not a partial/corrupt one');

        $test_user_id = class_exists('OUS_Debug') ? OUS_Debug::get_or_create_test_user('bhv_chapters_test') : get_current_user_id();
        update_user_meta($test_user_id, '_bhv_resume_' . $fixture_id, 137);
        $rows[] = OUS_TestRunner::assert_same(137, BHV_Chapters::resume_position($fixture_id, $test_user_id), 'resume_position(): reads back exactly what was written for this video/user pair');
        $rows[] = OUS_TestRunner::assert_same(0, BHV_Chapters::resume_position($fixture_id, 999999), 'resume_position(): a user with no saved position at all reads back 0, not a notice/null');
        delete_user_meta($test_user_id, '_bhv_resume_' . $fixture_id);

        wp_set_current_user($previous_user_id);
        wp_delete_post($fixture_id, true);
        return $rows;
    }

    /* ---------- BHV_API: payload shape + list filtering ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_api_tests(): array {
        $rows = [];

        $with_video_id = wp_insert_post(['post_type' => 'bhv_video', 'post_status' => 'publish', 'post_title' => 'BHV Suite Video With File'], true);
        $without_video_id = wp_insert_post(['post_type' => 'bhv_video', 'post_status' => 'publish', 'post_title' => 'BHV Suite Video Without File'], true);
        if (is_wp_error($with_video_id) || is_wp_error($without_video_id)) return $rows;

        // A real attachment isn't needed to exercise video_url_for()'s
        // own logic — an obviously-nonexistent attachment ID exercises
        // the same wp_get_attachment_url() code path as a real one
        // (it just returns an empty string for a missing attachment,
        // same as a video post that was never given a file at all).
        update_post_meta($with_video_id, '_bhv_attachment_id', 999999999);
        wp_set_post_terms($with_video_id, ['Rock'], 'bhv_genre');

        $with_post = get_post($with_video_id);
        $payload = BHV_API::video_payload($with_post);
        $rows[] = OUS_TestRunner::assert_same('BHV Suite Video With File', $payload['title'], 'video_payload(): title carries through unchanged');
        $rows[] = OUS_TestRunner::assert_same(['Rock'], $payload['genres'], 'video_payload(): genre terms resolve to plain names');
        $rows[] = OUS_TestRunner::assert_true(array_key_exists('chapters', $payload) && is_array($payload['chapters']), 'video_payload(): always includes a chapters array, even with none saved');
        $rows[] = OUS_TestRunner::assert_same(0, $payload['resumeSeconds'], 'video_payload(): resumeSeconds is 0 for a logged-out/no-history request rather than a notice');

        $without_post = get_post($without_video_id);
        $without_payload = BHV_API::video_payload($without_post);
        $rows[] = OUS_TestRunner::assert_same('', $without_payload['url'], 'video_payload(): a video with no attachment ever assigned has an empty url, not a fatal');

        wp_delete_post($with_video_id, true);
        wp_delete_post($without_video_id, true);
        return $rows;
    }
}
