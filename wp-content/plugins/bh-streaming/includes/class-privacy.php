<?php
if (!defined('ABSPATH')) exit;

/**
 * Wires WordPress core's native Tools > Export/Erase Personal Data
 * screens for wp_bhs_likes, wp_bhs_jam_sessions, and
 * wp_bhs_jam_participants — all safe to hard-delete on request
 * (a like is only meaningful to the person who made it; Jam sessions/
 * participation are ephemeral, tied to a past listening session, not
 * referenced by any other feature). wp_bhs_daily_stats is deliberately
 * excluded — it's aggregate-only, no user_id column at all.
 *
 * A hosted Jam session (host_user_id) is deleted outright rather than
 * reassigned — an orphaned session with no host isn't meaningfully
 * useful to keep around, and it's already ephemeral by design.
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
            'exporter_friendly_name' => __('Own Ur Shit — Streaming Activity', 'bh-streaming'),
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
            'eraser_friendly_name' => __('Own Ur Shit — Streaming Activity', 'bh-streaming'),
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

        $removed_likes = $wpdb->delete($wpdb->prefix . 'bhs_likes', ['user_id' => $user->ID]);
        $removed_participation = $wpdb->delete($wpdb->prefix . 'bhs_jam_participants', ['user_id' => $user->ID]);
        $removed_sessions = $wpdb->delete($wpdb->prefix . 'bhs_jam_sessions', ['host_user_id' => $user->ID]);

        return [
            'items_removed' => (bool) ($removed_likes || $removed_participation || $removed_sessions),
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}
