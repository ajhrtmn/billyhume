<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a — WYSIWYG
 * shortcode-to-block conversion, continuing the same wp.serverSideRender
 * pattern already shipped for bh-monetization-woo, bh-contest, and
 * bh-streaming. Two blocks: 'bhc/catalog' ([bh_courses], no attributes
 * — always the full catalog grid) and 'bhc/course' ([bh_course], an
 * `id` attribute + an Inspector picker, same shape as bh-monetization-
 * woo's bhm/buy block). Old shortcodes untouched.
 *
 * Unlike bh-contest's/bh-streaming's blocks (a static mount div hydrated
 * by client-side JS), BOTH of these render REAL, complete server-side
 * HTML — the catalog grid and a course's full detail page are both
 * fully formed on the PHP side already, so ServerSideRender here shows
 * the actual final content, not just a container shell.
 */
class BHC_Blocks {
    public static function init() {
        self::register_blocks();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_blocks() {
        if (!function_exists('register_block_type')) return; // WP too old — harmless no-op, same posture every optional integration in this ecosystem uses

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

    public static function render_catalog($attributes) {
        return BHC_Render::render_catalog();
    }

    public static function render_course($attributes) {
        $id = (int) ($attributes['id'] ?? 0);
        if (!$id) return '';
        return BHC_Render::render_course(['id' => $id]);
    }

    public static function register_routes() {
        register_rest_route('bhc/v1', '/courses-picker', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_courses_picker'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Populates the bhc/course block's Inspector picker — every
    // published course, regardless of gating (an editor choosing which
    // course to embed needs to see all of them, not just ones they
    // personally have access to).
    public static function rest_courses_picker($req) {
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
