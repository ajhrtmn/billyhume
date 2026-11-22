<?php
if (!defined('ABSPATH')) exit;

/** "My Tickets" account-portal panel via the bhi_portal_panels filter; optional, wp-admin works without it. */
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
            'label' => BHT_Words::get('portal.tab'),
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
        // A fan can only view their own ticket, whatever id is in the URL.
        if ($ticket && (int) $ticket['user_id'] !== $user_id) $ticket = null;

        if (isset($_GET['bht_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bht_msg']))) . '</p></div>';

        $ticket ? self::render_detail($ticket) : self::render_list($user_id);
    }

    private static function render_list(int $user_id): void {
        $tickets = BHT_Tickets::for_user($user_id);

        echo '<h3>' . BHT_Words::e('portal.new_heading') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bht_portal_new_ticket">';
        wp_nonce_field('bht_portal_new_ticket');
        echo '<p><input type="text" name="subject" class="regular-text" placeholder="' . esc_attr(BHT_Words::get('portal.subject')) . '" required></p>';
        echo '<p><textarea name="body" rows="5" class="large-text" placeholder="' . esc_attr(BHT_Words::get('portal.body')) . '" required></textarea></p>';
        echo '<p><button class="button button-primary">' . BHT_Words::e('portal.submit') . '</button></p>';
        echo '</form>';

        echo '<h3>' . BHT_Words::e('portal.list_heading') . '</h3>';
        if (!$tickets) {
            echo '<p>' . BHT_Words::e('portal.none') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . BHT_Words::e('portal.col_subject') . '</th><th>' . BHT_Words::e('portal.col_status') . '</th><th>' . BHT_Words::e('portal.col_updated') . '</th></tr></thead><tbody>';
        foreach ($tickets as $t) {
            $url = add_query_arg(['panel' => 'tickets', 'ticket_id' => (int) $t['id']]);
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html($t['subject']) . '</a></td>'
               . '<td>' . esc_html(BHT_Words::status($t['status'])) . '</td>'
               . '<td>' . esc_html($t['updated_at']) . '</td></tr>';
        }
        echo '</table>';
    }

    /** @param array<string, mixed> $ticket */
    private static function render_detail(array $ticket): void {
        $ticket_id = (int) $ticket['id'];
        echo '<p><a href="' . esc_url(add_query_arg(['panel' => 'tickets', 'ticket_id' => false])) . '">' . BHT_Words::e('portal.back') . '</a></p>';
        echo '<h3>' . esc_html($ticket['subject']) . '</h3>';
        echo '<p><strong>' . BHT_Words::e('portal.status_label') . '</strong> ' . esc_html(BHT_Words::status($ticket['status'])) . '</p>';

        foreach (BHT_Replies::for_ticket($ticket_id) as $reply) {
            $author = get_userdata((int) $reply['user_id']);
            echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:12px;margin-bottom:8px;' . ($reply['is_staff'] ? 'background:#f0f6fc;' : '') . '">';
            echo '<p><strong>' . esc_html($reply['is_staff'] ? BHT_Words::get('portal.staff_name') : ($author ? $author->display_name : BHT_Words::get('portal.you'))) . '</strong>'
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
            echo '<p><button class="button button-primary">' . BHT_Words::e('portal.reply') . '</button></p>';
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
        $msg = is_wp_error($result) ? $result->get_error_message() : BHT_Words::get('portal.submitted');
        wp_safe_redirect(add_query_arg(['panel' => 'tickets', 'bht_msg' => rawurlencode($msg)], home_url('/account/')));
        exit;
    }

    public static function handle_reply(): void {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
        if (!is_user_logged_in() || !check_admin_referer('bht_portal_reply_' . $ticket_id)) wp_die('Not allowed.');

        $ticket = BHT_Tickets::get($ticket_id);
        // WHY: ownership re-checked on POST; never trust the client.
        if (!$ticket || (int) $ticket['user_id'] !== get_current_user_id()) wp_die('Not allowed.');

        BHT_Replies::add($ticket_id, get_current_user_id(), wp_unslash($_POST['body'] ?? ''), false);
        wp_safe_redirect(add_query_arg(['panel' => 'tickets', 'ticket_id' => $ticket_id, 'bht_msg' => BHT_Words::get('portal.reply_sent')], home_url('/account/')));
        exit;
    }
}
