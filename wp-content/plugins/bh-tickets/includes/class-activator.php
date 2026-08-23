<?php
if (!defined('ABSPATH')) exit;

/**
 * Two tables: a ticket is its own lifecycle (open/pending/resolved/
 * closed, optional assignment), a reply is a flat append-only thread —
 * same "a table when the shape doesn't fit post/meta" convention
 * bh-crm's own bhcrm_notes/bhcrm_projects already established, not a
 * custom post type (a ticket has no reason to appear in WP's own
 * post-list/search machinery).
 */
class BHT_Activator {
    const DB_VERSION = '1.0';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bht_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bht_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bht_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tickets = BHT_Tables::tickets();
        $replies = BHT_Tables::replies();

        dbDelta("CREATE TABLE $tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            subject varchar(190) NOT NULL,
            category varchar(60) NOT NULL DEFAULT '',
            priority varchar(20) NOT NULL DEFAULT 'normal',
            status varchar(20) NOT NULL DEFAULT 'open',
            assigned_to bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_lookup (user_id),
            KEY status_lookup (status),
            KEY assigned_lookup (assigned_to)
        ) $charset;");

        dbDelta("CREATE TABLE $replies (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            is_staff tinyint(1) NOT NULL DEFAULT 0,
            body longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ticket_lookup (ticket_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tickets)) === $tickets
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $replies)) === $replies;
    }
}
