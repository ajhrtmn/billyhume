<?php
if (!defined('ABSPATH')) exit;

/**
 * Product-edit authoring UI for BHM_Auctions (class-auctions.php) — the
 * "next pass" that class's own docblock flagged as not yet built. Same
 * metabox shape as BHM_PostGate's own on 'product' instead of post/page
 * (add_meta_box on 'product' post type, save_post handler, nonce).
 *
 * Also owns the one human-in-the-loop action the fraud gate needs:
 * approving or rejecting a winning bid BHM_Auctions flagged for review
 * (see that class's charge-on-win/fraud-gate docblock notes).
 */
class BHM_AuctionAdmin {
    public static function init(): void {
        if (!self::available()) return;
        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('save_post_product', [self::class, 'save_metabox']);
        add_action('admin_post_bhm_resolve_flagged_auction', [self::class, 'handle_resolve_flagged_auction']);
    }

    public static function add_metabox(): void {
        add_meta_box('bhm_auction', 'Auction listing', [self::class, 'render_metabox'], 'product', 'normal', 'high');
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhm_auction_save', 'bhm_auction_nonce');
        $product_id = $post->ID;
        $is_auction = BHM_Auctions::is_auction($product_id);
        $status = BHM_Auctions::status($product_id);

        echo '<p><label><input type="checkbox" name="bhm_is_auction" value="1" ' . checked($is_auction, true, false) . ' id="bhm_is_auction_cb"> Sell this product as an auction, not a fixed price</label></p>';

        if ($is_auction && $status !== 'not_auction') {
            echo '<p><strong>Status:</strong> ' . esc_html(self::status_label($status)) . '</p>';
            if ($status === 'awaiting_review') {
                $winner_id = BHM_Auctions::current_bidder_id($product_id);
                $winner = get_userdata($winner_id);
                echo '<div class="notice notice-warning inline"><p>Winning bidder' . ($winner ? ' <strong>' . esc_html($winner->display_name) . '</strong>' : ' #' . (int) $winner_id) . ' was flagged by the fraud-pattern tracker (repeat refunds or a shared device with another flagged account). The winning bid was NOT auto-charged. Review the account in bh-crm, then:</p>';
                echo '<p>';
                echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhm_resolve_flagged_auction&product_id=' . $product_id . '&decision=approve'), 'bhm_resolve_flagged_auction_' . $product_id)) . '" onclick="return confirm(\'Charge the winning bidder and finalize this sale?\');">Approve &amp; charge winner</a> ';
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhm_resolve_flagged_auction&product_id=' . $product_id . '&decision=reject'), 'bhm_resolve_flagged_auction_' . $product_id)) . '" onclick="return confirm(\'Reject this bid? The auction will close unsold.\');">Reject (unsold)</a>';
                echo '</p></div>';
            }
        }

        $starting = (int) get_post_meta($product_id, BHM_Auctions::META_STARTING_CENTS, true);
        $reserve = (int) get_post_meta($product_id, BHM_Auctions::META_RESERVE_CENTS, true);
        $ends_at = (string) get_post_meta($product_id, BHM_Auctions::META_ENDS_AT, true);

        $locked = $is_auction && !in_array($status, ['open'], true) && $status !== 'not_auction';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bhm_auction_starting">Starting price</label></th><td>$<input type="number" step="0.01" min="0" id="bhm_auction_starting" name="bhm_auction_starting" value="' . esc_attr($starting > 0 ? number_format($starting / 100, 2, '.', '') : '') . '" class="small-text"></td></tr>';
        echo '<tr><th><label for="bhm_auction_reserve">Reserve price</label></th><td>$<input type="number" step="0.01" min="0" id="bhm_auction_reserve" name="bhm_auction_reserve" value="' . esc_attr($reserve > 0 ? number_format($reserve / 100, 2, '.', '') : '') . '" class="small-text"><p class="description">0 = no reserve. Never shown to bidders. If the highest bid doesn\'t meet this, the item doesn\'t sell.</p></td></tr>';
        echo '<tr><th><label for="bhm_auction_ends">Ends at</label></th><td><input type="datetime-local" id="bhm_auction_ends" name="bhm_auction_ends" value="' . esc_attr($ends_at ? str_replace(' ', 'T', substr($ends_at, 0, 16)) : '') . '"><p class="description">Site local time. Bidding closes automatically at this time.</p></td></tr>';
        echo '</tbody></table>';
        if ($locked) {
            echo '<p class="description">This auction already has bids or has closed — starting price, reserve, and end time are locked to protect bidders who bid against the terms shown when they bid. Uncheck the box above and save to cancel it instead.</p>';
        }
        echo '<p class="description">Bidding is charge-on-win: no funds are held when a bid is placed, only when the winner is charged at close. If a wallet balance can\'t cover the winning bid at that moment, the auction closes unsold rather than falling back to the next bidder.</p>';
    }

    public static function save_metabox(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_auction_nonce']) || !wp_verify_nonce($_POST['bhm_auction_nonce'], 'bhm_auction_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $wants_auction = !empty($_POST['bhm_is_auction']);
        $was_auction = BHM_Auctions::is_auction($post_id);
        $status = BHM_Auctions::status($post_id);

        if (!$wants_auction) {
            if ($was_auction) delete_post_meta($post_id, BHM_Auctions::META_IS_AUCTION);
            return;
        }

        // Already has bids or has closed — terms are locked, don't let
        // an edit change what bidders already bid against.
        if ($was_auction && !in_array($status, ['open', 'not_auction'], true)) return;

        $starting_cents = (int) round(((float) ($_POST['bhm_auction_starting'] ?? 0)) * 100);
        $reserve_cents = (int) round(((float) ($_POST['bhm_auction_reserve'] ?? 0)) * 100);
        $ends_raw = sanitize_text_field($_POST['bhm_auction_ends'] ?? '');
        $ends_mysql = $ends_raw ? str_replace('T', ' ', $ends_raw) . ':00' : '';

        if ($starting_cents <= 0 || !$ends_mysql) return; // incomplete form, nothing sane to save yet

        BHM_Auctions::convert_to_auction($post_id, $starting_cents, $reserve_cents, $ends_mysql);
    }

    public static function handle_resolve_flagged_auction(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $product_id = (int) ($_GET['product_id'] ?? 0);
        check_admin_referer('bhm_resolve_flagged_auction_' . $product_id);

        $decision = sanitize_text_field($_GET['decision'] ?? '');
        BHM_Auctions::resolve_flagged_auction($product_id, $decision === 'approve');

        wp_safe_redirect(get_edit_post_link($product_id, 'raw') ?: admin_url('edit.php?post_type=product'));
        exit;
    }

    public static function status_label(string $status): string {
        return [
            'open' => 'Open — accepting bids',
            'awaiting_close' => 'Closing — bidding ended, finalizing shortly',
            'awaiting_review' => 'Awaiting review — winning bid flagged, see below',
            'closed' => 'Closed',
        ][$status] ?? ucfirst($status);
    }

    private static function available(): bool {
        return class_exists('BHM_Auctions') && class_exists('BH_Commerce') && BH_Commerce::available();
    }
}
