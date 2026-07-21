<?php
if (!defined('ABSPATH')) exit;

/**
 * Course reviews/ratings — explicitly deferred in an earlier pass (see
 * class-render-course.php's own "no data model exists yet" comment,
 * now resolved). A real table (bhc_reviews, see class-activator.php),
 * not a CPT — same "queryable-across-users, not per-user postmeta"
 * reasoning bhc_progress/bhc_enrollments/bhc_completions already
 * established, and a review is small fixed-shape data with no need
 * for its own editor/list-table chrome.
 *
 * Two product decisions this was built to:
 * - Eligibility is ENROLLMENT, not completion — anyone enrolled can
 *   review, but every review visibly says whether that student had
 *   actually finished the course at the time they wrote it (a
 *   completed_at_review snapshot, not a live-recomputed flag — see
 *   class-activator.php's schema comment for why it's a snapshot).
 * - Every review is held for admin approval before it's ever publicly
 *   visible — same "moderation queue" posture WordPress core comments
 *   already default to, not a bespoke concept.
 */
class BHC_Reviews {
    const CAP = 'bhcore_manage_students'; // same course-content-moderation capability BHC_ProgressAdmin already uses

    private static function required_cap() {
        return class_exists('OUS_Roles') ? self::CAP : 'edit_posts';
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhc_reviews';
    }

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhc_review_approve', [self::class, 'handle_approve']);
        add_action('admin_post_bhc_review_reject', [self::class, 'handle_reject']);
    }

    /* ---------------- submission ---------------- */

    // Rejects anyone not enrolled at all — enrollment (not completion)
    // is the eligibility bar. An edited resubmit
    // (same user+course, the UNIQUE KEY's ON DUPLICATE KEY branch)
    // always resets status back to 'pending' — an edited review is
    // re-moderated, never grandfathered in on its original approval.
    public static function submit_review($user_id, $course_id, $rating, $body) {
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

    public static function ajax_submit_review() {
        check_ajax_referer('bhc_progress', 'nonce'); // same generic front-end nonce every other bh-courses AJAX action already uses
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

    public static function average_rating($course_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM " . self::table() . " WHERE course_id = %d AND status = 'approved'",
            $course_id
        ));
        $count = $row ? (int) $row->cnt : 0;
        return ['average' => $count ? round((float) $row->avg_rating, 1) : null, 'count' => $count];
    }

    // Bulk sibling of average_rating(), same shape as BHC_Progress::
    // enrollment_counts() — used for the catalog's "Highest rated" sort
    // so that sort doesn't run one query per course card.
    public static function average_ratings() {
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

    public static function reviews_for_course($course_id, $status = 'approved') {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE course_id = %d AND status = %s ORDER BY created_at DESC",
            $course_id, $status
        ), ARRAY_A);
    }

    // A student's own review regardless of status — the course page
    // shows this instead of the blank submission form once it exists,
    // so a student can see their pending/rejected/approved state and
    // edit rather than only ever being able to submit once blind.
    public static function user_review($user_id, $course_id) {
        if (!$user_id) return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ), ARRAY_A);
    }

    public static function pending_reviews() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status = 'pending' ORDER BY created_at ASC",
            ARRAY_A
        );
    }

    /* ---------------- admin moderation ---------------- */

    public static function add_menu() {
        add_submenu_page(
            BHC_PostTypes::MENU_PARENT, 'Course Reviews', 'Course Reviews',
            self::required_cap(), 'bhc-reviews', [self::class, 'render_admin']
        );
    }

    public static function handle_approve() { self::handle_moderation('approved'); }
    public static function handle_reject() { self::handle_moderation('rejected'); }

    private static function handle_moderation($new_status) {
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

    public static function render_admin() {
        if (!current_user_can(self::required_cap())) wp_die('Not allowed.', '', ['back_link' => true]);

        echo '<div class="wrap"><h1>Course Reviews</h1>';

        $pending = self::pending_reviews();
        echo '<h2>Pending approval</h2>';
        if (!$pending) {
            echo '<p class="description">No reviews waiting on approval right now.</p>';
        } else {
            self::render_review_table($pending, true);
        }

        // Recently-moderated, for reference/undo-by-eye — approved and
        // rejected together, most recent first, capped so this page
        // never grows unbounded.
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

    private static function render_review_table($rows, $show_actions) {
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
