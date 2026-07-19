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
        add_shortcode('bhm_buy', [self::class, 'render_purchase_button']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_tip_price']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'apply_tip_amount'], 10, 2);
        // Pay-what-you-want purchases (ROADMAP-ux-polish-and-feature-
        // parity-2026-07.md 5c) — same two-hook cart-price-override
        // shape as the tip jar above, just keyed to a purchase product's
        // own _bhm_purchase_pwyw/_bhm_purchase_price_cents meta instead
        // of a single hardcoded product. See BHM_Products::save_object()
        // for where those two meta keys get written.
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_purchase_price']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'apply_purchase_amount'], 10, 2);

        // Auto-detect a page carrying the [bhm_tiers] shortcode and
        // remember it as the tiers page — so BHM_Tiers::tiers_page_url()
        // (used by every paywall notice's "Become a supporter" link)
        // works without a separate manual "which page is this" setting
        // most of the time. The admin screen still lets an artist
        // override it explicitly if they'd rather.
        add_action('save_post_page', [self::class, 'maybe_remember_tiers_page']);
        // Same auto-detect for the gift-claim page — BHM_Gifts::redeem_page_url()
        // needs a real page to point a claim link at, and requiring a
        // manual setting before gifting can be tested at all would be a
        // needless "it just works" violation for something this cheap to
        // auto-detect.
        add_action('save_post_page', [self::class, 'maybe_remember_gift_redeem_page']);
        // Logged-in-only — no _nopriv variant, since an anonymous
        // visitor has no subscription of their own to pause/resume.
        add_action('admin_post_bhm_manage_subscription', [self::class, 'handle_manage_subscription']);
    }

    public static function maybe_remember_tiers_page($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bhm_tiers')) {
            update_option('bhm_tiers_page_id', $post_id);
        }
    }

    public static function maybe_remember_gift_redeem_page($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bhm_redeem_gift')) {
            update_option('bhm_gift_redeem_page_id', $post_id);
        }
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || (!has_shortcode($post->post_content, 'bhm_tiers') && !has_shortcode($post->post_content, 'bhm_wallet') && !has_shortcode($post->post_content, 'bhm_tip_jar') && !has_shortcode($post->post_content, 'bhm_buy'))) return;
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
            if (!empty($t['cover_image_id'])) {
                $img_url = wp_get_attachment_image_url($t['cover_image_id'], 'medium');
                if ($img_url) echo '<img class="bhm-tier-cover" src="' . esc_url($img_url) . '" alt="">';
            }
            echo '<h3>' . esc_html($t['name']) . '</h3>';
            echo '<div class="bhm-tier-price">$' . esc_html(number_format($t['price_cents'] / 100, 2)) . '/mo</div>';
            if (!empty($t['annual_price_cents'])) {
                echo '<div class="bhm-tier-price-annual">or $' . esc_html(number_format($t['annual_price_cents'] / 100, 2)) . '/yr</div>';
            }
            // Real conversion-facing surface for the trial value stored
            // via BHM_Tiers — a trial nobody can see before checking out
            // isn't a conversion lever, it's just hidden product config.
            if (!empty($t['trial_days']) && class_exists('BH_Commerce') && BH_Commerce::has_subscriptions()) {
                echo '<div class="bhm-tier-trial">' . (int) $t['trial_days'] . '-day free trial</div>';
            }
            if ($t['benefits']) echo '<p class="bhm-tier-benefits">' . esc_html($t['benefits']) . '</p>';
            // Structured benefits list — a real <ul>, separate from the
            // free-text paragraph above (see BHM_Tiers::render_metabox()
            // for why these are two distinct fields rather than one).
            if (!empty($t['benefits_list'])) {
                echo '<ul class="bhm-tier-benefits-list">';
                foreach ($t['benefits_list'] as $item) {
                    echo '<li>' . esc_html($item) . '</li>';
                }
                echo '</ul>';
            }
            if ($active) {
                echo '<span class="bhm-badge">Your current tier</span>';
                echo self::render_subscription_controls($user_id, $t['id']);
            } elseif ($t['wc_product_id']) {
                echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($t['wc_product_id'])) . '">Join monthly</a>';
                if (!empty($t['wc_product_id_annual'])) {
                    echo ' <a class="bhm-btn bhm-btn-secondary" href="' . esc_url(self::add_to_cart_url($t['wc_product_id_annual'])) . '">Join annually</a>';
                }
                // Gifting — "buy this tier on someone else's behalf"
                // (ROADMAP-platform-evolution.md Section 4). A plain GET
                // form straight to the ordinary add-to-cart URL (same
                // cart flow every other purchase uses) rather than a
                // separate checkout path — BHM_Gifts::capture_gift_email()
                // is the only thing that treats this purchase
                // differently, and only once the recipient email is
                // actually present.
                echo '<details class="bhm-tier-gift"><summary>Gift this</summary>';
                // A GET form submission discards whatever query string is
                // already on its own `action` attribute — the base cart
                // URL is the right target here, not add_to_cart_url()
                // (which appends ?add-to-cart=N that would just get
                // silently dropped), with add-to-cart supplied as an
                // ordinary hidden field instead.
                echo '<form method="get" action="' . esc_url(wc_get_cart_url()) . '">';
                echo '<input type="hidden" name="add-to-cart" value="' . (int) $t['wc_product_id'] . '">';
                echo '<input type="hidden" name="bhm_gift" value="1">';
                echo '<input type="email" name="bhm_gift_email" placeholder="Recipient\'s email" required>';
                echo '<button type="submit" class="bhm-btn bhm-btn-secondary">Send gift</button>';
                echo '</form></details>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---------- subscription pause/resume ---------- */

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a (the feature
    // half — the entitlement-revocation bug fix, 0.4.6, already closed
    // the correctness half). A branded button here, not a link out to
    // WooCommerce Subscriptions' own native My Account screen — same
    // "thin wrapper, the fan never needs to touch WooCommerce's own UI
    // directly" posture this whole class already takes for checkout/
    // the tip jar, not a new principle invented for this one feature.
    //
    // Only renders for a REAL recurring subscription (a row in
    // bhm_entitlements with a real wc_subscription_id) — the one-time-
    // purchase-as-30-days-access fallback model (BHM_Gate's own
    // documented pattern for when WC Subscriptions isn't installed)
    // has nothing to pause; there's no recurring billing to interrupt,
    // just an expiry date already running.
    private static function render_subscription_controls($user_id, $tier_id) {
        if (!class_exists('WC_Subscriptions')) return '';
        $sub_id = self::active_subscription_id($user_id, $tier_id);
        if (!$sub_id) return '';
        $subscription = wcs_get_subscription($sub_id);
        if (!$subscription) return '';

        $status = $subscription->get_status();
        $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bhm-subscription-controls">';
        $out .= '<input type="hidden" name="action" value="bhm_manage_subscription">';
        $out .= '<input type="hidden" name="subscription_id" value="' . (int) $sub_id . '">';
        $out .= wp_nonce_field('bhm_manage_subscription_' . $sub_id, '_wpnonce', true, false);

        if ($status === 'on-hold') {
            $out .= '<p class="description">Your subscription is paused — you\'ll keep your account, but supporter access is off until you resume.</p>';
            if ($subscription->can_be_updated_to('active')) {
                $out .= '<button type="submit" name="bhm_sub_action" value="resume" class="bhm-btn">Resume subscription</button>';
            }
        } elseif ($subscription->can_be_updated_to('on-hold')) {
            $out .= '<button type="submit" name="bhm_sub_action" value="pause" class="bhm-btn bhm-btn-secondary">Pause subscription</button>';
        }
        $out .= '</form>';
        return $out;
    }

    /** The most recent real (has a wc_subscription_id) tier entitlement row for this user+tier — a fallback one-time-purchase entitlement (wc_subscription_id NULL) never matches, by design (see this section's own docblock). */
    private static function active_subscription_id($user_id, $tier_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wc_subscription_id FROM $t WHERE user_id = %d AND type = 'subscription' AND object_id = %d AND wc_subscription_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            $user_id, $tier_id
        )) ?: 0;
    }

    /**
     * admin-post handler for the pause/resume form above — logged-in-
     * only (no _nopriv variant registered; an anonymous visitor has no
     * subscription to manage), verifies BOTH the nonce AND that the
     * subscription actually belongs to the requesting user before
     * touching anything — a crafted subscription_id from a different
     * account must never be actionable here.
     */
    public static function handle_manage_subscription() {
        $sub_id = (int) ($_POST['subscription_id'] ?? 0);
        $action = sanitize_key($_POST['bhm_sub_action'] ?? '');
        $user_id = get_current_user_id();

        if (!$user_id || !$sub_id || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhm_manage_subscription_' . $sub_id)) {
            wp_die('Invalid request.', 400);
        }
        if (!class_exists('WC_Subscriptions') || !function_exists('wcs_get_subscription')) {
            wp_die('Subscriptions aren\'t available.', 400);
        }

        $subscription = wcs_get_subscription($sub_id);
        // get_user_id() is the subscription's OWN customer — the real
        // ownership check. Never trust that the sub_id/user_id pairing
        // implied by the form alone is honest.
        if (!$subscription || (int) $subscription->get_user_id() !== $user_id) {
            wp_die('That subscription doesn\'t belong to you.', 403);
        }

        $new_status = $action === 'resume' ? 'active' : ($action === 'pause' ? 'on-hold' : '');
        if ($new_status && $subscription->can_be_updated_to($new_status)) {
            $subscription->update_status($new_status, $action === 'pause' ? 'Paused by the subscriber from their account.' : 'Resumed by the subscriber from their account.');
        }

        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
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

    // Migrated onto BH_Commerce::upsert_product() — second migration
    // pass, same treatment BHM_Products::sync_tier_wc_product() got
    // first (see class-commerce.php's own docblock for the full
    // history). Never a subscription — a tip is always a one-time
    // product, upsert_product()'s subscription arg simply isn't passed.
    private static function ensure_tip_product() {
        if (class_exists('BH_Commerce')) {
            $product_id = BH_Commerce::upsert_product(0, [
                'name' => 'Tip',
                'price_cents' => self::TIP_MIN_CENTS, // real per-order amount is overridden by apply_tip_price() below — this is just the catalog fallback
                'virtual' => true,
                'catalog_visibility' => 'hidden',
            ]);
            if ($product_id) update_option('bhm_tip_product_id', $product_id);
            return $product_id;
        }

        // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
        $product = new WC_Product_Simple();
        $product->set_name('Tip');
        $product->set_regular_price(number_format(self::TIP_MIN_CENTS / 100, 2, '.', ''));
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

    /* ---------- pay-what-you-want purchases ---------- */

    // Ceiling matches the tip jar's own TIP_MAX_CENTS reasoning exactly
    // (an unbounded "name your price" field is a way to push arbitrarily
    // large, oddly-labeled payments through the store) — same number,
    // not duplicated by coincidence.
    const PURCHASE_MAX_CENTS = 50000;

    // A track/release "Buy" link/form, wherever an artist drops this
    // shortcode (a track's own content, a widget, a BH_Content block —
    // deliberately not wired to any one specific template, since
    // there's no existing front-end purchase entry point anywhere in
    // this ecosystem yet; this shortcode IS that entry point, same
    // "drop it wherever" posture as [bhm_tip_jar]). $atts['id'] is the
    // track/release post ID (its own real ID, not the WC product ID —
    // matches how BHM_Products::render_object_ui() is already keyed).
    public static function render_purchase_button($atts) {
        if (!class_exists('WooCommerce')) return '';
        $atts = shortcode_atts(['id' => 0], $atts);
        $object_id = (int) $atts['id'];
        if (!$object_id) return '';

        $product_id = (int) get_post_meta($object_id, '_bhm_purchase_wc_product_id', true);
        $price_cents = (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true);
        if (!$product_id || !$price_cents) return '';
        $pwyw = (bool) get_post_meta($object_id, '_bhm_purchase_pwyw', true);

        ob_start();
        if ($pwyw) {
            ?>
            <form class="bhm-buy-form" method="get" action="<?php echo esc_url(wc_get_cart_url()); ?>">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>">
                <label>Name your price (min $<?php echo esc_html(number_format($price_cents / 100, 2)); ?>): $
                    <input type="number" step="1" min="<?php echo esc_attr($price_cents / 100); ?>" max="<?php echo esc_attr(self::PURCHASE_MAX_CENTS / 100); ?>" name="bhm_purchase_amount" value="<?php echo esc_attr($price_cents / 100); ?>">
                </label>
                <button type="submit" class="bhm-btn">Buy</button>
            </form>
            <?php
        } else {
            echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($product_id)) . '">Buy for $' . esc_html(number_format($price_cents / 100, 2)) . '</a>';
        }
        return ob_get_clean();
    }

    // Same shape as apply_tip_amount() — clamps against the SPECIFIC
    // purchase's own floor (its own _bhm_purchase_price_cents, not the
    // tip jar's fixed TIP_MIN_CENTS), and only acts on a product this
    // reverse-lookup confirms is actually a PWYW-enabled purchase
    // product, never a tier/tip/wallet product that happens to share
    // the cart.
    public static function apply_purchase_amount($cart_item_data, $product_id) {
        $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
        if (!$object_id || !get_post_meta($object_id, '_bhm_purchase_pwyw', true)) return $cart_item_data;
        $floor_cents = (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true);
        if (!$floor_cents) return $cart_item_data;

        $requested_cents = isset($_GET['bhm_purchase_amount']) ? (int) round(((float) $_GET['bhm_purchase_amount']) * 100) : $floor_cents;
        // Clamped server-side — same reasoning as apply_tip_amount()'s
        // own comment: the <input> min/max is a UX hint only.
        $cents = max($floor_cents, min(self::PURCHASE_MAX_CENTS, $requested_cents));
        $cart_item_data['bhm_purchase_cents'] = $cents;
        return $cart_item_data;
    }

    public static function apply_purchase_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $item) {
            if (!isset($item['bhm_purchase_cents'])) continue;
            $product_id = $item['data']->get_id();
            $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
            $floor_cents = $object_id ? (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true) : 0;
            if (!$floor_cents) continue; // re-check at calculation time too — the price could have changed since add-to-cart
            $cents = max($floor_cents, min(self::PURCHASE_MAX_CENTS, (int) $item['bhm_purchase_cents']));
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
                $exists = class_exists('BH_Commerce') ? BH_Commerce::product_exists($product_id) : ($product_id && wc_get_product($product_id));
                if (!$product_id || !$exists) continue; // not synced yet — admin needs to save settings once
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
    // Migrated onto BH_Commerce::upsert_product() — see ensure_tip_product()
    // above and class-commerce.php's docblock for the full migration
    // history. $price here is a decimal dollar STRING (as stored in
    // bhm_wallet_topup_options, entered by the artist on the settings
    // screen), converted to integer cents for upsert_product()'s
    // contract rather than passed through as a formatted price string.
    public static function sync_wallet_topup_products() {
        if (!class_exists('WooCommerce')) return;
        $options = get_option('bhm_wallet_topup_options', []);
        $existing = get_option('bhm_wallet_topup_products', []); // cents => product_id
        $new_map = [];
        $use_commerce = class_exists('BH_Commerce');

        foreach ($options as $cents => $price) {
            $product_id = $existing[$cents] ?? 0;
            $name = 'Play Credit — $' . number_format($cents / 100, 2);
            $price_cents = (int) round(((float) $price) * 100);

            if ($use_commerce) {
                $new_id = BH_Commerce::upsert_product($product_id, [
                    'name' => $name,
                    'price_cents' => $price_cents,
                    'virtual' => true,
                    'catalog_visibility' => 'hidden',
                ]);
                if (!$new_id) continue;
                $product_id = $new_id;
            } else {
                // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
                $product = $product_id ? wc_get_product($product_id) : null;
                if (!$product) $product = new WC_Product_Simple();
                $product->set_name($name);
                $product->set_regular_price(number_format((float) $price, 2, '.', ''));
                $product->set_virtual(true);
                $product->set_catalog_visibility('hidden');
                $product->save();
                $product_id = $product->get_id();
            }

            update_post_meta($product_id, '_bhm_wallet_topup_cents', $cents);
            $new_map[$cents] = $product_id;
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
