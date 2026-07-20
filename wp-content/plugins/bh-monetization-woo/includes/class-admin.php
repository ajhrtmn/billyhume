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
            // 'parent' => 'woocommerce' — this used to default to
            // 'own-ur-shit' (every admin_menus entry's implicit
            // default, see the core's class-menu-merge.php), splitting
            // this plugin's admin presence across two different
            // top-level menus: "Monetization Settings" under the
            // cross-cutting ecosystem hub, but its own Tiers CPT
            // (class-tiers.php's own 'show_in_menu' => 'woocommerce')
            // right there under WooCommerce instead. Joining Tiers
            // under the same parent it already lives next to is the
            // one consistent home for a WooCommerce-backed plugin.
            'admin_menus' => [
                ['slug' => 'bhm-settings', 'label' => 'Monetization Settings', 'callback' => [self::class, 'render'], 'parent' => 'woocommerce'],
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
        // Routed through BH_Commerce (this ecosystem's abstraction seam
        // over WooCommerce, per AJ's own standing "nothing hard-wired to
        // an external, unmockable dependency" rule) rather than a bare
        // class_exists() — an audit found this file had been missed in
        // the pass that fixed the rest of this plugin's own call sites.
        $has_wc = class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce');
        $has_subs = class_exists('BH_Commerce') ? BH_Commerce::has_subscriptions() : class_exists('WC_Subscriptions');
        echo '<div class="wrap"><h1>Monetization Settings</h1>';

        if (!$has_wc) {
            echo '<div class="notice notice-warning"><p><strong>WooCommerce isn\'t installed yet.</strong> Every monetization feature (tiers, purchases, tips, pay-per-play) stays completely inactive — zero cost, zero UI clutter on your track/release screens — until you install it. Go to <strong>Own Ur Shit</strong> and click "Install from WordPress.org" next to WooCommerce.</p></div>';
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

    /**
     * "It just works" applied to the one real gap the wizard-opportunity
     * survey found: this plugin has NO payment-gateway screen of its
     * own — real Stripe/PayPal/card processing is configured entirely
     * in WooCommerce core's own checkout settings, which is exactly the
     * kind of raw, technical, third-party screen VISION.md's "it just
     * works" principle exists to wrap. Rather than reimplementing
     * gateway configuration (WooCommerce core already ships a real
     * guided Payments setup task), this is a thin, honest launcher: a
     * REAL check of whether a gateway is actually enabled right now
     * (WC_Payment_Gateways::get_available_payment_gateways() — a live
     * API call, never a guess) plus a direct link into WooCommerce's
     * own screen. Same "wrap what already exists, don't rebuild it"
     * posture as OUS_MediaWizard pointing at Advanced Media Offloader.
     */
    private static function render_get_paid_card() {
        $enabled = class_exists('BH_Commerce') ? BH_Commerce::get_available_payment_gateways() : (class_exists('WC_Payment_Gateways') ? WC_Payment_Gateways::instance()->get_available_payment_gateways() : []);
        $payments_url = admin_url('admin.php?page=wc-settings&tab=checkout');

        echo '<div class="bhy-alert" style="border-left:3px solid ' . ($enabled ? '#1DB954' : '#d63638') . ';background:#f6f7f7;padding:14px 16px;margin:16px 0;max-width:760px;">';
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
