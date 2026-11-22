<?php
/**
 * Physical table names for this plugin, written only here. Named accessors, so a typo
 * is a fatal instead of a query against a table that does not exist.
 */
if (!defined('ABSPATH')) exit;

final class BHT_Tables {

    private const NAMES = [
        'tickets' => 'bhtickets_tickets',
        'replies' => 'bhtickets_replies',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function tickets(): string { return self::name('tickets'); }
    public static function replies(): string { return self::name('replies'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
