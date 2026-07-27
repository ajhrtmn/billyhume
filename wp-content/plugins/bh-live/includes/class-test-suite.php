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
        $rows = array_merge($rows, self::run_cloudflare_engine_tests());
        $rows = array_merge($rows, self::run_engine_registry_tests());
        $rows = array_merge($rows, self::run_polling_chat_tests());
        $rows = array_merge($rows, self::run_workers_chat_tests());
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
            $chat_render = $chat->render(0); // $stream_id is unused by BHL_OwncastChat — one chat room per server, not per stream
            $rows[] = OUS_TestRunner::assert_same('iframe', $chat_render['type'], 'BHL_OwncastChat::render(): type is always iframe');
            $rows[] = OUS_TestRunner::assert_true(strpos($chat_render['html'], 'suite-test.invalid/embed/chat') !== false, 'BHL_OwncastChat::render(): embeds the configured server\'s own /embed/chat route');
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

    /* ---------- BHL_CloudflareStreamEngine: real request shape, mocked network ---------- */

    private static function run_cloudflare_engine_tests() {
        if (!class_exists('BHL_CloudflareStreamEngine')) return [];
        $rows = [];
        $original = get_option('bhl_cloudflare_settings');

        BHL_CloudflareStreamEngine::save_connection_settings('suite-account', 'suite-token', 'suitecode');
        $engine = new BHL_CloudflareStreamEngine();
        $rows[] = OUS_TestRunner::assert_true($engine->is_configured(), 'is_configured(): true once account id/token/customer code are all saved');

        $captured = [];
        add_filter('pre_http_request', $create_intercept = function ($false, $args, $url) use (&$captured) {
            $captured[] = ['url' => $url, 'method' => $args['method'], 'headers' => $args['headers'] ?? [], 'body' => $args['body'] ?? null];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => [
                'uid' => 'suite-cf-uid', 'rtmps' => ['url' => 'rtmps://live.cloudflare.com:443/live/', 'streamKey' => 'suite-cf-key'],
            ]])];
        }, 10, 3);

        $result = $engine->create_live_input();
        $rows[] = OUS_TestRunner::assert_false(is_wp_error($result), 'create_live_input(): succeeds against a mocked 200 response');
        if (!is_wp_error($result)) {
            $rows[] = OUS_TestRunner::assert_same('suite-cf-uid', $result['uid'], 'create_live_input(): returns the uid Cloudflare assigned');
            $rows[] = OUS_TestRunner::assert_same('suite-cf-key', $result['stream_key'], 'create_live_input(): returns the stream key to paste into OBS');
        }
        $rows[] = OUS_TestRunner::assert_same('POST', $captured[0]['method'] ?? null, 'create_live_input(): a real POST to the live_inputs endpoint');
        $rows[] = OUS_TestRunner::assert_true(strpos($captured[0]['url'] ?? '', '/accounts/suite-account/stream/live_inputs') !== false, 'create_live_input(): URL includes the configured account id');
        $rows[] = OUS_TestRunner::assert_same('Bearer suite-token', $captured[0]['headers']['Authorization'] ?? null, 'create_live_input(): carries the configured API token as a Bearer header');
        remove_filter('pre_http_request', $create_intercept, 10);

        $rows[] = OUS_TestRunner::assert_same('suite-cf-uid', BHL_CloudflareStreamEngine::settings()['live_input_uid'], 'create_live_input(): persists the uid for later get_status()/get_embed_html() calls');
        $rows[] = OUS_TestRunner::assert_true(strpos($engine->get_embed_html(), 'suitecode.cloudflarestream.com/suite-cf-uid/iframe') !== false, 'get_embed_html(): embeds using the configured customer code + the created live input\'s uid');

        // status mapping — 'connected' is the only value that means online
        add_filter('pre_http_request', $status_intercept = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => ['status' => 'connected']])];
        }, 10, 3);
        $status = $engine->get_status();
        $rows[] = OUS_TestRunner::assert_true($status['online'], 'get_status(): status "connected" maps to online = true');
        remove_filter('pre_http_request', $status_intercept, 10);

        add_filter('pre_http_request', $status_intercept2 = function () {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => ['status' => 'reconnecting']])];
        }, 10, 3);
        $status2 = $engine->get_status();
        $rows[] = OUS_TestRunner::assert_false($status2['online'], 'get_status(): any status OTHER than "connected" maps to online = false');
        remove_filter('pre_http_request', $status_intercept2, 10);

        $rows[] = OUS_TestRunner::assert_true(is_wp_error($engine->disconnect()), 'disconnect(): honestly returns a WP_Error rather than a fake no-op — Cloudflare Stream Live has no such API');

        if ($original === false) {
            delete_option('bhl_cloudflare_settings');
        } else {
            update_option('bhl_cloudflare_settings', $original);
        }
        return $rows;
    }

    /* ---------- BHL_EngineRegistry ---------- */

    private static function run_engine_registry_tests() {
        if (!class_exists('BHL_EngineRegistry')) return [];
        $rows = [];
        $original = get_option('bhl_active_engine');
        $original_chat = get_option('bhl_active_chat');

        $rows[] = OUS_TestRunner::assert_same('owncast', BHL_EngineRegistry::active_key(), 'active_key(): defaults to owncast when never explicitly set');

        BHL_EngineRegistry::save_active_key('cloudflare');
        $rows[] = OUS_TestRunner::assert_same('cloudflare', BHL_EngineRegistry::active_key(), 'save_active_key(): switches the active engine correctly');
        $rows[] = OUS_TestRunner::assert_true(BHL_EngineRegistry::active() instanceof BHL_CloudflareStreamEngine, 'active(): resolves to the correct engine class once switched');
        $rows[] = OUS_TestRunner::assert_same('polling', BHL_EngineRegistry::active_chat_key(), 'active_chat_key(): defaults to the free polling chat under Cloudflare — Owncast\'s bundled chat isn\'t compatible with it');
        $rows[] = OUS_TestRunner::assert_true(BHL_EngineRegistry::active_chat() instanceof BHL_PollingChat, 'active_chat(): resolves to BHL_PollingChat by default under Cloudflare');

        // Explicitly choosing an incompatible chat for the current
        // engine (owncast_bundled while Cloudflare is active) falls
        // back to the engine's own default rather than resolving to a
        // chat class that can never actually work here.
        BHL_EngineRegistry::save_active_chat_key('owncast_bundled');
        $rows[] = OUS_TestRunner::assert_same('polling', BHL_EngineRegistry::active_chat_key(), 'active_chat_key(): an incompatible stored choice (owncast\'s chat while Cloudflare is active) falls back to the engine\'s own default rather than resolving to something that can\'t work');

        BHL_EngineRegistry::save_active_key('owncast');
        $rows[] = OUS_TestRunner::assert_true(BHL_EngineRegistry::active() instanceof BHL_OwncastEngine, 'active(): resolves back to Owncast after switching back');
        $rows[] = OUS_TestRunner::assert_true(BHL_EngineRegistry::active_chat() instanceof BHL_OwncastChat, 'active_chat(): resolves to BHL_OwncastChat by default when owncast is active');

        BHL_EngineRegistry::save_active_chat_key('polling');
        $rows[] = OUS_TestRunner::assert_true(BHL_EngineRegistry::active_chat() instanceof BHL_PollingChat, 'active_chat_key(): polling chat is selectable under Owncast too, since it declares no engine restriction');

        $rows[] = OUS_TestRunner::assert_same('owncast', (function () { BHL_EngineRegistry::save_active_key('not_a_real_engine'); return BHL_EngineRegistry::active_key(); })(), 'save_active_key(): an unrecognized key is silently ignored rather than corrupting the stored choice');

        if ($original_chat === false) {
            delete_option('bhl_active_chat');
        } else {
            update_option('bhl_active_chat', $original_chat);
        }
        if ($original === false) {
            delete_option('bhl_active_engine');
        } else {
            update_option('bhl_active_engine', $original);
        }
        return $rows;
    }

    /* ---------- BHL_PollingChat ---------- */

    private static function run_polling_chat_tests() {
        if (!class_exists('BHL_PollingChat')) return [];
        $rows = [];

        $chat = new BHL_PollingChat();
        $rows[] = OUS_TestRunner::assert_true($chat->is_configured(), 'is_configured(): always true — no external account needed');
        $render = $chat->render(999);
        $rows[] = OUS_TestRunner::assert_same('native', $render['type'], 'render(): type is native, not iframe');
        $rows[] = OUS_TestRunner::assert_true(strpos($render['html'], 'data-stream-id="999"') !== false, 'render(): embeds the given stream id in the mount container');

        $stream_id = wp_insert_post(['post_type' => 'bhl_stream', 'post_status' => 'publish', 'post_title' => 'BHL Chat Suite Fixture', 'meta_input' => ['bhcore_is_test' => 'bhl_chat_suite']], true);
        $poster = class_exists('OUS_Debug') ? OUS_Debug::get_or_create_test_user('bhl_chat_poster') : 1;
        $previous_user = get_current_user_id();
        wp_set_current_user($poster);

        $req = new WP_REST_Request('POST', '/bhl/v1/chat/' . $stream_id . '/messages');
        $req->set_url_params(['stream_id' => $stream_id]);
        $req->set_param('message', 'Hello from the suite');
        $result = BHL_PollingChat::post_message($req);
        $rows[] = OUS_TestRunner::assert_false(is_wp_error($result), 'post_message(): a real logged-in post succeeds');

        $get_req = new WP_REST_Request('GET', '/bhl/v1/chat/' . $stream_id . '/messages');
        $get_req->set_url_params(['stream_id' => $stream_id]);
        $get_req->set_param('after_id', 0);
        $messages = BHL_PollingChat::get_messages($get_req)->get_data()['messages'];
        $rows[] = OUS_TestRunner::assert_same(1, count($messages), 'get_messages(): the posted message shows up for this stream');
        $rows[] = OUS_TestRunner::assert_same('Hello from the suite', $messages[0]['message'] ?? null, 'get_messages(): message text round-trips correctly');

        // after_id pagination — asking for messages after the one just
        // fetched returns nothing new yet.
        $get_req2 = new WP_REST_Request('GET', '/bhl/v1/chat/' . $stream_id . '/messages');
        $get_req2->set_url_params(['stream_id' => $stream_id]);
        $get_req2->set_param('after_id', $messages[0]['id']);
        $rows[] = OUS_TestRunner::assert_same(0, count(BHL_PollingChat::get_messages($get_req2)->get_data()['messages']), 'get_messages(): after_id correctly excludes messages already seen');

        // Muted user can't post
        set_transient('bhl_chat_muted_' . $poster, 1, 60);
        $muted_req = new WP_REST_Request('POST', '/bhl/v1/chat/' . $stream_id . '/messages');
        $muted_req->set_url_params(['stream_id' => $stream_id]);
        $muted_req->set_param('message', 'Should be blocked');
        $muted_result = BHL_PollingChat::post_message($muted_req);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($muted_result), 'post_message(): a muted user\'s message is rejected');
        delete_transient('bhl_chat_muted_' . $poster);

        // Empty message rejected
        $empty_req = new WP_REST_Request('POST', '/bhl/v1/chat/' . $stream_id . '/messages');
        $empty_req->set_url_params(['stream_id' => $stream_id]);
        $empty_req->set_param('message', '   ');
        $rows[] = OUS_TestRunner::assert_true(is_wp_error(BHL_PollingChat::post_message($empty_req)), 'post_message(): a whitespace-only message is rejected, not saved as blank');

        // mute_user() sets the same transient post_message() checks
        $mute_req = new WP_REST_Request('POST', '/bhl/v1/chat/' . $stream_id . '/mute');
        $mute_req->set_param('target_user_id', $poster);
        BHL_PollingChat::mute_user($mute_req);
        $remuted_req = new WP_REST_Request('POST', '/bhl/v1/chat/' . $stream_id . '/messages');
        $remuted_req->set_url_params(['stream_id' => $stream_id]);
        $remuted_req->set_param('message', 'Should also be blocked');
        $rows[] = OUS_TestRunner::assert_true(is_wp_error(BHL_PollingChat::post_message($remuted_req)), 'mute_user(): the same transient block post_message() checks is actually set by the mute action');
        delete_transient('bhl_chat_muted_' . $poster);

        wp_set_current_user($previous_user);
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bhl_chat_messages', ['stream_id' => $stream_id]);
        wp_delete_post($stream_id, true);
        return $rows;
    }

    /* ---------- BHL_WorkersChat: real request shape, mocked network ---------- */

    private static function run_workers_chat_tests() {
        if (!class_exists('BHL_WorkersChat') || !class_exists('BHL_CloudflareStreamEngine')) return [];
        $rows = [];
        $original_cf = get_option('bhl_cloudflare_settings');
        $original_workers = get_option('bhl_workers_settings');

        BHL_CloudflareStreamEngine::save_connection_settings('suite-account', 'suite-token', 'suitecode');
        BHL_WorkersChat::save_settings('suite-chat-worker', 'suitesubdomain');
        $chat = new BHL_WorkersChat();

        $rows[] = OUS_TestRunner::assert_false($chat->is_configured(), 'is_configured(): false before a deploy has ever succeeded');

        $captured = [];
        add_filter('pre_http_request', $intercept = function ($false, $args, $url) use (&$captured) {
            $captured[] = ['url' => $url, 'method' => $args['method'], 'headers' => $args['headers'] ?? [], 'body' => $args['body'] ?? null];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => ['id' => 'suite-chat-worker']])];
        }, 10, 3);

        $result = $chat->deploy();
        remove_filter('pre_http_request', $intercept, 10);

        $rows[] = OUS_TestRunner::assert_false(is_wp_error($result), 'deploy(): succeeds against a mocked 200/200 sequence');
        $rows[] = OUS_TestRunner::assert_same(2, count($captured), 'deploy(): exactly 2 real requests — upload the script, then enable the subdomain');
        $rows[] = OUS_TestRunner::assert_same('PUT', $captured[0]['method'] ?? null, 'deploy(): script upload is a real PUT, matching Cloudflare\'s documented method');
        $rows[] = OUS_TestRunner::assert_true(strpos($captured[0]['url'] ?? '', '/accounts/suite-account/workers/scripts/suite-chat-worker') !== false, 'deploy(): upload URL includes the configured account and script name');
        $rows[] = OUS_TestRunner::assert_true(strpos($captured[0]['headers']['Content-Type'] ?? '', 'multipart/form-data; boundary=') === 0, 'deploy(): upload request declares a real multipart boundary');

        $body = $captured[0]['body'] ?? '';
        $rows[] = OUS_TestRunner::assert_true(strpos($body, 'name="metadata"') !== false, 'deploy(): multipart body includes the metadata part');
        $rows[] = OUS_TestRunner::assert_true(strpos($body, '"main_module":"chat-worker.js"') !== false, 'deploy(): metadata JSON declares the correct main_module');
        $rows[] = OUS_TestRunner::assert_true(strpos($body, '"type":"durable_object_namespace"') !== false, 'deploy(): metadata declares the Durable Object binding');
        $rows[] = OUS_TestRunner::assert_true(strpos($body, '"new_sqlite_classes":["ChatRoom"]') !== false, 'deploy(): metadata declares the migration that creates the ChatRoom class');
        $rows[] = OUS_TestRunner::assert_true(strpos($body, 'export default') !== false && strpos($body, 'export class ChatRoom') !== false, 'deploy(): the actual bundled worker script content is included in the upload');

        $rows[] = OUS_TestRunner::assert_same('POST', $captured[1]['method'] ?? null, 'deploy(): subdomain-enable is a real POST');
        $rows[] = OUS_TestRunner::assert_true(strpos($captured[1]['url'] ?? '', '/subdomain') !== false, 'deploy(): second request targets the subdomain-enable endpoint');

        $rows[] = OUS_TestRunner::assert_true($chat->is_configured(), 'is_configured(): true once deploy() has succeeded');
        $render = $chat->render(77);
        $rows[] = OUS_TestRunner::assert_same('iframe', $render['type'], 'render(): type is iframe');
        $rows[] = OUS_TestRunner::assert_true(strpos($render['html'], 'suite-chat-worker.suitesubdomain.workers.dev/?stream=77') !== false, 'render(): embeds the deployed worker\'s own URL with the stream id as a query param');

        if ($original_cf === false) { delete_option('bhl_cloudflare_settings'); } else { update_option('bhl_cloudflare_settings', $original_cf); }
        if ($original_workers === false) { delete_option('bhl_workers_settings'); } else { update_option('bhl_workers_settings', $original_workers); }
        return $rows;
    }
}
