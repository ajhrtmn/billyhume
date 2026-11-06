<?php
if (!defined('ABSPATH')) exit;

/**
 * Thin bhr_links lookup by id; deliberately not a full repository.
 */
class BHR_Links {
    public static function table(): string {
        return BHR_Tables::links();
    }

    public static function find(int $link_id): ?\stdClass {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $link_id));
    }
}
