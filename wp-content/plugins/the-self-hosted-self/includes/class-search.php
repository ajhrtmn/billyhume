<?php
if (!defined('ABSPATH') ) exit;

/**
 * OUS_Search: site-wide search dispatch. Each plugin registers a provider via
 * `ous_search_providers`; this class merges results. A provider is a callable:
 * function(string $query, int $limit): array<{type,title,excerpt,url,icon}>
 * LIKE-based matching, not a relevance index.
 *
 * WHY: the REST route is public and unauthenticated, so only public content
 * may be registered. Never bh-crm person records or bh_submission entries.
 */
class OUS_Search {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_shortcode('ous_search', [self::class, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);

        // bh-courses provider (class_exists()-guarded inside).
        add_filter('ous_search_providers', [self::class, 'maybe_register_courses_provider']);
    }

    /**
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    public static function maybe_register_courses_provider($providers): array {
        if (post_type_exists('bh_course')) {
            $providers['courses'] = [self::class, 'search_courses'];
        }
        return $providers;
    }

    // bh_course is a public post type, so plain WP_Query 's' works.
    /** @return array<int, array<string, mixed>> */
    public static function search_courses(string $query, int $limit): array {
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
     * Calls every provider and merges results. Each provider is wrapped in
     * try/catch so one failure cannot drop the others.
     */
    /** @return array<int, array<string, mixed>> */
    public static function run(string $query, int $limit_per_type = 5): array {
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

    public static function register_routes(): void {
        register_rest_route('ous/v1', '/search', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // public site search, same openness as WooCommerce's own shop browsing
            'callback' => [self::class, 'rest_search'],
        ]);
    }

    public static function rest_search(\WP_REST_Request $req): \WP_REST_Response {
        $q = (string) $req->get_param('q');
        $results = self::run($q);
        return new \WP_REST_Response(['results' => $results, 'count' => count($results)], 200);
    }

    /* ---------------- front-end UI ---------------- */

    public static function render_shortcode(): string {
        ob_start();
        ?>
        <div class="ous-search">
            <input type="search" class="ous-search-input" placeholder="Search the site&hellip;" aria-label="Search">
            <div class="ous-search-results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function maybe_enqueue(): void {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'ous_search')) return;
        wp_enqueue_style('ous-search', OUS_URL . 'assets/css/search.css', [], OUS_VER);
        wp_enqueue_script('ous-search', OUS_URL . 'assets/js/search.js', [], OUS_VER, true);
        wp_localize_script('ous-search', 'ousSearchConfig', [
            'restUrl' => esc_url_raw(rest_url('ous/v1/search')),
        ]);
    }
}
