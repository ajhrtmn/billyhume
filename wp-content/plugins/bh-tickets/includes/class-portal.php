<?php
if (!defined('ABSPATH')) exit;

/**
 * "My Tickets" portal panel — registered via the bhi_portal_panels
 * filter (the-self-hosted-self's BHI_Portal, /account/), same zero-central-
 * registration shape every other panel uses. Entirely optional: if
 * BHI_Portal isn't active, this filter just never gets applied and this
 * plugin still works fine through wp-admin alone (BHT_Admin).
 */
class BHT_Portal {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_action('admin_post_bht_portal_new_ticket', [self::class, 'handle_new_ticket']);
        add_action('admin_post_bht_portal_reply', [self::class, 'handle_reply']);
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_panel(array $panels): array {
        $panels[] = [
            'id' => 'tickets',
            'label' => 'Support',
            'icon' => 'dashicons-sos',
            'render' => [self::class, 'render'],
            'priority' => 50,
        ];
        return $panels;
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        if (!$user_id) return;

        $ticket_id = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
        $ticket = $ticket_id ? BHT_Tickets::get($ticket_id) : null;
        // Ownership check — a fan can only ever view their OWN ticket
        // through this panel, regardless of what id is in the URL.
        if ($ticket && (int) $ticket['user_id'] !== $user_id) $ticket = null;

        if (isset($_GET['bht_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bht_msg']))) . '</p></div>';

        $ticket ? self::render_detail($ticket) : self::render_list($user_id);
    }

    private static function render_list(int $user_id): void {
        $tickets = BHT_Tickets::for_user($user_id);

        echo '<h3>New ticket</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bht_portal_new_ticket">';
        wp_nonce_field('bht_portal_new_ticket');
        echo '<p><input type="text" name="subject" class="regular-text" placeholder="Subject" required></p>';
        echo '<p><textarea name="body" rows="5" class="large-text" placeholder="How can we help?" required></textarea></p>';
        echo '<p><button class="button button-primary">Submit</button></p>';
        echo '</form>';

        echo '<h3>Your tickets</h3>';
        if (!$tickets) {
            echo '<p>No tickets yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
        foreach ($tickets as $t) {
            $url = add_query_arg(['panel' => 'tickets', 'ticket_id' => (int) $t['id']]);
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html($t['subject']) . '</a></td>'
               . '<td>' . esc_html(BHT_Tickets::STATUSES[$t['status']] ?? $t['status']) . '</td>'
               . '<td>' . esc_html($t['updated_at']) . '</td></tr>';
        }
        echo '</table>';
    }

    /** @param array<string, mixed> $ticket */
    private static function render_detail(array $ticket): void {
        $ticket_id = (int) $ticket['id'];
        echo '<p><a href="' . esc_url(add_query_arg(['panel' => 'tickets', 'ticket_id' => false])) . '">&larr; Your tickets</a></p>';
        echo '<h3>' . esc_html($ticket['subject']) . '</h3>';
        echo '<p><strong>Status:</strong> ' . esc_html(BHT_Tickets::STATUSES[$ticket['status']] ?? $ticket['status']) . '</p>';

        foreach (BHT_Replies::for_ticket($ticket_id) as $reply) {
            $author = get_userdata((int) $reply['user_id']);
            echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:12px;margin-bottom:8px;' . ($reply['is_staff'] ? 'background:#f0f6fc;' : '') . '">';
            echo '<p><strong>' . esc_html($reply['is_staff'] ? 'Support' : ($author ? $author->display_name : 'You')) . '</strong>'
               . ' <span class="description">' . esc_html($reply['created_at']) . '</span></p>';
            echo wp_kses_post($reply['body']);
            echo '</div>';
        }

        if ($ticket['status'] !== 'closed') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="bht_portal_reply">';
            echo '<input type="hidden" name="ticket_id" value="' . $ticket_id . '">';
            wp_nonce_field('bht_portal_reply_' . $ticket_id);
            echo '<textarea name="body" rows="5" class="large-text" required></textarea>';
            echo '<p><button class="button button-primary">Reply</button></p>';
            echo '</form>';
        }
    }

    public static function handle_new_ticket(): void {
        if (!is_user_logged_in() || !check_admin_referer('bht_portal_new_ticket')) wp_die('Not allowed.');
        $result = BHT_Tickets::create(
            get_current_user_id(),
            wp_unslash($_POST['subject'] ?? ''),
            wp_unslash($_POST['body'] ?? '')
        );
        $msg = is_wp_error($result) ? $result->get_error_message() : 'Ticket submitted.';
        wp_safe_redirect(add_query_arg(['panel' => 'tickets', 'bht_msg' => rawurlencode($msg)], home_url('/account/')));
        exit;
    }

    public static function handle_reply(): void {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
        if (!is_user_logged_in() || !check_admin_referer('bht_portal_reply_' . $ticket_id)) wp_die('Not allowed.');

        $ticket = BHT_Tickets::get($ticket_id);
        // Ownership re-checked server-side — the panel already hides
        // another person's ticket, but the form POST itself must not
        // trust the client.
        if (!$ticket || (int) $ticket['user_id'] !== get_current_user_id()) wp_die('Not allowed.');

        BHT_Replies::add($ticket_id, get_current_user_id(), wp_unslash($_POST['body'] ?? ''), false);
        wp_safe_redirect(add_query_arg(['panel' => 'tickets', 'ticket_id' => $ticket_id, 'bht_msg' => 'Reply sent.'], home_url('/account/')));
        exit;
    }
}
