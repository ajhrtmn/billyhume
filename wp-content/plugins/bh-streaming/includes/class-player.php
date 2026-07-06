<?php
if (!defined('ABSPATH')) exit;

class BHS_Player {
    public static function init() {
        add_shortcode('bh_streaming', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_streaming')) return;

        wp_enqueue_style('bhs-player', BHS_URL . 'assets/css/player.css', [], BHS_VER);
        wp_add_inline_style('bhs-player', BHY_Style::inline_css());
        wp_enqueue_script('bhs-player', BHS_URL . 'assets/js/player.js', [], BHS_VER, true);
        wp_localize_script('bhs-player', 'BHSData', [
            'rest'     => esc_url_raw(rest_url('bhs/v1/')),
            'identity' => esc_url_raw(rest_url('bhi/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ]);
    }

    public static function render() {
        return '
        <div class="bhs-app" id="bhs-app">
            <div class="bhs-topbar">
                <div class="bhs-account" id="bhs-account">
                    <button type="button" class="bhs-btn" id="bhs-login-open" style="display:none;">Log in</button>
                    <span id="bhs-account-info" style="display:none;">
                        <span id="bhs-account-username"></span>
                        <button type="button" class="bhs-link-btn" id="bhs-logout">Log out</button>
                    </span>
                </div>
                <div class="bhs-tabs" id="bhs-tabs">
                    <button type="button" class="bhs-tab active" data-view="all">All Tracks</button>
                    <button type="button" class="bhs-tab" data-view="releases">Releases</button>
                    <button type="button" class="bhs-tab" data-view="liked">Liked Songs</button>
                    <button type="button" class="bhs-tab" data-view="playlists">My Playlists</button>
                </div>
                <div class="bhs-filters">
                    <input type="text" id="bhs-search" placeholder="Search title or artist…">
                    <select id="bhs-genre-filter"><option value="">All genres</option></select>
                </div>
            </div>

            <div class="bhs-auth-modal" id="bhs-auth-modal" style="display:none;">
                <div class="bhs-auth-modal-inner">
                    <button type="button" class="bhs-modal-close" id="bhs-auth-close">&times;</button>
                    <div class="bhs-auth-tabs">
                        <button type="button" class="bhs-auth-tab active" data-mode="login">Log In</button>
                        <button type="button" class="bhs-auth-tab" data-mode="register">Sign Up</button>
                    </div>
                    <div id="bhs-auth-error" class="bhs-auth-error"></div>
                    <input type="text" id="bhs-auth-username" placeholder="Username">
                    <input type="email" id="bhs-auth-email" placeholder="Email" style="display:none;">
                    <input type="password" id="bhs-auth-password" placeholder="Password">
                    <button type="button" class="bhs-btn" id="bhs-auth-submit">Log In</button>
                </div>
            </div>

            <div class="bhs-main">
                <div class="bhs-library" id="bhs-library"><p class="bhs-empty">Loading…</p></div>
                <div class="bhs-related" id="bhs-related" style="display:none;">
                    <h3>Related</h3>
                    <div id="bhs-related-list"></div>
                </div>
            </div>

            <div class="bhs-nowplaying" id="bhs-nowplaying" style="display:none;">
                <img class="bhs-np-art" id="bhs-np-art" alt="">
                <div class="bhs-np-info">
                    <div class="bhs-np-title" id="bhs-np-title"></div>
                    <div class="bhs-np-artist" id="bhs-np-artist"></div>
                </div>
                <div class="bhs-np-controls">
                    <button type="button" id="bhs-prev" aria-label="Previous">&#9198;</button>
                    <button type="button" id="bhs-playpause" aria-label="Play">&#9658;</button>
                    <button type="button" id="bhs-next" aria-label="Next">&#9197;</button>
                    <button type="button" id="bhs-like" aria-label="Like" class="bhs-like-btn">&#9825;</button>
                    <button type="button" id="bhs-add-playlist" aria-label="Add to playlist">&#65291;</button>
                    <button type="button" id="bhs-queue-toggle" aria-label="Queue">&#9776;</button>
                </div>
                <div class="bhs-np-volume">
                    <span>&#128266;</span>
                    <input type="range" id="bhs-volume" min="0" max="100" value="100">
                </div>
                <input type="range" id="bhs-seek" class="bhs-seek" min="0" max="100" value="0">
            </div>

            <div class="bhs-queue-panel" id="bhs-queue-panel" style="display:none;">
                <h3>Up Next</h3>
                <div id="bhs-queue-list"></div>
            </div>

            <div class="bhs-playlist-picker" id="bhs-playlist-picker" style="display:none;">
                <div class="bhs-playlist-picker-inner">
                    <h3>Add to playlist</h3>
                    <div id="bhs-playlist-picker-list"></div>
                    <p><input type="text" id="bhs-new-playlist-name" placeholder="New playlist name"> <button type="button" id="bhs-new-playlist-create" class="bhs-btn">Create</button></p>
                    <button type="button" id="bhs-playlist-picker-close" class="bhs-btn">Close</button>
                </div>
            </div>
        </div>';
    }
}
