<?php
if (!defined('ABSPATH')) exit;

/**
 * Same versioned, idempotent migration pattern as every other plugin in
 * this ecosystem. Only one custom table: the request itself is a real
 * CPT (bh_feedback_request — see class-post-types.php), matching
 * bh-contest's own bh_submission precedent for "something a fan
 * submits, an admin/reviewer works." A completed REVIEW is genuinely
 * relational data (queried by reviewer across many requests, by
 * request for its own review) in a way postmeta would make awkward, so
 * it gets its own flat table instead.
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

        // One row per COMPLETED review — request_id is the
        // bh_feedback_request post ID. UNIQUE on request_id: a request
        // gets exactly one review, ever (no revision history in v1 —
        // matches the roadmap's own v1 scope). tier is denormalized onto
        // the review row (not just read from the request post) so a
        // reviewer's own history/stats stay queryable even if a request
        // post is later deleted.
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

        // Timestamped waveform annotations (DB_VERSION 1.1) — 'detailed'
        // tier only (see class-annotations.php's own docblock for why
        // this stayed a feature of the existing tier rather than a new
        // priced one). A top-level marker (parent_id 0) carries its own
        // timestamp_seconds; a reply (parent_id > 0) inherits the
        // parent's timestamp for display and leaves this column NULL —
        // no reason to duplicate it, threads are always read via the
        // parent. request_id/parent_id are NOT foreign keys to a CPT or
        // to this same table via a real FK constraint — same posture
        // bh-crm's tags table and every other lightweight relational
        // table in this ecosystem already takes, since WordPress core's
        // own tables (wp_posts) aren't InnoDB-FK-friendly across a
        // dbDelta-managed plugin table anyway.
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
