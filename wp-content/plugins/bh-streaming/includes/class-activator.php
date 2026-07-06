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
    const DB_VERSION = '1.0';

    public static function activate() {
        BHS_PostTypes::register();
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhs_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhs_db_version', self::DB_VERSION);
        }
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

        if ($wpdb->last_error) return false;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists === $table;
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
