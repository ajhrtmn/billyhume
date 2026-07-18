<?php
if (!defined('ABSPATH') ) exit;

/**
 * OUS_Search — unified site-wide search, scoped in
 * ROADMAP-search-and-revisions.md Section 1. WordPress core's own `?s=`
 * search only ever covered bh_course/bh_lesson (the one CPT registered
 * `'public' => true`) — contests, tracks/releases, tiers, CRM people,
 * and registry artists were all either `'public' => false` by design or
 * (bh-crm, bh-registry) not a post type at all, so no single query
 * could ever find them. This is a genuine dispatch layer, not a CPT
 * visibility flag flip: each plugin registers its own lookup, this
 * class only merges the results.
 *
 * Same "one shared service, zero central registration needed by
 * consumers" shape as bhy_style_surfaces/bhi_portal_panels/
 * ous_debug_tools — this class never needs to know bh-crm or
 * bh-registry exist. A provider is just a callable:
 * function(string $query, int $limit): array<{type,title,excerpt,url,icon}>
 *
 * v1 scope, per the roadmap doc's own sequencing: LIKE-based matching,
 * not a real relevance-ranked index — the honest, correct-for-catalog-
 * size choice here, same reasoning BHS_Recommendations/
 * BHM_Recommendations already used for content-based scoring instead of
 * a real ML/index-backed system.
 *
 * Providers wired: bh-courses, bh-contest (published contests only,
 * linking to the contest's real page — never bh_submission, which
 * holds real people's contact info/audio files), bh-registry (only
 * 'active'/verified artists, the same gate its own public REST search
 * already enforces).
 *
 * Deliberately NOT wired, by design, not by omission — this REST route
 * is `permission_callback => __return_true` (fully public, unauthenticated),
 * so anything registered here is exposed to anonymous visitors:
 * - **bh-crm**: real, private person records (contact info, notes,
 *   tags) about real people. AJ's own standing rule, stated directly:
 *   "err on the side of safety and privacy — search shouldn't take
 *   people where they aren't allowed to go." A future admin-only CRM
 *   search (inside wp-admin, capability-gated) would be a legitimate,
 *   SEPARATE feature — it must never share this public dispatch layer.
 * - **bh-streaming**: no privacy issue, just no real destination yet —
 *   tracks/releases have no canonical per-item URL at all (confirmed in
 *   ROADMAP-discoverability.md), only ever reachable through the
 *   client-rendered player SPA. Wiring this properly needs either a
 *   query-param deep link the player can read on load, or a real
 *   single-track page — a real follow-up, not done here.
 */
class OUS_Search {
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_shortcode('ous_search', [self::class, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);

        // The one real provider wired at v1 (bh-courses — already
        // `'public' => true`, the cheapest to prove the mechanism
        // against). Guarded the same class_exists()-optional-dependency
        // way every other cross-plugin registration in this ecosystem
        // already is.
        add_filter('ous_search_providers', [self::class, 'maybe_register_courses_provider']);
    }

    public static function maybe_register_courses_provider($providers) {
        if (post_type_exists('bh_course')) {
            $providers['courses'] = [self::class, 'search_courses'];
        }
        return $providers;
    }

    // A plain WP_Query 's' search, since bh_course is already a real
    // public, indexed post type — no bespoke LIKE query needed for this
    // one provider specifically.
    public static function search_courses($query, $limit) {
        $posts = get_posts([
            'post_type' => 'bh_course', 'post_status' => 'publish',
            's' => $query, 'posts_per_page' => $limit,
        ]);
        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'type' => 'Course',
                'title' => $p->post_title,
                'excerpt' => wp_trim_words($p->post_content, 20),
                'url' => get_permalink($p),
                'icon' => 'dashicons-welcome-learn-more',
            ];
        }
        return $out;
    }

    /**
     * Calls every registered provider, merges, caps total results.
     * A single misbehaving provider (throws, times out on a slow query)
     * must not take down every OTHER provider's results — same
     * per-item try/catch posture BHM_Products::on_order_completed()
     * already uses for exactly this reason.
     */
    public static function run($query, $limit_per_type = 5) {
        $query = trim((string) $query);
        if ($query === '' || strlen($query) < 2) return [];

        $providers = apply_filters('ous_search_providers', []);
        $results = [];
        foreach ($providers as $key => $callback) {
            try {
                $rows = call_user_func($callback, $query, $limit_per_type);
                if (is_array($rows)) $results = array_merge($results, $rows);
            } catch (\Throwable $e) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('warning', "OUS_Search provider \"$key\" threw: " . $e->getMessage(), ['provider' => $key], 'OUS Search');
                }
            }
        }
        return $results;
    }

    public static function register_routes() {
        register_rest_route('ous/v1', '/search', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // public site search, same openness as WooCommerce's own shop browsing
            'callback' => [self::class, 'rest_search'],
        ]);
    }

    public static function rest_search(\WP_REST_Request $req) {
        $q = (string) $req->get_param('q');
        $results = self::run($q);
        return new \WP_REST_Response(['results' => $results, 'count' => count($results)], 200);
    }

    /* ---------------- front-end UI ---------------- */

    public static function render_shortcode() {
        ob_start();
        ?>
        <div class="ous-search">
            <input type="search" class="ous-search-input" placeholder="Search the site&hellip;" aria-label="Search">
            <div class="ous-search-results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function maybe_enqueue() {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'ous_search')) return;
        wp_enqueue_style('ous-search', OUS_URL . 'assets/css/search.css', [], OUS_VER);
        wp_enqueue_script('ous-search', OUS_URL . 'assets/js/search.js', [], OUS_VER, true);
        wp_localize_script('ous-search', 'ousSearchConfig', [
            'restUrl' => esc_url_raw(rest_url('ous/v1/search')),
        ]);
    }
}
