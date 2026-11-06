<?php
if (!defined('ABSPATH')) exit;

/**
 * Artist-facing aggregate metrics: plays over time, top tracks, Jam
 * skip-rate, and region/referrer. Aggregate only: (day, dimension)
 * counters, never per-listener rows. Region comes from Accept-Language,
 * not GeoIP, and is labeled as rough everywhere it's shown.
 */
class BHS_Stats {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_admin_page']);
        // BH_Event registration, in addition to the bhs_daily_stats rollup.
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhs/play', ['track_id' => 'int']);
            BH_Event::register_event_type('bhs/skip', ['track_id' => 'int']);
        }
    }

    private static function table(): string { return BHS_Tables::daily_stats(); }

    // Called per allowed play (class-api.php record_play()) and per executed
    // Jam vote-skip; `metric` distinguishes 'play' from 'skip'.
    public static function record_play(int $track_id, \WP_REST_Request $req): void {
        self::bump($track_id, 'play', $req);
        // Also records a per-event bhcore_events row; this table stays the
        // fast dashboard rollup.
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhs/play', [
                'subject_type' => 'bhs_track',
                'subject_id'   => (int) $track_id,
            ]);
        }
    }

    public static function record_skip(int $track_id): void {
        self::bump($track_id, 'skip', null);
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhs/skip', [
                'subject_type' => 'bhs_track',
                'subject_id'   => (int) $track_id,
            ]);
        }
    }

    private static function bump(int $track_id, string $metric, ?\WP_REST_Request $req): void {
        global $wpdb;
        $today = current_time('Y-m-d');
        $country = $req ? self::guess_country($req) : 'unknown';
        $referrer = $req ? self::classify_referrer($req) : 'unknown';

        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (stat_date, track_id, metric, country, referrer_bucket, count)
             VALUES (%s, %d, %s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $today, $track_id, $metric, $country, $referrer
        ));
    }

    // Rough region proxy: the first Accept-Language tag (locale, not location).
    private static function guess_country(\WP_REST_Request $req): string {
        $header = $req->get_header('accept_language') ?: ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if (!$header) return 'unknown';
        if (preg_match('/^[a-zA-Z]{2}-([A-Za-z]{2})/', trim(explode(',', $header)[0]), $m)) {
            return strtoupper($m[1]);
        }
        return 'unknown';
    }

    private static function classify_referrer(\WP_REST_Request $req): string {
        $ref = $req->get_header('referer') ?: ($_SERVER['HTTP_REFERER'] ?? '');
        if (!$ref) return 'direct';
        if (strpos($ref, 'bh_shared_playlist=') !== false) return 'shared_playlist';
        if (strpos($ref, 'bh_jam') !== false || strpos($ref, 'jam=') !== false) return 'jam';
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $ref_host = wp_parse_url($ref, PHP_URL_HOST);
        if ($site_host && $ref_host && $site_host !== $ref_host) return 'external';
        return 'on_site';
    }

    /* ---------- admin dashboard ---------- */

    public static function add_admin_page(): void {
        add_submenu_page(
            BHS_PostTypes::MENU_PARENT, 'Metrics', 'Metrics', 'edit_posts', 'bhs-metrics', [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        global $wpdb;
        $days = 30;
        $since = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);

        $by_day = $wpdb->get_results($wpdb->prepare(
            "SELECT stat_date, SUM(count) as plays FROM " . self::table() . "
             WHERE metric = 'play' AND stat_date >= %s GROUP BY stat_date ORDER BY stat_date ASC", $since
        ));
        $top_tracks = $wpdb->get_results($wpdb->prepare(
            "SELECT track_id, SUM(count) as plays FROM " . self::table() . "
             WHERE metric = 'play' AND stat_date >= %s GROUP BY track_id ORDER BY plays DESC LIMIT 10", $since
        ));
        $skips = $wpdb->get_results($wpdb->prepare(
            "SELECT track_id, SUM(count) as skips FROM " . self::table() . "
             WHERE metric = 'skip' AND stat_date >= %s GROUP BY track_id ORDER BY skips DESC LIMIT 10", $since
        ));
        $by_country = $wpdb->get_results($wpdb->prepare(
            "SELECT country, SUM(count) as plays FROM " . self::table() . "
             WHERE metric = 'play' AND stat_date >= %s GROUP BY country ORDER BY plays DESC LIMIT 15", $since
        ));
        $by_referrer = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer_bucket, SUM(count) as plays FROM " . self::table() . "
             WHERE metric = 'play' AND stat_date >= %s GROUP BY referrer_bucket ORDER BY plays DESC", $since
        ));

        // D3 charts (vendored) render these server-aggregated rows via stats-charts.js.
        wp_enqueue_script('bhs-d3', BHS_URL . 'assets/js/vendor/d3.min.js', [], '7.9.0', true);
        wp_enqueue_script('bhs-stats-charts', BHS_URL . 'assets/js/stats-charts.js', ['bhs-d3'], BHS_VER, true);

        echo '<div class="wrap"><h1>Metrics</h1><p class="description">Last ' . (int) $days . ' days. All figures below are aggregate counts — no per-listener data is collected or shown anywhere here.</p>';

        echo '<h2>Plays per day</h2>';
        if (!$by_day) {
            echo '<p>No plays recorded yet in this window.</p>';
        } else {
            $chart_days = array_map(fn($r) => ['date' => $r->stat_date, 'value' => (int) $r->plays], $by_day);
            echo '<div id="bhs-chart-plays-per-day" data-chart="' . esc_attr(wp_json_encode($chart_days)) . '" style="max-width:760px;"></div>';
        }

        echo '<h2>Top tracks</h2><div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Track</th><th>Plays</th></tr></thead><tbody>';
        foreach ($top_tracks as $row) {
            $title = get_the_title($row->track_id) ?: ('Track #' . $row->track_id);
            echo '<tr><td>' . esc_html($title) . '</td><td>' . (int) $row->plays . '</td></tr>';
        }
        if (!$top_tracks) echo '<tr><td colspan="2">No plays recorded yet.</td></tr>';
        echo '</tbody></table></div>';

        echo '<h2>Most skipped (Jam vote-skips)</h2><div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Track</th><th>Skips</th></tr></thead><tbody>';
        foreach ($skips as $row) {
            $title = get_the_title($row->track_id) ?: ('Track #' . $row->track_id);
            echo '<tr><td>' . esc_html($title) . '</td><td>' . (int) $row->skips . '</td></tr>';
        }
        if (!$skips) echo '<tr><td colspan="2">No Jam vote-skips recorded yet.</td></tr>';
        echo '</tbody></table></div>';

        echo '<h2>Listener region (approximate — from browser language, not true GeoIP)</h2>';
        if (!$by_country) {
            echo '<p>No data yet.</p>';
        } else {
            $chart_region = array_map(fn($r) => ['country' => $r->country, 'plays' => (int) $r->plays], $by_country);
            echo '<div id="bhs-chart-region" data-chart="' . esc_attr(wp_json_encode($chart_region)) . '" style="max-width:760px;"></div>';
        }

        echo '<h2>How listeners got here</h2>';
        $labels = ['shared_playlist' => 'Shared playlist link', 'jam' => 'Jam invite', 'on_site' => 'This site', 'external' => 'Another site', 'direct' => 'Direct / no referrer', 'unknown' => 'Unknown'];
        if (!$by_referrer) {
            echo '<p>No data yet.</p>';
        } else {
            $chart_referrer = array_map(fn($r) => ['label' => $labels[$r->referrer_bucket] ?? $r->referrer_bucket, 'plays' => (int) $r->plays], $by_referrer);
            echo '<div id="bhs-chart-referrer" data-chart="' . esc_attr(wp_json_encode($chart_referrer)) . '" style="max-width:760px;"></div>';
        }
        echo '</div>';
    }
}
