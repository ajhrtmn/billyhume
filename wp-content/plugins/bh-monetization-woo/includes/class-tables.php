<?php
/**
 * Physical table names for this plugin — the only place they are written.
 *
 * Named accessors rather than a generic get($key): a mistyped method is a
 * fatal, where a mistyped key would return null and degrade into a query
 * against a table that doesn't exist — silent on the money paths.
 *
 * @package BH_Monetization_Woo
 */
if (!defined('ABSPATH')) exit;

final class BHM_Tables {

    private const NAMES = [
        'entitlements'        => 'bhm_entitlements',
        'wallet'              => 'bhm_wallet',
        'wallet_ledger'       => 'bhm_wallet_ledger',
        'play_log'            => 'bhm_play_log',
        'refund_fingerprints' => 'bhm_refund_fingerprints',
        'gift_redemptions'    => 'bhm_gift_redemptions',
        'referral_codes'      => 'bhm_referral_codes',
        'referrals'           => 'bhm_referrals',
        'purchase_ledger'     => 'bhm_purchase_ledger',
        'auction_bids'        => 'bhm_auction_bids',
    ];

    private static function name(string $key): string {
        global $wpdb;
        // WHY: prefix, not base_prefix — these are per-site tables on multisite.
        return $wpdb->prefix . self::NAMES[$key];
    }

    public static function entitlements(): string        { return self::name('entitlements'); }
    public static function wallet(): string              { return self::name('wallet'); }
    public static function wallet_ledger(): string       { return self::name('wallet_ledger'); }
    public static function play_log(): string            { return self::name('play_log'); }
    public static function refund_fingerprints(): string { return self::name('refund_fingerprints'); }
    public static function gift_redemptions(): string    { return self::name('gift_redemptions'); }
    public static function referral_codes(): string      { return self::name('referral_codes'); }
    public static function referrals(): string           { return self::name('referrals'); }
    public static function purchase_ledger(): string     { return self::name('purchase_ledger'); }
    public static function auction_bids(): string        { return self::name('auction_bids'); }

    /** @return array<string,string> accessor key => prefixed table name */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::NAMES) as $key) {
            $tables[$key] = self::name($key);
        }
        return $tables;
    }
}
