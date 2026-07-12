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
    const DB_VERSION = '1.10'; // 1.2 added bhi_reports — see class-reports.php; 1.3 added bhcore_notifications + bhcore_jobs — see class-notifications.php / class-jobs.php; 1.4 added bhcore_debug_log — see class-debug-log.php; 1.5 added bhcore_content — see class-content.php; 1.6 added bhcore_debug_log's structured-trace columns (file/line/col/trace/url/user_id/request_method) — see class-debug-log.php v2; 1.7 added bhcore_debug_log.request_id — per-request correlation ID so scattered log entries from one failing request can be traced together, see class-debug-log.php's request_id()/has_request_id_column(); 1.8 added bhcore_events — see class-event.php (BH_Event), the event-tracking envelope table per EVENT-TRACKING-ARCHITECTURE-PLAN.md, first implemented this pass; 1.9 added bhcore_element_placements — see class-element.php (BH_Element), the placement storage table per ELEMENT-BUILDER-DESIGN-PLAN.md Section 2.1, Phase 1/2 of that doc's build order; 1.10 added bhcore_element_prefabs — see class-element-prefab.php (BH_Element_Prefab), the prefab (named reusable placement composition) storage table added per AJ's mid-build request on top of the element-builder design doc's remaining phases (§6) — a saved, deep-copyable composition of one or more placements, distinct from a single placement row

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

        // Versioned, namespaced per-event envelope — see class-event.php
        // (BH_Event) and EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 2.
        // dedup_key is UNIQUE but MySQL treats multiple '' values as
        // colliding under a UNIQUE index (unlike NULL) — class-event.php
        // always writes either a real deterministic string or NULL,
        // never '', so non-deduplicated events (plays, votes) don't
        // collide with each other under this index.
        $events = $wpdb->prefix . 'bhcore_events';
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

        // Element-builder placements — see class-element.php (BH_Element)
        // and ELEMENT-BUILDER-DESIGN-PLAN.md Section 2.1. "This element
        // type, configured this way, sits in this slot on this surface
        // for this context, at this position." Deliberately NO unique
        // constraint on (surface, context, slot, element_type) — the
        // same element type can legitimately appear more than once in
        // one slot (two stat-cards bound to different metrics), per the
        // design doc's own note on this. NO COLUMN CHANGE THIS PASS —
        // both columns below already existed in this table's original
        // 1.9 definition; OUS_VER 3.4.34 just ACTIVATED parent_placement_id
        // (see class-element.php's save_placement()/render_slot()/
        // render_placement() and the REST save route) as the real
        // placement-tree seam it was reserved as (§1.1) — 0 still means
        // "root/top-level within the slot," non-zero now means "this
        // row's parent within the same surface/context/slot," validated
        // and cycle-guarded in save_placement(). revision_of remains an
        // unused seam for future work (§2.3's version-history service).
        $element_placements = $wpdb->prefix . 'bhcore_element_placements';
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

        // Element-builder PREFABS — see class-element-prefab.php
        // (BH_Element_Prefab). A prefab is a NAMED, reusable, saved
        // composition of one or more placement definitions (potentially
        // including a container's nested BH_Content tree, carried inside
        // 'definition' as a JSON array of placement-shaped entries plus
        // an embedded 'content_tree' per container entry — see that
        // class's instantiate()/save_from_slot() for the exact shape).
        // Deliberately a SEPARATE table from bhcore_element_placements,
        // not a special 'is_prefab' flag on that table — a prefab is
        // definitional (code-adjacent authored data, edited independently
        // of any live placement) while a placement is instance state tied
        // to one surface/slot/context; conflating them would mean
        // "editing a prefab" and "editing a live instance" fight over the
        // same rows, which is exactly the retroactive-mutation bug the
        // deep-copy instantiate contract is designed to avoid.
        $prefabs = $wpdb->prefix . 'bhcore_element_prefabs';
        $sql9 = "CREATE TABLE $prefabs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(80) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            description text,
            definition longtext,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset;";
        dbDelta($sql9);

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $reports_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $reports));
        $notif_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $notifications));
        $jobs_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs));
        $log_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $debug_log));
        $content_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $content));
        $events_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events));
        $element_placements_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $element_placements));
        $prefabs_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefabs));
        return $exists === $table && $reports_exists === $reports && $notif_exists === $notifications && $jobs_exists === $jobs && $log_exists === $debug_log && $content_exists === $content && $events_exists === $events && $element_placements_exists === $element_placements && $prefabs_exists === $prefabs;
    }
}
