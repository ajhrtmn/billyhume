<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a — judge/rubric
 * scoring mode. A judge is a specific user assigned to a specific
 * contest (a plain list of user IDs on the contest post, `_bh_judges`),
 * not a new WordPress capability/role — most judges are guest
 * volunteers, not site admins, and a per-contest list is both simpler to
 * manage than provisioning a real WP role and matches the roadmap doc's
 * own "or a per-contest judge list" alternative.
 *
 * A rubric (`_bh_rubric`, per contest) is an admin-defined list of named
 * criteria, each with its own max score — same free-text-one-per-line
 * authoring pattern BH_Helpers::parse_categories_input() already uses
 * for voting categories, just with an optional ":max" suffix.
 *
 * Scores live in bh_judge_scores (class-activator.php) — one row per
 * (judge, submission, category), holding the WHOLE rubric's per-
 * criterion scores as a JSON snapshot with an explicit draft/submitted
 * status, deliberately NOT reusing bh_votes (see that table's own
 * comment for why the shapes are genuinely different). Only 'submitted'
 * rows ever count toward judge_results() — a judge can save a draft,
 * come back later, and it never affects the leaderboard until they
 * explicitly submit, matching Devpost's own "save progress" convention
 * the roadmap doc calls out directly.
 */
class BH_Judging {
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_shortcode('bh_judge_panel', [self::class, 'render_judge_panel']);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bh_judge_scores';
    }

    /* ---------------- judges + rubric config ---------------- */

    public static function judge_ids($cid) {
        $raw = get_post_meta($cid, '_bh_judges', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? array_map('intval', $list) : [];
    }

    public static function is_judge($uid, $cid) {
        return $uid && in_array((int) $uid, self::judge_ids($cid), true);
    }

    // One rubric criterion per line: "Originality" (defaults to a max of
    // 10) or "Originality:20" for a custom max. Same
    // parse-free-text-into-a-slug+shape pattern as
    // BH_Helpers::parse_categories_input(), deliberately not reusing
    // that method directly since criteria carry a max score categories
    // don't.
    public static function parse_rubric_input($text) {
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

    public static function rubric($cid) {
        $raw = get_post_meta($cid, '_bh_rubric', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    /* ---------------- scoring ---------------- */

    public static function judge_status($judge_id, $cid, $sid, $category, $round = 0) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT scores, status FROM " . self::table() . " WHERE judge_id = %d AND contest_id = %d AND submission_id = %d AND category = %s AND round = %d",
            $judge_id, $cid, $sid, $category, (int) $round
        ), ARRAY_A);
        if (!$row) return ['scores' => [], 'status' => 'draft'];
        $decoded = json_decode($row['scores'], true);
        return ['scores' => is_array($decoded) ? $decoded : [], 'status' => $row['status']];
    }

    // Saves one submission's rubric scores for one judge — either a
    // draft (can be resubmitted/overwritten freely) or a final submit
    // (still overwritable, same as re-voting is in bh_votes — a judge
    // correcting a misclick shouldn't need a support ticket).
    public static function save_score($judge_id, $cid, $sid, $category, array $scores, $status, $round = 0) {
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

    // Same ranked-results shape BH_API::category_results()/BH_Reveal::
    // overall_results() already return (rank/id/title/artist/votes) so
    // every existing consumer (medal_slice(), the reveal sequence
    // builder, the display shortcode's rendering) works unmodified — the
    // 'votes' key just holds a normalized 0-100 score here instead of a
    // raw vote count. Per-judge score = each scored criterion normalized
    // to a percentage of its own max, averaged across criteria (so a
    // 5-criterion and a 3-criterion rubric both land on the same 0-100
    // scale); per-submission score = the average of every judge who
    // actually SUBMITTED (drafts never count) for that submission/
    // category. A submission no judge has scored yet is omitted
    // entirely, same "nothing to rank" omission category_results()
    // already does for a zero-vote submission.
    // $round: see BH_API::category_results()'s own docblock on this same
    // parameter — identical meaning and identical null-means-"every
    // round" default here.
    public static function judge_results($cid, $category, $round = null) {
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
                'votes'  => $v['score'], // see method docblock — a normalized 0-100 score, keyed 'votes' for shape compatibility
            ];
        }
        return $out;
    }

    /* ---------------- REST ---------------- */

    public static function register_routes() {
        register_rest_route('bh/v1', '/judge/score', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_save_score'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function rest_save_score($req) {
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
        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: a
        // submission that's already been cut out of the running (didn't
        // survive a prior round) can't collect new scores against the
        // CURRENT round — its round-N history stays exactly as scored,
        // untouched. No-op for a single-round contest (every submission
        // is always eligible for round 0).
        $round = class_exists('BH_Rounds') ? BH_Rounds::active_round_index($cid) : 0;
        if (class_exists('BH_Rounds') && !BH_Rounds::is_eligible($sid, $cid)) {
            return new WP_Error('eliminated', 'This entry did not advance to the current round.', ['status' => 403]);
        }

        $ok = self::save_score($uid, $cid, $sid, $category, $scores, $status, $round);
        if (!$ok) return new WP_Error('save_failed', 'Could not save your score — please try again.', ['status' => 500]);

        return new WP_REST_Response(['success' => true, 'status' => $status === 'submitted' ? 'submitted' : 'draft'], 200);
    }

    /* ---------------- front-end panel ---------------- */

    // [bh_judge_panel contest="..."] — a private, judge-only scoring
    // view. Deliberately a front-end shortcode rather than a wp-admin
    // screen (the roadmap doc's own suggested precedent, class-reveal.
    // php's admin-controller pattern, assumes a manage_options user —
    // most judges here are guest volunteers with no wp-admin access at
    // all, so gating this the same way the rest of this plugin's
    // logged-in-only front-end surfaces do (BH_Auth's session, not a WP
    // capability) is the right fit, not a deviation from precedent).
    public static function render_judge_panel($atts) {
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
            'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
        ]);

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: a
        // multi-round contest only ever asks a judge to score whoever is
        // still IN the running for the round currently active — an
        // eliminated entry simply disappears from the panel rather than
        // inviting a now-pointless score against it. round_reached()
        // defaults to 0 for every submission, so a single-round contest
        // (active round always 0) keeps every submission listed exactly
        // as before this existed.
        $round = class_exists('BH_Rounds') ? BH_Rounds::active_round_index($cid) : 0;
        if (class_exists('BH_Rounds')) {
            $subs = array_values(array_filter($subs, fn($s) => BH_Rounds::is_eligible($s->ID, $cid)));
        }

        // Reuses the main player's own design-token stylesheet — the
        // panel previously enqueued nothing at all (raw unstyled browser
        // controls, caught live: "its ugly") — plus a small panel-only
        // stylesheet for the entry-card/slider layout this shortcode
        // needs that player.css has no equivalent of.
        wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', [], BH_VER);
        wp_enqueue_style('bh-judging', BH_URL . 'assets/css/judging.css', ['bh-player'], BH_VER);
        wp_enqueue_script('bh-judging', BH_URL . 'assets/js/bh-judging.js', [], BH_VER, true);
        wp_localize_script('bh-judging', 'BHJudgeData', [
            'restUrl' => esc_url_raw(rest_url('bh/v1/judge/score')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'contestId' => $cid,
        ]);

        ob_start();
        // bh-container: opts into player.css's whole design-token system
        // (dark theme, --bh-* custom properties, .bh-btn family) — the
        // panel is a sibling surface of the contest player, not a
        // separately-invented look.
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
