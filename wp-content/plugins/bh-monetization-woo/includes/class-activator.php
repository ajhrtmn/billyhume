<?php
if (!defined('ABSPATH')) exit;

/**
 * Same versioned, idempotent migration pattern as every other plugin in
 * this ecosystem (bh-streaming's likes table, bh-contest's votes table,
 * bh-registry's artists/links tables).
 *
 * Two small custom tables, not postmeta — both are genuinely relational
 * (per-user, queried by user across many tracks/releases) in a way
 * postmeta would make awkward:
 *
 * - bhm_entitlements: "this user has access to this thing" — one row
 *   per purchase, subscription, or streaming-tier grant, linked back to
 *   the WooCommerce order (and subscription, if WooCommerce
 *   Subscriptions is active) that paid for it. This is the ONE table
 *   BHM_Gate checks to decide whether a listener can stream or download
 *   something — it never re-derives access from WooCommerce order
 *   status on every page load.
 * - bhm_wallet / bhm_wallet_ledger: a prepaid credit balance for
 *   pay-as-you-listen, topped up via a WooCommerce product (buying
 *   "500 play credits" is itself just a normal WC order) and debited
 *   per play. A ledger table alongside the balance so a listener (or an
 *   artist looking at their own CRM activity, see bh-streaming's
 *   class-crm-integration.php for the established pattern) can see
 *   what actually happened, not just a single opaque number.
 */
class BHM_Activator {
    const DB_VERSION = '1.3'; // 1.2 added bhm_refund_fingerprints; 1.3 migrates _bhm_purchase_object_type meta values after bh-streaming renamed bh_track/bh_release to bhs_track/bhs_release

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhm_db_version', self::DB_VERSION);
        }
        self::migrate_purchase_object_type_meta();
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhm_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhm_db_version', self::DB_VERSION);
        }
        self::migrate_purchase_object_type_meta();
    }

    // Companion to bh-streaming's own BHS_Activator::rename_post_types_to_prefixed().
    // This plugin stores the purchased object's post_type as a plain
    // STRING VALUE in postmeta (_bhm_purchase_object_type — see
    // class-products.php::sync_object_purchase_product()), not as an
    // actual post_type column, so renaming the CPT slugs in bh-streaming
    // doesn't touch this data on its own — any purchase product created
    // before this version still has the OLD 'bh_release'/'bh_track'
    // string sitting in its meta, which class-products.php's own
    // on_order_completed() compares against the NEW 'bhs_release' string
    // going forward. Without this migration, an existing release
    // purchase would silently misclassify as a "track" purchase after
    // upgrading. Idempotent: an UPDATE matching zero rows is a no-op.
    private static function migrate_purchase_object_type_meta() {
        global $wpdb;
        $renames = ['bh_track' => 'bhs_track', 'bh_release' => 'bhs_release'];
        foreach ($renames as $old => $new) {
            $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_key' => '_bhm_purchase_object_type', 'meta_value' => $old]);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $entitlements = $wpdb->prefix . 'bhm_entitlements';
        $wallet       = $wpdb->prefix . 'bhm_wallet';
        $ledger       = $wpdb->prefix . 'bhm_wallet_ledger';

        // type: 'purchase' | 'subscription' | 'streaming_tier'. scope:
        // 'track' | 'release' | 'account' (an account-wide streaming-tier
        // grant covers every track/release the artist site sells that
        // tier for, without one row per track). expires_at is NULL for
        // a one-time purchase (permanent) and set for subscription/
        // streaming_tier grants, refreshed on each renewal webhook.
        dbDelta("CREATE TABLE $entitlements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            scope varchar(20) NOT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            wc_subscription_id bigint(20) unsigned DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_lookup (user_id, type, scope, object_id)
        ) $charset;");

        dbDelta("CREATE TABLE $wallet (
            user_id bigint(20) unsigned NOT NULL,
            balance_cents bigint(20) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) $charset;");

        dbDelta("CREATE TABLE $ledger (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            delta_cents bigint(20) NOT NULL,
            reason varchar(40) NOT NULL,
            track_id bigint(20) unsigned DEFAULT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset;");

        // The authoritative, money-relevant play record — distinct from
        // bh-streaming's own _bhs_play_count (a cheap, unauthenticated,
        // "slightly gameable by refresh-spamming" vanity counter, which
        // is fine for a vanity counter but not something a payout should
        // ever be computed from). Every row here corresponds to either a
        // real wallet debit (paid = 1, cents = the actual amount taken)
        // or a play that was allowed for a non-monetary reason (a tier/
        // subscription/purchase entitlement already covering it, paid =
        // 0) — so an artist (or a future payout/reporting feature) has a
        // real, server-authored history to work from, not a client-
        // reported number. Deliberately does NOT attempt to compute or
        // issue actual payouts/royalty splits here — see class-debug.php
        // and this table's own read helpers for the explicit note that a
        // real "split subscription revenue by relative plays" payout
        // engine is a flagged, NOT-YET-BUILT next step, consistent with
        // VISION.md's layered scope (this is Patreon-lite groundwork,
        // not a full royalty accounting system).
        $play_log = $wpdb->prefix . 'bhm_play_log';
        dbDelta("CREATE TABLE $play_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            paid tinyint(1) NOT NULL DEFAULT 0,
            cents bigint(20) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY track_id (track_id),
            KEY user_id (user_id)
        ) $charset;");

        // Refund/fraud-pattern correlation: one row per refunded order,
        // carrying a fingerprint (hashed IP + a persistent non-tracking
        // cookie id — see class-products.php's fingerprint_for()) rather
        // than the raw IP itself. This is what lets "3+ refunds on one
        // ACCOUNT" (already flagged via user meta) extend to "3+ refunds
        // across DIFFERENT accounts sharing the same device/connection"
        // — the same person evading a flag by signing up again. Hashed,
        // not raw, so this table itself isn't a new place raw IPs sit
        // around indefinitely.
        $fingerprints = $wpdb->prefix . 'bhm_refund_fingerprints';
        dbDelta("CREATE TABLE $fingerprints (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fingerprint varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY fingerprint (fingerprint)
        ) $charset;");

        if ($wpdb->last_error) return false;
        foreach ([$entitlements, $wallet, $ledger, $play_log, $fingerprints] as $t) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return false;
        }
        return true;
    }
}
