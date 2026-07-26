<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-live — same convention as every other
 * plugin's own class-test-suite.php. Covers BHL_Streams'
 * current_live_stream() query against real fixture posts,
 * BHL_OwncastEngine's settings round-trip (including the
 * blank-token-keeps-existing-secret rule), and BHL_OwncastChat/
 * BHL_API's payload shape. Deliberately does NOT exercise a real
 * network call to an actual Owncast server (same "not CI-friendly"
 * reasoning bh-monetization-woo's own OpenTimestamps suite already
 * documents for its own third-party network calls) — get_status()'s
 * real wp_remote_get behavior was already live-verified in a browser
 * this pass.
 */
class BHL_TestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-live'] = ['label' => 'BH Live', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHL_Streams')) {
            return [['name' => 'BHL_Streams not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_stream_lifecycle_tests());
        $rows = array_merge($rows, self::run_engine_settings_tests());
        $rows = array_merge($rows, self::run_api_tests());
        $rows = array_merge($rows, self::run_fly_provisioner_tests());
        return $rows;
    }

    /* ---------- BHL_Streams::current_live_stream() ---------- */

    private static function run_stream_lifecycle_tests() {
        $rows = [];

        $rows[] = OUS_TestRunner::assert_true(BHL_Streams::current_live_stream() === null, 'current_live_stream(): null when no bhl_stream is marked live (baseline, before this suite creates any fixture)');

        $live_id = wp_insert_post([
            'post_type' => 'bhl_stream', 'post_status' => 'publish', 'post_title' => 'BHL Suite Live Fixture',
            'meta_input' => ['_bhl_status' => BHL_PostTypes::STATUS_LIVE, '_bhl_started_at' => current_time('mysql'), 'bhcore_is_test' => 'bhl_suite'],
        ], true);
        $ended_id = wp_insert_post([
            'post_type' => 'bhl_stream', 'post_status' => 'publish', 'post_title' => 'BHL Suite Ended Fixture',
            'meta_input' => ['_bhl_status' => BHL_PostTypes::STATUS_ENDED, '_bhl_started_at' => current_time('mysql'), '_bhl_ended_at' => current_time('mysql'), 'bhcore_is_test' => 'bhl_suite'],
        ], true);

        $current = BHL_Streams::current_live_stream();
        $rows[] = OUS_TestRunner::assert_true($current && $current->ID === $live_id, 'current_live_stream(): finds the one post actually marked live, ignoring an ended one');

        update_post_meta($live_id, '_bhl_status', BHL_PostTypes::STATUS_ENDED);
        $rows[] = OUS_TestRunner::assert_true(BHL_Streams::current_live_stream() === null, 'current_live_stream(): null again once the only live record is closed out');

        wp_delete_post($live_id, true);
        wp_delete_post($ended_id, true);
        return $rows;
    }

    /* ---------- BHL_OwncastEngine settings ---------- */

    private static function run_engine_settings_tests() {
        if (!class_exists('BHL_OwncastEngine')) return [];
        $rows = [];
        $original = BHL_OwncastEngine::settings(); // restored at the end — this suite must not leave a real connection's settings clobbered

        BHL_OwncastEngine::save_settings('https://suite-test.invalid/', 'suite-token-1');
        $s = BHL_OwncastEngine::settings();
        $rows[] = OUS_TestRunner::assert_same('https://suite-test.invalid', $s['server_url'], 'save_settings(): a trailing slash is stripped from the server URL');
        $rows[] = OUS_TestRunner::assert_same('suite-token-1', $s['access_token'], 'save_settings(): access token saved correctly');

        BHL_OwncastEngine::save_settings('https://suite-test.invalid/', '');
        $s2 = BHL_OwncastEngine::settings();
        $rows[] = OUS_TestRunner::assert_same('suite-token-1', $s2['access_token'], 'save_settings(): a blank token field keeps the existing saved token rather than clearing it');

        $engine = new BHL_OwncastEngine();
        $rows[] = OUS_TestRunner::assert_true($engine->is_configured(), 'is_configured(): true once a server URL is saved');
        $rows[] = OUS_TestRunner::assert_true(strpos($engine->get_embed_html(), 'suite-test.invalid/embed/video') !== false, 'get_embed_html(): embeds the configured server\'s own /embed/video route');

        if (class_exists('BHL_OwncastChat')) {
            $chat = new BHL_OwncastChat();
            $rows[] = OUS_TestRunner::assert_true(strpos($chat->get_embed_html(), 'suite-test.invalid/embed/chat') !== false, 'BHL_OwncastChat::get_embed_html(): embeds the configured server\'s own /embed/chat route');
        }

        update_option('bhl_owncast_settings', $original);
        return $rows;
    }

    /* ---------- BHL_API payload shape ---------- */

    private static function run_api_tests() {
        if (!class_exists('BHL_API')) return [];
        $rows = [];

        // Offline baseline — no live fixture exists at this point in the suite.
        $status = BHL_API::get_status();
        $data = $status->get_data();
        $rows[] = OUS_TestRunner::assert_false($data['online'], 'get_status(): online is false when nothing is marked live');
        $rows[] = OUS_TestRunner::assert_same('', $data['embed_html'], 'get_status(): embed_html is empty when offline, not a stale value');

        // A replay with no attachment ever assigned is correctly excluded.
        $no_replay_id = wp_insert_post([
            'post_type' => 'bhl_stream', 'post_status' => 'publish', 'post_title' => 'BHL Suite No-Replay Fixture',
            'meta_input' => ['_bhl_status' => BHL_PostTypes::STATUS_ENDED, 'bhcore_is_test' => 'bhl_suite'],
        ], true);
        $replays = BHL_API::get_replays()->get_data()['replays'];
        $found = array_filter($replays, fn($r) => $r['id'] === $no_replay_id);
        $rows[] = OUS_TestRunner::assert_true(empty($found), 'get_replays(): a stream with no replay attachment ever assigned is excluded from the list, not shown with a broken url');

        wp_delete_post($no_replay_id, true);
        return $rows;
    }

    /* ---------- BHL_FlyProvisioner: real request shape, mocked network ---------- */

    // pre_http_request lets this suite verify the ACTUAL HTTP method/
    // URL/auth-header/body BHL_FlyProvisioner builds against Fly's
    // real, documented API (confirmed 2026-07-26) without ever hitting
    // the real network — same principle as the rest of this ecosystem's
    // "grounded, not guessed" testing discipline, just applied to an
    // external API's request shape instead of this codebase's own logic.
    private static function run_fly_provisioner_tests() {
        if (!class_exists('BHL_FlyProvisioner')) return [];
        $rows = [];
        $original = get_option('bhl_fly_settings');

        $captured = [];
        $intercept = function ($false, $args, $url) use (&$captured) {
            $captured[] = ['url' => $url, 'method' => $args['method'], 'headers' => $args['headers'] ?? [], 'body' => $args['body'] ?? null];
            if (strpos($url, '/machines') === false && $args['method'] === 'GET') {
                return ['response' => ['code' => 404], 'body' => wp_json_encode(['error' => 'not found'])];
            }
            if (strpos($url, '/v1/apps') !== false && strpos($url, '/machines') === false && $args['method'] === 'POST') {
                return ['response' => ['code' => 201], 'body' => wp_json_encode(['name' => 'suite-test-app'])];
            }
            if (strpos($url, '/machines') !== false && $args['method'] === 'POST') {
                return ['response' => ['code' => 200], 'body' => wp_json_encode(['id' => 'suite-machine-1', 'state' => 'created'])];
            }
            return $false;
        };
        add_filter('pre_http_request', $intercept, 10, 3);

        BHL_FlyProvisioner::save_settings('suite-token', 'suite-test-app', 'personal', 'iad');
        $p = new BHL_FlyProvisioner();
        $result = $p->provision();

        $rows[] = OUS_TestRunner::assert_false(is_wp_error($result), 'provision(): succeeds against a mocked 200/201 sequence');
        if (!is_wp_error($result)) {
            $rows[] = OUS_TestRunner::assert_same('suite-machine-1', $result['host_id'], 'provision(): returns the machine id Fly assigned');
            $rows[] = OUS_TestRunner::assert_same('https://suite-test-app.fly.dev', $result['public_url'], 'provision(): public_url is the app\'s own shared *.fly.dev hostname');
        }
        $rows[] = OUS_TestRunner::assert_same(3, count($captured), 'provision(): exactly 3 real requests — check app exists, create app, create machine');
        $rows[] = OUS_TestRunner::assert_true(strpos($captured[1]['body'], '"org_slug":"personal"') !== false, 'provision(): app-creation body includes the configured org slug');
        $body = json_decode($captured[2]['body'], true);
        $rows[] = OUS_TestRunner::assert_same('owncast/owncast:latest', $body['config']['image'] ?? null, 'provision(): machine config uses the real Owncast Docker image');
        $rows[] = OUS_TestRunner::assert_same(2, count($body['config']['services'] ?? []), 'provision(): declares both the HTTP/TLS web service and the raw-TCP RTMP service');
        $rows[] = OUS_TestRunner::assert_same('Bearer suite-token', $captured[0]['headers']['Authorization'] ?? null, 'provision(): every request carries the configured API token as a Bearer header');

        remove_filter('pre_http_request', $intercept, 10);

        // Error path — Fly rejecting the request (bad token, etc.)
        add_filter('pre_http_request', $error_intercept = function () {
            return ['response' => ['code' => 401], 'body' => wp_json_encode(['error' => 'invalid token'])];
        }, 10, 3);
        $result2 = $p->provision();
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($result2), 'provision(): a rejected request (401) returns a WP_Error, never a fatal');
        remove_filter('pre_http_request', $error_intercept, 10);

        if ($original === false) {
            delete_option('bhl_fly_settings');
        } else {
            update_option('bhl_fly_settings', $original);
        }
        return $rows;
    }
}
