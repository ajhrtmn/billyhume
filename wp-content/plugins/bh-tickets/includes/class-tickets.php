<?php
if (!defined('ABSPATH')) exit;

/**
 * BHT_Tickets — core CRUD, and this plugin's OUS_Integration built-in
 * default (see bh-tickets.php's registration). Every ticket belongs to
 * a real wp_user (bh-crm's own identity model — a person IS a
 * wp_users row, per CLAUDE.md); no separate contact/identity system,
 * same reasoning that ruled out FluentCRM/Groundhogg for the marketing
 * layer applies here too. A ticket links to its requester via
 * BHCRM_Links (already-existing generic typed relationship table) when
 * bh-crm is active — entirely optional, this plugin works standalone.
 */
class BHT_Tickets {
    const STATUSES = ['open' => 'Open', 'pending' => 'Pending', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhtickets_tickets';
    }

    public static function create($user_id, $subject, $body, $category = '', $priority = 'normal') {
        global $wpdb;
        $user_id = (int) $user_id;
        $subject = sanitize_text_field($subject);
        if (!$user_id || $subject === '') return new WP_Error('invalid', 'A ticket needs a real account and a subject.');

        $priority = isset(self::PRIORITIES[$priority]) ? $priority : 'normal';

        $wpdb->insert(self::table(), [
            'user_id' => $user_id,
            'subject' => $subject,
            'category' => sanitize_text_field($category),
            'priority' => $priority,
            'status' => 'open',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $ticket_id = (int) $wpdb->insert_id;
        if (!$ticket_id) return new WP_Error('db_error', 'Could not create the ticket.');

        // The opening message is itself the first reply — one thread,
        // no separate "ticket body" field to keep in sync with replies.
        if (class_exists('BHT_Replies')) {
            BHT_Replies::add($ticket_id, $user_id, $body, false);
        }

        if (class_exists('BHCRM_Links')) {
            BHCRM_Links::link('ticket', $ticket_id, 'person', $user_id, 'requester');
        }

        if (class_exists('BH_Event')) {
            BH_Event::emit('bht/ticket_created', [
                'user_id' => $user_id, 'subject_type' => 'bht_ticket', 'subject_id' => $ticket_id,
                'payload' => ['subject' => $subject, 'category' => $category, 'priority' => $priority],
            ]);
        }

        return $ticket_id;
    }

    public static function get($ticket_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $ticket_id), ARRAY_A);
    }

    /** Every ticket a given user opened, newest first. */
    public static function for_user($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY updated_at DESC', (int) $user_id
        ), ARRAY_A);
    }

    /** The staff-facing list — optionally filtered by status. */
    public static function all($status = '') {
        global $wpdb;
        if ($status && isset(self::STATUSES[$status])) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY updated_at DESC', $status
            ), ARRAY_A);
        }
        return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC', ARRAY_A);
    }

    public static function set_status($ticket_id, $status) {
        global $wpdb;
        if (!isset(self::STATUSES[$status])) return false;
        $ticket = self::get($ticket_id);
        if (!$ticket) return false;

        $wpdb->update(self::table(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => (int) $ticket_id]);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bht/status_changed', [
                'user_id' => (int) $ticket['user_id'], 'subject_type' => 'bht_ticket', 'subject_id' => (int) $ticket_id,
                'payload' => ['status' => $status],
            ]);
        }
        return true;
    }

    public static function assign($ticket_id, $staff_user_id) {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'assigned_to' => (int) $staff_user_id, 'updated_at' => current_time('mysql'),
        ], ['id' => (int) $ticket_id]) !== false;
    }

    public static function touch($ticket_id) {
        global $wpdb;
        $wpdb->update(self::table(), ['updated_at' => current_time('mysql')], ['id' => (int) $ticket_id]);
    }

    /**
     * Optional enrichment of bh-crm's person view — same
     * bh_crm_activity_summary contract every other peer plugin uses,
     * harmless to register even if bh-crm is never active.
     */
    public static function register_crm_activity($sections, $user_id) {
        $tickets = self::for_user($user_id);
        if (!$tickets) return $sections;
        $open = count(array_filter($tickets, fn($t) => in_array($t['status'], ['open', 'pending'], true)));
        $sections[] = [
            'plugin' => 'BH Tickets',
            'summary' => count($tickets) . ' ticket' . (count($tickets) === 1 ? '' : 's') . ($open ? ", $open open" : ''),
            'render' => function () use ($tickets) {
                echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
                foreach ($tickets as $t) {
                    $url = admin_url('admin.php?page=bh-tickets&ticket_id=' . (int) $t['id']);
                    echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html($t['subject']) . '</a></td>'
                       . '<td>' . esc_html(self::STATUSES[$t['status']] ?? $t['status']) . '</td>'
                       . '<td>' . esc_html($t['updated_at']) . '</td></tr>';
                }
                echo '</tbody></table>';
            },
        ];
        return $sections;
    }
}
