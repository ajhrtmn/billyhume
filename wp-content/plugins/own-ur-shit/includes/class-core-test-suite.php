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

    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['own-ur-shit'] = ['label' => 'The Self-Hosted Self (core)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    private static function call_private(string $method, ...$args) {
        $ref = new \ReflectionMethod('BHI_TwoFactor', $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    /** @return array<int, mixed> */
    public static function run(): array {
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

        // Link-in-bio (ecosystem depth-pass Tier 2a) — BHI_Profiles::save()/
        // get()'s links sanitization, exercised end-to-end against a real
        // test user rather than reflection, since sanitize_links()/
        // decode_links() are private and the save/get round trip IS the
        // actual contract callers depend on.
        if (class_exists('BHI_Profiles') && class_exists('OUS_Debug')) {
            $rows = array_merge($rows, self::run_profile_links_tests());
        }

        if (class_exists('BH_Mail')) {
            $rows = array_merge($rows, self::run_mail_tests());
        }

        return $rows;
    }

    /**
     * BH_Mail (class-mail.php) — the shared send() every plugin's own
     * notification/campaign/gift/contest-winner email now routes
     * through instead of calling wp_mail() directly. Never lets a real
     * email actually dispatch: pre_wp_mail (WP 5.7+) short-circuits the
     * send and hands back the exact assembled args (to/subject/message/
     * headers) instead, which is what these assertions check against —
     * the same technique any test suite would need to verify an email
     * WOULD have been sent correctly, without spamming a real inbox
     * every time this suite runs.
     */
    /** @return array<int, mixed> */
    private static function run_mail_tests(): array {
        $rows = [];

        $rows[] = OUS_TestRunner::assert_false(BH_Mail::send(['subject' => 'x', 'body' => 'y']), 'send() rejects when neither to nor a resolvable user_id is given');
        $rows[] = OUS_TestRunner::assert_false(BH_Mail::send(['to' => 'test@example.com', 'body' => 'y']), 'send() rejects a missing subject');
        $rows[] = OUS_TestRunner::assert_false(BH_Mail::send(['to' => 'test@example.com', 'subject' => 'x']), 'send() rejects a missing body');
        $rows[] = OUS_TestRunner::assert_false(BH_Mail::send(['user_id' => 999999999, 'subject' => 'x', 'body' => 'y']), 'send() rejects a user_id that resolves to no real user');

        $captured = null;
        $capture = function ($null, $atts) use (&$captured) { $captured = $atts; return true; };
        add_filter('pre_wp_mail', $capture, 10, 2);

        $user_id = class_exists('OUS_Debug') ? OUS_Debug::get_or_create_test_user('bh-mail-suite') : 0;
        if ($user_id) {
            $result = BH_Mail::send(['user_id' => $user_id, 'subject' => 'Test Subject', 'body' => 'Test body']);
            $rows[] = OUS_TestRunner::assert_true($result, 'send() returns true when the short-circuited send succeeds');
            $rows[] = OUS_TestRunner::assert_same('Test Subject', $captured['subject'] ?? null, 'send() passes the exact subject through to wp_mail()');
            $user = get_userdata($user_id);
            $rows[] = OUS_TestRunner::assert_same($user->user_email, $captured['to'] ?? null, "send() resolves a bare user_id to that user's real email");
        } else {
            $rows[] = OUS_TestRunner::assert_true(true, 'OUS_Debug test-user helper not available — user_id resolution skipped, explicit-\'to\' coverage below still applies');
        }

        $captured = null;
        BH_Mail::send(['to' => 'raw@example.com', 'user_id' => $user_id, 'subject' => 'Raw recipient test', 'body' => 'plain body', 'html' => true]);
        $rows[] = OUS_TestRunner::assert_same('raw@example.com', $captured['to'] ?? null, "an explicit 'to' address wins over user_id's own email when both are given");
        $rows[] = OUS_TestRunner::assert_true(
            in_array('Content-Type: text/html; charset=UTF-8', (array) ($captured['headers'] ?? []), true),
            "html => true adds the HTML content-type header"
        );

        $captured = null;
        BH_Mail::send(['to' => 'plain@example.com', 'subject' => 'Plain', 'body' => 'plain body']);
        $rows[] = OUS_TestRunner::assert_false(
            in_array('Content-Type: text/html; charset=UTF-8', (array) ($captured['headers'] ?? []), true),
            'omitting html leaves the plain-text default untouched (no stray HTML header)'
        );

        remove_filter('pre_wp_mail', $capture);

        return $rows;
    }

    /** @return array<int, mixed> */
    private static function run_profile_links_tests(): array {
        $rows = [];
        $user_id = OUS_Debug::get_or_create_test_user('bhi-profile-links');

        BHI_Profiles::save($user_id, ['links' => [
            ['label' => 'New single', 'url' => 'https://example.com/single'],
            ['label' => '', 'url' => 'https://example.com/dropped-empty-label'],
            ['label' => 'No URL', 'url' => ''],
        ]]);
        $data = BHI_Profiles::get($user_id);
        $rows[] = OUS_TestRunner::assert_same(1, count($data['links']), 'Links missing a label or URL are dropped, only the valid one is kept');
        $rows[] = OUS_TestRunner::assert_same('New single', $data['links'][0]['label'] ?? null, 'Valid link label round-trips');
        $rows[] = OUS_TestRunner::assert_same('https://example.com/single', $data['links'][0]['url'] ?? null, 'Valid link URL round-trips');

        $many = [];
        for ($i = 0; $i < BHI_Profiles::MAX_LINKS + 5; $i++) {
            $many[] = ['label' => "Link $i", 'url' => "https://example.com/$i"];
        }
        BHI_Profiles::save($user_id, ['links' => $many]);
        $data = BHI_Profiles::get($user_id);
        $rows[] = OUS_TestRunner::assert_same(BHI_Profiles::MAX_LINKS, count($data['links']), 'Links are capped at MAX_LINKS even when more are submitted');

        BHI_Profiles::save($user_id, ['links' => []]);
        $data = BHI_Profiles::get($user_id);
        $rows[] = OUS_TestRunner::assert_same([], $data['links'], 'Saving an empty links array clears them, and get() defaults to an empty array');

        return $rows;
    }
}
