<?php
if (!defined('ABSPATH')) exit;

/**
 * Same versioned, idempotent migration pattern as every other plugin
 * in this ecosystem. First real table this plugin has needed: chat
 * messages for BHL_PollingChat (bhl_stream itself is a plain CPT, no
 * custom table of its own).
 */
class BHL_Activator {
    const DB_VERSION = '1.2';

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
        // `source` (1.2, ROADMAP-obs-integration.md Phase 3) — 'ecosystem'
        // for every message BHL_PollingChat itself already inserted before
        // this column existed (dbDelta's own default backfills those rows),
        // 'twitch'/'youtube' for messages BHL_PollingChat::relay_message()
        // inserts on the automation bridge's behalf.
        $messages = $wpdb->prefix . 'bhl_chat_messages';
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

        // 1.1 — ROADMAP-obs-integration.md Phase 2. A small log of
        // synchronous, non-BH_Event triggers the automation bridge
        // needs to react to (bh_contest_round_advanced today — BH_Event
        // itself is async via OUS_Jobs, too slow for a live scene-switch
        // trigger, so this exists specifically for the ones that can't
        // wait on that queue). BH_Event-sourced triggers (bhs/play,
        // bh/vote, etc.) are read directly from wp_bhcore_events instead
        // of being duplicated in here.
        $log = $wpdb->prefix . 'bhl_automation_log';
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
