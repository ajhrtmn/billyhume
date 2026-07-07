<?php
if (!defined('ABSPATH')) exit;

/**
 * Fan-facing surfaces: the supporter-tier picker ([bhm_tiers]), wallet
 * balance/top-up, and a tip jar. Deliberately thin wrappers around
 * WooCommerce's own cart/checkout — a "Join" or "Top Up" button here
 * just links to WooCommerce's real add-to-cart URL and lets its normal
 * checkout flow (and whatever payment gateways the artist has
 * configured) take over from there. Re-skinning checkout/payment forms
 * themselves isn't worth it — that's exactly the surface WooCommerce
 * and its gateways already handle securely (see this plugin's own
 * bootstrap docblock on the "wrap it" principle).
 */
class BHM_Frontend {
    // Server-side bounds on the tip amount — a free-form "name your own
    // price" field is exactly the kind of input that needs a floor and
    // a ceiling: too low isn't worth a transaction, and an unbounded
    // ceiling turns the tip jar into a way to push arbitrarily large,
    // oddly-labeled payments through the store, which is worth avoiding
    // even though catching genuine money laundering is fundamentally the
    // payment gateway's job (Stripe/PayPal are the regulated, KYC'd
    // money-services businesses here, not this plugin — see this
    // class's own docblock and README for why this plugin doesn't
    // pretend to do AML/fraud detection itself).
    const TIP_MIN_CENTS = 100;
    const TIP_MAX_CENTS = 50000;

    public static function init() {
        add_shortcode('bhm_tiers', [self::class, 'render_tiers']);
        add_shortcode('bhm_tip_jar', [self::class, 'render_tip_jar']);
        add_shortcode('bhm_wallet', [self::class, 'render_wallet']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_tip_price']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'apply_tip_amount'], 10, 2);

        // Auto-detect a page carrying the [bhm_tiers] shortcode and
        // remember it as the tiers page — so BHM_Tiers::tiers_page_url()
        // (used by every paywall notice's "Become a supporter" link)
        // works without a separate manual "which page is this" setting
        // most of the time. The admin screen still lets an artist
        // override it explicitly if they'd rather.
        add_action('save_post_page', [self::class, 'maybe_remember_tiers_page']);
    }

    public static function maybe_remember_tiers_page($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bhm_tiers')) {
            update_option('bhm_tiers_page_id', $post_id);
        }
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || (!has_shortcode($post->post_content, 'bhm_tiers') && !has_shortcode($post->post_content, 'bhm_wallet') && !has_shortcode($post->post_content, 'bhm_tip_jar'))) return;
        wp_enqueue_style('bhm-frontend', BHM_URL . 'assets/css/frontend.css', [], BHM_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhm-frontend', BHY_Style::inline_css());
    }

    /* ---------- tier picker ---------- */

    public static function render_tiers() {
        if (!class_exists('WooCommerce')) return '<p>Supporter tiers aren\'t available yet.</p>';
        $tiers = BHM_Tiers::all();
        if (!$tiers) return '<p>No supporter tiers are set up yet.</p>';

        $user_id = get_current_user_id();
        ob_start();
        echo '<div class="bhm-tier-grid">';
        foreach ($tiers as $t) {
            $active = $user_id && BHM_Gate::user_has_tier_access($user_id, $t['id']);
            echo '<div class="bhm-tier-card' . ($active ? ' bhm-tier-active' : '') . '">';
            echo '<h3>' . esc_html($t['name']) . '</h3>';
            echo '<div class="bhm-tier-price">$' . esc_html(number_format($t['price_cents'] / 100, 2)) . '/mo</div>';
            if ($t['benefits']) echo '<p class="bhm-tier-benefits">' . esc_html($t['benefits']) . '</p>';
            if ($active) {
                echo '<span class="bhm-badge">Your current tier</span>';
            } elseif ($t['wc_product_id']) {
                echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($t['wc_product_id'])) . '">Join</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function add_to_cart_url($product_id) {
        return wc_get_cart_url() . '?add-to-cart=' . (int) $product_id;
    }

    /* ---------- tip jar ---------- */

    // A tip is just a variable-price WooCommerce product the fan sets
    // their own amount on at checkout (WooCommerce core supports
    // "Name Your Price"-style variable pricing via a simple custom
    // field on a virtual product) — no separate payment machinery here.
    public static function render_tip_jar($atts) {
        if (!class_exists('WooCommerce')) return '';
        $product_id = (int) get_option('bhm_tip_product_id', 0);
        if (!$product_id) $product_id = self::ensure_tip_product();

        ob_start();
        ?>
        <form class="bhm-tip-jar" method="get" action="<?php echo esc_url(wc_get_cart_url()); ?>">
            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>">
            <label>Send a tip: $<input type="number" step="1" min="<?php echo esc_attr(self::TIP_MIN_CENTS / 100); ?>" max="<?php echo esc_attr(self::TIP_MAX_CENTS / 100); ?>" name="bhm_tip_amount" value="5"></label>
            <button type="submit" class="bhm-btn">Send Tip</button>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function ensure_tip_product() {
        $product = new WC_Product_Simple();
        $product->set_name('Tip');
        $product->set_regular_price(number_format(self::TIP_MIN_CENTS / 100, 2, '.', '')); // real per-order amount is overridden by apply_tip_price() below — this is just the catalog fallback
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        update_option('bhm_tip_product_id', $product->get_id());
        return $product->get_id();
    }

    // Registered unconditionally in init() (NOT only inside
    // ensure_tip_product(), which — bug fixed here — only ever runs
    // once, the very first time the tip product is created; every
    // request after that would otherwise never register this filter at
    // all and silently fall back to charging the $1 catalog price
    // regardless of what the fan actually typed).
    public static function apply_tip_amount($cart_item_data, $product_id) {
        if ((int) $product_id !== (int) get_option('bhm_tip_product_id', 0)) return $cart_item_data;
        $requested_cents = isset($_GET['bhm_tip_amount']) ? (int) round(((float) $_GET['bhm_tip_amount']) * 100) : 500;
        // Clamped server-side — the min/max on the <input> above is a
        // UX hint only; a request can trivially bypass HTML attributes,
        // so the actual bound has to be enforced here.
        $cents = max(self::TIP_MIN_CENTS, min(self::TIP_MAX_CENTS, $requested_cents));
        $cart_item_data['bhm_tip_cents'] = $cents;
        return $cart_item_data;
    }

    // The actual price override, applied every time WooCommerce
    // recalculates cart totals — this is what makes the tip amount real
    // rather than cosmetic. Re-clamps again here too (not just at
    // add-to-cart time) since cart contents can persist across requests
    // and this is the actual point money changes hands.
    public static function apply_tip_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $item) {
            if (!isset($item['bhm_tip_cents'])) continue;
            $cents = max(self::TIP_MIN_CENTS, min(self::TIP_MAX_CENTS, (int) $item['bhm_tip_cents']));
            $item['data']->set_price(number_format($cents / 100, 2, '.', ''));
        }
    }

