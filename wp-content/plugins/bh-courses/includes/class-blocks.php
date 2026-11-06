<?php
if (!defined('ABSPATH')) exit;

/**
 * 'bhc/catalog' ([bh_courses]) and 'bhc/course' ([bh_course] with an `id` picker) blocks,
 * rendered fully server-side via ServerSideRender. Shortcodes still work.
 */
class BHC_Blocks {
    public static function init(): void {
        self::register_blocks();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_blocks(): void {
        if (!function_exists('register_block_type')) return; // WP too old

        wp_register_script(
            'bhc-blocks',
            BHC_URL . 'assets/js/bhc-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render'],
            BHC_VER,
            true
        );

        register_block_type('bhc/catalog', [
            'editor_script'   => 'bhc-blocks',
            'render_callback' => [self::class, 'render_catalog'],
        ]);

        register_block_type('bhc/course', [
            'editor_script'   => 'bhc-blocks',
            'render_callback' => [self::class, 'render_course'],
            'attributes'      => [
                'id' => ['type' => 'number', 'default' => 0],
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_catalog($attributes): string {
        return BHC_Render_Catalog::render_catalog();
    }

    /** @param array<string, mixed> $attributes */
    public static function render_course($attributes): string {
        $id = (int) ($attributes['id'] ?? 0);
        if (!$id) return '';
        return BHC_Render_Course::render_course(['id' => $id]);
    }

    public static function register_routes(): void {
        register_rest_route('bhc/v1', '/courses-picker', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_courses_picker'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Every published course for the bhc/course picker, regardless of gating.
    /** @return \WP_REST_Response */
    public static function rest_courses_picker(\WP_REST_Request $req) {
        $q = new WP_Query([
            'post_type' => 'bh_course', 'post_status' => 'publish', 'posts_per_page' => -1,
        ]);
        $out = [];
        foreach ($q->posts as $p) {
            $out[] = ['id' => $p->ID, 'title' => $p->post_title];
        }
        return new WP_REST_Response($out, 200);
    }
}
