<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see own-ur-shit's class-test-runner.php) version of
 * this plugin's own test coverage — same convention bh-courses'
 * class-test-suite.php and bh-monetization-woo's class-test-suite.php
 * already established. Closes a real, flagged gap: this plugin had
 * ZERO automated test coverage despite real, non-trivial logic (round
 * eligibility/elimination, judge-score normalization, medal-reveal
 * sequencing) — the largest test-coverage gap in the ecosystem per an
 * ecosystem-wide DRY/SOLID/test-quality audit.
 *
 * Two shapes of test here, matching what's actually testable:
 * - BH_Reveal::medal_tier_count()/medal_slice() are genuinely pure
 *   (array in, array out, no DB/WP calls) but private — reached via
 *   reflection, same "test the real internals, not a public-API-only
 *   copy" posture bh-monetization-woo's own test suite already uses
 *   for grant_entitlement().
 * - BH_Rounds::is_eligible()/advance_round() and BH_Judging::
 *   save_score()/judge_results() are DB-coupled by nature (real
 *   postmeta reads, a real custom table) — exercised against real,
 *   tagged fixture bh_contest/bh_submission posts, cleaned up
 *   afterward, same convention bh-courses' run_gate_drip_tests()/
 *   run_review_tests() already established.
 */
class BH_TestSuite {
    const SEED_TAG = 'bh_test_suite';

    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-contest'] = ['label' => 'BH Contest', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner')) return [];
        $rows = [];

        if (class_exists('BH_Reveal')) {
            $rows = array_merge($rows, self::run_medal_tests());
        }
        if (class_exists('BH_Judging')) {
            $rows = array_merge($rows, self::run_judging_tests());
        }
        if (class_exists('BH_Rounds')) {
            $rows = array_merge($rows, self::run_rounds_tests());
        }

        return $rows;
    }

    /* ---------- BH_Reveal::medal_tier_count()/medal_slice() — pure logic, via reflection ---------- */

    private static function run_medal_tests() {
        $rows = [];
        $tier_count = new ReflectionMethod('BH_Reveal', 'medal_tier_count');
        $tier_count->setAccessible(true);
        $slice = new ReflectionMethod('BH_Reveal', 'medal_slice');
        $slice->setAccessible(true);

        $r = function ($rank, $id) { return ['rank' => $rank, 'id' => $id, 'title' => 't' . $id, 'artist' => 'a' . $id, 'votes' => 0]; };

        // No ties: 3 distinct medal tiers.
        $clean = [$r(1, 1), $r(2, 2), $r(3, 3), $r(4, 4)];
        $rows[] = OUS_TestRunner::assert_same(3, $tier_count->invoke(null, $clean), 'No ties: 1st/2nd/3rd are 3 distinct medal tiers');

        // A 2-way tie for 2nd is still ONE tier, not two — so only 2 distinct tiers total (1st, tied-2nd).
        $tied_second = [$r(1, 1), $r(2, 2), $r(2, 3), $r(4, 4)];
        $rows[] = OUS_TestRunner::assert_same(2, $tier_count->invoke(null, $tied_second), 'A tie for 2nd collapses to one tier — 2 distinct medal tiers, not 3');

        // Fewer than 3 entries ranked at all.
        $only_one = [$r(1, 1)];
        $rows[] = OUS_TestRunner::assert_same(1, $tier_count->invoke(null, $only_one), 'Only one ranked entry is only one medal tier');

        // medal_slice() reveals worst-to-best: reveal_count=1 on the clean
        // set should show ONLY 3rd place, and report tier 3 as just-revealed.
        [$entries, $just] = $slice->invoke(null, $clean, 1);
        $rows[] = OUS_TestRunner::assert_same(3, $just, 'First reveal step (reveal_count=1) surfaces the 3rd-place tier (worst-to-best)');
        $rows[] = OUS_TestRunner::assert_same([3], array_column($entries, 'rank'), 'First reveal step shows ONLY the 3rd-place entry, nothing better yet');

        // reveal_count=3 (all tiers revealed) should show all three medal
        // entries and report tier 1 (the winner) as just-revealed.
        [$entries_full, $just_full] = $slice->invoke(null, $clean, 3);
        $rows[] = OUS_TestRunner::assert_same(1, $just_full, 'Final reveal step surfaces the 1st-place (winner) tier');
        $rows[] = OUS_TestRunner::assert_same([1, 2, 3], array_column($entries_full, 'rank'), 'Final reveal step shows all three medal ranks');

        // tied_second's medal ranks are only {1, 2} (rank 4 doesn't
        // medal) — 2 distinct tiers total, worst (rank 2, the tied pair)
        // first. reveal_count=1 (the FIRST reveal step) must surface
        // BOTH tied rank-2 entries together, not just one of them.
        [$tie_entries, $tie_just] = $slice->invoke(null, $tied_second, 1);
        $rows[] = OUS_TestRunner::assert_same(2, $tie_just, 'The first reveal step of a tied set reports rank 2 (the worst/tied tier) as just-revealed');
        $rows[] = OUS_TestRunner::assert_same(2, count($tie_entries), 'A 2-way tie for a rank reveals BOTH tied entries together on the same step, not just one');

        // reveal_count clamps — asking for more tiers than exist must not
        // error or go out of bounds.
        [, $clamped_just] = $slice->invoke(null, $only_one, 5);
        $rows[] = OUS_TestRunner::assert_same(1, $clamped_just, 'reveal_count beyond the total tier count clamps to the last (best) tier, does not error');

        return $rows;
    }

    /* ---------- BH_Judging::save_score()/judge_results() — real DB fixtures ---------- */

    private static function run_judging_tests() {
        $rows = [];
        global $wpdb;
        $table = $wpdb->prefix . 'bh_judge_scores';

        $cid = wp_insert_post([
            'post_type' => 'bh_contest', 'post_status' => 'publish', 'post_title' => 'Judging Test Fixture Contest',
            'meta_input' => ['bhcore_is_test' => self::SEED_TAG],
        ], true);
        $sub1 = wp_insert_post([
            'post_type' => 'bh_submission', 'post_status' => 'publish', 'post_title' => 'Fixture Sub One',
            'meta_input' => ['bhcore_is_test' => self::SEED_TAG, '_bh_contest_id' => $cid],
        ], true);
        $sub2 = wp_insert_post([
            'post_type' => 'bh_submission', 'post_status' => 'publish', 'post_title' => 'Fixture Sub Two',
            'meta_input' => ['bhcore_is_test' => self::SEED_TAG, '_bh_contest_id' => $cid],
        ], true);
        if (is_wp_error($cid) || is_wp_error($sub1) || is_wp_error($sub2)) {
            return [['name' => 'Judging test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture contest/submission posts — skipping judging tests.']];
        }

        // A 10-point and a 20-point criterion, deliberately different
        // maxes — this is exactly the "5-criterion and 3-criterion rubric
        // land on the same 0-100 footing" case the plugin's own docblock
        // describes.
        $rubric = [
            ['slug' => 'originality', 'name' => 'Originality', 'max' => 10],
            ['slug' => 'production', 'name' => 'Production', 'max' => 20],
        ];
        update_post_meta($cid, '_bh_rubric', wp_json_encode($rubric));

        $judge_a = 555001;
        $judge_b = 555002;
        $judge_c_draft = 555003;

        // Judge A on sub1: originality 10/10 (100%), production 10/20 (50%) -> avg 75%.
        BH_Judging::save_score($judge_a, $cid, $sub1, '', ['originality' => 10, 'production' => 10], 'submitted');
        // Judge B on sub1: originality 5/10 (50%), production 20/20 (100%) -> avg 75%.
        BH_Judging::save_score($judge_b, $cid, $sub1, '', ['originality' => 5, 'production' => 20], 'submitted');
        // Judge C on sub1: a DRAFT, high scores — must NOT count toward sub1's average.
        BH_Judging::save_score($judge_c_draft, $cid, $sub1, '', ['originality' => 10, 'production' => 20], 'draft');
        // Judge A on sub2: zero on both criteria.
        BH_Judging::save_score($judge_a, $cid, $sub2, '', ['originality' => 0, 'production' => 0], 'submitted');
        // Judge A tries to save an OUT-OF-RANGE score on sub2 (production
        // way over its max of 20, originality negative) — save_score()
        // must clamp server-side, not trust the caller.
        BH_Judging::save_score($judge_a, $cid, $sub2, '', ['originality' => -50, 'production' => 999], 'submitted');

        $clamped = BH_Judging::judge_status($judge_a, $cid, $sub2, '');
        $rows[] = OUS_TestRunner::assert_same(0, $clamped['scores']['originality'] ?? null, 'save_score() clamps a negative score to 0, never stores it raw');
        $rows[] = OUS_TestRunner::assert_same(20, $clamped['scores']['production'] ?? null, 'save_score() clamps a score above the criterion max down to that max');

        $results = BH_Judging::judge_results($cid, '');
        $by_id = [];
        foreach ($results as $r) $by_id[$r['id']] = $r;

        $rows[] = OUS_TestRunner::assert_true(isset($by_id[$sub1]), 'judge_results() includes a submission with real submitted scores');
        $rows[] = OUS_TestRunner::assert_same(75.0, $by_id[$sub1]['votes'] ?? null, 'Two judges both averaging 75% across differently-weighted criteria correctly average to exactly 75.0 (not skewed by the draft judge)');
        $rows[] = OUS_TestRunner::assert_same(1, $by_id[$sub1]['rank'] ?? null, 'The higher-scored submission ranks #1');
        $rows[] = OUS_TestRunner::assert_same(2, $by_id[$sub2]['rank'] ?? null, 'The lower (clamped-to-zero) submission ranks #2, not tied or omitted');

        // A submission with only a DRAFT (no submitted score at all) must
        // be omitted entirely from judge_results() — "nothing to rank"
        // per the method's own docblock, not shown with a 0 or null score.
        $sub3 = wp_insert_post([
            'post_type' => 'bh_submission', 'post_status' => 'publish', 'post_title' => 'Fixture Sub Three (draft only)',
            'meta_input' => ['bhcore_is_test' => self::SEED_TAG, '_bh_contest_id' => $cid],
        ], true);
        if (!is_wp_error($sub3)) {
            BH_Judging::save_score($judge_a, $cid, $sub3, '', ['originality' => 10, 'production' => 20], 'draft');
            $results_with_draft_only = BH_Judging::judge_results($cid, '');
            $ids_present = array_column($results_with_draft_only, 'id');
            $rows[] = OUS_TestRunner::assert_false(in_array($sub3, $ids_present, true), 'A submission with only a draft (never submitted) score is omitted entirely from judge_results()');
            wp_delete_post($sub3, true);
        }

        // Cleanup.
        $wpdb->delete($table, ['contest_id' => $cid]);
        wp_delete_post($sub1, true);
        wp_delete_post($sub2, true);
        wp_delete_post($cid, true);

        return $rows;
    }

    /* ---------- BH_Rounds::is_eligible()/advance_round() — real DB fixtures ---------- */

    private static function run_rounds_tests() {
        $rows = [];
        global $wpdb;

        $cid = wp_insert_post([
            'post_type' => 'bh_contest', 'post_status' => 'publish', 'post_title' => 'Rounds Test Fixture Contest',
            'meta_input' => [
                'bhcore_is_test' => self::SEED_TAG,
                '_bh_contest_format' => 'judges',
                '_bh_active_round' => 0,
                '_bh_rounds' => wp_json_encode([
                    ['cut_count' => 2], // round 0 -> keep top 2
                    ['cut_count' => 1], // round 1 -> keep top 1
                ]),
                '_bh_rubric' => wp_json_encode([['slug' => 'score', 'name' => 'Score', 'max' => 100]]),
            ],
        ], true);
        if (is_wp_error($cid)) {
            return [['name' => 'Rounds test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture contest post — skipping rounds tests.']];
        }

        $subs = [];
        foreach (['A', 'B', 'C'] as $label) {
            $sid = wp_insert_post([
                'post_type' => 'bh_submission', 'post_status' => 'publish', 'post_title' => 'Rounds Fixture ' . $label,
                'meta_input' => ['bhcore_is_test' => self::SEED_TAG, '_bh_contest_id' => $cid],
            ], true);
            if (!is_wp_error($sid)) $subs[$label] = $sid;
        }
        if (count($subs) < 3) {
            wp_delete_post($cid, true);
            return [['name' => 'Rounds test fixture creation failed', 'pass' => false, 'message' => 'Could not create fixture submission posts — skipping rounds tests.']];
        }

        // Fresh submission, no _bh_round_reached meta at all, contest
        // still on round 0 -> eligible (0 >= 0), never regresses to
        // "ineligible by default."
        $rows[] = OUS_TestRunner::assert_true(BH_Rounds::is_eligible($subs['A'], $cid), 'A brand-new submission with no round_reached meta is eligible for round 0 (0 >= 0)');

        // Judge scores that clearly rank A > B > C, so advance_round()'s
        // cut_count=2 keeps A and B, eliminates C.
        $judge = 555010;
        BH_Judging::save_score($judge, $cid, $subs['A'], '', ['score' => 100], 'submitted');
        BH_Judging::save_score($judge, $cid, $subs['B'], '', ['score' => 60], 'submitted');
        BH_Judging::save_score($judge, $cid, $subs['C'], '', ['score' => 10], 'submitted');

        $result = BH_Rounds::advance_round($cid);
        $rows[] = OUS_TestRunner::assert_false(is_wp_error($result), 'advance_round() succeeds against a real fixture round with real judge scores');
        if (!is_wp_error($result)) {
            $rows[] = OUS_TestRunner::assert_same(1, $result['next_round'], 'advance_round() reports the new active round index (0 -> 1)');
            $rows[] = OUS_TestRunner::assert_true(in_array($subs['A'], $result['advanced'], true) && in_array($subs['B'], $result['advanced'], true), 'The top cut_count=2 scored submissions (A, B) are reported as advanced');
            $rows[] = OUS_TestRunner::assert_true(in_array($subs['C'], $result['eliminated'], true), 'The lowest-scored submission (C) is reported as eliminated, not silently dropped');
        }

        $rows[] = OUS_TestRunner::assert_same(1, (int) get_post_meta($cid, '_bh_active_round', true), 'advance_round() actually persists the new active round to the contest post');
        $rows[] = OUS_TestRunner::assert_same(1, BH_Rounds::round_reached($subs['A']), 'A survivor\'s round_reached is bumped to the new round index');
        $rows[] = OUS_TestRunner::assert_same(0, BH_Rounds::round_reached($subs['C']), 'An eliminated submission\'s round_reached is left alone (eliminated, not deleted or regressed)');

        // Now that the contest has moved to round 1, the eliminated
        // submission (still at round_reached=0) must no longer be
        // eligible — this is the actual "cut" taking effect.
        $rows[] = OUS_TestRunner::assert_false(BH_Rounds::is_eligible($subs['C'], $cid), 'An eliminated submission is no longer eligible once the contest advances past its round_reached');
        $rows[] = OUS_TestRunner::assert_true(BH_Rounds::is_eligible($subs['A'], $cid), 'A survivor remains eligible in the new active round');

        // Cleanup.
        $wpdb->delete($wpdb->prefix . 'bh_judge_scores', ['contest_id' => $cid]);
        foreach ($subs as $sid) wp_delete_post($sid, true);
        wp_delete_post($cid, true);

        return $rows;
    }
}
