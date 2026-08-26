<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end bid form + status display for BHM_Auctions (class-
 * auctions.php) — the other half of the "next pass" that class's own
 * docblock flagged as not yet built (see class-auction-admin.php for
 * the product-edit authoring half).
 *
 * An auction product is never purchasable via the normal Add to Cart
 * form (woocommerce_is_purchasable filter below) — bidding replaces
 * checkout entirely for these; WooCommerce still owns the product's
 * title/images/description/category the same as any other product.
 *
 * Plain admin-post.php form submission, not AJAX/REST — this is one
 * infrequent user action per bid, not the kind of live-updating
 * surface (bh-contest's vote counter, a chat) that justifies a JS
 * round-trip in this codebase's own established convention.
 */
class BHM_AuctionFrontend {
    public static function init(): void {
        if (!self::available()) return;
        add_filter('woocommerce_is_purchasable', [self::class, 'auction_not_purchasable'], 10, 2);
        add_action('woocommerce_single_product_summary', [self::class, 'render_bid_section'], 25);
        add_action('admin_post_bhm_place_bid', [self::class, 'handle_place_bid']);
        add_action('admin_post_nopriv_bhm_place_bid', [self::class, 'handle_place_bid_nopriv']);
    }

    public static function auction_not_purchasable(bool $purchasable, \WC_Product $product): bool {
        return BHM_Auctions::is_auction($product->get_id()) ? false : $purchasable;
    }

    public static function render_bid_section(): void {
        $product_id = get_the_ID();
        if (!$product_id || !BHM_Auctions::is_auction($product_id)) return;

        $status = BHM_Auctions::status($product_id);
        $current_cents = BHM_Auctions::current_bid_cents($product_id);
        $has_bids = (bool) get_post_meta($product_id, BHM_Auctions::META_CURRENT_BID_CENTS, true);

        echo '<div class="bhm-auction">';
        echo '<p><strong>' . ($has_bids ? 'Current bid: ' : 'Starting price: ') . '$' . esc_html(number_format($current_cents / 100, 2)) . '</strong></p>';

        $ends_at = get_post_meta($product_id, BHM_Auctions::META_ENDS_AT, true);
        if ($ends_at && $status === 'open') {
            echo '<p class="description">Ends ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($ends_at))) . '</p>';
        }

        if (isset($_GET['bhm_bid'])) {
            if ($_GET['bhm_bid'] === 'ok') echo '<div class="notice notice-success inline"><p>Bid placed — you\'re the current high bidder.</p></div>';
            elseif (!empty($_GET['bhm_bid_error'])) echo '<div class="notice notice-error inline"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhm_bid_error']))) . '</p></div>';
        }

        if ($status === 'open') {
            if (!is_user_logged_in()) {
                echo '<p><a class="button" href="' . esc_url(wp_login_url(get_permalink($product_id))) . '">Log in to bid</a></p>';
            } else {
                $minimum = ($has_bids ? $current_cents + 1 : $current_cents) / 100;
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('bhm_place_bid_' . $product_id);
                echo '<input type="hidden" name="action" value="bhm_place_bid"><input type="hidden" name="product_id" value="' . (int) $product_id . '">';
                echo '<p><label>Your bid ($' . esc_html(number_format($minimum, 2)) . ' minimum)<br>$<input type="number" step="0.01" min="' . esc_attr((string) $minimum) . '" name="amount" required></label></p>';
                submit_button('Place Bid', 'primary', 'submit', false);
                echo '</form>';
            }
        } elseif ($status === 'awaiting_close') {
            echo '<p class="description">Bidding has ended — finalizing shortly.</p>';
        } elseif ($status === 'awaiting_review') {
            echo '<p class="description">Bidding has ended — this sale is under review.</p>';
        } elseif ($status === 'closed') {
            $winner_id = (int) get_post_meta($product_id, BHM_Auctions::META_WINNER_ID, true);
            if ($winner_id && $winner_id === get_current_user_id()) {
                echo '<div class="notice notice-success inline"><p>You won this auction.</p></div>';
            } elseif ($winner_id) {
                echo '<p class="description">This auction has closed — sold.</p>';
            } else {
                echo '<p class="description">This auction has closed — not sold.</p>';
            }
        }
        echo '</div>';
    }

    public static function handle_place_bid_nopriv(): void {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        wp_safe_redirect(wp_login_url(get_permalink($product_id) ?: home_url('/')));
        exit;
    }

    public static function handle_place_bid(): void {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        check_admin_referer('bhm_place_bid_' . $product_id);

        $redirect = get_permalink($product_id) ?: home_url('/');
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($redirect));
            exit;
        }

        $amount_cents = (int) round(((float) ($_POST['amount'] ?? 0)) * 100);
        $result = BHM_Auctions::place_bid($product_id, get_current_user_id(), $amount_cents);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['bhm_bid' => 'error', 'bhm_bid_error' => rawurlencode($result->get_error_message())], $redirect));
        } else {
            wp_safe_redirect(add_query_arg('bhm_bid', 'ok', $redirect));
        }
        exit;
    }

    private static function available(): bool {
        return class_exists('BHM_Auctions') && class_exists('BH_Commerce') && BH_Commerce::available() && class_exists('WC_Product');
    }
}
