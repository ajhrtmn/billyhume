<?php
if (!defined('ABSPATH')) exit;

/**
 * Fan-facing surfaces: tier picker, wallet, tip jar, buy button.
 * Thin wrappers that link into WooCommerce's own cart/checkout; payment forms are never re-skinned.
 */
class BHM_Frontend {
    // WHY: a free-form price field needs a floor and a ceiling; an unbounded
    // ceiling lets arbitrarily large, oddly-labeled payments through the store.
    const TIP_MIN_CENTS = 100;
    const TIP_MAX_CENTS = 50000;

    public static function init(): void {
        add_shortcode('bhm_tiers', [self::class, 'render_tiers']);
        add_shortcode('bhm_tip_jar', [self::class, 'render_tip_jar']);
        add_shortcode('bhm_wallet', [self::class, 'render_wallet']);
        add_shortcode('bhm_buy', [self::class, 'render_purchase_button']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_tip_price']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'apply_tip_amount'], 10, 2);
        // Pay-what-you-want purchases: same price-override shape as the tip jar,
        // keyed to _bhm_purchase_pwyw/_bhm_purchase_price_cents (BHM_MonetizationUI::save_object()).
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_purchase_price']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'apply_purchase_amount'], 10, 2);

        // Remember the page carrying [bhm_tiers] so tiers_page_url() works without a manual setting.
        add_action('save_post_page', [self::class, 'maybe_remember_tiers_page']);
        // WHY: admin_init, never plugins_loaded — wp_insert_post needs $wp_rewrite.
        add_action('admin_init', [self::class, 'ensure_tiers_page']);
        add_action('save_post_page', [self::class, 'maybe_remember_gift_redeem_page']);
        // Logged-in only: an anonymous visitor has no subscription to manage.
        add_action('admin_post_bhm_manage_subscription', [self::class, 'handle_manage_subscription']);

        // Priority 5: prepended before WooCommerce's own thank-you markup, never replacing it.
        add_action('woocommerce_thankyou', [self::class, 'render_tier_thankyou_banner'], 5);
        add_action('template_redirect', [self::class, 'maybe_redirect_after_purchase']);
        add_filter('woocommerce_login_redirect', [self::class, 'login_redirect'], 10, 2);
        add_action('template_redirect', [self::class, 'maybe_redirect_account_dashboard']);
    }

    /**
     * On the order-received page, lets another plugin send the buyer somewhere more useful
     * than WooCommerce's receipt (bh-courses → My Courses). Only for the buyer's own paid
     * order; anything else keeps the normal receipt.
     */
    /** The site's own Account portal, or '' when core's portal isn't available. */
    private static function portal_url(): string {
        return class_exists('BHI_Portal') ? home_url('/' . BHI_Portal::REWRITE_SLUG . '/') : '';
    }

    /**
     * WHY: members otherwise land on WooCommerce's My account dashboard (orders, addresses)
     * after a password reset or a log-in there, which never mentions their courses.
     * @param mixed $redirect
     * @param mixed $user
     */
    public static function login_redirect($redirect, $user = null): string {
        $portal = self::portal_url();
        if (!$portal || ($user instanceof \WP_User && user_can($user, 'edit_posts'))) return (string) $redirect;
        return $portal;
    }

    // Only the bare dashboard; order, download and password pages keep working for email links.
    public static function maybe_redirect_account_dashboard(): void {
        if (!function_exists('is_account_page') || !is_account_page() || !is_user_logged_in() || is_wc_endpoint_url()) return;
        if (current_user_can('edit_posts') || !($portal = self::portal_url())) return;
        wp_safe_redirect($portal);
        exit;
    }

    public static function maybe_redirect_after_purchase(): void {
        if (!function_exists('is_order_received_page') || !is_order_received_page() || !is_user_logged_in()) return;
        $order_id = absint(get_query_var('order-received'));
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || (int) $order->get_customer_id() !== get_current_user_id()) return;
        if (!hash_equals((string) $order->get_order_key(), (string) wp_unslash($_GET['key'] ?? ''))) return;
        if (!$order->is_paid()) return;

        $purchased = []; // post type => [object ids]
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) continue;
            $object_id = (int) get_post_meta($item->get_product_id(), '_bhm_purchase_object_id', true);
            $type = (string) get_post_meta($item->get_product_id(), '_bhm_purchase_object_type', true);
            if ($object_id && $type !== '') $purchased[$type][] = $object_id;
        }
        if (!$purchased) return;

        $target = (string) apply_filters('bhm_post_purchase_redirect', '', $purchased, $order_id);
        if ($target === '') return;
        wp_safe_redirect($target);
        exit;
    }

    // Tier-specific "You're now supporting at X" banner; renders nothing for non-tier orders.
    public static function render_tier_thankyou_banner(int $order_id): void {
        if (!$order_id || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Base WC_Order_Item has no get_product_id(), hence the instanceof guard.
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            $product_id = $item->get_product_id();
            $tier_id = (int) get_post_meta($product_id, '_bhm_tier_id', true);
            if (!$tier_id) continue;
            $tier = BHM_Tiers::get($tier_id);
            if (!$tier) continue;

            echo '<div class="bhm-thankyou-banner">';
            echo '<h2>&#127881; You\'re now supporting at ' . esc_html($tier['name']) . '!</h2>';
            if ($tier['benefits'] || !empty($tier['benefits_list'])) {
                echo '<p>Here\'s what that unlocks:</p>';
                if (!empty($tier['benefits_list'])) {
                    echo '<ul>';
                    foreach ($tier['benefits_list'] as $benefit) echo '<li>' . esc_html($benefit) . '</li>';
                    echo '</ul>';
                } elseif ($tier['benefits']) {
                    echo '<p>' . esc_html($tier['benefits']) . '</p>';
                }
            }
            if (class_exists('BHI_Portal')) {
                echo '<p><a class="bhm-btn" href="' . esc_url(home_url('/' . BHI_Portal::REWRITE_SLUG . '/membership/')) . '">Manage your membership &rarr;</a></p>';
            }
            echo '</div>';
            return; // one order realistically carries at most one tier line item — first match is enough
        }
    }

    public static function maybe_remember_tiers_page(int $post_id): void {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bhm_tiers')) {
            update_option('bhm_tiers_page_id', $post_id);
        }
    }

    /** Guarantee a real page behind BHM_Tiers::tiers_page_url(), adopting an existing one if present. */
    public static function ensure_tiers_page(): void {
        // WHY: method_exists, not class_exists — this plugin can deploy ahead of a core that lacks the method.
        if (!method_exists('OUS_Pages', 'ensure')) return;
        OUS_Pages::ensure('bhm_tiers', 'bhm_tiers_page_id', __('Supporter Tiers', 'bh-monetization-woo'), ['bhm/tiers']);
    }

    public static function maybe_remember_gift_redeem_page(int $post_id): void {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bhm_redeem_gift')) {
            update_option('bhm_gift_redeem_page_id', $post_id);
        }
    }

    public static function maybe_enqueue(): void {
        // The thank-you page has no bhm_* shortcode but still shows the tier banner.
        $is_thankyou = function_exists('is_order_received_page') && is_order_received_page();
        if (!$is_thankyou) {
            if (!is_singular()) return;
            global $post;
            if (!$post) return;
            $has_shortcode = has_shortcode($post->post_content, 'bhm_tiers')
                || has_shortcode($post->post_content, 'bhm_wallet')
                || has_shortcode($post->post_content, 'bhm_tip_jar')
                || has_shortcode($post->post_content, 'bhm_buy');
            // A tier-gated post has no shortcode but still renders .bhm-paywall.
            $is_gated = class_exists('BHM_Gate') && BHM_Gate::get_required_tier($post->ID) > 0;
            if (!$has_shortcode && !$is_gated) return;
        }
        wp_enqueue_style('bhm-frontend', BHM_URL . 'assets/css/frontend.css', [], BHM_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhm-frontend', BHY_Style::inline_css());
        wp_enqueue_script('bhm-frontend', BHM_URL . 'assets/js/frontend.js', [], BHM_VER, true);
    }

    /* ---------- tier picker ---------- */

    /** Empty state for the tiers page, with a first-run shortcut for the site owner. */
    private static function render_tiers_empty(bool $commerce_ready): string {
        $star = '<svg class="bhm-tiers-empty-icon" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 3.5l2.6 5.27 5.82.85-4.21 4.1.99 5.8L12 16.79l-5.2 2.73.99-5.8-4.21-4.1 5.82-.85z" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        $body = $commerce_ready
            ? '<p class="bhm-tiers-empty-lead">Supporter tiers aren\'t open yet.</p>'
              . '<p class="bhm-tiers-empty-note">This is where you\'ll be able to back the music directly &mdash; a recurring pledge in exchange for members-only perks. Check back soon.</p>'
            : '<p class="bhm-tiers-empty-lead">Supporter tiers aren\'t available yet.</p>'
              . '<p class="bhm-tiers-empty-note">The store connection still needs to be finished before tiers can go live.</p>';

        $admin = '';
        if (current_user_can('edit_posts')) {
            $admin = '<p class="bhm-tiers-empty-admin"><a href="' . esc_url(admin_url('post-new.php?post_type=bhm_tier')) . '">&rarr; Add your first tier</a>'
                   . ' <span class="bhm-tiers-empty-admin-hint">(only you can see this)</span></p>';
        }

        return '<div class="bhm-tiers-empty">' . $star
             . '<h2 class="bhm-tiers-empty-title">Supporter tiers</h2>'
             . $body . $admin . '</div>';
    }

    public static function render_tiers(): string {
        $tiers = BH_Commerce::available() ? BHM_Tiers::all() : [];
        if (!$tiers) {
            return self::render_tiers_empty(BH_Commerce::available());
        }

        $user_id = get_current_user_id();
        // Pre-pass: every other card's CTA depends on whether the fan has any tier at all.
        $user_has_any_tier = false;
        if ($user_id) {
            foreach ($tiers as $t) {
                if (BHM_Gate::user_has_tier_access($user_id, $t['id'])) { $user_has_any_tier = true; break; }
            }
        }
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
            // Without WC Subscriptions a tier is a one-time 30-day purchase; say which.
            $has_subscriptions = class_exists('BH_Commerce') && BH_Commerce::has_subscriptions();
            echo '<span class="bhm-billing-badge">' . ($has_subscriptions ? 'Recurring — billed monthly' : 'One-time — 30 days access') . '</span>';
            echo '<div class="bhm-tier-price">$' . esc_html(BHM_Money::display($t['price_cents'])) . '/mo</div>';
            if (!empty($t['annual_price_cents'])) {
                // Savings shown only when annual is genuinely cheaper than 12x monthly.
                $annual_monthly_equivalent = $t['annual_price_cents'] / 12;
                $savings_percent = BHM_Gate::annual_savings_percent($t['price_cents'], $t['annual_price_cents']);
                echo '<div class="bhm-tier-price-annual">or $' . esc_html(BHM_Money::display($t['annual_price_cents'])) . '/yr ($' . esc_html(BHM_Money::display($annual_monthly_equivalent)) . '/mo equivalent)';
                if ($savings_percent > 0) echo ' <span class="bhm-tier-savings">— save ' . (int) $savings_percent . '%</span>';
                echo '</div>';
            }
            if (!empty($t['trial_days']) && class_exists('BH_Commerce') && BH_Commerce::has_subscriptions()) {
                echo '<div class="bhm-tier-trial">' . (int) $t['trial_days'] . '-day free trial</div>';
            }
            if ($t['benefits']) echo '<p class="bhm-tier-benefits">' . esc_html($t['benefits']) . '</p>';
            if (!empty($t['benefits_list'])) {
                echo '<ul class="bhm-tier-benefits-list">';
                foreach ($t['benefits_list'] as $item) {
                    echo '<li>' . esc_html($item) . '</li>';
                }
                echo '</ul>';
            }
            if ($active) {
                echo '<span class="bh-badge bhm-badge">Your current tier</span>';
                echo self::render_subscription_controls($user_id, $t['id']);
            } elseif ($t['wc_product_id']) {
                // Label only; tier switching is reconciled server-side (BHM_Gate::handle_tier_downgrade()).
                $join_label = $user_has_any_tier ? 'Switch to this tier' : 'Join monthly';
                $annual_label = $user_has_any_tier ? 'Switch (annual)' : 'Join annually';
                echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($t['wc_product_id'])) . '">' . esc_html($join_label) . '</a>';
                if (!empty($t['wc_product_id_annual'])) {
                    echo ' <a class="bhm-btn bhm-btn-secondary" href="' . esc_url(self::add_to_cart_url($t['wc_product_id_annual'])) . '">' . esc_html($annual_label) . '</a>';
                }

                // Gift this tier: a <details> so no JS is needed to reveal the form.
                echo '<details class="bhm-tier-gift"><summary class="bhm-btn bhm-btn-secondary">&#127873; Gift this tier</summary>';
                // WHY: a GET form drops its action's query string, so add-to-cart goes in a hidden field.
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

    // Pause/resume only for a real recurring subscription; one-time 30-day access has nothing to pause.
    private static function render_subscription_controls(int $user_id, int $tier_id): string {
        // Via BH_Commerce so a mock provider can stand in for WC Subscriptions in tests.
        if (!class_exists('BH_Commerce') || !BH_Commerce::has_subscriptions()) return '';
        $sub_id = self::active_subscription_id($user_id, $tier_id);
        if (!$sub_id) return '';
        $subscription = BH_Commerce::get_subscription($sub_id);
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
            $out .= '<button type="submit" name="bhm_sub_action" value="pause" class="bhm-btn bhm-btn-secondary" onclick="return confirm(\'Pause your subscription? You\\\'ll lose supporter access until you resume it.\');">Pause subscription</button>';
        }
        $out .= '</form>';
        return $out;
    }

    /** Latest wc_subscription_id for this user+tier; one-time entitlements (NULL id) never match. */
    private static function active_subscription_id(int $user_id, int $tier_id): int {
        global $wpdb;
        $t = BHM_Tables::entitlements();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wc_subscription_id FROM $t WHERE user_id = %d AND type = 'subscription' AND object_id = %d AND wc_subscription_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            $user_id, $tier_id
        )) ?: 0;
    }

    /** Pause/resume handler: checks the nonce AND that the subscription belongs to the current user. */
    public static function handle_manage_subscription(): void {
        $sub_id = (int) ($_POST['subscription_id'] ?? 0);
        $action = sanitize_key($_POST['bhm_sub_action'] ?? '');
        $user_id = get_current_user_id();

        if (!$user_id || !$sub_id || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhm_manage_subscription_' . $sub_id)) {
            wp_die('Invalid request.', '', ['response' => 400]);
        }
        if (!class_exists('BH_Commerce') || !BH_Commerce::has_subscriptions()) {
            wp_die('Subscriptions aren\'t available.', 400);
        }

        $subscription = BH_Commerce::get_subscription($sub_id);
        // WHY: ownership comes from the subscription itself, never from the form.
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

    private static function add_to_cart_url(int $product_id): string {
        return wc_get_cart_url() . '?add-to-cart=' . (int) $product_id;
    }

    /* ---------- tip jar ---------- */

    // A tip is a hidden virtual product whose price is overridden per cart item.
    /** @param mixed $atts */
    public static function render_tip_jar($atts): string {
        if (!BH_Commerce::available()) return '';
        $product_id = (int) get_option('bhm_tip_product_id', 0);
        if (!$product_id) $product_id = self::ensure_tip_product();

        ob_start();
        ?>
        <form class="bhm-tip-jar" method="get" action="<?php echo esc_url(wc_get_cart_url()); ?>">
            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr((string) $product_id); ?>">
            <!-- Suggested-amount chips — a bare number input defaulting to
                 $5 was the only anchor a fan had for "what's a normal tip
                 here"; real, tappable amounts (matching the same
                 min/PURCHASE-adjacent scale the PWYW form below now also
                 uses) turn this into a one-tap action instead of a blank
                 field to fill in. The manual input stays for anyone who
                 wants a different amount — chips just set it, they don't
                 replace it. -->
            <div class="bhm-amount-chips" role="group" aria-label="Suggested tip amounts">
                <?php foreach ([5, 10, 25, 50] as $amt): ?>
                    <button type="button" class="bhm-amount-chip" data-amount="<?php echo esc_attr((string) $amt); ?>">$<?php echo esc_html((string) $amt); ?></button>
                <?php endforeach; ?>
            </div>
            <label>Send a tip: $<input type="number" step="1" min="<?php echo esc_attr((string) (self::TIP_MIN_CENTS / 100)); ?>" max="<?php echo esc_attr((string) (self::TIP_MAX_CENTS / 100)); ?>" name="bhm_tip_amount" value="5" class="bhm-amount-input"></label>
            <button type="submit" class="bhm-btn">Send Tip</button>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function ensure_tip_product(): int {
        if (class_exists('BH_Commerce')) {
            $product_id = BH_Commerce::upsert_product(0, [
                'name' => 'Tip',
                'price_cents' => self::TIP_MIN_CENTS, // catalog fallback; apply_tip_price() sets the real amount
                'virtual' => true,
                'catalog_visibility' => 'hidden',
            ]);
            if ($product_id) update_option('bhm_tip_product_id', $product_id);
            return $product_id;
        }

        // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
        $product = new WC_Product_Simple();
        $product->set_name('Tip');
        $product->set_regular_price(BHM_Money::price(self::TIP_MIN_CENTS));
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        update_option('bhm_tip_product_id', $product->get_id());
        return $product->get_id();
    }

    // WHY: registered unconditionally in init(); ensure_tip_product() runs only once.
    /**
     * @param array<string, mixed> $cart_item_data
     * @return array<string, mixed>
     */
    public static function apply_tip_amount($cart_item_data, int $product_id): array {
        if ((int) $product_id !== (int) get_option('bhm_tip_product_id', 0)) return $cart_item_data;
        $requested_cents = isset($_GET['bhm_tip_amount']) ? BHM_Money::parse($_GET['bhm_tip_amount']) : 500;
        // WHY: clamped server-side; the <input> min/max is only a UX hint.
        $cents = max(self::TIP_MIN_CENTS, min(self::TIP_MAX_CENTS, $requested_cents));
        $cart_item_data['bhm_tip_cents'] = $cents;
        return $cart_item_data;
    }

    // The real price override; re-clamps because cart contents persist across requests.
    public static function apply_tip_price(\WC_Cart $cart): void {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $item) {
            if (!isset($item['bhm_tip_cents'])) continue;
            $cents = max(self::TIP_MIN_CENTS, min(self::TIP_MAX_CENTS, (int) $item['bhm_tip_cents']));
            $item['data']->set_price(BHM_Money::price($cents));
        }
    }

    /* ---------- pay-what-you-want purchases ---------- */

    // Same ceiling reasoning as TIP_MAX_CENTS.
    const PURCHASE_MAX_CENTS = 50000;

    // [bhm_buy id=N]: N is the track/release post ID, not the WC product ID.
    /** @param mixed $atts */
    public static function render_purchase_button($atts): string {
        if (!BH_Commerce::available()) return '';
        $atts = shortcode_atts(['id' => 0], $atts);
        $object_id = (int) $atts['id'];
        if (!$object_id) return '';

        $product_id = (int) get_post_meta($object_id, '_bhm_purchase_wc_product_id', true);
        $price_cents = (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true);
        if (!$product_id || !$price_cents) return '';
        $pwyw = (bool) get_post_meta($object_id, '_bhm_purchase_pwyw', true);

        ob_start();
        if ($pwyw) {
            // Suggested amounts scale off the floor price.
            $floor_dollars = $price_cents / 100;
            $suggested = array_unique(array_map(function ($mult) use ($floor_dollars) {
                return (int) round($floor_dollars * $mult);
            }, [1, 1.5, 2, 3]));
            ?>
            <form class="bhm-buy-form" method="get" action="<?php echo esc_url(wc_get_cart_url()); ?>">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr((string) $product_id); ?>">
                <div class="bhm-amount-chips" role="group" aria-label="Suggested purchase amounts">
                    <?php foreach ($suggested as $amt): ?>
                        <button type="button" class="bhm-amount-chip" data-amount="<?php echo esc_attr((string) $amt); ?>">$<?php echo esc_html((string) $amt); ?></button>
                    <?php endforeach; ?>
                </div>
                <label>Name your price (min $<?php echo esc_html(BHM_Money::display($price_cents)); ?>): $
                    <input type="number" step="1" min="<?php echo esc_attr((string) ($price_cents / 100)); ?>" max="<?php echo esc_attr((string) (self::PURCHASE_MAX_CENTS / 100)); ?>" name="bhm_purchase_amount" value="<?php echo esc_attr((string) ($price_cents / 100)); ?>" class="bhm-amount-input">
                </label>
                <button type="submit" class="bhm-btn">Buy</button>
            </form>
            <?php
        } else {
            echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($product_id)) . '">Buy for $' . esc_html(BHM_Money::display($price_cents)) . '</a>';
        }
        return ob_get_clean();
    }

    // Clamps to this purchase's own floor; acts only on PWYW-enabled purchase products.
    /**
     * @param array<string, mixed> $cart_item_data
     * @return array<string, mixed>
     */
    public static function apply_purchase_amount($cart_item_data, int $product_id): array {
        $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
        if (!$object_id || !get_post_meta($object_id, '_bhm_purchase_pwyw', true)) return $cart_item_data;
        $floor_cents = (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true);
        if (!$floor_cents) return $cart_item_data;

        $requested_cents = isset($_GET['bhm_purchase_amount']) ? BHM_Money::parse($_GET['bhm_purchase_amount']) : $floor_cents;
        $cents = max($floor_cents, min(self::PURCHASE_MAX_CENTS, $requested_cents));
        $cart_item_data['bhm_purchase_cents'] = $cents;
        return $cart_item_data;
    }

    public static function apply_purchase_price(\WC_Cart $cart): void {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $item) {
            if (!isset($item['bhm_purchase_cents'])) continue;
            $product_id = $item['data']->get_id();
            $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
            $floor_cents = $object_id ? (int) get_post_meta($object_id, '_bhm_purchase_price_cents', true) : 0;
            if (!$floor_cents) continue; // re-check at calculation time too — the price could have changed since add-to-cart
            $cents = max($floor_cents, min(self::PURCHASE_MAX_CENTS, (int) $item['bhm_purchase_cents']));
            $item['data']->set_price(BHM_Money::price($cents));
        }
    }

    /* ---------- wallet ---------- */

    public static function render_wallet(): string {
        if (!is_user_logged_in()) return '<p>Log in to see your play-credit wallet.</p>';
        $user_id = get_current_user_id();
        $balance = BHM_Wallet::balance_cents($user_id);
        $topup_options = get_option('bhm_wallet_topup_options', []);
        $topup_products = get_option('bhm_wallet_topup_products', []); // cents => wc_product_id, built by sync_wallet_topup_products()

        ob_start();
        echo '<div class="bhm-wallet">';
        echo '<p class="bhm-wallet-balance">Balance: $' . esc_html(BHM_Money::display($balance)) . '</p>';
        if (BH_Commerce::available() && $topup_options) {
            echo '<div class="bhm-wallet-topups">';
            foreach ($topup_options as $cents => $price) {
                $product_id = (int) ($topup_products[$cents] ?? 0);
                $exists = BH_Commerce::product_exists($product_id);
                if (!$product_id || !$exists) continue; // not synced yet — admin needs to save settings once
                echo '<a class="bhm-btn" href="' . esc_url(self::add_to_cart_url($product_id)) . '">Add $' . esc_html(BHM_Money::display($cents)) . ' credit — $' . esc_html(number_format((float) $price, 2)) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---------- wallet top-up product sync ---------- */

    // One hidden product per top-up amount, tagged with the wallet cents it grants.
    // $price is a decimal dollar string from settings, converted to cents.
    public static function sync_wallet_topup_products(): void {
        if (!BH_Commerce::available()) return;
        $options = get_option('bhm_wallet_topup_options', []);
        $existing = get_option('bhm_wallet_topup_products', []); // cents => product_id
        $new_map = [];
        $use_commerce = class_exists('BH_Commerce');

        foreach ($options as $cents => $price) {
            $product_id = $existing[$cents] ?? 0;
            $name = 'Play Credit — $' . BHM_Money::display($cents);
            $price_cents = BHM_Money::parse($price);

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

    public static function register_routes(): void {
        register_rest_route('bhm/v1', '/wallet', [
            'methods' => 'GET', 'callback' => [self::class, 'get_wallet'], 'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function get_wallet(): \WP_REST_Response {
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
