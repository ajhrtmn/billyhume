<?php
if (!defined('ABSPATH')) exit;

/**
 * Same versioned, idempotent migration pattern as every other plugin in
 * this ecosystem. No custom table yet — a bhv_video is a real CPT with
 * postmeta pointing at a standard attachment, nothing relational to
 * store outside WordPress's own tables in v1. Chapters/resume-position
 * (ported from bh-streaming's own class-chapters.php) will need its own
 * table once built — this class is the place that migration lands,
 * bumping DB_VERSION the same way bh-streaming's own activator does.
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

    // Real gap found in a functional-depth audit: every sibling plugin
    // with a public shortcode-driven browse page (bh-registry, bh-
    // streaming) auto-creates that page on activation — this plugin
    // never did, despite its own description promising "a standalone
    // video catalog and player... browse/playback SPA." [bh_video] and
    // its REST API were both fully real and working, but there was
    // literally no discoverable page anywhere on a fresh install unless
    // an admin already knew to hand-create one and paste the shortcode
    // in — confirmed live (this install's own bhv_video post count was
    // 0 and no page anywhere referenced [bh_video]). Same version-gated
    // pattern as BHR_Activator::maybe_create_default_pages() (a
    // manually-trashed page isn't silently recreated), hooked to
    // admin_init rather than every front-end request the same way.
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
