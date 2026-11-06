<?php
if (!defined('ABSPATH')) exit;

/**
 * Reviewer side: a self-serve claim queue gated by the single
 * `bhcore_review_submissions` capability. claim()/release()/complete() use
 * atomic conditional UPDATEs so two reviewers can't both claim a request.
 */
class BHF_Queue {
    public static function init(): void {
        add_action('admin_post_bhf_claim_request', [self::class, 'handle_claim']);
        add_action('admin_post_bhf_release_request', [self::class, 'handle_release']);
        add_action('admin_post_bhf_complete_review', [self::class, 'handle_complete']);
    }

    /** @return \WP_Post[] */
    public static function open_requests(): array {
        return get_posts([
            'post_type' => 'bh_feedback_request', 'post_status' => 'publish', 'posts_per_page' => 50,
            'orderby' => 'date', 'order' => 'ASC',
            'meta_key' => '_bhf_status', 'meta_value' => BHF_PostTypes::STATUS_OPEN,
        ]);
    }

    /** @return \WP_Post[] */
    public static function claimed_by(int $reviewer_id): array {
        return get_posts([
            'post_type' => 'bh_feedback_request', 'post_status' => 'publish', 'posts_per_page' => 50,
            'meta_query' => [
                ['key' => '_bhf_status', 'value' => BHF_PostTypes::STATUS_CLAIMED],
                ['key' => '_bhf_reviewer_id', 'value' => $reviewer_id],
            ],
        ]);
    }

    // WHY: a raw $wpdb UPDATE on postmeta bypasses the meta cache, so
    // clean_post_cache() follows every status UPDATE below.
    public static function claim(int $request_id, int $reviewer_id): bool {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_bhf_status' AND meta_value = %s",
            BHF_PostTypes::STATUS_CLAIMED, $request_id, BHF_PostTypes::STATUS_OPEN
        ));
        if ($wpdb->rows_affected !== 1) return false; // already claimed by someone else, or doesn't exist
        clean_post_cache($request_id);
        update_post_meta($request_id, '_bhf_reviewer_id', $reviewer_id);
        update_post_meta($request_id, '_bhf_claimed_at', current_time('mysql'));
        return true;
    }

    // Only the claim holder can release, checked in the same atomic statement.
    public static function release(int $request_id, int $reviewer_id): bool {
        global $wpdb;
        $current_reviewer = (int) get_post_meta($request_id, '_bhf_reviewer_id', true);
        if ($current_reviewer !== (int) $reviewer_id) return false;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_bhf_status' AND meta_value = %s",
            BHF_PostTypes::STATUS_OPEN, $request_id, BHF_PostTypes::STATUS_CLAIMED
        ));
        if ($wpdb->rows_affected !== 1) return false;
        clean_post_cache($request_id);
        update_post_meta($request_id, '_bhf_reviewer_id', 0);
        return true;
    }

    public static function complete(int $request_id, int $reviewer_id, string $body): bool {
        global $wpdb;
        $current_reviewer = (int) get_post_meta($request_id, '_bhf_reviewer_id', true);
        if ($current_reviewer !== (int) $reviewer_id) return false;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_bhf_status' AND meta_value = %s",
            BHF_PostTypes::STATUS_COMPLETED, $request_id, BHF_PostTypes::STATUS_CLAIMED
        ));
        if ($wpdb->rows_affected !== 1) return false;
        clean_post_cache($request_id);

        $tier = get_post_meta($request_id, '_bhf_tier', true) ?: 'quick_take';
        // UNIQUE KEY on request_id: one review per request. If the insert fails
        // the status transition still stands, rather than leaving it "claimed".
        $wpdb->insert(BHF_Tables::reviews(), [
            'request_id' => $request_id, 'reviewer_user_id' => $reviewer_id, 'tier' => $tier, 'body' => wp_kses_post($body),
        ]);

        if (class_exists('OUS_Notifications')) {
            $submitter_id = (int) get_post_field('post_author', $request_id);
            OUS_Notifications::notify(
                $submitter_id, 'feedback_completed', 'Your feedback is ready',
                'A reviewer left feedback on "' . get_the_title($request_id) . '".',
                '', 'BH Feedback'
            );
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    public static function review_for(int $request_id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . BHF_Tables::reviews() . " WHERE request_id = %d", $request_id
        ), ARRAY_A);
    }

    public static function handle_claim(): void {
        if (!current_user_can('bhcore_review_submissions')) wp_die('Not authorized.');
        if (!isset($_POST['bhf_queue_nonce']) || !wp_verify_nonce($_POST['bhf_queue_nonce'], 'bhf_queue_action')) wp_die('Security check failed.');
        self::claim((int) $_POST['request_id'], get_current_user_id());
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    public static function handle_release(): void {
        if (!current_user_can('bhcore_review_submissions')) wp_die('Not authorized.');
        if (!isset($_POST['bhf_queue_nonce']) || !wp_verify_nonce($_POST['bhf_queue_nonce'], 'bhf_queue_action')) wp_die('Security check failed.');
        self::release((int) $_POST['request_id'], get_current_user_id());
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    public static function handle_complete(): void {
        if (!current_user_can('bhcore_review_submissions')) wp_die('Not authorized.');
        if (!isset($_POST['bhf_queue_nonce']) || !wp_verify_nonce($_POST['bhf_queue_nonce'], 'bhf_queue_action')) wp_die('Security check failed.');
        $body = isset($_POST['review_body']) ? wp_unslash($_POST['review_body']) : '';
        $referer = wp_get_referer() ?: home_url('/');
        if (trim($body) === '') {
            wp_safe_redirect(add_query_arg('bhf_error', rawurlencode('Write something before submitting.'), $referer));
            exit;
        }
        self::complete((int) $_POST['request_id'], get_current_user_id(), $body);
        wp_safe_redirect($referer);
        exit;
    }
}
