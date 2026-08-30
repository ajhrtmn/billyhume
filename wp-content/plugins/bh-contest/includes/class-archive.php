<?php
if (!defined('ABSPATH')) exit;

/**
 * The archive — a single, unified library across every contest ever
 * run, not a "pick a contest first" browsing structure. Closer to how
 * an actual streaming service works: everything's in one catalog,
 * filtered by contest/search rather than navigated into contest by
 * contest.
 *
 * Uses the site-wide theme only, never a per-contest override — there's
 * no single "correct" contest to theme this page after since it spans
 * all of them at once.
 *
 * Winner badges only appear for a contest whose results are actually
 * published (same _bh_results_published gate used everywhere else) —
 * an in-progress contest's tracks show up in the library with no
 * placement info at all, never a leaked ranking.
 */
class BH_Archive {
    public static function init(): void {
        add_shortcode('bh_archive', [self::class, 'render_display_shortcode']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('bh/v1', '/library', [
            'methods' => 'GET', 'callback' => [self::class, 'get_library'], 'permission_callback' => '__return_true',
        ]);
    }

    /** @return \WP_REST_Response */
    public static function get_library(\WP_REST_Request $req) {
        $filter_cid = (int) $req->get_param('contest');
        $all_contests = get_posts(['post_type' => 'bh_contest', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC']);
        $target_contests = $filter_cid ? array_values(array_filter($all_contests, fn($c) => $c->ID === $filter_cid)) : $all_contests;

        $out = [];
        foreach ($target_contests as $contest) {
            $cid = $contest->ID;
            $placements = self::compute_placements($cid);
            $subs = get_posts([
                'post_type' => 'bh_submission', 'post_status' => 'publish',
                'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid, 'posts_per_page' => -1,
            ]);
            foreach ($subs as $p) {
                $aid = (int) get_post_meta($p->ID, '_bh_audio_id', true);
                $out[] = [
                    'id' => $p->ID, 'title' => $p->post_title, 'artist' => BH_Helpers::artist_for($p),
                    'url' => $aid ? wp_get_attachment_url($aid) : '',
                    'contest_id' => $cid, 'contest_title' => $contest->post_title,
                    'placements' => $placements[$p->ID] ?? [],
                ];
            }
        }

        return new WP_REST_Response([
            'success'  => true,
            'tracks'   => $out,
            'contests' => array_map(fn($c) => ['id' => $c->ID, 'title' => $c->post_title], $all_contests),
        ], 200);
    }

    // Every medal a submission earned in a contest — category wins and
    // an overall win are both possible for the same track, so this
    // returns a list, not a single result. Empty entirely (not
    // partially withheld) for a contest that hasn't published results.
    /** @return array<int, array<int, string>> */
    private static function compute_placements(int $cid): array {
        if (get_post_meta($cid, '_bh_results_published', true) !== '1') return [];

        $medals = ['🥇', '🥈', '🥉'];
        $placements = [];

        foreach (BH_Helpers::categories($cid) as $cat) {
            foreach (BH_API::category_results($cid, $cat['slug']) as $r) {
                if ($r['rank'] > 3) continue;
                $placements[$r['id']][] = ($medals[$r['rank'] - 1] ?? ('#' . $r['rank'])) . ' ' . $cat['name'];
            }
        }
        foreach (BH_Reveal::overall_results($cid) as $r) {
            if ($r['rank'] > 3) continue;
            $placements[$r['id']][] = ($medals[$r['rank'] - 1] ?? ('#' . $r['rank'])) . ' Overall';
        }

        return $placements;
    }

    // The contests-landing block that sits above the track library: every
    // published contest as a card linking to its own page, grouped so
    // ongoing contests (accepting submissions / voting open) lead, then
    // upcoming, then finished. Server-rendered — no JS, no REST round
    // trip — because this is the part people need to actually find a
    // live contest to enter or vote in. The track library below stays
    // the "everything ever submitted" catalog it already was.
    private static function render_contests_landing(): string {
        $contests = get_posts([
            'post_type' => 'bh_contest', 'post_status' => 'publish',
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        if (!$contests) return '';

        $ongoing_labels  = ['Accepting submissions', 'Voting open'];
        $upcoming_needle = ['soon', 'not scheduled'];
        $groups = ['ongoing' => [], 'upcoming' => [], 'past' => []];

        foreach ($contests as $c) {
            $phase = class_exists('BH_Helpers') && method_exists('BH_Helpers', 'contest_phase_summary')
                ? BH_Helpers::contest_phase_summary($c->ID)
                : ['label' => '', 'color' => '#8a8a8a'];
            $label = (string) ($phase['label'] ?? '');
            if (in_array($label, $ongoing_labels, true)) {
                $bucket = 'ongoing';
            } elseif (array_filter($upcoming_needle, fn($n) => stripos($label, $n) !== false)) {
                $bucket = 'upcoming';
            } else {
                $bucket = 'past';
            }
            $groups[$bucket][] = [
                'title' => get_the_title($c->ID) ?: '(untitled contest)',
                'url'   => get_permalink($c->ID),
                'phase' => $label,
                'color' => (string) ($phase['color'] ?? '#8a8a8a'),
                'excerpt' => wp_trim_words(wp_strip_all_tags((string) $c->post_content), 24, '…'),
            ];
        }

        $section_titles = ['ongoing' => 'Open now', 'upcoming' => 'Coming up', 'past' => 'Past contests'];
        ob_start();
        echo '<div class="bh-contests-landing">';
        foreach ($section_titles as $key => $heading) {
            if (!$groups[$key]) continue;
            echo '<h2 class="bh-contests-landing-heading">' . esc_html($heading) . '</h2>';
            echo '<div class="bh-contests-landing-grid">';
            foreach ($groups[$key] as $item) {
                echo '<a class="bh-contest-card" href="' . esc_url($item['url']) . '">';
                if ($item['phase']) {
                    echo '<span class="bh-contest-card-phase" style="--phase-color:' . esc_attr($item['color']) . '">'
                        . esc_html($item['phase']) . '</span>';
                }
                echo '<span class="bh-contest-card-title">' . esc_html($item['title']) . '</span>';
                if ($item['excerpt']) {
                    echo '<span class="bh-contest-card-excerpt">' . esc_html($item['excerpt']) . '</span>';
                }
                echo '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function render_display_shortcode(): string {
        // Shared catalog structure/elevation, registered by the core plugin.
        // By handle, so this plugin never needs to know where core keeps the
        // file; enqueueing an unregistered handle is a no-op, so a
        // core-absent install degrades to this plugin's own styles.
        wp_enqueue_style('ous-catalog');
        ob_start();
        ?>
        <style><?php echo BHY_Style::inline_css(); ?></style>
        <div class="bh-container bh-archive" id="bh-archive-root">
            <div class="bh-header">
                <div class="bh-brand">Contests</div>
            </div>
            <?php echo self::render_contests_landing(); ?>
            <h2 class="bh-contests-landing-heading">Track library</h2>
            <div class="bh-archive-controls ous-catalog-controls">
                <input type="text" id="bh-archive-search" placeholder="Search title or artist…">
                <select id="bh-archive-filter"><option value="">All contests</option></select>
            </div>
            <div id="bh-archive-grid" class="bh-archive-grid ous-catalog-grid">
                <p class="bh-empty">Loading…</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
