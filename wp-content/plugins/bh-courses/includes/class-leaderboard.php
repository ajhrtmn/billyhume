<?php
if (!defined('ABSPATH')) exit;

/**
 * LMS depth-of-magic Phase 4 — an opt-in "top quiz scorers" moment for a
 * single course, mirroring bh-contest's BH_Reveal ranked-results pattern
 * (rank/name/score rows, medal-tier styling for the top 3) rather than
 * sharing code with it directly — bh-contest may not even be installed,
 * and a course leaderboard's own ranking rules (course_quiz_average(),
 * not vote/judge tallies) are genuinely different data.
 *
 * Off by default per course (BHC_Admin::render_catalog_metabox()'s
 * `_bhc_leaderboard_enabled` checkbox) — a course creator opts a
 * specific course into "your students' scores are comparable," rather
 * than every course silently gaining a public ranking the moment this
 * shipped, same posture Lesson Q&A/certificates already established.
 */
class BHC_Leaderboard {
    public static function is_enabled(int $course_id): bool {
        return (bool) get_post_meta($course_id, '_bhc_leaderboard_enabled', true);
    }

    // Ranked by course_quiz_average() — the same "current quiz standing"
    // figure already shown on the course page and used for certificate
    // distinction (Phase 1e), so a student sees one consistent number
    // for "how am I doing" everywhere it appears, not a second, silently
    // different scoring rule invented just for this list. Only students
    // who've attempted at least one quiz appear (average returns null
    // otherwise) — obvious-or-gone, no student shows as "0%" just for
    // having enrolled.
    /** @return array<int, array<string, mixed>> */
    public static function top_scorers(int $course_id, int $limit = 10): array {
        if (!class_exists('BHC_Progress')) return [];
        global $wpdb;
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . BHC_Tables::enrollments() . " WHERE course_id = %d",
            $course_id
        ));

        $scored = [];
        foreach ($user_ids as $uid) {
            $avg = BHC_Progress::course_quiz_average($uid, $course_id);
            if ($avg === null) continue;
            $user = get_userdata($uid);
            if (!$user) continue; // a deleted account shouldn't still occupy a leaderboard slot
            $scored[] = ['user_id' => (int) $uid, 'name' => $user->display_name ?: $user->user_login, 'score' => $avg];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Competition ranking (1, 1, 3 — not 1, 1, 2): a tie shares the
        // same rank, and the next distinct score's rank accounts for how
        // many tied above it. Reimplemented locally (not a cross-plugin
        // call into bh-contest's own rank helper) per this feature's own
        // scoping — see this class's docblock.
        $ranked = [];
        $rank = 0;
        $prev_score = null;
        foreach ($scored as $i => $row) {
            if ($prev_score === null || $row['score'] !== $prev_score) $rank = $i + 1;
            $row['rank'] = $rank;
            $ranked[] = $row;
            $prev_score = $row['score'];
        }
        return array_slice($ranked, 0, $limit);
    }

    // Not rendered at all when disabled or when nobody's attempted a
    // quiz yet — obvious-or-gone, never an empty "Top Scorers" heading
    // sitting over nothing.
    public static function render(int $course_id): string {
        if (!self::is_enabled($course_id)) return '';
        $rows = self::top_scorers($course_id);
        if (!$rows) return '';

        ob_start();
        echo '<div class="bhc-leaderboard">';
        echo '<h2 class="bhc-leaderboard-title">Top Quiz Scorers</h2>';
        echo '<ol class="bhc-leaderboard-list">';
        // Emoji medal glyphs for the top 3 — same convention bh-contest's
        // own reveal.js already established for "instantly recognizable
        // rank" (medalIcon()), not a new color token invented just for
        // this list.
        $medals = [1 => '&#129351;', 2 => '&#129352;', 3 => '&#129353;'];
        foreach ($rows as $row) {
            $is_medal = isset($medals[$row['rank']]);
            echo '<li class="bhc-leaderboard-row' . ($is_medal ? ' bhc-leaderboard-medal' : '') . '">';
            echo '<span class="bhc-leaderboard-rank">' . ($is_medal ? $medals[$row['rank']] : '#' . (int) $row['rank']) . '</span>';
            echo '<span class="bhc-leaderboard-name">' . esc_html($row['name']) . '</span>';
            echo '<span class="bhc-leaderboard-score">' . (int) $row['score'] . '%</span>';
            echo '</li>';
        }
        echo '</ol></div>';
        return ob_get_clean();
    }
}
