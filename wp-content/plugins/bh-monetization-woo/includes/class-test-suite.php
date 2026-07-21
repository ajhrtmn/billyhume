<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see own-ur-shit's class-test-runner.php) version of
 * the tier-depth logic — same convention bh-courses'
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

        /* ---------- benefit_registry() exact contents ---------- */

        $registry = BHM_Tiers::benefit_registry();
        $rows[] = OUS_TestRunner::assert_same(
            ['streaming' => 'Streaming library access', 'downloads' => 'Downloadable audio', 'courses' => 'Course/LMS access', 'merch_discount' => 'Storefront discount'],
            $registry,
            'benefit_registry() returns exactly the default benefit key => label pairs (not just "non-empty" — a silently dropped or renamed key would break bh-courses\' own gating, which depends on the exact "courses" key)'
        );

        /* ---------- user_has_tier_access()/user_has_benefit() degenerate (unlocked) inputs ---------- */

        $rows[] = OUS_TestRunner::assert_true(
            BHM_Gate::user_has_tier_access(0, 0),
            'A required_tier of 0 (unlocked content) always passes, even with no user'
        );
        $rows[] = OUS_TestRunner::assert_true(
            BHM_Gate::user_has_benefit(0, ''),
            'An empty required_benefit (unlocked content) always passes, even with no user'
        );

        /* ---------- user_has_tier_access()/user_has_benefit() real gating, seeded ----------
         * The two assertions above only prove the degenerate "nothing
         * required" short-circuit works — they'd still pass even if the
         * real entitlement lookup below were completely broken. This
         * seeds a real fixture tier (with a real benefit key and a real
         * price), a real test user, and a real bhm_entitlements row, then
         * exercises actual grant/no-grant/expired/wrong-tier cases against
         * the real DB-backed logic in BHM_Gate.
         */
        if (class_exists('OUS_Debug') && method_exists('OUS_Debug', 'get_or_create_test_user')) {
            global $wpdb;
            $entitlements_table = $wpdb->prefix . 'bhm_entitlements';

            $tier_id = wp_insert_post([
                'post_type' => 'bhm_tier', 'post_status' => 'publish',
                'post_title' => 'BHM Test Suite Fixture Tier',
            ], true);

            if (!is_wp_error($tier_id)) {
                update_post_meta($tier_id, '_bhm_price_cents', 500);
                update_post_meta($tier_id, '_bhm_benefit_keys', ['courses']);

                $test_user_id = (int) OUS_Debug::get_or_create_test_user('bhm-test-suite', false);

                // No entitlement row yet — access must correctly be denied.
                $rows[] = OUS_TestRunner::assert_false(
                    BHM_Gate::user_has_tier_access($test_user_id, $tier_id),
                    'A real user with zero entitlement rows is correctly denied access to a real paid tier'
                );
                $rows[] = OUS_TestRunner::assert_false(
                    BHM_Gate::user_has_benefit($test_user_id, 'courses'),
                    'A real user with zero entitlement rows is correctly denied a real benefit'
                );

                $wpdb->insert($entitlements_table, [
                    'user_id' => $test_user_id, 'type' => 'subscription', 'scope' => 'account',
                    'object_id' => $tier_id, 'expires_at' => null, 'created_at' => current_time('mysql'),
                ]);

                $rows[] = OUS_TestRunner::assert_true(
                    BHM_Gate::user_has_tier_access($test_user_id, $tier_id),
                    'A real active (non-expiring) entitlement row correctly grants access to its own tier'
                );
                $rows[] = OUS_TestRunner::assert_true(
                    BHM_Gate::user_has_benefit($test_user_id, 'courses'),
                    'A real active entitlement to a tier granting "courses" correctly grants that benefit'
                );
                $rows[] = OUS_TestRunner::assert_false(
                    BHM_Gate::user_has_benefit($test_user_id, 'merch_discount'),
                    'The same entitlement does NOT grant a benefit the fixture tier was never given'
                );

                $wpdb->update($entitlements_table, ['expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)], ['user_id' => $test_user_id, 'object_id' => $tier_id]);
                $rows[] = OUS_TestRunner::assert_false(
                    BHM_Gate::user_has_tier_access($test_user_id, $tier_id),
                    'An entitlement row that already expired correctly no longer grants access'
                );

                $wpdb->delete($entitlements_table, ['user_id' => $test_user_id, 'object_id' => $tier_id]);
                wp_delete_post($tier_id, true);
            }
        }

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

        /* ---------- tier-exclusivity (BHM_Products::grant_entitlement()) ----------
         * No coverage existed for this before — a refund/monetization
         * audit found nothing enforced "one active tier at a time,"
         * letting a user stack multiple simultaneous active
         * subscription/streaming_tier entitlements. grant_entitlement()
         * is private (called only from real order/subscription webhook
         * handlers), so this reaches it via reflection rather than
         * duplicating its logic in a mock — same "exercise the real
         * thing" posture run_wallet_tests() above already takes. Runs
         * against two real, tagged fixture bhm_tier posts + a real
         * fake user's entitlement rows, cleaned up afterward. */
        if (class_exists('BHM_Products') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_tier_exclusivity_tests());
        }

        return $rows;
    }

    private static function run_tier_exclusivity_tests() {
        $rows = [];
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        $uid = OUS_Debug::get_or_create_test_user('bhm_tier_exclusivity_suite', false);
        $wpdb->delete($t, ['user_id' => $uid]);

        $cheap_tier = wp_insert_post([
            'post_type' => 'bhm_tier', 'post_status' => 'publish', 'post_title' => 'Exclusivity Test Fan',
            'meta_input' => ['bhcore_is_test' => 'bhm_tier_exclusivity_suite', '_bhm_price_cents' => 300],
        ], true);
        $pricey_tier = wp_insert_post([
            'post_type' => 'bhm_tier', 'post_status' => 'publish', 'post_title' => 'Exclusivity Test Supporter',
            'meta_input' => ['bhcore_is_test' => 'bhm_tier_exclusivity_suite', '_bhm_price_cents' => 1200],
        ], true);
        if (is_wp_error($cheap_tier) || is_wp_error($pricey_tier)) {
            return [['name' => 'Tier-exclusivity fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bhm_tier posts — skipping.']];
        }

        $grant = new ReflectionMethod('BHM_Entitlements', 'grant_entitlement');
        $grant->setAccessible(true);

        // Grant the cheap tier first — a plain, uncontested grant.
        $grant->invoke(null, $uid, 'subscription', 'account', $cheap_tier, 1000001, null, gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $count_after_first = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_first, 'Granting a first tier leaves exactly one active entitlement row');

        // Grant a DIFFERENT (pricier) tier — must replace, not stack.
        $grant->invoke(null, $uid, 'subscription', 'account', $pricey_tier, 1000002, null, gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $rows_after_switch = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, count($rows_after_switch), 'Granting a DIFFERENT tier replaces the old one — never two simultaneous active tiers');
        $rows[] = OUS_TestRunner::assert_same((string) $pricey_tier, (string) ($rows_after_switch[0]->object_id ?? null), 'After switching tiers, the ONE remaining row is the newly-granted tier, not the old one');

        // Re-granting the SAME tier (e.g. an early renewal) should still
        // leave exactly one row — not stack a second row for itself.
        $grant->invoke(null, $uid, 'subscription', 'account', $pricey_tier, 1000003, null, gmdate('Y-m-d H:i:s', strtotime('+60 days')));
        $count_after_renewal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_renewal, 'Re-granting the SAME tier (early renewal) still leaves exactly one row, not a second stacked one');

        // Cleanup.
        $wpdb->delete($t, ['user_id' => $uid]);
        wp_delete_post($cheap_tier, true);
        wp_delete_post($pricey_tier, true);

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
