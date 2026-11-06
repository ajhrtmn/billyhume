<?php
if (!defined('ABSPATH')) exit;

class BH_Activator {
    const DB_VERSION = '1.7';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bh_db_version', self::DB_VERSION);
        }
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    // WHY: runs on every load because FTP deploys never re-fire the activation hook.
    // The version is stamped only on real success so a failed migration retries.
    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bh_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bh_db_version', self::DB_VERSION);
        }
    }

    // One-time copy of _bh_style_* meta to the _bhy_style_* keys BHY_Style reads.
    // Has its own done flag; DB_VERSION is for table schema only.
    public static function maybe_migrate_style_meta_keys(): void {
        if (get_option('bh_style_meta_migrated') === '1') return;

        $contests = get_posts(['post_type' => 'bh_contest', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($contests as $cid) {
            if (get_post_meta($cid, '_bhy_style_override', true) !== '') continue; // already migrated (or never had an override)
            $old_on = get_post_meta($cid, '_bh_style_override', true);
            if ($old_on === '') continue; // nothing to migrate for this contest

            update_post_meta($cid, '_bhy_style_override', $old_on);
            $old_json = get_post_meta($cid, '_bh_style_json', true);
            if ($old_json !== '') update_post_meta($cid, '_bhy_style_json', $old_json);
        }

        update_option('bh_style_meta_migrated', '1');
    }

    // Bump when the singleton page list changes; creation stays idempotent.
    const PAGES_VERSION = '3';

    // Creates the site-wide singleton pages. Runs on admin_init (not every
    // request like the schema check) because nothing public depends on it.
    public static function maybe_create_default_pages(): void {
        $pages = [
            ['bh_reveal_page_id', 'Reveal', '[bh_results_reveal]'],
            ['bh_archive_page_id', 'Archive', '[bh_archive]'],
            // Library browses contests by lifecycle; Archive lists every entry.
            ['bh_contest_library_page_id', 'Contests', '[bh_contest_library]'],
        ];

        $version_current = get_option('bh_pages_version') === self::PAGES_VERSION;
        $all_ok = true;
        foreach ($pages as [$opt, $title, $shortcode]) {
            // A page whose option was never set is retried even when the version is current.
            if ($version_current && (int) get_option($opt, 0)) continue;
            $all_ok = self::maybe_create_singleton_page($opt, $title, $shortcode) && $all_ok;
        }

        if ($all_ok) update_option('bh_pages_version', self::PAGES_VERSION);
    }

    // False only if a create failed. A recorded page the owner removed is not recreated.
    private static function maybe_create_singleton_page(string $option_key, string $title, string $shortcode): bool {
        $existing = (int) get_option($option_key, 0);
        if ($existing) {
            $status = get_post_status($existing);
            return $status !== false; // recorded page still a real post (published/draft/trash all fine)
        }

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $shortcode,
        ], true);
        if (is_wp_error($new_id) || !$new_id) return false;

        update_option($option_key, $new_id);
        return true;
    }

    // True only if every step succeeded; $wpdb never throws, so last_error is checked.
    private static function create_or_update_schema(): bool {
        global $wpdb;
        $table   = BHCON_Tables::votes();
        $charset = $wpdb->get_charset_collate();

        // category '' is the implicit single category. ip_address/voter_fp feed only
        // the manual-review fraud signal, never vote counting.
        // PRIVACY: ip_address is personal data with no privacy export/erasure hook yet.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            submission_id bigint(20) unsigned NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            voter_fp varchar(64) NOT NULL DEFAULT '',
            round int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id, round),
            KEY contest_idx (contest_id),
            KEY user_contest_idx (user_id, contest_id),
            KEY ip_idx (ip_address)
        ) $charset;";
        // round is 0 for single-round contests; it's in the UNIQUE KEY so each
        // round's vote for the same submission is an independent row.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // WHY: rebuild a same-named index with different columns BEFORE dbDelta(),
        // which would otherwise try a bare ADD and fail with "Duplicate key name".
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $key_columns = $wpdb->get_col($wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_user_track' ORDER BY SEQ_IN_INDEX",
                DB_NAME, $table
            ));
            $target_columns = ['user_id', 'contest_id', 'category', 'submission_id', 'round'];
            if ($key_columns && $key_columns !== $target_columns) {
                $wpdb->query("ALTER TABLE $table DROP INDEX uniq_user_track");
                if ($wpdb->last_error !== '') return false;
                // Very old installs may lack the columns this key indexes.
                $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
                if (!in_array('category', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN category varchar(64) NOT NULL DEFAULT ''");
                if (!in_array('round', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN round int(11) unsigned NOT NULL DEFAULT 0");
                if ($wpdb->last_error !== '') return false;
                $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id, round)");
                if ($wpdb->last_error !== '') return false;
            }
        }

        $wpdb->last_error = '';
        dbDelta($sql); // adds any still-missing columns (ip_address, voter_fp, etc.) and creates the table outright on a fresh install
        if ($wpdb->last_error !== '') return false;

        // dbDelta can report no error yet leave the table missing on a dropped connection.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;

        if (!self::create_or_update_profiles_table($charset)) return false;
        return self::create_or_update_judge_scores_table($charset);
    }

    // One row per (judge, submission, category, round), rubric scores as a JSON
    // snapshot. Only status 'submitted' rows count in BH_Judging::judge_results().
    private static function create_or_update_judge_scores_table(string $charset): bool {
        global $wpdb;
        $table = BHCON_Tables::judge_scores();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            judge_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            round int(11) unsigned NOT NULL DEFAULT 0,
            scores longtext DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_judge_track (judge_id, contest_id, submission_id, category, round),
            KEY contest_idx (contest_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Index rebuild before dbDelta(), same reason as in create_or_update_schema().
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $key_columns = $wpdb->get_col($wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_judge_track' ORDER BY SEQ_IN_INDEX",
                DB_NAME, $table
            ));
            $target_columns = ['judge_id', 'contest_id', 'submission_id', 'category', 'round'];
            if ($key_columns && $key_columns !== $target_columns) {
                $wpdb->query("ALTER TABLE $table DROP INDEX uniq_judge_track");
                if ($wpdb->last_error !== '') return false;
                $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
                if (!in_array('round', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN round int(11) unsigned NOT NULL DEFAULT 0");
                if ($wpdb->last_error !== '') return false;
                $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_judge_track (judge_id, contest_id, submission_id, category, round)");
                if ($wpdb->last_error !== '') return false;
            }
        }

        $wpdb->last_error = '';
        dbDelta($sql);
        if ($wpdb->last_error !== '') return false;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    // Legacy profiles table, superseded by core's BHI_Profiles and read only by the
    // one-time migration in bh-contest.php. Never created on a fresh install.
    private static function create_or_update_profiles_table(string $charset): bool {
        global $wpdb;
        $table = BHCON_Tables::participant_profiles();

        $already_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$already_exists) return true; // fresh install — nothing to migrate, nothing to create

        $sql = "CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            real_name varchar(190) NOT NULL DEFAULT '',
            discord_name varchar(190) NOT NULL DEFAULT '',
            twitch_name varchar(190) NOT NULL DEFAULT '',
            youtube_name varchar(190) NOT NULL DEFAULT '',
            phone varchar(30) NOT NULL DEFAULT '',
            typical_platform varchar(20) NOT NULL DEFAULT '',
            real_name_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            discord_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            twitch_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            youtube_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->last_error = '';
        dbDelta($sql);
        if ($wpdb->last_error !== '') return false;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
