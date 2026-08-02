<?php
if (!defined('ABSPATH')) exit;

/** "Sessions" portal panel — a student's own upcoming/past sessions, plus a "book a slot" list of what's open. */
class BHC_SessionsPortal {
    public static function init() {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_action('admin_post_bhc_book_slot', [self::class, 'handle_book']);
        add_action('admin_post_bhc_cancel_slot', [self::class, 'handle_cancel']);
    }

    public static function register_panel($panels) {
        $panels[] = [
            'id' => 'sessions',
            'label' => 'Sessions',
            'icon' => 'dashicons-calendar-alt',
            'render' => [self::class, 'render'],
            'priority' => 35, // right after Courses (30), same neighborhood
        ];
        return $panels;
    }

    public static function render() {
        $user_id = get_current_user_id();
        if (!$user_id) return;

        if (isset($_GET['bhc_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhc_msg']))) . '</p></div>';

        $mine = BHC_Sessions::for_student($user_id);
        $upcoming = array_filter($mine, fn($r) => $r['status'] === 'booked' && strtotime($r['starts_at']) >= time());

        echo '<h3>Your upcoming sessions</h3>';
        if (!$upcoming) {
            echo '<p>Nothing booked yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Start</th><th>Duration</th><th></th></tr></thead><tbody>';
            foreach ($upcoming as $r) {
                $hours_left = (strtotime($r['starts_at']) - time()) / HOUR_IN_SECONDS;
                echo '<tr><td>' . esc_html($r['starts_at']) . '</td><td>' . (int) $r['duration_minutes'] . ' min</td><td>';
                if ($hours_left >= BHC_Sessions::cancel_cutoff_hours()) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                    echo '<input type="hidden" name="action" value="bhc_cancel_slot">';
                    echo '<input type="hidden" name="slot_id" value="' . (int) $r['id'] . '">';
                    wp_nonce_field('bhc_cancel_slot_' . $r['id']);
                    echo '<button class="button button-small" onclick="return confirm(\'Cancel this session?\');">Cancel</button>';
                    echo '</form>';
                } else {
                    echo '<span class="description">Too close to start to self-cancel — contact your instructor.</span>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }

        echo '<h3>Book a session</h3>';
        $open = BHC_Sessions::open_slots();
        if (!$open) {
            echo '<p>No open slots right now.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Start</th><th>Duration</th><th></th></tr></thead><tbody>';
        foreach ($open as $r) {
            echo '<tr><td>' . esc_html($r['starts_at']) . '</td><td>' . (int) $r['duration_minutes'] . ' min</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="bhc_book_slot">';
            echo '<input type="hidden" name="slot_id" value="' . (int) $r['id'] . '">';
            wp_nonce_field('bhc_book_slot_' . $r['id']);
            echo '<button class="button button-primary button-small">Book</button>';
            echo '</form></td></tr>';
        }
        echo '</table>';
    }

    public static function handle_book() {
        $slot_id = (int) ($_POST['slot_id'] ?? 0);
        if (!is_user_logged_in() || !check_admin_referer('bhc_book_slot_' . $slot_id)) wp_die('Not allowed.');

        $ok = BHC_Sessions::claim($slot_id, get_current_user_id());
        $msg = $ok ? 'Session booked!' : 'That slot was just taken — pick another.';
        wp_safe_redirect(add_query_arg(['panel' => 'sessions', 'bhc_msg' => rawurlencode($msg)], home_url('/account/')));
        exit;
    }

    public static function handle_cancel() {
        $slot_id = (int) ($_POST['slot_id'] ?? 0);
        if (!is_user_logged_in() || !check_admin_referer('bhc_cancel_slot_' . $slot_id)) wp_die('Not allowed.');

        $result = BHC_Sessions::self_cancel($slot_id, get_current_user_id());
        $msg = is_wp_error($result) ? $result->get_error_message() : ($result ? 'Session cancelled.' : 'Could not cancel that session.');
        wp_safe_redirect(add_query_arg(['panel' => 'sessions', 'bhc_msg' => rawurlencode($msg)], home_url('/account/')));
        exit;
    }
}
