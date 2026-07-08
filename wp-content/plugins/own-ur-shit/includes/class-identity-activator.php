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
    const DB_VERSION = '1.7'; // 1.2 added bhi_reports — see class-reports.php; 1.3 added bhcore_notifications + bhcore_jobs — see class-notifications.php / class-jobs.php; 1.4 added bhcore_debug_log — see class-debug-log.php; 1.5 added bhcore_content — see class-content.php; 1.6 added bhcore_debug_log's structured-trace columns (file/line/col/trace/url/user_id/request_method) — see class-debug-log.php v2; 1.7 added bhcore_debug_log.request_id — per-request correlation ID so scattered log entries from one failing request can be traced together, see class-debug-log.php's request_id()/has_request_id_column()

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
            avatar_id bigint(20) unsigned NOT NULL DEFAULT 0,
            banner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            bio text,
            profile_slug varchar(60) DEFAULT NULL,
            profile_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id),
            UNIQUE KEY profile_slug (profile_slug)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Generic reports/moderation queue — one shared table any
        // plugin's "Report" button writes into (see class-reports.php),
        // rather than every plugin building its own moderation UI and
        // storage. target_type/target_id is deliberately loose (a post
        // ID, a user ID, whatever the reporting plugin's own concept of
        // "the thing being reported" is) — this table doesn't need to
        // understand what it's storing reports about, only who reported
        // what and what an admin decided to do about it.
        $reports = $wpdb->prefix . 'bhi_reports';
        $sql2 = "CREATE TABLE $reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reporter_user_id bigint(20) unsigned NOT NULL,
            target_type varchar(40) NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            category varchar(30) NOT NULL DEFAULT 'other',
            reason text,
            status varchar(20) NOT NULL DEFAULT 'open',
            admin_note text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY target (target_type, target_id),
            KEY status (status)
        ) $charset;";
        dbDelta($sql2);

        // Shared notification inbox — see class-notifications.php.
        // Deliberately core (not any one feature plugin's table) for the
        // same reason bhi_reports is core: any plugin should be able to
        // call OUS_Notifications::notify() the moment it depends on this
        // plugin at all, with zero registration step and zero awareness
        // of any other plugin that might also be sending notifications.
        $notifications = $wpdb->prefix . 'bhcore_notifications';
        $sql3 = "CREATE TABLE $notifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(60) NOT NULL DEFAULT 'general',
            source varchar(60) NOT NULL DEFAULT '',
            title varchar(190) NOT NULL DEFAULT '',
            body text,
            url varchar(500) NOT NULL DEFAULT '',
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_unread (user_id, read_at)
        ) $charset;";
        dbDelta($sql3);

        // Shared async job queue — see class-jobs.php. A WP-Cron-driven
        // worker, not a real message broker (this ecosystem has no
        // server infra beyond plain WordPress/MySQL to assume), but the
        // same "any plugin can enqueue, zero registration with a
        // central authority, zero awareness of who else uses it" shape.
        $jobs = $wpdb->prefix . 'bhcore_jobs';
        $sql4 = "CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hook varchar(100) NOT NULL,
            args longtext,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            run_after datetime DEFAULT CURRENT_TIMESTAMP,
            last_error text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status_run_after (status, run_after)
        ) $charset;";
        dbDelta($sql4);

        // Aggregate console/error log — see class-debug-log.php. Same
        // "core owns it, any plugin logs into it with one call" shape as
        // notifications/jobs above.
        // v1.6 added the structured-trace columns below (file/line/col/
        // trace/url/user_id/request_method) so a row carries a real,
        // filterable stack trace down to file/line/column instead of
        // whatever happened to be stuffed into the free-text 'context'
        // column — see class-debug-log.php v2. dbDelta() handles adding
        // these to an existing table on upgrade the same way it handles
        // fresh installs; no separate ALTER TABLE needed.
        $debug_log = $wpdb->prefix . 'bhcore_debug_log';
        $sql5 = "CREATE TABLE $debug_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            source varchar(60) NOT NULL DEFAULT '',
            message text,
            context text,
            file varchar(500) NOT NULL DEFAULT '',
            line int(11) NOT NULL DEFAULT 0,
            col int(11) NOT NULL DEFAULT 0,
            trace longtext,
            url varchar(500) NOT NULL DEFAULT '',
            request_method varchar(10) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id varchar(20) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY source (source),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY request_id (request_id)
        ) $charset;";
        dbDelta($sql5);

        // BH_Content's non-post storage backend — see class-content.php.
        // A document attached to a real WP post lives in that post's own
        // post_content (Gutenberg's existing block format); anything
        // else (a lesson step tree, a tier's benefit list) lives here as
        // plain JSON, one row per (context_type, context_id) pair.
        $content = $wpdb->prefix . 'bhcore_content';
        $sql6 = "CREATE TABLE $content (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(60) NOT NULL,
            context_id bigint(20) unsigned NOT NULL,
            blocks longtext,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY context (context_type, context_id)
        ) $charset;";
        dbDelta($sql6);

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $reports_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $reports));
        $notif_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $notifications));
        $jobs_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs));
        $log_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $debug_log));
        $content_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $content));
        return $exists === $table && $reports_exists === $reports && $notif_exists === $notifications && $jobs_exists === $jobs && $log_exists === $debug_log && $content_exists === $content;
    }
}
