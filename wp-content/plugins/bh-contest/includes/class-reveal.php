<?php
if (!defined('ABSPATH')) exit;

/**
 * Live results reveal: each category counts up from 3rd place to the winner,
 * then an Overall reveal. An admin controller drives a public display
 * ([bh_results_reveal]) that polls the server, so they can run on different machines.
 */
class BH_Reveal {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_shortcode('bh_results_reveal', [self::class, 'render_display_shortcode']);
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'bh-console') === false) return;
        wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
    }

    public static function register_routes(): void {
        register_rest_route('bh/v1', '/reveal/state', [
            'methods' => 'GET', 'callback' => [self::class, 'get_state'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bh/v1', '/reveal/advance', [
            'methods' => 'POST', 'callback' => [self::class, 'advance'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    /* ---------- sequence + rendering ---------- */

    // Ordered reveal steps, rebuilt from live data on every request (never cached).
    /** @return array<int, array<string, mixed>> */
    public static function build_sequence(int $cid): array {
        $steps = [['type' => 'intro', 'title' => get_the_title($cid)]];

        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => 'Results']];

        // 'judges' feeds the rubric average into the same steps; 'hybrid' runs two
        // labeled passes, never a blended score.
        $format = BH_Helpers::contest_format($cid);
        // Multi-round contests reveal only the active round's tally; null = all votes.
        $round = class_exists('BH_Rounds') && BH_Rounds::is_multi_round($cid) ? BH_Rounds::active_round_index($cid) : null;

        $passes = [];
        if ($format === 'public' || $format === 'hybrid') $passes[] = ['source' => 'public', 'label' => $format === 'hybrid' ? "People's Choice" : null];
        if ($format === 'judges' || $format === 'hybrid') $passes[] = ['source' => 'judges', 'label' => $format === 'hybrid' ? "Judges' Pick" : null];

        foreach ($passes as $pass) {
            if ($pass['label']) $steps[] = ['type' => 'pass_intro', 'title' => $pass['label']];

            foreach ($cats as $c) {
                $results = $pass['source'] === 'judges' ? BH_Judging::judge_results($cid, $c['slug'], $round) : BH_API::category_results($cid, $c['slug'], $round);
                if (!$results) continue; // nothing to reveal for an empty category
                $tier_count = self::medal_tier_count($results);
                $steps[] = ['type' => 'category_intro', 'category' => $c['name'], 'slug' => $c['slug'], 'entry_count' => count($results), 'source' => $pass['source']];
                for ($i = 1; $i <= $tier_count; $i++) {
                    $steps[] = ['type' => 'category_reveal', 'category' => $c['name'], 'slug' => $c['slug'], 'reveal_count' => $i, 'source' => $pass['source']];
                }
            }
        }

        // Overall is public-vote and single-round only; across rounds it would mix
        // eliminated entries' votes with the survivors'.
        if (($format === 'public' || $format === 'hybrid') && $round === null) {
            $overall = self::overall_results($cid);
            if ($overall) {
                $tier_count = self::medal_tier_count($overall);
                $steps[] = ['type' => 'overall_intro'];
                for ($i = 1; $i <= $tier_count; $i++) {
                    $steps[] = ['type' => 'overall_reveal', 'reveal_count' => $i];
                }
            }
        }

        $steps[] = ['type' => 'end'];
        return $steps;
    }

    // Overall ranking: total votes across all categories. Public for BH_Discord.
    /** @return array<int, array<string, mixed>> */
    public static function overall_results(int $cid): array {
        global $wpdb;
        $t = BH_Helpers::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, COUNT(id) votes FROM $t WHERE contest_id = %d GROUP BY submission_id ORDER BY votes DESC LIMIT 10",
            $cid
        ));

        $valid = [];
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p || $p->post_status !== 'publish') continue;
            $valid[] = ['post' => $p, 'votes' => (int) $r->votes];
        }
        $ranks = BH_Helpers::competition_ranks(array_column($valid, 'votes'));

        $out = [];
        foreach ($valid as $i => $v) {
            $p = $v['post'];
            $out[] = ['rank' => $ranks[$i], 'id' => $p->ID, 'title' => $p->post_title, 'artist' => BH_Helpers::artist_for($p), 'votes' => $v['votes']];
        }
        return $out;
    }

    // Display data for one step; *_reveal steps include the medal entries shown
    // so far (3rd first) and which rank just appeared.
    /** @return array<string, mixed> */
    public static function render_step(int $cid, int $index): array {
        $seq = self::build_sequence($cid);
        $index = max(0, min($index, count($seq) - 1));
        $step = $seq[$index];
        $data = ['index' => $index, 'total' => count($seq), 'type' => $step['type']];

        if ($step['type'] === 'intro') {
            $data['title'] = $step['title'];
        } elseif ($step['type'] === 'pass_intro') {
            // Hybrid-only divider between the two leaderboards.
            $data['title'] = $step['title'];
        } elseif ($step['type'] === 'category_intro') {
            $data['category'] = $step['category'];
            $data['entry_count'] = $step['entry_count'];
            $data['source'] = $step['source'] ?? 'public';
        } elseif ($step['type'] === 'category_reveal') {
            $source = $step['source'] ?? 'public';
            // Must match build_sequence()'s round scoping.
            $round = class_exists('BH_Rounds') && BH_Rounds::is_multi_round($cid) ? BH_Rounds::active_round_index($cid) : null;
            $results = $source === 'judges' ? BH_Judging::judge_results($cid, $step['slug'], $round) : BH_API::category_results($cid, $step['slug'], $round);
            $data['category'] = $step['category'];
            $data['source'] = $source;
            [$data['entries'], $data['just_revealed_rank']] = self::medal_slice($results, $step['reveal_count']);
        } elseif ($step['type'] === 'overall_reveal') {
            $results = self::overall_results($cid);
            [$data['entries'], $data['just_revealed_rank']] = self::medal_slice($results, $step['reveal_count']);
        } elseif ($step['type'] === 'overall_intro') {
            $data['entry_count'] = count(self::overall_results($cid));
        }

        return $data;
    }

    // Distinct medal ranks (ties share one reveal step), not the entry count.
    /** @param array<int, array<string, mixed>> $results */
    private static function medal_tier_count($results): int {
        $ranks = array_unique(array_column(array_filter($results, fn($r) => $r['rank'] <= 3), 'rank'));
        return count($ranks);
    }

    // Returns [entries shown, rank just revealed] after $reveal_count medal tiers,
    // worst tier first. Tied entries share a tier; reveal.js keys off rank value.
    /**
     * @param array<int, array<string, mixed>> $results
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function medal_slice($results, int $reveal_count): array {
        $medal_results = array_values(array_filter($results, fn($r) => $r['rank'] <= 3));
        $tiers = array_values(array_unique(array_column($medal_results, 'rank')));
        sort($tiers); // ascending, e.g. [1,2,3] normally, or [1,2] if a tie ate the 3rd-place tier entirely

        $total_tiers = count($tiers);
        $reveal_count = max(1, min($reveal_count, $total_tiers));
        $revealed_tiers = array_slice($tiers, $total_tiers - $reveal_count); // the worst $reveal_count tiers revealed so far
        $just_revealed_tier = $tiers[$total_tiers - $reveal_count];

        $entries = array_values(array_filter($medal_results, fn($r) => in_array($r['rank'], $revealed_tiers, true)));
        usort($entries, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return [$entries, $just_revealed_tier];
    }

    /* ---------- REST ---------- */

    /** @return \WP_REST_Response */
    public static function get_state(\WP_REST_Request $req) {
        $cid = (int) $req->get_param('contest') ?: self::default_contest();
        if (!$cid) return new WP_REST_Response(['success' => true, 'type' => 'none', 'index' => 0, 'total' => 1, 'authoritative_index' => 0], 200);

        $seq = self::build_sequence($cid);
        $stored_index = max(0, min((int) get_post_meta($cid, '_bh_reveal_step', true), count($seq) - 1));

        // ?index= is a read-only peek at a step at or before the stored one, so the
        // display can catch up step by step instead of skipping ahead.
        $peek = $req->get_param('index');
        $render_index = $stored_index;
        if ($peek !== null) {
            $render_index = max(0, min((int) $peek, $stored_index));
        }

        $data = self::render_step($cid, $render_index);
        $data['success'] = true;
        $data['contest'] = $cid;
        $data['authoritative_index'] = $stored_index;
        return new WP_REST_Response($data, 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function advance(\WP_REST_Request $req) {
        $cid = (int) $req->get_param('contest') ?: self::default_contest();
        if (!$cid) return new WP_Error('no_contest', 'No contest to reveal.', ['status' => 404]);

        $seq = self::build_sequence($cid);
        $index = (int) get_post_meta($cid, '_bh_reveal_step', true);
        $action = sanitize_key((string) $req->get_param('action'));

        if ($action === 'next') $index++;
        elseif ($action === 'prev') $index--;
        elseif ($action === 'reset') $index = 0;
        elseif ($action === 'goto') $index = (int) $req->get_param('index');

        $index = max(0, min($index, count($seq) - 1));

        // WHY: never pass the intro while voting is open, so a mis-click can't leak standings.
        if ($index > 0 && BH_Helpers::is_voting_open($cid)) {
            return new WP_Error('voting_open', 'Voting is still open for this contest — close voting before revealing results.', ['status' => 403]);
        }

        update_post_meta($cid, '_bh_reveal_step', $index);
        $data = self::render_step($cid, $index);
        $data['success'] = true;
        return new WP_REST_Response($data, 200);
    }

    // Newest closed contest, else the active one. Public so BH_Console picks the same one.
    public static function default_contest(): int {
        foreach (BH_Helpers::all_contests() as $c) {
            if (BH_Helpers::contest_status($c->ID) === 'closed') return $c->ID;
        }
        return BH_Helpers::active_contest();
    }

    /* ---------- admin controls widget (embedded in Live Console) ---------- */

    // Reveal preview + Next/Prev/Reset, rendered inline by BH_Console::render().
    public static function render_controls_widget(int $cid): void {
        if (BH_Helpers::is_voting_open($cid)) {
            echo '<div class="notice notice-warning" style="margin:0 0 12px;"><p>Voting is still open — the reveal is locked to the intro slide until you close voting.</p></div>';
        }

        echo '<div id="bh-reveal-preview" style="background:#1a1a1a;color:#fff;border-radius:8px;padding:24px;margin-bottom:12px;min-height:120px;font-family:sans-serif;"></div>';
        echo '<p>';
        echo '<button type="button" class="button" id="bh-reveal-prev">&larr; Previous</button> ';
        echo '<button type="button" class="button button-primary" id="bh-reveal-next">Next &rarr;</button> ';
        echo '<button type="button" class="button" id="bh-reveal-reset">Reset to start</button>';
        echo '</p>';
        echo '<p class="description">Drives <code>[bh_results_reveal]</code> wherever that\'s embedded (e.g. the "Reveal Party" page) — updates within a couple of seconds of each click.</p>';
        ?>
        <script>
        (function () {
            var cid = <?php echo (int) $cid; ?>;
            var rest = <?php echo wp_json_encode(esc_url_raw(rest_url('bh/v1/'))); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
            var preview = document.getElementById('bh-reveal-preview');

            function render(data) {
                var html = '<div style="font-size:12px;color:#999;margin-bottom:8px;">Step ' + (data.index + 1) + ' of ' + data.total + ' — ' + bhEsc(data.type) + '</div>';
                if (data.type === 'intro') html += '<h2 style="margin:0;">' + bhEsc(data.title) + '</h2>';
                else if (data.type === 'category_intro') html += '<h2 style="margin:0;">' + bhEsc(data.category) + '</h2><p>' + bhEsc(data.entry_count) + ' entries</p>';
                else if (data.type === 'overall_intro') html += '<h2 style="margin:0;">Overall</h2><p>' + bhEsc(data.entry_count) + ' entries</p>';
                else if (data.type === 'category_reveal' || data.type === 'overall_reveal') {
                    html += data.category ? '<h3 style="margin:0 0 8px;">' + bhEsc(data.category) + '</h3>' : '<h3 style="margin:0 0 8px;">Overall</h3>';
                    (data.entries || []).forEach(function (e) {
                        var isNew = e.rank === data.just_revealed_rank;
                        html += '<div style="padding:6px 0;' + (isNew ? 'color:#FFD700;font-weight:700;' : '') + '">#' + bhEsc(e.rank) + ' — ' + bhEsc(e.title) + ' (' + bhEsc(e.artist) + ') — ' + bhEsc(e.votes) + ' votes</div>';
                    });
                } else if (data.type === 'end') html += '<h2 style="margin:0;">End of reveal</h2>';
                preview.innerHTML = html;
            }

            function refresh() {
                fetch(rest + 'reveal/state?contest=' + cid).then(function (r) { return r.json(); }).then(render);
            }

            var revealBtns = [
                document.getElementById('bh-reveal-next'),
                document.getElementById('bh-reveal-prev'),
                document.getElementById('bh-reveal-reset'),
            ];
            var busy = false;

            function send(action) {
                // Guarded against overlapping requests — a fast double-click
                // on "Next" during a live reveal used to be able to fire two
                // advance calls at once and skip a step in front of an
                // audience. Also previously had no .catch() at all: a
                // dropped connection just did nothing, with no sign to the
                // presenter that the click failed.
                if (busy) return;
                busy = true;
                revealBtns.forEach(function (b) { if (b) b.disabled = true; });
                fetch(rest + 'reveal/advance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ contest: cid, action: action }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.code === 'voting_open') { alert(data.message); return; }
                        render(data);
                    })
                    .catch(function () {
                        alert('Could not reach the server — check your connection and try again.');
                    })
                    .finally(function () {
                        busy = false;
                        revealBtns.forEach(function (b) { if (b) b.disabled = false; });
                    });
            }

            document.getElementById('bh-reveal-next').addEventListener('click', function () { send('next'); });
            document.getElementById('bh-reveal-prev').addEventListener('click', function () { send('prev'); });
            document.getElementById('bh-reveal-reset').addEventListener('click', function () {
                if (confirm('Reset the reveal back to the start?')) send('reset');
            });

            refresh();
        })();
        </script>
        <?php
    }

    /* ---------- public display shortcode ---------- */

    /** @param mixed $atts */
    public static function render_display_shortcode($atts): string {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_results_reveal');
        // Same fallback as the REST endpoint, so the inline theme matches the shown contest.
        $cid = $atts['contest'] !== '' ? BH_Helpers::resolve_contest($atts['contest']) : self::default_contest();

        ob_start();
        ?>
        <style><?php echo BHY_Style::inline_css($cid); ?></style>
        <div class="bh-reveal-wrap">
            <button type="button" class="bh-reveal-sound-toggle" id="bh-reveal-sound-toggle" aria-label="Toggle reveal sound effects" aria-pressed="true">🔊</button>
            <span class="bh-reveal-live" id="bh-reveal-live"><span class="bh-reveal-live-dot"></span><span class="bh-reveal-live-label">LIVE</span></span>
            <div class="bh-container bh-reveal-stage" id="bh-reveal-stage" data-contest="<?php echo esc_attr((string) $cid); ?>">
                <div class="bh-reveal-loading">Waiting for reveal to start…</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
