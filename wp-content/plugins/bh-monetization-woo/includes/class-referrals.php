<?php
if (!defined('ABSPATH')) exit;

/**
 * Ecosystem depth-pass Tier 2 — referral/affiliate tracking. Piggybacks
 * on WooCommerce's already-working coupon support (confirmed elsewhere
 * in this plugin — promo codes work via native WC coupons already)
 * rather than inventing a second discount mechanism: a referrer's code
 * IS a real percent-off WC_Coupon, auto-created the first time they view
 * their own referral section, so sharing it gives the person they send
 * an actual, immediate discount. The new part this class adds on top is
 * attribution (a code tied to a specific person, not just a generic
 * coupon anyone could have made) and a revenue rollup: when an order
 * using that code completes, the referrer gets a wallet credit and a
 * permanent redemption row.
 */
class BHM_Referrals {
    const DISCOUNT_PERCENT = 10;  // what the customer saves by using the code
    const COMMISSION_PERCENT = 10; // what the referrer earns, of the order's actual (post-discount) total

    public static function init() {
        // Priority 20 — after BHM_Entitlements' own woocommerce_order_status_completed
        // handler (default priority 10, class-entitlements.php:15) has
        // already run, so a referral credit is never the FIRST thing to
        // touch a freshly-completed order.
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed'], 20);
    }

    public static function code_for_user($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}bhm_referral_codes WHERE user_id = %d", $user_id
        ));
    }

    // Lazy-provisioned: no row/coupon exists until a referrer actually
    // looks at their own referral section for the first time — nothing
    // to clean up for the overwhelming majority of accounts that never
    // do.
    public static function get_or_create_code($user_id) {
        $existing = self::code_for_user($user_id);
        if ($existing) return $existing;
        if (!BH_Commerce::available()) return '';

        global $wpdb;
        $code = self::generate_unique_code();
        if (!$code) return '';

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(self::DISCOUNT_PERCENT);
        $coupon->set_individual_use(false);
        $coupon->save();

        $inserted = $wpdb->insert($wpdb->prefix . 'bhm_referral_codes', ['user_id' => $user_id, 'code' => $code]);
        if ($inserted === false) return '';
        return $code;
    }

    private static function generate_unique_code() {
        for ($i = 0; $i < 5; $i++) {
            $code = strtoupper(substr(wp_generate_password(8, false, false), 0, 6));
            if (!self::code_taken($code)) return $code;
        }
        return '';
    }

    private static function code_taken($code) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bhm_referral_codes WHERE code = %s", $code
        ));
        return (bool) $exists || (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code));
    }

    public static function calculate_commission_cents($order_total_dollars) {
        return (int) round(((float) $order_total_dollars) * 100 * (self::COMMISSION_PERCENT / 100));
    }

    public static function on_order_completed($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $used_codes = $order->get_coupon_codes();
        if (!$used_codes) return;

        global $wpdb;
        $referrer_user_id = 0;
        $matched_code = '';
        foreach ($used_codes as $used_code) {
            $candidate = strtoupper($used_code);
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}bhm_referral_codes WHERE code = %s", $candidate
            ));
            if ($owner) { $referrer_user_id = (int) $owner; $matched_code = $candidate; break; }
        }
        if (!$referrer_user_id) return; // no coupon on this order was a referral code

        $customer_user_id = (int) $order->get_customer_id();
        // Self-referral guard — a referrer using their own code shouldn't
        // be able to pay themselves a commission on their own purchase.
        if ($customer_user_id && $customer_user_id === $referrer_user_id) return;

        $commission_cents = self::calculate_commission_cents($order->get_total());

        // The INSERT is the atomic claim, not a prior SELECT — the
        // UNIQUE KEY on wc_order_id means a second call for the same
        // order (this hook firing twice, or a race) fails here and never
        // reaches the wallet credit below, same idempotency pattern as
        // bhcore_notifications.email_sent elsewhere in this ecosystem.
        $inserted = $wpdb->insert($wpdb->prefix . 'bhm_referrals', [
            'code' => $matched_code,
            'referrer_user_id' => $referrer_user_id,
            'customer_user_id' => $customer_user_id,
            'wc_order_id' => $order_id,
            'commission_cents' => $commission_cents,
        ]);
        if ($inserted === false) return; // already credited for this order

        if (class_exists('BHM_Wallet')) {
            BHM_Wallet::credit($referrer_user_id, $commission_cents, 'referral', null, $order_id);
        }
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhm/referral_credited', [
                'user_id' => $referrer_user_id, 'subject_type' => 'bhm_referral', 'subject_id' => $order_id,
                'payload' => ['code' => $matched_code, 'commission_cents' => $commission_cents, 'customer_user_id' => $customer_user_id],
            ]);
        }
    }

    public static function stats_for_referrer($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS redemptions, COALESCE(SUM(commission_cents), 0) AS total_cents
             FROM {$wpdb->prefix}bhm_referrals WHERE referrer_user_id = %d",
            $user_id
        ), ARRAY_A);
        return [
            'redemptions' => $row ? (int) $row['redemptions'] : 0,
            'total_cents' => $row ? (int) $row['total_cents'] : 0,
        ];
    }

    // Called from BHM_PortalPanel::render() (class-portal-panel.php) —
    // same class_exists()-gated cross-plugin call shape bh-courses'
    // portal panel uses for its own achievements section, rather than
    // this class registering an entirely separate panel for what's
    // really one more section of the same "money" panel.
    public static function render_section($user_id) {
        if (!BH_Commerce::available()) return;
        $code = self::get_or_create_code($user_id);
        if (!$code) return;

        $stats = self::stats_for_referrer($user_id);
        echo '<div class="bhi-portal-section">';
        echo '<h2>Referrals</h2>';
        echo '<p>Share your code — anyone who uses it gets ' . (int) self::DISCOUNT_PERCENT . '% off, and you earn ' . (int) self::COMMISSION_PERCENT . '% of what they spend as wallet credit.</p>';
        echo '<p class="bhi-referral-code">' . esc_html($code) . '</p>';
        if ($stats['redemptions'] > 0) {
            echo '<p>' . (int) $stats['redemptions'] . ' redemption' . ($stats['redemptions'] === 1 ? '' : 's') . ' — $' . esc_html(BHM_Money::display($stats['total_cents'])) . ' earned.</p>';
        }
        echo '</div>';
    }
}
