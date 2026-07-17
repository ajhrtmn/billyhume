<?php
if (!defined('ABSPATH')) exit;

/**
 * Thin data-access wrapper around the bhr_links table — added in the
 * DRY/SOLID refactor pass for encapsulation: before this, every class in
 * the plugin (class-api.php, class-admin.php, class-verification.php,
 * class-debug.php) reached directly into `$wpdb->prefix . 'bhr_links'`
 * and hand-wrote its own SQL, including three byte-identical
 * "fetch one link by id" queries. The table name and primary-key lookup
 * are now defined in exactly one place; every other read/write in this
 * plugin is still free to run its own more specific query (a list
 * filtered by artist/status, a bulk delete, etc.) — this intentionally
 * does NOT try to become a full ORM/repository for every access pattern
 * the plugin needs, just the one genuinely duplicated shape plus the
 * shared table-name constant every other query in the plugin can also
 * reference instead of re-typing the literal string.
 */
class BHR_Links {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhr_links';
    }

    public static function find($link_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $link_id));
    }
}
