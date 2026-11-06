<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned, idempotent schema (dbDelta). bhm_entitlements is the one table BHM_Gate reads;
 * access is never re-derived from WooCommerce order status.
 */
class BHM_Activator {
    const DB_VERSION = '1.7'; // bump on any schema change

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhm_db_version', self::DB_VERSION);
        }
        self::migrate_purchase_object_type_meta();
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhm_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhm_db_version', self::DB_VERSION);
        }
        self::migrate_purchase_object_type_meta();
    }

    // _bhm_purchase_object_type stores bh-streaming's post type as a string, so its
    // bh_* -> bhs_* rename must be mirrored here. Idempotent.
    private static function migrate_purchase_object_type_meta(): void {
        global $wpdb;
        $renames = ['bh_track' => 'bhs_track', 'bh_release' => 'bhs_release'];
        foreach ($renames as $old => $new) {
            $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_key' => '_bhm_purchase_object_type', 'meta_value' => $old]);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $entitlements = BHM_Tables::entitlements();
        $wallet       = BHM_Tables::wallet();
        $ledger       = BHM_Tables::wallet_ledger();

        // type: 'purchase' | 'subscription' | 'streaming_tier'. scope: 'track' | 'release' | 'course' | 'account'.
        // expires_at NULL = permanent.
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

        // Spendable = balance_cents - held_cents, checked atomically in hold().
        dbDelta("CREATE TABLE $wallet (
            user_id bigint(20) unsigned NOT NULL,
            balance_cents bigint(20) NOT NULL DEFAULT 0,
            held_cents bigint(20) NOT NULL DEFAULT 0,
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

        // Server-authored play record (unlike bh-streaming's gameable _bhs_play_count).
        // paid=1 rows are wallet debits; paid=0 plays were covered by an entitlement.
        $play_log = BHM_Tables::play_log();
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

        // One row per refunded order with a hashed device fingerprint (never a raw IP),
        // to spot refund abuse across accounts.
        $fingerprints = BHM_Tables::refund_fingerprints();
        dbDelta("CREATE TABLE $fingerprints (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fingerprint varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY fingerprint (fingerprint)
        ) $charset;");

        // One row per gifted tier purchase, claimed or not.
        $gifts = BHM_Tables::gift_redemptions();
        dbDelta("CREATE TABLE $gifts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(32) NOT NULL,
            tier_id bigint(20) unsigned NOT NULL,
            buyer_user_id bigint(20) unsigned NOT NULL,
            recipient_email varchar(191) NOT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            redeemed_by_user_id bigint(20) unsigned DEFAULT NULL,
            redeemed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY recipient_email (recipient_email)
        ) $charset;");

        // bhm_referral_codes: one code (a real WC coupon) per referrer. bhm_referrals: one row per
        // redemption. WHY: UNIQUE wc_order_id makes the INSERT the atomic no-double-credit claim.
        $referral_codes = BHM_Tables::referral_codes();
        $referral_codes_sql = "CREATE TABLE $referral_codes (
            user_id bigint(20) unsigned NOT NULL,
            code varchar(32) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id),
            UNIQUE KEY code (code)
        ) $charset;";
        dbDelta($referral_codes_sql);

        $referrals = BHM_Tables::referrals();
        $referrals_sql = "CREATE TABLE $referrals (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(32) NOT NULL,
            referrer_user_id bigint(20) unsigned NOT NULL,
            customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wc_order_id bigint(20) unsigned NOT NULL,
            commission_cents bigint(20) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_order_id (wc_order_id),
            KEY referrer_user_id (referrer_user_id)
        ) $charset;";
        dbDelta($referrals_sql);

        // Append-only proof of purchase: never update or delete rows; a reversal is a new row
        // linked via linked_record_id. Only record_hash is anchored; user_id never leaves this DB.
        $purchase_ledger = BHM_Tables::purchase_ledger();
        $purchase_ledger_sql = "CREATE TABLE $purchase_ledger (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(20) NOT NULL,
            wc_order_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned DEFAULT NULL,
            content_hash varchar(64) DEFAULT NULL,
            price_cents bigint(20) NOT NULL DEFAULT 0,
            record_hash varchar(64) NOT NULL,
            anchor_status varchar(20) NOT NULL DEFAULT 'pending',
            anchor_proof longtext DEFAULT NULL,
            linked_record_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY wc_order_id (wc_order_id),
            KEY user_id (user_id),
            KEY anchor_status (anchor_status)
        ) $charset;";
        dbDelta($purchase_ledger_sql);

        // Per-bid history. status: active | outbid | won | reserve_not_met | won_pending_review |
        // payment_failed | rejected (see BHM_Auctions).
        $auction_bids = BHM_Tables::auction_bids();
        $auction_bids_sql = "CREATE TABLE $auction_bids (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            amount_cents bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id, amount_cents),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta($auction_bids_sql);

        if ($wpdb->last_error) return false;
        foreach ([$entitlements, $wallet, $ledger, $play_log, $fingerprints, $gifts, $referral_codes, $referrals, $purchase_ledger] as $t) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return false;
        }
        return true;
    }
}
