<?php
if (!defined('ABSPATH')) exit;

/**
 * Monetization settings screen (exists even without WooCommerce) and registry entries,
 * including WooCommerce as an install-from-WordPress.org dependency (never bundled).
 */
class BHM_Admin {
    public static function init(): void {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_post_bhm_save_settings', [self::class, 'save_settings']);
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function register($plugins): array {
        $plugins['bh-monetization-woo'] = [
            'label' => 'BH Monetization', 'file' => 'bh-monetization-woo/bh-monetization-woo.php',
            'depends_on' => ['woocommerce'], 'check_class' => 'BHM_Gate',
            'description' => 'Supporter tiers, purchases, tips, and pay-per-play for bh-streaming — backed by WooCommerce, with refund/velocity fraud-pattern flagging.',
            // Must exist at the-self-hosted-self/bundled/ for the dashboard's Install button.
            'bundled_zip' => 'bh-monetization-woo.zip',
            'dashboard_link' => 'admin.php?page=bhm-settings',
            // Under WooCommerce, next to the Tiers CPT menu.
            'admin_menus' => [
                ['slug' => 'bhm-settings', 'label' => 'Monetization Settings', 'callback' => [self::class, 'render'], 'parent' => 'woocommerce'],
            ],
        ];
        // Only if no other plugin already registered it.
        if (!isset($plugins['woocommerce'])) {
            $plugins['woocommerce'] = [
                'label' => 'WooCommerce', 'file' => 'woocommerce/woocommerce.php',
                'wporg_slug' => 'woocommerce', 'check_class' => 'WooCommerce',
                'description' => 'Required for BH Monetization — payments and commerce, not reimplemented here.',
            ];
        }
        return $plugins;
    }

    public static function render(): void {
        $has_wc = BH_Commerce::available();
        $has_subs = BH_Commerce::has_subscriptions();
        echo '<div class="wrap"><h1>Monetization Settings</h1>';

        if (!$has_wc) {
            echo '<div class="notice notice-warning"><p><strong>WooCommerce isn\'t installed yet.</strong> Every monetization feature (tiers, purchases, tips, pay-per-play) stays completely inactive — zero cost, zero UI clutter on your track/release screens — until you install it. Go to <strong>The Self-Hosted Self</strong> and click "Install from WordPress.org" next to WooCommerce.</p></div>';
        } else {
            echo '<p>WooCommerce is active. ' . ($has_subs
                ? 'WooCommerce Subscriptions is also active — supporter tiers bill on a real recurring schedule.'
                : '<strong>WooCommerce Subscriptions isn\'t active</strong> — supporter tiers will sell as one-time, 30-day access instead of automatic recurring billing. Install WooCommerce Subscriptions (a separate, official, paid extension — WooCommerce core has no subscription billing of its own) if you want true recurring tiers.'
            ) . '</p>';

            self::render_get_paid_card();

            $topup_options = get_option('bhm_wallet_topup_options', [500 => 5.00, 1000 => 10.00, 2500 => 25.00]);
            echo '<h2>Pay-per-play wallet top-up amounts</h2>';
            echo '<p class="description">The fixed top-up amounts fans see when adding play credit. Stored as cents-of-credit → USD price (usually 1:1, i.e. $5 buys 500 cents / $5.00 of play credit — a discount tier is just a price lower than the cents value).</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bhm_save_settings', 'bhm_settings_nonce');
            echo '<input type="hidden" name="action" value="bhm_save_settings">';
            echo '<table class="form-table"><tbody>';
            foreach ($topup_options as $cents => $price) {
                echo '<tr><td>' . esc_html(BHM_Money::display($cents)) . ' credit</td><td><input type="number" step="0.01" name="bhm_topup_price[' . esc_attr($cents) . ']" value="' . esc_attr($price) . '"></td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><button type="submit" class="button button-primary">Save</button></p>';
            echo '</form>';

            $tiers_page = get_option('bhm_tiers_page_id', 0);
            echo '<h2>Supporter tiers page</h2>';
            echo '<p class="description">Add the <code>[bhm_tiers]</code> shortcode to a page, then set it below so paywall notices link somewhere real.</p>';
            if ($tiers_page && get_post($tiers_page)) {
                echo '<p><a href="' . esc_url(get_edit_post_link($tiers_page)) . '">' . esc_html(get_the_title($tiers_page)) . '</a> — <a href="' . esc_url(get_permalink($tiers_page)) . '" target="_blank">view</a></p>';
            } else {
                echo '<p><em>Not set yet.</em></p>';
            }

            // Without this page, gift claim links fall back to the homepage.
            $gift_redeem_page = get_option('bhm_gift_redeem_page_id', 0);
            echo '<h2>Gift redemption page</h2>';
            echo '<p class="description">Add the <code>[bhm_redeem_gift]</code> shortcode to a page so a gift recipient has somewhere to claim their tier.</p>';
            if ($gift_redeem_page && get_post($gift_redeem_page)) {
                echo '<p><a href="' . esc_url(get_edit_post_link($gift_redeem_page)) . '">' . esc_html(get_the_title($gift_redeem_page)) . '</a> — <a href="' . esc_url(get_permalink($gift_redeem_page)) . '" target="_blank">view</a></p>';
            } else {
                echo '<p><em>Not set yet — gift claim links currently have nowhere to send a recipient.</em></p>';
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

        echo '<h2>Recent gift redemptions</h2>';
        if (!$recent) {
            echo '<p class="description">No gifts purchased yet.</p>';
            return;
        }
        echo '<div class="bhy-table-wrap" style="max-width:760px;"><table class="wp-list-table widefat striped"><thead><tr><th>Recipient</th><th>Status</th><th>Purchased</th><th>Claimed</th></tr></thead><tbody>';
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
            echo '<p><strong>&#9989; Ready to get paid.</strong> Active payment method' . (count($enabled) === 1 ? '' : 's') . ': ' . esc_html($names) . '.</p>';
            echo '<p><a class="button" href="' . esc_url($payments_url) . '">Manage payment methods</a></p>';
        } else {
            echo '<p><strong>&#10060; No payment method is enabled yet.</strong> Tiers and purchases can be created, but a fan can\'t actually pay for anything until at least one gateway (Stripe, PayPal, WooCommerce Payments, etc.) is turned on.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url($payments_url) . '">Set up a payment method &rarr;</a> <span class="description">Opens WooCommerce\'s own guided payments setup — real card processing is configured there, not duplicated here.</span></p>';
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
