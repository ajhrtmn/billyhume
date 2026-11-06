<?php
if (!defined('ABSPATH')) exit;

/**
 * Referrals: each referrer's code is a real percent-off WC_Coupon; a completed order using it
 * credits the referrer's wallet and records a redemption row.
 */
class BHM_Referrals {
    const DISCOUNT_PERCENT = 10;  // what the customer saves by using the code
    const COMMISSION_PERCENT = 10; // what the referrer earns, of the order's actual (post-discount) total

    public static function init(): void {
        // Priority 20: after entitlements (priority 10) are granted.
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed'], 20);
    }

    public static function code_for_user(int $user_id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM " . BHM_Tables::referral_codes() . " WHERE user_id = %d", $user_id
        ));
    }

    // Created lazily, the first time a referrer views their referral section.
    public static function get_or_create_code(int $user_id): string {
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

        $inserted = $wpdb->insert(BHM_Tables::referral_codes(), ['user_id' => $user_id, 'code' => $code]);
        if ($inserted === false) return '';
        return $code;
    }

    private static function generate_unique_code(): string {
        for ($i = 0; $i < 5; $i++) {
            $code = strtoupper(substr(wp_generate_password(8, false, false), 0, 6));
            if (!self::code_taken($code)) return $code;
        }
        return '';
    }

    private static function code_taken(string $code): bool {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM " . BHM_Tables::referral_codes() . " WHERE code = %s", $code
        ));
        return (bool) $exists || (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code));
    }

    public static function calculate_commission_cents(float $order_total_dollars): int {
        return (int) round(((float) $order_total_dollars) * 100 * (self::COMMISSION_PERCENT / 100));
    }

    // WHY: try/catch because WooCommerce wraps all callbacks of a hook in one try/catch.
    public static function on_order_completed(int $order_id): void {
        try {
            self::do_on_order_completed($order_id);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Referral commission crediting failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id], 'BH Monetization');
            }
        }
    }

    private static function do_on_order_completed(int $order_id): void {
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
                "SELECT user_id FROM " . BHM_Tables::referral_codes() . " WHERE code = %s", $candidate
            ));
            if ($owner) { $referrer_user_id = (int) $owner; $matched_code = $candidate; break; }
        }
        if (!$referrer_user_id) return;

        $customer_user_id = (int) $order->get_customer_id();
        // No commission on your own purchase.
        if ($customer_user_id && $customer_user_id === $referrer_user_id) return;

        $commission_cents = self::calculate_commission_cents($order->get_total());

        // WHY: the INSERT is the atomic claim (UNIQUE wc_order_id), so a repeat call never credits twice.
        // Errors suppressed because the duplicate-key failure is the expected outcome.
        $suppress_state = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(BHM_Tables::referrals(), [
            'code' => $matched_code,
            'referrer_user_id' => $referrer_user_id,
            'customer_user_id' => $customer_user_id,
            'wc_order_id' => $order_id,
            'commission_cents' => $commission_cents,
        ]);
        $wpdb->suppress_errors($suppress_state);
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

    /** @return array{redemptions:int, total_cents:int} */
    public static function stats_for_referrer(int $user_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS redemptions, COALESCE(SUM(commission_cents), 0) AS total_cents
             FROM " . BHM_Tables::referrals() . " WHERE referrer_user_id = %d",
            $user_id
        ), ARRAY_A);
        return [
            'redemptions' => $row ? (int) $row['redemptions'] : 0,
            'total_cents' => $row ? (int) $row['total_cents'] : 0,
        ];
    }

    // A section of the portal's money panel (BHM_PortalPanel::render()).
    public static function render_section(int $user_id): void {
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
