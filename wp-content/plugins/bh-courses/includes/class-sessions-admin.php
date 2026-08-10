<?php
if (!defined('ABSPATH')) exit;

/** Staff-facing "Sessions" screen — plain submenu under BHC_PostTypes::MENU_PARENT, same sibling-of-Courses/Lessons placement class-progress-admin.php already uses. */
class BHC_SessionsAdmin {
    const CAP = 'bhcore_manage_students';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('admin_post_bhc_create_slot', [self::class, 'handle_create_slot']);
        add_action('admin_post_bhc_set_slot_status', [self::class, 'handle_set_status']);
    }

    private static function required_cap(): string {
        return class_exists('OUS_Roles') ? self::CAP : 'edit_posts';
    }

    public static function add_menu(): void {
        add_submenu_page(
            BHC_PostTypes::MENU_PARENT, 'Sessions', 'Sessions',
            self::required_cap(), 'bhc-sessions', [self::class, 'render']
        );
    }

    public static function maybe_enqueue(string $hook): void {
        if (strpos($hook, 'bhc-sessions') === false) return;
        wp_enqueue_script('bhc-fullcalendar', BHC_URL . 'assets/js/vendor/fullcalendar.global.js', [], '7.0.2', true);
        wp_enqueue_script('bhc-sessions-admin', BHC_URL . 'assets/js/sessions-admin.js', ['bhc-fullcalendar'], BHC_VER, true);
    }

    public static function render(): void {
        if (!current_user_can(self::required_cap())) wp_die('Not allowed.', '', ['back_link' => true]);
        if (isset($_GET['bhc_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhc_msg']))) . '</p></div>';

        BHY_UI::shell_open('Sessions', 'Availability slots for 1:1 sessions — students book from their account portal.');

        $instructor_id = BHC_Sessions::default_instructor_id();
        $rows = BHC_Sessions::for_instructor($instructor_id);

        echo '<div id="bhc-sessions-calendar" data-events="' . esc_attr(wp_json_encode(BHC_Sessions::as_calendar_events($rows))) . '" style="max-width:900px;margin-bottom:24px;"></div>';

        echo '<h2>New slot</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bhc_create_slot">';
        wp_nonce_field('bhc_create_slot');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="starts_at">Start</label></th><td><input type="datetime-local" name="starts_at" id="starts_at" required></td></tr>';
        echo '<tr><th><label for="duration_minutes">Duration (minutes)</label></th><td><input type="number" name="duration_minutes" id="duration_minutes" value="30" min="5" step="5"></td></tr>';
        echo '<tr><th><label for="course_id">Course (optional)</label></th><td><select name="course_id" id="course_id"><option value="0">— Unscoped —</option>';
        foreach (get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]) as $course) {
            echo '<option value="' . (int) $course->ID . '">' . esc_html($course->post_title) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label for="notes">Notes</label></th><td><textarea name="notes" id="notes" rows="3" class="large-text"></textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary">Create slot</button></p>';
        echo '</form>';

        echo '<h2>All slots</h2>';
        if (!$rows) {
            echo '<p>No slots yet.</p>';
        } else {
            echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Start</th><th>Duration</th><th>Course</th><th>Student</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $student = (int) $r['student_id'] ? get_userdata((int) $r['student_id']) : null;
                $course_title = (int) $r['course_id'] ? get_the_title((int) $r['course_id']) : '—';
                echo '<tr>';
                echo '<td>' . esc_html($r['starts_at']) . '</td>';
                echo '<td>' . (int) $r['duration_minutes'] . ' min</td>';
                echo '<td>' . esc_html($course_title) . '</td>';
                echo '<td>' . esc_html($student ? $student->display_name : '—') . '</td>';
                echo '<td>' . esc_html(BHC_Sessions::STATUSES[$r['status']] ?? $r['status']) . '</td>';
                echo '<td>';
                if ($r['status'] !== 'cancelled' && $r['status'] !== 'completed') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                    echo '<input type="hidden" name="action" value="bhc_set_slot_status">';
                    echo '<input type="hidden" name="slot_id" value="' . (int) $r['id'] . '">';
                    echo '<input type="hidden" name="status" value="cancelled">';
                    wp_nonce_field('bhc_set_slot_status_' . $r['id']);
                    echo '<button class="button button-small" onclick="return confirm(\'Cancel this slot?\');">Cancel</button>';
                    echo '</form> ';
                }
                if ($r['status'] === 'booked') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                    echo '<input type="hidden" name="action" value="bhc_set_slot_status">';
                    echo '<input type="hidden" name="slot_id" value="' . (int) $r['id'] . '">';
                    echo '<input type="hidden" name="status" value="completed">';
                    wp_nonce_field('bhc_set_slot_status_' . $r['id']);
                    echo '<button class="button button-small">Mark completed</button>';
                    echo '</form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        BHY_UI::shell_close();
    }

    public static function handle_create_slot(): void {
        if (!current_user_can(self::required_cap()) || !check_admin_referer('bhc_create_slot')) wp_die('Not allowed.');

        $result = BHC_Sessions::create_slot(
            wp_unslash($_POST['starts_at'] ?? ''),
            (int) ($_POST['duration_minutes'] ?? 30),
            (int) ($_POST['course_id'] ?? 0),
            wp_unslash($_POST['notes'] ?? '')
        );
        $msg = is_wp_error($result) ? $result->get_error_message() : 'Slot created.';
        wp_safe_redirect(add_query_arg(['page' => 'bhc-sessions', 'bhc_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public static function handle_set_status(): void {
        $slot_id = (int) ($_POST['slot_id'] ?? 0);
        if (!current_user_can(self::required_cap()) || !check_admin_referer('bhc_set_slot_status_' . $slot_id)) wp_die('Not allowed.');

        BHC_Sessions::set_status($slot_id, sanitize_key($_POST['status'] ?? ''));
        wp_safe_redirect(add_query_arg(['page' => 'bhc-sessions', 'bhc_msg' => 'Updated.'], admin_url('admin.php')));
        exit;
    }
}
