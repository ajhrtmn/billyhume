<?php
if (!defined('ABSPATH')) exit;

/**
 * A prepaid credit wallet, backing pay-as-you-listen. Topped up via a
 * normal WooCommerce product (see BHM_Frontend's wallet-topup product
 * + on_order_completed() in class-products.php), debited per play.
 * Deliberately its OWN small ledger rather than reusing bhm_entitlements
 * — an entitlement is "can you access this at all," a wallet debit is
 * "you did access it and here's what it cost," a genuinely different
 * kind of row queried differently (a running balance vs. a yes/no check).
 */
class BHM_Wallet {
    public static function init(): void {
        // Pure API class, no hooks of its own to register — see
        // BHM_Gate::init() for the same reasoning.
    }

    public static function balance_cents(int $user_id): int {
        global $wpdb;
        $bal = $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM " . BHM_Tables::wallet() . " WHERE user_id = %d", $user_id));
        return $bal === null ? 0 : (int) $bal;
    }

    public static function held_cents(int $user_id): int {
        global $wpdb;
        $held = $wpdb->get_var($wpdb->prepare("SELECT held_cents FROM " . BHM_Tables::wallet() . " WHERE user_id = %d", $user_id));
        return $held === null ? 0 : (int) $held;
    }

    /** What's actually still spendable/biddable right now — balance minus whatever's already committed to an open auction bid. */
    public static function available_cents(int $user_id): int {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT balance_cents, held_cents FROM " . BHM_Tables::wallet() . " WHERE user_id = %d", $user_id), ARRAY_A);
        return $row ? ((int) $row['balance_cents'] - (int) $row['held_cents']) : 0;
    }

