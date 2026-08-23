<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH_Courses
 */
if (!defined('ABSPATH')) exit;

final class BHC_Tables {

    private const NAMES = [
        'progress'     => 'bhc_progress',
        'enrollments'  => 'bhc_enrollments',
        'achievements' => 'bhc_achievements',
        'reviews'      => 'bhc_reviews',
        'completions'  => 'bhc_completions',
        'sessions'     => 'bhc_sessions',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function progress(): string     { return self::name('progress'); }
    public static function enrollments(): string  { return self::name('enrollments'); }
    public static function achievements(): string { return self::name('achievements'); }
    public static function reviews(): string      { return self::name('reviews'); }
    public static function completions(): string  { return self::name('completions'); }
    public static function sessions(): string     { return self::name('sessions'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