    /* ---------- wallet ---------- */

    public static function render_wallet() {
        if (!is_user_logged_in()) return '<p>Log in to see your play-credit wallet.</p>';
        $user_id = get_current_user_id();
        $balance = BHM_Wallet::balance_cents($user_id);
        $topup_options = get_option('bhm_wallet_topup_options', []);
        $topup_products = get_option('bhm_wallet_topup_products', []); // cents => wc_product_id, built by sync_wallet_topup_products()

        ob_start();
        echo '<div class="bhm-wallet">';
        echo '<p class="bhm-wallet-balance">Balance: $' . esc_html(number_format($balance / 100, 2)) . '</p>';
        if (class_exists('WooCommerce') && $topup_options) {
            echo '<div class="bhm-wallet-topups">';
            foreach ($topup_options as $cents => $price) {
                $product_id = (int) ($topup_products[$cents] ?? 0);
                if (!$product_id || !wc_get_product($product_id)) continue; // not synced yet — admin needs to save settings once
                echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($product_id)) . '">Add $' . esc_html(number_format($cents / 100, 2)) . ' credit — $' . esc_html(number_format((float) $price, 2)) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---------- wallet top-up product sync ---------- */

    // Called whenever the admin settings screen saves new top-up
    // amounts (see class-admin.php) — keeps one WooCommerce product per
    // configured top-up tier, tagged with how many wallet cents it
    // grants so on_order_completed() in class-products.php knows what
    // to credit.
    public static function sync_wallet_topup_products() {
        if (!class_exists('WooCommerce')) return;
        $options = get_option('bhm_wallet_topup_options', []);
        $existing = get_option('bhm_wallet_topup_products', []); // cents => product_id
        $new_map = [];

        foreach ($options as $cents => $price) {
            $product_id = $existing[$cents] ?? 0;
            $product = $product_id ? wc_get_product($product_id) : null;
            if (!$product) $product = new WC_Product_Simple();

            $product->set_name('Play Credit — $' . number_format($cents / 100, 2));
            $product->set_regular_price(number_format((float) $price, 2, '.', ''));
            $product->set_virtual(true);
            $product->set_catalog_visibility('hidden');
            $product->save();

            update_post_meta($product->get_id(), '_bhm_wallet_topup_cents', $cents);
            $new_map[$cents] = $product->get_id();
        }
        update_option('bhm_wallet_topup_products', $new_map);
    }

    public static function register_routes() {
        register_rest_route('bhm/v1', '/wallet', [
            'methods' => 'GET', 'callback' => [self::class, 'get_wallet'], 'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function get_wallet() {
        $user_id = get_current_user_id();
        return new WP_REST_Response([
            'success' => true,
            'balance_cents' => BHM_Wallet::balance_cents($user_id),
            'ledger' => array_map(function ($row) {
                return ['delta_cents' => (int) $row->delta_cents, 'reason' => $row->reason, 'track_id' => $row->track_id ? (int) $row->track_id : null, 'created_at' => $row->created_at];
            }, BHM_Wallet::ledger_for($user_id)),
        ], 200);
    }
}
