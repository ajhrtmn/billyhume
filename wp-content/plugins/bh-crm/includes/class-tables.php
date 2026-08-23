<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package BH CRM
 */
if (!defined('ABSPATH')) exit;

final class BHCRM_Tables {

    private const NAMES = [
        'projects'            => 'bhcrm_projects',
        'project_card_moves'  => 'bhcrm_project_card_moves',
        'project_fixes'       => 'bhcrm_project_fixes',
        'project_feedback'    => 'bhcrm_project_feedback',
        'project_attachments' => 'bhcrm_project_attachments',
        'segments'            => 'bhcrm_segments',
        'notes'               => 'bhcrm_notes',
        'links'               => 'bhcrm_links',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function projects(): string { return self::name('projects'); }
    public static function project_card_moves(): string { return self::name('project_card_moves'); }
    public static function project_fixes(): string { return self::name('project_fixes'); }
    public static function project_feedback(): string { return self::name('project_feedback'); }
    public static function project_attachments(): string { return self::name('project_attachments'); }
    public static function segments(): string { return self::name('segments'); }
    public static function notes(): string { return self::name('notes'); }
    public static function links(): string { return self::name('links'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
