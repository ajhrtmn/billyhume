<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH_Social
 */
if (!defined('ABSPATH')) exit;

final class BHSO_Tables {

    private const NAMES = [
        'ad_campaigns'   => 'bhso_ad_campaigns',
        'platform_stats' => 'bhso_platform_stats',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function ad_campaigns(): string   { return self::name('ad_campaigns'); }
    public static function platform_stats(): string { return self::name('platform_stats'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
