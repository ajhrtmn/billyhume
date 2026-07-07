<?php
if (!defined('ABSPATH')) exit;

/**
 * Same hardened migration pattern proven out in bh-contest: versioned,
 * runs on every load via a cheap early-return (not just on activation,
 * since a file-replace deploy never fires WordPress's real activation
 * hook), and only marks itself done if the migration actually succeeded
 * — never persists "complete" on a half-finished migration from a flaky
 * connection.
 */
class BHS_Activator {
    const DB_VERSION = '1.3'; // 1.2 added bhs_daily_stats; 1.3 renamed the bh_track/bh_release/bh_playlist post types to bhs_track/bhs_release/bhs_playlist — see rename_post_types_to_prefixed()

    public static function activate() {
        BHS_PostTypes::register();
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
        self::rename_post_types_to_prefixed();
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhs_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
        self::rename_post_types_to_prefixed();
        // Rewrite rules (post_type slugs changed) need regenerating once
        // — flush_rewrite_rules() is a real, if small, cost (rebuilds
        // and re-saves the whole rewrite table), so this only ever runs
        // as part of the same version-gated migration, never on every
        // ordinary page load.
        flush_rewrite_rules();
    }

    // One-time data migration. Own-ur-shit's whole ecosystem naming
    // convention prefixes every plugin's own post types/tables/classes
    // with that plugin's own short code — bhc_, bhm_, bhr_, bhi_ — but
    // these three post types shipped as bare bh_track/bh_release/
    // bh_playlist instead of bhs_-prefixed, inconsistent with this
    // plugin's OWN bhs_feed_source and every other plugin's convention.
    // Fixed at the code level (register_post_type() calls, all internal
    // references) AND here at the data level: any row already stored
    // under the old post_type on a site that upgrades from before this
    // version gets renamed in place, so existing tracks/releases/
    // playlists don't silently vanish from admin list-tables (which
    // filter strictly by post_type) after updating this plugin's files.
    // Idempotent and safe to run repeatedly — an UPDATE ... WHERE
    // post_type = 'old' matching zero rows (already migrated, or a
    // fresh install that never had the old post type at all) is a
    // harmless no-op.
    private static function rename_post_types_to_prefixed() {
        global $wpdb;
        $renames = [
            'bh_track' => 'bhs_track',
            'bh_release' => 'bhs_release',
            'bh_playlist' => 'bhs_playlist',
        ];
        foreach ($renames as $old => $new) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", $new, $old
            ));
        }
        // Any postmeta (e.g. a plugin's own meta_query keyed loosely by
        // post type name rather than post ID) is unaffected — this
        // ecosystem's own meta keys are all named after the FIELD
        // (_bhs_source, _bhs_artist, etc.), never the post type string
        // itself, so there's nothing else to rename here.
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'bhs_likes';

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_track (user_id, track_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Jam: one row per active/ended session, keyed by a short random
        // invite code (not the numeric ID — same reasoning as playlist
        // share tokens, an unguessable/rotatable value that isn't tied
        // to "guess the next integer"). state_json carries the transient,
        // fast-changing bits (queue order, current index, position,
        // playing/paused, control mode, skip votes) as one JSON blob
        // rather than a column per field — polled and rewritten often
        // enough (see class-jam.php) that one UPDATE beats several.
        $jam = $wpdb->prefix . 'bhs_jam_sessions';
        $sql2 = "CREATE TABLE $jam (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            invite_code varchar(12) NOT NULL,
            host_user_id bigint(20) unsigned NOT NULL,
            control_mode varchar(20) NOT NULL DEFAULT 'host',
            state_json longtext,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY invite_code (invite_code)
        ) $charset;";
        dbDelta($sql2);

        $participants = $wpdb->prefix . 'bhs_jam_participants';
        $sql3 = "CREATE TABLE $participants (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            display_name varchar(190) NOT NULL DEFAULT '',
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY session_user (session_id, user_id)
        ) $charset;";
        dbDelta($sql3);

        // Aggregate-only daily rollup for the artist metrics dashboard —
        // one row per (day, track, metric, country, referrer) combo,
        // incremented via INSERT...ON DUPLICATE KEY UPDATE (same atomic
        // pattern as bh-monetization-woo's wallet, never a read-then-
        // write). Never a per-listener row — see class-stats.php's own
        // docblock for why that distinction matters here.
        $stats = $wpdb->prefix . 'bhs_daily_stats';
        $sql4 = "CREATE TABLE $stats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stat_date date NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            metric varchar(20) NOT NULL,
            country varchar(10) NOT NULL DEFAULT 'unknown',
            referrer_bucket varchar(30) NOT NULL DEFAULT 'unknown',
            count bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY bucket (stat_date, track_id, metric, country, referrer_bucket),
            KEY stat_date (stat_date),
            KEY track_id (track_id)
        ) $charset;";
        dbDelta($sql4);

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $jam_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jam));
        $part_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $participants));
        $stats_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats));
        return $exists === $table && $jam_exists === $jam && $part_exists === $participants && $stats_exists === $stats;
    }

    // Same pattern proven out in bh-contest: version-gated so this only
    // does real work once, then a single cheap option check on every
    // subsequent admin_init. Hooked to admin_init specifically, not the
    // broader plugins_loaded a schema migration needs — page creation
    // isn't blocking anything and only matters to whoever's running the
    // site, so there's no reason to make every public page view pay for
    // a check that's only ever useful to an admin.
    const PAGES_VERSION = '1';

    public static function maybe_create_default_pages() {
        if (get_option('bhs_pages_version') === self::PAGES_VERSION) return;

        self::maybe_create_singleton_page('bhs_streaming_page_id', 'Streaming', '[bh_streaming]');

        update_option('bhs_pages_version', self::PAGES_VERSION);
    }

    // Trusts the stored option once set rather than re-verifying the
    // page's status on every load — if someone manually trashes the
    // "Streaming" page, this won't notice and silently recreate it, a
    // deliberate choice since that's plausibly what they wanted.
    private static function maybe_create_singleton_page($option_key, $title, $shortcode) {
        if ((int) get_option($option_key, 0)) return;

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $shortcode,
        ], true);
        if (is_wp_error($new_id)) return;

        update_option($option_key, $new_id);
    }
}
