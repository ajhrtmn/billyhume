<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned, idempotent activation. No custom tables: bhv_video is a CPT with postmeta.
 */
class BHV_Activator {
    const DB_VERSION = '1.0';

    public static function activate(): void {
        update_option('bhv_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhv_db_version', '0'), self::DB_VERSION, '>=')) return;
        update_option('bhv_db_version', self::DB_VERSION);
    }

    // Creates the [bh_video] page once; version-gated so a trashed page isn't silently recreated.
    const PAGES_VERSION = '1';

    public static function maybe_create_default_pages(): void {
        if (get_option('bhv_pages_version') === self::PAGES_VERSION) return;

        if (!(int) get_option('bhv_catalog_page_id', 0)) {
            $new_id = wp_insert_post([
                'post_title'   => 'Videos',
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[bh_video]',
            ], true);
            if (!is_wp_error($new_id)) update_option('bhv_catalog_page_id', $new_id);
        }

        update_option('bhv_pages_version', self::PAGES_VERSION);
    }
}
