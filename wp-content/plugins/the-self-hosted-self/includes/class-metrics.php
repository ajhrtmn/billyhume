<?php
if (!defined('ABSPATH')) exit;

/**
 * Creator metrics dashboard: one shared page where each plugin contributes a KPI card via
 * the bhcore_metrics_widgets filter. Read-only aggregation over bhcore_events.
 *
 * WHY: manage_options only, and widgets show counts/trends, never per-person event rows
 * (individual activity belongs on the CRM person page).
 */
class OUS_Metrics {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    // 'the-self-hosted-self' parent, not 'ous-debug' — same as OUS_MediaWizard (see its docblock).
    public static function add_menu(): void {
        add_submenu_page('ous', 'Metrics', 'Metrics', 'manage_options', 'ous-metrics', [self::class, 'render']);
    }

    /** Total count of a given event type in the last $days days. */
    public static function count_events(string $type, int $days = 30): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . OUS_Tables::events() . " WHERE type = %s AND occurred_at >= %s",
            $type, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }

    /**
     * Day-bucketed counts for the last $days days, 0-filled, chronological: ['Y-m-d' => count].
     *
     * @return array<string, int>
     */
    public static function event_trend(string $type, int $days = 30): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(occurred_at) as d, COUNT(*) as c FROM " . OUS_Tables::events() . "
             WHERE type = %s AND occurred_at >= %s GROUP BY DATE(occurred_at)",
            $type, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ), ARRAY_A);
        $by_day = [];
        foreach ($rows as $r) $by_day[$r['d']] = (int) $r['c'];

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $trend[$day] = $by_day[$day] ?? 0;
        }
        return $trend;
    }

    /**
     * Month-bucketed counts for the last $months months, 0-filled, chronological: ['Y-m' => count].
     *
     * @return array<string, int>
     */
    public static function event_trend_monthly(string $type, int $months = 12): array {
        global $wpdb;
        $since = gmdate('Y-m-01 00:00:00', strtotime("-$months months"));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(occurred_at, '%%Y-%%m') as m, COUNT(*) as c FROM " . OUS_Tables::events() . "
             WHERE type = %s AND occurred_at >= %s GROUP BY m",
            $type, $since
        ), ARRAY_A);
        $by_month = [];
        foreach ($rows as $r) $by_month[$r['m']] = (int) $r['c'];

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = gmdate('Y-m', strtotime("-$i months"));
            $trend[$month] = $by_month[$month] ?? 0;
        }
        return $trend;
    }

    // Tiny inline SVG sparkline, no chart library.
    /**
     * $color defaults to the --bhy-accent token, with a literal fallback so a page without
     * tokens still draws a visible line.
     *
     * @param array<string, int> $trend
     */
    public static function sparkline_svg($trend, string $color = 'var(--bhy-accent, #2271b1)'): string {
        $values = array_values($trend);
        $max = max(1, max($values));
        $count = count($values);
        if ($count < 2) return '';
        $w = 160; $h = 32;
        $points = [];
        foreach ($values as $i => $v) {
            $x = round(($i / ($count - 1)) * $w, 1);
            $y = round($h - ($v / $max) * $h, 1);
            $points[] = "$x,$y";
        }
        return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">'
            . '<polyline points="' . esc_attr(implode(' ', $points)) . '" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>';
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        echo '<div class="wrap"><h1>Metrics</h1>';
        echo '<p class="description">Your own data, your own dashboard — no third-party analytics tool involved. Every number below comes from this site\'s own event log (<code>bhcore_events</code>), aggregate only — never a per-visitor identity lookup.</p>';

        $widgets = apply_filters('bhcore_metrics_widgets', []);
        if (!$widgets) {
            echo '<p>No plugin has registered any metrics widgets yet.</p></div>';
            return;
        }

        // Every value reads a --bhy-* token (BHY_UI) with a hex fallback, so token overrides re-theme this screen.
        echo '<style>
            .ous-metrics-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--bhy-space-4, 16px); margin-top: var(--bhy-space-4, 16px); }
            .ous-metrics-card { background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px); padding: var(--bhy-space-4, 16px) var(--bhy-space-4, 18px); }
            .ous-metrics-card h3 { margin: 0 0 4px; font-size: var(--bhy-text-base, 13px); text-transform: uppercase; letter-spacing: .03em; color: var(--bhy-ink-dim, #646970); }
            .ous-metrics-card .ous-metrics-value { font-size: var(--bhy-text-2xl, 28px); font-weight: 700; color: var(--bhy-ink, #1d2327); line-height: 1.2; }
            .ous-metrics-card .ous-metrics-sub { font-size: var(--bhy-text-sm, 12px); color: var(--bhy-ink-dim, #646970); margin-top: 2px; }
            .ous-metrics-card .ous-metrics-spark { margin-top: var(--bhy-space-2, 8px); }
            .ous-metrics-source { font-size: var(--bhy-text-xs, 11px); color: var(--bhy-ink-dim, #a7aaad); margin-top: var(--bhy-space-3, 10px); text-transform: uppercase; letter-spacing: .03em; }
        </style>';

        echo '<div class="ous-metrics-grid">';
        foreach ($widgets as $widget) {
            if (empty($widget['render']) || !is_callable($widget['render'])) continue;
            echo '<div class="ous-metrics-card">';
            call_user_func($widget['render']);
            if (!empty($widget['source'])) echo '<div class="ous-metrics-source">' . esc_html($widget['source']) . '</div>';
            echo '</div>';
        }
        echo '</div></div>';
    }

    // Shared card body (big number, sparkline, sub-label) for widget render callbacks.
    /**
     * @param mixed $value
     * @param array<string, int>|null $trend
     */
    public static function render_card(string $title, $value, string $sub = '', $trend = null): void {
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<div class="ous-metrics-value">' . esc_html($value) . '</div>';
        if ($sub) echo '<div class="ous-metrics-sub">' . esc_html($sub) . '</div>';
        if ($trend) echo '<div class="ous-metrics-spark">' . self::sparkline_svg($trend) . '</div>';
    }
}
