<?php
if (!defined('ABSPATH')) exit;

/**
 * Gift memberships — the one item ROADMAP-platform-evolution.md Section 4
 * left genuinely open besides promo/discount codes (which already work
 * today via WooCommerce's own native checkout coupon field, nothing here
 * disables it). "Buy a tier on someone else's behalf" is a real product
 * flow, not a WooCommerce-native feature: recipient email capture at
 * add-to-cart, a redemption code instead of an immediate grant, and a
 * claim flow the recipient completes on their own account (signing up
 * first if they don't have one).
 *
 * Deliberately does NOT touch BHM_Tiers' own product/entitlement model —
 * a gift purchase still buys the SAME tier product every other purchase
 * does (so pricing/tax/subscription behavior stay identical), it just
 * gets intercepted at the entitlement-granting step (class-products.php's
 * on_order_completed()) and redirected into a redemption code instead of
 * granting the buyer immediate access.
 */
class BHM_Gifts {
    const TABLE = 'bhm_gift_redemptions';

    public static function init() {
        add_filter('woocommerce_add_cart_item_data', [self::class, 'capture_gift_email'], 10, 2);
        add_filter('woocommerce_get_item_data', [self::class, 'show_gift_email_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'persist_gift_email_to_order_item'], 10, 4);
        add_shortcode('bhm_redeem_gift', [self::class, 'render_redeem_form']);
        add_action('admin_post_bhm_redeem_gift', [self::class, 'handle_redeem']);
        add_action('admin_post_nopriv_bhm_redeem_gift', [self::class, 'handle_redeem']);
    }

    // The tier picker's "Gift this" link appends these two query args to
    // the ordinary add-to-cart URL (see class-frontend.php's
    // render_tiers()) — captured here into the cart item's own data
    // array, the standard WooCommerce extension point for exactly this
    // ("carry custom data alongside a cart line item").
    public static function capture_gift_email($cart_item_data, $product_id) {
        if (!empty($_REQUEST['bhm_gift']) && !empty($_REQUEST['bhm_gift_email'])) {
            $email = sanitize_email(wp_unslash($_REQUEST['bhm_gift_email']));
            if (is_email($email)) {
                $cart_item_data['bhm_gift_email'] = $email;
                // WooCommerce merges identical cart items (same product,
                // same data) into one line with a quantity — without a
                // unique-ish key here, gifting the SAME tier to two
                // different people in one cart would silently merge into
                // a single line item carrying only the last email typed.
                $cart_item_data['unique_key'] = md5(microtime() . wp_rand());
            }
        }
        return $cart_item_data;
    }

    public static function show_gift_email_in_cart($item_data, $cart_item) {
        if (!empty($cart_item['bhm_gift_email'])) {
            $item_data[] = ['name' => 'Gift for', 'value' => esc_html($cart_item['bhm_gift_email'])];
        }
        return $item_data;
    }

    public static function persist_gift_email_to_order_item($item, $cart_item_key, $values, $order) {
        if (!empty($values['bhm_gift_email'])) {
            $item->add_meta_data('_bhm_gift_email', $values['bhm_gift_email'], true);
        }
    }

    // Called from class-products.php's on_order_completed() INSTEAD of
    // grant_entitlement() when the paid line item carries a gift email —
    // the buyer never gets the entitlement themselves, only the
    // recipient does, once they claim it.
    public static function create_redemption($tier_id, $buyer_user_id, $order_id, $recipient_email) {
        global $wpdb;
        $code = wp_generate_password(20, false, false);
        $wpdb->insert($wpdb->prefix . self::TABLE, [
            'code' => $code,
            'tier_id' => (int) $tier_id,
            'buyer_user_id' => (int) $buyer_user_id,
            'recipient_email' => $recipient_email,
            'wc_order_id' => (int) $order_id,
            'status' => 'pending',
        ]);

        $tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($tier_id) : null;
        $tier_name = $tier ? $tier['name'] : 'a supporter tier';
        $buyer = get_userdata($buyer_user_id);
        $buyer_name = $buyer ? $buyer->display_name : 'Someone';
        $claim_url = add_query_arg('gift_code', $code, self::redeem_page_url());

        $subject = $buyer_name . ' sent you a gift membership!';
        $body = $buyer_name . " signed you up for \"$tier_name\" — click below to claim it:\n\n$claim_url\n\nIf you don't already have an account, you'll be able to create one on that page first.";
        $sent = wp_mail($recipient_email, $subject, $body);
        if (!$sent && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', 'Gift redemption email failed to send (wp_mail() returned false).', [
                'recipient_email' => $recipient_email, 'tier_id' => $tier_id, 'order_id' => $order_id, 'code' => $code,
            ], 'BH Monetization');
        }

        return $code;
    }

