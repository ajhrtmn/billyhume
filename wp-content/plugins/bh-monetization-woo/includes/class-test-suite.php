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

        return $rows;
    }
}
