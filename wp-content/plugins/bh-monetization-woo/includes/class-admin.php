<?php
if (!defined('ABSPATH')) exit;

/**
 * Monetization settings screen (exists even without WooCommerce).
 */
class BHM_Admin {
    public static function init(): void {
        add_action('admin_post_bhm_save_settings', [self::class, 'save_settings']);
    }

    public static function render(): void {
        $has_wc = BH_Commerce::available();
        $has_subs = BH_Commerce::has_subscriptions();
        echo ('<div class="wrap"><h1>' . BHM_Words::get('admin.admin.monetization_settings') . '</h1>');

        if (!$has_wc) {
            echo ('<div class="notice notice-warning"><p><strong>' . BHM_Words::get('admin.admin.woocommerce_isn_t_installed_yet') . '</strong> ' . BHM_Words::get('admin.admin.every_monetization_feature_tiers_purchas') . '</p></div>');
        } else {
            echo ('<p>' . BHM_Words::get('admin.admin.woocommerce_active') . ' ') . ($has_subs
                ? BHM_Words::get('admin.admin.woocommerce_subscriptions_also_active_su')
                : ('<strong>' . BHM_Words::get('admin.admin.woocommerce_subscriptions_isn_t_active') . '</strong> ' . BHM_Words::get('admin.admin.supporter_tiers_will_sell_as'))
            ) . '</p>';

            self::render_get_paid_card();

            $topup_options = get_option('bhm_wallet_topup_options', [500 => 5.00, 1000 => 10.00, 2500 => 25.00]);
            echo ('<h2>' . BHM_Words::get('admin.admin.pay_per_play_wallet_top') . '</h2>');
            echo '<p class="description">The fixed top-up amounts fans see when adding play credit. Stored as cents-of-credit → USD price (usually 1:1, i.e. $5 buys 500 cents / $5.00 of play credit — a discount tier is just a price lower than the cents value).</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bhm_save_settings', 'bhm_settings_nonce');
            echo '<input type="hidden" name="action" value="bhm_save_settings">';
            echo '<table class="form-table"><tbody>';
            foreach ($topup_options as $cents => $price) {
                echo '<tr><td>' . esc_html(BHM_Money::display($cents)) . ' credit</td><td><input type="number" step="0.01" name="bhm_topup_price[' . esc_attr($cents) . ']" value="' . esc_attr($price) . '"></td></tr>';
            }
            echo '</tbody></table>';
            echo ('<p><button type="submit" class="button button-primary">' . BHM_Words::get('admin.admin.save') . '</button></p>');
            echo '</form>';

            $tiers_page = get_option('bhm_tiers_page_id', 0);
            echo ('<h2>' . BHM_Words::get('admin.admin.supporter_tiers_page') . '</h2>');
            echo ('<p class="description">' . BHM_Words::get('admin.admin.add_bhm_tiers_shortcode_page') . '</p>');
            if ($tiers_page && get_post($tiers_page)) {
                echo '<p><a href="' . esc_url(get_edit_post_link($tiers_page)) . '">' . esc_html(get_the_title($tiers_page)) . '</a> — <a href="' . esc_url(get_permalink($tiers_page)) . '" target="_blank">view</a></p>';
            } else {
                echo '<p><em>Not set yet.</em></p>';
            }

            // Without this page, gift claim links fall back to the homepage.
            $gift_redeem_page = get_option('bhm_gift_redeem_page_id', 0);
            echo ('<h2>' . BHM_Words::get('admin.admin.gift_redemption_page') . '</h2>');
            echo ('<p class="description">' . BHM_Words::get('admin.admin.add_bhm_redeem_gift_shortcode') . '</p>');
            if ($gift_redeem_page && get_post($gift_redeem_page)) {
                echo '<p><a href="' . esc_url(get_edit_post_link($gift_redeem_page)) . '">' . esc_html(get_the_title($gift_redeem_page)) . '</a> — <a href="' . esc_url(get_permalink($gift_redeem_page)) . '" target="_blank">view</a></p>';
            } else {
                echo ('<p><em>' . BHM_Words::get('admin.admin.not_set_yet_gift_claim') . '</em></p>');
            }

            self::render_gift_status();
        }
        echo '</div>';
    }

    // Gift status for admins; claim links are shown only in Debug Tools.
    private static function render_gift_status(): void {
        if (!class_exists('BHM_Gifts')) return;
        global $wpdb;
        $t = BHM_Tables::gift_redemptions();
        $recent = $wpdb->get_results("SELECT recipient_email, status, created_at, redeemed_at FROM $t ORDER BY id DESC LIMIT 20", ARRAY_A);

        echo ('<h2>' . BHM_Words::get('admin.admin.recent_gift_redemptions') . '</h2>');
        if (!$recent) {
            echo ('<p class="description">' . BHM_Words::get('admin.admin.no_gifts_purchased_yet') . '</p>');
            return;
        }
        echo ('<div class="bhy-table-wrap" style="max-width:760px;"><table class="wp-list-table widefat striped"><thead><tr><th>' . BHM_Words::get('admin.admin.recipient') . '</th><th>' . BHM_Words::get('admin.admin.status') . '</th><th>' . BHM_Words::get('admin.admin.purchased') . '</th><th>' . BHM_Words::get('admin.admin.claimed') . '</th></tr></thead><tbody>');
        foreach ($recent as $row) {
            echo '<tr><td>' . esc_html($row['recipient_email']) . '</td><td>' . esc_html(ucfirst($row['status'])) . '</td>'
               . '<td>' . esc_html(mysql2date('M j, Y', $row['created_at'])) . '</td>'
               . '<td>' . ($row['redeemed_at'] ? esc_html(mysql2date('M j, Y', $row['redeemed_at'])) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Live "is any payment gateway enabled" check, linking to WooCommerce's own payments setup. */
    private static function render_get_paid_card(): void {
        $enabled = BH_Commerce::get_available_payment_gateways();
        $payments_url = admin_url('admin.php?page=wc-settings&tab=checkout');

        echo '<div class="bhy-alert ' . ($enabled ? 'bhy-alert-success' : 'bhy-alert-danger') . '" style="max-width:760px;">';
        if ($enabled) {
            $names = implode(', ', array_map(fn($g) => $g->get_title(), $enabled));
            echo ('<p><strong>&#9989; Ready to get paid.</strong> ' . BHM_Words::get('admin.admin.active_payment_method')) . (count($enabled) === 1 ? '' : 's') . ': ' . esc_html($names) . '.</p>';
            echo '<p><a class="button" href="' . esc_url($payments_url) . ('">' . BHM_Words::get('admin.admin.manage_payment_methods') . '</a></p>');
        } else {
            echo ('<p><strong>&#10060; No payment method is enabled yet.</strong> ' . BHM_Words::get('admin.admin.tiers_purchases_can_created_but') . '</p>');
            echo '<p><a class="button button-primary" href="' . esc_url($payments_url) . ('">Set up a payment method &rarr;</a> <span class="description">' . BHM_Words::get('admin.admin.opens_woocommerce_s_own_guided') . '</span></p>');
        }
        echo '</div>';
    }

    public static function save_settings(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['bhm_settings_nonce'] ?? '', 'bhm_save_settings')) {
            wp_die('Not allowed.');
        }
        $prices = $_POST['bhm_topup_price'] ?? [];
        $out = [];
        if (is_array($prices)) {
            foreach ($prices as $cents => $price) {
                $out[(int) $cents] = (float) $price;
            }
        }
        update_option('bhm_wallet_topup_options', $out);
        BHM_Frontend::sync_wallet_topup_products();
        wp_safe_redirect(admin_url('admin.php?page=bhm-settings'));
        exit;
    }
}
