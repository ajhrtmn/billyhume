<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for BHR_Verification, with HTTP mocked via the 'pre_http_request' filter.
 */
class BHR_TestSuite {
    const SEED_TAG = 'bhr_test_suite';

    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-registry'] = ['label' => 'BH Registry', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHR_Verification')) {
            return [['name' => 'BHR_Verification not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_domain_ownership_tests());
        $rows = array_merge($rows, self::run_activitypub_actor_tests());
        $rows = array_merge($rows, self::run_verify_link_tests());
        return $rows;
    }

    /* ---------- check_domain_ownership() — private, via Reflection ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_domain_ownership_tests(): array {
        $rows = [];
        $check = new ReflectionMethod('BHR_Verification', 'check_domain_ownership');

        $filter = function () { return ['response' => ['code' => 200], 'body' => "some\nOTHERTOKEN123\nmore"]; };
        add_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_true(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): token present as its own line in the challenge file passes'
        );
        remove_filter('pre_http_request', $filter, 10);

        // Exact-line match by design: a page merely containing the token is not proof of control.
        $filter = function () { return ['response' => ['code' => 200], 'body' => 'prefix-OTHERTOKEN123-suffix']; };
        add_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): token only as a SUBSTRING of a line (not its own line) correctly fails'
        );
        remove_filter('pre_http_request', $filter, 10);

        $filter = function () { return ['response' => ['code' => 200], 'body' => 'WRONGTOKEN']; };
        add_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a 200 response with the WRONG token correctly fails'
        );
        remove_filter('pre_http_request', $filter, 10);

        $filter = function () { return ['response' => ['code' => 404], 'body' => '']; };
        add_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a 404 (challenge file never published) correctly fails, not just an empty-body pass'
        );
        remove_filter('pre_http_request', $filter, 10);

        $filter = function () { return new WP_Error('http_request_failed', 'Could not resolve host'); };
        add_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a WP_Error (DNS/timeout) correctly fails without throwing'
        );
        remove_filter('pre_http_request', $filter, 10);

        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'not-a-url', 'OTHERTOKEN123'),
            'check_domain_ownership(): a URL with no parseable host correctly fails'
        );

        return $rows;
    }

    /* ---------- check_activitypub_actor() — private, via Reflection ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_activitypub_actor_tests(): array {
        $rows = [];
        $check = new ReflectionMethod('BHR_Verification', 'check_activitypub_actor');

        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode([
                'type' => 'Person', 'name' => 'Test Artist', 'outbox' => 'https://example.test/outbox',
            ])];
        };
        add_filter('pre_http_request', $filter, 10);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_true(
            $result['valid'],
            'check_activitypub_actor(): a valid Person actor with a real outbox passes'
        );
        $rows[] = OUS_TestRunner::assert_same(
            'Test Artist', $result['metadata']['name'] ?? null,
            'check_activitypub_actor(): metadata carries the actor\'s display name through'
        );

        // Missing outbox is the anti-spoofing check.
        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'Person', 'name' => 'No Outbox'])];
        };
        add_filter('pre_http_request', $filter, 10);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): a "Person" type with NO outbox correctly fails (missing outbox is the actual spoof-prevention check)'
        );

        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'NotAnActorType', 'outbox' => 'https://example.test/outbox'])];
        };
        add_filter('pre_http_request', $filter, 10);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): an unrecognized "type" value correctly fails even with an outbox present'
        );

        $filter = function () { return ['response' => ['code' => 200], 'body' => '<html>not json</html>']; };
        add_filter('pre_http_request', $filter, 10);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): a non-JSON response body correctly fails without throwing'
        );

        return $rows;
    }

    /* ---------- verify_link() end-to-end, real fixture row + DB ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_verify_link_tests(): array {
        $rows = [];
        global $wpdb;
        $artists_table = BHR_Tables::artists();
        $links_table   = BHR_Tables::links();

        $artist_id = null;
        $wpdb->insert($artists_table, [
            'display_name' => 'Test Suite Fixture Artist', 'status' => 'pending',
        ]);
        $artist_id = (int) $wpdb->insert_id;
        if (!$artist_id) {
            return [['name' => 'BHR_TestSuite fixture artist insert failed', 'pass' => false, 'message' => (string) $wpdb->last_error]];
        }

        $token = 'FIXTURETOKEN456';
        $wpdb->insert($links_table, [
            'artist_id' => $artist_id, 'protocol' => 'activitypub', 'url' => 'https://example.test/actor',
            'verification_token' => $token, 'verification_status' => 'pending', 'fail_count' => 0,
        ]);
        $link_id = (int) $wpdb->insert_id;

        // Both checks pass: expect verified, fail_count reset, artist activated.
        $filter = function ($preempt, $args, $url) use ($token) {
            if (strpos($url, '.well-known') !== false) {
                return ['response' => ['code' => 200], 'body' => $token];
            }
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'Person', 'outbox' => 'https://example.test/outbox'])];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $links_table WHERE id = %d", $link_id));
        $result = BHR_Verification::verify_link($link);
        remove_filter('pre_http_request', $filter, 10);

        $rows[] = OUS_TestRunner::assert_true($result, 'verify_link(): both ownership and openness passing returns true');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $links_table WHERE id = %d", $link_id));
        $rows[] = OUS_TestRunner::assert_same('verified', $row->verification_status, 'verify_link(): persists verification_status = verified on success');
        $rows[] = OUS_TestRunner::assert_same(0, (int) $row->fail_count, 'verify_link(): fail_count is reset to 0 on success');

        $artist = $wpdb->get_row($wpdb->prepare("SELECT * FROM $artists_table WHERE id = %d", $artist_id));
        $rows[] = OUS_TestRunner::assert_same('active', $artist->status, 'verify_link(): a first verified link activates the previously-pending artist (maybe_activate_artist())');

        // Partial pass (no outbox): expect failed and fail_count incremented.
        $wpdb->update($links_table, ['verification_status' => 'pending', 'fail_count' => 2], ['id' => $link_id]);
        $filter = function ($preempt, $args, $url) use ($token) {
            if (strpos($url, '.well-known') !== false) {
                return ['response' => ['code' => 200], 'body' => $token];
            }
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'Person'])]; // no outbox
        };
        add_filter('pre_http_request', $filter, 10, 3);
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $links_table WHERE id = %d", $link_id));
        $result = BHR_Verification::verify_link($link);
        remove_filter('pre_http_request', $filter, 10);

        $rows[] = OUS_TestRunner::assert_false($result, 'verify_link(): ownership passing but openness failing (no outbox) correctly does NOT verify — both checks are required');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $links_table WHERE id = %d", $link_id));
        $rows[] = OUS_TestRunner::assert_same('failed', $row->verification_status, 'verify_link(): a partial pass persists verification_status = failed, not verified');
        $rows[] = OUS_TestRunner::assert_same(3, (int) $row->fail_count, 'verify_link(): fail_count increments (not resets) on a failed re-check');

        $wpdb->delete($links_table, ['id' => $link_id]);
        $wpdb->delete($artists_table, ['id' => $artist_id]);

        return $rows;
    }
}
