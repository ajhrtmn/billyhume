<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH Streaming
 */
if (!defined('ABSPATH')) exit;

final class BHS_Tables {

    private const NAMES = [
        'likes'            => 'bhs_likes',
        'jam_sessions'     => 'bhs_jam_sessions',
        'jam_participants' => 'bhs_jam_participants',
        'fan_library'      => 'bhs_fan_library',
        'daily_stats'      => 'bhs_daily_stats',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function likes(): string { return self::name('likes'); }
    public static function jam_sessions(): string { return self::name('jam_sessions'); }
    public static function jam_participants(): string { return self::name('jam_participants'); }
    public static function fan_library(): string { return self::name('fan_library'); }
    public static function daily_stats(): string { return self::name('daily_stats'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
