<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-side vote/score visualisation for a contest — pie (donut) charts
 * of where the votes went, rendered as plain inline SVG (no chart
 * library: the ecosystem ships nothing at runtime it doesn't own).
 *
 * Three breakdowns, per Billy's ask:
 *   - whole contest, slices = categories   (which category drew engagement)
 *   - whole contest, slices = songs        (the "Overall" vote share)
 *   - one pie per category, slices = songs (the split within each category)
 * plus, on a judged / hybrid contest, the same shapes sized by summed
 * rubric score instead of raw votes.
 *
 * render_section() is shared by the Contest Results page
 * (BH_AdminReports) and a metabox on the contest editor
 * (BH_AdminMetaboxes). Server-rendered snapshot — reload to refresh;
 * the Results page's own live table already covers second-by-second.
 */
class BH_Charts {

    /** Slices past this many collapse into a single "Other" wedge. */
    const MAX_SLICES = 8;

    /** @return array<int, string> 8 category-palette hex values */
    private static function palette(): array {
        if (class_exists('BHY_Style')) {
            $s = BHY_Style::get();
            $out = [];
            for ($i = 1; $i <= 8; $i++) {
                $hex = (string) ($s['cat_color_' . $i] ?? '');
                if ($hex !== '') $out[] = $hex;
            }
            if (count($out) === 8) return $out;
        }
        return ['#C1503A', '#D9A441', '#B8785A', '#8C3B2E', '#C98B5E', '#A66A4D', '#D96C4D', '#7A4A38'];
    }

    private static function votes_table(): string {
        return class_exists('BHCON_Tables') ? BHCON_Tables::votes() : (class_exists('BH_Helpers') ? BH_Helpers::table() : '');
    }

    /* ---------------- data ---------------- */

    /**
     * [{label, value, color}] — one wedge per category, sized by total
     * votes cast in it. Categories keep their palette slot by index so
     * the colour is stable across every chart on the page.
     * @return array<int, array{label:string,value:float,color:string}>
     */
    public static function vote_slices_by_category(int $cid, ?int $round = null): array {
        global $wpdb;
        $t = self::votes_table();
        if (!$t) return [];
        $cats = BH_Helpers::categories($cid);
        $pal  = self::palette();
        $round_sql = $round !== null ? $wpdb->prepare(' AND round = %d', $round) : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(id) v FROM $t WHERE contest_id = %d$round_sql GROUP BY category",
            $cid
        ));
        $by_slug = [];
        foreach ($rows as $r) $by_slug[(string) $r->category] = (int) $r->v;

        $slices = [];
        if ($cats) {
            foreach ($cats as $i => $c) {
                $slices[] = [
                    'label' => (string) $c['name'],
                    'value' => (float) ($by_slug[$c['slug']] ?? 0),
                    'color' => $pal[$i % 8],
                ];
            }
            // Votes recorded against a since-removed category slug.
            $known = wp_list_pluck($cats, 'slug');
            $orphan = 0;
            foreach ($by_slug as $slug => $v) if ($slug !== '' && !in_array($slug, $known, true)) $orphan += $v;
            if ($orphan > 0) $slices[] = ['label' => __('(removed categories)', 'bh-contest'), 'value' => (float) $orphan, 'color' => '#7A7A7A'];
        } else {
            $slices[] = ['label' => __('All votes', 'bh-contest'), 'value' => (float) ($by_slug[''] ?? array_sum($by_slug)), 'color' => $pal[0]];
        }
        return $slices;
    }

    /**
     * [{label, value}] — one wedge per song. $cat_slug null = whole
     * contest; otherwise just that category. Top MAX_SLICES by votes,
     * the rest folded into "Other".
     * @return array<int, array{label:string,value:float,color?:string}>
     */
    public static function vote_slices_by_song(int $cid, ?int $round = null, ?string $cat_slug = null): array {
        global $wpdb;
        $t = self::votes_table();
        if (!$t) return [];
        $where = $wpdb->prepare('contest_id = %d', $cid);
        if ($cat_slug !== null) $where .= $wpdb->prepare(' AND category = %s', $cat_slug);
        if ($round !== null)    $where .= $wpdb->prepare(' AND round = %d', $round);

        $rows = $wpdb->get_results(
            "SELECT submission_id, COUNT(id) v FROM $t WHERE $where GROUP BY submission_id ORDER BY v DESC"
        );
        return self::songs_to_slices($rows);
    }

    /** @return array<int, array{label:string,value:float,color:string}> */
    public static function judge_slices_by_category(int $cid, ?int $round = null): array {
        if (!class_exists('BH_Judging')) return [];
        $cats = BH_Helpers::categories($cid);
        $pal  = self::palette();
        $slices = [];
        foreach (($cats ?: [['name' => __('All entries', 'bh-contest'), 'slug' => '']]) as $i => $c) {
            $results = BH_Judging::judge_results($cid, (string) $c['slug'], $round);
            $sum = 0.0;
            foreach ($results as $r) $sum += (float) $r['votes']; // 'votes' carries the 0-100 score here
            if ($sum > 0) $slices[] = ['label' => (string) $c['name'], 'value' => $sum, 'color' => $pal[$i % 8]];
        }
        return $slices;
    }

    /** @return array<int, array{label:string,value:float,color?:string}> */
    public static function judge_slices_by_song(int $cid, ?int $round = null, ?string $cat_slug = null): array {
        if (!class_exists('BH_Judging')) return [];
        $cats = $cat_slug !== null ? [['slug' => $cat_slug]] : (BH_Helpers::categories($cid) ?: [['slug' => '']]);
        $by_song = []; // id => ['label' => .., 'value' => ..]
        foreach ($cats as $c) {
            foreach (BH_Judging::judge_results($cid, (string) $c['slug'], $round) as $r) {
                $id = (int) $r['id'];
                if (!isset($by_song[$id])) {
                    $by_song[$id] = ['label' => self::song_label($r['title'], $r['artist']), 'value' => 0.0];
                }
                $by_song[$id]['value'] += (float) $r['votes'];
            }
        }
        uasort($by_song, fn($a, $b) => $b['value'] <=> $a['value']);
        return self::cap_slices(array_values($by_song));
    }

    /* ---------------- data helpers ---------------- */

    /** @param array<int, object> $rows submission_id + v */
    private static function songs_to_slices(array $rows): array {
        $slices = [];
        foreach ($rows as $r) {
            $p = get_post((int) $r->submission_id);
            if (!$p || $p->post_status !== 'publish') continue;
            $slices[] = [
                'label' => self::song_label($p->post_title, BH_Helpers::artist_for($p)),
                'value' => (float) $r->v,
            ];
        }
        return self::cap_slices($slices);
    }

    /** Fold everything past MAX_SLICES into one "Other (N)" wedge. */
    private static function cap_slices(array $slices): array {
        if (count($slices) <= self::MAX_SLICES) return $slices;
        $keep = array_slice($slices, 0, self::MAX_SLICES - 1);
        $rest = array_slice($slices, self::MAX_SLICES - 1);
        $sum = 0.0;
        foreach ($rest as $s) $sum += $s['value'];
        $keep[] = [
            'label' => sprintf(_n('%d more', '%d more', count($rest), 'bh-contest'), count($rest)),
            'value' => $sum,
            'color' => '#7A7A7A',
        ];
        return $keep;
    }

    private static function song_label(string $title, string $artist): string {
        $label = $artist !== '' ? "$title — $artist" : $title;
        return mb_strlen($label) > 42 ? mb_substr($label, 0, 41) . '…' : $label;
    }

    /* ---------------- rendering ---------------- */

    /**
     * One donut + its legend.
     * @param array<int, array{label:string,value:float,color?:string}> $slices
     */
    public static function pie(array $slices, string $center_label = '', string $aria = ''): string {
        self::print_style_once();
        $pal   = self::palette();
        $total = 0.0;
        foreach ($slices as $s) $total += max(0.0, (float) $s['value']);

        $r = 70.0;
        $c = 2 * M_PI * $r;
        $svg = '<svg class="bh-chart-svg" viewBox="0 0 180 180" role="img" aria-label="' . esc_attr($aria ?: __('Vote breakdown', 'bh-contest')) . '">';

        if ($total <= 0) {
            $svg .= '<circle cx="90" cy="90" r="' . $r . '" fill="none" stroke="var(--bhy-border,#d7d7d7)" stroke-width="26"/>';
            $svg .= '<text x="90" y="95" text-anchor="middle" class="bh-chart-center-empty">' . esc_html__('no data', 'bh-contest') . '</text>';
            $svg .= '</svg>';
            return '<div class="bh-chart"><div class="bh-chart-pie">' . $svg . '</div></div>';
        }

        $offset = 0.0;
        $rows_html = '';
        foreach ($slices as $i => $s) {
            $val = max(0.0, (float) $s['value']);
            if ($val <= 0) continue;
            $frac  = $val / $total;
            $len   = $frac * $c;
            $color = isset($s['color']) && $s['color'] !== '' ? $s['color'] : $pal[$i % 8];
            $svg .= '<circle cx="90" cy="90" r="' . $r . '" fill="none"'
                  . ' stroke="' . esc_attr($color) . '" stroke-width="26"'
                  . ' stroke-dasharray="' . round($len, 3) . ' ' . round($c - $len, 3) . '"'
                  . ' stroke-dashoffset="' . round(-$offset, 3) . '"'
                  . ' transform="rotate(-90 90 90)">'
                  . '<title>' . esc_html($s['label'] . ': ' . self::num($val) . ' (' . round($frac * 100) . '%)') . '</title>'
                  . '</circle>';
            $offset += $len;
            $rows_html .= '<li><span class="bh-chart-swatch" style="background:' . esc_attr($color) . '"></span>'
                        . '<span class="bh-chart-legend-label">' . esc_html($s['label']) . '</span>'
                        . '<span class="bh-chart-legend-val">' . esc_html(self::num($val)) . '</span>'
                        . '<span class="bh-chart-legend-pct">' . round($frac * 100) . '%</span></li>';
        }
        if ($center_label !== '') {
            $svg .= '<text x="90" y="86" text-anchor="middle" class="bh-chart-center-num">' . esc_html(self::num($total)) . '</text>';
            $svg .= '<text x="90" y="104" text-anchor="middle" class="bh-chart-center-lbl">' . esc_html($center_label) . '</text>';
        }
        $svg .= '</svg>';

        return '<div class="bh-chart"><div class="bh-chart-pie">' . $svg . '</div>'
             . '<ul class="bh-chart-legend">' . $rows_html . '</ul></div>';
    }

    private static function num(float $v): string {
        return abs($v - round($v)) < 0.05 ? number_format_i18n(round($v)) : number_format_i18n($v, 1);
    }

    /**
     * The whole visualisation block for one contest.
     * $opts: ['compact' => bool, 'round' => ?int, 'heading' => bool]
     */
    public static function render_section(int $cid, array $opts = []): void {
        $compact = !empty($opts['compact']);
        $round   = array_key_exists('round', $opts) ? $opts['round'] : null;
        $format  = class_exists('BH_Helpers') ? BH_Helpers::contest_format($cid) : 'public';
        $cats    = BH_Helpers::categories($cid);

        $cat_slices  = self::vote_slices_by_category($cid, $round);
        $song_slices = self::vote_slices_by_song($cid, $round);
        $has_votes   = array_sum(wp_list_pluck($cat_slices, 'value')) > 0 || array_sum(wp_list_pluck($song_slices, 'value')) > 0;

        self::print_style_once();
        echo '<div class="bh-charts">';
        if (!empty($opts['heading'])) echo '<h2 class="bh-charts-title">' . esc_html__('Vote breakdown', 'bh-contest') . '</h2>';
        echo '<p class="bh-charts-note">' . esc_html__('Snapshot as of this page load — reload to refresh.', 'bh-contest')
           . ($round !== null ? ' ' . esc_html(sprintf(__('Round %d only.', 'bh-contest'), $round)) : '') . '</p>';

        if (!$has_votes && !in_array($format, ['judges', 'hybrid'], true)) {
            echo '<p class="bh-charts-empty">' . esc_html__('No votes have been cast yet.', 'bh-contest') . '</p></div>';
            return;
        }

        // --- overview row ---
        echo '<div class="bh-charts-row">';
        if ($cats) {
            echo self::labelled(self::pie($cat_slices, __('votes', 'bh-contest'), __('Votes by category', 'bh-contest')), __('By category', 'bh-contest'));
        }
        echo self::labelled(self::pie($song_slices, __('votes', 'bh-contest'), __('Votes by song', 'bh-contest')), __('By song (overall)', 'bh-contest'));
        echo '</div>';

        if ($compact) {
            $url = add_query_arg(['post_type' => 'bh_contest', 'page' => 'bh-results', 'contest_id' => $cid], admin_url('edit.php'));
            echo '<p class="bh-charts-more"><a href="' . esc_url($url) . '">' . esc_html__('Full breakdown, per category →', 'bh-contest') . '</a></p></div>';
            return;
        }

        // --- one pie per category ---
        if ($cats && count($cats) > 1) {
            echo '<h3 class="bh-charts-subtitle">' . esc_html__('Vote split within each category', 'bh-contest') . '</h3>';
            echo '<div class="bh-charts-grid">';
            foreach ($cats as $c) {
                $slices = self::vote_slices_by_song($cid, $round, (string) $c['slug']);
                echo self::labelled(self::pie($slices, __('votes', 'bh-contest'), sprintf(__('Votes in %s', 'bh-contest'), $c['name'])), (string) $c['name']);
            }
            echo '</div>';
        }

        // --- judge scores ---
        if (in_array($format, ['judges', 'hybrid'], true)) {
            $j_cat  = self::judge_slices_by_category($cid, $round);
            $j_song = self::judge_slices_by_song($cid, $round);
            if (array_sum(wp_list_pluck($j_cat, 'value')) > 0 || array_sum(wp_list_pluck($j_song, 'value')) > 0) {
                echo '<h3 class="bh-charts-subtitle">' . esc_html__('Judge scores', 'bh-contest')
                   . ' <span class="bh-charts-hint">' . esc_html__('(share of total rubric points)', 'bh-contest') . '</span></h3>';
                echo '<div class="bh-charts-row">';
                if ($cats) echo self::labelled(self::pie($j_cat, __('pts', 'bh-contest'), __('Judge points by category', 'bh-contest')), __('By category', 'bh-contest'));
                echo self::labelled(self::pie($j_song, __('pts', 'bh-contest'), __('Judge points by song', 'bh-contest')), __('By song (overall)', 'bh-contest'));
                echo '</div>';
            }
        }

        echo '</div>';
    }

    private static function labelled(string $inner, string $label): string {
        return '<figure class="bh-chart-fig"><figcaption>' . esc_html($label) . '</figcaption>' . $inner . '</figure>';
    }

    /* ---------------- style (scoped, printed once) ---------------- */

    private static bool $style_done = false;

    private static function print_style_once(): void {
        if (self::$style_done) return;
        self::$style_done = true;
        echo '<style id="bh-charts-css">'
           . '.bh-charts{margin:18px 0}'
           . '.bh-charts-title{font-size:16px;margin:0 0 4px}'
           . '.bh-charts-note,.bh-charts-empty{color:var(--bhy-ink-dim,#666);font-size:12px;margin:0 0 12px}'
           . '.bh-charts-subtitle{font-size:14px;margin:22px 0 10px;border-top:1px solid var(--bhy-border,#e0e0e0);padding-top:14px}'
           . '.bh-charts-hint{font-weight:400;color:var(--bhy-ink-dim,#666);font-size:12px}'
           . '.bh-charts-row{display:flex;flex-wrap:wrap;gap:26px}'
           . '.bh-charts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px}'
           . '.bh-charts-more{margin:12px 0 0;font-size:13px}'
           . '.bh-chart-fig{margin:0;min-width:230px}'
           . '.bh-chart-fig figcaption{font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--bhy-ink-dim,#666);margin-bottom:8px}'
           . '.bh-chart{display:flex;gap:14px;align-items:center;flex-wrap:wrap}'
           . '.bh-chart-pie{flex:0 0 128px}'
           . '.bh-chart-svg{width:128px;height:128px;display:block}'
           . '.bh-chart-center-num{font-size:26px;font-weight:700;fill:var(--bhy-ink,#1e1e1e)}'
           . '.bh-chart-center-lbl{font-size:11px;fill:var(--bhy-ink-dim,#777);text-transform:uppercase;letter-spacing:.04em}'
           . '.bh-chart-center-empty{font-size:13px;fill:var(--bhy-ink-dim,#999)}'
           . '.bh-chart-legend{list-style:none;margin:0;padding:0;font-size:12px;min-width:150px;flex:1}'
           . '.bh-chart-legend li{display:grid;grid-template-columns:12px 1fr auto auto;gap:6px;align-items:center;padding:2px 0}'
           . '.bh-chart-swatch{width:10px;height:10px;border-radius:2px;display:inline-block}'
           . '.bh-chart-legend-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--bhy-ink,#1e1e1e)}'
           . '.bh-chart-legend-val{font-variant-numeric:tabular-nums;font-weight:600}'
           . '.bh-chart-legend-pct{font-variant-numeric:tabular-nums;color:var(--bhy-ink-dim,#777);min-width:34px;text-align:right}'
           . '</style>';
    }
}
