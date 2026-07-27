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

    private static function run_referral_tests() {
        $rows = [];
        global $wpdb;

        $referrer_id = OUS_Debug::get_or_create_test_user('bhm_referral_suite_referrer', false);
        $customer_id = OUS_Debug::get_or_create_test_user('bhm_referral_suite_customer', false);
        $wpdb->delete($wpdb->prefix . 'bhm_referral_codes', ['user_id' => $referrer_id]);
        $wpdb->delete($wpdb->prefix . 'bhm_wallet', ['user_id' => $referrer_id]);
        $wpdb->delete($wpdb->prefix . 'bhm_wallet_ledger', ['user_id' => $referrer_id]);

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
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhm_referrals WHERE wc_order_id = %d", $order->get_id()
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
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhm_referrals WHERE wc_order_id = %d", $self_order->get_id()
        ));
        $rows[] = OUS_TestRunner::assert_same(0, $self_redemption_count, 'A self-referral order writes no redemption row at all');

        // Cleanup
        $wpdb->delete($wpdb->prefix . 'bhm_referrals', ['wc_order_id' => $order->get_id()]);
        $wpdb->delete($wpdb->prefix . 'bhm_referrals', ['wc_order_id' => $self_order->get_id()]);
        $wpdb->delete($wpdb->prefix . 'bhm_referral_codes', ['user_id' => $referrer_id]);
        $wpdb->delete($wpdb->prefix . 'bhm_wallet', ['user_id' => $referrer_id]);
        $wpdb->delete($wpdb->prefix . 'bhm_wallet_ledger', ['user_id' => $referrer_id]);
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
    private static function run_purchase_ledger_tests() {
        if (!class_exists('BHS_API')) return []; // content_hash lookup needs a real bhs_track

        $rows = [];
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_purchase_ledger';

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
