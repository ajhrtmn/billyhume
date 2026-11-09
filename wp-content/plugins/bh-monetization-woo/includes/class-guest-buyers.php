<?php
if (!defined('ABSPATH')) exit;

/**
 * Access lives on an account, so a purchase must end up on one. Checkout requires an
 * account when the cart grants access (tiers, courses, releases, tracks), and as a safety
 * net a completed order without a customer is attached to the account with its billing
 * email, or a new account (with a set-password email) before fulfilment runs.
 */
class BHM_GuestBuyers {
    public static function init(): void {
        add_filter('woocommerce_checkout_registration_required', [self::class, 'require_account']);
        add_filter('woocommerce_checkout_registration_enabled', [self::class, 'enable_registration']);
        // Priority 1: before BHM_Downloads (5) and BHM_Entitlements (10) read the customer.
        add_action('woocommerce_order_status_completed', [self::class, 'attach_account'], 1);
        // Both the classic and the block order-confirmation page print this text.
        add_filter('woocommerce_thankyou_order_received_text', [self::class, 'receipt_next_step'], 20, 2);
    }

    /**
     * A logged-in buyer is redirected to their account; someone who checked out signed out
     * is told where their purchase is and how to get in, instead of a bare "thank you".
     * @param mixed $text
     * @param mixed $order
     */
    public static function receipt_next_step($text, $order = null): string {
        $text = (string) $text;
        if (is_user_logged_in()) return $text;
        // WHY: once attach_account() puts the order on an account, WooCommerce hides it from a
        // signed-out viewer and passes no order; recognise it from the receipt link's own key.
        if (!$order instanceof \WC_Order) {
            $order_id = absint(get_query_var('order-received'));
            $candidate = $order_id ? wc_get_order($order_id) : null;
            $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            $order = ($candidate && $key !== '' && hash_equals($candidate->get_order_key(), $key)) ? $candidate : null;
        }
        if (!$order instanceof \WC_Order) return $text;
        $grants = false;
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product && self::product_grants_access((int) $item->get_product_id())) { $grants = true; break; }
        }
        if (!$grants) return $text;
        $account = class_exists('BHI_Portal') ? home_url('/' . BHI_Portal::REWRITE_SLUG . '/') : wp_login_url();
        $email = $order->get_billing_email();
        return $text . ' <strong>Your purchase is in your account.</strong> '
            . 'Log in as ' . esc_html($email) . ' to start. If this is your first purchase, we\'ve emailed you a link to set your password. '
            . '<a href="' . esc_url($account) . '">Go to your account &rarr;</a>';
    }

    public static function product_grants_access(int $product_id): bool {
        return (bool) (get_post_meta($product_id, '_bhm_tier_id', true) || get_post_meta($product_id, '_bhm_purchase_object_id', true));
    }

    private static function cart_grants_access(): bool {
        if (!function_exists('WC') || !WC()->cart) return false;
        foreach (WC()->cart->get_cart() as $item) {
            if (self::product_grants_access((int) ($item['product_id'] ?? 0))) return true;
        }
        return false;
    }

    /** @param mixed $required */
    public static function require_account($required): bool {
        return (bool) $required || (!is_user_logged_in() && self::cart_grants_access());
    }

    /** @param mixed $enabled */
    public static function enable_registration($enabled): bool {
        return (bool) $enabled || self::cart_grants_access();
    }

    public static function attach_account(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id()) return;
        $grants = false;
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product && self::product_grants_access((int) $item->get_product_id())) { $grants = true; break; }
        }
        $email = sanitize_email($order->get_billing_email());
        if (!$grants || !is_email($email)) return;

        $user = get_user_by('email', $email);
        $user_id = $user ? (int) $user->ID : self::create_member($email, $order->get_billing_first_name(), $order->get_billing_last_name());
        if (is_wp_error($user_id) || !$user_id) {
            if (class_exists('OUS_DebugLog')) OUS_DebugLog::log('error', "Guest order $order_id could not be given an account; access was not granted.", ['order_id' => $order_id], 'BH Monetization');
            return;
        }
        $order->set_customer_id($user_id);
        $order->add_order_note($user ? 'Guest purchase attached to the existing account for ' . $email . '.' : 'Guest purchase: created an account for ' . $email . ' and sent a set-password email.');
        $order->save();
    }

    /**
     * A member account for a buyer or gift recipient, with WordPress's set-password email.
     * @return int|WP_Error
     */
    public static function create_member(string $email, string $first_name = "", string $last_name = "") {
        $base = sanitize_user(strtok($email, "@"), true) ?: "member";
        $login = $base;
        for ($i = 2; username_exists($login); $i++) $login = $base . $i;
        $user_id = wp_insert_user([
            "user_login" => $login, "user_email" => $email, "user_pass" => wp_generate_password(32),
            "first_name" => $first_name, "last_name" => $last_name,
            "role" => get_role("bh_member") ? "bh_member" : get_option("default_role", "subscriber"),
        ]);
        if (is_wp_error($user_id)) return $user_id;
        wp_new_user_notification($user_id, null, "user");
        return (int) $user_id;
    }
}
