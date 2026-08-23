<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner version of this plugin's own real-branching logic —
 * the claim/release/complete state machine and its concurrency guards
 * — same convention every other plugin's own class-test-suite.php uses.
 * Runs against real, tagged fixture users/posts, cleaned up afterward.
 */
class BHF_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-feedback'] = ['label' => 'BH Feedback', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_Debug')) return [['name' => 'OUS_Debug not loaded', 'pass' => false, 'message' => 'Skipped.']];
        global $wpdb;
        $rows = [];

        $submitter = OUS_Debug::get_or_create_test_user('bhf_suite_submitter', false);
        $reviewer_a = OUS_Debug::get_or_create_test_user('bhf_suite_reviewer_a', false);
        $reviewer_b = OUS_Debug::get_or_create_test_user('bhf_suite_reviewer_b', false);

        $request_id = wp_insert_post([
            'post_type' => 'bh_feedback_request', 'post_status' => 'publish',
            'post_title' => 'Suite Test Track', 'post_author' => $submitter,
            'meta_input' => ['bhcore_is_test' => 'bhf_suite'],
        ], true);
        update_post_meta($request_id, '_bhf_tier', 'quick_take');
        update_post_meta($request_id, '_bhf_status', BHF_PostTypes::STATUS_OPEN);
        update_post_meta($request_id, '_bhf_reviewer_id', 0);

        /* ---------- pricing — pure ---------- */
        $rows[] = OUS_TestRunner::assert_true(BHF_Pricing::is_valid_tier('quick_take'), 'quick_take is a valid tier');
        $rows[] = OUS_TestRunner::assert_false(BHF_Pricing::is_valid_tier('nonexistent_tier'), 'An unknown tier key is not valid');
        $rows[] = OUS_TestRunner::assert_true(BHF_Pricing::cents_for('detailed') > BHF_Pricing::cents_for('quick_take'), 'The detailed tier costs more than quick_take');

        /* ---------- claim() concurrency guard ---------- */
        $claim_a = BHF_Queue::claim($request_id, $reviewer_a);
        $rows[] = OUS_TestRunner::assert_true($claim_a, 'The first reviewer to claim an open request succeeds');

        $claim_b = BHF_Queue::claim($request_id, $reviewer_b);
        $rows[] = OUS_TestRunner::assert_false($claim_b, 'A second reviewer claiming the SAME already-claimed request is rejected, not allowed to double-claim');

        $status_after_claim = get_post_meta($request_id, '_bhf_status', true);
        $rows[] = OUS_TestRunner::assert_same(BHF_PostTypes::STATUS_CLAIMED, $status_after_claim, 'Status is "claimed" after a successful claim');
        $reviewer_after_claim = (int) get_post_meta($request_id, '_bhf_reviewer_id', true);
        $rows[] = OUS_TestRunner::assert_same($reviewer_a, $reviewer_after_claim, 'The claiming reviewer is recorded, not the one who was rejected');

        /* ---------- release() ownership guard ---------- */
        $release_by_wrong_reviewer = BHF_Queue::release($request_id, $reviewer_b);
        $rows[] = OUS_TestRunner::assert_false($release_by_wrong_reviewer, 'A reviewer who does NOT hold the claim cannot release it');
        $status_still_claimed = get_post_meta($request_id, '_bhf_status', true);
        $rows[] = OUS_TestRunner::assert_same(BHF_PostTypes::STATUS_CLAIMED, $status_still_claimed, 'A rejected release attempt leaves the request exactly as it was');

        $release_by_owner = BHF_Queue::release($request_id, $reviewer_a);
        $rows[] = OUS_TestRunner::assert_true($release_by_owner, 'The reviewer who actually holds the claim CAN release it');
        $status_after_release = get_post_meta($request_id, '_bhf_status', true);
        $rows[] = OUS_TestRunner::assert_same(BHF_PostTypes::STATUS_OPEN, $status_after_release, 'A released request goes back to "open", available to be claimed again');

        /* ---------- re-claim + complete() ---------- */
        BHF_Queue::claim($request_id, $reviewer_b);
        $complete_by_wrong_reviewer = BHF_Queue::complete($request_id, $reviewer_a, 'Should not be allowed.');
        $rows[] = OUS_TestRunner::assert_false($complete_by_wrong_reviewer, 'A reviewer who does not hold the current claim cannot complete the review');

        $complete_ok = BHF_Queue::complete($request_id, $reviewer_b, 'Solid hook, but the second verse drags a bit.');
        $rows[] = OUS_TestRunner::assert_true($complete_ok, 'The reviewer holding the claim can complete the review');
        $status_after_complete = get_post_meta($request_id, '_bhf_status', true);
        $rows[] = OUS_TestRunner::assert_same(BHF_PostTypes::STATUS_COMPLETED, $status_after_complete, 'Status becomes "completed" after a successful review submission');

        $review_row = BHF_Queue::review_for($request_id);
        $rows[] = OUS_TestRunner::assert_true((bool) $review_row, 'A bh_feedback_reviews row exists for the completed request');
        $rows[] = OUS_TestRunner::assert_same('Solid hook, but the second verse drags a bit.', $review_row['body'] ?? null, 'The review body is stored exactly as submitted');

        $recomplete = BHF_Queue::complete($request_id, $reviewer_b, 'Trying again.');
        $rows[] = OUS_TestRunner::assert_false($recomplete, 'A request already completed cannot be completed a second time');

        // Cleanup
        $wpdb->delete(BHF_Tables::reviews(), ['request_id' => $request_id]);
        wp_delete_post($request_id, true);

        return $rows;
    }
}
