<?php
if (!defined('ABSPATH')) exit;

/**
 * Fan-facing [bh_registry] browse/search page plus the self-serve submission form, built on the public bhr/v1 API.
 */
class BHR_Frontend {
    public static function init(): void {
        add_shortcode('bh_registry', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        // Detect the directory page so search results can link to it.
        add_action('save_post_page', [self::class, 'maybe_remember_registry_page']);
        // OUS_Search provider; same 'active'-only gate as the public REST search.
        add_filter('ous_search_providers', [self::class, 'register_search_provider']);
        add_action('template_redirect', [self::class, 'maybe_set_seo_data_early']);
    }

    /**
     * WHY: render() runs after wp_head, too late for BH_SEO's tags, so set page SEO data early
     * when the shortcode is present.
     */
    public static function maybe_set_seo_data_early(): void {
        if (!class_exists('BH_SEO')) return;
        if (BH_SEO::shortcode_atts_on_current_page('bh_registry') === null) return;
        self::set_seo_data();
    }

    private static function set_seo_data(): void {
        if (!class_exists('BH_SEO')) return;
        BH_SEO::set_page_data([
            'title' => 'Artist Registry — ' . get_bloginfo('name'),
            'description' => 'Browse independent artists and their feeds on ' . get_bloginfo('name') . '.',
            'url' => get_permalink() ?: home_url('/'),
            'type' => 'website',
        ]);
    }

    public static function maybe_remember_registry_page(int $post_id): void {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bh_registry')) {
            update_option('bhr_registry_page_id', $post_id);
        }
    }

    /**
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    public static function register_search_provider(array $providers): array {
        $providers['artists'] = [self::class, 'search_artists'];
        return $providers;
    }

    /** @return array<int, array<string, mixed>> */
    public static function search_artists(string $query, int $limit): array {
        global $wpdb;
        $page_id = (int) get_option('bhr_registry_page_id', 0);
        if (!$page_id || get_post_status($page_id) !== 'publish') return []; // nowhere real to link to yet

        $artists_t = BHR_Tables::artists();
        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT display_name, bio FROM $artists_t WHERE status = 'active' AND display_name LIKE %s ORDER BY display_name ASC LIMIT %d",
            $like, max(1, (int) $limit)
        ), ARRAY_A);

        $url = get_permalink($page_id);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'type' => 'Artist',
                'title' => $r['display_name'],
                // No per-artist permalinks exist, so link to the directory page.
                'excerpt' => wp_trim_words($r['bio'], 20),
                'url' => $url,
                'icon' => 'dashicons-admin-users',
            ];
        }
        return $out;
    }

    public static function maybe_enqueue(): void {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_registry')) return;

        wp_enqueue_style('bhr-registry', BHR_URL . 'assets/css/registry.css', [], BHR_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhr-registry', BHY_Style::inline_css());
        wp_enqueue_script('bhr-registry', BHR_URL . 'assets/js/registry.js', [], BHR_VER, true);
        wp_localize_script('bhr-registry', 'BHRData', [
            'rest' => esc_url_raw(rest_url('bhr/v1/')),
            // Shared BHY_Style empty state; a fresh registry starts here.
            'emptyHtml' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'No artists yet',
                'description' => 'Be the first to add your feed to the registry.',
            ]) : '<p class="bhr-empty">No artists found.</p>',
        ]);
        // Reporting uses the core BHI_Reports queue; an empty rest url disables it in JS.
        wp_localize_script('bhr-registry', 'BHIData', [
            'rest'     => class_exists('BHI_Reports') ? esc_url_raw(rest_url('bhi/v1/')) : '',
            'nonce'    => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public static function render(): string {
        // Page-level SEO only (the grid is client-rendered). Idempotent, and covers do_shortcode()
        // use the early hook can't detect.
        self::set_seo_data();

        return '
        <div class="bhr-app" id="bhr-app">
            <div class="bhr-search-row">
                <input type="text" id="bhr-search" class="bhr-search" placeholder="Search artists…">
                <select id="bhr-protocol-filter" class="bhr-protocol-filter">
                    <option value="">All protocols</option>
                    <option value="activitypub">ActivityPub</option>
                    <option value="feed">RSS / Podcasting 2.0</option>
                </select>
                <button type="button" class="bhr-btn" id="bhr-submit-open">Submit your link</button>
            </div>
            <div class="bhr-grid" id="bhr-grid"><p class="bhr-empty">Loading…</p></div>

            <div class="bhr-submit-modal" id="bhr-submit-modal" style="display:none;">
                <div class="bhr-submit-modal-inner">
                    <button type="button" class="bhr-modal-close" id="bhr-submit-close">&times;</button>
                    <h3>Submit your link</h3>
                    <p class="bhr-modal-note">Free, self-serve. We store only your public link and basic metadata — never your media. You will need to prove you control the domain via a small text file (instructions shown after submitting).</p>
                    <div id="bhr-submit-step-form">
                        <input type="text" id="bhr-f-name" placeholder="Artist / project name">
                        <textarea id="bhr-f-bio" placeholder="Short bio (optional)"></textarea>
                        <input type="email" id="bhr-f-email" placeholder="Contact email (optional, for verification issues)">
                        <select id="bhr-f-protocol">
                            <option value="feed">RSS / Podcasting 2.0 feed (e.g. a Funkwhale channel\'s RSS link)</option>
                            <option value="activitypub">ActivityPub actor URL</option>
                        </select>
                        <input type="url" id="bhr-f-url" placeholder="https://your-instance.example/…">
                        <p class="bhr-modal-note">No instance yet? <a href="https://funkwhale.audio" target="_blank" rel="noopener">Get a free Funkwhale channel</a>, then come back and paste its RSS link above.</p>
                        <button type="button" class="bhr-btn" id="bhr-f-submit">Submit</button>
                        <div id="bhr-f-error" class="bhr-form-error bh-alert bh-alert-error" role="alert"></div>
                    </div>
                    <div id="bhr-submit-step-verify" style="display:none;"></div>
                </div>
            </div>
        </div>';
    }
}
