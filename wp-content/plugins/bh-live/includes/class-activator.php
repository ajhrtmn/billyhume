<?php
if (!defined('ABSPATH')) exit;

/**
 * Same versioned, idempotent migration pattern as every other plugin
 * in this ecosystem. First real table this plugin has needed: chat
 * messages for BHL_PollingChat (bhl_stream itself is a plain CPT, no
 * custom table of its own).
 */
class BHL_Activator {
    const DB_VERSION = '1.0';

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhl_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhl_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhl_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One row per chat message, scoped to the bhl_stream post it
        // belongs to — messages from a past broadcast stay attached to
        // that stream's own history rather than one global firehose.
        $messages = $wpdb->prefix . 'bhl_chat_messages';
        $sql = "CREATE TABLE $messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stream_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            display_name varchar(60) NOT NULL,
            message varchar(300) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY stream_id (stream_id)
        ) $charset;";
        dbDelta($sql);

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages)) === $messages;
    }
}
