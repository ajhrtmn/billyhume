<?php
if (!defined('ABSPATH')) exit;

/** BHT_Replies — a flat, append-only thread per ticket. The opening message (BHT_Tickets::create()) is reply #1. */
class BHT_Replies {
    private static function table(): string {
        return BHT_Tables::replies();
    }

    /** @return int|\WP_Error */
    public static function add(int $ticket_id, int $user_id, string $body, bool $is_staff = false) {
        global $wpdb;
        $body = wp_kses_post($body);
        if (trim(wp_strip_all_tags($body)) === '') return new WP_Error('empty', BHT_Words::get('admin.replies.reply_needs_body'));

        $wpdb->insert(self::table(), [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'is_staff' => $is_staff ? 1 : 0,
            'body' => $body,
            'created_at' => current_time('mysql'),
        ]);
        $reply_id = (int) $wpdb->insert_id;
        if (!$reply_id) return new WP_Error('db_error', BHT_Words::get('admin.replies.could_not_save_reply'));

        if (class_exists('BHT_Tickets')) BHT_Tickets::touch($ticket_id);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bht/reply_added', [
                'user_id' => $user_id, 'subject_type' => 'bht_ticket', 'subject_id' => $ticket_id,
                'payload' => ['is_staff' => $is_staff],
            ]);
        }

        self::maybe_notify($ticket_id, $user_id, $is_staff);

        return $reply_id;
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_ticket(int $ticket_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE ticket_id = %d ORDER BY created_at ASC, id ASC', $ticket_id
        ), ARRAY_A);
    }

    /** Staff reply notifies the requester; requester reply notifies the assignee (if any). */
    private static function maybe_notify(int $ticket_id, int $actor_id, bool $is_staff): void {
        if (!class_exists('OUS_Notifications') || !class_exists('BHT_Tickets')) return;
        $ticket = BHT_Tickets::get($ticket_id);
        if (!$ticket) return;

        $url = home_url('/account/?panel=tickets&ticket_id=' . (int) $ticket_id);

        if ($is_staff && (int) $ticket['user_id'] !== (int) $actor_id) {
            OUS_Notifications::notify(
                (int) $ticket['user_id'], 'ticket_reply',
                BHT_Words::get('admin.replies.new_reply_ticket') . ' ' . $ticket['subject'],
                BHT_Words::get('admin.replies.someone_replied_take_look'),
                $url, BHT_Words::get('admin.replies.bh_tickets')
            );
        } elseif (!$is_staff && (int) $ticket['assigned_to'] > 0 && (int) $ticket['assigned_to'] !== (int) $actor_id) {
            OUS_Notifications::notify(
                (int) $ticket['assigned_to'], 'ticket_reply',
                BHT_Words::get('admin.replies.new_reply_assigned_ticket') . ' ' . $ticket['subject'],
                BHT_Words::get('admin.replies.requester_replied_take_look'),
                admin_url('admin.php?page=bh-tickets&ticket_id=' . (int) $ticket_id), BHT_Words::get('admin.replies.bh_tickets')
            );
        }
    }
}
