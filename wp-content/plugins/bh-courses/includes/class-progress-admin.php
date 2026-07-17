<?php
if (!defined('ABSPATH')) exit;

/**
 * The gap called out after v1 shipped: Debug Tools only ever shows ONE
 * seeded student's progress, which isn't a real teaching workflow.
 * This is a plain custom admin page — not a CPT list-table — added as
 * a submenu under bh_course's own top-level menu (same as bh-streaming
 * hangs Releases/Playlists/Feed Sources off its own MENU_PARENT), not
 * routed through the core's admin_menus/OUS_Registry relocation
 * mechanism, since it's naturally a sibling of "Courses"/"Lessons" in
 * the sidebar rather than a core-hub concern.
 *
 * Gated on `bhcore_manage_students` (see the core's OUS_Roles) rather
 * than the generic `edit_posts` this originally shipped with — real
 * student data (progress, quiz scores) is a meaningfully different
 * sensitivity level than "can edit some post somewhere," and the whole
 * point of that capability existing is so a course instructor account
 * that ISN'T a full site admin can be granted exactly this and nothing
 * more. Falls back to `edit_posts` if the core is old enough not to
 * have registered the capability yet (a capability that was never
 * granted to anyone simply means current_user_can() is always false
 * for it, which would silently lock EVERYONE out on an old core —
 * checking class_exists() first avoids that trap).
 */
class BHC_ProgressAdmin {
    const CAP = 'bhcore_manage_students';

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    private static function required_cap() {
        return class_exists('OUS_Roles') ? self::CAP : 'edit_posts';
    }

    public static function add_menu() {
        add_submenu_page(
            BHC_PostTypes::MENU_PARENT, 'Student Progress', 'Student Progress',
            self::required_cap(), 'bhc-progress', [self::class, 'render']
        );
    }

    public static function render() {
        if (!current_user_can(self::required_cap())) wp_die('Not allowed.', '', ['back_link' => true]);
        self::maybe_handle_override(); // processes + queues a settings_errors() notice before any output below

        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);
        $selected_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : ($courses[0]->ID ?? 0);
        // The override form POSTs its own course_id (see render_override_form()),
        // so a submission stays on the same course afterward rather than
        // silently jumping back to whichever one is first alphabetically —
        // matches the GET-based selection precedence above it.
        if (isset($_POST['course_id'])) $selected_id = (int) $_POST['course_id'];

        echo '<div class="wrap"><h1>Student Progress</h1>';
        settings_errors('bhc_progress_admin');

        if (!$courses) { echo '<p>No courses yet.</p></div>'; return; }

        echo '<form method="get" style="margin:16px 0;"><input type="hidden" name="post_type" value="bh_course"><input type="hidden" name="page" value="bhc-progress">';
        echo '<select name="course_id" onchange="this.form.submit()">';
        foreach ($courses as $c) {
            echo '<option value="' . (int) $c->ID . '"' . selected($selected_id, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select></form>';

        if (!$selected_id) { echo '</div>'; return; }
        self::render_override_form($selected_id);
        self::render_summary($selected_id);
        self::render_course_table($selected_id);
        echo '</div>';
    }

    // Stalled = has recorded activity but hasn't touched this course in
    // this many days and hasn't finished it — the raw "last activity"
    // column already on the per-student table told you WHEN someone was
    // last active, but never flagged that as a problem; an instructor
    // with more than a handful of students had to eyeball every row
    // themselves to notice who'd gone quiet.
    const STALLED_DAYS = 14;

    /* ---------------- aggregate summary (per-lesson completion rate,
       avg quiz score, stalled-student count) ----------------
       The per-student table below already has all the per-step raw
       data this rolls up — deliberately NOT a new report page/data
       source, just a different aggregation of the same bhc_progress
       rows, same relationship BHC_PostTypes::step_count() already has
       to lesson_order()/BHC_Steps::count(). */
    private static function render_summary($course_id) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        $students = self::students_for_course($course_id);
        if (!$lesson_ids || !$students) return;

        $stalled_cutoff = current_time('timestamp') - (self::STALLED_DAYS * DAY_IN_SECONDS);
        $stalled = 0;
        foreach ($students as $user_id) {
            if (BHC_Progress::is_course_completed($user_id, $course_id)) continue;
            $last = BHC_Progress::last_activity_for_course($user_id, $course_id);
            if ($last && strtotime($last) < $stalled_cutoff) $stalled++;
        }

        echo '<div class="bhy-card" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;background:#fff;">';
        echo '<h2 style="margin-top:0;font-size:14px;">At a glance</h2>';
        if ($stalled > 0) {
            echo '<p><strong style="color:#b32d2e;">' . (int) $stalled . ' student' . ($stalled === 1 ? '' : 's') . '</strong> active before but quiet for ' . self::STALLED_DAYS . '+ days and not yet finished — flagged &#9888; in the table below.</p>';
        } else {
            echo '<p class="description">No stalled students right now (none quiet ' . self::STALLED_DAYS . '+ days without finishing).</p>';
        }

        echo '<table class="widefat striped" style="max-width:720px;"><thead><tr><th>Lesson</th><th>Completion rate</th><th>Avg. quiz score</th></tr></thead><tbody>';
        foreach ($lesson_ids as $lesson_id) {
            $step_count = BHC_Steps::count($lesson_id);
            $finished = 0;
            foreach ($students as $user_id) {
                if ($step_count && count(BHC_Progress::completed_steps($user_id, $lesson_id)) >= $step_count) $finished++;
            }
            $rate = count($students) ? round(($finished / count($students)) * 100) : 0;
            $quiz_avg = self::lesson_avg_quiz_score($lesson_id);

            echo '<tr><td>' . esc_html(get_the_title($lesson_id)) . '</td>';
            echo '<td>' . (int) $rate . '% (' . (int) $finished . '/' . count($students) . ')</td>';
            echo '<td>' . ($quiz_avg === null ? '&#8212;' : (int) round($quiz_avg) . '%') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // Averages every quiz step's mean score, then averages those means —
    // deliberately not a single flat AVG(score) across all quiz rows in
    // the lesson, which would let a lesson with one easy 2-question quiz
    // and one hard 10-question quiz have the easy one dominate just
    // because more students reached it. Null if the lesson has no quiz
    // steps at all, so the table can show "—" instead of a misleading 0%.
    private static function lesson_avg_quiz_score($lesson_id) {
        global $wpdb;
        $steps = BHC_Steps::get($lesson_id);
        $quiz_indexes = [];
        foreach ($steps as $i => $step) if (($step['type'] ?? '') === 'quiz') $quiz_indexes[] = $i;
        if (!$quiz_indexes) return null;

        $sums = [];
        foreach ($quiz_indexes as $i) {
            $avg = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(score) FROM " . $wpdb->prefix . "bhc_progress WHERE lesson_id = %d AND step_index = %d AND score IS NOT NULL",
                $lesson_id, $i
            ));
            if ($avg !== null) $sums[] = (float) $avg;
        }
        return $sums ? array_sum($sums) / count($sums) : null;
    }

