<?php
if (!defined('ABSPATH')) exit;

/**
 * "Apply automatically" on WooCommerce coupons: applied to any cart the coupon is valid for,
 * so launch offers like "first 10 buyers get 30% off" are a normal coupon with a usage limit.
 * WooCommerce's own rules (usage limits, products, expiry, minimums) decide validity.
 */
class BHM_AutoCoupons {
    const META = '_bhm_auto_apply';
    const CACHE = 'bhm_auto_coupon_codes';
    const REMOVED = 'bhm_auto_coupons_removed';

    public static function init(): void {
        add_action('woocommerce_coupon_options', [self::class, 'render_option'], 10, 2);
        add_action('woocommerce_coupon_options_save', [self::class, 'save_option'], 10, 2);
        add_action('woocommerce_cart_loaded_from_session', [self::class, 'apply']);
        add_action('woocommerce_add_to_cart', [self::class, 'apply_after_add'], 20);
        add_action('woocommerce_removed_coupon', [self::class, 'remember_removed']);
        add_action('deleted_post', [self::class, 'flush']);
        add_action('trashed_post', [self::class, 'flush']);
        // Lets other plugins advertise the offer (e.g. a course sales page) without knowing about coupons.
        add_filter('bhm_product_offer', [self::class, 'offer_filter'], 10, 2);
    }

    /**
     * @param mixed $offer
     * @return array{price: float, regular: float, remaining: ?int}|mixed
     */
    public static function offer_filter($offer, int $product_id) {
        return $offer ?: self::offer_for_product($product_id);
    }

    /**
     * The best automatic offer a lone cart of this product would get, judged by the coupon's
     * own rules (expiry, usage left, product lists, minimum spend).
     * @return array{price: float, regular: float, remaining: ?int}|null
     */
    public static function offer_for_product(int $product_id): ?array {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) return null;
        $regular = (float) $product->get_price();
        $best = null;
        foreach (self::codes() as $code) {
            $c = new \WC_Coupon($code);
            if (!$c->get_id()) continue;
            $expires = $c->get_date_expires();
            if ($expires && $expires->getTimestamp() < time()) continue;
            $limit = (int) $c->get_usage_limit();
            if ($limit && $c->get_usage_count() >= $limit) continue;
            $only = array_map('intval', $c->get_product_ids());
            if (($only && !in_array($product_id, $only, true)) || in_array($product_id, array_map('intval', $c->get_excluded_product_ids()), true)) continue;
            if ((float) $c->get_minimum_amount() > $regular) continue;
            $amount = (float) $c->get_amount();
            $price = match ($c->get_discount_type()) {
                'percent' => $regular * (1 - min(100, $amount) / 100),
                'fixed_product', 'fixed_cart' => max(0, $regular - $amount),
                default => null,
            };
            if ($price === null || ($best && $price >= $best['price'])) continue;
            $best = ['price' => round($price, 2), 'regular' => $regular, 'remaining' => $limit ? $limit - (int) $c->get_usage_count() : null];
        }
        return ($best && $best['price'] < $regular) ? $best : null;
    }

    /** @param mixed $coupon */
    public static function render_option(int $coupon_id, $coupon = null): void {
        if (!function_exists('woocommerce_wp_checkbox')) return;
        woocommerce_wp_checkbox([
            'id' => self::META,
            'label' => 'Apply automatically',
            'description' => 'Added to every cart this coupon is valid for. Use the usage limit for offers like "first 10 buyers".',
            'value' => get_post_meta($coupon_id, self::META, true) === 'yes' ? 'yes' : 'no',
        ]);
    }

    // Runs inside WooCommerce's own coupon save, after its nonce and capability checks.
    /** @param mixed $coupon */
    public static function save_option(int $coupon_id, $coupon = null): void {
        update_post_meta($coupon_id, self::META, !empty($_POST[self::META]) ? 'yes' : 'no');
        self::flush();
    }

    public static function flush(): void {
        delete_transient(self::CACHE);
    }

    /** @return string[] */
    private static function codes(): array {
        $codes = get_transient(self::CACHE);
        if (is_array($codes)) return $codes;
        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 20, 'fields' => 'ids',
            'meta_key' => self::META, 'meta_value' => 'yes']);
        $codes = array_values(array_filter(array_map(fn($id) => wc_format_coupon_code(get_the_title($id)), $ids)));
        set_transient(self::CACHE, $codes, HOUR_IN_SECONDS);
        return $codes;
    }

    public static function apply_after_add(): void {
        self::apply(WC()->cart);
    }

    /** @param mixed $cart */
    public static function apply($cart = null): void {
        if (!$cart instanceof \WC_Cart || $cart->is_empty() || (is_admin() && !wp_doing_ajax())) return;
        $codes = self::codes();
        if (!$codes) return;
        $removed = (WC()->session) ? (array) WC()->session->get(self::REMOVED, []) : [];
        foreach ($codes as $code) {
            if ($cart->has_discount($code) || in_array($code, $removed, true)) continue;
            $coupon = new \WC_Coupon($code);
            // WHY: check validity first so a used-up offer never shows the shopper an error.
            $valid = (new \WC_Discounts($cart))->is_coupon_valid($coupon);
            if ($valid === true) $cart->apply_coupon($code);
        }
    }

    // A shopper who removes an automatic coupon keeps it removed for their session.
    public static function remember_removed(string $code): void {
        if (!WC()->session || !in_array(wc_format_coupon_code($code), self::codes(), true)) return;
        $removed = (array) WC()->session->get(self::REMOVED, []);
        $removed[] = wc_format_coupon_code($code);
        WC()->session->set(self::REMOVED, array_values(array_unique($removed)));
    }
}
