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
    public static function init() {
        add_shortcode('bh_archive', [self::class, 'render_display_shortcode']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('bh/v1', '/library', [
            'methods' => 'GET', 'callback' => [self::class, 'get_library'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function get_library($req) {
        $filter_cid = (int) $req->get_param('contest');
        $all_contests = get_posts(['post_type' => 'bh_contest', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC']);
        $target_contests = $filter_cid ? array_values(array_filter($all_contests, fn($c) => $c->ID === $filter_cid)) : $all_contests;

        $out = [];
        foreach ($target_contests as $contest) {
            $cid = $contest->ID;
            $placements = self::compute_placements($cid);
            $subs = get_posts([
                'post_type' => 'bh_submission', 'post_status' => 'publish',
                'meta_key' => '_bh_contest_id', 'meta_value' => $cid, 'posts_per_page' => -1,
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
    private static function compute_placements($cid) {
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

    public static function render_display_shortcode() {
        ob_start();
        ?>
        <style><?php echo BHY_Style::inline_css(); ?></style>
        <div class="bh-container bh-archive" id="bh-archive-root">
            <div class="bh-header">
                <div class="bh-brand">Archive</div>
            </div>
            <div class="bh-archive-controls">
                <input type="text" id="bh-archive-search" placeholder="Search title or artist…">
                <select id="bh-archive-filter"><option value="">All contests</option></select>
            </div>
            <div id="bh-archive-grid" class="bh-archive-grid">
                <p class="bh-empty">Loading…</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
