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
 * Migration history: first pass (per the handoff) moved
 * bh-monetization-woo's `BHM_Products::sync_tier_wc_product()` /
 * `sync_object_purchase_product()` onto `upsert_product()`. Second pass
 * (ROADMAP-platform-evolution.md Section 5's own stated prerequisite —
 * Storefront/merchandising needs a FULLY migrated interface to build on,
 * not a partially-migrated one) finished the rest of
 * bh-monetization-woo: the wallet top-up / tip-jar product sync in
 * class-frontend.php now go through `upsert_product()` too, and the
 * order/subscription-lifecycle handlers in class-products.php
 * (`on_order_completed`, `on_order_reversed`, `on_subscription_active`,
 * `on_subscription_ended`) now read orders/subscriptions through
 * `get_order()`/`normalize_subscription()` instead of calling
 * `wc_get_order()`/`$order->get_items()`/`$subscription->get_items()`
 * directly. `BHM_Wallet` and `BHM_Gate` themselves turned out to have no
 * direct WooCommerce coupling to migrate (pure `$wpdb` ledger logic and
 * stateless entitlement checks, respectively) — see class-wallet.php/
 * class-gate.php, unchanged except `BHM_Gate::handle_tier_downgrade()`'s
 * one `class_exists('WC_Subscriptions_Switcher')` check, now routed
 * through `has_subscription_switching()` for the same reason.
 *
 * A non-WooCommerce implementation later is a matter of writing a new
 * class with the same static contract and swapping which one the
 * consuming plugins call — this class's job today is to BE that
 * contract, backed by WooCommerce, not to anticipate what a future
 * backend looks like.
 */
class BH_Commerce {
    public static function available() {
        return class_exists('WooCommerce');
    }

    public static function has_subscriptions() {
        return class_exists('WC_Subscriptions') && class_exists('WC_Product_Subscription');
    }

    /**
     * Create or update a product, returning its ID (0 on failure / WooCommerce
     * not active). $args:
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
    public static function upsert_product($existing_id, array $args) {
        if (!self::available()) return 0;

        $name = (string) ($args['name'] ?? '');
        $price_cents = (int) ($args['price_cents'] ?? 0);
        $virtual = array_key_exists('virtual', $args) ? (bool) $args['virtual'] : true;
        $downloadable = (bool) ($args['downloadable'] ?? false);
        $catalog_visibility = (string) ($args['catalog_visibility'] ?? 'hidden');
        $want_subscription = (bool) ($args['subscription'] ?? false);
        $use_subscription = $want_subscription && self::has_subscriptions();

        $product = $existing_id ? wc_get_product((int) $existing_id) : null;
        if (!$product) {
            // Same degrade-to-Simple-if-Subscriptions-isn't-really-there
            // safety this replaced code already had: a class_exists()
            // pass doesn't guarantee the product class is safe to
            // instantiate (partial install/upgrade), so re-check
            // has_subscriptions() here rather than trusting $use_subscription
            // alone if $existing_id's product type doesn't match what
            // was asked for.
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
                // 0 is WC Subscriptions' own "no trial" value — always set
                // explicitly (not just when > 0) so turning a trial back
                // off actually clears a previously-set one instead of
                // leaving it stuck.
                'trial_length' => $trial_length,
                'trial_period' => (string) ($args['trial_period'] ?? 'day'),
            ]);
        }
        $product->save();

        return $product->get_id();
    }

    public static function get_product($product_id) {
        if (!self::available()) return null;
        $product = wc_get_product((int) $product_id);
        if (!$product) return null;
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price_cents' => (int) round(((float) $product->get_regular_price()) * 100),
            'purchasable' => $product->is_purchasable(),
        ];
    }

    public static function product_exists($product_id) {
        return self::available() && (bool) wc_get_product((int) $product_id);
    }

    public static function get_edit_url($product_id) {
        return self::available() ? (string) get_edit_post_link((int) $product_id) : '';
    }

    /**
     * Normalizes a WooCommerce order into a plain array so callers never
     * touch a WC_Order object directly. Returns null if unavailable/not found.
     */
    public static function get_order($order_id) {
        if (!self::available() || !function_exists('wc_get_order')) return null;
        $order = wc_get_order((int) $order_id);
        if (!$order) return null;

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'product_id' => $item->get_product_id(),
                'quantity' => $item->get_quantity(),
                // Gifting support (bh-monetization-woo): a line item can
                // carry a recipient email captured at add-to-cart time —
                // passed through generically here (rather than a
                // WooCommerce-specific getter) so this stays a normal
                // field on the interface's own item shape, not a
                // WC_Order_Item leak.
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

    public static function is_subscription_active($subscription_id) {
        if (!self::has_subscriptions() || !function_exists('wcs_get_subscription')) return false;
        $sub = wcs_get_subscription((int) $subscription_id);
        return $sub && $sub->has_status('active');
    }

    // Whether WooCommerce Subscriptions' own switch/proration handling
    // is available — a distinct capability from has_subscriptions()
    // itself (WC_Subscriptions_Switcher is a separate class WC
    // Subscriptions only defines when its product-switching feature is
    // enabled). Added for BHM_Gate::handle_tier_downgrade(), which used
    // to check class_exists('WC_Subscriptions_Switcher') directly —
    // second migration pass, same "wrap the feature-detection behind
    // this interface too, not just product CRUD" treatment.
    public static function has_subscription_switching() {
        return class_exists('WC_Subscriptions_Switcher');
    }

    /**
     * Normalizes a WooCommerce Subscription into the same plain-array
     * shape get_order() returns — id/customer_id/items[{product_id,quantity}]
     * — so callers never touch a WC_Subscription object directly. Unlike
     * get_order(), this takes the object itself rather than an ID: it
     * arrives as the actual object via WooCommerce Subscriptions' own
     * action hooks (woocommerce_subscription_status_active/cancelled/
     * expired), so there's no ID-based lookup to wrap — this is purely
     * about not letting callers reach into the object's own methods
     * beyond this one conversion point.
     */
    public static function normalize_subscription($subscription) {
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
