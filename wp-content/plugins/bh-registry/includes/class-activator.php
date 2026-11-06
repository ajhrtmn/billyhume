<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned schema migration for the registry's custom tables (relational data, so not a CPT).
 * WHY: runs on every load via a cheap version check, since a file-replace deploy never fires
 * the activation hook; only stamps the version when the migration actually succeeded.
 */
class BHR_Activator {
    const DB_VERSION = '1.4';

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhr_db_version', self::DB_VERSION);
        }
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhr_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhr_db_version', self::DB_VERSION);
        }
    }

    // Creates the [bh_registry] page once; version-gated so a trashed page isn't silently recreated.
    const PAGES_VERSION = '1';

    public static function maybe_create_default_pages(): void {
        if (get_option('bhr_pages_version') === self::PAGES_VERSION) return;

        if (!(int) get_option('bhr_registry_page_id', 0)) {
            $new_id = wp_insert_post([
                'post_title'   => 'Artist Registry',
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[bh_registry]',
            ], true);
            if (!is_wp_error($new_id)) update_option('bhr_registry_page_id', $new_id);
        }

        update_option('bhr_pages_version', self::PAGES_VERSION);
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $artists = BHR_Tables::artists();
        $links   = BHR_Tables::links();

        // status: 'pending' (not public) | 'active' (>=1 verified link) | 'rejected' (admin-hidden, sticky).
        // contact_email is NEVER exposed via the public API.
        dbDelta("CREATE TABLE $artists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            display_name varchar(190) NOT NULL,
            bio text,
            avatar_url varchar(500) DEFAULT '',
            contact_email varchar(190) DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset;");

        // protocol: 'activitypub' | 'feed'; verification_status: 'pending' | 'verified' | 'failed'.
        // discovered_via ('manual' | 'crawl') and the hop/peer columns are provenance only;
        // they never affect verification.
        dbDelta("CREATE TABLE $links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            artist_id bigint(20) unsigned NOT NULL,
            protocol varchar(20) NOT NULL,
            url varchar(500) NOT NULL,
            verification_token varchar(64) NOT NULL,
            verification_status varchar(20) NOT NULL DEFAULT 'pending',
            verified_at datetime DEFAULT NULL,
            last_checked_at datetime DEFAULT NULL,
            fail_count int unsigned NOT NULL DEFAULT 0,
            metadata longtext,
            discovered_via varchar(20) NOT NULL DEFAULT 'manual',
            discovered_from_peer_id bigint(20) unsigned DEFAULT NULL,
            discovered_hop_count tinyint unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY artist_id (artist_id),
            KEY verification_status (verification_status)
        ) $charset;");

        // Other bh-registry installs this site crawls. status: 'active' | 'paused' (auto, after repeated
        // crawl failures) | 'blocked' (admin, sticky). discovered_hop: 0 for genesis/relay/seed peers,
        // N+1 when found in a hop-N peer's manifest; bounds propagation like a TTL.
        $peers = BHR_Tables::peers();
        dbDelta("CREATE TABLE $peers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            base_url varchar(255) NOT NULL,
            label varchar(190) DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            discovered_hop tinyint unsigned NOT NULL DEFAULT 0,
            last_crawled_at datetime DEFAULT NULL,
            last_seen_at datetime DEFAULT NULL,
            fail_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY base_url (base_url),
            KEY status (status)
        ) $charset;");

        // WHY: dbDelta silently skips column definitions it can't parse, so it only creates tables;
        // every column add/drop on an existing table goes through ensure_column()/drop_column().
        self::ensure_column($peers, 'discovered_hop', 'tinyint unsigned NOT NULL DEFAULT 0');
        self::ensure_column($peers, 'last_crawled_at', 'datetime DEFAULT NULL');

        self::drop_column($peers, 'shared_secret');
        self::drop_column($peers, 'last_announced_at');

        self::ensure_column($links, 'discovered_via', "varchar(20) NOT NULL DEFAULT 'manual'");
        self::ensure_column($links, 'discovered_from_peer_id', 'bigint(20) unsigned DEFAULT NULL');
        self::ensure_column($links, 'discovered_hop_count', 'tinyint unsigned NOT NULL DEFAULT 0');

        // Dedup ledger: one row per candidate, seen_hash = BHR_Crawl::candidate_hash().
        $seen = BHR_Tables::gossip_seen();
        dbDelta("CREATE TABLE $seen (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seen_hash varchar(64) NOT NULL,
            origin_base_url varchar(255) NOT NULL,
            candidate_url varchar(500) NOT NULL,
            protocol varchar(20) NOT NULL,
            min_hop_seen tinyint unsigned NOT NULL DEFAULT 0,
            first_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY seen_hash (seen_hash)
        ) $charset;");

        self::ensure_column($seen, 'min_hop_seen', 'tinyint unsigned NOT NULL DEFAULT 0');

        if ($wpdb->last_error) return false;
        $ok_artists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $artists)) === $artists;
        $ok_links   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $links)) === $links;
        $ok_peers   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $peers)) === $peers;
        $ok_seen    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $seen)) === $seen;
        return $ok_artists && $ok_links && $ok_peers && $ok_seen;
    }

    /**
     * Adds a column only if MySQL reports it missing.
     * WHY: $definition is a hardcoded literal from this file, never user input, so interpolation is safe.
     */
    private static function ensure_column(string $table, string $column, string $definition): void {
        global $wpdb;
        if (!self::table_exists($table)) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        if (in_array($column, (array) $cols, true)) return;
        $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }

    private static function drop_column(string $table, string $column): void {
        global $wpdb;
        if (!self::table_exists($table)) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        if (!in_array($column, (array) $cols, true)) return;
        $wpdb->query("ALTER TABLE `$table` DROP COLUMN `$column`");
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
