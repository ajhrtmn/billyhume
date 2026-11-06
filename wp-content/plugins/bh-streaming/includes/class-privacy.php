<?php
if (!defined('ABSPATH')) exit;

/**
 * Core personal-data export/erase for likes, Jam sessions, and Jam
 * participants (all safe to hard-delete). wp_bhs_daily_stats is excluded:
 * aggregate-only, no user_id. Hosted Jam sessions are deleted, not reassigned.
 */
class BHS_Privacy {
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function register_exporter(array $exporters): array {
        $exporters['bh-streaming-activity'] = [
            'exporter_friendly_name' => __('The Self-Hosted Self — Streaming Activity', 'bh-streaming'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function register_eraser(array $erasers): array {
        $erasers['bh-streaming-activity'] = [
            'eraser_friendly_name' => __('The Self-Hosted Self — Streaming Activity', 'bh-streaming'),
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /** @return array{data: array<int, array<string, mixed>>, done: bool} */
    public static function export(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $data = [];

        $likes = $wpdb->get_results($wpdb->prepare(
            "SELECT track_id, created_at FROM " . $wpdb->prefix . "bhs_likes WHERE user_id = %d",
            $user->ID
        ), ARRAY_A);
        foreach ($likes as $row) {
            $data[] = [
                'group_id' => 'bh-streaming-likes',
                'group_label' => __('Liked Tracks', 'bh-streaming'),
                'item_id' => 'like-' . $row['track_id'],
                'data' => [
                    ['name' => 'track_id', 'value' => (string) $row['track_id']],
                    ['name' => 'created_at', 'value' => $row['created_at']],
                ],
            ];
        }

        $hosted = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invite_code, status, created_at FROM " . $wpdb->prefix . "bhs_jam_sessions WHERE host_user_id = %d",
            $user->ID
        ), ARRAY_A);
        foreach ($hosted as $row) {
            $data[] = [
                'group_id' => 'bh-streaming-jam-hosted',
                'group_label' => __('Jam Sessions Hosted', 'bh-streaming'),
                'item_id' => 'jam-session-' . $row['id'],
                'data' => [
                    ['name' => 'invite_code', 'value' => $row['invite_code']],
                    ['name' => 'status', 'value' => $row['status']],
                    ['name' => 'created_at', 'value' => $row['created_at']],
                ],
            ];
        }

        $participation = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, display_name, joined_at FROM " . $wpdb->prefix . "bhs_jam_participants WHERE user_id = %d",
            $user->ID
        ), ARRAY_A);
        foreach ($participation as $row) {
            $data[] = [
                'group_id' => 'bh-streaming-jam-participation',
                'group_label' => __('Jam Sessions Joined', 'bh-streaming'),
                'item_id' => 'jam-participant-' . $row['session_id'],
                'data' => [
                    ['name' => 'session_id', 'value' => (string) $row['session_id']],
                    ['name' => 'display_name', 'value' => $row['display_name']],
                    ['name' => 'joined_at', 'value' => $row['joined_at']],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: array<int, mixed>, done: bool} */
    public static function erase(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $removed_likes = $wpdb->delete(BHS_Tables::likes(), ['user_id' => $user->ID]);
        $removed_participation = $wpdb->delete(BHS_Tables::jam_participants(), ['user_id' => $user->ID]);
        $removed_sessions = $wpdb->delete(BHS_Tables::jam_sessions(), ['host_user_id' => $user->ID]);

        return [
            'items_removed' => (bool) ($removed_likes || $removed_participation || $removed_sessions),
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}
