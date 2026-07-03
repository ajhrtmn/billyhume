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
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_shortcode('bh_results_reveal', [self::class, 'render_display_shortcode']);
    }

    public static function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bh-reveal') === false) return;
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

        foreach ($cats as $c) {
            $results = BH_API::category_results($cid, $c['slug']);
            if (!$results) continue; // nothing to reveal for an empty category
            $medals = min(3, count($results));
            $steps[] = ['type' => 'category_intro', 'category' => $c['name'], 'slug' => $c['slug'], 'entry_count' => count($results)];
            for ($i = 1; $i <= $medals; $i++) {
                $steps[] = ['type' => 'category_reveal', 'category' => $c['name'], 'slug' => $c['slug'], 'reveal_count' => $i];
            }
        }

        $overall = self::overall_results($cid);
        if ($overall) {
            $medals = min(3, count($overall));
            $steps[] = ['type' => 'overall_intro'];
            for ($i = 1; $i <= $medals; $i++) {
                $steps[] = ['type' => 'overall_reveal', 'reveal_count' => $i];
            }
        }

        $steps[] = ['type' => 'end'];
        return $steps;
    }

    // Total votes per submission across every category in the contest —
    // the "Overall" ranking, separate from any single category's.
    private static function overall_results($cid) {
        global $wpdb;
        $t = BH_Helpers::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, COUNT(id) votes FROM $t WHERE contest_id = %d GROUP BY submission_id ORDER BY votes DESC LIMIT 10",
            $cid
        ));
        $out = []; $rank = 0;
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p || $p->post_status !== 'publish') continue;
            $out[] = ['rank' => ++$rank, 'id' => (int) $r->submission_id, 'title' => $p->post_title, 'artist' => BH_Helpers::artist_for($p), 'votes' => (int) $r->votes];
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
        } elseif ($step['type'] === 'category_intro') {
            $data['category'] = $step['category'];
            $data['entry_count'] = $step['entry_count'];
        } elseif ($step['type'] === 'category_reveal') {
            $results = BH_API::category_results($cid, $step['slug']);
            $data['category'] = $step['category'];
            [$data['entries'], $data['just_revealed_rank']] = self::medal_slice($results, $step['reveal_count']);
        } elseif ($step['type'] === 'overall_reveal') {
            $results = self::overall_results($cid);
            [$data['entries'], $data['just_revealed_rank']] = self::medal_slice($results, $step['reveal_count']);
        }

        return $data;
    }

    // Given a ranked results list and how many medal positions have been
    // revealed so far, returns [entries actually shown, the rank that
    // was just revealed]. Always ordered 1st-at-top for display, even
    // though revealing happens bottom-up.
    private static function medal_slice($results, $reveal_count) {
        $medals = min(3, count($results));
        $lowest_shown = $medals - $reveal_count + 1;
        $entries = array_values(array_filter($results, fn($r) => $r['rank'] >= $lowest_shown && $r['rank'] <= $medals));
        usort($entries, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return [$entries, $lowest_shown];
    }

    /* ---------- REST ---------- */

    public static function get_state($req) {
        $cid = (int) $req->get_param('contest') ?: self::default_contest();
        if (!$cid) return new WP_REST_Response(['success' => true, 'type' => 'none', 'index' => 0, 'total' => 1], 200);

        $index = (int) get_post_meta($cid, '_bh_reveal_step', true);
        $data = self::render_step($cid, $index);
        $data['success'] = true;
        $data['contest'] = $cid;
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
    private static function default_contest() {
        foreach (BH_Helpers::all_contests() as $c) {
            if (BH_Helpers::contest_status($c->ID) === 'closed') return $c->ID;
        }
        return BH_Helpers::active_contest();
    }

    /* ---------- admin controller ---------- */

    public static function add_menu() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Reveal Control', 'Reveal Control', 'manage_options', 'bh-reveal',
            [self::class, 'render_controller']
        );
    }

    public static function render_controller() {
        $contests = BH_Helpers::all_contests();
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : self::default_contest();

        echo '<div class="wrap"><h1>Reveal Control</h1>';
        echo '<p class="description">This page drives the public Results Reveal display ([bh_results_reveal]) — whatever you click here updates that page within a couple of seconds, wherever it\'s open.</p>';

        if (!$contests) { echo '<p>No contests yet.</p></div>'; return; }

        echo '<form method="get" style="margin:14px 0;"><input type="hidden" name="page" value="bh-reveal">';
        echo '<select name="contest_id" onchange="this.form.submit()">';
        foreach ($contests as $c) {
            echo '<option value="' . esc_attr($c->ID) . '" ' . selected($cid, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select></form>';

        if (!$cid) { echo '</div>'; return; }

        if (BH_Helpers::is_voting_open($cid)) {
            echo '<div class="notice notice-warning"><p>Voting is still open for this contest — the reveal is locked to the intro slide until you close voting.</p></div>';
        }

        echo '<div id="bh-reveal-preview" style="background:#1a1a1a;color:#fff;border-radius:8px;padding:24px;margin:14px 0;min-height:120px;font-family:sans-serif;"></div>';
        echo '<p>';
        echo '<button type="button" class="button" id="bh-reveal-prev">&larr; Previous</button> ';
        echo '<button type="button" class="button button-primary" id="bh-reveal-next">Next &rarr;</button> ';
        echo '<button type="button" class="button" id="bh-reveal-reset">Reset to start</button>';
        echo '</p>';
        echo '<p class="description">Embed the display on its own page with <code>[bh_results_reveal]</code> — open that page (full-screen, or as an OBS browser source) and it will follow along automatically.</p>';
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
        echo '</div>';
    }

    /* ---------- public display shortcode ---------- */

    public static function render_display_shortcode($atts) {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_results_reveal');
        $cid = $atts['contest'] !== '' ? BH_Helpers::resolve_contest($atts['contest']) : 0;

        ob_start();
        ?>
        <div class="bh-container bh-reveal-stage" id="bh-reveal-stage" data-contest="<?php echo esc_attr($cid); ?>">
            <div class="bh-reveal-loading">Waiting for reveal to start…</div>
        </div>
        <?php
        return ob_get_clean();
    }
}
