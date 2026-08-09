<?php
if (!defined('ABSPATH')) exit;

/** Dev-only seed/reset, registered into the shared Debug Tools page — same convention every peer plugin's own class-debug.php follows. */
class BHT_Debug {
    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register(array $tools): array {
        $tools['bh-tickets'] = [
            'label' => 'BH Tickets',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset' => [self::class, 'reset'],
            'group' => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }

    public static function render_section(): void {
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'bhtickets_tickets WHERE user_id IN (SELECT user_id FROM ' . $wpdb->usermeta . " WHERE meta_key = 'bhcore_is_test')");
        echo '<p>' . $count . ' test ticket(s) on file.</p>';
        OUS_Debug::button('bh-tickets', 'seed', 'Seed a test ticket');
    }

    /** @param array<string, mixed> $post */
    public static function handle_action(string $action, array $post): string {
        if ($action !== 'seed') return 'Unknown action.';
        $user_id = OUS_Debug::get_or_create_test_user('bh_tickets_requester');
        $result = BHT_Tickets::create($user_id, 'Test ticket: cannot log in', 'This is a seeded test ticket for QA.', 'account', 'normal');
        return is_wp_error($result) ? 'Seed failed: ' . $result->get_error_message() : 'Seeded ticket #' . $result . '.';
    }

    public static function reset(): string {
        global $wpdb;
        $test_ids = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'bhcore_is_test'");
        if (!$test_ids) return 'Nothing to reset.';
        $placeholders = implode(',', array_fill(0, count($test_ids), '%d'));
        $ticket_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bhtickets_tickets WHERE user_id IN ($placeholders)", ...$test_ids));
        if ($ticket_ids) {
            $ph = implode(',', array_fill(0, count($ticket_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bhtickets_replies WHERE ticket_id IN ($ph)", ...$ticket_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bhtickets_tickets WHERE id IN ($ph)", ...$ticket_ids));
        }
        return 'Removed ' . count($ticket_ids) . ' test ticket(s).';
    }
}