    // Auction bidding (class-auctions.php's own use, 1.7): commits
    // $cents against a bid WITHOUT touching balance_cents yet — a bid
    // that gets outbid or loses only ever needs release_hold(), never a
    // separate refund/credit, because the spendable balance was never
    // actually debited. Same TOCTOU-safe shape as debit() above: the
    // "is there enough available to hold" check and the write are the
    // SAME atomic UPDATE, guarded by its own WHERE clause, so two bids
    // racing for the same low-available-balance user can't both pass a
    // prior read before either commits.
    public static function hold(int $user_id, int $cents, string $reason, ?int $ref_id = null): bool {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = BHM_Tables::wallet();

        $wpdb->query($wpdb->prepare(
            "UPDATE $w SET held_cents = held_cents + %d, updated_at = %s WHERE user_id = %d AND (balance_cents - held_cents) >= %d",
            $cents, current_time('mysql'), $user_id, $cents
        ));
        if ($wpdb->rows_affected !== 1) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Wallet hold declined — insufficient available balance or no wallet row exists for this user.', [
                    'user_id' => $user_id, 'cents' => $cents, 'reason' => $reason, 'ref_id' => $ref_id,
                ], 'BH Wallet');
            }
            return false;
        }

        $wpdb->insert(BHM_Tables::wallet_ledger(), [
            'user_id' => $user_id, 'delta_cents' => 0, 'reason' => $reason, 'track_id' => $ref_id,
        ]);
        // delta_cents is deliberately 0 here — a hold moves money between
        // "available" and "held," it never changes the actual
        // balance_cents total the ledger's delta_cents column tracks.
        // The ledger row still exists (reason + ref_id) purely as an
        // audit trail entry: "a hold happened, here's when and why."
        return true;
    }

    /** Releases a hold without ever touching balance_cents — the outbid/lost path. Floors at 0 so a duplicate release call can't drive held_cents negative. */
    public static function release_hold(int $user_id, int $cents, string $reason, ?int $ref_id = null): bool {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = BHM_Tables::wallet();

        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE $w SET held_cents = GREATEST(held_cents - %d, 0), updated_at = %s WHERE user_id = %d",
            $cents, current_time('mysql'), $user_id
        ));
        if ($ok !== false) {
            $wpdb->insert(BHM_Tables::wallet_ledger(), [
                'user_id' => $user_id, 'delta_cents' => 0, 'reason' => $reason, 'track_id' => $ref_id,
            ]);
        } elseif (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Wallet hold release failed — held_cents may now be overstated for this user.', [
                'user_id' => $user_id, 'cents' => $cents, 'reason' => $reason, 'ref_id' => $ref_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
        }
        return $ok !== false;
    }

    // The actual spend, once an auction closes in this bidder's favor —
    // moves the already-held amount out of BOTH held_cents AND
    // balance_cents together, in one atomic statement. Requires
    // held_cents >= $cents in its own WHERE clause rather than trusting
    // the caller's bookkeeping — a capture can only ever spend money
    // that was genuinely held for it.
    public static function capture_hold(int $user_id, int $cents, string $reason, ?int $ref_id = null): bool {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = BHM_Tables::wallet();

        $wpdb->query($wpdb->prepare(
            "UPDATE $w SET balance_cents = balance_cents - %d, held_cents = held_cents - %d, updated_at = %s WHERE user_id = %d AND held_cents >= %d",
            $cents, $cents, current_time('mysql'), $user_id, $cents
        ));
        if ($wpdb->rows_affected !== 1) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Wallet hold capture failed — held_cents was less than the amount being captured (a real bookkeeping bug, not a routine decline).', [
                    'user_id' => $user_id, 'cents' => $cents, 'reason' => $reason, 'ref_id' => $ref_id,
                ], 'BH Wallet');
            }
            return false;
        }

        $ledger_ok = $wpdb->insert(BHM_Tables::wallet_ledger(), [
            'user_id' => $user_id, 'delta_cents' => -$cents, 'reason' => $reason, 'track_id' => $ref_id,
        ]);
        if ($ledger_ok !== false && class_exists('BH_Event')) {
            BH_Event::emit('bhm/wallet_debit', [
                'user_id' => $user_id, 'subject_type' => 'bhm_wallet', 'subject_id' => $user_id,
                'payload' => ['cents' => $cents, 'reason' => $reason],
            ]);
        } elseif ($ledger_ok === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Wallet hold captured but the ledger row failed to insert — balance and ledger are now out of sync for this user.', [
                'user_id' => $user_id, 'cents' => $cents, 'reason' => $reason, 'ref_id' => $ref_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
        }
        return true;
    }

    public static function credit(int $user_id, int $cents, string $reason, ?int $track_id = null, ?int $order_id = null): void {
        self::apply_delta($user_id, abs((int) $cents), $reason, $track_id, $order_id);
        // Fraud/abuse velocity cap — only real purchased
        // top-ups count against this, not admin grants or refund-
        // reversal adjustments (apply_ledger_delta() below is the
        // separate entry point those use).
        if ($reason === 'topup' && class_exists('BHM_Fraud')) {
            BHM_Fraud::track_topup_velocity($user_id, abs((int) $cents));
        }
    }

    // Returns true if the debit succeeded (sufficient balance), false if
    // declined (insufficient funds) — the caller (BHM_Products' play-gate
    // check, wired through bh-streaming's /tracks/{id}/play flow) decides
    // what "declined" means for playback.
    //
    // Deliberately NOT "check balance, then separately write" (a real
    // TOCTOU race — two concurrent debits for a low-balance user could
    // both pass the check before either write commits, driving the
    // balance negative) — the check and the write are the SAME atomic
    // UPDATE statement, guarded by its own WHERE clause, with success
    // determined by $wpdb->rows_affected rather than a prior read.
    public static function debit(int $user_id, int $cents, ?int $track_id = null, string $reason = 'play'): bool {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = BHM_Tables::wallet();

        $wpdb->query($wpdb->prepare(
            "UPDATE $w SET balance_cents = balance_cents - %d, updated_at = %s WHERE user_id = %d AND balance_cents >= %d",
            $cents, current_time('mysql'), $user_id, $cents
        ));
        if ($wpdb->rows_affected !== 1) {
            // Previously silent — a declined debit (insufficient
            // balance, the expected/common case) and a genuinely missing
            // wallet row (a real data-integrity gap: a user with no
            // wallet row at all shouldn't be reachable via normal
            // sign-up/purchase flow) looked identical to every caller.
            // Logged at 'info' (not 'warning') since insufficient-balance
            // declines are routine, not a bug — but now at least visible
            // and filterable by user_id if a pattern of declines needs
            // investigating (e.g. a fraud signal, or a UI bug offering
            // plays a user can't actually afford).
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Wallet debit declined — insufficient balance or no wallet row exists for this user.', [
                    'user_id' => $user_id, 'cents' => $cents, 'track_id' => $track_id,
                ], 'BH Wallet');
            }
            return false;
        }

        $ledger_ok = $wpdb->insert(BHM_Tables::wallet_ledger(), [
            'user_id' => $user_id, 'delta_cents' => -$cents, 'reason' => $reason, 'track_id' => $track_id,
        ]);
        // Feeds the CRM's unified per-person activity timeline
        // (BHCRM's render_timeline(), the-self-hosted-self's BH_Event) — same
        // "harmless no-op if the core event system isn't loaded"
        // posture every other emit() call site in this ecosystem uses.
        if ($ledger_ok !== false && class_exists('BH_Event')) {
            BH_Event::emit('bhm/wallet_debit', [
                'user_id' => $user_id, 'subject_type' => 'bhm_wallet', 'subject_id' => $user_id,
                'payload' => ['cents' => $cents, 'reason' => $reason, 'track_id' => $track_id],
            ]);
        }
        if ($ledger_ok === false && class_exists('OUS_DebugLog')) {
            // The balance mutation above already committed — this is a
            // real desync risk (balance moved, ledger didn't record why)
            // that was previously completely invisible. 'error', not
            // 'warning': the wallet's balance and its own audit trail
            // just went out of sync, on a money-handling path.
            OUS_DebugLog::log('error', 'Wallet debit succeeded but the ledger row failed to insert — balance and ledger are now out of sync for this user.', [
                'user_id' => $user_id, 'cents' => $cents, 'track_id' => $track_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
        }
        return true;
    }

    // Public entry point for a reason neither credit() nor debit() quite
    // fits: reversing a refunded/cancelled top-up (see class-products.php's
    // on_order_reversed()) is neither "the listener bought more credit"
    // nor "the listener spent credit on a play" — it's undoing a grant
    // after the fact, with its own explicit reason string in the ledger
    // rather than being disguised as either of those two.
    public static function apply_ledger_delta(int $user_id, int $delta_cents, string $reason, ?int $track_id = null, ?int $order_id = null): void {
        self::apply_delta($user_id, (int) $delta_cents, $reason, $track_id, $order_id);
    }

    private static function apply_delta(int $user_id, int $delta_cents, string $reason, ?int $track_id, ?int $order_id): void {
        global $wpdb;
        $w = BHM_Tables::wallet();
        $l = BHM_Tables::wallet_ledger();

        // INSERT ... ON DUPLICATE KEY UPDATE — a single atomic statement
        // rather than a read-then-write, so two plays debiting the same
        // wallet in quick succession can't race each other into an
        // incorrect balance.
        $balance_ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $w (user_id, balance_cents, updated_at) VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE balance_cents = balance_cents + %d, updated_at = %s",
            $user_id, $delta_cents, current_time('mysql'), $delta_cents, current_time('mysql')
        ));
        if ($balance_ok === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Wallet credit/reversal balance write failed — no ledger entry attempted since there is nothing to record against.', [
                'user_id' => $user_id, 'delta_cents' => $delta_cents, 'reason' => $reason, 'order_id' => $order_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
            return;
        }
        $ledger_ok = $wpdb->insert($l, [
            'user_id' => $user_id, 'delta_cents' => $delta_cents, 'reason' => $reason,
            'track_id' => $track_id, 'wc_order_id' => $order_id,
        ]);
        if ($ledger_ok !== false && class_exists('BH_Event')) {
            BH_Event::emit('bhm/wallet_credit', [
                'user_id' => $user_id, 'subject_type' => 'bhm_wallet', 'subject_id' => $user_id,
                'payload' => ['cents' => $delta_cents, 'reason' => $reason, 'order_id' => $order_id],
            ]);
        }
        if ($ledger_ok === false && class_exists('OUS_DebugLog')) {
            // Same balance/ledger desync risk as debit() above — this is
            // the credit/reversal-side counterpart (top-ups and refund
            // reversals both flow through here), previously equally
            // silent.
            OUS_DebugLog::log('error', 'Wallet balance updated but the ledger row failed to insert — balance and ledger are now out of sync for this user.', [
                'user_id' => $user_id, 'delta_cents' => $delta_cents, 'reason' => $reason, 'order_id' => $order_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
        }
    }

    /** @return array<int, object> */
    public static function ledger_for(int $user_id, int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BHM_Tables::wallet_ledger() . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }
}
