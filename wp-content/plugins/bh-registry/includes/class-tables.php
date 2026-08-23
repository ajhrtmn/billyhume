<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH_Registry
 */
if (!defined('ABSPATH')) exit;

final class BHR_Tables {

    private const NAMES = [
        'links'       => 'bhr_links',
        'artists'     => 'bhr_artists',
        'peers'       => 'bhr_peers',
        'gossip_seen' => 'bhr_gossip_seen',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function links(): string       { return self::name('links'); }
    public static function artists(): string     { return self::name('artists'); }
    public static function peers(): string       { return self::name('peers'); }
    public static function gossip_seen(): string { return self::name('gossip_seen'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
