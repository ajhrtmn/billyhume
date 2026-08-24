<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see the-self-hosted-self's class-test-runner.php) version of
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
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['bh-monetization-woo'] = ['label' => 'BH Monetization', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
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

        /* ---------- annual_savings_percent() (audit fix, 2026-07-25) ---------- */

        $rows[] = OUS_TestRunner::assert_same(
            17, BHM_Gate::annual_savings_percent(1000, 10000),
            '$10/mo tier priced at $100/yr (vs. $120/yr at monthly rate) is a real 17% savings'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::annual_savings_percent(1000, 12000),
            'Annual priced exactly at monthly-times-12 is 0% savings, not a rounding artifact'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::annual_savings_percent(1000, 15000),
            'Annual priced ABOVE monthly-times-12 never shows a misleading negative percent'
        );
        $rows[] = OUS_TestRunner::assert_same(
            0, BHM_Gate::annual_savings_percent(0, 0),
            'A free tier never divides by zero'
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
            $entitlements_table = BHM_Tables::entitlements();

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

        // Referral/affiliate tracking (ecosystem depth-pass Tier 2) —
        // exercises the real order-completion path end to end (a real
        // WC_Coupon, a real WC_Order, real wallet crediting), rather than
        // a mock of any of it, same "exercise the real thing" posture as
        // the wallet/tier-exclusivity suites above. Needs WooCommerce
        // actually active (creates a real product/order/coupon).
        if (class_exists('BHM_Referrals') && class_exists('OUS_Debug') && BH_Commerce::available()) {
            $rows = array_merge($rows, self::run_referral_tests());
        }

        // Same availability gate as run_referral_tests() above — a real
        // WC product/order is needed to exercise write_row() through
        // its actual entry point (on_order_completed()), not a mock of it.
        if (class_exists('BHM_PurchaseLedger') && class_exists('OUS_Debug') && BH_Commerce::available()) {
            $rows = array_merge($rows, self::run_purchase_ledger_tests());
        }

        // Auction listings (Section 5a) — hold/release/capture on a real
        // (tagged, cleaned-up) fake wallet, same posture as
        // run_wallet_tests() above, plus the bidding engine itself
        // (BHM_Auctions) against a real post standing in for a product —
        // this class never actually calls wc_get_product() or checks
        // post_type, so a plain post exercises the exact same code path
        // a real WooCommerce product would.
        if (class_exists('BHM_Wallet') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_wallet_hold_tests());
        }
        if (class_exists('BHM_Auctions') && class_exists('BHM_Wallet') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_auction_tests());
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_tier_exclusivity_tests(): array {
        $rows = [];
        global $wpdb;
        $t = BHM_Tables::entitlements();
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
        $grant->invoke(null, $uid, 'subscription', 'account', $cheap_tier, 1000001, null, gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')));
        $count_after_first = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_first, 'Granting a first tier leaves exactly one active entitlement row');

        // Grant a DIFFERENT (pricier) tier — must replace, not stack.
        $grant->invoke(null, $uid, 'subscription', 'account', $pricey_tier, 1000002, null, gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')));
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

    /** @return array<int, array<string, mixed>> */
    private static function run_wallet_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhm_wallet_suite', false); // false = always a fresh user, never reuse a pool member mid-assertions
        $wallet_table = BHM_Tables::wallet();
        $ledger_table = BHM_Tables::wallet_ledger();

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

    /**
     * BHM_Wallet::hold()/release_hold()/capture_hold() (1.7, auction
     * listings) — the three new methods a bid actually calls. Same
     * fresh-tagged-user + real-DB-round-trip posture as run_wallet_tests()
     * above, since these are exactly the kind of atomic-UPDATE-with-a-
     * WHERE-clause logic worth exercising for real rather than mocking.
     */
    /** @return array<int, array<string, mixed>> */
    private static function run_wallet_hold_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhm_wallet_hold_suite', false);
        $wallet_table = BHM_Tables::wallet();
        $ledger_table = BHM_Tables::wallet_ledger();
        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        BHM_Wallet::apply_ledger_delta($uid, 1000, 'test_topup');

        $rows[] = OUS_TestRunner::assert_true(BHM_Wallet::hold($uid, 400, 'test_hold'), 'hold(400) against a 1000-cent balance succeeds');
        $rows[] = OUS_TestRunner::assert_same(1000, BHM_Wallet::balance_cents($uid), 'a hold never touches balance_cents — still exactly 1000');
        $rows[] = OUS_TestRunner::assert_same(400, BHM_Wallet::held_cents($uid), 'held_cents is exactly 400 after hold(400)');
        $rows[] = OUS_TestRunner::assert_same(600, BHM_Wallet::available_cents($uid), 'available_cents (balance - held) is exactly 600');

        $rows[] = OUS_TestRunner::assert_false(BHM_Wallet::hold($uid, 700, 'test_hold'), 'a second hold(700) — more than the 600 still available — is declined, not allowed to overcommit');
        $rows[] = OUS_TestRunner::assert_same(400, BHM_Wallet::held_cents($uid), 'a declined hold leaves held_cents completely unchanged');

        $rows[] = OUS_TestRunner::assert_true(BHM_Wallet::release_hold($uid, 400, 'test_release'), 'release_hold(400) succeeds');
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Wallet::held_cents($uid), 'held_cents is back to 0 after releasing the full hold');
        $rows[] = OUS_TestRunner::assert_same(1000, BHM_Wallet::balance_cents($uid), 'balance_cents is untouched by the release — still 1000, exactly what a losing/outbid bidder should keep');

        BHM_Wallet::hold($uid, 300, 'test_hold');
        $rows[] = OUS_TestRunner::assert_true(BHM_Wallet::capture_hold($uid, 300, 'test_capture'), 'capture_hold(300) against a genuine 300-cent hold succeeds');
        $rows[] = OUS_TestRunner::assert_same(700, BHM_Wallet::balance_cents($uid), 'capture_hold() actually spends the money — balance drops from 1000 to 700');
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Wallet::held_cents($uid), 'capture_hold() clears the held amount it just captured');

        $rows[] = OUS_TestRunner::assert_false(BHM_Wallet::capture_hold($uid, 100, 'test_capture'), 'capture_hold() against a NON-existent hold (held_cents is 0) is refused, never silently spending un-held money');
        $rows[] = OUS_TestRunner::assert_same(700, BHM_Wallet::balance_cents($uid), 'a refused capture leaves the balance completely unchanged');

        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        return $rows;
    }

    /**
     * BHM_Auctions end to end — bidding, outbid release, reserve
     * handling, and close_auction()'s own idempotency — against two
     * real (tagged, cleaned-up) fake users and a plain post standing in
     * for a product (see the call site's own comment on why that's a
     * faithful stand-in here).
     */
    /** @return array<int, array<string, mixed>> */
    private static function run_auction_tests(): array {
        $rows = [];
        global $wpdb;
        $bidder_a = OUS_Debug::get_or_create_test_user('bhm_auction_bidder_a', false);
        $bidder_b = OUS_Debug::get_or_create_test_user('bhm_auction_bidder_b', false);
        $wallet_table = BHM_Tables::wallet();
        $ledger_table = BHM_Tables::wallet_ledger();
        $bids_table = BHM_Tables::auction_bids();
        foreach ([$bidder_a, $bidder_b] as $uid) {
            $wpdb->delete($wallet_table, ['user_id' => $uid]);
            $wpdb->delete($ledger_table, ['user_id' => $uid]);
        }
        BHM_Wallet::apply_ledger_delta($bidder_a, 10000, 'test_topup');
        BHM_Wallet::apply_ledger_delta($bidder_b, 10000, 'test_topup');

        $product_id = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Auction Suite Fixture'], true);
        $wpdb->delete($bids_table, ['product_id' => $product_id]);

        $rows[] = OUS_TestRunner::assert_false(BHM_Auctions::is_auction($product_id), 'a plain product is not an auction until convert_to_auction() is called');

        $ends_at = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' +1 hour'));
        $rows[] = OUS_TestRunner::assert_true(
            BHM_Auctions::convert_to_auction($product_id, 500, 800, $ends_at),
            'convert_to_auction() succeeds with a real future end time'
        );
        $rows[] = OUS_TestRunner::assert_true(BHM_Auctions::is_auction($product_id), 'is_auction() is true immediately after conversion');
        $rows[] = OUS_TestRunner::assert_same('open', BHM_Auctions::status($product_id), 'status() is "open" while the end time is still in the future');
        $rows[] = OUS_TestRunner::assert_same(500, BHM_Auctions::current_bid_cents($product_id), 'current_bid_cents() is the starting price before any real bid exists');

        $rows[] = OUS_TestRunner::assert_true(is_wp_error(BHM_Auctions::place_bid($product_id, $bidder_a, 400)), 'a bid below the starting price is rejected');
        $rows[] = OUS_TestRunner::assert_false(is_wp_error(BHM_Auctions::place_bid($product_id, $bidder_a, 500)), "bidder A's opening bid at exactly the starting price succeeds");
        $rows[] = OUS_TestRunner::assert_same(500, BHM_Wallet::held_cents($bidder_a), "bidder A's wallet now holds exactly 500 for their open bid");

        $rows[] = OUS_TestRunner::assert_true(is_wp_error(BHM_Auctions::place_bid($product_id, $bidder_b, 500)), "bidder B matching (not beating) the current high bid is rejected");
        $rows[] = OUS_TestRunner::assert_false(is_wp_error(BHM_Auctions::place_bid($product_id, $bidder_b, 700)), "bidder B's higher bid of 700 succeeds");
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Wallet::held_cents($bidder_a), "bidder A's hold is fully released the instant they're outbid");
        $rows[] = OUS_TestRunner::assert_same(700, BHM_Wallet::held_cents($bidder_b), "bidder B's wallet now holds exactly their own 700 bid");
        $rows[] = OUS_TestRunner::assert_same(700, BHM_Auctions::current_bid_cents($product_id), 'current_bid_cents() reflects the new high bid of 700');
        $rows[] = OUS_TestRunner::assert_same((int) $bidder_b, BHM_Auctions::current_bidder_id($product_id), 'current_bidder_id() is bidder B after their bid takes the lead');

        $outbid_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $bids_table WHERE product_id = %d AND user_id = %d AND amount_cents = 500", $product_id, $bidder_a));
        $rows[] = OUS_TestRunner::assert_same('outbid', $outbid_status, "bidder A's own 500-cent bid row is marked 'outbid', not deleted — a real history, not just current-state");

        // Reserve (800) is NOT met by the current 700 high bid —
        // close_auction() should release B's hold rather than capture
        // it, and the listing should NOT report as sold.
        BHM_Auctions::close_auction(['product_id' => $product_id]);
        $rows[] = OUS_TestRunner::assert_same('closed', BHM_Auctions::status($product_id), 'status() is "closed" once close_auction() has actually finalized it');
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Wallet::held_cents($bidder_b), "bidder B's hold is released (not captured) because the 700 bid never met the 800 reserve");
        $rows[] = OUS_TestRunner::assert_same(10000, BHM_Wallet::balance_cents($bidder_b), "bidder B's balance is completely untouched — the reserve wasn't met, so nothing was ever actually spent");

        // Idempotency: calling close_auction() again must be a pure
        // no-op, never a second release/capture against money that was
        // already settled once.
        BHM_Wallet::apply_ledger_delta($bidder_b, 0, 'test_noop'); // no-op ledger touch, just to have a fresh read point
        $held_before_replay = BHM_Wallet::held_cents($bidder_b);
        BHM_Auctions::close_auction(['product_id' => $product_id]);
        $rows[] = OUS_TestRunner::assert_same($held_before_replay, BHM_Wallet::held_cents($bidder_b), 'calling close_auction() a second time on an already-finalized auction is a no-op — held_cents does not move again');

        // A SEPARATE listing with no reserve should actually sell —
        // capture_hold() moves the winner's money for real.
        $product_id_2 = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Auction Suite Fixture (no reserve)'], true);
        BHM_Auctions::convert_to_auction($product_id_2, 500, 0, $ends_at);
        BHM_Auctions::place_bid($product_id_2, $bidder_a, 600);
        BHM_Auctions::close_auction(['product_id' => $product_id_2]);
        $rows[] = OUS_TestRunner::assert_same('closed', BHM_Auctions::status($product_id_2), 'the no-reserve listing is also closed after close_auction()');
        $rows[] = OUS_TestRunner::assert_same(9400, BHM_Wallet::balance_cents($bidder_a), "the winning bidder's balance actually drops by the winning 600-cent bid once a sale with no reserve genuinely closes");
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Wallet::held_cents($bidder_a), "the winner's hold is cleared once captured — it's spent, not still 'held'");

        foreach ([$bidder_a, $bidder_b] as $uid) {
            $wpdb->delete($wallet_table, ['user_id' => $uid]);
            $wpdb->delete($ledger_table, ['user_id' => $uid]);
        }
        $wpdb->delete($bids_table, ['product_id' => $product_id]);
        $wpdb->delete($bids_table, ['product_id' => $product_id_2]);
        wp_delete_post($product_id, true);
        wp_delete_post($product_id_2, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_referral_tests(): array {
        $rows = [];
        global $wpdb;

        $referrer_id = OUS_Debug::get_or_create_test_user('bhm_referral_suite_referrer', false);
        $customer_id = OUS_Debug::get_or_create_test_user('bhm_referral_suite_customer', false);
        $wpdb->delete(BHM_Tables::referral_codes(), ['user_id' => $referrer_id]);
        $wpdb->delete(BHM_Tables::wallet(), ['user_id' => $referrer_id]);
        $wpdb->delete(BHM_Tables::wallet_ledger(), ['user_id' => $referrer_id]);

        /* ---------- calculate_commission_cents() — pure ---------- */
        $rows[] = OUS_TestRunner::assert_same(1000, BHM_Referrals::calculate_commission_cents(100), 'A $100.00 order at the default 10% commission credits exactly $10.00 (1000 cents)');
        $rows[] = OUS_TestRunner::assert_same(0, BHM_Referrals::calculate_commission_cents(0), 'A $0 order credits nothing');

        /* ---------- get_or_create_code() ---------- */
        $code = BHM_Referrals::get_or_create_code($referrer_id);
        $rows[] = OUS_TestRunner::assert_true((bool) preg_match('/^[A-Z0-9]{6}$/', $code), 'get_or_create_code() returns a 6-char uppercase alphanumeric code');
        $again = BHM_Referrals::get_or_create_code($referrer_id);
        $rows[] = OUS_TestRunner::assert_same($code, $again, 'Calling get_or_create_code() again for the same user returns the SAME code, not a new one');
        $coupon_id = wc_get_coupon_id_by_code($code);
        $rows[] = OUS_TestRunner::assert_true($coupon_id > 0, 'A real WC_Coupon exists for the generated code');

        /* ---------- end-to-end: a real order using the code ---------- */
        $product = new WC_Product_Simple();
        $product->set_name('Referral Suite Test Product');
        $product->set_regular_price('100');
        $product->save();

        $order = wc_create_order(['customer_id' => $customer_id]);
        $order->add_product($product, 1);
        $order->apply_coupon($code);
        $order->calculate_totals();
        $order->update_status('completed'); // fires the real woocommerce_order_status_completed path

        $order_total = (float) $order->get_total();
        $expected_cents = BHM_Referrals::calculate_commission_cents($order_total);
        $balance = class_exists('BHM_Wallet') ? BHM_Wallet::balance_cents($referrer_id) : 0;
        $rows[] = OUS_TestRunner::assert_same($expected_cents, $balance, 'A completed order using the referral code credits the referrer exactly ' . BHM_Referrals::COMMISSION_PERCENT . '% of the (post-discount) order total');

        $redemption_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . BHM_Tables::referrals() . " WHERE wc_order_id = %d", $order->get_id()
        ));
        $rows[] = OUS_TestRunner::assert_same(1, $redemption_count, 'Exactly one bhm_referrals redemption row is written for the order');

        // Idempotency — calling the handler again for the SAME order
        // must not double-credit (the UNIQUE KEY on wc_order_id is the
        // atomic guard, not a prior SELECT).
        BHM_Referrals::on_order_completed($order->get_id());
        $balance_after_replay = class_exists('BHM_Wallet') ? BHM_Wallet::balance_cents($referrer_id) : 0;
        $rows[] = OUS_TestRunner::assert_same($expected_cents, $balance_after_replay, 'Re-firing on_order_completed() for the same order does NOT credit the referrer a second time');

        /* ---------- self-referral guard ---------- */
        $self_order = wc_create_order(['customer_id' => $referrer_id]);
        $self_order->add_product($product, 1);
        $self_order->apply_coupon($code);
        $self_order->calculate_totals();
        $self_order->update_status('completed');
        $balance_after_self = class_exists('BHM_Wallet') ? BHM_Wallet::balance_cents($referrer_id) : 0;
        $rows[] = OUS_TestRunner::assert_same($expected_cents, $balance_after_self, 'A referrer using their own code on their own order earns NO additional commission (self-referral guard)');
        $self_redemption_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . BHM_Tables::referrals() . " WHERE wc_order_id = %d", $self_order->get_id()
        ));
        $rows[] = OUS_TestRunner::assert_same(0, $self_redemption_count, 'A self-referral order writes no redemption row at all');

        // Cleanup
        $wpdb->delete(BHM_Tables::referrals(), ['wc_order_id' => $order->get_id()]);
        $wpdb->delete(BHM_Tables::referrals(), ['wc_order_id' => $self_order->get_id()]);
        $wpdb->delete(BHM_Tables::referral_codes(), ['user_id' => $referrer_id]);
        $wpdb->delete(BHM_Tables::wallet(), ['user_id' => $referrer_id]);
        $wpdb->delete(BHM_Tables::wallet_ledger(), ['user_id' => $referrer_id]);
        if ($coupon_id) wp_delete_post($coupon_id, true);
        $order->delete(true);
        $self_order->delete(true);
        wp_delete_post($product->get_id(), true);

        return $rows;
    }

    // Audit fix (2026-07-26): BHM_PurchaseLedger + BHM_Anchoring
    // (tamper-evident proof-of-purchase, ROADMAP-streaming-media-scope-
    // and-blockchain.md Part 2) had zero test coverage since they
    // shipped. Live-verified in a browser this pass (a real WC order,
    // a real anchoring job, a real refund) — this covers the same
    // ground as a real DB-backed fixture, matching every other suite
    // in this file, EXCEPT BHM_Anchoring's own actual network calls to
    // OpenTimestamps (handle_submit_job()/handle_upgrade_job()):
    // deliberately NOT exercised here — hitting a real third-party
    // calendar server from an automated suite is slow, flaky, and not
    // CI-friendly (the live browser pass already confirmed the real
    // submission round-trip works). This only asserts that write_row()
    // successfully ENQUEUES the job — a job-queue interaction, not the
    // HTTP call itself.
    /** @return array<int, array<string, mixed>> */
    private static function run_purchase_ledger_tests(): array {
        if (!class_exists('BHS_API')) return []; // content_hash lookup needs a real bhs_track

        $rows = [];
        global $wpdb;
        $t = BHM_Tables::purchase_ledger();

        $track_id = wp_insert_post([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'BHM Purchase Ledger Suite Fixture Track',
            'meta_input' => ['bhcore_is_test' => 'bhm_purchase_ledger_suite', '_bhs_audio_hash' => 'suitehash1234567890'],
        ], true);
        if (is_wp_error($track_id)) {
            return [['name' => 'Purchase-ledger fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bhs_track post — skipping.']];
        }

        $product_id = BHM_ProductSync::sync_object_purchase_product($track_id, 'bhs_track', 199);
        $customer_id = OUS_Debug::get_or_create_test_user('bhm_purchase_ledger_suite_customer', false);
        $wpdb->delete($t, ['user_id' => $customer_id]);

        $order = wc_create_order(['customer_id' => $customer_id]);
        $order->add_product(wc_get_product($product_id), 1);
        $order->calculate_totals();
        $order->update_status('completed'); // fires the real woocommerce_order_status_completed path, same convention as run_referral_tests() above

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE wc_order_id = %d AND event_type = 'purchase'", $order->get_id()));
        $rows[] = OUS_TestRunner::assert_true((bool) $row, 'A completed order for a downloadable track-purchase product writes a real bhm_purchase_ledger row');
        if ($row) {
            $rows[] = OUS_TestRunner::assert_same('suitehash1234567890', $row->content_hash, 'The ledger row carries the track\'s real _bhs_audio_hash as content_hash');
            $rows[] = OUS_TestRunner::assert_same(199, (int) $row->price_cents, 'The ledger row records the order total in cents, not dollars');
            $rows[] = OUS_TestRunner::assert_true((bool) $row->record_hash && strlen($row->record_hash) === 64, 'record_hash is a real 64-char SHA-256 hex digest');
            $rows[] = OUS_TestRunner::assert_same('pending', $row->anchor_status, 'A freshly-written row starts anchor_status=pending, before any anchoring job has run');

            // write_row() -> anchor_async() should have enqueued a real
            // bhm_anchor_submit job for this row — checked via Action
            // Scheduler's own query API (this ecosystem runs on real
            // Action Scheduler, not the OUS_Jobs fallback table, per
            // BHM_Anchoring::init()'s registration). Not asserting the
            // job actually RAN (see this method's own docblock).
            // Queried directly against Action Scheduler's own table
            // rather than as_get_scheduled_actions() — that helper
            // defaults to pending-only status, but this install's
            // Action Scheduler runner processes jobs almost immediately
            // (confirmed live this pass), so by the time this query
            // runs the job may already be 'complete'. The assertion is
            // "a job was enqueued at all" (any status), not "one is
            // still waiting."
            $as_table = $wpdb->prefix . 'actionscheduler_actions';
            if ($wpdb->get_var("SHOW TABLES LIKE '$as_table'") === $as_table) {
                $enqueued_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $as_table WHERE hook = 'bhm_anchor_submit' AND args LIKE %s",
                    '%"ledger_row_id":' . (int) $row->id . '%'
                ));
                $rows[] = OUS_TestRunner::assert_true($enqueued_count > 0, 'write_row() enqueues a real bhm_anchor_submit job for the new row (not asserting the network call itself ran)');
            }
        }

        // Reversal: refunding the order must write a SECOND, linked row
        // — never mutate/delete the original.
        $order->update_status('refunded');
        $reversal = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE wc_order_id = %d AND event_type = 'reversal'", $order->get_id()));
        $rows[] = OUS_TestRunner::assert_true((bool) $reversal, 'Refunding the order writes a linked reversal row rather than mutating the original purchase row');
        if ($reversal && $row) {
            $rows[] = OUS_TestRunner::assert_same((string) $row->id, (string) $reversal->linked_record_id, 'The reversal row\'s linked_record_id points back at the original purchase row');
        }

        // The double-reversal guard: a second status transition through
        // a reversal-triggering hook (e.g. refunded -> cancelled, a real
        // multi-step lifecycle) must NOT record a second reversal for
        // the same already-reversed purchase.
        BHM_PurchaseLedger::on_order_reversed($order->get_id());
        $reversal_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE wc_order_id = %d AND event_type = 'reversal'", $order->get_id()));
        $rows[] = OUS_TestRunner::assert_same(1, $reversal_count, 'Calling on_order_reversed() again for an already-reversed order does not record a second reversal (the NOT EXISTS guard)');

        // Read helpers
        $rows[] = OUS_TestRunner::assert_same(1, count(BHM_PurchaseLedger::for_user($customer_id)), 'for_user() returns exactly this customer\'s one purchase row');
        if ($row) {
            $found_reversal = BHM_PurchaseLedger::reversal_for($row->id);
            $rows[] = OUS_TestRunner::assert_true((bool) $found_reversal, 'reversal_for() finds the linked reversal row for the original purchase');
        }

        // Cleanup
        $wpdb->delete($t, ['wc_order_id' => $order->get_id()]);
        $order->delete(true);
        if ($product_id) wp_delete_post($product_id, true);
        wp_delete_post($track_id, true);

        return $rows;
    }
}
