<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned schema migration that runs on every load with a cheap early return (a
 * file-replace deploy never fires the activation hook); only marks itself done on success.
 */
class BHC_Activator {
    const DB_VERSION = '1.6';

    public static function activate(): void {
        BHC_PostTypes::register();
        if (self::create_or_update_schema()) {
            update_option('bhc_db_version', self::DB_VERSION);
        }
        self::ensure_catalog_page();
        flush_rewrite_rules();
    }

    /**
     * Ensures the catalog page exists (adopting a hand-made one rather than duplicating).
     * WHY: must not run on plugins_loaded; creating a page before $wp_rewrite exists fatals.
     * admin_init is late enough.
     */
    public static function ensure_catalog_page(): void {
        // WHY: method_exists, not class_exists: a peer can deploy ahead of core with an
        // older OUS_Pages that lacks ensure().
        if (!method_exists('OUS_Pages', 'ensure')) return;
        // bhc/catalog (the block form) so block-authored pages are found, not duplicated.
        OUS_Pages::ensure('bh_courses', 'bhc_catalog_page_id', __('Courses', 'bh-courses'), ['bhc/catalog']);
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhc_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhc_db_version', self::DB_VERSION);
        }
    }

    // One-time rewrite flush after has_archive was removed from bh_course, so the old
    // /courses/ archive rule stops shadowing the catalog Page. Option-gated, admin_init.
    public static function maybe_flush_after_archive_removal(): void {
        if (get_option('bhc_flushed_archive_removal') === '1') return;
        flush_rewrite_rules(false);
        update_option('bhc_flushed_archive_removal', '1');
    }

    // One-time backfill of lesson-side _bhc_course_id from the course-side order when
    // exactly one course contains the lesson. Option-gated, admin_init.
    public static function maybe_backfill_lesson_course_ids(): void {
        if (get_option('bhc_backfilled_lesson_course_ids') === '1') return;
        $courses = get_posts(['post_type' => 'bh_course', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        $orders = [];
        foreach ($courses as $cid) {
            $orders[(int) $cid] = BHC_PostTypes::lesson_order((int) $cid);
        }
        $lessons = get_posts(['post_type' => 'bh_lesson', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($lessons as $lid) {
            if ((int) get_post_meta($lid, '_bhc_course_id', true)) continue;
            $owners = [];
            foreach ($orders as $cid => $order) {
                if (in_array((int) $lid, $order, true)) $owners[] = $cid;
            }
            if (count($owners) === 1) update_post_meta($lid, '_bhc_course_id', $owners[0]);
        }
        update_option('bhc_backfilled_lesson_course_ids', '1');
    }

    // Content repair, versioned separately from the schema. Rewrites lessons saved before
    // bhc/text and bhc/quiz's save() gained a wrapper div, which Gutenberg flags as invalid;
    // its "Attempt recovery" would drop the quiz's child questions.
    const CONTENT_MIGRATION_VERSION = '1';

    public static function maybe_migrate_content(): void {
        if (version_compare(get_option('bhc_content_migration_version', '0'), self::CONTENT_MIGRATION_VERSION, '>=')) return;
        if (self::fix_stale_block_markup()) {
            update_option('bhc_content_migration_version', self::CONTENT_MIGRATION_VERSION);
        }
    }

    private static function fix_stale_block_markup(): bool {
        $lessons = get_posts([
            'post_type' => 'bh_lesson',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        foreach ($lessons as $lesson_id) {
            $post = get_post($lesson_id);
            if (!$post) continue;
            $content = $post->post_content;
            $fixed = self::fix_stale_text_blocks($content);
            $fixed = self::fix_stale_quiz_blocks($fixed);
            if ($fixed !== $content) {
                wp_update_post(['ID' => $lesson_id, 'post_content' => $fixed]);
            }
        }
        return true;
    }

    // Re-derives the missing wrapper div for a self-closed bhc/text block from its attribute.
    private static function fix_stale_text_blocks(string $content): string {
        $pattern = '/<!--\s*wp:bhc\/text\s*(\{[^}]*\})\s*\/-->/';
        return (string) preg_replace_callback($pattern, function ($m) {
            $attrs = json_decode($m[1], true);
            if (!is_array($attrs)) return $m[0];
            $text_content = $attrs['content'] ?? '';
            $attrs_json = wp_json_encode($attrs);
            return '<!-- wp:bhc/text ' . $attrs_json . ' --><div class="wp-block-bhc-text">' . $text_content . '</div><!-- /wp:bhc/text -->';
        }, $content);
    }

    // Wraps a bhc/quiz's existing inner content in the div, never touching the child questions.
    private static function fix_stale_quiz_blocks(string $content): string {
        $pattern = '/(<!-- wp:bhc\/quiz \{[^}]*\} -->)([\s\S]*?)(<!-- \/wp:bhc\/quiz -->)/';
        return (string) preg_replace_callback($pattern, function ($m) {
            if (strpos(ltrim($m[2]), '<div class="wp-block-bhc-quiz"') === 0) return $m[0]; // already correct
            return $m[1] . '<div class="wp-block-bhc-quiz">' . $m[2] . '</div>' . $m[3];
        }, $content);
    }

    // Drops the old 3-column unique key so dbDelta can create the 4-column one. Checked via
    // information_schema, so it's safe on every activation.
    private static function maybe_widen_progress_unique_key(): void {
        global $wpdb;
        $table = BHC_Tables::progress();
        $table_name = str_replace('`', '', $table);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table_name
        ));
        if (!$exists) return; // fresh install — dbDelta below creates the correct shape directly

        $cols = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = 'user_lesson_step' ORDER BY seq_in_index",
            DB_NAME, $table_name
        ));
        if ($cols === ['user_id', 'lesson_id', 'step_index', 'sub_index']) return; // already widened
        if (empty($cols)) return; // key doesn't exist yet for some other reason — let dbDelta create it fresh

        $wpdb->query("ALTER TABLE $table DROP INDEX user_lesson_step");
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // WHY: dbDelta() can't widen an existing UNIQUE KEY (it tries to add a duplicate name),
        // so drop the old 3-column shape first.
        self::maybe_widen_progress_unique_key();

        // Step-granularity progress, one table across users. step_index is the step's position
        // in the lesson's steps array; score/passed are NULL for non-quiz steps.
        $progress = BHC_Tables::progress();
        $sql = "CREATE TABLE $progress (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            lesson_id bigint(20) unsigned NOT NULL,
            step_index int(11) NOT NULL,
            sub_index int(11) NOT NULL DEFAULT 0,
            completed_at datetime DEFAULT CURRENT_TIMESTAMP,
            score int(11) DEFAULT NULL,
            passed tinyint(1) DEFAULT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            answers longtext DEFAULT NULL,
            watched_percent int(11) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_lesson_step (user_id, lesson_id, step_index, sub_index)
        ) $charset;";
        // sub_index: 0 is the step's own row; >0 is one in-video annotation's completion.
        // watched_percent: the furthest position reached (never regresses); NULL for non-video.
        // answers: JSON snapshot of the latest attempt, self-contained (question text, choices,
        // correct index as submitted), so later quiz edits don't change an old review.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // One row when a user first gets real access to a course: the drip clock start.
        $enroll = BHC_Tables::enrollments();
        $sql2 = "CREATE TABLE $enroll (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            enrolled_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_course (user_id, course_id)
        ) $charset;";
        dbDelta($sql2);

        // One row when a user first hits 100%: the UNIQUE KEY makes bhc_course_completed fire once.
        $completions = BHC_Tables::completions();
        $sql3 = "CREATE TABLE $completions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            completed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_course (user_id, course_id)
        ) $charset;";
        dbDelta($sql3);

        // One review per user per course (UNIQUE KEY); resubmitting updates the row and resets
        // status to 'pending'. completed_at_review is a snapshot taken at submission time.
        $reviews = BHC_Tables::reviews();
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

        // WHY: course_id uses 0, not NULL, for global achievements; NULLs are distinct in a
        // unique index and would defeat the once-only guarantee.
        $achievements = BHC_Tables::achievements();
        $sql5 = "CREATE TABLE $achievements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            achievement_key varchar(40) NOT NULL,
            course_id bigint(20) unsigned NOT NULL DEFAULT 0,
            earned_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_achievement_course (user_id, achievement_key, course_id),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta($sql5);

        return true;
    }
}
