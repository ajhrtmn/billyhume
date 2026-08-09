<?php
if (!defined('ABSPATH')) exit;

/**
 * Wires WordPress core's native Tools > Export/Erase Personal Data
 * screens for wp_bhl_chat_messages — ephemeral live-stream chat tied
 * to past streams, safe to hard-delete on request (nothing else in
 * this ecosystem reads another person's chat history).
 */
class BHL_Privacy {
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function register_exporter(array $exporters): array {
        $exporters['bh-live-chat'] = [
            'exporter_friendly_name' => __('Own Ur Shit — Live Chat', 'bh-live'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function register_eraser(array $erasers): array {
        $erasers['bh-live-chat'] = [
            'eraser_friendly_name' => __('Own Ur Shit — Live Chat', 'bh-live'),
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

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, stream_id, message, created_at FROM " . $wpdb->prefix . "bhl_chat_messages WHERE user_id = %d",
            $user->ID
        ), ARRAY_A);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'bh-live-chat',
                'group_label' => __('Live Chat Messages', 'bh-live'),
                'item_id' => 'chat-message-' . $row['id'],
                'data' => [
                    ['name' => 'stream_id', 'value' => (string) $row['stream_id']],
                    ['name' => 'message', 'value' => $row['message']],
                    ['name' => 'created_at', 'value' => $row['created_at']],
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

        $removed = $wpdb->delete($wpdb->prefix . 'bhl_chat_messages', ['user_id' => $user->ID]);

        return [
            'items_removed' => (bool) $removed,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}
