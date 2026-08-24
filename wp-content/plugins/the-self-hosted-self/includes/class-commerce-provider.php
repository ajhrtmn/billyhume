<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_CommerceProvider — the real contract behind BH_Commerce.
 *
 * Extracted from BH_Commerce (class-commerce.php), which for two prior
 * migration passes was already the ecosystem's commerce interface, but
 * as a single class with WooCommerce logic written directly inside its
 * own method bodies — a genuine contract in spirit, but not yet an
 * actual swappable one. This interface is that contract made literal:
 * every method BH_Commerce's callers already depend on (get_order(),
 * upsert_product(), normalize_subscription(), etc.), as real signatures
 * any concrete provider class must implement.
 *
 * BH_WooCommerceProvider (class-commerce-provider-woocommerce.php) is
 * the one real, working implementation today — a pure move of
 * BH_Commerce's old method bodies, no logic changes. BH_Commerce itself
 * (class-commerce.php) is now a thin dispatcher: every one of its public
 * static methods delegates to `BH_CommerceProviders::active()`, so every
 * existing call site across bh-monetization-woo/bh-streaming/etc. keeps
 * working completely unchanged — this refactor only changes what's
 * INSIDE BH_Commerce, never its public API.
 *
 * Named provider slots reserved for later (BH_CommerceProviders::
 * REGISTERABLE_KEYS) — Shopify, Stripe (direct), Squarespace — are
 * deliberately NOT implemented here. Writing fake adapter classes
 * against three real payment platforms' APIs without a real store/
 * account to test against would be exactly the kind of unverified,
 * possibly-wrong code this ecosystem's own "NOT runtime-verified"
 * disclosure convention exists to flag — worse, code that LOOKS like a
 * working integration but has never talked to a real API is more
 * dangerous than no code at all. A future adapter for any of those
 * platforms is a matter of writing one new class implementing this
 * interface and registering it — see BH_CommerceProviders' own
 * docblock for exactly what that involves.
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
