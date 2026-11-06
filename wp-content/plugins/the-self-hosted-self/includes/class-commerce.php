<?php
if (!defined('ABSPATH')) exit;

/**
 * Commerce interface: product CRUD and order/subscription status behind our
 * own contract. Each method dispatches to BH_CommerceProviders::active()
 * (WooCommerce today). Payment gateways stay behind WooCommerce's own API.
 */
class BH_Commerce {
    public static function available(): bool {
        return BH_CommerceProviders::active()->available();
    }

    /** @return array<string, mixed> */
    public static function get_available_payment_gateways(): array {
        return BH_CommerceProviders::active()->get_available_payment_gateways();
    }

    public static function has_subscriptions(): bool {
        return BH_CommerceProviders::active()->has_subscriptions();
    }

    /**
     * Create or update a product; returns its ID (0 on failure). $args:
     *   name (string, required), price_cents (int, required)
     *   virtual (bool, true), downloadable (bool, false)
     *   catalog_visibility (string, 'hidden'): sold only via this ecosystem's UI
     *   subscription (bool, false): recurring only if has_subscriptions()
     *   subscription_period ('month'), subscription_period_interval (1)
     *   trial_length (int, 0 = none), trial_period ('day'|'week'|'month'|'year')
     *
     * @param array<string, mixed> $args
     */
    public static function upsert_product(int $existing_id, array $args): int {
        return BH_CommerceProviders::active()->upsert_product($existing_id, $args);
    }

    /** @return array<string, mixed>|null */
    public static function get_product(int $product_id): ?array {
        return BH_CommerceProviders::active()->get_product($product_id);
    }

    public static function product_exists(int $product_id): bool {
        return BH_CommerceProviders::active()->product_exists($product_id);
    }

    public static function get_edit_url(int $product_id): string {
        return BH_CommerceProviders::active()->get_edit_url($product_id);
    }

    /**
     * Provider order as a plain array, or null if unavailable/not found.
     *
     * @return array<string, mixed>|null
     */
    public static function get_order(int $order_id): ?array {
        return BH_CommerceProviders::active()->get_order($order_id);
    }

    public static function is_subscription_active(int $subscription_id): bool {
        return BH_CommerceProviders::active()->is_subscription_active($subscription_id);
    }

    public static function has_subscription_switching(): bool {
        return BH_CommerceProviders::active()->has_subscription_switching();
    }

    /** @return mixed */
    public static function get_subscription(int $subscription_id) {
        return BH_CommerceProviders::active()->get_subscription($subscription_id);
    }

    /**
     * Provider subscription as the same plain shape get_order() returns
     * (id/customer_id/items[{product_id,quantity}]).
     *
     * @param mixed $subscription
     * @return array<string, mixed>|null
     */
    public static function normalize_subscription($subscription): ?array {
        return BH_CommerceProviders::active()->normalize_subscription($subscription);
    }
}
