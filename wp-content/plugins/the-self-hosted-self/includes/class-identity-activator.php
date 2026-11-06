<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned schema migrations. WHY: runs on every load behind a cheap version check, since a
 * file-replace deploy never fires the activation hook; marks done only on success.
 */
class BHI_Activator {
    // Per-version table/column history lives in CHANGELOG.md.
    const DB_VERSION = '1.16';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhi_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhi_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhi_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = OUS_Tables::profiles();

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
            links longtext,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id),
            UNIQUE KEY profile_slug (profile_slug)
        ) $charset;";
        // links: JSON array of {label, url}; dbDelta() adds new columns from the CREATE TABLE.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Shared reports/moderation queue (class-reports.php). target_type/target_id are deliberately
        // loose: this table only records who reported what and the admin decision.
        $reports = OUS_Tables::reports();
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

        // Shared notification inbox (class-notifications.php), core-owned so any plugin can notify().
        $notifications = OUS_Tables::notifications();
        $sql3 = "CREATE TABLE $notifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(60) NOT NULL DEFAULT 'general',
            source varchar(60) NOT NULL DEFAULT '',
            title varchar(190) NOT NULL DEFAULT '',
            body text,
            url varchar(500) NOT NULL DEFAULT '',
            read_at datetime DEFAULT NULL,
            email_sent tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_unread (user_id, read_at)
        ) $charset;";
        dbDelta($sql3);
        // WHY: email_sent is an idempotency claim — send_queued_email() claims via
        // UPDATE ... WHERE email_sent = 0 so a job firing twice only mails once.

        // Shared async job queue (class-jobs.php): WP-Cron driven worker, no external broker.
        $jobs = OUS_Tables::jobs();
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

        // Aggregate console/error log (class-debug-log.php), with structured-trace columns.
        $debug_log = OUS_Tables::debug_log();
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

        // BH_Content's non-post storage: plain JSON, one row per (context_type, context_id).
        $content = OUS_Tables::content();
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

        // Per-event envelope (class-event.php). WHY: dedup_key is UNIQUE and MySQL collides on ''
        // (not NULL), so BH_Event writes either a real key or NULL, never ''.
        $events = OUS_Tables::events();
        $sql7 = "CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(100) NOT NULL,
            v smallint(5) unsigned NOT NULL DEFAULT 1,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            client_id varchar(64) NOT NULL DEFAULT '',
            subject_type varchar(60) NOT NULL DEFAULT '',
            subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext,
            context text,
            occurred_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            dedup_key varchar(191) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedup (dedup_key),
            KEY user (user_id),
            KEY client (client_id),
            KEY type_time (type, occurred_at),
            KEY subject (subject_type, subject_id)
        ) $charset;";
        dbDelta($sql7);

        // BH_Element placements. Deliberately no unique constraint: one element type may appear
        // several times per slot. parent_placement_id 0 = top level, else the parent within the same
        // surface/context/slot (validated, cycle-guarded in save_placement()). revision_of is unused.
        $element_placements = OUS_Tables::element_placements();
        $sql8 = "CREATE TABLE $element_placements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            surface varchar(60) NOT NULL,
            surface_context_id bigint(20) unsigned NOT NULL DEFAULT 0,
            slot varchar(60) NOT NULL,
            position int(10) unsigned NOT NULL DEFAULT 0,
            element_type varchar(100) NOT NULL,
            config longtext,
            content_context_id bigint(20) unsigned NOT NULL DEFAULT 0,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            parent_placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
            revision_of bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY place (surface, surface_context_id, slot, position)
        ) $charset;";
        dbDelta($sql8);

        // Campaigns (class-campaigns.php): stores what was sent and to how many; the recipient list
        // is a live query at send time, never stored.
        $campaigns = OUS_Tables::campaigns();
        $sql11 = "CREATE TABLE $campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject varchar(190) NOT NULL DEFAULT '',
            body longtext,
            segment_key varchar(60) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'draft',
            total_recipients int(10) unsigned NOT NULL DEFAULT 0,
            sent_count int(10) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset;";
        dbDelta($sql11);

        // DMCA notice intake/tracking (class-dmca-notices.php), admin-entered. Its own table because
        // claimant identity, attestation, and counter-notice deadline need real columns.
        $dmca_notices = OUS_Tables::dmca_notices();
        $sql12 = "CREATE TABLE $dmca_notices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            claimant_name varchar(190) NOT NULL DEFAULT '',
            claimant_email varchar(190) NOT NULL DEFAULT '',
            claimant_address text,
            target_type varchar(40) NOT NULL DEFAULT '',
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_url text,
            work_description text,
            infringing_description text,
            sworn_statement tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'received',
            counter_notice_deadline datetime DEFAULT NULL,
            counter_notice_received_at datetime DEFAULT NULL,
            admin_note text,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY target (target_type, target_id),
            KEY status (status)
        ) $charset;";
        dbDelta($sql12);

        if ($wpdb->last_error) return false;
        foreach ([$table, $reports, $notifications, $jobs, $debug_log, $content, $events, $element_placements, $campaigns, $dmca_notices] as $name) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) return false;
        }
        return true;
    }
}
