<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH Live
 */
if (!defined('ABSPATH')) exit;

final class BHL_Tables {

    private const NAMES = [
        'chat_messages'  => 'bhl_chat_messages',
        'automation_log' => 'bhl_automation_log',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function chat_messages(): string { return self::name('chat_messages'); }
    public static function automation_log(): string { return self::name('automation_log'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
