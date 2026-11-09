<?php
if (!defined('ABSPATH')) exit;

/**
 * Private course feedback from enrolled students, shown on the course page in place of
 * public reviews. Becomes a bh-tickets ticket when that plugin is active, otherwise a
 * notification to the instructor and site admins.
 */
class BHC_Feedback {
    const MAX_LENGTH = 5000;
    const THROTTLE_SECONDS = 120;

    public static function init(): void {
        add_action('admin_post_bhc_course_feedback', [self::class, 'handle']);
    }

    public static function render_form(int $course_id, int $uid): string {
        if (!$uid || !BHC_Progress::enrolled_at($uid, $course_id)) return '';
        ob_start();
        echo '<div class="bhc-feedback" id="bhc-feedback">';
        echo '<h2>Send feedback</h2>';
        if (isset($_GET['bhc_feedback'])) {
            $sent = $_GET['bhc_feedback'] === 'sent';
            echo '<p class="bhc-feedback-result" role="status">' . ($sent ? 'Thanks, your feedback was sent.' : 'That didn\'t send. Please wait a minute and try again.') . '</p>';
        }
        echo '<p class="description">Tell us what\'s working and what isn\'t. Only the course team sees this.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bhc_course_feedback">';
        echo '<input type="hidden" name="course_id" value="' . (int) $course_id . '">';
        wp_nonce_field('bhc_course_feedback_' . $course_id);
        echo '<label for="bhc-feedback-message" class="screen-reader-text">Your feedback</label>';
        echo '<textarea id="bhc-feedback-message" name="message" rows="4" maxlength="' . self::MAX_LENGTH . '" required></textarea>';
        echo '<p><button type="submit" class="bhc-btn bhc-btn-secondary">Send feedback</button></p>';
        echo '</form></div>';
        return (string) ob_get_clean();
    }

    public static function handle(): void {
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $uid = get_current_user_id();
        if (!$uid || !check_admin_referer('bhc_course_feedback_' . $course_id)) wp_die('Not allowed.', '', ['response' => 403]);
        if (get_post_type($course_id) !== 'bh_course' || !BHC_Progress::enrolled_at($uid, $course_id)) wp_die('Not allowed.', '', ['response' => 403]);

        $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
        $message = mb_substr($message, 0, self::MAX_LENGTH);
        $throttle_key = 'bhc_feedback_' . $uid;
        $ok = $message !== '' && !get_transient($throttle_key);
        if ($ok) {
            set_transient($throttle_key, 1, self::THROTTLE_SECONDS);
            self::deliver($uid, $course_id, $message);
        }
        $back = add_query_arg('bhc_feedback', $ok ? 'sent' : 'error', (string) get_permalink($course_id));
        wp_safe_redirect($back . '#bhc-feedback');
        exit;
    }

    private static function deliver(int $uid, int $course_id, string $message): void {
        $title = get_the_title($course_id);
        if (class_exists('BHT_Tickets')) {
            $ticket = BHT_Tickets::create($uid, 'Course feedback: ' . $title, nl2br(esc_html($message)), 'course-feedback');
            if (!is_wp_error($ticket)) return;
        }
        if (!class_exists('OUS_Notifications')) return;
        $user = get_userdata($uid);
        $who = $user ? ($user->display_name ?: $user->user_login) . ' (' . $user->user_email . ')' : 'A student';
        $recipients = array_map('intval', get_users(['role' => 'administrator', 'fields' => 'ID']));
        $instructor = BHC_PostTypes::instructor($course_id);
        if ($instructor) $recipients[] = (int) $instructor->ID;
        foreach (array_unique($recipients) as $recipient) {
            OUS_Notifications::notify($recipient, 'course_feedback', 'Course feedback: ' . $title, nl2br(esc_html($who . " wrote:\n\n" . $message)), (string) get_edit_post_link($course_id, 'raw'), 'BH Courses');
        }
    }
}
