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

    public static function activate() {
        update_option('bhv_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhv_db_version', '0'), self::DB_VERSION, '>=')) return;
        update_option('bhv_db_version', self::DB_VERSION);
    }
}
