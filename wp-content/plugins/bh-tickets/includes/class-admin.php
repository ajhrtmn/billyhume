<?php
if (!defined('ABSPATH')) exit;

/**
 * Staff-facing list + detail view. A plain top-level admin.php page,
 * NOT a Debug Tools section — this is real, everyday feature UI (same
 * distinction bh-crm's People page makes), not dev/admin-only tooling.
 * Registered with 'parent' => null in this plugin's own
 * ous_registered_plugins self-registration (see bh-tickets.php),
 * matching bh-crm's own documented pattern for exactly this reason —
 * CLAUDE.md's real, multi-session-documented page-hook-resolution
 * incident.
 */
class BHT_Admin {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bht_reply', [self::class, 'handle_reply']);
        add_action('admin_post_bht_set_status', [self::class, 'handle_set_status']);
        add_action('admin_post_bht_assign', [self::class, 'handle_assign']);
    }

    public static function add_menu() {
        add_menu_page('Support Tickets', 'Tickets', 'bhcore_manage_tickets', 'bh-tickets', [self::class, 'render'], 'dashicons-sos', 30);
    }

    public static function render() {
        if (!current_user_can('bhcore_manage_tickets')) wp_die('Not allowed.');

        $ticket_id = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
        BHY_UI::shell_open('Support Tickets');

        if (isset($_GET['bht_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bht_msg']))) . '</p></div>';

        $ticket_id ? self::render_detail($ticket_id) : self::render_list();

        BHY_UI::shell_close();
    }

    private static function render_list() {
        $status = sanitize_key($_GET['status'] ?? '');
        $tickets = BHT_Tickets::all($status);

        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=bh-tickets')) . '">All</a> | ';
        foreach (BHT_Tickets::STATUSES as $key => $label) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=bh-tickets&status=' . $key)) . '">' . esc_html($label) . '</a> ';
        }
        echo '</p>';

        if (!$tickets) {
            echo '<p>No tickets.</p>';
            return;
        }

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . '<th>Subject</th><th>Requester</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Updated</th>'
           . '</tr></thead><tbody>';
        foreach ($tickets as $t) {
            $user = get_userdata((int) $t['user_id']);
            $assignee = (int) $t['assigned_to'] ? get_userdata((int) $t['assigned_to']) : null;
            $url = admin_url('admin.php?page=bh-tickets&ticket_id=' . (int) $t['id']);
            echo '<tr>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($t['subject']) . '</strong></a></td>';
            echo '<td>' . esc_html($user ? $user->display_name : '#' . $t['user_id']) . '</td>';
            echo '<td>' . esc_html(BHT_Tickets::PRIORITIES[$t['priority']] ?? $t['priority']) . '</td>';
            echo '<td>' . esc_html(BHT_Tickets::STATUSES[$t['status']] ?? $t['status']) . '</td>';
            echo '<td>' . esc_html($assignee ? $assignee->display_name : '—') . '</td>';
            echo '<td>' . esc_html($t['updated_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_detail($ticket_id) {
        $ticket = BHT_Tickets::get($ticket_id);
        if (!$ticket) {
            echo '<p>Ticket not found.</p>';
            return;
        }
        $user = get_userdata((int) $ticket['user_id']);

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=bh-tickets')) . '">&larr; All tickets</a></p>';
        echo '<h2>' . esc_html($ticket['subject']) . '</h2>';
        echo '<p><strong>Requester:</strong> ' . esc_html($user ? $user->display_name : '#' . $ticket['user_id'])
           . ' &nbsp; <strong>Priority:</strong> ' . esc_html(BHT_Tickets::PRIORITIES[$ticket['priority']] ?? $ticket['priority']) . '</p>';

        // Status + assignment controls.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px;">';
        echo '<input type="hidden" name="action" value="bht_set_status">';
        echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket_id . '">';
        wp_nonce_field('bht_set_status_' . $ticket_id);
        echo '<select name="status">';
        foreach (BHT_Tickets::STATUSES as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($ticket['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <button class="button">Update status</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="action" value="bht_assign">';
        echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket_id . '">';
        wp_nonce_field('bht_assign_' . $ticket_id);
        wp_dropdown_users(['name' => 'assigned_to', 'selected' => (int) $ticket['assigned_to'], 'show_option_none' => 'Unassigned', 'capability' => 'bhcore_manage_tickets']);
        echo ' <button class="button">Assign</button>';
        echo '</form>';

        // Thread.
        echo '<div class="bht-thread">';
        foreach (BHT_Replies::for_ticket($ticket_id) as $reply) {
            $author = get_userdata((int) $reply['user_id']);
            echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:12px;margin-bottom:8px;' . ($reply['is_staff'] ? 'background:#f0f6fc;' : '') . '">';
            echo '<p><strong>' . esc_html($author ? $author->display_name : '#' . $reply['user_id']) . '</strong>'
               . ($reply['is_staff'] ? ' <span class="bhy-badge bhy-badge-neutral">Staff</span>' : '')
               . ' <span class="description">' . esc_html($reply['created_at']) . '</span></p>';
            echo wp_kses_post($reply['body']);
            echo '</div>';
        }
        echo '</div>';

        echo '<h3>Reply</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bht_reply">';
        echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket_id . '">';
        wp_nonce_field('bht_reply_' . $ticket_id);
        echo '<textarea name="body" rows="6" class="large-text" required></textarea>';
        echo '<p><button class="button button-primary">Send reply</button></p>';
        echo '</form>';
    }

    public static function handle_reply() {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
        if (!current_user_can('bhcore_manage_tickets') || !check_admin_referer('bht_reply_' . $ticket_id)) wp_die('Not allowed.');

        $result = BHT_Replies::add($ticket_id, get_current_user_id(), wp_unslash($_POST['body'] ?? ''), true);
        $msg = is_wp_error($result) ? $result->get_error_message() : 'Reply sent.';
        wp_safe_redirect(add_query_arg(['page' => 'bh-tickets', 'ticket_id' => $ticket_id, 'bht_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public static function handle_set_status() {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
        if (!current_user_can('bhcore_manage_tickets') || !check_admin_referer('bht_set_status_' . $ticket_id)) wp_die('Not allowed.');

        BHT_Tickets::set_status($ticket_id, sanitize_key($_POST['status'] ?? ''));
        wp_safe_redirect(add_query_arg(['page' => 'bh-tickets', 'ticket_id' => $ticket_id, 'bht_msg' => 'Status updated.'], admin_url('admin.php')));
        exit;
    }

    public static function handle_assign() {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
        if (!current_user_can('bhcore_manage_tickets') || !check_admin_referer('bht_assign_' . $ticket_id)) wp_die('Not allowed.');

        BHT_Tickets::assign($ticket_id, (int) ($_POST['assigned_to'] ?? 0));
        wp_safe_redirect(add_query_arg(['page' => 'bh-tickets', 'ticket_id' => $ticket_id, 'bht_msg' => 'Assignment updated.'], admin_url('admin.php')));
        exit;
    }
}
