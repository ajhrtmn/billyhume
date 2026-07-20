<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-streaming — same convention as the rest
 * of the ecosystem's own class-test-suite.php files. This plugin had
 * ZERO test coverage before this pass. Covers BHS_ISRC's placeholder-
 * pattern/issuance logic, BHS_Jam's skip-vote threshold math (real
 * branching logic on a real DB-backed participant count), and
 * BHS_Recommendations' content-based scoring (artist/release/genre
 * weights) against real fixture tracks.
 */
class BHS_TestSuite {
    const SEED_TAG = 'bhs_test_suite';

    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-streaming'] = ['label' => 'BH Streaming', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHS_ISRC')) {
            return [['name' => 'BHS_ISRC not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_isrc_tests());
        $rows = array_merge($rows, self::run_jam_skip_vote_tests());
        $rows = array_merge($rows, self::run_recommendations_tests());
        return $rows;
    }

    /* ---------- BHS_ISRC ---------- */

    private static function run_isrc_tests() {
        $rows = [];

        $rows[] = OUS_TestRunner::assert_true(
            BHS_ISRC::is_mock('ZZOUS2401234'),
            'is_mock(): a correctly-shaped placeholder ISRC (ZZ + OUS + 2-digit year + 5-digit sequence) is recognized as mock'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHS_ISRC::is_mock('USRC17607839'),
            'is_mock(): a real-shaped ISRC (not starting ZZOUS) is correctly NOT flagged as mock — this is the suppression check real issued codes must pass through cleanly'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHS_ISRC::is_mock('ZZOUS240123'),
            'is_mock(): one digit short of the real pattern (6 digits instead of 7 after the year) correctly fails — a loose regex here would misclassify malformed codes as valid placeholders'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHS_ISRC::is_mock(''),
            'is_mock(): an empty string is correctly not mock'
        );

        // issue()'s mock path — no real registrant configured, so this
        // must always produce a MOCK_PATTERN-shaped code, and must never
        // collide with an existing _bhs_audio_isrc... err, _bhs_isrc
        // postmeta value already in use.
        delete_option('bhs_isrc_registrant'); // ensure no real registrant is configured for this test
        $issued = BHS_ISRC::issue();
        $rows[] = OUS_TestRunner::assert_true(
            BHS_ISRC::is_mock($issued),
            'issue(): with no real registrant configured, issues a correctly-shaped mock/placeholder code'
        );

        // Collision avoidance: seed a real postmeta row using the exact
        // pattern issue() would generate for "this year", then confirm
        // issue() doesn't hand back that same value again.
        global $wpdb;
        $fixture_post_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'draft', 'post_title' => 'BHS Test Suite Fixture Track'], true);
        if (!is_wp_error($fixture_post_id)) {
            $seeded = 'ZZOUS' . gmdate('y') . '00001';
            update_post_meta($fixture_post_id, '_bhs_isrc', $seeded);
            // 30 fresh issues should never happen to collide with the one
            // seeded value if collision-checking is actually working — a
            // regression that dropped the existence check would
            // eventually (rarely, but non-zero probability) return it.
            $collided = false;
            for ($i = 0; $i < 30; $i++) {
                if (BHS_ISRC::issue() === $seeded) { $collided = true; break; }
            }
            $rows[] = OUS_TestRunner::assert_false($collided, 'issue(): never re-issues a mock code that already exists in postmeta (collision-checked, not trusted to random_int() alone)');
            wp_delete_post($fixture_post_id, true);
        }

        return $rows;
    }

    /* ---------- BHS_Jam skip-vote threshold ---------- */

    private static function run_jam_skip_vote_tests() {
        if (!class_exists('BHS_Jam')) return [];
        $rows = [];
        global $wpdb;
        $sessions_t = $wpdb->prefix . 'bhs_jam_sessions';
        $participants_t = $wpdb->prefix . 'bhs_jam_participants';

        $needed = new ReflectionMethod('BHS_Jam', 'skip_votes_needed');

        // 0 participants (a session that's just been created, or a race
        // where the host row failed to insert — see the audit fix in
        // create()) must still require at least 1 vote, never 0 — a 0
        // threshold would mean "already skipped" the instant anyone
        // calls vote_skip(), or worse, count as satisfied with no real
        // votes at all.
        $wpdb->insert($sessions_t, ['invite_code' => 'TSTZERO', 'host_user_id' => 999901, 'control_mode' => 'vote_skip', 'state_json' => '{}', 'status' => 'active']);
        $session_id_zero = (int) $wpdb->insert_id;
        $rows[] = OUS_TestRunner::assert_same(1, $needed->invoke(null, $session_id_zero), 'skip_votes_needed(): 0 real participants still requires at least 1 vote (max(1, ...) floor), never 0');

        // 1 participant — ceil(1 * 0.5) = 1, still floored to 1 either way.
        $wpdb->insert($sessions_t, ['invite_code' => 'TSTONE', 'host_user_id' => 999902, 'control_mode' => 'vote_skip', 'state_json' => '{}', 'status' => 'active']);
        $session_id_one = (int) $wpdb->insert_id;
        $wpdb->insert($participants_t, ['session_id' => $session_id_one, 'user_id' => 999902, 'display_name' => 'Test Host']);
        $rows[] = OUS_TestRunner::assert_same(1, $needed->invoke(null, $session_id_one), 'skip_votes_needed(): 1 participant needs 1 vote (ceil(1 * 0.5) = 1)');

        // 3 participants — ceil(3 * 0.5) = 2, a real majority (not 1,
        // not 3) — this is the exact case that would catch a wrong
        // rounding direction (floor vs. ceil) regression.
        $wpdb->insert($sessions_t, ['invite_code' => 'TSTTHREE', 'host_user_id' => 999903, 'control_mode' => 'vote_skip', 'state_json' => '{}', 'status' => 'active']);
        $session_id_three = (int) $wpdb->insert_id;
        foreach ([999903, 999904, 999905] as $uid) {
            $wpdb->insert($participants_t, ['session_id' => $session_id_three, 'user_id' => $uid, 'display_name' => 'Test User']);
        }
        $rows[] = OUS_TestRunner::assert_same(2, $needed->invoke(null, $session_id_three), 'skip_votes_needed(): 3 participants needs 2 votes (ceil(3 * 0.5) = 2, catches a floor-vs-ceil rounding regression)');

        // 4 participants — ceil(4 * 0.5) = 2 exactly, no rounding needed.
        $wpdb->insert($sessions_t, ['invite_code' => 'TSTFOUR', 'host_user_id' => 999906, 'control_mode' => 'vote_skip', 'state_json' => '{}', 'status' => 'active']);
        $session_id_four = (int) $wpdb->insert_id;
        foreach ([999906, 999907, 999908, 999909] as $uid) {
            $wpdb->insert($participants_t, ['session_id' => $session_id_four, 'user_id' => $uid, 'display_name' => 'Test User']);
        }
        $rows[] = OUS_TestRunner::assert_same(2, $needed->invoke(null, $session_id_four), 'skip_votes_needed(): 4 participants needs exactly 2 votes (ceil(4 * 0.5) = 2)');

        // Cleanup.
        foreach ([$session_id_zero, $session_id_one, $session_id_three, $session_id_four] as $sid) {
            $wpdb->delete($participants_t, ['session_id' => $sid]);
            $wpdb->delete($sessions_t, ['id' => $sid]);
        }

        return $rows;
    }

    /* ---------- BHS_Recommendations content-based scoring ---------- */

    private static function run_recommendations_tests() {
        if (!class_exists('BHS_Recommendations')) return [];
        $rows = [];

        // Real fixture tracks: a seed track, a same-artist-and-release
        // track (should score highest: 3 + 4 = 7), a same-artist-only
        // track (score 3), and an unrelated track (score 0, excluded
        // entirely — get_related() only returns tracks scoring > 0).
        $seed_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'Seed Track'], true);
        $same_both_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'Same Artist and Release'], true);
        $same_artist_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'Same Artist Only'], true);
        $unrelated_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'publish', 'post_title' => 'Unrelated Track'], true);

        if (is_wp_error($seed_id) || is_wp_error($same_both_id) || is_wp_error($same_artist_id) || is_wp_error($unrelated_id)) {
            return [['name' => 'BHS_TestSuite recommendations fixture creation failed', 'pass' => false, 'message' => '']];
        }

        // A dummy _bhs_external_audio_url on every candidate — track_payload()
        // (called inside get_related()) skips any unlocked track with NO
        // resolvable audio URL at all, and these fixtures have no real
        // attachment; a fake external URL is enough to make audio_url_for()
        // resolve to something non-empty without needing a real upload.
        foreach ([$seed_id, $same_both_id, $same_artist_id, $unrelated_id] as $id) {
            update_post_meta($id, '_bhs_external_audio_url', 'https://example.test/' . $id . '.mp3');
        }

        update_post_meta($seed_id, '_bhs_artist', 'Test Artist A');
        update_post_meta($seed_id, '_bhs_release_id', 12345);
        update_post_meta($same_both_id, '_bhs_artist', 'Test Artist A');
        update_post_meta($same_both_id, '_bhs_release_id', 12345);
        update_post_meta($same_artist_id, '_bhs_artist', 'Test Artist A');
        update_post_meta($same_artist_id, '_bhs_release_id', 99999); // different release
        update_post_meta($unrelated_id, '_bhs_artist', 'Totally Different Artist');
        update_post_meta($unrelated_id, '_bhs_release_id', 88888);

        $req = new WP_REST_Request('GET', '/bhs/v1/tracks/' . $seed_id . '/related');
        $req->set_param('id', $seed_id);
        $response = BHS_Recommendations::get_related($req);
        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        $ids = wp_list_pluck($data['related'] ?? [], 'id');

        $rows[] = OUS_TestRunner::assert_true(
            !is_wp_error($response),
            'get_related(): a real seed track does not return a WP_Error'
        );
        $rows[] = OUS_TestRunner::assert_true(
            in_array($same_both_id, $ids, true) && array_search($same_both_id, $ids, true) < array_search($same_artist_id, $ids, true),
            'get_related(): a track sharing BOTH artist and release (score 7) ranks strictly above one sharing only artist (score 3) — catches an artist/release weight or arsort() regression'
        );
        $rows[] = OUS_TestRunner::assert_false(
            in_array($unrelated_id, $ids, true),
            'get_related(): a track sharing nothing (score 0) is correctly excluded entirely, not just ranked last'
        );

        // Cleanup.
        foreach ([$seed_id, $same_both_id, $same_artist_id, $unrelated_id] as $id) {
            wp_delete_post($id, true);
        }

        return $rows;
    }
}
