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
