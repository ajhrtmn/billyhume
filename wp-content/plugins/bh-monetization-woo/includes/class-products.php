<?php
if (!defined('ABSPATH')) exit;

/**
 * Backward-compatible facade: inits and delegates to BHM_ProductSync, BHM_MonetizationUI,
 * BHM_PlayGating and BHM_Entitlements.
 */
class BHM_Products {
    public static function init(): void {
        BHM_MonetizationUI::init();
        BHM_PlayGating::init();
        BHM_Entitlements::init();
    }

    /* ---------- delegating facade methods ---------- */

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
