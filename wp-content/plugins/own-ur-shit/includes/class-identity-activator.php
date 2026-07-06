<?php
if (!defined('ABSPATH')) exit;

/**
 * Same hardened migration pattern used everywhere else in this
 * ecosystem: versioned, runs on every load via a cheap early-return
 * (not just on activation — a file-replace deploy never fires
 * WordPress's real activation hook), and only marks itself done if the
 * migration actually succeeded.
 */
class BHI_Activator {
    const DB_VERSION = '1.0';

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhi_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhi_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhi_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'bhi_profiles';

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
        dbDelta($sql);

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists === $table;
    }
}