    // Same "an admin can dedicate a real page to this" pattern
    // BHM_Tiers::tiers_page_url() already uses — falls back to the
    // homepage (with the shortcode nowhere to render it, admittedly a
    // dead end) only until an admin actually creates a page containing
    // [bhm_redeem_gift] and points this option at it.
    public static function redeem_page_url() {
        $page_id = (int) get_option('bhm_gift_redeem_page_id', 0);
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    public static function render_redeem_form() {
        $code = isset($_GET['gift_code']) ? sanitize_text_field(wp_unslash($_GET['gift_code'])) : '';
        if (!$code) return '<p>No gift code provided.</p>';

        global $wpdb;
        $t = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE code = %s", $code), ARRAY_A);
        if (!$row) return '<p>That gift code isn\'t valid.</p>';
        if ($row['status'] === 'redeemed') return '<p>This gift has already been claimed.</p>';

        $tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($row['tier_id']) : null;
        $tier_name = $tier ? esc_html($tier['name']) : 'a supporter tier';

        ob_start();
        echo '<div class="bhm-gift-claim">';
        echo '<h2>You\'ve been gifted "' . $tier_name . '"!</h2>';

        if (!is_user_logged_in()) {
            echo '<p>Log in or create an account to claim it — your gift will still be here once you do.</p>';
            echo '<p><a class="bhm-btn" href="' . esc_url(wp_login_url(add_query_arg('gift_code', $code, self::redeem_page_url()))) . '">Log in</a></p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="bhm_redeem_gift">';
            echo '<input type="hidden" name="gift_code" value="' . esc_attr($code) . '">';
            wp_nonce_field('bhm_redeem_gift_' . $code, 'bhm_redeem_nonce');
            echo '<button type="submit" class="bhm-btn">Claim this gift</button>';
            echo '</form>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function handle_redeem() {
        $code = isset($_POST['gift_code']) ? sanitize_text_field(wp_unslash($_POST['gift_code'])) : '';
        if (!$code || !isset($_POST['bhm_redeem_nonce']) || !wp_verify_nonce($_POST['bhm_redeem_nonce'], 'bhm_redeem_gift_' . $code)) {
            wp_die('Invalid request.', 400);
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg('gift_code', $code, self::redeem_page_url())));
            exit;
        }

        global $wpdb;
        $t = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE code = %s", $code), ARRAY_A);
        if (!$row || $row['status'] === 'redeemed') {
            wp_die('That gift is no longer available to claim.', 400);
        }

        $user_id = get_current_user_id();
        // Same grant shape a real tier purchase gets (class-products.php's
        // on_order_completed()) — streaming_tier, 30-day expiry, since a
        // gift is a one-time purchase, never a recurring subscription
        // (recurring billing would need the RECIPIENT's own payment
        // method, which this flow was never designed to collect).
        BHM_Products::grant_gift_entitlement($user_id, (int) $row['tier_id'], (int) $row['wc_order_id']);

        $wpdb->update($t, [
            'status' => 'redeemed', 'redeemed_by_user_id' => $user_id, 'redeemed_at' => current_time('mysql'),
        ], ['id' => $row['id']]);

        wp_safe_redirect(class_exists('BHM_Tiers') ? BHM_Tiers::tiers_page_url() : home_url('/'));
        exit;
    }
}
