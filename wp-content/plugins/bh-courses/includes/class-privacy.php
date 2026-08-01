<?php
if (!defined('ABSPATH')) exit;

/**
 * Wires WordPress core's native Tools > Export/Erase Personal Data
 * screens for the two tables this plugin owns that are keyed directly
 * by user_id: wp_bhc_progress (lesson/quiz progress, free-text quiz
 * answers) and wp_bhc_achievements (earned badges). Both are safe to
 * hard-delete on request — no other feature in this ecosystem reads
 * another person's progress/achievement rows, so erasing one user's
 * own doesn't break anything else (unlike, say, a fraud-signal table).
 *
 * Deliberately excludes wp_bhc_reviews — that's public-facing content
 * (a star rating + free-text review other visitors see), which needs
 * its own anonymize-vs-delete policy decision, not a blind delete.
 */
class BHC_Privacy {
    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function register_exporter($exporters) {
        $exporters['bh-courses-progress'] = [
            'exporter_friendly_name' => __('Own Ur Shit — Course Progress', 'bh-courses'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['bh-courses-progress'] = [
            'eraser_friendly_name' => __('Own Ur Shit — Course Progress', 'bh-courses'),
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    public static function export($email, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $data = [];

        $progress_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lesson_id, step_index, completed_at, score, passed, attempts, watched_percent FROM " . $wpdb->prefix . "bhc_progress WHERE user_id = %d",
            $user->ID
        ), ARRAY_A);
        foreach ($progress_rows as $row) {
            $props = [];
            foreach ($row as $key => $value) {
                if ($value === null || $value === '') continue;
                $props[] = ['name' => $key, 'value' => (string) $value];
            }
            $data[] = [
                'group_id' => 'bh-courses-progress',
                'group_label' => __('Course Progress', 'bh-courses'),
                'item_id' => 'progress-' . $row['lesson_id'] . '-' . $row['step_index'],
                'data' => $props,
            ];
        }

        $achievements = BHC_Achievements::all_for_user($user->ID);
        foreach ($achievements as $row) {
            $data[] = [
                'group_id' => 'bh-courses-achievements',
                'group_label' => __('Course Achievements', 'bh-courses'),
                'item_id' => 'achievement-' . $row['achievement_key'] . '-' . $row['course_id'],
                'data' => [
                    ['name' => 'achievement_key', 'value' => $row['achievement_key']],
                    ['name' => 'course_id', 'value' => (string) $row['course_id']],
                    ['name' => 'earned_at', 'value' => $row['earned_at']],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    public static function erase($email, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $removed_progress = $wpdb->delete($wpdb->prefix . 'bhc_progress', ['user_id' => $user->ID]);
        $removed_achievements = $wpdb->delete($wpdb->prefix . 'bhc_achievements', ['user_id' => $user->ID]);

        return [
            'items_removed' => (bool) ($removed_progress || $removed_achievements),
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}
