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
    const DB_VERSION = '1.1';

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
        // pure provenance for the peer gossip/announce layer (see
        // class-gossip.php once it lands) — 'manual' (the existing
        // POST /submissions path) or 'gossip' (arrived via an
        // authenticated peer announce). Never affects verification
        // itself: every link, regardless of how it was discovered, goes
        // through the exact same BHR_Verification::verify_link() check.
        // dbDelta diffs column-by-column against the existing table, so
        // this is a safe additive ALTER, not a rebuild.
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
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY artist_id (artist_id),
            KEY verification_status (verification_status)
        ) $charset;");

        // New in DB_VERSION 1.1 — peer gossip/announce layer (real plan,
        // scoped in full before any of this landed; see the project's
        // plan file for the complete phased design). A "peer" is
        // another bh-registry install an admin HERE has explicitly
        // chosen to exchange announcements with — never auto-discovered,
        // never a default relay (this ecosystem's own standing rule:
        // no third-party service as the only implementation of anything
        // critical, and no silent default trust). status: 'active'
        // (gossip flows both ways), 'paused' (auto, after repeated
        // liveness-check failures — see the future BHR_Peers::
        // check_all_liveness()), 'blocked' (an admin explicitly stopped
        // trusting announces FROM this peer — sticky, same shape as
        // bhr_artists.status = 'rejected', never auto-reactivated).
        // shared_secret is exchanged out-of-band (shown once, like a
        // webhook key) when two admins each add the other as a peer —
        // mutual, two-sided, matching how ActivityPub/Mastodon instances
        // bootstrap federation trust.
        $peers = $wpdb->prefix . 'bhr_peers';
        dbDelta("CREATE TABLE $peers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            base_url varchar(255) NOT NULL,
            label varchar(190) DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            shared_secret varchar(64) NOT NULL,
            last_announced_at datetime DEFAULT NULL,
            last_seen_at datetime DEFAULT NULL,
            fail_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY base_url (base_url),
            KEY status (status)
        ) $charset;");

        // New in DB_VERSION 1.1 — dedup/hop-limiting ledger for the
        // gossip layer. One row per (protocol,url) candidate hash,
        // regardless of how many different peers re-announce the exact
        // same candidate (a realistic mesh scenario — two peers both
        // relaying the same original announcement) or how many times a
        // loop would otherwise re-propagate it. seen_hash is
        // sha256(protocol . '|' . normalized_url) — see
        // BHR_Gossip::candidate_hash() once that class lands.
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

        if ($wpdb->last_error) return false;
        $ok_artists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $artists)) === $artists;
        $ok_links   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $links)) === $links;
        $ok_peers   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $peers)) === $peers;
        $ok_seen    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $seen)) === $seen;
        return $ok_artists && $ok_links && $ok_peers && $ok_seen;
    }
}
