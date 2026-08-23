<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would degrade into a query against a table
 * that doesn't exist.
 *
 * @package The Self-Hosted Self (core)
 */
if (!defined('ABSPATH')) exit;

final class OUS_Tables {

    private const NAMES = [
        'audit_log'          => 'bhcore_audit_log',
        'campaigns'          => 'bhcore_campaigns',
        'content'            => 'bhcore_content',
        'debug_log'          => 'bhcore_debug_log',
        'dmca_notices'       => 'bhcore_dmca_notices',
        'element_placements' => 'bhcore_element_placements',
        'element_prefabs'    => 'bhcore_element_prefabs',
        'element_states'     => 'bhcore_element_states',
        'events'             => 'bhcore_events',
        'jobs'               => 'bhcore_jobs',
        'notifications'      => 'bhcore_notifications',
        'revisions'          => 'bhcore_revisions',
        'profiles'           => 'bhi_profiles',
        'reports'            => 'bhi_reports',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function audit_log(): string { return self::name('audit_log'); }
    public static function campaigns(): string { return self::name('campaigns'); }
    public static function content(): string { return self::name('content'); }
    public static function debug_log(): string { return self::name('debug_log'); }
    public static function dmca_notices(): string { return self::name('dmca_notices'); }
    public static function element_placements(): string { return self::name('element_placements'); }
    public static function element_prefabs(): string { return self::name('element_prefabs'); }
    public static function element_states(): string { return self::name('element_states'); }
    public static function events(): string { return self::name('events'); }
    public static function jobs(): string { return self::name('jobs'); }
    public static function notifications(): string { return self::name('notifications'); }
    public static function revisions(): string { return self::name('revisions'); }
    public static function profiles(): string { return self::name('profiles'); }
    public static function reports(): string { return self::name('reports'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
