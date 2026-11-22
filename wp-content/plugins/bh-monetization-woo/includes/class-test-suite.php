<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner suite (bhcore_test_suites). Pure helpers are tested directly; wallet, tier,
 * referral, ledger and auction logic run against real, tagged, cleaned-up DB fixtures.
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

        /* ---------- annual_savings_percent() ---------- */

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

        /* ---------- user_has_tier_access()/user_has_benefit() real gating, seeded ---------- */
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

        /* ---------- BHM_Wallet debit/credit + ledger consistency ---------- */
        if (class_exists('BHM_Wallet') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_wallet_tests());
        } elseif (class_exists('BHM_Wallet')) {
            $rows[] = ['name' => 'BHM_Wallet tests skipped', 'pass' => false, 'message' => 'OUS_Debug (for get_or_create_test_user) not loaded.'];
        }

        /* ---------- tier exclusivity (BHM_Entitlements::grant_entitlement(), via reflection) ---------- */
        if (class_exists('BHM_Entitlements') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_tier_exclusivity_tests());
        }

        // The referral suite needs WooCommerce (real product/order/coupon).
        if (class_exists('BHM_Referrals') && class_exists('OUS_Debug') && BH_Commerce::available()) {
            $rows = array_merge($rows, self::run_referral_tests());
        }

        if (class_exists('BHM_GiftAccess') && class_exists('OUS_Debug') && BH_Commerce::available()) {
            $rows = array_merge($rows, self::run_gift_access_tests());
        }

        if (class_exists('BHM_Wallet') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_wallet_hold_tests());
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

        $grant->invoke(null, $uid, 'subscription', 'account', $cheap_tier, 1000001, null, gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')));
        $count_after_first = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_first, 'Granting a first tier leaves exactly one active entitlement row');

        // Grant a DIFFERENT (pricier) tier — must replace, not stack.
        $grant->invoke(null, $uid, 'subscription', 'account', $pricey_tier, 1000002, null, gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')));
        $rows_after_switch = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, count($rows_after_switch), 'Granting a DIFFERENT tier replaces the old one — never two simultaneous active tiers');
        $rows[] = OUS_TestRunner::assert_same((string) $pricey_tier, (string) ($rows_after_switch[0]->object_id ?? null), 'After switching tiers, the ONE remaining row is the newly-granted tier, not the old one');

        // Same tier again (early renewal).
        $grant->invoke(null, $uid, 'subscription', 'account', $pricey_tier, 1000003, null, gmdate('Y-m-d H:i:s', strtotime('+60 days')));
        $count_after_renewal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_renewal, 'Re-granting the SAME tier (early renewal) still leaves exactly one row, not a second stacked one');

        $wpdb->delete($t, ['user_id' => $uid]);
        wp_delete_post($cheap_tier, true);
        wp_delete_post($pricey_tier, true);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_wallet_tests(): array {
        $rows = [];
        global $wpdb;
        $uid = OUS_Debug::get_or_create_test_user('bhm_wallet_suite', false); // false = fresh user, never a pool member
        $wallet_table = BHM_Tables::wallet();
        $ledger_table = BHM_Tables::wallet_ledger();

        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        BHM_Wallet::apply_ledger_delta($uid, 500, 'test_topup');
        $balance = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(500, $balance, 'apply_ledger_delta(+500) credits the wallet balance to exactly 500');
        $ledger_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d AND reason = %s", $uid, 'test_topup'));
        $rows[] = OUS_TestRunner::assert_same(1, $ledger_count, 'apply_ledger_delta() writes exactly one matching ledger row');

        $debit_ok = BHM_Wallet::debit($uid, 200, null);
        $rows[] = OUS_TestRunner::assert_true($debit_ok, 'debit(200) against a 500-cent balance succeeds');
        $balance_after_debit = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(300, $balance_after_debit, 'balance after debit(200) from 500 is exactly 300, not off-by-one-row-affected or double-counted');
        $ledger_debit_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d AND reason = %s AND delta_cents = %d", $uid, 'play', -200));
        $rows[] = OUS_TestRunner::assert_same(1, $ledger_debit_count, 'a successful debit() writes a matching -200 ledger row (reason "play")');

        // Over-balance debit must be declined by debit()'s atomic WHERE guard.
        $decline_ok = BHM_Wallet::debit($uid, 9999, null);
        $rows[] = OUS_TestRunner::assert_false($decline_ok, 'debit(9999) against a 300-cent balance is correctly declined');
        $balance_after_decline = (int) $wpdb->get_var($wpdb->prepare("SELECT balance_cents FROM $wallet_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(300, $balance_after_decline, 'a declined debit leaves the balance completely unchanged (never negative)');
        $ledger_after_decline = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger_table WHERE user_id = %d", $uid));
        $rows[] = OUS_TestRunner::assert_same(2, $ledger_after_decline, 'a declined debit writes NO new ledger row (still just the +500 and -200 from before)');

        // The tagged test user itself is left for OUS_Debug's "Reset Everything" sweep.
        $wpdb->delete($wallet_table, ['user_id' => $uid]);
        $wpdb->delete($ledger_table, ['user_id' => $uid]);

        return $rows;
    }

    /** BHM_Wallet::hold()/release_hold()/capture_hold() against a real wallet. */
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

        // Replay must not double-credit (UNIQUE KEY on wc_order_id).
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

    // Purchase ledger + anchoring. Only asserts the anchor job is enqueued; the
    // OpenTimestamps network call is deliberately not exercised (slow, flaky).
    /** @return array<int, array<string, mixed>> */
    private static function run_gift_access_tests(): array {
        global $wpdb;
        $rows = [];
        $track_id = wp_insert_post([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'BHM Gift Access Suite Fixture Track',
            'meta_input' => ['bhcore_is_test' => 'bhm_gift_access_suite'],
        ], true);
        if (is_wp_error($track_id)) return [['name' => 'Gift-access fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture bhs_track post.']];
        $product_id = BHM_ProductSync::sync_object_purchase_product($track_id, 'bhs_track', 499);
        $recipient = get_userdata(OUS_Debug::get_or_create_test_user('bhm_gift_access_suite', false));
        $wpdb->delete(BHM_Tables::entitlements(), ['user_id' => $recipient->ID, 'object_id' => $track_id]);

        $order_id = BHM_GiftAccess::gift($track_id, $recipient->user_email, 'Enjoy', 0);
        $order = is_wp_error($order_id) ? null : wc_get_order($order_id);
        $rows[] = OUS_TestRunner::assert_true((bool) $order, 'Gift access creates a real WooCommerce order');
        if ($order) {
            $rows[] = OUS_TestRunner::assert_same('completed', $order->get_status(), 'The gift order is completed, so the normal fulfilment hooks ran');
            $rows[] = OUS_TestRunner::assert_same(0.0, (float) $order->get_total(), 'The gift order totals $0 even though the product has a price');
            $rows[] = OUS_TestRunner::assert_true(BHM_Gate::user_owns_object($recipient->ID, $track_id), 'The recipient owns the item after the gift');
            $again = BHM_GiftAccess::gift($track_id, $recipient->user_email, '', 0);
            $rows[] = OUS_TestRunner::assert_same('owned', is_wp_error($again) ? $again->get_error_code() : 'created', 'Gifting the same item twice is refused instead of making a second order');
            $order->update_status('refunded');
            $rows[] = OUS_TestRunner::assert_true(!BHM_Gate::user_owns_object($recipient->ID, $track_id), 'Refunding the gift order takes access back, like any purchase');
            $wpdb->delete(BHM_Tables::purchase_ledger(), ['wc_order_id' => $order->get_id()]);
            $order->delete(true);
        }
        $wpdb->delete(BHM_Tables::entitlements(), ['user_id' => $recipient->ID, 'object_id' => $track_id]);
        if ($product_id) wp_delete_post($product_id, true);
        wp_delete_post($track_id, true);
        return $rows;
    }
}
