<?php
if (!defined('ABSPATH')) exit;

/**
 * 'bhs/player' block wrapping [bh_streaming] via wp.serverSideRender; the
 * editor shows the static mount div, which player.ts hydrates on the front
 * end. render_callback calls BHS_Player::render(), so BHS_FORCE_HIDDEN applies.
 */
class BHS_Blocks {
    public static function init(): void {
        self::register_blocks();
    }

    public static function register_blocks(): void {
        if (!function_exists('register_block_type')) return; // WP too old; harmless no-op

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

    /** @param array<string, mixed> $attributes */
    public static function render_player($attributes): string {
        return BHS_Player::render();
    }
}
