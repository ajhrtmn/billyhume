<?php
if (!defined('ABSPATH')) exit;

/**
 * Auction listings — ROADMAP-platform-evolution.md Section 5a, the
 * item STATUS.md flagged as "sequenced last." Agreed shape (2026-08-01)
 * before any of this was written:
 *
 *  1. Payment authorization via BHM_Wallet holds (class-wallet.php's new
 *     hold()/release_hold()/capture_hold()), not a real payment-gateway
 *     auth-hold — a gateway hold typically expires after ~7 days, which
 *     breaks for any auction running longer than that, and there was no
 *     existing gateway-hold integration to build on. A real gateway-hold
 *     path for non-wallet buyers is a later v2, not this pass.
 *  2. An auction listing IS a real WooCommerce product with an "auction"
 *     mode layered on via postmeta — NOT a new custom post type. This
 *     reuses the storefront/purchase-ledger machinery that already
 *     exists rather than duplicating it for a new post type, matching
 *     the platform-generalization decision made the same day (favor
 *     reusable machinery over vertical-specific one-offs).
 *  3. Closing precision: bh-contest's own round-closing was checked
 *     first for precedent and turned out to be ALWAYS human-initiated
 *     (BH_Rounds::window_status() only gates the UI live, on every page
 *     load — advancing a round needs an admin's click). That doesn't
 *     work for an auction nobody's going to babysit. This class combines
 *     that same lazy, live status-check pattern (status() below) for
 *     immediate UI correctness regardless of cron lag, with OUS_Jobs
 *     (already vendored Action Scheduler, see class-jobs.php) actually
 *     doing the automated close — not a raw wp_schedule_event.
 *
 * This pass covers the backend engine only (wallet-hold bidding,
 * status computation, scheduled close/finalization) — a product-edit
 * metabox for authoring an auction and a front-end bid form are the
 * next pass, not yet built.
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

    public static function init(): void {
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bhm_close_auction', [self::class, 'close_auction']);
        }
    }

    public static function is_auction(int $product_id): bool {
        return (bool) get_post_meta($product_id, self::META_IS_AUCTION, true);
    }

    /**
     * Turns an existing WooCommerce product into an auction listing —
     * deliberately not "create a product," since authoring (title,
     * description, images) is exactly what the normal product-edit
     * screen already does; this only layers the auction-specific meta
     * on top and schedules the automated close.
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
     * Live, computed status — never trust a stored "is this closed"
     * flag for whether bidding should still be accepted, same lazy-
     * check reasoning bh-contest's own BH_Rounds::window_status()
     * already established as this ecosystem's precedent for exactly
     * this problem (deadline passage should never depend on a cron job
     * having actually fired yet). Same technique too: a plain STRING
     * comparison against current_time('mysql') — both sides are
     * site-local MySQL datetime strings, which sort lexicographically
     * the same as chronologically, so there's no GMT-offset conversion
     * to get wrong. schedule_close()'s own delay-seconds math (below)
     * is the one place this class actually needs a real timestamp, and
     * converts explicitly for that reason.
     *
     * Returns one of: 'not_auction' | 'open' | 'awaiting_close' | 'closed'.
     * 'awaiting_close' is the honest middle state between the deadline
     * passing and OUS_Jobs actually running close_auction() — bidding
     * is already refused, but the winner isn't finalized yet.
     */
    public static function status(int $product_id): string {
        if (!self::is_auction($product_id)) return 'not_auction';
        if (get_post_meta($product_id, self::META_FINALIZED, true)) return 'closed';

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

    /**
     * Places a bid — holds the bidder's funds FIRST (BHM_Wallet::hold(),
     * which is itself the atomic, TOCTOU-safe check for "can they
     * actually afford this bid"), then only on success does it release
     * the previous highest bidder's own hold. Ordering matters: holding
     * first means a failed hold never touches the previous bidder's
     * money at all, rather than releasing it and then discovering the
     * new bid can't actually be backed.
     *
     * @return true|WP_Error
     */
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
        // No bid yet: the FIRST bid only needs to meet the starting
        // price, not beat it (there's nothing to beat). Every bid after
        // that must strictly exceed the current highest.
        $minimum = $has_prior_bid ? $current_bid + 1 : $current_bid;
        if ($amount_cents < $minimum) {
            return new WP_Error('bhm_auction_bid_too_low', 'Your bid must be at least ' . number_format($minimum / 100, 2) . '.');
        }

        if (!class_exists('BHM_Wallet') || !BHM_Wallet::hold($user_id, $amount_cents, 'auction_hold', $product_id)) {
            return new WP_Error('bhm_auction_insufficient_funds', 'Your available wallet balance can\'t cover this bid.');
        }

        $previous_bidder_id = self::current_bidder_id($product_id);
        $previous_bid_cents = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        if ($previous_bidder_id && $previous_bid_cents > 0) {
            BHM_Wallet::release_hold($previous_bidder_id, $previous_bid_cents, 'auction_outbid', $product_id);
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

    /**
     * OUS_Jobs handler, registered in init() above — runs once, at (or
     * shortly after) the auction's own end time. Idempotent: a second
     * call against an already-finalized auction is a harmless no-op,
     * since neither a stray duplicate job nor a manual re-run should
     * ever be able to double-capture or double-release funds.
     */
    /** @param array<string, mixed> $args */
    public static function close_auction($args): void {
        $product_id = (int) ($args['product_id'] ?? 0);
        if (!$product_id || get_post_meta($product_id, self::META_FINALIZED, true)) return;

        $winner_id = self::current_bidder_id($product_id);
        $winning_bid = (int) get_post_meta($product_id, self::META_CURRENT_BID_CENTS, true);
        $reserve = (int) get_post_meta($product_id, self::META_RESERVE_CENTS, true);
        $sold = $winner_id && $winning_bid > 0 && $winning_bid >= $reserve;

        if ($winner_id && $winning_bid > 0) {
            if ($sold && class_exists('BHM_Wallet')) {
                BHM_Wallet::capture_hold($winner_id, $winning_bid, 'auction_capture', $product_id);
                self::mark_bid_status($product_id, $winner_id, $winning_bid, 'won');
            } elseif (class_exists('BHM_Wallet')) {
                // Reserve not met — the auction simply doesn't sell, the
                // would-be winner's hold is released like any other
                // outbid/lost bid, never captured.
                BHM_Wallet::release_hold($winner_id, $winning_bid, 'auction_reserve_not_met', $product_id);
                self::mark_bid_status($product_id, $winner_id, $winning_bid, 'refunded');
            }
        }

        update_post_meta($product_id, self::META_FINALIZED, 1);
        update_post_meta($product_id, self::META_WINNER_ID, $sold ? $winner_id : 0);

        if (class_exists('BH_Event')) {
            BH_Event::emit('bhm/auction_closed', [
                'user_id' => $sold ? $winner_id : 0, 'subject_type' => 'product', 'subject_id' => $product_id,
                'payload' => ['sold' => $sold, 'winning_bid_cents' => $sold ? $winning_bid : 0],
            ]);
        }
    }
}
