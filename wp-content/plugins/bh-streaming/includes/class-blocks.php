<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a — WYSIWYG
 * shortcode-to-block conversion, continuing the same wp.serverSideRender
 * pattern already shipped for bh-monetization-woo (bhm/buy, bhm/
 * tip-jar, bhm/tiers) and bh-contest (bh/contest-player, bh/results-
 * reveal, bh/archive). One block, 'bhs/player' — [bh_streaming] takes
 * no attributes and BHS_Player::render() is a single, fixed mount div
 * (the actual player is entirely player.js hydrating it client-side on
 * a real front-end load, same "editor canvas shows the real static
 * container, not a live-interactive preview" honesty already
 * established for the bh-contest blocks). Old shortcode untouched.
 *
 * Respects BHS_Env::hidden_in_production() the same way the shortcode
 * always has — render_callback calls BHS_Player::render() directly,
 * so a production site with streaming still hidden sees an empty block
 * here too, not a leaked half-built feature.
 */
class BHS_Blocks {
    public static function init() {
        self::register_blocks();
    }

    public static function register_blocks() {
        if (!function_exists('register_block_type')) return; // WP too old — harmless no-op, same posture every optional integration in this ecosystem uses

        wp_register_script(
            'bhs-player-block',
            BHS_URL . 'assets/js/bhs-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render'],
            BHS_VER,
            true
        );

        register_block_type('bhs/player', [
            'editor_script'   => 'bhs-player-block',
            'render_callback' => [self::class, 'render_player'],
        ]);
    }

    public static function render_player($attributes) {
        return BHS_Player::render();
    }
}
