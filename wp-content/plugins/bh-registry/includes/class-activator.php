<?php
if (!defined('ABSPATH')) exit;

/**
 * Same hardened migration pattern as bh-contest's votes table and
 * bh-streaming's likes table: versioned, runs on every load via a cheap
 * early-return (not just on activation, since a file-replace deploy
 * never fires WordPress's real activation hook), and only marks itself
 * done if the migration actually succeeded.
 *
 * Custom tables rather than a CPT for artists/links: this data is
 * queried relationally from day one (search by protocol, join artist to
 * its links, filter to verified-only) in a way a CPT + postmeta would
 * make awkward — the same reasoning bh-streaming's likes table and
 * bh-contest's votes table already establish for this ecosystem.
 */
class BHR_Activator {
    // 1.4 exists solely to force the 1.3 migration to actually re-run:
    // 1.3 marked itself successful (all four tables existed, which was
    // all it checked) while dbDelta had silently skipped the new
    // columns, so a site that already stamped 1.3 would never retry.
    // The version-gate is cheap and the schema work is now idempotent
    // (see ensure_column()), so bumping is the honest fix rather than
    // hand-editing the stored option.
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

    // Same pattern as bh-streaming's BHS_Activator::maybe_create_default_pages()
    // — without this, [bh_registry] exists but nothing on the site actually
    // places it anywhere, so activating the plugin alone doesn't produce a
    // visible page. Version-gated the same way (a manually-trashed page
    // isn't silently recreated), and hooked to admin_init rather than the
    // schema migration above since page creation isn't something every
    // front-end request should pay a check for.
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

        $artists = $wpdb->prefix . 'bhr_artists';
        $links   = $wpdb->prefix . 'bhr_links';

        // status: 'pending' (submitted, no verified link yet — not shown
        // in public browse/search), 'active' (>=1 verified link),
        // 'rejected' (an admin explicitly hid it — spam, abuse, etc.).
        // contact_email is stored but NEVER exposed via the public API —
        // it exists only so an admin reviewing the queue, or an
        // automated re-check that starts failing, has somewhere to
        // notify the submitter.
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

        // protocol: 'activitypub' | 'feed'. verification_status: 'pending'
        // | 'verified' | 'failed'. verification_token is the well-known
        // challenge value generated at submission time — domain-level
        // proof of control is checked independently of protocol-openness
        // (see class-verification.php): two separate questions, two
        // separate checks, one row.
        // discovered_via/discovered_from_peer_id: new in DB_VERSION 1.1,
        // pure provenance for the automatic-discovery layer (see
        // class-crawl.php) — 'manual' (the existing POST /submissions
        // path) or 'crawl' (arrived via a peer's manifest, regardless of
        // whether that peer was itself found via direct crawl, the
        // ActivityPub relay layer, or the search-index layer — all three
        // funnel into the same bhr_peers table and the same crawl loop).
        // Never affects verification itself: every link, regardless of
        // how it was discovered, goes through the exact same
        // BHR_Verification::verify_link() check. discovered_hop_count:
        // new in DB_VERSION 1.2, kept in the 1.3 redesign — how many
        // peer-hops from a genesis peer this candidate was found at,
        // read off the link row (not threaded through job args) so it
        // stays correct no matter which path re-triggers verification.
        // dbDelta diffs column-by-column against the existing table, so
        // this (and the 1.1 columns above) are safe additive ALTERs, not
        // a rebuild.
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

        // New in DB_VERSION 1.1, redesigned in 1.3 — automatic discovery
        // layer (see the project's plan file for the full three-layer
        // design: peer-crawl foundation + optional ActivityPub relay +
        // optional search-index lookup, all funneling into this ONE
        // table regardless of which layer found a given peer). A "peer"
        // is another bh-registry install this site pulls a manifest
        // from periodically — no authentication, no shared secret (the
        // original 1.1/1.2 design used a mutual shared_secret for a
        // PUSH model; redesigned to an open PULL/crawl model instead,
        // see the 1.3 migration note below for why). status: 'active'
        // (crawled normally), 'paused' (auto, after repeated crawl
        // failures — see BHR_Peers::check_all_liveness()), 'blocked'
        // (an admin explicitly stopped trusting this peer — sticky,
        // same shape as bhr_artists.status = 'rejected', never
        // auto-reactivated). discovered_hop: 0 for a manually-added
        // (genesis) peer or one found via the relay/search-index layers
        // (both treated as genesis-equivalent, not chained through an
        // existing peer), N+1 for a peer discovered inside another
        // peer's own manifest at hop N — bounds propagation the same
        // conceptual way a TTL bounds network broadcast.
        $peers = $wpdb->prefix . 'bhr_peers';
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

        // Real bug caught by this plugin's own discovery test suite on
        // its very first run, worth recording rather than silently
        // fixing: dbDelta() did NOT add `discovered_hop tinyint
        // unsigned ...` to the pre-existing bhr_peers table. dbDelta
        // parses column definitions with its own regex rather than
        // asking MySQL, and it is genuinely picky about type syntax —
        // an unrecognized definition is skipped SILENTLY, with no error
        // and no return value to check, so the migration "succeeded"
        // while the column simply never appeared. The failure only
        // surfaced downstream as an inexplicable failed INSERT.
        //
        // Fix, applied to every column change from here on: don't rely
        // on dbDelta for ALTERs at all. dbDelta still creates tables
        // (which it does reliably); every column add/drop on an
        // existing table goes through the explicit, guarded helpers
        // below, which ask MySQL what actually exists and issue a real
        // ALTER. Deterministic, verifiable, and independently asserted
        // by the schema tests in class-discovery-test-suite.php.
        self::ensure_column($peers, 'discovered_hop', 'tinyint unsigned NOT NULL DEFAULT 0');
        self::ensure_column($peers, 'last_crawled_at', 'datetime DEFAULT NULL');

        // 1.3 cleanup: shared_secret/last_announced_at are dead weight
        // after the push-model redesign. Justified as a real DROP (not
        // left as permanent dead schema) specifically because zero real
        // peer data exists anywhere yet — confirmed before writing this.
        self::drop_column($peers, 'shared_secret');
        self::drop_column($peers, 'last_announced_at');

        // Same treatment for the bhr_links provenance columns — added
        // via dbDelta in 1.1/1.2, so they may be missing on an install
        // that ran those migrations and hit the same silent skip.
        self::ensure_column($links, 'discovered_via', "varchar(20) NOT NULL DEFAULT 'manual'");
        self::ensure_column($links, 'discovered_from_peer_id', 'bigint(20) unsigned DEFAULT NULL');
        self::ensure_column($links, 'discovered_hop_count', 'tinyint unsigned NOT NULL DEFAULT 0');

        // New in DB_VERSION 1.1 — dedup ledger, unchanged by the 1.3
        // redesign (still exactly as useful for a crawl-discovered
        // candidate as it was for a pushed one). One row per
        // (protocol,url) candidate hash, regardless of how many
        // different peers' manifests list the exact same candidate.
        // seen_hash is sha256(protocol . '|' . normalized_url) — see
        // BHR_Crawl::candidate_hash().
        $seen = $wpdb->prefix . 'bhr_gossip_seen';
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
     * Adds a column if (and only if) it isn't already there. Exists
     * because dbDelta() silently skips column definitions it can't
     * parse — see the long note at its call sites above for the real
     * bug this caught. Asks MySQL what actually exists rather than
     * trusting a parser.
     *
     * $definition is a trusted, hardcoded literal from this file only —
     * never user input, so it's safe to interpolate (column names and
     * types can't be bound as prepared-statement parameters anyway).
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
