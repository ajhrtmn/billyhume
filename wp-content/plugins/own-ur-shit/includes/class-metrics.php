<?php
if (!defined('ABSPATH')) exit;

/**
 * A real creator dashboard rather than per-plugin stats pages: a
 * bhcore_metrics_widgets filter, one shared dashboard page, each
 * plugin contributing its own KPI card. Foundational infrastructure
 * meant to grow in tandem with bh-courses/bh-contest/bh-crm, not a
 * bolt-on.
 *
 * Reads `bhcore_events` (BH_Event's table — see class-event.php), the
 * self-hosted event-tracking envelope every GA/Meta-Pixel/Segment
 * replacement in this ecosystem emits into. This class adds nothing
 * new to WRITE that table — it's purely a read/aggregate layer, so it
 * can't introduce a new place tracking data could diverge from what
 * BH_Event already records.
 *
 * Security posture: gated to `manage_options` only, same as every
 * other cross-cutting admin surface in this ecosystem — this is the
 * site owner's own aggregate view of their audience's activity, never
 * a per-visitor identity lookup. Widgets are expected to render
 * COUNTS/TRENDS, not raw per-user event rows — a widget needing an
 * individual person's activity belongs on that person's CRM detail
 * page (bh-crm), not this aggregate dashboard.
 *
 * Purpose: not vanity numbers or competitive pressure against other
 * artists, but understanding how an artist's own community engages
 * with their content. That's why event_trend_monthly() exists
 * alongside the daily event_trend() — a single day spiking or dipping
 * isn't the story; whether a community is actually growing over
 * months is. Two related ideas deliberately NOT built here yet: (1)
 * benchmarking a creator's numbers against the global market — only
 * buildable without a surveillance/leaderboard dynamic if opt-in and
 * anonymized, same constraint as federated cross-instance metrics;
 * (2) richer engagement views beyond simple counts (return-visitor
 * patterns, content-type affinity) — a Phase 2, not a same-day add.
 */
class OUS_Metrics {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    // Same proven-safe parent as OUS_MediaWizard/BHI_Reports — see
    // class-media-wizard.php's own docblock for why 'own-ur-shit' is
    // used instead of 'ous-debug' on this specific install.
    public static function add_menu(): void {
        add_submenu_page('ous', 'Metrics', 'Metrics', 'manage_options', 'ous-metrics', [self::class, 'render']);
    }

    /**
     * Total count of a given event type in the last $days days.
     * The one query every simple "N somethings this month" KPI card
     * needs — shared here so six plugins don't each write a slightly-
     * different version of the same COUNT(*)...WHERE type=...AND
     * occurred_at >= ... query.
     */
    public static function count_events(string $type, int $days = 30): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . OUS_Tables::events() . " WHERE type = %s AND occurred_at >= %s",
            $type, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }

    /**
     * Day-bucketed counts for the last $days days, always returning
     * one entry per day (0-filled, never a gap) — the shape a simple
     * sparkline/bar-chart renderer needs without doing its own gap-
     * filling. Returns ['Y-m-d' => count, ...] in chronological order.
     */
    /** @return array<string, int> */
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
     * Month-bucketed counts for the last $months months — the long-
     * pattern counterpart to event_trend()'s daily view. A 30-day daily
     * sparkline answers "is this week unusual," not "is this actually
     * growing." Always 0-filled, same gap-free contract as
     * event_trend(). Returns ['Y-m' => count, ...] in chronological
     * order.
     */
    /** @return array<string, int> */
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

    // A tiny inline SVG sparkline — zero external chart library
    // dependency, matching this ecosystem's own no-external-runtime
    // convention (see the API Docs viewer's own docblock for the same
    // instinct applied earlier). Good enough for "is this trending up
    // or down at a glance," not meant to replace a real charting tool
    // for deeper analysis later.
    /**
     * $color defaults to the shared --bhy-accent token rather than a
     * hardcoded #2271b1. An SVG `stroke` attribute accepts a var()
     * reference the same as any CSS value, so this inherits whatever
     * the active token set defines — the admin skin previously had to
     * override this exact polyline's stroke from its own stylesheet to
     * theme it, which is no longer necessary. The literal is retained
     * as the var() fallback so a context with no tokens loaded still
     * renders a visible line rather than defaulting to black.
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

        // Every value below reads a --bhy-* token (BHY_UI::
        // print_design_system_css(), class-ui.php) with the previous
        // hardcoded hex kept as its fallback — identical rendering on a
        // stock install, but this block now participates in the shared
        // token system instead of being a parallel one. It previously
        // hardcoded WP core's light palette (#fff/#dcdcde/#646970),
        // which is why the admin skin had to override these exact class
        // names from the outside to theme them; consuming the tokens
        // means anything that redefines them re-themes this screen for
        // free, with no knowledge of this file.
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

    // Shared card-body renderer every widget's own render callback can
    // reuse — one consistent look (big number, trend sparkline,
    // sub-label) instead of six plugins each hand-rolling the same
    // card markup slightly differently.
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
