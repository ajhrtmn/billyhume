<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see class-test-runner.php) version of tests/TwoFactorTest.php
 * — same RFC 6238 Appendix B vectors, same reasoning, but expressed as
 * plain assertions runnable from the Debug Tools page on the site's own
 * PHP, instead of requiring PHPUnit/CLI. The PHPUnit version stays in
 * tests/ for real CI later; this is the "no CLI needed" version of the
 * same coverage for day-to-day use.
 */
class OUS_CoreTestSuite {
    const RFC_TEST_SECRET_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['own-ur-shit'] = ['label' => 'Own Ur Shit (core)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    private static function call_private($method, ...$args) {
        $ref = new \ReflectionMethod('BHI_TwoFactor', $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public static function run() {
        if (!class_exists('BHI_TwoFactor')) {
            return [['name' => 'BHI_TwoFactor not loaded', 'pass' => false, 'message' => 'Skipped — core 2FA class not found.']];
        }

        $rows = [];

        // RFC 6238 vectors — [unix_time, expected last-6-digits]
        $vectors = [
            [59, '287082'], [1111111109, '081804'], [1111111111, '050471'],
            [1234567890, '005924'], [2000000000, '279037'],
        ];
        foreach ($vectors as [$time, $expected]) {
            $timeslice = (int) floor($time / 30);
            $code = self::call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice);
            $rows[] = OUS_TestRunner::assert_same($expected, $code, "RFC 6238 vector matches at unix time $time");
        }

        $timeslice = (int) floor(time() / 30);
        $current = self::call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice);
        $rows[] = OUS_TestRunner::assert_true(BHI_TwoFactor::verify_code(0, $current, self::RFC_TEST_SECRET_B32), 'Current window code is accepted');

        $before = self::call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice - 1);
        $after = self::call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice + 1);
        $rows[] = OUS_TestRunner::assert_true(BHI_TwoFactor::verify_code(0, $before, self::RFC_TEST_SECRET_B32), 'One step in the past accepted (clock drift tolerance)');
        $rows[] = OUS_TestRunner::assert_true(BHI_TwoFactor::verify_code(0, $after, self::RFC_TEST_SECRET_B32), 'One step in the future accepted (clock drift tolerance)');

        $too_old = self::call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice - 2);
        $rows[] = OUS_TestRunner::assert_false(BHI_TwoFactor::verify_code(0, $too_old, self::RFC_TEST_SECRET_B32), 'Two steps away is rejected');
        $rows[] = OUS_TestRunner::assert_false(BHI_TwoFactor::verify_code(0, '000000', self::RFC_TEST_SECRET_B32), 'Wrong code is rejected');

        foreach (['', '12345', '1234567', 'abcdef', '123 456'] as $bad) {
            $rows[] = OUS_TestRunner::assert_false(BHI_TwoFactor::verify_code(0, $bad, self::RFC_TEST_SECRET_B32), "Malformed code '$bad' is rejected");
        }
        $rows[] = OUS_TestRunner::assert_false(BHI_TwoFactor::verify_code(123, '123456', null), 'No secret available never passes');

        // base32 codec
        $original = random_bytes(20);
        $encoded = self::call_private('base32_encode', $original);
        $decoded = self::call_private('base32_decode', $encoded);
        $rows[] = OUS_TestRunner::assert_same($original, $decoded, 'base32 round-trips arbitrary bytes exactly');
        $rows[] = OUS_TestRunner::assert_true((bool) preg_match('/^[A-Z2-7]+$/', $encoded), 'base32 encoding uses only the RFC 4648 alphabet');

        $clean = self::call_private('base32_encode', 'test-secret-value!!');
        $with_separators = substr($clean, 0, 4) . '-' . substr($clean, 4, 4) . ' ' . substr($clean, 8);
        $rows[] = OUS_TestRunner::assert_same(
            self::call_private('base32_decode', $clean),
            self::call_private('base32_decode', $with_separators),
            'base32 decode ignores stray dashes/spaces rather than corrupting'
        );

        return $rows;
    }
}
