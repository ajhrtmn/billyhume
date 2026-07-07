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
    public static function init() {
        // Pure API class, no hooks of its own to register — see
        // BHM_Gate::init() for the same reasoning.
    }

    public static function balance_cents($user_id) {
        global $wpdb;
        $bal = $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM {$wpdb->prefix}bhm_wallet WHERE user_id = %d", $user_id));
        return $bal === null ? 0 : (int) $bal;
    }

    public static function credit($user_id, $cents, $reason, $track_id = null, $order_id = null) {
        self::apply_delta($user_id, abs((int) $cents), $reason, $track_id, $order_id);
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
    public static function debit($user_id, $cents, $track_id = null) {
        global $wpdb;
        $cents = abs((int) $cents);
        $w = $wpdb->prefix . 'bhm_wallet';

        $wpdb->query($wpdb->prepare(
            "UPDATE $w SET balance_cents = balance_cents - %d, updated_at = %s WHERE user_id = %d AND balance_cents >= %d",
            $cents, current_time('mysql'), $user_id, $cents
        ));
        if ($wpdb->rows_affected !== 1) return false; // no wallet row yet, or insufficient balance — either way, WHERE matched nothing

        $wpdb->insert($wpdb->prefix . 'bhm_wallet_ledger', [
            'user_id' => $user_id, 'delta_cents' => -$cents, 'reason' => 'play', 'track_id' => $track_id,
        ]);
        return true;
    }

    // Public entry point for a reason neither credit() nor debit() quite
    // fits: reversing a refunded/cancelled top-up (see class-products.php's
    // on_order_reversed()) is neither "the listener bought more credit"
    // nor "the listener spent credit on a play" — it's undoing a grant
    // after the fact, with its own explicit reason string in the ledger
    // rather than being disguised as either of those two.
    public static function apply_ledger_delta($user_id, $delta_cents, $reason, $track_id = null, $order_id = null) {
        self::apply_delta($user_id, (int) $delta_cents, $reason, $track_id, $order_id);
    }

    private static function apply_delta($user_id, $delta_cents, $reason, $track_id, $order_id) {
        global $wpdb;
        $w = $wpdb->prefix . 'bhm_wallet';
        $l = $wpdb->prefix . 'bhm_wallet_ledger';

        // INSERT ... ON DUPLICATE KEY UPDATE — a single atomic statement
        // rather than a read-then-write, so two plays debiting the same
        // wallet in quick succession can't race each other into an
        // incorrect balance.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $w (user_id, balance_cents, updated_at) VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE balance_cents = balance_cents + %d, updated_at = %s",
            $user_id, $delta_cents, current_time('mysql'), $delta_cents, current_time('mysql')
        ));
        $wpdb->insert($l, [
            'user_id' => $user_id, 'delta_cents' => $delta_cents, 'reason' => $reason,
            'track_id' => $track_id, 'wc_order_id' => $order_id,
        ]);
    }

    public static function ledger_for($user_id, $limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhm_wallet_ledger WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }
}
