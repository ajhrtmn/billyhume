<?php
/**
 * Physical table names for this plugin, written only here. Named accessors, so a typo
 * is a fatal instead of a query against a table that does not exist.
 */
if (!defined('ABSPATH')) exit;

final class BHCON_Tables {

    private const NAMES = [
        'votes'                => 'bh_votes',
        'judge_scores'         => 'bh_judge_scores',
        'participant_profiles' => 'bh_participant_profiles',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function votes(): string { return self::name('votes'); }
    public static function judge_scores(): string { return self::name('judge_scores'); }
    public static function participant_profiles(): string { return self::name('participant_profiles'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
