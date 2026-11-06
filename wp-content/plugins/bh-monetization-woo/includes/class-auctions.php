<?php
if (!defined('ABSPATH')) exit;

/**
 * Auction listings: a WooCommerce product with auction postmeta, closed by an OUS_Jobs job.
 * - Charge-on-win: bids hold nothing; close_auction() debits the winner. A failed debit leaves it unsold.
 * - status() is computed live, so bidding stops at the deadline even if the close job is late.
 * - A winner carrying a BHM_Fraud refund flag goes to 'awaiting_review' for a human to resolve.
 */
class BHM_Auctions {
    const META_IS_AUCTION = '_bhm_is_auction';
    const META_STARTING_CENTS = '_bhm_auction_starting_cents';
    const META_RESERVE_CENTS = '_bhm_auction_reserve_cents';
    const META_ENDS_AT = '_bhm_auction_ends_at';
    const META_CURRENT_BID_CENTS = '_bhm_auction_current_bid_cents';
    const META_CURRENT_BIDDER_ID = '_bhm_auction_current_bidder_id';
    const META_FINALIZED = '_bhm_auction_finalized';
    const META_WINNER_ID = '_bhm_auction_winner_id';
    const META_NEEDS_REVIEW = '_bhm_auction_needs_review';

    public static function init(): void {
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bhm_close_auction', [self::class, 'close_auction']);
        }
    }

    public static function is_auction(int $product_id): bool {
        return (bool) get_post_meta($product_id, self::META_IS_AUCTION, true);
    }

    /**
     * Layers auction meta onto an existing product and schedules the close.
     *
     * @param int    $product_id
     * @param int    $starting_cents Opening bid floor — the first bid must meet or beat this.
     * @param int    $reserve_cents  Minimum winning bid for a sale to actually happen (0 = no reserve). Never shown to bidders.
     * @param string $ends_at_mysql  MySQL datetime (site local time, matches current_time('mysql')) — when bidding closes.
     * @return bool
     */
    public static function convert_to_auction(int $product_id, int $starting_cents, int $reserve_cents, string $ends_at_mysql): bool {
        if (!$product_id || !$ends_at_mysql) return false;
        $ends_timestamp = strtotime(get_gmt_from_date($ends_at_mysql) . ' UTC');
        if (!$ends_timestamp || $ends_timestamp <= time()) return false;

        update_post_meta($product_id, self::META_IS_AUCTION, 1);
        update_post_meta($product_id, self::META_STARTING_CENTS, (int) $starting_cents);
        update_post_meta($product_id, self::META_RESERVE_CENTS, (int) $reserve_cents);
        update_post_meta($product_id, self::META_ENDS_AT, $ends_at_mysql);
        delete_post_meta($product_id, self::META_FINALIZED);
        delete_post_meta($product_id, self::META_WINNER_ID);
        delete_post_meta($product_id, self::META_CURRENT_BID_CENTS);
        delete_post_meta($product_id, self::META_CURRENT_BIDDER_ID);

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::enqueue('bhm_close_auction', ['product_id' => (int) $product_id], max(0, $ends_timestamp - time()));
        }
        return true;
    }

    /**
     * Live status: 'not_auction' | 'open' | 'awaiting_close' (deadline passed, job not yet run) |
     * 'awaiting_review' (fraud-flagged winner) | 'closed'.
     * Plain string compare is safe: both sides are site-local MySQL datetimes.
     */
    public static function status(int $product_id): string {
        if (!self::is_auction($product_id)) return 'not_auction';
        if (get_post_meta($product_id, self::META_FINALIZED, true)) return 'closed';
        if (get_post_meta($product_id, self::META_NEEDS_REVIEW, true)) return 'awaiting_review';

        $ends_at = get_post_meta($product_id, self::META_ENDS_AT, true);
        if (!$ends_at) return 'awaiting_close';
        return (current_time('mysql') < $ends_at) ? 'open' : 'awaiting_close';
    }

    public static function current_bid_cents(int $product_id): int {
        $starting = (int) get_post_meta($product_id, self::META_STARTING_CENTS, true);
        $current = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        return $current > 0 ? $current : $starting;
    }

    public static function current_bidder_id(int $product_id): int {
        return (int) get_post_meta($product_id, self::META_CURRENT_BIDDER_ID, true);
    }

    // Holds no funds; the balance check is a courtesy, the binding check is debit() at close.
    /**
     * @param mixed $amount_cents
     * @return true|\WP_Error
     */
    public static function place_bid(int $product_id, int $user_id, $amount_cents) {
        $amount_cents = (int) $amount_cents;
        $status = self::status($product_id);
        if ($status !== 'open') {
            return new WP_Error('bhm_auction_closed', 'This auction is no longer accepting bids.');
        }

        $current_bid = self::current_bid_cents($product_id);
        $has_prior_bid = (bool) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        // First bid may equal the starting price; later bids must exceed the current high.
        $minimum = $has_prior_bid ? $current_bid + 1 : $current_bid;
        if ($amount_cents < $minimum) {
            return new WP_Error('bhm_auction_bid_too_low', 'Your bid must be at least ' . number_format($minimum / 100, 2) . '.');
        }

        if (!class_exists('BHM_Wallet') || BHM_Wallet::available_cents($user_id) < $amount_cents) {
            return new WP_Error('bhm_auction_insufficient_funds', 'Your available wallet balance can\'t cover this bid yet.');
        }

        $previous_bidder_id = self::current_bidder_id($product_id);
        $previous_bid_cents = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        if ($previous_bidder_id && $previous_bid_cents > 0) {
            self::mark_bid_status($product_id, $previous_bidder_id, $previous_bid_cents, 'outbid');
        }

        update_post_meta($product_id, self::META_CURRENT_BID_CENTS, $amount_cents);
        update_post_meta($product_id, self::META_CURRENT_BIDDER_ID, $user_id);

        global $wpdb;
        $wpdb->insert(BHM_Tables::auction_bids(), [
            'product_id' => $product_id, 'user_id' => $user_id, 'amount_cents' => $amount_cents, 'status' => 'active',
        ]);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bhm/auction_bid_placed', [
                'user_id' => $user_id, 'subject_type' => 'product', 'subject_id' => $product_id,
                'payload' => ['amount_cents' => $amount_cents],
            ]);
        }

        return true;
    }

    private static function mark_bid_status(int $product_id, int $user_id, int $amount_cents, string $status): void {
        global $wpdb;
        $wpdb->update(
            BHM_Tables::auction_bids(),
            ['status' => $status],
            ['product_id' => $product_id, 'user_id' => $user_id, 'amount_cents' => $amount_cents, 'status' => 'active']
        );
    }

    /** OUS_Jobs handler at end time. Idempotent: no-op once finalized or flagged for review. */
    /** @param array<string, mixed> $args */
    public static function close_auction($args): void {
        $product_id = (int) ($args['product_id'] ?? 0);
        if (!$product_id) return;
        if (get_post_meta($product_id, self::META_FINALIZED, true)) return;
        if (get_post_meta($product_id, self::META_NEEDS_REVIEW, true)) return;

        $winner_id = self::current_bidder_id($product_id);
        $winning_bid = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        $reserve = (int) get_post_meta($product_id, self::META_RESERVE_CENTS, true);
        $meets_reserve = $winner_id && $winning_bid > 0 && $winning_bid >= $reserve;

        if (!$meets_reserve) {
            if ($winner_id && $winning_bid > 0) self::mark_bid_status($product_id, $winner_id, $winning_bid, 'reserve_not_met');
            self::finalize($product_id, 0, false);
            return;
        }

        if (self::winner_is_fraud_flagged($winner_id)) {
            update_post_meta($product_id, self::META_NEEDS_REVIEW, 1);
            self::mark_bid_status($product_id, $winner_id, $winning_bid, 'won_pending_review');
            if (class_exists('BH_Event')) {
                BH_Event::emit('bhm/auction_flagged_for_review', [
                    'user_id' => $winner_id, 'subject_type' => 'product', 'subject_id' => $product_id,
                    'payload' => ['winning_bid_cents' => $winning_bid],
                ]);
            }
            return;
        }

        self::charge_and_finalize($product_id, $winner_id, $winning_bid);
    }

    private static function winner_is_fraud_flagged(int $user_id): bool {
        if (!$user_id || !class_exists('BHM_Fraud')) return false;
        return (bool) get_user_meta($user_id, '_bhm_refund_flagged', true)
            || (bool) get_user_meta($user_id, '_bhm_refund_shared_device_flagged', true);
    }

    /** Shared by close_auction() and the admin "approve" resolution. */
    private static function charge_and_finalize(int $product_id, int $winner_id, int $winning_bid): void {
        $charged = class_exists('BHM_Wallet') && BHM_Wallet::debit($winner_id, $winning_bid, $product_id, 'auction_win');
        self::mark_bid_status($product_id, $winner_id, $winning_bid, $charged ? 'won' : 'payment_failed');
        self::finalize($product_id, $charged ? $winner_id : 0, $charged);
    }

    /** Resolve an 'awaiting_review' auction: approve charges the winner, reject leaves it unsold. */
    public static function resolve_flagged_auction(int $product_id, bool $approve): void {
        if (!get_post_meta($product_id, self::META_NEEDS_REVIEW, true)) return;
        delete_post_meta($product_id, self::META_NEEDS_REVIEW);

        $winner_id = self::current_bidder_id($product_id);
        $winning_bid = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);

        if ($approve && $winner_id && $winning_bid > 0) {
            self::charge_and_finalize($product_id, $winner_id, $winning_bid);
        } else {
            if ($winner_id && $winning_bid > 0) self::mark_bid_status($product_id, $winner_id, $winning_bid, 'rejected');
            self::finalize($product_id, 0, false);
        }
    }

    private static function finalize(int $product_id, int $winner_id, bool $sold): void {
        update_post_meta($product_id, self::META_FINALIZED, 1);
        update_post_meta($product_id, self::META_WINNER_ID, $sold ? $winner_id : 0);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bhm/auction_closed', [
                'user_id' => $sold ? $winner_id : 0, 'subject_type' => 'product', 'subject_id' => $product_id,
                'payload' => ['sold' => $sold],
            ]);
        }
    }
}
