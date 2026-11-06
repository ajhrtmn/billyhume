<?php
if (!defined('ABSPATH')) exit;

/**
 * The 'the-self-hosted-self/element-prefab' block: renders a saved BH_Element_Prefab
 * tree read-only wherever the block is placed. Editor script is plain JS on wp.* globals.
 */
class OUS_Gutenberg_Block {
    // WHY: init() already runs inside the 'init' hook, so a nested add_action('init', ...) would
    // never fire this request; register directly. No-ops while BH_Element_Prefab doesn't exist.
    public static function init(): void {
        self::register_block();
    }

    public static function register_block(): void {
        if (!function_exists('register_block_type') || !class_exists('BH_Element_Prefab')) return; // WP too old, or the prefab class isn't loaded — harmless no-op.

        wp_register_script(
            'ous-element-prefab-block',
            OUS_URL . 'assets/js/element-prefab-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            OUS_VER,
            true
        );

        register_block_type('the-self-hosted-self/element-prefab', [
            'editor_script'   => 'ous-element-prefab-block',
            'render_callback' => [self::class, 'render'],
            'attributes'      => [
                'prefabId' => ['type' => 'number', 'default' => 0],
            ],
        ]);
    }

    /**
     * Server-side render for every visitor. WHY: not capability-gated — this is public post
     * content; $ctx uses the viewing visitor's user_id (0 for guests), never an admin's.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render($attributes): string {
        $prefab_id = (int) ($attributes['prefabId'] ?? 0);
        if ($prefab_id <= 0) {
            // No prefab chosen: render nothing on the front end; the editor shows a placeholder.
            return '';
        }

        $ctx = ['user_id' => get_current_user_id()];
        $html = BH_Element_Prefab::render_definition($prefab_id, $ctx);
        if ($html === '') return '';

        return '<div class="bh-el-prefab-embed" data-bhel-prefab-id="' . (int) $prefab_id . '">' . $html . '</div>';
    }
}
