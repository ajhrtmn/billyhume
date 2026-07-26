<?php
if (!defined('ABSPATH')) exit;

/**
 * [bh_video] shortcode — a genuine browse/playback SPA, same posture
 * as bh-streaming's own class-player.php but deliberately smaller: v1
 * scope is browse + play only (see the plan's own "what NOT to build
 * in v1" list — no login/import/playlists here, those are audio-catalog
 * concepts this doesn't need to duplicate). Renders straight into
 * whatever WP theme is active, so the CSS resets under .bhv-app follow
 * the exact same theme-isolation reasoning as player.css.
 */
class BHV_Player {
    public static function init() {
        add_shortcode('bh_video', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_video')) return;

        wp_enqueue_style('bhv-player', BHV_URL . 'assets/css/video-player.css', [], BHV_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhv-player', BHY_Style::inline_css());
        wp_enqueue_script('bhv-player', BHV_URL . 'assets/js/video-player.js', [], BHV_VER, true);
        wp_localize_script('bhv-player', 'BHVData', [
            'rest'  => esc_url_raw(rest_url('bhv/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function render($atts = []) {
        return '
        <div class="bhv-app" id="bhv-app">
            <div class="bhv-topbar">
                <input type="text" id="bhv-search" placeholder="Search title…">
                <select id="bhv-genre-filter"><option value="">All genres</option></select>
            </div>

            <div class="bhv-player-wrap" id="bhv-player-wrap" style="display:none;">
                <video id="bhv-video-el" controls playsinline></video>
                <h3 id="bhv-now-title"></h3>
            </div>

            <div class="bhv-grid" id="bhv-grid"><div class="bhv-empty">Loading videos…</div></div>
        </div>';
    }
}
