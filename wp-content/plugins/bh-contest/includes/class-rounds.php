<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b — multi-round/
 * elimination format, built AFTER judge scoring (2a) per that doc's own
 * sequencing note, since a round transition can cut the field either by
 * public vote or by judge score depending on the contest's own format.
 *
 * A contest with no `_bh_rounds` configured behaves exactly as it
 * always has — every method here falls back to BH_Helpers'
 * pre-existing single-window logic in that case, so nothing already
 * running changes shape. Only once an admin actually defines 2+ rounds
 * does any of this engage.
 *
 * `_bh_rounds`: an ordered list of round definitions, each with its own
 * submission window (optional — most rounds after the first have none,
 * since round 2+'s "entrants" are the survivors of round 1, not new
 * submissions) and voting window, plus a cut_count (how many survive
 * into the next round). `_bh_active_round`: which round index is
 * currently being run. A submission's own `_bh_round_reached` (post
 * meta) is the highest round index it has survived INTO — starts at 0
 * (every submission is eligible for round 0 the moment it's approved),
 * bumped by advance_round() below for whoever the cut keeps.
 *
 * Votes/judge scores each carry their own `round` (class-activator.php
 * 1.7) so each round's tally is genuinely independent — a submission
 * that got 40 votes in round 1 doesn't carry those into round 2's count.
 */
class BH_Rounds {
    public static function rounds($cid) {
        $raw = get_post_meta($cid, '_bh_rounds', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    public static function is_multi_round($cid) {
        return count(self::rounds($cid)) > 1;
    }

    public static function active_round_index($cid) {
        return max(0, (int) get_post_meta($cid, '_bh_active_round', true));
    }

    public static function active_round($cid) {
        $rounds = self::rounds($cid);
        return $rounds[self::active_round_index($cid)] ?? null;
    }

    private static function normalize_dt($raw) {
        if (!$raw) return '';
        $raw = str_replace('T', ' ', trim($raw));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) $raw .= ':00';
        return $raw;
    }

    // Same open/upcoming/closed/unscheduled shape as
    // BH_Helpers::submission_status()/contest_status() — a round with
    // blank dates (the common case for round 2+, which has no NEW
    // submission window) reads as 'unscheduled', same "always open"
    // treatment BH_Helpers::is_submission_open() already gives an
    // unscheduled single-round contest, so a round with no sub window
    // simply never blocks submissions on its own (BH_Gate-equivalent
    // callers should treat an active round with round_index > 0 and no
    // sub window as CLOSED to new entrants in practice — see
    // is_new_submission_allowed() below, which is the real gate).
    private static function window_status($start, $end) {
        $start = self::normalize_dt($start);
        $end = self::normalize_dt($end);
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end) return 'closed';
        return 'open';
    }

    // The real gate for "can a NEW track be submitted right now." Round
    // 0 with no configured sub window falls back to the contest's
    // original _bh_sub_start/_bh_sub_end (unchanged behavior for a
    // single-round contest, or a multi-round contest that never touched
    // round 0's window). Round 1+ with no configured sub window is
    // CLOSED to new entrants by design — round N+'s "entrants" are the
    // survivors of round N-1, not open enrollment.
    public static function is_new_submission_allowed($cid) {
        if (!self::is_multi_round($cid)) return BH_Helpers::is_submission_open($cid);

        $round = self::active_round($cid);
        $idx = self::active_round_index($cid);
        if (!$round) return false;

        if (empty($round['sub_start']) && empty($round['sub_end'])) {
            return $idx === 0 ? BH_Helpers::is_submission_open($cid) : false;
        }
        $status = self::window_status($round['sub_start'] ?? '', $round['sub_end'] ?? '');
        return $status === 'open';
    }

    public static function is_voting_open($cid) {
        if (!self::is_multi_round($cid)) return BH_Helpers::is_voting_open($cid);

        $round = self::active_round($cid);
        if (!$round) return false;
        if (empty($round['vote_start']) && empty($round['vote_end'])) {
            return BH_Helpers::is_voting_open($cid); // falls back to the contest-wide window
        }
        return self::window_status($round['vote_start'] ?? '', $round['vote_end'] ?? '') === 'open';
    }

    public static function round_reached($sid) {
        return max(0, (int) get_post_meta($sid, '_bh_round_reached', true));
    }

    // Eligible to be voted/scored/counted in the CURRENT active round —
    // survived at least that far. A submission never regresses; it
    // simply stops advancing once cut.
    public static function is_eligible($sid, $cid) {
        return self::round_reached($sid) >= self::active_round_index($cid);
    }

    // Admin-triggered: closes out the active round by tallying its
    // votes/judge scores (whichever the contest's own format uses —
    // BH_Helpers::contest_format()), keeps the top cut_count entries,
    // bumps their _bh_round_reached to the NEXT round index, and moves
    // the contest's active round forward. Everyone not kept simply stays
    // at their current _bh_round_reached — eliminated, not deleted, so
    // their round-N results/history remain intact for the record.
    // Returns ['advanced' => [...ids], 'eliminated' => [...ids], 'next_round' => int] or a WP_Error.
    public static function advance_round($cid) {
        $rounds = self::rounds($cid);
        $idx = self::active_round_index($cid);
        if (!isset($rounds[$idx])) return new WP_Error('no_round', 'No active round to advance from.');
        if (!isset($rounds[$idx + 1])) return new WP_Error('final_round', 'This is already the final round — nothing to advance to.');

        $round = $rounds[$idx];
        $cut = max(1, (int) ($round['cut_count'] ?? 0));
        $format = BH_Helpers::contest_format($cid);
        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => '']];

        // Ranked across every category combined (highest single-category
        // rank position wins ties) would need real tie-breaking logic
        // this MVP doesn't need to invent — most elimination contests
        // run a single implicit category, and a multi-category contest
        // advancing per-category would need its own UI decision (does
        // "advance" mean the union of every category's top N, or per-
        // category cuts kept separate?). Scoped here to the simplest,
        // most common real shape: total tally across all categories
        // combined, same "Overall" ranking BH_Reveal::overall_results()
        // already computes for the public-vote case.
        $totals = [];
        foreach ($cats as $c) {
            $results = $format === 'judges'
                ? BH_Judging::judge_results($cid, $c['slug'], $idx)
                : BH_API::category_results($cid, $c['slug'], $idx);
            foreach ($results as $r) {
                $totals[$r['id']] = ($totals[$r['id']] ?? 0) + $r['votes'];
            }
        }
        arsort($totals);
        $survivors = array_slice(array_keys($totals), 0, $cut);

        $eligible_now = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
        ]);
        $eligible_now = array_filter($eligible_now, fn($sid) => self::is_eligible($sid, $cid));

        $eliminated = array_diff($eligible_now, $survivors);
        foreach ($survivors as $sid) {
            update_post_meta($sid, '_bh_round_reached', $idx + 1);
        }
        update_post_meta($cid, '_bh_active_round', $idx + 1);

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Contest round advanced.', [
                'contest_id' => $cid, 'from_round' => $idx, 'to_round' => $idx + 1,
                'survivors' => $survivors, 'eliminated' => array_values($eliminated),
            ], 'BH Contest Rounds');
        }

        return ['advanced' => $survivors, 'eliminated' => array_values($eliminated), 'next_round' => $idx + 1];
    }
}
