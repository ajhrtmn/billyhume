<?php
if (!defined('ABSPATH')) exit;

/**
 * Contract behind BH_Commerce. BH_WooCommerceProvider is the only
 * implementation; Shopify/Stripe/Squarespace slots are reserved but
 * deliberately unimplemented until they can be tested against real accounts.
 */
interface BH_CommerceProvider {
    public function available(): bool;

    /** @return array<string, mixed> */
    public function get_available_payment_gateways(): array;

    public function has_subscriptions(): bool;

    /** @param array<string, mixed> $args */
    public function upsert_product(int $existing_id, array $args): int;

    /** @return array<string, mixed>|null */
    public function get_product(int $product_id): ?array;

    public function product_exists(int $product_id): bool;

    public function get_edit_url(int $product_id): string;

    /** @return array<string, mixed>|null */
    public function get_order(int $order_id): ?array;

    public function is_subscription_active(int $subscription_id): bool;

    public function has_subscription_switching(): bool;

    /** @return mixed */
    public function get_subscription(int $subscription_id);

    /**
     * @param mixed $subscription
     * @return array<string, mixed>|null
     */
    public function normalize_subscription($subscription): ?array;
}
