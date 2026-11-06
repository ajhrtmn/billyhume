<?php
if (!defined('ABSPATH')) exit;

/**
 * Course reviews/ratings in the bhc_reviews table. Eligibility is enrollment, not
 * completion; each review carries a completed_at_review snapshot. Every review is held
 * for admin approval before it's public.
 */
class BHC_Reviews {
    const CAP = 'bhcore_manage_students'; // same capability as BHC_ProgressAdmin

    private static function required_cap(): string {
        return class_exists('OUS_Roles') ? self::CAP : 'edit_posts';
    }

    private static function table(): string {
        return BHC_Tables::reviews();
    }

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhc_review_approve', [self::class, 'handle_approve']);
        add_action('admin_post_bhc_review_reject', [self::class, 'handle_reject']);
    }

    /* ---------------- submission ---------------- */

    // Enrollment required. An edited resubmit resets status to 'pending' for re-moderation.
    /** @return true|\WP_Error */
    public static function submit_review(int $user_id, int $course_id, int $rating, string $body) {
        if (!$user_id || !$course_id) return new WP_Error('bhc_review_invalid', 'Missing user or course.');
        if (!BHC_Progress::enrolled_at($user_id, $course_id)) {
            return new WP_Error('bhc_review_not_enrolled', 'You need to be enrolled in this course to leave a review.');
        }

        $rating = max(1, min(5, (int) $rating));
        $body = sanitize_textarea_field((string) $body);
        $completed = BHC_Progress::is_course_completed($user_id, $course_id) ? 1 : 0;

        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, course_id, rating, body, status, completed_at_review, created_at, updated_at)
             VALUES (%d, %d, %d, %s, 'pending', %d, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body), status = 'pending', completed_at_review = VALUES(completed_at_review), updated_at = CURRENT_TIMESTAMP",
            $user_id, $course_id, $rating, $body, $completed
        ));
        if ($result === false) return new WP_Error('bhc_review_db_error', 'Could not save your review — please try again.');
        return true;
    }

    public static function ajax_submit_review(): void {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $course_id = (int) ($_POST['course_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $body = (string) ($_POST['body'] ?? '');

        $result = self::submit_review($user_id, $course_id, $rating, $body);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 403);
        wp_send_json_success(['message' => 'Thanks — your review is awaiting approval before it appears publicly.']);
    }

    /* ---------------- reads ---------------- */

    /** @return array{average: float|null, count: int} */
    public static function average_rating(int $course_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM " . self::table() . " WHERE course_id = %d AND status = 'approved'",
            $course_id
        ));
        $count = $row ? (int) $row->cnt : 0;
        return ['average' => $count ? round((float) $row->avg_rating, 1) : null, 'count' => $count];
    }

    // Bulk sibling of average_rating(), for the catalog's "Highest rated" sort.
    /** @return array<int, array{average: float, count: int}> */
    public static function average_ratings(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT course_id, AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM " . self::table() . " WHERE status = 'approved' GROUP BY course_id",
            ARRAY_A
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['course_id']] = ['average' => round((float) $row['avg_rating'], 1), 'count' => (int) $row['cnt']];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function reviews_for_course(int $course_id, string $status = 'approved'): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE course_id = %d AND status = %s ORDER BY created_at DESC",
            $course_id, $status
        ), ARRAY_A);
    }

    // A student's own review in any status, so they can see its state and edit it.
    /** @return array<string, mixed>|null */
    public static function user_review(int $user_id, int $course_id): ?array {
        if (!$user_id) return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ), ARRAY_A);
    }

    /** @return array<int, array<string, mixed>> */
    public static function pending_reviews(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status = 'pending' ORDER BY created_at ASC",
            ARRAY_A
        );
    }

    /* ---------------- admin moderation ---------------- */

    public static function add_menu(): void {
        add_submenu_page(
            BHC_PostTypes::MENU_PARENT, 'Course Reviews', 'Course Reviews',
            self::required_cap(), 'bhc-reviews', [self::class, 'render_admin']
        );
    }

    public static function handle_approve(): void { self::handle_moderation('approved'); }
    public static function handle_reject(): void { self::handle_moderation('rejected'); }

    private static function handle_moderation(string $new_status): void {
        if (!current_user_can(self::required_cap())) wp_die('Not allowed.', '', ['back_link' => true]);
        check_admin_referer('bhc_review_moderate');
        $id = (int) ($_GET['review_id'] ?? 0);
        if ($id) {
            global $wpdb;
            $wpdb->update(self::table(), ['status' => $new_status, 'updated_at' => current_time('mysql')], ['id' => $id]);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=bh_course&page=bhc-reviews'));
        exit;
    }

    public static function render_admin(): void {
        if (!current_user_can(self::required_cap())) wp_die('Not allowed.', '', ['back_link' => true]);

        echo '<div class="wrap"><h1>Course Reviews</h1>';

        $pending = self::pending_reviews();
        echo '<h2>Pending approval</h2>';
        if (!$pending) {
            echo '<p class="description">No reviews waiting on approval right now.</p>';
        } else {
            self::render_review_table($pending, true);
        }

        // Recently moderated, most recent first, capped.
        global $wpdb;
        $recent = $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status IN ('approved','rejected') ORDER BY updated_at DESC LIMIT 50",
            ARRAY_A
        );
        echo '<h2 style="margin-top:24px;">Recently moderated</h2>';
        if (!$recent) {
            echo '<p class="description">Nothing moderated yet.</p>';
        } else {
            self::render_review_table($recent, false);
        }

        echo '</div>';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function render_review_table($rows, bool $show_actions): void {
        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Course</th><th>Student</th><th>Rating</th><th>Review</th><th>Status</th>';
        if ($show_actions) echo '<th></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $user = get_userdata((int) $row['user_id']);
            $name = $user ? ($user->display_name ?: $user->user_login) : 'User #' . $row['user_id'];
            $course_title = get_the_title((int) $row['course_id']) ?: 'Course #' . $row['course_id'];
            echo '<tr>';
            echo '<td>' . esc_html($course_title) . '</td>';
            echo '<td>' . esc_html($name) . ($row['completed_at_review'] ? ' <span class="description">(completed course)</span>' : ' <span class="description">(enrolled, not yet completed)</span>') . '</td>';
            echo '<td>' . str_repeat('&#9733;', (int) $row['rating']) . str_repeat('&#9734;', 5 - (int) $row['rating']) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($row['body'], 25)) . '</td>';
            echo '<td>' . esc_html(ucfirst($row['status'])) . '</td>';
            if ($show_actions) {
                $approve_url = wp_nonce_url(admin_url('admin-post.php?action=bhc_review_approve&review_id=' . (int) $row['id']), 'bhc_review_moderate');
                $reject_url = wp_nonce_url(admin_url('admin-post.php?action=bhc_review_reject&review_id=' . (int) $row['id']), 'bhc_review_moderate');
                echo '<td><a class="button button-primary button-small" href="' . esc_url($approve_url) . '">Approve</a> <a class="button button-small" href="' . esc_url($reject_url) . '">Reject</a></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
