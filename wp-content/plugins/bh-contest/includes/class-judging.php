<?php
if (!defined('ABSPATH')) exit;

/**
 * Judge/rubric scoring. Judges are a per-contest list of user IDs (`_bh_judges`),
 * not a WP role; the rubric (`_bh_rubric`) is named criteria with a max score each.
 * Scores live in bh_judge_scores; only 'submitted' rows count toward results.
 */
class BH_Judging {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_shortcode('bh_judge_panel', [self::class, 'render_judge_panel']);
    }

    private static function table(): string {
        return BHCON_Tables::judge_scores();
    }

    /* ---------------- judges + rubric config ---------------- */

    /** @return array<int, int> */
    public static function judge_ids(int $cid): array {
        $raw = get_post_meta($cid, '_bh_judges', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? array_map('intval', $list) : [];
    }

    public static function is_judge(int $uid, int $cid): bool {
        return $uid && in_array((int) $uid, self::judge_ids($cid), true);
    }

    // One criterion per line: "Originality" (max 10) or "Originality:20".
    /**
     * @param mixed $text
     * @return array<int, array{slug:string,name:string,max:int}>
     */
    public static function parse_rubric_input($text): array {
        $out = [];
        $seen = [];
        foreach (preg_split('/[\r\n]+/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $max = 10;
            if (strpos($line, ':') !== false) {
                [$name, $max_raw] = array_map('trim', explode(':', $line, 2));
                $max = max(1, min(100, (int) $max_raw ?: 10));
            } else {
                $name = $line;
            }
            if ($name === '') continue;
            $slug = sanitize_title($name);
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'name' => $name, 'max' => $max];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function rubric(int $cid): array {
        $raw = get_post_meta($cid, '_bh_rubric', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    /* ---------------- scoring ---------------- */

    /** @return array{scores: array<string, int>, status: string} */
    public static function judge_status(int $judge_id, int $cid, int $sid, string $category, int $round = 0): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT scores, status FROM " . self::table() . " WHERE judge_id = %d AND contest_id = %d AND submission_id = %d AND category = %s AND round = %d",
            $judge_id, $cid, $sid, $category, (int) $round
        ), ARRAY_A);
        if (!$row) return ['scores' => [], 'status' => 'draft'];
        $decoded = json_decode($row['scores'], true);
        return ['scores' => is_array($decoded) ? $decoded : [], 'status' => $row['status']];
    }

    // Upserts one judge's scores as draft or submitted; both stay overwritable.
    /** @param array<string, mixed> $scores */
    public static function save_score(int $judge_id, int $cid, int $sid, string $category, array $scores, string $status, int $round = 0): bool {
        global $wpdb;
        $status = $status === 'submitted' ? 'submitted' : 'draft';
        $clean_scores = [];
        foreach (self::rubric($cid) as $c) {
            if (!isset($scores[$c['slug']])) continue;
            $clean_scores[$c['slug']] = max(0, min($c['max'], (int) $scores[$c['slug']]));
        }

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (judge_id, contest_id, submission_id, category, round, scores, status)
             VALUES (%d, %d, %d, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE scores = VALUES(scores), status = VALUES(status), updated_at = CURRENT_TIMESTAMP",
            $judge_id, $cid, $sid, $category, (int) $round, wp_json_encode($clean_scores), $status
        ));
        if ($result === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'Judge score DB write failed.', [
                'judge_id' => $judge_id, 'contest_id' => $cid, 'submission_id' => $sid, 'category' => $category, 'db_error' => $wpdb->last_error,
            ], 'BH Contest Judging');
        }
        return $result !== false;
    }

    // Same shape as BH_API::category_results(), but 'votes' holds a 0-100 score:
    // criteria normalized to % of max and averaged, then averaged over submitted judges.
    // Unscored submissions are omitted. $round null = every round.
    /** @return array<int, array<string, mixed>> */
    public static function judge_results(int $cid, string $category, ?int $round = null): array {
        global $wpdb;
        $rubric = self::rubric($cid);
        if (!$rubric) return [];

        $round_sql = $round !== null ? $wpdb->prepare('AND round = %d', (int) $round) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, scores FROM " . self::table() . " WHERE contest_id = %d AND category = %s $round_sql AND status = 'submitted'",
            $cid, $category
        ));

        $by_submission = [];
        foreach ($rows as $row) {
            $scores = json_decode($row->scores, true);
            if (!is_array($scores) || !$scores) continue;

            $pct_sum = 0;
            $pct_count = 0;
            foreach ($rubric as $c) {
                if (!isset($scores[$c['slug']])) continue;
                $pct_sum += (min($c['max'], (int) $scores[$c['slug']]) / $c['max']) * 100;
                $pct_count++;
            }
            if (!$pct_count) continue;

            $by_submission[(int) $row->submission_id][] = $pct_sum / $pct_count;
        }

        $valid = [];
        foreach ($by_submission as $sid => $judge_scores) {
            $p = get_post($sid);
            if (!$p || $p->post_status !== 'publish') continue;
            $avg = array_sum($judge_scores) / count($judge_scores);
            $valid[] = ['post' => $p, 'score' => round($avg, 1)];
        }
        usort($valid, fn($a, $b) => $b['score'] <=> $a['score']);
        $valid = array_slice($valid, 0, 10);

        $ranks = BH_Helpers::competition_ranks(array_column($valid, 'score'));

        $out = [];
        foreach ($valid as $i => $v) {
            $p = $v['post'];
            $out[] = [
                'rank'   => $ranks[$i],
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'artist' => BH_Helpers::artist_for($p),
                'votes'  => $v['score'], // 0-100 score, keyed 'votes' for shape compatibility
            ];
        }
        return $out;
    }

    /* ---------------- REST ---------------- */

    public static function register_routes(): void {
        register_rest_route('bh/v1', '/judge/score', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_save_score'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest_save_score(\WP_REST_Request $req) {
        $uid = get_current_user_id();
        $cid = (int) $req->get_param('contest_id');
        $sid = (int) $req->get_param('submission_id');
        $category = sanitize_title((string) $req->get_param('category'));
        $status = (string) $req->get_param('status');
        $scores = (array) $req->get_param('scores');

        if (!self::is_judge($uid, $cid)) {
            return new WP_Error('not_judge', 'You are not assigned to judge this contest.', ['status' => 403]);
        }
        $sub = get_post($sid);
        if (!$sub || $sub->post_type !== 'bh_submission' || $sub->post_status !== 'publish'
            || (int) get_post_meta($sid, '_bh_contest_id', true) !== $cid) {
            return new WP_Error('bad', 'That track does not belong to this contest.', ['status' => 400]);
        }
        if (!BH_Helpers::is_valid_category($cid, $category)) {
            return new WP_Error('bad_category', 'That category does not exist.', ['status' => 400]);
        }
        // Entries cut in a prior round can't be scored in the current one.
        $round = class_exists('BH_Rounds') ? BH_Rounds::active_round_index($cid) : 0;
        if (class_exists('BH_Rounds') && !BH_Rounds::is_eligible($sid, $cid)) {
            return new WP_Error('eliminated', 'This entry did not advance to the current round.', ['status' => 403]);
        }

        $ok = self::save_score($uid, $cid, $sid, $category, $scores, $status, $round);
        if (!$ok) return new WP_Error('save_failed', 'Could not save your score — please try again.', ['status' => 500]);

        return new WP_REST_Response(['success' => true, 'status' => $status === 'submitted' ? 'submitted' : 'draft'], 200);
    }

    /* ---------------- front-end panel ---------------- */

    // [bh_judge_panel contest="..."]: front-end, not wp-admin, because most
    // judges are guest volunteers without wp-admin access.
    /** @param mixed $atts */
    public static function render_judge_panel($atts): string {
        if (!is_user_logged_in()) {
            return '<p class="bh-empty">Log in to access the judging panel.</p>';
        }
        $uid = get_current_user_id();
        $cid = BH_Helpers::resolve_contest($atts['contest'] ?? '');
        if (!$cid || !self::is_judge($uid, $cid)) {
            return '<p class="bh-empty">You are not assigned to judge this contest.</p>';
        }

        $rubric = self::rubric($cid);
        if (!$rubric) {
            return '<p class="bh-empty">This contest has no rubric configured yet.</p>';
        }

        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => 'Entries']];

        $subs = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'publish', 'posts_per_page' => -1,
            'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid,
        ]);

        // List only entries still eligible in the active round.
        $round = class_exists('BH_Rounds') ? BH_Rounds::active_round_index($cid) : 0;
        if (class_exists('BH_Rounds')) {
            $subs = array_values(array_filter($subs, fn($s) => BH_Rounds::is_eligible($s->ID, $cid)));
        }

        wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', [], BH_VER);
        wp_enqueue_style('bh-judging', BH_URL . 'assets/css/judging.css', ['bh-player'], BH_VER);
        wp_enqueue_script('bh-judging', BH_URL . 'assets/js/bh-judging.js', [], BH_VER, true);
        wp_localize_script('bh-judging', 'BHJudgeData', [
            'restUrl' => esc_url_raw(rest_url('bh/v1/judge/score')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'contestId' => $cid,
        ]);

        ob_start();
        // bh-container opts into player.css's design tokens.
        echo '<div class="bh-judge-panel bh-container">';
        echo '<h2 class="bh-judge-title">Judging: ' . esc_html(get_the_title($cid)) . ($round > 0 ? ' — Round ' . ((int) $round + 1) : '') . '</h2>';
        foreach ($cats as $cat) {
            echo '<h3 class="bh-judge-category">' . esc_html($cat['name']) . '</h3>';
            foreach ($subs as $sub) {
                $existing = self::judge_status($uid, $cid, $sub->ID, $cat['slug'], $round);
                $submitted = $existing['status'] === 'submitted';
                echo '<div class="bh-judge-entry' . ($submitted ? ' bh-judge-entry-submitted' : '') . '" data-submission-id="' . (int) $sub->ID . '" data-category="' . esc_attr($cat['slug']) . '">';
                echo '<h4 class="bh-judge-entry-title">' . esc_html($sub->post_title) . ' <span class="bh-judge-artist">' . esc_html(BH_Helpers::artist_for($sub)) . '</span></h4>';
                $audio_id = get_post_meta($sub->ID, '_bh_audio_id', true);
                $audio_url = $audio_id ? wp_get_attachment_url($audio_id) : '';
                if ($audio_url) {
                    echo '<audio class="bh-judge-audio" controls preload="none" style="color-scheme: dark;" src="' . esc_url($audio_url) . '"></audio>';
                } else {
                    echo '<p class="bh-judge-no-audio">No audio file attached to this entry yet.</p>';
                }

                foreach ($rubric as $c) {
                    $val = $existing['scores'][$c['slug']] ?? 0;
                    echo '<label class="bh-judge-criterion">' . esc_html($c['name']) . ' <span class="bh-judge-criterion-value">' . (int) $val . '</span>/' . (int) $c['max']
                       . '<input type="range" class="bh-scrubber bh-judge-slider" min="0" max="' . (int) $c['max'] . '" value="' . (int) $val . '" data-criterion="' . esc_attr($c['slug']) . '"></label>';
                }
                echo '<div class="bh-judge-actions">';
                echo '<button type="button" class="bh-btn bh-btn-outline bh-judge-save-draft">Save draft</button> ';
                echo '<button type="button" class="bh-btn bh-btn-primary bh-judge-submit">' . ($submitted ? 'Update submission' : 'Submit score') . '</button>';
                echo ' <span class="bh-judge-status' . ($submitted ? ' bh-judge-status-submitted' : '') . '">' . ($submitted ? 'Submitted' : 'Draft') . '</span>';
                echo '</div>';
                echo '</div>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }
}
