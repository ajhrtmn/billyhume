<?php
if (!defined('ABSPATH')) exit;

/** BHT_Replies — a flat, append-only thread per ticket. The opening message (BHT_Tickets::create()) is reply #1. */
class BHT_Replies {
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhtickets_replies';
    }

    public static function add($ticket_id, $user_id, $body, $is_staff = false) {
        global $wpdb;
        $body = wp_kses_post($body);
        if (trim(wp_strip_all_tags($body)) === '') return new WP_Error('empty', 'A reply needs a body.');

        $wpdb->insert(self::table(), [
            'ticket_id' => (int) $ticket_id,
            'user_id' => (int) $user_id,
            'is_staff' => $is_staff ? 1 : 0,
            'body' => $body,
            'created_at' => current_time('mysql'),
        ]);
        $reply_id = (int) $wpdb->insert_id;
        if (!$reply_id) return new WP_Error('db_error', 'Could not save the reply.');

        if (class_exists('BHT_Tickets')) BHT_Tickets::touch($ticket_id);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bht/reply_added', [
                'user_id' => (int) $user_id, 'subject_type' => 'bht_ticket', 'subject_id' => (int) $ticket_id,
                'payload' => ['is_staff' => (bool) $is_staff],
            ]);
        }

        self::maybe_notify($ticket_id, $user_id, $is_staff);

        return $reply_id;
    }

    public static function for_ticket($ticket_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d ORDER BY created_at ASC, id ASC', (int) $ticket_id
        ), ARRAY_A);
    }

    /**
     * Staff reply -> notify the requester. Requester reply on an
     * assigned ticket -> notify the assignee. Nothing to notify for a
     * brand-new ticket with no assignee yet — there's no "all staff"
     * notification target in this first pass (no dedicated
     * ticket-manager role/list beyond the bhcore_manage_tickets
     * capability, which could match several accounts or none).
     */
    private static function maybe_notify($ticket_id, $actor_id, $is_staff) {
        if (!class_exists('OUS_Notifications') || !class_exists('BHT_Tickets')) return;
        $ticket = BHT_Tickets::get($ticket_id);
        if (!$ticket) return;

        $url = home_url('/account/?panel=tickets&ticket_id=' . (int) $ticket_id);

        if ($is_staff && (int) $ticket['user_id'] !== (int) $actor_id) {
            OUS_Notifications::notify(
                (int) $ticket['user_id'], 'ticket_reply',
                'New reply on your ticket: ' . $ticket['subject'],
                'Someone replied — take a look.',
                $url, 'BH Tickets'
            );
        } elseif (!$is_staff && (int) $ticket['assigned_to'] > 0 && (int) $ticket['assigned_to'] !== (int) $actor_id) {
            OUS_Notifications::notify(
                (int) $ticket['assigned_to'], 'ticket_reply',
                'New reply on assigned ticket: ' . $ticket['subject'],
                'The requester replied — take a look.',
                admin_url('admin.php?page=bh-tickets&ticket_id=' . (int) $ticket_id), 'BH Tickets'
            );
        }
    }
}
