<?php
if (!defined('ABSPATH')) exit;

/**
 * Site-wide settings — the one screen that exists regardless of whether
 * WooCommerce is installed yet. Registers on 'ous_registered_plugins'
 * with 'wporg_slug' => 'woocommerce' as a real, on-demand-installable
 * dependency, exactly the pattern the core's own class-registry.php
 * docblock documents for third-party plugins — the "Install from
 * WordPress.org" button on the Own Ur Shit dashboard handles the actual
 * install/activate, this plugin never bundles or redistributes
 * WooCommerce itself.
 */
class BHM_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_post_bhm_save_settings', [self::class, 'save_settings']);
    }

    public static function register($plugins) {
        $plugins['bh-monetization-woo'] = [
            'label' => 'BH Monetization', 'file' => 'bh-monetization-woo/bh-monetization-woo.php',
            'depends_on' => ['woocommerce'], 'check_class' => 'BHM_Gate',
            'description' => 'Supporter tiers, purchases, tips, and pay-per-play for bh-streaming — backed by WooCommerce, with refund/velocity fraud-pattern flagging.',
            // Same gap fixed as bh-registry's: this plugin's own zip
            // needs to physically exist at own-ur-shit/bundled/
            // bh-monetization-woo.zip for the dashboard's one-click
            // Install button to have anything to extract.
            'bundled_zip' => 'bh-monetization-woo.zip',
            'dashboard_link' => 'admin.php?page=bhm-settings',
            'admin_menus' => [
                ['slug' => 'bhm-settings', 'label' => 'Monetization Settings', 'callback' => [self::class, 'render']],
            ],
        ];
        // The actual WooCommerce entry — same wporg_slug pattern the
        // core's own docblock shows for a third-party dependency. Only
        // added if nothing else (another plugin, or the core itself in
        // a future version) has already registered it, so two plugins
        // both depending on WooCommerce don't fight over the entry.
        if (!isset($plugins['woocommerce'])) {
            $plugins['woocommerce'] = [
                'label' => 'WooCommerce', 'file' => 'woocommerce/woocommerce.php',
                'wporg_slug' => 'woocommerce', 'check_class' => 'WooCommerce',
                'description' => 'Required for BH Monetization — payments and commerce, not reimplemented here.',
            ];
        }
        return $plugins;
    }

    public static function render() {
        $has_wc = class_exists('WooCommerce');
        $has_subs = class_exists('WC_Subscriptions');
        echo '<div class="wrap"><h1>Monetization Settings</h1>';

        if (!$has_wc) {
            echo '<div class="notice notice-warning"><p><strong>WooCommerce isn\'t installed yet.</strong> Every monetization feature (tiers, purchases, tips, pay-per-play) stays completely inactive — zero cost, zero UI clutter on your track/release screens — until you install it. Go to <strong>Own Ur Shit</strong> and click "Install from WordPress.org" next to WooCommerce.</p></div>';
        } else {
            echo '<p>WooCommerce is active. ' . ($has_subs
                ? 'WooCommerce Subscriptions is also active — supporter tiers bill on a real recurring schedule.'
                : '<strong>WooCommerce Subscriptions isn\'t active</strong> — supporter tiers will sell as one-time, 30-day access instead of automatic recurring billing. Install WooCommerce Subscriptions (a separate, official, paid extension — WooCommerce core has no subscription billing of its own) if you want true recurring tiers.'
            ) . '</p>';

            $topup_options = get_option('bhm_wallet_topup_options', [500 => 5.00, 1000 => 10.00, 2500 => 25.00]);
            echo '<h2>Pay-per-play wallet top-up amounts</h2>';
            echo '<p class="description">The fixed top-up amounts fans see when adding play credit. Stored as cents-of-credit → USD price (usually 1:1, i.e. $5 buys 500 cents / $5.00 of play credit — a discount tier is just a price lower than the cents value).</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bhm_save_settings', 'bhm_settings_nonce');
            echo '<input type="hidden" name="action" value="bhm_save_settings">';
            echo '<table class="form-table"><tbody>';
            foreach ($topup_options as $cents => $price) {
                echo '<tr><td>' . esc_html(number_format($cents / 100, 2)) . ' credit</td><td><input type="number" step="0.01" name="bhm_topup_price[' . esc_attr($cents) . ']" value="' . esc_attr($price) . '"></td></tr>';
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
        }
        echo '</div>';
    }

    public static function save_settings() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['bhm_settings_nonce'] ?? '', 'bhm_save_settings')) {
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
