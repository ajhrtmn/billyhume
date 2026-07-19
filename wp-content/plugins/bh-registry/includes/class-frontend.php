<?php
if (!defined('ABSPATH')) exit;

/**
 * The plain fan-facing browse/search page — one of the three consumers
 * of the bhr/v1 API (alongside a WP streaming app adding a feed source,
 * and a future native app), built entirely against the same public GET
 * endpoints those other two would use. Also renders the self-serve
 * submission form, since a fan who's also an artist shouldn't need a
 * second page to submit their own link.
 */
class BHR_Frontend {
    public static function init() {
        add_shortcode('bh_registry', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        // Same auto-detect pattern bh-monetization-woo already uses for
        // its own tier/gift-redeem pages — needed so a search result can
        // link somewhere real (see search_artists() below).
        add_action('save_post_page', [self::class, 'maybe_remember_registry_page']);
        // OUS_Search consumer, ROADMAP-search-and-revisions.md Section 1
        // sequencing. Public-safe: reuses the SAME 'active'-only gate
        // (verified links only) BHR_API::list_artists() already
        // enforces for its own public REST search — pending/rejected
        // artists are never visible here regardless of query, matching
        // this ecosystem's "search shouldn't take people where they
        // aren't allowed to go" standard.
        add_filter('ous_search_providers', [self::class, 'register_search_provider']);
    }

    public static function maybe_remember_registry_page($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && has_shortcode($post->post_content, 'bh_registry')) {
            update_option('bhr_registry_page_id', $post_id);
        }
    }

    public static function register_search_provider($providers) {
        $providers['artists'] = [self::class, 'search_artists'];
        return $providers;
    }

    public static function search_artists($query, $limit) {
        global $wpdb;
        $page_id = (int) get_option('bhr_registry_page_id', 0);
        if (!$page_id || get_post_status($page_id) !== 'publish') return []; // nowhere real to link to yet

        $artists_t = $wpdb->prefix . 'bhr_artists';
        $like = '%' . $wpdb->esc_like($query) . '%';
        // Same 'active'-only gate as the real public REST search — an
        // artist still pending/rejected is never surfaced here either.
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
                // No per-artist canonical URL exists yet (the directory
                // is one client-rendered page, not individual permalinks
                // — same limitation ROADMAP-discoverability.md already
                // found for this exact plugin) — links to the real
                // directory page rather than a dead/nonexistent one; a
                // fan lands on the browse page and can find them there.
                'excerpt' => wp_trim_words($r['bio'], 20),
                'url' => $url,
                'icon' => 'dashicons-admin-users',
            ];
        }
        return $out;
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_registry')) return;

        wp_enqueue_style('bhr-registry', BHR_URL . 'assets/css/registry.css', [], BHR_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhr-registry', BHY_Style::inline_css());
        wp_enqueue_script('bhr-registry', BHR_URL . 'assets/js/registry.js', [], BHR_VER, true);
        wp_localize_script('bhr-registry', 'BHRData', [
            'rest' => esc_url_raw(rest_url('bhr/v1/')),
            // Rendered once server-side (the shared BHY_Style component,
            // same one bh-courses' catalog already uses) rather than a
            // bare '<p>No artists found.</p>' — a brand-new registry
            // with zero real entries yet is exactly the day-one state a
            // fresh site actually hits, not an edge case.
            'emptyHtml' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'No artists yet',
                'description' => 'Be the first to add your feed to the registry.',
            ]) : '<p class="bhr-empty">No artists found.</p>',
        ]);
        // Reporting is handled by own-ur-shit's shared queue (see
        // BHI_Reports), not something this plugin builds its own
        // version of — this is a no-op object (empty rest url) if the
        // core plugin's REST route somehow isn't there, which the JS
        // below checks before ever trying to use it.
        wp_localize_script('bhr-registry', 'BHIData', [
            'rest'     => class_exists('BHI_Reports') ? esc_url_raw(rest_url('bhi/v1/')) : '',
            'nonce'    => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public static function render() {
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
                        <div id="bhr-f-error" class="bhr-form-error"></div>
                    </div>
                    <div id="bhr-submit-step-verify" style="display:none;"></div>
                </div>
            </div>
        </div>';
    }
}
