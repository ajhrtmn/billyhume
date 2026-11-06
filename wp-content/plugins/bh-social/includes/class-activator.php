<?php
if (!defined('ABSPATH')) exit;

/**
 * Creates bhso_platform_stats and bhso_ad_campaigns. OAuth credentials live in
 * one wp_options entry per platform (bhso_{platform}_settings), not these tables.
 */
class BHSO_Activator {
    const DB_VERSION = '1.1';

    public static function activate(): void {
        if (self::create_or_update_schema()) update_option('bhso_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhso_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) update_option('bhso_db_version', self::DB_VERSION);
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $stats = BHSO_Tables::platform_stats();
        $sql = "CREATE TABLE $stats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            platform varchar(20) NOT NULL,
            metric_key varchar(60) NOT NULL,
            metric_value bigint(20) NOT NULL DEFAULT 0,
            recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY platform_metric (platform, metric_key),
            KEY recorded_at (recorded_at)
        ) $charset;";
        dbDelta($sql);
        if ($wpdb->last_error) return false;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats)) !== $stats) return false;

        $campaigns = BHSO_Tables::ad_campaigns();
        $sql2 = "CREATE TABLE $campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            platform varchar(20) NOT NULL,
            name varchar(190) NOT NULL,
            budget_cents bigint(20) unsigned NOT NULL DEFAULT 0,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            targeting_notes text,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY platform (platform),
            KEY status (status)
        ) $charset;";
        dbDelta($sql2);
        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $campaigns)) === $campaigns;
    }
}
