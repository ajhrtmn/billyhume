<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned, idempotent table migrations for bh-live.
 */
class BHL_Activator {
    const DB_VERSION = '1.2';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhl_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhl_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhl_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One row per chat message, scoped to its bhl_stream post. `source` is 'ecosystem'
        // (the default) or 'twitch'/'youtube' for messages relayed by the OBS bridge.
        $messages = BHL_Tables::chat_messages();
        $sql = "CREATE TABLE $messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stream_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            display_name varchar(60) NOT NULL,
            message varchar(300) NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'ecosystem',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY stream_id (stream_id)
        ) $charset;";
        dbDelta($sql);

        // WHY: synchronous triggers (bh_contest_round_advanced) are logged here because BH_Event
        // is async via OUS_Jobs, too slow for a scene switch. BH_Event triggers are read from
        // wp_bhcore_events directly.
        $log = BHL_Tables::automation_log();
        $sql2 = "CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(64) NOT NULL,
            subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
            occurred_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type_occurred (type, occurred_at)
        ) $charset;";
        dbDelta($sql2);

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages)) === $messages
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $log)) === $log;
    }
}
