<?php
if (!defined('ABSPATH')) exit;

/**
 * Multi-round elimination. Without 2+ `_bh_rounds`, everything falls back to
 * BH_Helpers' single-window logic. `_bh_active_round` is the current round index;
 * a submission's `_bh_round_reached` is the highest round it survived into.
 * Votes and judge scores carry their own `round`, so each round tallies independently.
 */
class BH_Rounds {
    /** @return array<int, array<string, mixed>> */
    public static function rounds(int $cid): array {
        $raw = get_post_meta($cid, '_bh_rounds', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    public static function is_multi_round(int $cid): bool {
        return count(self::rounds($cid)) > 1;
    }

    public static function active_round_index(int $cid): int {
        return max(0, (int) get_post_meta($cid, '_bh_active_round', true));
    }

    /** @return array<string, mixed>|null */
    public static function active_round(int $cid): ?array {
        $rounds = self::rounds($cid);
        return $rounds[self::active_round_index($cid)] ?? null;
    }

    /** @param mixed $raw */
    private static function normalize_dt($raw): string {
        if (!$raw) return '';
        $raw = str_replace('T', ' ', trim($raw));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) $raw .= ':00';
        return $raw;
    }

    // open/upcoming/closed/unscheduled, like BH_Helpers::contest_status().
    // is_new_submission_allowed() is the real submission gate.
    private static function window_status(string $start, string $end): string {
        $start = self::normalize_dt($start);
        $end = self::normalize_dt($end);
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end) return 'closed';
        return 'open';
    }

    // Can a new track be submitted now? With no round sub window, round 0 uses the
    // contest window and later rounds are closed (their entrants are survivors).
    public static function is_new_submission_allowed(int $cid): bool {
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

    public static function is_voting_open(int $cid): bool {
        if (!self::is_multi_round($cid)) return BH_Helpers::is_voting_open($cid);

        $round = self::active_round($cid);
        if (!$round) return false;
        if (empty($round['vote_start']) && empty($round['vote_end'])) {
            return BH_Helpers::is_voting_open($cid); // falls back to the contest-wide window
        }
        return self::window_status($round['vote_start'] ?? '', $round['vote_end'] ?? '') === 'open';
    }

    public static function round_reached(int $sid): int {
        return max(0, (int) get_post_meta($sid, '_bh_round_reached', true));
    }

    // Eligible in the active round if it survived at least that far.
    public static function is_eligible(int $sid, int $cid): bool {
        return self::round_reached($sid) >= self::active_round_index($cid);
    }

    // Tallies the active round, advances the top cut_count, and moves the contest on.
    // Eliminated entries keep their history. Returns advanced/eliminated/next_round.
    /** @return array<string, mixed>|\WP_Error */
    public static function advance_round(int $cid) {
        $rounds = self::rounds($cid);
        $idx = self::active_round_index($cid);
        if (!isset($rounds[$idx])) return new WP_Error('no_round', 'No active round to advance from.');
        if (!isset($rounds[$idx + 1])) return new WP_Error('final_round', 'This is already the final round — nothing to advance to.');

        $round = $rounds[$idx];
        $cut = max(1, (int) ($round['cut_count'] ?? 0));
        $format = BH_Helpers::contest_format($cid);
        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => '']];

        // The cut ranks by total tally across all categories (no per-category cuts).
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
            'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid,
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

        // WHY: a synchronous action, not a BH_Event (async), so live consumers
        // like an OBS scene switch react immediately.
        do_action('bh_contest_round_advanced', $cid, $idx + 1);

        return ['advanced' => $survivors, 'eliminated' => array_values($eliminated), 'next_round' => $idx + 1];
    }
}
