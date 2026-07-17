<?php
if (!defined('ABSPATH')) exit;

/**
 * Artist-facing aggregate metrics — plays over time, top tracks, a
 * skip-rate signal (from Jam vote-skips), and a rough geo/referrer
 * breakdown. Deliberately AGGREGATE ONLY: every row this writes is a
 * (day, dimension) counter, never a per-listener record — nothing here
 * is a new privacy surface, it's a dashboard built entirely on data
 * this plugin already generates (bhs_play_count) or that a play/skip
 * event already carries in the request (referrer, Accept-Language).
 *
 * Geo is explicitly NOT true GeoIP — this ecosystem has no IP-to-
 * location database and isn't taking on that dependency for a "nice to
 * have" dashboard. Country is approximated from the Accept-Language
 * header, labeled as such everywhere it's shown, which is honest about
 * being a rough signal (a VPN or a browser set to English doesn't mean
 * "this listener is in the US") rather than pretending to precision
 * this plugin doesn't have.
 */
class BHS_Stats {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_admin_page']);
        // BH_Event registration, per EVENT-TRACKING-ARCHITECTURE-PLAN.md
        // Section 6 — additive, does not replace this class's own
        // aggregate bhs_daily_stats rollup below.
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhs/play', ['track_id' => 'int']);
            BH_Event::register_event_type('bhs/skip', ['track_id' => 'int']);
        }
    }

    private static function table() { global $wpdb; return $wpdb->prefix . 'bhs_daily_stats'; }

    // Called once per allowed play (see class-api.php's record_play())
    // and once per executed Jam vote-skip (see class-jam.php). One
    // shared table, a `metric` column distinguishing 'play' from
    // 'skip' so both live in the same simple rollup rather than two
    // near-identical tables.
    public static function record_play($track_id, $req) {
        self::bump($track_id, 'play', $req);
        // Real per-event record alongside the aggregate rollup above —
        // both coexist (see EVENT-TRACKING-ARCHITECTURE-PLAN.md Section
        // 6): this table stays the fast rollup for the artist dashboard,
        // bhcore_events becomes the first per-listener, identity-
        // joinable record of the same activity.
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhs/play', [
                'subject_type' => 'bhs_track',
                'subject_id'   => (int) $track_id,
            ]);
        }
    }

    public static function record_skip($track_id) {
        self::bump($track_id, 'skip', null);
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhs/skip', [
                'subject_type' => 'bhs_track',
                'subject_id'   => (int) $track_id,
            ]);
        }
    }

    private static function bump($track_id, $metric, $req) {
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

    // Rough, explicitly-labeled-as-rough proxy for "what region are our
    // listeners roughly in" without adding a GeoIP dependency — the
    // first language tag in Accept-Language, which is at least real
    // signal (a listener's own browser/OS locale) rather than fabricated
    // data, just not the same thing as their actual location.
    private static function guess_country($req) {
        $header = $req->get_header('accept_language') ?: ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if (!$header) return 'unknown';
        if (preg_match('/^[a-zA-Z]{2}-([A-Za-z]{2})/', trim(explode(',', $header)[0]), $m)) {
            return strtoupper($m[1]);
        }
        return 'unknown';
    }

    private static function classify_referrer($req) {
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

    public static function add_admin_page() {
        add_submenu_page(
            BHS_PostTypes::MENU_PARENT, 'Metrics', 'Metrics', 'edit_posts', 'bhs-metrics', [self::class, 'render']
        );
    }

    public static function render() {
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

        echo '<div class="wrap"><h1>Metrics</h1><p class="description">Last ' . (int) $days . ' days. All figures below are aggregate counts — no per-listener data is collected or shown anywhere here.</p>';

        echo '<h2>Plays per day</h2>';
        if (!$by_day) { echo '<p>No plays recorded yet in this window.</p>'; }
        else {
            $max = max(array_map(fn($r) => (int) $r->plays, $by_day)) ?: 1;
            echo '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid #ccc;padding-bottom:4px;">';
            foreach ($by_day as $row) {
                $h = max(2, round(((int) $row->plays / $max) * 110));
                echo '<div title="' . esc_attr($row->stat_date . ': ' . $row->plays . ' plays') . '" style="width:8px;height:' . (int) $h . 'px;background:#C1503A;"></div>';
            }
            echo '</div>';
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

        echo '<h2>Listener region (approximate — from browser language, not true GeoIP)</h2><div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Region</th><th>Plays</th></tr></thead><tbody>';
        foreach ($by_country as $row) {
            echo '<tr><td>' . esc_html($row->country) . '</td><td>' . (int) $row->plays . '</td></tr>';
        }
        if (!$by_country) echo '<tr><td colspan="2">No data yet.</td></tr>';
        echo '</tbody></table></div>';

        echo '<h2>How listeners got here</h2><div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Source</th><th>Plays</th></tr></thead><tbody>';
        $labels = ['shared_playlist' => 'Shared playlist link', 'jam' => 'Jam invite', 'on_site' => 'This site', 'external' => 'Another site', 'direct' => 'Direct / no referrer', 'unknown' => 'Unknown'];
        foreach ($by_referrer as $row) {
            echo '<tr><td>' . esc_html($labels[$row->referrer_bucket] ?? $row->referrer_bucket) . '</td><td>' . (int) $row->plays . '</td></tr>';
        }
        if (!$by_referrer) echo '<tr><td colspan="2">No data yet.</td></tr>';
        echo '</tbody></table></div></div>';
    }
}
