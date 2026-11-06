<?php
if (!defined('ABSPATH')) exit;

/**
 * Timestamped waveform annotations, part of the 'detailed' tier. Only the
 * current reviewer (per BHF_Queue) may add top-level markers; the reviewer
 * and the submitter may reply under an existing marker.
 */
class BHF_Annotations {
    public static function init(): void {
        add_action('wp_ajax_bhf_add_annotation', [self::class, 'handle_add']);
    }

    public static function tier_supports_annotations(int $request_id): bool {
        return get_post_meta($request_id, '_bhf_tier', true) === 'detailed';
    }

    /**
     * @return array<int, array<string, mixed>> Top-level markers, each with a nested 'replies' array, ordered by timestamp.
     */
    public static function for_request(int $request_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BHF_Tables::annotations() . " WHERE request_id = %d ORDER BY parent_id, timestamp_seconds, created_at",
            $request_id
        ), ARRAY_A);

        $top = [];
        $by_id = [];
        foreach ($rows as $row) {
            $row['replies'] = [];
            $by_id[(int) $row['id']] = $row;
        }
        foreach ($by_id as $id => $row) {
            if ((int) $row['parent_id'] === 0) {
                $top[$id] = $row;
            }
        }
        foreach ($by_id as $row) {
            $parent_id = (int) $row['parent_id'];
            if ($parent_id !== 0 && isset($top[$parent_id])) {
                $top[$parent_id]['replies'][] = $row;
            }
        }
        return array_values($top);
    }

    /**
     * @return int|\WP_Error New annotation row id, or an error explaining why it was refused.
     */
    public static function create(int $request_id, int $user_id, ?float $timestamp_seconds, int $parent_id, string $body) {
        global $wpdb;
        $body = trim(wp_strip_all_tags($body));
        if ($body === '') return new WP_Error('bhf_annotation_empty', 'Write something before adding a note.');
        if (!self::tier_supports_annotations($request_id)) return new WP_Error('bhf_annotation_wrong_tier', 'Waveform annotations are only available on the detailed tier.');

        $reviewer_id = (int) get_post_meta($request_id, '_bhf_reviewer_id', true);
        $submitter_id = (int) get_post_field('post_author', $request_id);

        if ($parent_id === 0) {
            // New top-level marker: reviewer of record, while holding the claim.
            if ($user_id !== $reviewer_id || $reviewer_id === 0) {
                return new WP_Error('bhf_annotation_not_reviewer', 'Only the reviewer currently working on this request can add a new marker.');
            }
            if ($timestamp_seconds === null || $timestamp_seconds < 0) {
                return new WP_Error('bhf_annotation_bad_timestamp', 'A new marker needs a valid timestamp.');
            }
        } else {
            // Reply: reviewer or submitter, only under a marker of this request.
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM " . BHF_Tables::annotations() . " WHERE id = %d AND request_id = %d AND parent_id = 0",
                $parent_id, $request_id
            ));
            if (!$parent) return new WP_Error('bhf_annotation_bad_parent', 'That marker no longer exists.');
            if ($user_id !== $reviewer_id && $user_id !== $submitter_id) {
                return new WP_Error('bhf_annotation_not_allowed', 'Only the reviewer and the person who submitted this track can reply here.');
            }
            $timestamp_seconds = null; // replies inherit the parent's timestamp
        }

        $ok = $wpdb->insert(BHF_Tables::annotations(), [
            'request_id' => $request_id,
            'parent_id' => $parent_id,
            'user_id' => $user_id,
            'timestamp_seconds' => $timestamp_seconds,
            'body' => $body,
        ]);
        if (!$ok) return new WP_Error('bhf_annotation_db_error', 'Could not save that note — try again.');

        // WHY: read insert_id immediately; OUS_Notifications::notify() below
        // does its own INSERT and overwrites $wpdb->insert_id.
        $new_id = (int) $wpdb->insert_id;

        if ($parent_id === 0 && class_exists('OUS_Notifications') && $submitter_id) {
            // Notify only on the first marker of a review pass, not each one.
            $marker_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . BHF_Tables::annotations() . " WHERE request_id = %d AND parent_id = 0", $request_id
            ));
            if ($marker_count === 1) {
                OUS_Notifications::notify(
                    $submitter_id, 'feedback_annotation_started', 'A reviewer left a note on your track',
                    'Timestamped notes are being added to "' . get_the_title($request_id) . '" — check back once it\'s complete.',
                    '', 'BH Feedback'
                );
            }
        }

        return $new_id;
    }

    public static function handle_add(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in.'], 401);
        check_ajax_referer('bhf_annotations', 'nonce');

        $request_id = (int) ($_POST['request_id'] ?? 0);
        $parent_id = (int) ($_POST['parent_id'] ?? 0);
        $timestamp = isset($_POST['timestamp_seconds']) && $_POST['timestamp_seconds'] !== '' ? (float) $_POST['timestamp_seconds'] : null;
        $body = isset($_POST['body']) ? wp_unslash((string) $_POST['body']) : '';

        if (!$request_id || get_post_type($request_id) !== 'bh_feedback_request') {
            wp_send_json_error(['message' => 'Unknown request.'], 404);
        }

        $result = self::create($request_id, get_current_user_id(), $timestamp, $parent_id, $body);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 403);
        }
        wp_send_json_success(['id' => $result]);
    }
}
