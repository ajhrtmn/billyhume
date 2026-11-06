<?php
if (!defined('ABSPATH')) exit;

/**
 * Prepaid credit wallet for pay-per-play: topped up via WooCommerce products, debited per play.
 * Has its own balance + ledger, separate from entitlements.
 */
class BHM_Wallet {
    public static function init(): void {
        // Stateless API; no hooks needed.
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

    /** Spendable now: balance minus held. */
    public static function available_cents(int $user_id): int {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT balance_cents, held_cents FROM " . BHM_Tables::wallet() . " WHERE user_id = %d", $user_id), ARRAY_A);
        return $row ? ((int) $row['balance_cents'] - (int) $row['held_cents']) : 0;
    }

    // Reserves $cents without touching balance_cents. WHY: check and write are one atomic
    // UPDATE ... WHERE, so concurrent holds can't both pass a prior read.
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
        // delta_cents 0: a hold doesn't change the balance; the row is an audit entry.
        return true;
    }

    /** Releases a hold without touching balance_cents; floors at 0 so a duplicate release can't go negative. */
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

    // Spends held money: decrements held_cents and balance_cents atomically; can only spend what was held.
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
        // Velocity cap counts only purchased top-ups (adjustments use apply_ledger_delta()).
        if ($reason === 'topup' && class_exists('BHM_Fraud')) {
            BHM_Fraud::track_topup_velocity($user_id, abs((int) $cents));
        }
    }

    // False when declined for insufficient funds. WHY: check and write are one atomic
    // UPDATE ... WHERE (success = rows_affected), so concurrent debits can't go negative.
    public static function debit(int $user_id, int $cents, ?int $track_id = null, string $reason = 'play'): bool {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = BHM_Tables::wallet();

        $wpdb->query($wpdb->prepare(
            "UPDATE $w SET balance_cents = balance_cents - %d, updated_at = %s WHERE user_id = %d AND balance_cents >= %d",
            $cents, current_time('mysql'), $user_id, $cents
        ));
        if ($wpdb->rows_affected !== 1) {
            // 'info': declines are routine, but a missing wallet row looks the same, so keep it visible.
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
        // Feeds the CRM activity timeline.
        if ($ledger_ok !== false && class_exists('BH_Event')) {
            BH_Event::emit('bhm/wallet_debit', [
                'user_id' => $user_id, 'subject_type' => 'bhm_wallet', 'subject_id' => $user_id,
                'payload' => ['cents' => $cents, 'reason' => $reason, 'track_id' => $track_id],
            ]);
        }
        if ($ledger_ok === false && class_exists('OUS_DebugLog')) {
            // The balance already moved; ledger and balance are now out of sync.
            OUS_DebugLog::log('error', 'Wallet debit succeeded but the ledger row failed to insert — balance and ledger are now out of sync for this user.', [
                'user_id' => $user_id, 'cents' => $cents, 'track_id' => $track_id, 'db_error' => $wpdb->last_error,
            ], 'BH Wallet');
        }
        return true;
    }

    // Signed adjustment with its own reason (e.g. reversing a refunded top-up); skips the velocity check.
    public static function apply_ledger_delta(int $user_id, int $delta_cents, string $reason, ?int $track_id = null, ?int $order_id = null): void {
        self::apply_delta($user_id, (int) $delta_cents, $reason, $track_id, $order_id);
    }

    private static function apply_delta(int $user_id, int $delta_cents, string $reason, ?int $track_id, ?int $order_id): void {
        global $wpdb;
        $w = BHM_Tables::wallet();
        $l = BHM_Tables::wallet_ledger();

        // Atomic upsert, not read-then-write.
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
