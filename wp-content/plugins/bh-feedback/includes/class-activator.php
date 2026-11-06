<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned, idempotent migrations. Requests are a CPT; completed reviews
 * and annotations are relational, so they get their own tables.
 */
class BHF_Activator {
    const DB_VERSION = '1.1';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhf_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhf_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhf_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One row per completed review; UNIQUE on request_id. tier is
        // denormalized so reviewer history survives a deleted request post.
        $reviews = BHF_Tables::reviews();
        $sql = "CREATE TABLE $reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            reviewer_user_id bigint(20) unsigned NOT NULL,
            tier varchar(20) NOT NULL DEFAULT 'quick_take',
            body longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY request_id (request_id),
            KEY reviewer_user_id (reviewer_user_id)
        ) $charset;";
        dbDelta($sql);
        if ($wpdb->last_error) return false;

        // Waveform annotations. A top-level marker (parent_id 0) has a
        // timestamp; replies leave it NULL and inherit the parent's. No real FKs.
        $annotations = BHF_Tables::annotations();
        $sql2 = "CREATE TABLE $annotations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL,
            timestamp_seconds decimal(10,3) DEFAULT NULL,
            body text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY request_id (request_id),
            KEY parent_id (parent_id)
        ) $charset;";
        dbDelta($sql2);

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $reviews)) === $reviews
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $annotations)) === $annotations;
    }
}
