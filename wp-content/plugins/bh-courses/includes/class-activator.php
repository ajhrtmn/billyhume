<?php
if (!defined('ABSPATH')) exit;

/**
 * Same hardened migration pattern as bh-streaming/bh-contest: versioned,
 * runs on every load via a cheap early-return (not just on real
 * activation, since a file-replace deploy never fires that hook), only
 * marks itself done if the migration actually succeeded.
 */
class BHC_Activator {
    const DB_VERSION = '1.4'; // 1.1 added attempts (quiz retry limits), bhc_enrollments (drip scheduling), bhc_completions (course-completed hook, deduped). 1.2 added bhc_progress.answers (QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 1) — see that column's own comment below for why it's a self-contained snapshot, not a per-attempt history table. 1.3 added bhc_progress.watched_percent (ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b, real video progress tracking) — see that column's own comment below. 1.4 added bhc_reviews (course reviews/ratings — a real gap the plugin's own audit flagged as explicitly-deferred, no data model at all).

    public static function activate() {
        BHC_PostTypes::register();
        if (self::create_or_update_schema()) {
            update_option('bhc_db_version', self::DB_VERSION);
        }
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhc_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhc_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // One real, queryable-across-users table — same convention as
        // bh-monetization-woo's bhm_wallet/bhm_entitlements: anything
        // that needs "every student's status on this lesson" or "this
        // student's status across the whole course" gets a table, not
        // per-user postmeta. Tracked at STEP granularity (not just
        // per-lesson) so a multistep lesson can show real "3 of 7 steps
        // done" progress and a quiz step can gate the next step on a
        // passing score.
        //
        // step_index is the step's position within the lesson's own
        // steps array at the time it was completed — see class-steps.php.
        // score/passed are only ever set for quiz steps; NULL for a
        // plain text/image step (there's nothing to score).
        $progress = $wpdb->prefix . 'bhc_progress';
        $sql = "CREATE TABLE $progress (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            lesson_id bigint(20) unsigned NOT NULL,
            step_index int(11) NOT NULL,
            completed_at datetime DEFAULT CURRENT_TIMESTAMP,
            score int(11) DEFAULT NULL,
            passed tinyint(1) DEFAULT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            answers longtext DEFAULT NULL,
            watched_percent int(11) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_lesson_step (user_id, lesson_id, step_index)
        ) $charset;";
        // watched_percent: the furthest playback position (as a percent of
        // duration) BHC_Progress::update_watch_progress() has recorded for
        // a video step — NULL for every non-video step, same "real SQL
        // NULL means N/A" convention score/passed/answers already use.
        // Deliberately the FURTHEST position reached, not the last
        // position reported (a student who rewinds to review a section
        // shouldn't have their progress go backward) — see that method's
        // own comment for the MAX-based UPDATE that enforces this.
        // answers: JSON snapshot of the LATEST-WRITTEN attempt only (this
        // row is an upsert, not a history table — see the class docblock
        // above and QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 1.1/1.2 for why
        // that's the deliberate, precedented choice, not a shortcut). NULL
        // for non-quiz steps and for any quiz row written before this
        // column existed. Self-contained: stores the question text, choice
        // list, and correct index AS THEY WERE at submission time, not
        // just the chosen index — bhc/quiz-question blocks are editable
        // content (LMS-AUTHORING-DESIGN-PLAN.md), so replaying a chosen
        // index against the CURRENT quiz could point at a choice that's
        // since been reworded or removed. A later quiz edit intentionally
        // does not change what an old review shows — that's correct
        // behavior, not staleness, and is worth remembering if it ever
        // looks like a bug. See BHC_Steps::score_quiz()'s 'questions'
        // return shape for the exact per-record fields.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // One row the first time a user gets real access to a course —
        // NOT the same as a purchase/entitlement (bh-monetization-woo's
        // own job) or a wallet debit; this is purely "when did this
        // person's clock start for THIS course," which drip scheduling
        // (class-gate.php) needs and nothing else in this ecosystem
        // already tracks. A course a student never actually opens never
        // gets a row — same "record it when it actually happens, not
        // speculatively" approach bhm_play_log uses for plays.
        $enroll = $wpdb->prefix . 'bhc_enrollments';
        $sql2 = "CREATE TABLE $enroll (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            enrolled_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_course (user_id, course_id)
        ) $charset;";
        dbDelta($sql2);

        // One row the first time a user hits 100% on a course — the
        // dedup mechanism for bhc_course_completed (see class-progress.php):
        // the action should fire exactly once per student per course, not
        // every time a re-check happens to land on 100% again (e.g. a
        // step gets re-marked-complete after being reset). The INSERT's
        // own UNIQUE KEY is what actually enforces "only once," not an
        // application-level check — same atomic-write-decides approach
        // BHM_Wallet::debit() uses instead of a read-then-write race.
        $completions = $wpdb->prefix . 'bhc_completions';
        $sql3 = "CREATE TABLE $completions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            completed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_course (user_id, course_id)
        ) $charset;";
        dbDelta($sql3);

        // Reviews — a real table, not a CPT: a review is small, fixed-
        // shape structured data (one rating + one body per user per
        // course), the exact same "queryable-across-users, not per-user
        // postmeta" reasoning bhc_progress/bhc_enrollments/bhc_completions
        // above already established, not a second content type needing
        // its own admin list-table/editor chrome for what's really one
        // row. One review per user per course (UNIQUE KEY) — resubmitting
        // an edited review UPDATEs the same row rather than creating a
        // second one, and resets status back to 'pending' (see
        // class-reviews.php) so an edited review is re-moderated, not
        // grandfathered in on its original approval.
        //
        // status: real moderation gate — a review is
        // never publicly visible until an admin approves it, same
        // "held for review" posture WordPress core comments already use
        // by default, not a bespoke concept. completed_at_review is a
        // SNAPSHOT (not computed live from bhc_completions at render
        // time) of whether the reviewer had actually finished the course
        // AT THE MOMENT they wrote the review — deliberately captured
        // once, not recalculated later, so a review honestly reflects
        // "I'd completed it when I said this," and doesn't retroactively
        // gain or lose that badge if their completion record is ever
        // reset/edited after the fact.
        $reviews = $wpdb->prefix . 'bhc_reviews';
        $sql4 = "CREATE TABLE $reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            rating tinyint(1) unsigned NOT NULL,
            body text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            completed_at_review tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_course (user_id, course_id),
            KEY course_status (course_id, status)
        ) $charset;";
        dbDelta($sql4);

        return true;
    }
}
