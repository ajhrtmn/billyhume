<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-registry — same convention as
 * bh-contest/bh-courses/bh-monetization-woo's own class-test-suite.php.
 * This plugin had ZERO test coverage before this pass, despite
 * class-verification.php being the actual trust mechanism the whole
 * registry depends on (domain-ownership + protocol-openness — see that
 * file's own docblock). Covers exactly that class, plus a fixture-based
 * end-to-end run of verify_link() against a real bhr_links row.
 *
 * HTTP calls (wp_remote_get()) are mocked via WordPress core's own
 * 'pre_http_request' filter — the standard, real short-circuit hook
 * every HTTP-calling plugin already respects, not a bespoke test seam.
 */
class BHR_TestSuite {
    const SEED_TAG = 'bhr_test_suite';

    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-registry'] = ['label' => 'BH Registry', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
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

    private static function run_domain_ownership_tests() {
        $rows = [];
        $check = new ReflectionMethod('BHR_Verification', 'check_domain_ownership');

        // Exact-line token match, HTTP 200 — the success path.
        $filter = function () { return ['response' => ['code' => 200], 'body' => "some\nOTHERTOKEN123\nmore"]; };
        add_filter('pre_http_request', $filter, 10, 3);
        $rows[] = OUS_TestRunner::assert_true(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): token present as its own line in the challenge file passes'
        );
        remove_filter('pre_http_request', $filter, 10);

        // Substring match must NOT pass — this is an exact-line match by
        // design (this class's own docblock: "not a substring-of-
        // arbitrary-page"). A regression here would let a page merely
        // CONTAINING the token (e.g. embedded in unrelated text) count
        // as proof of control.
        $filter = function () { return ['response' => ['code' => 200], 'body' => 'prefix-OTHERTOKEN123-suffix']; };
        add_filter('pre_http_request', $filter, 10, 3);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): token only as a SUBSTRING of a line (not its own line) correctly fails'
        );
        remove_filter('pre_http_request', $filter, 10);

        // Right domain, wrong token — must fail, not just "any 200 passes".
        $filter = function () { return ['response' => ['code' => 200], 'body' => 'WRONGTOKEN']; };
        add_filter('pre_http_request', $filter, 10, 3);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a 200 response with the WRONG token correctly fails'
        );
        remove_filter('pre_http_request', $filter, 10);

        // A non-200 status (challenge file not published, or a redirect
        // to an error page) must fail even if the body happens to be
        // empty rather than throwing.
        $filter = function () { return ['response' => ['code' => 404], 'body' => '']; };
        add_filter('pre_http_request', $filter, 10, 3);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a 404 (challenge file never published) correctly fails, not just an empty-body pass'
        );
        remove_filter('pre_http_request', $filter, 10);

        // A WP_Error (DNS failure, timeout) must fail cleanly, not throw.
        $filter = function () { return new WP_Error('http_request_failed', 'Could not resolve host'); };
        add_filter('pre_http_request', $filter, 10, 3);
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'https://example.test/feed', 'OTHERTOKEN123'),
            'check_domain_ownership(): a WP_Error (DNS/timeout) correctly fails without throwing'
        );
        remove_filter('pre_http_request', $filter, 10);

        // An unparseable URL (no host) must fail without ever attempting
        // a request.
        $rows[] = OUS_TestRunner::assert_false(
            $check->invoke(null, 'not-a-url', 'OTHERTOKEN123'),
            'check_domain_ownership(): a URL with no parseable host correctly fails'
        );

        return $rows;
    }

    /* ---------- check_activitypub_actor() — private, via Reflection ---------- */

    private static function run_activitypub_actor_tests() {
        $rows = [];
        $check = new ReflectionMethod('BHR_Verification', 'check_activitypub_actor');

        // A real, spec-shaped actor document passes.
        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode([
                'type' => 'Person', 'name' => 'Test Artist', 'outbox' => 'https://example.test/outbox',
            ])];
        };
        add_filter('pre_http_request', $filter, 10, 3);
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

        // Missing outbox must fail — this is the actual anti-spoofing
        // check (this class's own docblock: "any recognized JSON blob"
        // must NOT pass just by claiming a valid type).
        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'Person', 'name' => 'No Outbox'])];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): a "Person" type with NO outbox correctly fails (missing outbox is the actual spoof-prevention check)'
        );

        // An unrecognized type must fail even with an outbox present —
        // a regression that dropped/loosened $valid_types would let
        // arbitrary JSON pass.
        $filter = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['type' => 'NotAnActorType', 'outbox' => 'https://example.test/outbox'])];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): an unrecognized "type" value correctly fails even with an outbox present'
        );

        // Non-JSON / arbitrary body must fail without throwing.
        $filter = function () { return ['response' => ['code' => 200], 'body' => '<html>not json</html>']; };
        add_filter('pre_http_request', $filter, 10, 3);
        $result = $check->invoke(null, 'https://example.test/actor');
        remove_filter('pre_http_request', $filter, 10);
        $rows[] = OUS_TestRunner::assert_false(
            $result['valid'],
            'check_activitypub_actor(): a non-JSON response body correctly fails without throwing'
        );

        return $rows;
    }

    /* ---------- verify_link() end-to-end, real fixture row + DB ---------- */

    private static function run_verify_link_tests() {
        $rows = [];
        global $wpdb;
        $artists_table = $wpdb->prefix . 'bhr_artists';
        $links_table   = $wpdb->prefix . 'bhr_links';

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

        // Both checks pass: domain ownership (well-known file has the
        // token) AND the actor document is valid — verify_link() should
        // mark the link verified, reset fail_count, and activate the
        // artist (maybe_activate_artist()'s own "first verified link
        // makes it public" rule).
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

        // Ownership passes but the actor document is invalid (no
        // outbox) — verify_link() must NOT verify on a partial pass
        // (this class's own rule: BOTH checks must pass), and must
        // increment fail_count from whatever it was.
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

        // Cleanup — real DB rows, no stray fixture data left behind.
        $wpdb->delete($links_table, ['id' => $link_id]);
        $wpdb->delete($artists_table, ['id' => $artist_id]);

        return $rows;
    }
}
