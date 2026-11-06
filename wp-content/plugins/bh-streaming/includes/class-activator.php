<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned schema migrations. WHY: runs on every load behind a cheap
 * version check (file-replace deploys never fire the activation hook), and
 * only records success once a migration actually completes.
 */
class BHS_Activator {
    const DB_VERSION = '1.4'; // bump when the schema changes; 1.3 = post type rename, 1.4 = bhs_fan_library

    public static function activate(): void {
        BHS_PostTypes::register();
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
        self::rename_post_types_to_prefixed();
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhs_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
        self::rename_post_types_to_prefixed();
        // Post type slugs changed, so flush rewrite rules once, inside the
        // version-gated migration only.
        flush_rewrite_rules();
    }

    // One-time rename of bh_track/bh_release/bh_playlist rows to the
    // bhs_-prefixed post types, so existing content doesn't vanish from
    // list tables. Idempotent: a zero-row UPDATE is a no-op.
    private static function rename_post_types_to_prefixed(): void {
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
        // Postmeta needs no rename: meta keys are named after fields, not post types.
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = BHS_Tables::likes();

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

        // Jam sessions, keyed by an unguessable invite code. state_json holds
        // the fast-changing playback bits as one blob, since it's rewritten often.
        $jam = BHS_Tables::jam_sessions();
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

        $participants = BHS_Tables::jam_participants();
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

        // Aggregate-only daily rollup (never per-listener), incremented via
        // INSERT...ON DUPLICATE KEY UPDATE.
        $stats = BHS_Tables::daily_stats();
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

        // Fan library: external tracks identified by feed_url + track_guid
        // with no local post. WHY: VARCHAR(191) keeps the unique key within
        // InnoDB's index-length limit under utf8mb4.
        $fan_library = BHS_Tables::fan_library();
        $sql5 = "CREATE TABLE $fan_library (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            feed_url varchar(191) NOT NULL,
            track_guid varchar(191) NOT NULL,
            title varchar(255) NOT NULL,
            artist varchar(255) NOT NULL DEFAULT '',
            audio_url text NOT NULL,
            artwork_url text NOT NULL,
            duration varchar(20) NOT NULL DEFAULT '',
            added_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_track (user_id, feed_url, track_guid),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta($sql5);

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $jam_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jam));
        $part_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $participants));
        $stats_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats));
        $fan_library_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fan_library));
        return $exists === $table && $jam_exists === $jam && $part_exists === $participants
            && $stats_exists === $stats && $fan_library_exists === $fan_library;
    }

    // Version-gated default page creation on admin_init (admin-only concern,
    // so public page loads don't pay for it). Version 2 forces a retry on
    // installs that were wrongly marked done.
    const PAGES_VERSION = '2';

    public static function maybe_create_default_pages(): void {
        if (get_option('bhs_pages_version') === self::PAGES_VERSION) return;

        // Only mark done on success, so a failed wp_insert_post() is retried.
        if (self::maybe_create_singleton_page('bhs_streaming_page_id', 'Streaming', '[bh_streaming]')) {
            update_option('bhs_pages_version', self::PAGES_VERSION);
        }
    }

    // Trusts the stored option: a manually trashed page is not recreated.
    private static function maybe_create_singleton_page(string $option_key, string $title, string $shortcode): bool {
        if ((int) get_option($option_key, 0)) return true;

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $shortcode,
        ], true);
        if (is_wp_error($new_id)) return false;

        update_option($option_key, $new_id);
        return true;
    }
}
