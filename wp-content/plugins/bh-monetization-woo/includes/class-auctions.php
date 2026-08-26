<?php
if (!defined('ABSPATH')) exit;

/**
 * Auction listings — ROADMAP-platform-evolution.md Section 5a, the
 * item STATUS.md flagged as "sequenced last." Agreed shape (2026-08-01)
 * before any of this was written, REVISED 2026-08-26 on payment timing
 * (see below):
 *
 *  1. PAYMENT TIMING — REVISED 2026-08-26. The original 2026-08-01 shape
 *     used BHM_Wallet holds (hold()/release_hold()/capture_hold()) at
 *     bid time — authorize on every bid, capture only the winner's hold
 *     at close. Explicit standing instruction from the project owner:
 *     "charge on win is important" — bids no longer place a hold at
 *     all; place_bid() only does a soft available-balance check as a
 *     courtesy (not a commitment), and close_auction() charges the
 *     winner directly via BHM_Wallet::debit() (the same atomic,
 *     TOCTOU-safe primitive bh-streaming's pay-per-play already uses)
 *     at the moment the auction closes. Real, accepted trade-off: a
 *     winner's balance can change between their bid and the close, so
 *     debit() can fail at close time — that auction simply closes
 *     unsold (bid status 'payment_failed') rather than falling back to
 *     the next-highest bidder (a real v2 gap, not attempted here).
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
 *  4. FRAUD GATE — added 2026-08-26. BHM_Fraud has no synchronous
 *     "block this purchase" gate (it's a reactive, post-refund
 *     pattern tracker — see its own docblock), so the real integration
 *     point is its existing per-user flags: a winner already flagged
 *     via `_bhm_refund_flagged` or `_bhm_refund_shared_device_flagged`
 *     skips the automatic charge and goes to a human-reviewed
 *     'awaiting_review' state instead — same "flag for review, never
 *     auto-restrict" posture BHM_Fraud's own docblock establishes.
 *     BHM_AuctionAdmin::handle_resolve_flagged_auction() is where a
 *     human actually approves (charge) or rejects (unsold) it.
 *
 * Product-edit metabox (BHM_AuctionAdmin, class-auction-admin.php) and
 * front-end bid form (BHM_AuctionFrontend, class-auction-frontend.php)
 * shipped 2026-08-26 alongside the payment-timing/fraud-gate revisions
 * above.
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
     * Returns one of: 'not_auction' | 'open' | 'awaiting_close' |
     * 'awaiting_review' | 'closed'. 'awaiting_close' is the honest
     * middle state between the deadline passing and OUS_Jobs actually
     * running close_auction() — bidding is already refused, but the
     * winner isn't finalized yet. 'awaiting_review' is the same idea
     * for a winner the fraud gate flagged — close_auction() already
     * ran, but a human still has to approve or reject before this
     * finalizes (see the class docblock's fraud-gate note).
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

    /**
     * Places a bid — charge-on-win (see class docblock): a bid does NOT
     * hold funds. This only does a soft available-balance courtesy
     * check (BHM_Wallet::available_cents()) so a bidder isn't offering
     * an amount they obviously can't cover right now — the real,
     * binding check is the atomic BHM_Wallet::debit() call in
     * close_auction() at the moment they'd actually be charged. A
     * balance that changes between this bid and the close is the
     * accepted trade-off documented in the class docblock.
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

    /**
     * OUS_Jobs handler, registered in init() above — runs once, at (or
     * shortly after) the auction's own end time. Idempotent: a second
     * call against an already-finalized OR already-flagged-for-review
     * auction is a harmless no-op.
     *
     * Charge-on-win (see class docblock): no hold to capture/release
     * here anymore — a would-be winner not flagged for fraud review is
     * charged directly via BHM_Wallet::debit(), the same atomic,
     * TOCTOU-safe primitive pay-per-play already uses. A flagged winner
     * skips the automatic charge entirely and this auction sits in
     * 'awaiting_review' until BHM_AuctionAdmin::handle_resolve_flagged_auction()
     * resolves it by hand.
     */
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
            // No bids, or reserve not met — nothing was ever held, so
            // there's nothing to release; the auction just doesn't sell.
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

    /** Actually charges the winner and finalizes — shared by close_auction() and the admin's manual "approve" resolution. */
    private static function charge_and_finalize(int $product_id, int $winner_id, int $winning_bid): void {
        $charged = class_exists('BHM_Wallet') && BHM_Wallet::debit($winner_id, $winning_bid, $product_id, 'auction_win');
        self::mark_bid_status($product_id, $winner_id, $winning_bid, $charged ? 'won' : 'payment_failed');
        self::finalize($product_id, $charged ? $winner_id : 0, $charged);
    }

    /** Admin resolution for an 'awaiting_review' auction — approve charges the winner, reject leaves it unsold. Both clear the review flag and finalize. */
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
