<?php
if (!defined('ABSPATH')) exit;

class BH_Activator {
    const DB_VERSION = '1.3';

    public static function activate() {
        self::create_or_update_schema();
        update_option('bh_db_version', self::DB_VERSION);
        flush_rewrite_rules();
    }

    // Runs on every page load (cheap early-return via version check) rather
    // than only on activation — AJ's workflow is replacing plugin files
    // directly over FTP without deactivating/reactivating, so a schema
    // change can't rely on the activation hook firing again.
    public static function maybe_upgrade() {
        if (version_compare(get_option('bh_db_version', '0'), self::DB_VERSION, '>=')) return;
        self::create_or_update_schema();
        update_option('bh_db_version', self::DB_VERSION);
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $table   = $wpdb->prefix . 'bh_votes';
        $charset = $wpdb->get_charset_collate();

        // `category` defaults to '' — every vote cast before this column
        // existed automatically becomes a vote in the implicit "general"
        // category once contests start defining named categories. No data
        // loss, no migration script needed for existing votes.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            submission_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id),
            KEY contest_idx (contest_id),
            KEY user_contest_idx (user_id, contest_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql); // reliably adds the missing `category` column on upgrade

        // dbDelta doesn't reliably rewrite an existing UNIQUE KEY's column
        // list, so the old 3-column key (if present from before category
        // support) is dropped and replaced explicitly.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_user_track' AND COLUMN_NAME = 'submission_id' AND SEQ_IN_INDEX = 3",
            DB_NAME, $table
        ));
        if ($exists) {
            $wpdb->query("ALTER TABLE $table DROP INDEX uniq_user_track");
            $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id)");
        }

        self::create_or_update_profiles_table($charset);
    }

    // One row per wp_users.ID — real name, platform handles, and a
    // per-field "OK to share publicly" consent flag. See BH_Profiles for
    // how this is read and written; this table is never queried directly
    // outside that class.
    private static function create_or_update_profiles_table($charset) {
        global $wpdb;
        $table = $wpdb->prefix . 'bh_participant_profiles';

        $sql = "CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            real_name varchar(190) NOT NULL DEFAULT '',
            discord_name varchar(190) NOT NULL DEFAULT '',
            twitch_name varchar(190) NOT NULL DEFAULT '',
            youtube_name varchar(190) NOT NULL DEFAULT '',
            typical_platform varchar(20) NOT NULL DEFAULT '',
            real_name_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            discord_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            twitch_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            youtube_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
