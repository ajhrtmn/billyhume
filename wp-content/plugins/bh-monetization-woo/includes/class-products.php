<?php
if (!defined('ABSPATH')) exit;

/**
 * DRY/SOLID audit Phase 3: this file used to be a single 920-line class
 * mixing four distinct responsibilities (WC product sync, bh-streaming
 * metabox UI, play/access gating, and the order/subscription →
 * entitlement bridge). Split into four focused classes:
 *   - BHM_ProductSync    (includes/class-product-sync.php)
 *   - BHM_MonetizationUI (includes/class-monetization-ui.php)
 *   - BHM_PlayGating     (includes/class-play-gating.php)
 *   - BHM_Entitlements   (includes/class-entitlements.php)
 *
 * BHM_Products itself stays only as a thin, backward-compatible facade:
 * its init() wires each new class's own init(), and its public methods
 * are one-line delegations to whichever new class now owns that logic —
 * every existing external call site (BHM_Debug, BHM_CRMIntegration,
 * BHM_Gifts, BHM_Tiers, BHM_MockCommerce) keeps working unchanged.
 */
class BHM_Products {
    public static function init(): void {
        // BHM_ProductSync has no init() — it's a pure static helper with
        // nothing to hook.
        BHM_MonetizationUI::init();
        BHM_PlayGating::init();
        BHM_Entitlements::init();
    }

    /* ---------- delegating facade methods (see class docblock) ---------- */

    public static function sync_tier_wc_product(int $tier_post_id, string $name, int $price_cents, int $annual_price_cents = 0, int $trial_days = 0): void {
        BHM_ProductSync::sync_tier_wc_product($tier_post_id, $name, $price_cents, $annual_price_cents, $trial_days);
    }

    public static function grant_gift_entitlement(int $user_id, int $tier_id, int $order_id): void {
        BHM_Entitlements::grant_gift_entitlement($user_id, $tier_id, $order_id);
    }

    /** @return array<string, mixed>|null */
    public static function revoke_entitlement_by_id(int $entitlement_id, string $reason = 'manual_revoke'): ?array {
        return BHM_Entitlements::revoke_entitlement_by_id($entitlement_id, $reason);
    }

    public static function debug_grant_tier(int $user_id, int $tier_id, int $days = 30): void {
        BHM_Entitlements::debug_grant_tier($user_id, $tier_id, $days);
    }

    public static function debug_grant_purchase(int $user_id, int $object_id, string $scope = 'track'): void {
        BHM_Entitlements::debug_grant_purchase($user_id, $object_id, $scope);
    }

    /** @param mixed $subscription */
    public static function on_subscription_active($subscription): void {
        BHM_Entitlements::on_subscription_active($subscription);
    }

    /** @param mixed $subscription */
    public static function on_subscription_ended($subscription): void {
        BHM_Entitlements::on_subscription_ended($subscription);
    }

    /** @param mixed $subscription */
    public static function on_subscription_paused($subscription): void {
        BHM_Entitlements::on_subscription_paused($subscription);
    }
}
