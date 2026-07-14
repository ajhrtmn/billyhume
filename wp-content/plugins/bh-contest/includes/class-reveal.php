<?php
if (!defined('ABSPATH')) exit;

/**
 * Results Reveal — a controller/display pair for revealing contest
 * results live on stream, Olympics-medal-ceremony style: each category
 * counts up from 3rd place to the winner, one reveal at a time, then a
 * final "Overall" reveal across the whole contest.
 *
 * Two separate surfaces, deliberately:
 *  - The CONTROLLER (private, wp-admin, capability-gated) is what the
 *    admin actually clicks Next/Prev on.
 *  - The DISPLAY (public shortcode, [bh_results_reveal]) is what OBS
 *    captures. It polls its own state from the server every couple of
 *    seconds rather than sharing a browser/tab with the controller, so
 *    it works when the controller is running on a completely different
 *    machine from whatever machine is doing the OBS capture.
 *
 * There is no separate "test mode" — the whole system operates on
 * whatever contest is selected, real or seeded via Debug Tools. Create
 * a test contest, seed fake submissions and votes, close its voting
 * window, and the exact same controller/display pipeline that will run
 * the real reveal can be rehearsed against it end to end.
 */
class BH_Reveal {
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_shortcode('bh_results_reveal', [self::class, 'render_display_shortcode']);
    }

    public static function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bh-console') === false) return;
        wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
    }

    public static function register_routes() {
        register_rest_route('bh/v1', '/reveal/state', [
            'methods' => 'GET', 'callback' => [self::class, 'get_state'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bh/v1', '/reveal/advance', [
            'methods' => 'POST', 'callback' => [self::class, 'advance'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    /* ---------- sequence + rendering ---------- */

    // Ordered list of "steps" for a contest's reveal. Rebuilt fresh on
    // every request from live data rather than cached/stored — a reveal
    // in progress should never show stale vote counts, and contests are
    // small enough that recomputing this is cheap.
    public static function build_sequence($cid) {
        $steps = [['type' => 'intro', 'title' => get_the_title($cid)]];

        $cats = BH_Helpers::categories($cid);
        if (!$cats) $cats = [['slug' => '', 'name' => 'Results']];

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: 'judges'
        // swaps the public tally for the rubric average everywhere below
        // — every existing step type/rendering is untouched, only which
        // ranked-results function feeds it changes. 'hybrid' runs BOTH
        // passes back to back (Judges' Pick fully revealed, then
        // People's Choice), as two clearly-labeled leaderboards rather
        // than a blended score — the roadmap doc's own direct decision,
        // not a shortcut. A 'public' contest (every contest that
        // predates this feature) takes exactly the single pass it always
        // has, byte-for-byte the same sequence as before this existed.
        $format = BH_Helpers::contest_format($cid);
        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: a multi-
        // round contest reveals the ACTIVE round's own tally, not votes/
        // scores combined across every round (each round's votes are
        // independent rows — class-activator.php 1.7) — null for a
        // single-round contest, meaning "every vote regardless of round"
        // exactly as before this feature existed.
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

        // Overall (cross-category) only ever makes sense for the public-
        // vote tally on a SINGLE-round contest — a judged contest's
        // "overall" is already exactly what the single (or, in hybrid,
        // judges-pass) category loop above just revealed, and a multi-
        // round contest's "overall" would combine eliminated rounds'
        // votes with the survivors' current round in a way that doesn't
        // mean anything coherent (see round scoping above) — the active
        // round's own category reveal above IS the meaningful ranking
        // for a multi-round contest, this section is additive to it, not
        // required by it.
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

    // Total votes per submission across every category in the contest —
    // the "Overall" ranking, separate from any single category's. Public
    // so BH_Discord's results-published announcement can reuse this
    // exact ranking instead of re-deriving it.
    public static function overall_results($cid) {
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

    // Resolves a step index into everything the display needs to render
    // it — for a *_reveal step, that means the medal entries actually
    // visible so far (built bottom-up: 3rd place appears first, then
    // 2nd joins it, then 1st) plus which one just appeared, so the
    // front end can single it out with its own reveal animation.
    public static function render_step($cid, $index) {
        $seq = self::build_sequence($cid);
        $index = max(0, min($index, count($seq) - 1));
        $step = $seq[$index];
        $data = ['index' => $index, 'total' => count($seq), 'type' => $step['type']];

        if ($step['type'] === 'intro') {
            $data['title'] = $step['title'];
        } elseif ($step['type'] === 'pass_intro') {
            // Hybrid-format-only step (see build_sequence()) — the
            // "Judges' Pick" / "People's Choice" section divider between
            // the two leaderboards. reveal.js needs its own case for
            // this type; a display that predates hybrid contests never
            // sees this step type at all (build_sequence() only emits it
            // for format === 'hybrid').
            $data['title'] = $step['title'];
        } elseif ($step['type'] === 'category_intro') {
            $data['category'] = $step['category'];
            $data['entry_count'] = $step['entry_count'];
            $data['source'] = $step['source'] ?? 'public';
        } elseif ($step['type'] === 'category_reveal') {
            $source = $step['source'] ?? 'public';
            // Same round-scoping as build_sequence() — must match, since
            // this re-fetches results for whichever step index the
            // sequence built against.
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

    // How many distinct medal reveal steps a category/overall ranking
    // actually needs — NOT how many entries qualify, since a tie means
    // fewer distinct steps than entries (two people tied for 2nd is
    // still only one reveal step, just with two names in it).
    private static function medal_tier_count($results) {
        $ranks = array_unique(array_column(array_filter($results, fn($r) => $r['rank'] <= 3), 'rank'));
        return count($ranks);
    }

    // Given a ranked results list and how many medal TIERS have been
    // revealed so far, returns [entries actually shown, the rank tier
    // that was just revealed]. Tiers reveal worst-to-best (3rd-place
    // tier first, building to the winner), and a tier can contain more
    // than one entry if they're tied — both get shown together, both
    // get the "just revealed" treatment, since medalIcon() and the
    // "isNew"/"isWinner" checks in reveal.js already key off the rank
    // VALUE rather than array position, so multiple entries sharing a
    // rank are handled correctly with no front-end changes needed.
    private static function medal_slice($results, $reveal_count) {
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

    public static function get_state($req) {
        $cid = (int) $req->get_param('contest') ?: self::default_contest();
        if (!$cid) return new WP_REST_Response(['success' => true, 'type' => 'none', 'index' => 0, 'total' => 1, 'authoritative_index' => 0], 200);

        $seq = self::build_sequence($cid);
        $stored_index = max(0, min((int) get_post_meta($cid, '_bh_reveal_step', true), count($seq) - 1));

        // ?index= is a read-only peek at a SPECIFIC step (any step at or
        // before the real stored position — see the bounds check below),
        // used by the display's catch-up logic to walk through steps the
        // admin already advanced past between polls, one at a time,
        // rather than jumping straight to the current step and silently
        // skipping whatever suspense was in between. It never changes
        // server state — only advance() (admin-only) can do that.
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

    public static function advance($req) {
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

        // Safety net: don't let the reveal progress past the intro while
        // voting is still open, so a mis-click can't leak live standings
        // before a contest has actually finished. This deliberately does
        // NOT check submissions — only voting status matters here.
        if ($index > 0 && BH_Helpers::is_voting_open($cid)) {
            return new WP_Error('voting_open', 'Voting is still open for this contest — close voting before revealing results.', ['status' => 403]);
        }

        update_post_meta($cid, '_bh_reveal_step', $index);
        $data = self::render_step($cid, $index);
        $data['success'] = true;
        return new WP_REST_Response($data, 200);
    }

    // Prefers a contest whose voting has actually closed — the natural
    // moment to be running a reveal — over just "whatever's newest."
    // Public so BH_Console can default to the same contest, rather than
    // the identity table and the reveal controls picking different ones
    // when neither has an explicit ?contest_id= yet.
    public static function default_contest() {
        foreach (BH_Helpers::all_contests() as $c) {
            if (BH_Helpers::contest_status($c->ID) === 'closed') return $c->ID;
        }
        return BH_Helpers::active_contest();
    }

    /* ---------- admin controls widget (embedded in Live Console) ---------- */

    // Renders just the reveal controls (preview + Next/Prev/Reset) for a
    // contest the caller has already resolved — no page chrome, no
    // contest picker, no empty-state handling, since BH_Console::render()
    // already owns all of that and calls this inline. Previously this
    // was its own full admin page ("Reveal Control"); merged into Live
    // Console so the participant identity/vote info and the controls for
    // actually running the reveal live on the same screen instead of
    // requiring two separate tabs while running the show.
    public static function render_controls_widget($cid) {
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

            function send(action) {
                fetch(rest + 'reveal/advance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ contest: cid, action: action }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.code === 'voting_open') { alert(data.message); return; }
                        render(data);
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

    public static function render_display_shortcode($atts) {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_results_reveal');
        // Same resolution the REST endpoint falls back to (see
        // default_contest() above) — needed here too, not just there,
        // so the embedded theme block below actually matches whichever
        // contest ends up being displayed, including the common case
        // where no explicit contest attribute was given at all.
        $cid = $atts['contest'] !== '' ? BH_Helpers::resolve_contest($atts['contest']) : self::default_contest();

        ob_start();
        ?>
        <style><?php echo BHY_Style::inline_css($cid); ?></style>
        <div class="bh-container bh-reveal-stage" id="bh-reveal-stage" data-contest="<?php echo esc_attr($cid); ?>">
            <div class="bh-reveal-loading">Waiting for reveal to start…</div>
        </div>
        <?php
        return ob_get_clean();
    }
}
