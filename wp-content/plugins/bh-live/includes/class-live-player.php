<?php
if (!defined('ABSPATH')) exit;

/**
 * [bh_live] shortcode — current live status/embed, plus a replay list
 * below it. Same theme-isolation posture as bh-video/bh-streaming's
 * own player CSS, same --bh-* design tokens.
 */
class BHL_Player {
    public static function init() {
        add_shortcode('bh_live', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_live')) return;

        wp_enqueue_style('bhl-player', BHL_URL . 'assets/css/live-player.css', [], BHL_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhl-player', BHY_Style::inline_css());

        // chat.js registered as a dependency (not just enqueued
        // alongside) so WordPress guarantees it loads and defines
        // window.BHLChatWidget BEFORE live-player.js's own status-fetch
        // callback ever tries to call .mount() on it — enqueuing both
        // with no declared relationship would leave that a race.
        wp_enqueue_script('bhl-chat', BHL_URL . 'assets/js/chat.js', [], BHL_VER, true);
        wp_enqueue_script('bhl-player', BHL_URL . 'assets/js/live-player.js', ['bhl-chat'], BHL_VER, true);
        wp_localize_script('bhl-player', 'BHLData', [
            'rest'     => esc_url_raw(rest_url('bhl/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public static function render($atts = []) {
        return '
        <div class="bhl-app" id="bhl-app">
            <div class="bhl-live-row">
                <div class="bhl-live-slot" id="bhl-live-slot"><div class="bhl-empty">Checking live status…</div></div>
                <div class="bhl-chat-slot" id="bhl-chat-slot" style="display:none;"></div>
            </div>
            <h3 class="bhl-replays-heading" id="bhl-replays-heading" style="display:none;">Past streams</h3>
            <div class="bhl-replay-grid" id="bhl-replay-grid"></div>
        </div>';
    }
}
