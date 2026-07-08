<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see own-ur-shit's class-test-runner.php) version of
 * this pass's new tier-depth logic — same convention bh-courses'
 * class-test-suite.php already established. Covers the PURE, no-database
 * parts (BHM_Gate::calculate_downgrade_credit_cents() and
 * BHM_Tiers::benefit_registry()'s shape) directly; the DB-backed parts
 * (user_has_benefit(), ids_granting_benefit()) are exercised end-to-end
 * instead via BHM_Debug's existing seed/reset actions on Debug Tools,
 * which already create and tear down real tier posts/entitlement rows —
 * duplicating that as a second, parallel fixture-based test here would
 * just be two ways of doing the same thing, not real extra coverage.
 */
class BHM_TestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-monetization-woo'] = ['label' => 'BH Monetization', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner')) return [];
        if (!class_exists('BHM_Gate') || !class_exists('BHM_Tiers')) {
            return [['name' => 'BHM_Gate/BHM_Tiers not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];

        /* ---------- calculate_downgrade_credit_cents() ---------- */

        $rows[] = OUS_TestRunner::assert_same(
            1000, BHM_Gate::calculate_downgrade_credit_cents(3000, 10),
            '$30.00 tier, 10 days remaining of a 30-day period credits exactly $10.00'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::calculate_downgrade_credit_cents(3000, 0),
            'Zero days remaining credits nothing'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::calculate_downgrade_credit_cents(0, 15),
            'A free (zero-price) tier credits nothing regardless of days remaining'
        );
        $rows[] = OUS_TestRunner::assert_same(
            3000, BHM_Gate::calculate_downgrade_credit_cents(3000, 30),
            'Full 30 days remaining credits the full tier price back'
        );
        $rows[] = OUS_TestRunner::assert_true(
            BHM_Gate::calculate_downgrade_credit_cents(3000, 45) > BHM_Gate::calculate_downgrade_credit_cents(3000, 30),
            'More days remaining always credits more (monotonic, not capped oddly)'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::calculate_downgrade_credit_cents(3000, -5),
            'Negative days remaining (already expired) credits nothing, never a negative wallet debit'
        );

        /* ---------- benefit_registry() shape ---------- */

        $registry = BHM_Tiers::benefit_registry();
        $rows[] = OUS_TestRunner::assert_true(is_array($registry) && count($registry) > 0, 'benefit_registry() returns at least the default benefit keys');
        $rows[] = OUS_TestRunner::assert_true(array_key_exists('courses', $registry), '"courses" is a registered default benefit key (bh-courses\' own gating depends on this exact key existing)');

        /* ---------- user_has_tier_access()/user_has_benefit() fail safely with no user ---------- */

        $rows[] = OUS_TestRunner::assert_true(
            BHM_Gate::user_has_tier_access(0, 0),
            'A required_tier of 0 (unlocked content) always passes, even with no user'
        );
        $rows[] = OUS_TestRunner::assert_true(
            BHM_Gate::user_has_benefit(0, ''),
            'An empty required_benefit (unlocked content) always passes, even with no user'
        );

        /* ---------- BHM_Wallet debit/credit + ledger consistency ----------
         * No coverage existed for this before — added after this session's
         * logging pass found debit()/apply_delta() had zero error
         * handling (see class-wallet.php's own comments). This exercises
         * the REAL debit/credit paths against a real (tagged, cleaned-up)
         * fake user's wallet, since the atomic-UPDATE-based logic
         * (deliberately not a check-then-write, see debit()'s own
         * docblock) is exactly the kind of thing worth a real DB
         * round-trip rather than a mock. */
        if (class_exists('BHM_Wallet') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_wallet_tests());
        } elseif (class_exists('BHM_Wallet')) {
            $rows[] = ['name' => 'BHM_Wallet tests skipped', 'pass' => false, 'message' => 'OUS_Debug (for get_or_create_test_user) not loaded.'];
        }

        return $rows;
    }

    private static function run_wallet_tests() {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhm_wallet_suite', false); // false = always a fresh user, never reuse a pool member mid-assertions
        $wallet_table = $wpdb->prefix . 'bhm_wallet';
        $ledger_table = $wpdb->prefix . 'bhm_wallet_ledger';

        // Start from a clean, known state regardless of whatever this
        // fake user's wallet happened to hold from a previous run.
        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        // Credit via apply_ledger_delta() (the public entry point) —
        // real balance write + real ledger row.
        BHM_Wallet::apply_ledger_delta($uid, 500, 'test_topup');
        $balance = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(500, $balance, 'apply_ledger_delta(+500) credits the wallet balance to exactly 500');
        $ledger_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d AND reason = %s", $uid, 'test_topup'));
        $rows[] = OUS_TestRunner::assert_same(1, $ledger_count, 'apply_ledger_delta() writes exactly one matching ledger row');

        // A debit within balance should succeed and leave balance/ledger
        // consistent with each other, not just each individually
        // "looking right."
        $debit_ok = BHM_Wallet::debit($uid, 200, null);
        $rows[] = OUS_TestRunner::assert_true($debit_ok, 'debit(200) against a 500-cent balance succeeds');
        $balance_after_debit = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(300, $balance_after_debit, 'balance after debit(200) from 500 is exactly 300, not off-by-one-row-affected or double-counted');
        $ledger_debit_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d AND reason = %s AND delta_cents = %d", $uid, 'play', -200));
        $rows[] = OUS_TestRunner::assert_same(1, $ledger_debit_count, 'a successful debit() writes a matching -200 ledger row (reason "play")');

        // A debit exceeding the remaining balance must be DECLINED, not
        // allowed to drive the balance negative — this is the whole
        // point of debit()'s atomic UPDATE...WHERE balance_cents >= %d
        // guard (see that method's own docblock re: TOCTOU races).
        $decline_ok = BHM_Wallet::debit($uid, 9999, null);
        $rows[] = OUS_TestRunner::assert_false($decline_ok, 'debit(9999) against a 300-cent balance is correctly declined');
        $balance_after_decline = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(300, $balance_after_decline, 'a declined debit leaves the balance completely unchanged (never negative)');
        $ledger_after_decline = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(2, $ledger_after_decline, 'a declined debit writes NO new ledger row (still just the +500 and -200 from before)');

        // Cleanup — this fake user's own wallet/ledger rows. The user
        // account itself is left for OUS_Debug's own "Reset Everything"
        // sweep (tagged bhcore_is_test => bhm_wallet_suite), same
        // convention every other suite/seed action in this ecosystem
        // uses, not re-implemented here.
        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        return $rows;
    }
}
