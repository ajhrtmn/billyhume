<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Commerce — the commerce interface (ROADMAP-platform-evolution.md
 * Section 2 item 2). Wraps product CRUD and order/subscription status
 * behind our own contract; WooCommerce is the implementation underneath
 * today, not something callers reach for directly anymore. Payment
 * GATEWAYS stay behind WooCommerce's own gateway API — this interface
 * is only about not being coupled to WooCommerce's cart/order/product
 * MODEL, per the roadmap doc's own explicit scoping.
 *
 * Provider-registry refactor (Phase 4 follow-up): this class used to
 * carry the actual WooCommerce logic directly inside its own method
 * bodies — a real contract in spirit, but a single hard-wired
 * implementation, not yet an actually swappable one. Every method below
 * is now a one-line dispatch to `BH_CommerceProviders::active()` — see
 * class-commerce-provider.php (the real interface, BH_CommerceProvider),
 * class-commerce-provider-woocommerce.php (BH_WooCommerceProvider, a
 * pure move of what used to live here), and class-commerce-providers.php
 * (the registry + how a future Shopify/Stripe/Squarespace adapter
 * plugs in). Every existing call site across bh-monetization-woo/
 * bh-streaming/etc. is unchanged — this refactor only changes what's
 * INSIDE these methods, never BH_Commerce's public API, so nothing
 * anywhere else in the ecosystem needed to change.
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
     * Create or update a product, returning its ID (0 on failure / no
     * active provider configured). $args:
     *   name (string, required)
     *   price_cents (int, required)
     *   virtual (bool, default true)          — no shipping; this ecosystem never sells physical goods yet
     *   downloadable (bool, default false)
     *   catalog_visibility (string, default 'hidden') — sold only via this ecosystem's own UI, never a stock shop listing
     *   subscription (bool, default false)    — real recurring billing IF has_subscriptions() is also true
     *   subscription_period (string, default 'month')
     *   subscription_period_interval (int, default 1)
     *   trial_length (int, default 0)          — free-trial length before the first real charge; 0 = no trial. Only meaningful with subscription => true and has_subscriptions().
     *   trial_period (string, default 'day')    — WC Subscriptions' own unit: day/week/month/year
     */
    /** @param array<string, mixed> $args */
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
     * Normalizes the active provider's order into a plain array so
     * callers never touch a provider-specific order object directly.
     * Returns null if unavailable/not found.
     */
    /** @return array<string, mixed>|null */
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
     * Normalizes a provider's subscription object into the same
     * plain-array shape get_order() returns — id/customer_id/
     * items[{product_id,quantity}] — so callers never touch a
     * provider-specific subscription object directly.
     */
    /**
     * @param mixed $subscription
     * @return array<string, mixed>|null
     */
    public static function normalize_subscription($subscription): ?array {
        return BH_CommerceProviders::active()->normalize_subscription($subscription);
    }
}
