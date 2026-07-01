<?php
if (!defined('ABSPATH')) exit;

class BH_Activator {
    public static function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . 'bh_votes';
        $charset = $wpdb->get_charset_collate();

        // Indexes added for the per-user lookups and the results GROUP BY.
        // UNIQUE key blocks a user voting the same track twice (defence in
        // depth alongside the app-level toggle + transaction).
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_track (user_id, contest_id, submission_id),
            KEY contest_idx (contest_id),
            KEY user_contest_idx (user_id, contest_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('bh_db_version', '1.1');
        flush_rewrite_rules();
    }
}
