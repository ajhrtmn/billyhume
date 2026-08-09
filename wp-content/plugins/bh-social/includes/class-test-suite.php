<?php
if (!defined('ABSPATH')) exit;

class BHSO_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-social'] = ['label' => 'BH Social', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHSO_YouTube')) {
            return [['name' => 'BHSO_YouTube not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }

        $rows = [];
        $rows = array_merge($rows, self::run_youtube_settings_tests());
        $rows = array_merge($rows, self::run_twitch_settings_tests());
        $rows = array_merge($rows, self::run_meta_settings_tests());
        $rows = array_merge($rows, self::run_tiktok_settings_tests());
        $rows = array_merge($rows, self::run_platform_registry_tests());
        $rows = array_merge($rows, self::run_ads_platform_tests());
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_youtube_settings_tests(): array {
        $rows = [];
        $original = get_option('bhso_youtube_settings');

        BHSO_YouTube::save_credentials('test-client-id', 'test-client-secret');
        $s = BHSO_YouTube::settings();
        $rows[] = OUS_TestRunner::assert_true($s['client_id'] === 'test-client-id', 'BHSO_YouTube::save_credentials(): client_id round-trips through wp_options.');
        $rows[] = OUS_TestRunner::assert_true($s['client_secret'] === 'test-client-secret', 'BHSO_YouTube::save_credentials(): client_secret round-trips on first save.');

        // Blank secret on a second save must keep the existing value —
        // the same convention BHL_FlyProvisioner::save_settings() uses.
        BHSO_YouTube::save_credentials('test-client-id-2', '');
        $s2 = BHSO_YouTube::settings();
        $rows[] = OUS_TestRunner::assert_true($s2['client_secret'] === 'test-client-secret', 'BHSO_YouTube::save_credentials(): blank client_secret on re-save keeps the existing stored secret rather than clearing it.');

        $yt = new BHSO_YouTube();
        $rows[] = OUS_TestRunner::assert_true($yt->is_configured() === true, 'BHSO_YouTube::is_configured(): true once client_id + client_secret are both set.');
        $rows[] = OUS_TestRunner::assert_true($yt->is_connected() === false, 'BHSO_YouTube::is_connected(): false with no refresh_token stored — never connected.');
        $rows[] = OUS_TestRunner::assert_true($yt->get_status() === 'not_connected', 'BHSO_YouTube::get_status(): "not_connected" when configured but no tokens exist.');

        // Restore whatever was there before this test ran, so a real
        // site's real saved credentials aren't clobbered by running
        // the test suite.
        if ($original === false) {
            delete_option('bhso_youtube_settings');
        } else {
            update_option('bhso_youtube_settings', $original);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_twitch_settings_tests(): array {
        $rows = [];
        $original = get_option('bhso_twitch_settings');

        BHSO_Twitch::save_credentials('tw-client-id', 'tw-client-secret');
        $s = BHSO_Twitch::settings();
        $rows[] = OUS_TestRunner::assert_true($s['client_id'] === 'tw-client-id', 'BHSO_Twitch::save_credentials(): client_id round-trips through wp_options.');
        $rows[] = OUS_TestRunner::assert_true($s['client_secret'] === 'tw-client-secret', 'BHSO_Twitch::save_credentials(): client_secret round-trips on first save.');

        BHSO_Twitch::save_credentials('tw-client-id-2', '');
        $s2 = BHSO_Twitch::settings();
        $rows[] = OUS_TestRunner::assert_true($s2['client_secret'] === 'tw-client-secret', 'BHSO_Twitch::save_credentials(): blank client_secret on re-save keeps the existing stored secret.');

        $tw = new BHSO_Twitch();
        $rows[] = OUS_TestRunner::assert_true($tw->is_configured() === true, 'BHSO_Twitch::is_configured(): true once client_id + client_secret are both set.');
        $rows[] = OUS_TestRunner::assert_true($tw->get_status() === 'not_connected', 'BHSO_Twitch::get_status(): "not_connected" when configured but no tokens exist.');

        $empty_post = $tw->cross_post(['message' => '']);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($empty_post), 'BHSO_Twitch::cross_post(): rejects an empty message rather than sending a blank announcement.');

        if ($original === false) {
            delete_option('bhso_twitch_settings');
        } else {
            update_option('bhso_twitch_settings', $original);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_meta_settings_tests(): array {
        $rows = [];
        $original = get_option('bhso_meta_settings');

        BHSO_Meta::save_credentials('meta-app-id', 'meta-app-secret');
        $s = BHSO_Meta::settings();
        $rows[] = OUS_TestRunner::assert_true($s['app_id'] === 'meta-app-id', 'BHSO_Meta::save_credentials(): app_id round-trips through wp_options.');
        $rows[] = OUS_TestRunner::assert_true($s['app_secret'] === 'meta-app-secret', 'BHSO_Meta::save_credentials(): app_secret round-trips on first save.');

        BHSO_Meta::save_credentials('meta-app-id-2', '');
        $s2 = BHSO_Meta::settings();
        $rows[] = OUS_TestRunner::assert_true($s2['app_secret'] === 'meta-app-secret', 'BHSO_Meta::save_credentials(): blank app_secret on re-save keeps the existing stored secret.');

        $meta = new BHSO_Meta();
        $rows[] = OUS_TestRunner::assert_true($meta->is_configured() === true, 'BHSO_Meta::is_configured(): true once app_id + app_secret are both set.');
        $rows[] = OUS_TestRunner::assert_true($meta->get_status() === 'not_connected', 'BHSO_Meta::get_status(): "not_connected" when configured but no page/IG connection exists.');

        $no_attachment = $meta->cross_post(['attachment_id' => 0]);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($no_attachment), 'BHSO_Meta::cross_post(): rejects a request with no attachment_id.');

        if ($original === false) {
            delete_option('bhso_meta_settings');
        } else {
            update_option('bhso_meta_settings', $original);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_tiktok_settings_tests(): array {
        $rows = [];
        $original = get_option('bhso_tiktok_settings');

        BHSO_TikTok::save_credentials('tt-client-key', 'tt-client-secret');
        $s = BHSO_TikTok::settings();
        $rows[] = OUS_TestRunner::assert_true($s['client_key'] === 'tt-client-key', 'BHSO_TikTok::save_credentials(): client_key round-trips through wp_options.');
        $rows[] = OUS_TestRunner::assert_true($s['client_secret'] === 'tt-client-secret', 'BHSO_TikTok::save_credentials(): client_secret round-trips on first save.');

        BHSO_TikTok::save_credentials('tt-client-key-2', '');
        $s2 = BHSO_TikTok::settings();
        $rows[] = OUS_TestRunner::assert_true($s2['client_secret'] === 'tt-client-secret', 'BHSO_TikTok::save_credentials(): blank client_secret on re-save keeps the existing stored secret.');

        $tt = new BHSO_TikTok();
        $rows[] = OUS_TestRunner::assert_true($tt->is_configured() === true, 'BHSO_TikTok::is_configured(): true once client_key + client_secret are both set.');
        $rows[] = OUS_TestRunner::assert_true($tt->get_status() === 'not_connected', 'BHSO_TikTok::get_status(): "not_connected" when configured but no tokens exist.');

        $no_file = $tt->cross_post(['attachment_id' => 0]);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($no_file), 'BHSO_TikTok::cross_post(): rejects a request with no real attachment file.');

        $https_only = BHSO_TikTok::authorize_url();
        // On this dev install admin_url() resolves to http://localhost —
        // authorize_url() must refuse rather than silently generating a
        // redirect_uri TikTok will reject at connect time.
        $rows[] = OUS_TestRunner::assert_true(
            is_wp_error($https_only) || strpos(admin_url(), 'https://') === 0,
            'BHSO_TikTok::authorize_url(): refuses to build an auth URL when the redirect_uri isn\'t HTTPS.'
        );

        if ($original === false) {
            delete_option('bhso_tiktok_settings');
        } else {
            update_option('bhso_tiktok_settings', $original);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_platform_registry_tests(): array {
        $rows = [];
        foreach (['youtube', 'twitch', 'meta', 'tiktok'] as $key) {
            $rows[] = OUS_TestRunner::assert_true(
                BHSO_PlatformRegistry::get($key) instanceof BH_SocialPlatform,
                'BHSO_PlatformRegistry::get("' . $key . '"): returns a real BH_SocialPlatform implementation.'
            );
        }
        $rows[] = OUS_TestRunner::assert_true(
            BHSO_PlatformRegistry::get('not-a-real-platform') === null,
            'BHSO_PlatformRegistry::get(): unknown platform key returns null rather than fatal-erroring.'
        );
        $all = BHSO_PlatformRegistry::all();
        $rows[] = OUS_TestRunner::assert_true(
            isset($all['youtube'], $all['twitch'], $all['meta'], $all['tiktok']),
            'BHSO_PlatformRegistry::all(): includes all four organic platform keys.'
        );
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function run_ads_platform_tests(): array {
        $rows = [];

        foreach (['roku', 'spotify', 'amazon_dsp', 'samsung', 'vizio'] as $key) {
            $rows[] = OUS_TestRunner::assert_true(
                BHSO_AdsPlatformRegistry::get($key) instanceof BH_AdsPlatform,
                'BHSO_AdsPlatformRegistry::get("' . $key . '"): returns a real BH_AdsPlatform implementation.'
            );
        }
        $rows[] = OUS_TestRunner::assert_true(
            BHSO_AdsPlatformRegistry::get('not-a-real-platform') === null,
            'BHSO_AdsPlatformRegistry::get(): unknown platform key returns null rather than fatal-erroring.'
        );

        // Real insert/list/delete round-trip against the live
        // bhso_ad_campaigns table, tagged with a distinctive test name
        // so cleanup can't accidentally touch a real draft.
        $roku = new BHSO_RokuAds();
        $test_name = 'BHSO_TestSuite fixture ' . wp_generate_password(8, false);

        $below_min = $roku->save_campaign_draft(['name' => $test_name, 'budget_dollars' => 100]);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($below_min), 'BHSO_RokuAds::save_campaign_draft(): rejects a budget below Roku\'s own documented $500 minimum.');

        $draft_id = $roku->save_campaign_draft(['name' => $test_name, 'budget_dollars' => 1000, 'start_date' => '2026-09-01']);
        $rows[] = OUS_TestRunner::assert_true(is_int($draft_id) && $draft_id > 0, 'BHSO_RokuAds::save_campaign_draft(): a valid draft (at/above minimum) saves and returns a real row id.');

        $drafts = $roku->list_campaign_drafts();
        $found = false;
        foreach ($drafts as $d) if ($d['name'] === $test_name) $found = true;
        $rows[] = OUS_TestRunner::assert_true($found, 'BHSO_RokuAds::list_campaign_drafts(): the just-saved draft appears in the list.');

        if (is_int($draft_id)) {
            $deleted = $roku->delete_campaign_draft($draft_id);
            $rows[] = OUS_TestRunner::assert_true($deleted === true, 'BHSO_RokuAds::delete_campaign_draft(): removes the test fixture, leaving no residue.');
        }

        $spotify = new BHSO_SpotifyAds();
        $spotify_below_min = $spotify->save_campaign_draft(['name' => $test_name, 'budget_dollars' => 100]);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($spotify_below_min), 'BHSO_SpotifyAds::save_campaign_draft(): rejects a budget below Spotify Ad Studio\'s own documented $250 minimum.');

        // Amazon DSP has no hard minimum — a low budget should still
        // SAVE (unlike Roku/Spotify's hard rejection), just with a
        // soft warning surfaced via WP_Error's error data.
        $amazon = new BHSO_AmazonDSP();
        $amazon_result = $amazon->save_campaign_draft(['name' => $test_name, 'budget_dollars' => 100]);
        $amazon_data = is_wp_error($amazon_result) ? $amazon_result->get_error_data() : null;
        $amazon_draft_id = is_wp_error($amazon_result) ? ($amazon_data['draft_id'] ?? null) : $amazon_result;
        $rows[] = OUS_TestRunner::assert_true(
            is_wp_error($amazon_result) && !empty($amazon_data['draft_id']),
            'BHSO_AmazonDSP::save_campaign_draft(): a below-practical-minimum budget still SAVES (no hard platform minimum exists) but surfaces a soft warning.'
        );
        if ($amazon_draft_id) $amazon->delete_campaign_draft($amazon_draft_id);

        return $rows;
    }
}
