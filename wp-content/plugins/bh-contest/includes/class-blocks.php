<?php
if (!defined('ABSPATH')) exit;

/**
 * Server-side-rendered blocks wrapping the existing shortcodes (which stay registered).
 * Each renders the same container div, hydrated by the front-end JS; the asset
 * enqueue in bh-contest.php therefore checks has_block() as well as has_shortcode().
 */
class BH_Blocks {
    public static function init(): void {
        self::register_blocks();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_blocks(): void {
        if (!function_exists('register_block_type')) return; // WP too old — harmless no-op, same posture every optional integration in this ecosystem uses

        wp_register_script(
            'bh-contest-blocks',
            BH_URL . 'assets/js/bh-contest-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render'],
            BH_VER,
            true
        );

        register_block_type('bh/contest-player', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_player'],
            'attributes'      => [
                'contest' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_block_type('bh/results-reveal', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_reveal'],
            'attributes'      => [
                'contest' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_block_type('bh/archive', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_archive'],
        ]);

        register_block_type('bh/contest-library', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_contest_library'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_player($attributes): string {
        return BH_Auth::render(['contest' => $attributes['contest'] ?? '']);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_reveal($attributes): string {
        return BH_Reveal::render_display_shortcode(['contest' => $attributes['contest'] ?? '']);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_archive($attributes): string {
        return BH_Archive::render_display_shortcode();
    }

    /** @param array<string, mixed> $attributes */
    public static function render_contest_library($attributes): string {
        return BH_Contest_Library::render_shortcode([]);
    }

    public static function register_routes(): void {
        register_rest_route('bh/v1', '/contests-picker', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_contests_picker'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Contest list for the blocks' Inspector picker (BH_Helpers::all_contests()).
    /** @return \WP_REST_Response */
    public static function rest_contests_picker(\WP_REST_Request $req) {
        $out = [];
        foreach (BH_Helpers::all_contests() as $c) {
            $out[] = ['id' => $c->ID, 'title' => $c->post_title, 'slug' => $c->post_name];
        }
        return new WP_REST_Response($out, 200);
    }
}