    /* ---------------- manual override ---------------- */

    // The real, ordinary support-request gap: a student's AJAX call
    // failed, or an instructor just wants to comp someone past a step —
    // there was previously no way to do either without a database
    // console. Deliberately scoped to "mark complete," not a general
    // progress editor: it force-completes every not-yet-done step in
    // either one lesson or the whole course, going through
    // BHC_Progress::mark_step_complete() itself (never a raw $wpdb
    // write) so bhc_course_completed still fires normally and a course
    // that reaches 100% this way behaves identically to one reached by
    // actually taking it.
    //
    // Deliberately NOT a general enrollment/access tool: the student
    // dropdown only lists people who already have at least one progress
    // row for this course (see students_for_course()) — granting access
    // to someone with zero activity is BHM_Gate's/an entitlement's job,
    // not this page's.
    private static function maybe_handle_override() {
        if (!isset($_POST['bhc_override_action'])) return;
        if (!current_user_can(self::required_cap())) return;
        if (!isset($_POST['bhc_override_nonce']) || !wp_verify_nonce($_POST['bhc_override_nonce'], 'bhc_override')) return;

        $course_id = (int) ($_POST['course_id'] ?? 0);
        $student_id = (int) ($_POST['student_user_id'] ?? 0);
        $lesson_id = (int) ($_POST['lesson_id'] ?? 0); // 0 = whole course

        if (!$course_id || !$student_id) {
            add_settings_error('bhc_progress_admin', 'bhc_override_missing', 'Pick a student to mark complete.', 'error');
            return;
        }

        $lesson_ids = $lesson_id ? [$lesson_id] : BHC_PostTypes::lesson_order($course_id);
        $touched = 0;
        foreach ($lesson_ids as $lid) {
            $touched += self::force_complete_lesson($student_id, $lid);
        }

        $student = get_userdata($student_id);
        $name = $student ? ($student->display_name ?: $student->user_login) : "User #$student_id";
        add_settings_error('bhc_progress_admin', 'bhc_override_done',
            $touched ? "Marked $touched step(s) complete for $name." : "Nothing to do — $name was already complete there.",
            'success');
    }

    // Returns how many previously-incomplete steps got force-marked.
    // Quiz steps get scored 100/passed=1 rather than left un-scored —
    // is_step_complete()'s own rule is that a quiz row only reads "done"
    // once passed, so anything less would silently fail to actually
    // unblock the student this was meant to unblock.
    private static function force_complete_lesson($user_id, $lesson_id) {
        $steps = BHC_Steps::get($lesson_id);
        $count = 0;
        foreach ($steps as $i => $step) {
            if (BHC_Progress::is_step_complete($user_id, $lesson_id, $i)) continue;
            if (($step['type'] ?? '') === 'quiz') {
                BHC_Progress::mark_step_complete($user_id, $lesson_id, $i, 100, 1);
            } else {
                BHC_Progress::mark_step_complete($user_id, $lesson_id, $i);
            }
            $count++;
        }
        return $count;
    }

