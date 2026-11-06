<?php
if (!defined('ABSPATH')) exit;

/**
 * Gift memberships: the buyer purchases the normal tier product with a recipient email; at
 * fulfilment the grant becomes a redemption code the recipient claims on their own account.
 */
class BHM_Gifts {

    public static function init(): void {
        add_filter('woocommerce_add_cart_item_data', [self::class, 'capture_gift_email'], 10, 2);
        add_filter('woocommerce_get_item_data', [self::class, 'show_gift_email_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'persist_gift_email_to_order_item'], 10, 4);
        add_shortcode('bhm_redeem_gift', [self::class, 'render_redeem_form']);
        add_action('admin_post_bhm_redeem_gift', [self::class, 'handle_redeem']);
        add_action('admin_post_nopriv_bhm_redeem_gift', [self::class, 'handle_redeem']);
    }

    // Captures the tier picker's gift form fields into cart item data.
    /**
     * @param array<string, mixed> $cart_item_data
     * @return array<string, mixed>
     */
    public static function capture_gift_email($cart_item_data, int $product_id): array {
        if (!empty($_REQUEST['bhm_gift']) && !empty($_REQUEST['bhm_gift_email'])) {
            $email = sanitize_email(wp_unslash($_REQUEST['bhm_gift_email']));
            if (is_email($email)) {
                $cart_item_data['bhm_gift_email'] = $email;
                // WHY: unique key stops WooCommerce merging two gifts of the same tier into one line.
                $cart_item_data['unique_key'] = md5(microtime() . wp_rand());
            }
        }
        return $cart_item_data;
    }

    /**
     * @param array<int, mixed> $item_data
     * @param array<string, mixed> $cart_item
     * @return array<int, mixed>
     */
    public static function show_gift_email_in_cart($item_data, $cart_item): array {
        if (!empty($cart_item['bhm_gift_email'])) {
            $item_data[] = ['name' => 'Gift for', 'value' => esc_html($cart_item['bhm_gift_email'])];
        }
        return $item_data;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function persist_gift_email_to_order_item(\WC_Order_Item $item, string $cart_item_key, $values, \WC_Order $order): void {
        if (!empty($values['bhm_gift_email'])) {
            $item->add_meta_data('_bhm_gift_email', $values['bhm_gift_email'], true);
        }
    }

    // Called instead of a grant when a paid line item carries a gift email.
    public static function create_redemption(int $tier_id, int $buyer_user_id, int $order_id, string $recipient_email): string {
        global $wpdb;
        $code = wp_generate_password(20, false, false);
        $wpdb->insert(BHM_Tables::gift_redemptions(), [
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
        if (class_exists('BH_Mail')) {
            BH_Mail::send([
                'to' => $recipient_email, 'subject' => $subject, 'body' => $body, 'source' => 'BH Monetization',
                'log_context' => ['recipient_email' => $recipient_email, 'tier_id' => $tier_id, 'order_id' => $order_id, 'code' => $code],
            ]);
        } else {
            $sent = wp_mail($recipient_email, $subject, $body);
            if (!$sent && class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Gift redemption email failed to send (wp_mail() returned false).', [
                    'recipient_email' => $recipient_email, 'tier_id' => $tier_id, 'order_id' => $order_id, 'code' => $code,
                ], 'BH Monetization');
            }
        }

        return $code;
    }

    // Falls back to the homepage until a [bhm_redeem_gift] page exists.
    public static function redeem_page_url(): string {
        $page_id = (int) get_option('bhm_gift_redeem_page_id', 0);
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    public static function render_redeem_form(): string {
        $code = isset($_GET['gift_code']) ? sanitize_text_field(wp_unslash($_GET['gift_code'])) : '';
        if (!$code) return '<p>No gift code provided.</p>';

        global $wpdb;
        $t = BHM_Tables::gift_redemptions();
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

    public static function handle_redeem(): void {
        $code = isset($_POST['gift_code']) ? sanitize_text_field(wp_unslash($_POST['gift_code'])) : '';
        if (!$code || !isset($_POST['bhm_redeem_nonce']) || !wp_verify_nonce($_POST['bhm_redeem_nonce'], 'bhm_redeem_gift_' . $code)) {
            wp_die('Invalid request.', '', ['response' => 400]);
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg('gift_code', $code, self::redeem_page_url())));
            exit;
        }

        global $wpdb;
        $t = BHM_Tables::gift_redemptions();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE code = %s", $code), ARRAY_A);
        if (!$row || $row['status'] === 'redeemed') {
            wp_die('That gift is no longer available to claim.', '', ['response' => 400]);
        }

        $user_id = get_current_user_id();
        // WHY: claim first with one conditional UPDATE, so two simultaneous claims can't both win;
        // only the request that actually flipped the row grants the entitlement.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status = 'redeemed', redeemed_by_user_id = %d, redeemed_at = %s WHERE id = %d AND status <> 'redeemed'",
            $user_id, current_time('mysql'), (int) $row['id']
        ));
        if ($claimed !== 1) {
            wp_die('That gift is no longer available to claim.', '', ['response' => 409]);
        }

        // One-time streaming_tier grant; a gift is never recurring.
        BHM_Products::grant_gift_entitlement($user_id, (int) $row['tier_id'], (int) $row['wc_order_id']);

        wp_safe_redirect(class_exists('BHM_Tiers') ? BHM_Tiers::tiers_page_url() : home_url('/'));
        exit;
    }
}
