<?php
if (!defined('ABSPATH')) exit;

/**
 * Reads/writes bhc_progress (see class-activator.php). A plain content
 * step (text/image) completes on an explicit "mark complete" click from
 * the front end; a quiz step only completes via a submitted attempt,
 * and only counts as "passed" if the score clears the step's own
 * passing_score. Also owns enrollment recording (bhc_enrollments — see
 * class-gate.php's drip-scheduling use of it) and course-completion
 * detection (bhc_completions + the bhc_course_completed action).
 */
class BHC_Progress {
    public static function init() {
        // Nothing to hook here beyond the two AJAX actions registered
        // in the main bootstrap file — kept as an init() entry point
        // anyway for consistency with every other class in this
        // ecosystem.

        // BH_Event registration (own-ur-shit's event-tracking layer) —
        // see enroll_if_needed()/mark_step_complete()/
        // maybe_fire_course_completed() below for the actual emit()
        // calls. Per EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6.
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhc/enroll', ['course_id' => 'int']);
            BH_Event::register_event_type('bhc/step_completed', ['lesson_id' => 'int', 'step_index' => 'int', 'score' => 'int|null', 'passed' => 'bool|null']);
            BH_Event::register_event_type('bhc/course_completed', ['course_id' => 'int']);
        }
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhc_progress';
    }

    public static function step_status($user_id, $lesson_id, $step_index) {
        global $wpdb;
        if (!$user_id) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d AND step_index = %d",
            $user_id, $lesson_id, $step_index
        ), ARRAY_A);
    }

    public static function is_step_complete($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        // A quiz step only counts as "complete" (i.e. the walker can
        // advance past it) once passed — a failed attempt still writes
        // a row (so attempts/score are tracked), but shouldn't read as
        // done. A non-quiz row has passed = NULL, which the ?? true
        // treats as "nothing to pass, so complete on write" — unchanged
        // behavior from before max_attempts existed.
        if (!$row) return false;
        return $row['passed'] === null ? true : (bool) $row['passed'];
    }

    // Highest step index a user has actually cleared in this lesson,
    // i.e. how far they can navigate to (next-uncompleted-step gating
    // lives in class-render.php, not here — this is just the data).
    // Only counts steps that are genuinely done per is_step_complete()'s
    // rule above (a failed, attempts-exhausted quiz never counts).
    public static function completed_steps($user_id, $lesson_id) {
        global $wpdb;
        if (!$user_id) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT step_index, passed FROM " . self::table() . " WHERE user_id = %d AND lesson_id = %d ORDER BY step_index",
            $user_id, $lesson_id
        ), ARRAY_A);
        $done = [];
        foreach ($rows as $row) {
            if ($row['passed'] === null || (int) $row['passed'] === 1) $done[] = (int) $row['step_index'];
        }
        return $done;
    }

    public static function attempts($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        return $row ? (int) $row['attempts'] : 0;
    }

    // $answers_json: pre-encoded JSON string (or null), the per-question
    // snapshot from BHC_Steps::score_quiz()'s 'questions' detail — see
    // ajax_submit_quiz() below for where it's assembled. Same conditional-
    // NULL-placeholder technique the score/passed QA fix already
    // established (see the comment below): a plain text/image step, or a
    // quiz row written before this column existed, gets a real SQL NULL,
    // never a stringified 'null'/empty string.
    public static function mark_step_complete($user_id, $lesson_id, $step_index, $score = null, $passed = null, $answers_json = null) {
        global $wpdb;
        // QA fix: $wpdb->prepare() has no NULL passthrough for scalar
        // placeholders — a PHP null bound through %s/%d is cast to ''/0
        // before the query runs, so the old version here was writing
        // score = 0 / passed = 0 for every plain text/image step instead
        // of a real SQL NULL. is_step_complete()/completed_steps() both
        // explicitly test `$row['passed'] === null` to detect "non-quiz
        // step, complete once a row exists at all" — with passed coming
        // back as "0" instead of NULL, that check always failed, so
        // every non-quiz step permanently read as incomplete. Building
        // the column list/placeholders conditionally keeps a real SQL
        // NULL for the non-quiz case while still using %d for real values.
        $score_sql   = $score === null ? 'NULL' : '%d';
        $passed_sql  = $passed === null ? 'NULL' : '%d';
        $answers_sql = $answers_json === null ? 'NULL' : '%s';
        $values = [$user_id, $lesson_id, $step_index];
        if ($score !== null) $values[] = (int) $score;
        if ($passed !== null) $values[] = (int) $passed;
        if ($answers_json !== null) $values[] = (string) $answers_json;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, lesson_id, step_index, score, passed, attempts, answers)
             VALUES (%d, %d, %d, $score_sql, $passed_sql, 1, $answers_sql)
             ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP, score = VALUES(score), passed = VALUES(passed), answers = VALUES(answers), attempts = attempts + 1",
            $values
        ));
        if ($result === false && class_exists('OUS_DebugLog')) {
            // First OUS_DebugLog call anywhere in bh-courses — previously
            // a failed write here meant the AJAX handler still reported
            // "step complete" to the student (see class-progress.php's
            // ajax_submit_quiz()/ajax_mark_complete(), which don't
            // separately check this method's success), so progress could
            // silently not persist with zero trace anywhere.
            OUS_DebugLog::log('error', 'mark_step_complete() DB write failed — student will still be told the step completed.', [
                'user_id' => $user_id, 'lesson_id' => $lesson_id, 'step_index' => $step_index, 'db_error' => $wpdb->last_error,
            ], 'BH Courses Progress');
        }

        // BH_Event: additive alongside the existing bhc_progress write
        // above — every completed-step write (not attempt) gets a real
        // per-event record. Append-only (no dedup_key): a step being
        // re-marked complete on a resubmit/refresh is tolerated the same
        // way this table's own ON DUPLICATE KEY UPDATE already is.
        if (($passed === null || $passed) && class_exists('BH_Event')) {
            BH_Event::emit('bhc/step_completed', [
                'user_id' => $user_id,
                'subject_type' => 'bhc_lesson', 'subject_id' => (int) $lesson_id,
                'payload' => ['step_index' => (int) $step_index, 'score' => $score, 'passed' => $passed],
            ]);
        }

        if ($passed === null || $passed) {
            self::maybe_fire_course_completed($user_id, BHC_PostTypes::course_for_lesson($lesson_id));
        }
    }

    public static function watched_percent($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        return $row && $row['watched_percent'] !== null ? (int) $row['watched_percent'] : 0;
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: called on a
    // throttled cadence from the front end's <video> timeupdate listener
    // (courses.js), NOT once per step like mark_step_complete() — this is
    // a lightweight progress ping, not a completion event. Auto-completes
    // the step itself (via mark_step_complete(), same path the manual
    // "Mark complete" button already uses) once $percent clears the
    // step's own watch_threshold, so a video step with a threshold set
    // never needs a separate button click to advance — same "no bespoke
    // second completion mechanic" posture the resource step's docblock
    // already established for non-blocking steps.
    public static function update_watch_progress($user_id, $lesson_id, $step_index, $percent) {
        global $wpdb;
        $percent = max(0, min(100, (int) $percent));

        // INSERT ... ON DUPLICATE KEY, with the UPDATE clause taking
        // GREATEST() of the old and new value — a student who rewinds to
        // re-watch an earlier section sends a lower percent on the next
        // tick than what's already stored, and that must never regress
        // recorded progress. attempts is left untouched here (unlike
        // mark_step_complete()'s own ON DUPLICATE KEY clause) since a
        // progress ping isn't a completion attempt.
        //
        // passed = 0 (not NULL) on the INSERT branch only, and left alone
        // entirely by the UPDATE clause — a real bug caught by running
        // this live: is_step_complete()'s rule reads a non-quiz row with
        // passed IS NULL as already complete (correct for a plain text/
        // image row, which is ONLY ever created by an explicit Mark-
        // complete click), so a threshold-gated video step read as
        // "done" after the very first progress ping, before the
        // threshold was ever reached. Since passed starts at 0 here and
        // the UPDATE clause never touches it again, the row stays
        // correctly "not complete" through every ping — mark_step_complete()
        // below is what flips it to the real NULL-means-complete state,
        // and only once, once threshold is actually crossed.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, lesson_id, step_index, watched_percent, passed, attempts)
             VALUES (%d, %d, %d, %d, 0, 0)
             ON DUPLICATE KEY UPDATE watched_percent = GREATEST(COALESCE(watched_percent, 0), VALUES(watched_percent))",
            $user_id, $lesson_id, $step_index, $percent
        ));

        $step = BHC_Steps::get_step($lesson_id, $step_index);
        $threshold = (int) ($step['watch_threshold'] ?? 0);
        if ($threshold > 0 && $percent >= $threshold && !self::is_step_complete($user_id, $lesson_id, $step_index)) {
            self::mark_step_complete($user_id, $lesson_id, $step_index);
            return true; // tells the AJAX caller the step just auto-completed
        }
        return false;
    }

    // The stored per-question snapshot for a quiz step, decoded — null if
    // this isn't a (scored, answers-bearing) quiz row, or predates the
    // answers column. Used by class-render.php to render a static review
    // of a passed quiz instead of re-deriving anything from the current
    // (possibly since-edited) _bhc_steps content — see the answers
    // column's own comment in class-activator.php for why that matters.
    public static function stored_answers($user_id, $lesson_id, $step_index) {
        $row = self::step_status($user_id, $lesson_id, $step_index);
        if (!$row || empty($row['answers'])) return null;
        $decoded = json_decode($row['answers'], true);
        return is_array($decoded) ? $decoded : null;
    }

    // Percent of a whole COURSE a user has completed — steps across
    // every lesson in the course's own order, not just one lesson.
    public static function course_percent($user_id, $course_id) {
        if (!$user_id) return 0;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return 0;

        $total = 0;
        $done = 0;
        foreach ($lesson_ids as $lesson_id) {
            $step_count = BHC_Steps::count($lesson_id);
            $total += $step_count;
            $done += count(self::completed_steps($user_id, $lesson_id));
        }
        return $total ? (int) round(($done / $total) * 100) : 0;
    }

    // Average of this student's current best/latest quiz score across
    // every quiz step in the course they've actually attempted — a
    // real-time "quiz mastery" signal, not a stored attempt history
    // (bhc_progress.score only keeps the latest attempt per step, same
    // limitation as the rest of this file). Only quiz steps ever get a
    // non-null score written (mark_step_complete()'s own NULL-vs-real
    // branching above), so filtering on score IS NOT NULL is sufficient
    // to isolate them without a second lookup against each step's type.
    // Returns null (not 0) when no quiz has been attempted yet —
    // callers must treat that as "nothing to show" (never a bare "0%"
    // before a student has actually taken a quiz).
    public static function course_quiz_average($user_id, $course_id) {
        if (!$user_id) return null;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return null;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        $scores = $wpdb->get_col($wpdb->prepare(
            "SELECT score FROM " . self::table() . " WHERE user_id = %d AND lesson_id IN ($placeholders) AND score IS NOT NULL",
            array_merge([$user_id], $lesson_ids)
        ));
        if (!$scores) return null;
        $scores = array_map('floatval', $scores);
        return (int) round(array_sum($scores) / count($scores));
    }

    // Every distinct user_id with a progress row on ANY lesson belonging
    // to this course — moved here from class-progress-admin.php (which
    // had its own private copy) so class-nudges.php's stalled-student
    // check and the admin Student Progress page read off one shared
    // implementation instead of two that could drift apart.
    public static function students_for_course($course_id) {
        global $wpdb;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return [];
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}bhc_progress WHERE lesson_id IN ($placeholders) ORDER BY user_id",
            $lesson_ids
        )));
    }

    public static function last_activity_for_course($user_id, $course_id) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return null;
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        return $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(completed_at) FROM {$wpdb->prefix}bhc_progress WHERE user_id = %d AND lesson_id IN ($placeholders)",
            array_merge([$user_id], $lesson_ids)
        ));
    }

    // Batched replacement for what class-progress-admin.php's Student
    // Progress page previously did with completed_steps()/course_percent()
    // called once per (student, lesson) pair — on a 20-lesson course with
    // 200 active students that was up to ~4,000 individual queries per
    // page load. One query here, everything else below is plain PHP
    // aggregation over rows already in memory. Every consumer OTHER than
    // that admin page (course/lesson rendering, gating) still calls the
    // single-user methods above — those are already O(1) per page view
    // and don't need batching.
    public static function course_progress_matrix($course_id) {
        global $wpdb;
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return ['user_ids' => [], 'completed' => [], 'last_activity' => [], 'quiz_scores' => []];

        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, lesson_id, step_index, passed, score, completed_at FROM " . self::table() . " WHERE lesson_id IN ($placeholders)",
            $lesson_ids
        ), ARRAY_A);

        $user_ids = [];
        $completed = [];      // [user_id][lesson_id] => [step_index, ...]
        $last_activity = [];  // [user_id] => latest completed_at string
        $quiz_scores = [];    // [lesson_id][step_index] => [score, ...]

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $lid = (int) $row['lesson_id'];
            $idx = (int) $row['step_index'];
            $user_ids[$uid] = true;

            // Same completion rule as is_step_complete()/completed_steps():
            // passed IS NULL means "non-quiz, complete on write".
            if ($row['passed'] === null || (int) $row['passed'] === 1) {
                $completed[$uid][$lid][] = $idx;
            }
            if ($row['completed_at'] && (!isset($last_activity[$uid]) || strtotime($row['completed_at']) > strtotime($last_activity[$uid]))) {
                $last_activity[$uid] = $row['completed_at'];
            }
            if ($row['score'] !== null) {
                $quiz_scores[$lid][$idx][] = (float) $row['score'];
            }
        }

        $user_ids = array_map('intval', array_keys($user_ids));
        sort($user_ids); // matches students_for_course()'s own ORDER BY user_id

        return ['user_ids' => $user_ids, 'completed' => $completed, 'last_activity' => $last_activity, 'quiz_scores' => $quiz_scores];
    }

    // Batched sibling of is_course_completed() — one query for every
    // student on the page instead of one COUNT(*) per student.
    public static function completed_user_ids($course_id, array $user_ids) {
        if (!$user_ids) return [];
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bhc_completions WHERE course_id = %d AND user_id IN ($placeholders)",
            array_merge([$course_id], $user_ids)
        ));
        return array_flip(array_map('intval', $rows));
    }

    // The lesson-level sibling of class-render.php's own step-level
    // "first not-yet-completed" logic (render_lesson_steps()'s
    // $start_index calc) — same idea, one level up, for the course-page
    // "Continue" CTA. Skips lessons that aren't published, aren't open
    // yet (drip-locked), or have no steps at all (nothing to resume
    // into). Returns null if every open lesson is fully done — the
    // caller (class-render.php) treats that as "show a Review/Complete
    // state instead."
    public static function first_incomplete_lesson($user_id, $course_id) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        foreach ($lesson_ids as $lesson_id) {
            if (get_post_status($lesson_id) !== 'publish') continue;
            if (class_exists('BHC_Gate') && !BHC_Gate::lesson_is_open($user_id, $lesson_id)) continue;
            $step_count = BHC_Steps::count($lesson_id);
            if (!$step_count) continue;
            $done_count = $user_id ? count(self::completed_steps($user_id, $lesson_id)) : 0;
            if ($done_count < $step_count) return $lesson_id;
        }
        return null;
    }

    /* ---------------- enrollment (drip scheduling's clock-start) ---------------- */

    // Called once access is confirmed (class-render.php, on viewing the
    // course or a lesson in it) — cheap no-op on every repeat visit
    // thanks to INSERT IGNORE, so callers don't need their own "have I
    // already enrolled this person" check.
    public static function enroll_if_needed($user_id, $course_id) {
        if (!$user_id || !$course_id) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bhc_enrollments (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
        // Deduplicated by design — dedup_key means a repeat visit's
        // repeat call to this cheap-no-op method never records a second
        // 'bhc/enroll' event for the same user+course.
        if ($wpdb->rows_affected === 1) {
            if (class_exists('BH_Event')) {
                BH_Event::emit('bhc/enroll', [
                    'user_id' => $user_id,
                    'subject_type' => 'bhc_course', 'subject_id' => (int) $course_id,
                    'dedup_key' => "bhc/enroll:$user_id:$course_id",
                ]);
            }
            // Real WP action, same "first real consumer of
            // OUS_Notifications" shape as bhc_course_completed
            // (class-crm-integration.php) — a student previously got no
            // confirmation at all that enrolling "took," only silent
            // access. Only fires on the actual INSERT (rows_affected===1),
            // never on the cheap repeat-visit no-op above.
            do_action('bhc_enrolled', $user_id, $course_id);
        }
    }

    public static function enrolled_at($user_id, $course_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT enrolled_at FROM {$wpdb->prefix}bhc_enrollments WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    // Every user actually enrolled in a course — distinct from
    // students_for_course() above, which only returns users with a
    // bhc_progress ROW (i.e. who've already touched a step). A student
    // waiting on a drip-locked lesson may be enrolled with zero progress
    // rows yet, so class-drip-nudges.php needs this list, not that one.
    public static function enrolled_user_ids($course_id) {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bhc_enrollments WHERE course_id = %d",
            $course_id
        )));
    }

    // The catalog's "popular" sort signal (QUIZ-AND-CATALOG-DESIGN-PLAN.md
    // Part 2.3) — a real, deduped, non-gameable enrollment count read
    // straight off bhc_enrollments' own UNIQUE KEY (user_id, course_id),
    // never a denormalized counter column that could drift out of sync.
    // Returns course_id => count for every course with at least one
    // enrollment; a course with zero simply isn't in the returned array
    // (callers treat a missing key as 0).
    public static function enrollment_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT course_id, COUNT(*) AS c FROM {$wpdb->prefix}bhc_enrollments GROUP BY course_id",
            ARRAY_A
        );
        $counts = [];
        foreach ($rows as $row) $counts[(int) $row['course_id']] = (int) $row['c'];
        return $counts;
    }

    /* ---------------- completion (bhc_course_completed) ---------------- */

    // Fires 'bhc_course_completed' ($user_id, $course_id) the first
    // time — and only the first time — a student clears 100% of a
    // course. Dedup is enforced by bhc_completions' own UNIQUE KEY (an
    // atomic INSERT that either succeeds once or silently no-ops on
    // every later call), not by an application-level "have I fired this
    // already" check, so a race between two nearly-simultaneous last
    // steps can't double-fire it either.
    private static function maybe_fire_course_completed($user_id, $course_id) {
        if (!$user_id || !$course_id) return;
        if (self::course_percent($user_id, $course_id) < 100) return;

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bhc_completions (user_id, course_id) VALUES (%d, %d)",
            $user_id, $course_id
        ));
        if ((int) $wpdb->rows_affected === 1) {
            do_action('bhc_course_completed', $user_id, $course_id);
            // Same once-only guarantee as the do_action() above (both
            // gated on the same INSERT IGNORE affecting exactly one
            // row) — dedup_key is a second, independent enforcement at
            // the bhcore_events table level.
            if (class_exists('BH_Event')) {
                BH_Event::emit('bhc/course_completed', [
                    'user_id' => $user_id,
                    'subject_type' => 'bhc_course', 'subject_id' => (int) $course_id,
                    'dedup_key' => "bhc/course_completed:$user_id:$course_id",
                ]);
            }
        }
    }

    public static function is_course_completed($user_id, $course_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhc_completions WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
    }

    /* ---------------- AJAX handlers (logged-in students) ---------------- */

    public static function ajax_mark_complete() {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] === 'quiz') {
            wp_send_json_error(['message' => 'Invalid step, or this step requires quiz submission instead.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        self::mark_step_complete($user_id, $lesson_id, $step_index);
        wp_send_json_success(['step_index' => $step_index]);
    }

    public static function ajax_update_watch_progress() {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $percent = (int) ($_POST['percent'] ?? 0);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'video') {
            wp_send_json_error(['message' => 'Invalid video step.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        $auto_completed = self::update_watch_progress($user_id, $lesson_id, $step_index, $percent);
        wp_send_json_success(['step_index' => $step_index, 'auto_completed' => $auto_completed]);
    }

    public static function ajax_submit_quiz() {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);

        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        $step_index = (int) ($_POST['step_index'] ?? -1);
        $step = BHC_Steps::get_step($lesson_id, $step_index);
        if (!$step || $step['type'] !== 'quiz') {
            wp_send_json_error(['message' => 'Invalid quiz step.'], 400);
        }
        if (!BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }

        $max_attempts = (int) ($step['max_attempts'] ?? 0);
        $existing = self::step_status($user_id, $lesson_id, $step_index);
        $already_passed = $existing && (int) $existing['passed'] === 1;
        $attempts_so_far = $existing ? (int) $existing['attempts'] : 0;

        if (!$already_passed && $max_attempts > 0 && $attempts_so_far >= $max_attempts) {
            wp_send_json_error([
                'message' => "No attempts remaining ($max_attempts allowed).",
                'attempts_used' => $attempts_so_far, 'max_attempts' => $max_attempts,
            ], 403);
        }

        // QA fix: a step that's already passed must never be rescored —
        // the old code only used $already_passed to skip the attempts-
        // exhausted check above, then fell through to score_quiz() and
        // mark_step_complete() regardless, which could overwrite a
        // passing result with a failing one on a resubmit/replayed POST
        // (the front end disables the resubmit UI, but that's not a
        // server-side guarantee). Once passed, stay passed.
        if ($already_passed) {
            // Return the stored snapshot (if this row predates the
            // answers column, stored_answers() is simply null and the
            // front end falls back to the aggregate-only display it
            // already had) so a resubmit against an already-passed quiz
            // — which the disabled UI shouldn't normally trigger, but a
            // replayed POST could — still gets the same rich response a
            // fresh pass would, rather than a degraded one.
            $snapshot = self::stored_answers($user_id, $lesson_id, $step_index);
            wp_send_json_success([
                'score' => (int) $existing['score'], 'passed' => true,
                'total' => count($step['questions'] ?? []), 'correct' => null,
                'questions' => $snapshot['questions'] ?? null,
                'attempts_used' => $attempts_so_far, 'max_attempts' => $max_attempts,
                'attempts_remaining' => $max_attempts > 0 ? max(0, $max_attempts - $attempts_so_far) : null,
                'already_passed' => true,
            ]);
        }

        $raw_answers = (array) ($_POST['answers'] ?? []);
        $answers = [];
        foreach ($raw_answers as $q_index => $choice_index) {
            $answers[(int) $q_index] = (int) $choice_index;
        }

        $result = BHC_Steps::score_quiz($step, $answers);

        // Snapshot per QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 1.3: self-
        // contained (question text/choices/correct_index, not just the
        // chosen index), so a later edit to this quiz can never corrupt
        // what this review shows. score/passed/passing_score duplicated
        // into the blob too, so a review render is one row read, not a
        // join against the step's own current (possibly different) config.
        $answers_json = wp_json_encode([
            'score' => $result['score'],
            'passed' => $result['passed'],
            'passing_score' => (int) ($step['passing_score'] ?? 70),
            'questions' => $result['questions'],
        ]);
        self::mark_step_complete($user_id, $lesson_id, $step_index, $result['score'], $result['passed'], $answers_json);

        $result['attempts_used'] = $attempts_so_far + 1;
        $result['max_attempts'] = $max_attempts;
        $result['attempts_remaining'] = $max_attempts > 0 ? max(0, $max_attempts - $result['attempts_used']) : null;
        wp_send_json_success($result);
    }
}