    private static function render_override_form($course_id) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        $students = self::students_for_course($course_id);

        echo '<div class="bhy-card" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;background:#fff;max-width:640px;">';
        echo '<h2 style="margin-top:0;font-size:14px;">Manual override</h2>';
        echo '<p class="description">For support cases where progress didn\'t record correctly — marks steps complete directly (quiz steps as scored/passed), bypassing normal submission. Only students with existing activity in this course are listed; this isn\'t an enrollment or access tool.</p>';

        if (!$students) {
            echo '<p class="description"><em>No students with recorded activity yet — nothing to override.</em></p></div>';
            return;
        }

        echo '<form method="post">';
        wp_nonce_field('bhc_override', 'bhc_override_nonce');
        echo '<input type="hidden" name="bhc_override_action" value="1">';
        echo '<input type="hidden" name="course_id" value="' . (int) $course_id . '">';

        echo '<p><label>Student<br><select name="student_user_id">';
        foreach ($students as $sid) {
            $u = get_userdata($sid);
            echo '<option value="' . (int) $sid . '">' . esc_html($u ? ($u->display_name ?: $u->user_login) : "User #$sid") . '</option>';
        }
        echo '</select></label></p>';

        echo '<p><label>Scope<br><select name="lesson_id"><option value="0">— Entire course —</option>';
        foreach ($lesson_ids as $lid) {
            echo '<option value="' . (int) $lid . '">' . esc_html(get_the_title($lid)) . '</option>';
        }
        echo '</select></label></p>';

        submit_button('Mark complete', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    private static function render_course_table($course_id) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        $lesson_titles = array_map('get_the_title', $lesson_ids);
        $total_steps = array_sum(array_map(['BHC_Steps', 'count'], $lesson_ids));

        $students = self::students_for_course($course_id);

        if (!$students) {
            echo '<p class="description">No student activity recorded for this course yet.</p>';
            return;
        }

        // .bhy-table-wrap (core's shared design system — see BHY_UI) —
        // this table gets a real column PER LESSON, which is exactly
        // the "genuinely wide, not just wide because nobody trimmed it"
        // case that wrapper exists for. A course with a dozen lessons
        // would otherwise either overflow the admin viewport with no
        // way to see the rest, or get crushed illegibly on anything
        // narrower than a desktop — the wrapper's horizontal scroll (and
        // denser padding once it's genuinely tight) is a real fix here,
        // not a cosmetic one.
        // --tall: this page's whole purpose is this one table (a course
        // selector above it, nothing else) — same "the table IS the
        // page" reasoning as Reports/Registry Submissions/CRM People.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall"><table class="widefat striped"><thead><tr><th>Student</th><th>Progress</th><th>Completed</th><th>Last activity</th>';
        foreach ($lesson_titles as $title) echo '<th>' . esc_html($title) . '</th>';
        echo '</tr></thead><tbody>';

        $stalled_cutoff = current_time('timestamp') - (self::STALLED_DAYS * DAY_IN_SECONDS);

        foreach ($students as $user_id) {
            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $completed = BHC_Progress::is_course_completed($user_id, $course_id);
            $last_activity = BHC_Progress::last_activity_for_course($user_id, $course_id);
            $user = get_userdata($user_id);
            $name = $user ? ($user->display_name ?: $user->user_login) : "User #$user_id";
            $is_stalled = !$completed && $last_activity && strtotime($last_activity) < $stalled_cutoff;

            echo '<tr>';
            echo '<td><strong>' . esc_html($name) . '</strong>' . ($is_stalled ? ' <span title="Quiet ' . self::STALLED_DAYS . '+ days, not finished" style="color:#b32d2e;">&#9888;</span>' : '') . '</td>';
            echo '<td><div class="bhc-admin-progress-bar" style="background:#e0e0e0;border-radius:3px;width:120px;height:8px;display:inline-block;overflow:hidden;vertical-align:middle;"><div style="background:#2271b1;height:100%;width:' . (int) $percent . '%;"></div></div> ' . (int) $percent . '%</td>';
            echo '<td>' . ($completed ? '&#10003; Yes' : '&#8212;') . '</td>';
            echo '<td>' . ($last_activity ? esc_html(human_time_diff(strtotime($last_activity), current_time('timestamp')) . ' ago') : '&#8212;') . '</td>';

            foreach ($lesson_ids as $lesson_id) {
                $step_count = BHC_Steps::count($lesson_id);
                $done = count(BHC_Progress::completed_steps($user_id, $lesson_id));
                echo '<td>' . (int) $done . '/' . (int) $step_count . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">' . count($students) . ' student(s) with recorded activity. ' . (int) $total_steps . ' total step(s) in this course.</p>';
    }

    // Thin wrapper kept for call-site brevity — the real implementation
    // moved to BHC_Progress (shared with class-nudges.php's stalled-
    // student check) so this page and the nudge job read off one query,
    // not two independently-maintained copies.
    private static function students_for_course($course_id) {
        return BHC_Progress::students_for_course($course_id);
    }
}
