<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_WooCommerceProvider — the real, working implementation of
 * BH_CommerceProvider. Every method here is a pure move of what used to
 * live directly inside BH_Commerce's own method bodies (class-commerce.php,
 * before the provider-registry refactor) — same logic, byte-for-byte,
 * just relocated so BH_Commerce can dispatch to it instead of being it.
 * See BH_CommerceProvider's own docblock for the full reasoning.
 */
class BH_WooCommerceProvider implements BH_CommerceProvider {
    public function available(): bool {
        return class_exists('WooCommerce');
    }

    /** @return array<string, mixed> */
    public function get_available_payment_gateways(): array {
        $gateways = class_exists('WC_Payment_Gateways') ? WC_Payment_Gateways::instance()->get_available_payment_gateways() : [];
        return apply_filters('bh_commerce_available_payment_gateways', $gateways);
    }

    public function has_subscriptions(): bool {
        return (bool) apply_filters('bh_commerce_has_subscriptions', class_exists('WC_Subscriptions') && class_exists('WC_Product_Subscription'));
    }

    /** @param array<string, mixed> $args */
    public function upsert_product(int $existing_id, array $args): int {
        if (!$this->available()) return 0;

        $name = (string) ($args['name'] ?? '');
        $price_cents = (int) ($args['price_cents'] ?? 0);
        $virtual = array_key_exists('virtual', $args) ? (bool) $args['virtual'] : true;
        $downloadable = (bool) ($args['downloadable'] ?? false);
        $catalog_visibility = (string) ($args['catalog_visibility'] ?? 'hidden');
        $want_subscription = (bool) ($args['subscription'] ?? false);
        $use_subscription = $want_subscription && $this->has_subscriptions();

        $product = $existing_id ? wc_get_product((int) $existing_id) : null;
        if (!$product) {
            $product = $use_subscription ? new WC_Product_Subscription() : new WC_Product_Simple();
        }

        $product->set_name($name);
        $product->set_regular_price(number_format($price_cents / 100, 2, '.', ''));
        $product->set_virtual($virtual);
        $product->set_downloadable($downloadable);
        $product->set_catalog_visibility($catalog_visibility);
        if ($use_subscription && method_exists($product, 'set_props')) {
            $trial_length = (int) ($args['trial_length'] ?? 0);
            $product->set_props([
                'subscription_period' => (string) ($args['subscription_period'] ?? 'month'),
                'subscription_period_interval' => (int) ($args['subscription_period_interval'] ?? 1),
                'trial_length' => $trial_length,
                'trial_period' => (string) ($args['trial_period'] ?? 'day'),
            ]);
        }
        $product->save();

        return $product->get_id();
    }

    /** @return array<string, mixed>|null */
    public function get_product(int $product_id): ?array {
        if (!$this->available()) return null;
        $product = wc_get_product((int) $product_id);
        if (!$product) return null;
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price_cents' => (int) round(((float) $product->get_regular_price()) * 100),
            'purchasable' => $product->is_purchasable(),
        ];
    }

    public function product_exists(int $product_id): bool {
        return $this->available() && (bool) wc_get_product((int) $product_id);
    }

    public function get_edit_url(int $product_id): string {
        return $this->available() ? (string) get_edit_post_link((int) $product_id) : '';
    }

    /** @return array<string, mixed>|null */
    public function get_order(int $order_id): ?array {
        if (!$this->available() || !function_exists('wc_get_order')) return null;
        $order = wc_get_order((int) $order_id);
        if (!$order) return null;

        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            $items[] = [
                'product_id' => $item->get_product_id(),
                'quantity' => $item->get_quantity(),
                'gift_email' => (string) $item->get_meta('_bhm_gift_email'),
            ];
        }

        return [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'total_cents' => (int) round(((float) $order->get_total()) * 100),
            'items' => $items,
        ];
    }

    public function is_subscription_active(int $subscription_id): bool {
        if (!$this->has_subscriptions() || !function_exists('wcs_get_subscription')) return false;
        $sub = wcs_get_subscription((int) $subscription_id);
        return $sub && $sub->has_status('active');
    }

    public function has_subscription_switching(): bool {
        return (bool) apply_filters('bh_commerce_has_subscription_switching', class_exists('WC_Subscriptions_Switcher'));
    }

    /** @return mixed */
    public function get_subscription(int $subscription_id) {
        $subscription = function_exists('wcs_get_subscription') ? wcs_get_subscription((int) $subscription_id) : null;
        return apply_filters('bh_commerce_get_subscription', $subscription, (int) $subscription_id);
    }

    /**
     * @param mixed $subscription
     * @return array<string, mixed>|null
     */
    public function normalize_subscription($subscription): ?array {
        if (!$subscription || !is_object($subscription) || !method_exists($subscription, 'get_id')) return null;

        $items = [];
        if (method_exists($subscription, 'get_items')) {
            foreach ($subscription->get_items() as $item) {
                $items[] = [
                    'product_id' => $item->get_product_id(),
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        return [
            'id' => $subscription->get_id(),
            'customer_id' => method_exists($subscription, 'get_customer_id') ? $subscription->get_customer_id() : 0,
            'items' => $items,
        ];
    }
}
