<?php
if (!defined('ABSPATH')) exit;

/**
 * Opt-in per-course "top quiz scorers" list (`_bhc_leaderboard_enabled`), ranked by
 * course_quiz_average(). Local implementation; bh-contest may not be installed.
 */
class BHC_Leaderboard {
    public static function is_enabled(int $course_id): bool {
        return (bool) get_post_meta($course_id, '_bhc_leaderboard_enabled', true);
    }

    // Same course_quiz_average() figure as the course page; students with no quiz attempts don't appear.
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
            if (!$user) continue; // deleted account
            $scored[] = ['user_id' => (int) $uid, 'name' => $user->display_name ?: $user->user_login, 'score' => $avg];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Competition ranking (1, 1, 3 — not 1, 1, 2).
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

    // Renders nothing when disabled or when nobody has attempted a quiz.
    public static function render(int $course_id): string {
        if (!self::is_enabled($course_id)) return '';
        $rows = self::top_scorers($course_id);
        if (!$rows) return '';

        ob_start();
        echo '<div class="bhc-leaderboard">';
        echo '<h2 class="bhc-leaderboard-title">Top Quiz Scorers</h2>';
        echo '<ol class="bhc-leaderboard-list">';
        // Medal glyphs for the top 3.
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
