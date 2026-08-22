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

    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-streaming'] = ['label' => 'BH Streaming', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHS_ISRC')) {
            return [['name' => 'BHS_ISRC not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_isrc_tests());
        $rows = array_merge($rows, self::run_jam_skip_vote_tests());
        $rows = array_merge($rows, self::run_recommendations_tests());
        $rows = array_merge($rows, self::run_chapters_tests());
        $rows = array_merge($rows, self::run_fan_library_tests());
        $rows = array_merge($rows, self::run_booklet_tests());
        return $rows;
    }

    /* ---------- BHS_Booklet: the "CD jacket" bonus content ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_booklet_tests(): array {
        if (!class_exists('BHS_Booklet')) return [];
        $rows = [];

        $fixture_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'draft', 'post_title' => 'BHS Test Suite Booklet Fixture'], true);
        if (is_wp_error($fixture_id)) return $rows;

        // Unhappy/empty: nothing filled in at all.
        $rows[] = OUS_TestRunner::assert_true(!BHS_Booklet::has_any_content($fixture_id), 'has_any_content(): a track with no liner notes/credits/artwork/lyrics-sheet has no bonus content.');
        $rows[] = OUS_TestRunner::assert_same('', BHS_Booklet::ensure_url($fixture_id), 'ensure_url(): returns \'\' rather than generating an empty PDF when there\'s genuinely nothing to include.');

        // Happy path: liner notes alone is enough to produce a real PDF.
        update_post_meta($fixture_id, '_bhs_liner_notes', 'A real test note, written during the test suite run.');
        $rows[] = OUS_TestRunner::assert_true(BHS_Booklet::has_any_content($fixture_id), 'has_any_content(): true once liner notes alone are present.');

        $url = BHS_Booklet::ensure_url($fixture_id);
        $rows[] = OUS_TestRunner::assert_true($url !== '', 'ensure_url(): produces a real, non-empty URL once there\'s real content.');

        $attachment_id = (int) get_post_meta($fixture_id, '_bhs_booklet_attachment_id', true);
        $rows[] = OUS_TestRunner::assert_true($attachment_id > 0, 'ensure_url(): a real WP attachment was created and its id cached.');
        if ($attachment_id) {
            $path = get_attached_file($attachment_id);
            $rows[] = OUS_TestRunner::assert_true((bool) $path && file_exists($path), 'ensure_url(): the cached attachment\'s file genuinely exists on disk.');
            if ($path) {
                $bytes = file_get_contents($path);
                $rows[] = OUS_TestRunner::assert_true($bytes !== false && substr($bytes, 0, 4) === '%PDF', 'ensure_url(): the generated file is a real PDF (starts with the %PDF magic bytes), not corrupted output.');
            }
        }

        // Caching: calling ensure_url() again with NO content change
        // must return the SAME attachment, not regenerate.
        $url_again = BHS_Booklet::ensure_url($fixture_id);
        $rows[] = OUS_TestRunner::assert_same($attachment_id, (int) get_post_meta($fixture_id, '_bhs_booklet_attachment_id', true), 'ensure_url(): calling again with unchanged content reuses the cached attachment rather than regenerating.');
        $rows[] = OUS_TestRunner::assert_same($url, $url_again, 'ensure_url(): the returned URL is stable across repeated calls when content hasn\'t changed.');

        // Regeneration: editing the content (matching what save() does —
        // clearing the cached hash) produces a genuinely NEW attachment,
        // not a stale one still reflecting the old text.
        update_post_meta($fixture_id, '_bhs_liner_notes', 'Updated note — this should trigger real regeneration.');
        delete_post_meta($fixture_id, '_bhs_booklet_content_hash'); // BHS_Booklet::save()'s own real invalidation step
        BHS_Booklet::ensure_url($fixture_id);
        $new_attachment_id = (int) get_post_meta($fixture_id, '_bhs_booklet_attachment_id', true);
        $rows[] = OUS_TestRunner::assert_true($new_attachment_id > 0 && $new_attachment_id !== $attachment_id, 'ensure_url(): editing the content and clearing the hash produces a genuinely new attachment, not the stale cached one.');
        $rows[] = OUS_TestRunner::assert_true(!get_post($attachment_id), 'ensure_url(): the OLD attachment was actually deleted after regeneration, not left as an orphaned file.');

        // Cleanup.
        if ($new_attachment_id) wp_delete_attachment($new_attachment_id, true);
        wp_delete_post($fixture_id, true);

        return $rows;
    }

    /* ---------- BHS_FanLibrary: the fan-facing global-library feature ---------- */

    // New feature (2026-08-21) — the "many plausible happy and unhappy
    // paths and edge cases" standard AJ asked for applied deliberately:
    // a clean add, a duplicate rejected as a real conflict (not a
    // silent no-op or a second row), missing required fields, an
    // oversized field beyond the column's real storage limit, deleting
    // your own item, deleting an item that was never yours (or never
    // existed — the same 404 either way, by design, since a caller
    // can't distinguish the two and shouldn't be able to), and that a
    // second, different user's library stays completely isolated from
    // the first's — the actual point of this being a fan-scoped table.
    /** @return array<int, array<string, mixed>> */
    private static function run_fan_library_tests(): array {
        if (!class_exists('BHS_FanLibrary')) return [];
        $rows = [];
        global $wpdb;
        $table = $wpdb->prefix . 'bhs_fan_library';

        $previous_user_id = get_current_user_id();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $user_a = $admins ? (int) $admins[0] : 0;
        // A second, distinct user id for the isolation test below —
        // doesn't need to be a real registered account, just a
        // different numeric id than $user_a, since the query itself is
        // a plain WHERE user_id = %d with no foreign-key/existence
        // requirement on wp_users.
        $user_b = $user_a + 999900;

        if (!$user_a) {
            wp_set_current_user($previous_user_id);
            return [['name' => 'BHS_FanLibrary tests', 'pass' => false, 'message' => 'Skipped — no administrator account found to run as.']];
        }
        wp_set_current_user($user_a);

        $make_req = function (array $params): \WP_REST_Request {
            $req = new \WP_REST_Request('POST', '/bhs/v1/fan-library');
            foreach ($params as $k => $v) $req->set_param($k, $v);
            return $req;
        };

        // Happy path: a complete, well-formed item.
        $add_ok = BHS_FanLibrary::add_item($make_req([
            'feed_url' => 'https://example.test/ts-feed.xml', 'track_guid' => 'ts-guid-1',
            'title' => 'Test Suite Track', 'artist' => 'Test Suite Artist',
            'audio_url' => 'https://example.test/track.mp3', 'artwork_url' => '', 'duration' => '210',
        ]));
        $rows[] = OUS_TestRunner::assert_true(!is_wp_error($add_ok), 'BHS_FanLibrary::add_item(): a complete, well-formed item is accepted.');
        $added_id = !is_wp_error($add_ok) ? $add_ok->get_data()['id'] : 0;

        // Unhappy: the exact same feed_url + track_guid for the SAME
        // user is a real conflict, not a silent duplicate row.
        $add_dup = BHS_FanLibrary::add_item($make_req([
            'feed_url' => 'https://example.test/ts-feed.xml', 'track_guid' => 'ts-guid-1',
            'title' => 'Test Suite Track', 'artist' => '', 'audio_url' => 'https://example.test/track.mp3',
        ]));
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($add_dup) && $add_dup->get_error_code() === 'already_added', 'BHS_FanLibrary::add_item(): re-adding the same feed_url+track_guid for the same user is rejected as already_added, not duplicated.');

        // Unhappy: missing required fields (no title, no audio_url).
        $add_missing = BHS_FanLibrary::add_item($make_req(['feed_url' => 'https://example.test/other-feed.xml', 'track_guid' => 'ts-guid-2']));
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($add_missing) && $add_missing->get_error_code() === 'missing_fields', 'BHS_FanLibrary::add_item(): rejects an item missing title/audio_url rather than saving a broken row.');

        // Edge case: a feed_url longer than the column's real
        // varchar(191) storage limit — must be rejected with a clear
        // reason, not silently truncated by MySQL or thrown as a raw
        // DB error.
        $long_url = 'https://example.test/' . str_repeat('x', 200) . '/feed.xml';
        $add_long = BHS_FanLibrary::add_item($make_req(['feed_url' => $long_url, 'track_guid' => 'ts-guid-3', 'title' => 'T', 'audio_url' => 'https://example.test/t.mp3']));
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($add_long) && $add_long->get_error_code() === 'too_long', 'BHS_FanLibrary::add_item(): rejects a feed_url longer than the column can store, rather than a silent truncation or raw SQL error.');

        // Isolation: a second user's library must never see the first
        // user's items — the actual point of a fan-SCOPED table.
        wp_set_current_user($user_b);
        $list_req = new \WP_REST_Request('GET', '/bhs/v1/fan-library');
        $list_b = BHS_FanLibrary::get_library($list_req);
        $rows[] = OUS_TestRunner::assert_same(0, count($list_b->get_data()['items']), 'BHS_FanLibrary::get_library(): a different user sees zero of user A\'s items — libraries stay fan-scoped.');
        wp_set_current_user($user_a);

        // Happy path: delete your own real item.
        if ($added_id) {
            $del_req = new \WP_REST_Request('DELETE', '/bhs/v1/fan-library/' . $added_id);
            $del_req->set_param('id', $added_id);
            $del_ok = BHS_FanLibrary::remove_item($del_req);
            $rows[] = OUS_TestRunner::assert_true(!is_wp_error($del_ok), 'BHS_FanLibrary::remove_item(): deleting your own real item succeeds.');
        }

        // Unhappy/edge: deleting an id that never existed (or isn't
        // yours — indistinguishable by design, see this method's own
        // comment) returns a real not_found, not a silent success.
        $del_missing_req = new \WP_REST_Request('DELETE', '/bhs/v1/fan-library/999999999');
        $del_missing_req->set_param('id', 999999999);
        $del_missing = BHS_FanLibrary::remove_item($del_missing_req);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($del_missing) && $del_missing->get_error_code() === 'not_found', 'BHS_FanLibrary::remove_item(): deleting a nonexistent id returns not_found rather than a silent success.');

        // Cleanup — real DB rows this test suite itself created, same
        // "leave no residue for the next suite run" discipline as
        // every other method in this file.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE user_id IN (%d, %d)", $user_a, $user_b));
        wp_set_current_user($previous_user_id);
        return $rows;
    }

    /* ---------- BHS_Chapters: line parsing, sorting, resume position ---------- */

    // Audit fix (2026-07-26): BHS_Chapters (long-form audio, ROADMAP-
    // streaming-media-scope-and-blockchain.md Part 1 Phase 1) had zero
    // test coverage since it shipped — live-verified in a browser this
    // pass (real track, real chapter lines, real player), but the pure
    // parsing/sorting logic deserves the same DB-backed real-fixture
    // coverage every other suite in this file already has.
    /** @return array<int, array<string, mixed>> */
    private static function run_chapters_tests(): array {
        if (!class_exists('BHS_Chapters')) return [];
        $rows = [];

        $fixture_id = wp_insert_post(['post_type' => 'bhs_track', 'post_status' => 'draft', 'post_title' => 'BHS Test Suite Chapters Fixture'], true);
        if (is_wp_error($fixture_id)) return $rows;

        // save()'s current_user_can('edit_post', $post_id) gate needs a
        // real capable user in context — this CLI test-runner request
        // has no logged-in user by default, unlike a real admin-screen
        // save. Swapped in only for the duration of this test and
        // restored after, same "don't leave global state changed for
        // suites that run after this one" discipline as everywhere else
        // real WP state gets touched in this file.
        $previous_user_id = get_current_user_id();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if ($admins) wp_set_current_user($admins[0]);

        // save() is normally reached via save_post_bhs_track + a real
        // nonce/capability check — the nonce is real (still required),
        // only the auth CONTEXT is seeded above, since the pure parsing/
        // sorting logic is what's under test, not the save-hook's own
        // gate (already the same shape everywhere else in this
        // ecosystem: real DB-backed fixtures, no HTTP round-trip needed
        // to exercise the actual method).
        $_POST['bhs_chapters_raw'] = "00:05 Chapter Two (out of order)\n00:00 Introduction\nnotachapterline\n00:03 Chapter One (out of order too)";
        $_POST['bhs_chapters_nonce'] = wp_create_nonce('bhs_save_chapters');
        BHS_Chapters::save($fixture_id);
        unset($_POST['bhs_chapters_raw'], $_POST['bhs_chapters_nonce']);

        $chapters = BHS_Chapters::get($fixture_id);
        $rows[] = OUS_TestRunner::assert_same(3, count($chapters), 'save(): 3 valid lines saved, the malformed "notachapterline" line silently skipped rather than rejecting the whole save');
        $rows[] = OUS_TestRunner::assert_same(
            [0, 3, 5], array_column($chapters, 'time'),
            'save(): chapters are sorted by time regardless of the order they were entered in (input was 5, 0, [invalid], 3)'
        );
        $rows[] = OUS_TestRunner::assert_same('Introduction', $chapters[0]['label'] ?? null, 'save(): the 0:00 line\'s label parsed correctly');

        // Malformed-only input: every line invalid should leave a clean
        // empty array, not a partial/corrupt one.
        $_POST['bhs_chapters_raw'] = "not a chapter\nalso not one";
        $_POST['bhs_chapters_nonce'] = wp_create_nonce('bhs_save_chapters');
        BHS_Chapters::save($fixture_id);
        unset($_POST['bhs_chapters_raw'], $_POST['bhs_chapters_nonce']);
        $rows[] = OUS_TestRunner::assert_same([], BHS_Chapters::get($fixture_id), 'save(): an input with zero valid lines saves a clean empty array, not a partial/corrupt one');

        // resume_position(): per-track, per-user read/write round-trip.
        $test_user_id = class_exists('OUS_Debug') ? OUS_Debug::get_or_create_test_user('bhs_chapters_test') : get_current_user_id();
        update_user_meta($test_user_id, '_bhs_resume_' . $fixture_id, 137);
        $rows[] = OUS_TestRunner::assert_same(137, BHS_Chapters::resume_position($fixture_id, $test_user_id), 'resume_position(): reads back exactly what was written for this track/user pair');
        $rows[] = OUS_TestRunner::assert_same(0, BHS_Chapters::resume_position($fixture_id, 999999), 'resume_position(): a user with no saved position at all reads back 0, not a notice/null');
        delete_user_meta($test_user_id, '_bhs_resume_' . $fixture_id);

        wp_set_current_user($previous_user_id);
        wp_delete_post($fixture_id, true);
        return $rows;
    }

    /* ---------- BHS_ISRC ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_isrc_tests(): array {
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

    /** @return array<int, array<string, mixed>> */
    private static function run_jam_skip_vote_tests(): array {
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

    /** @return array<int, array<string, mixed>> */
    private static function run_recommendations_tests(): array {
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
