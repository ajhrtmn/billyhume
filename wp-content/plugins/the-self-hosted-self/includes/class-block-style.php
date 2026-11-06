<?php
if (!defined('ABSPATH') ) exit;

/**
 * Generic "Advanced Styles" panel on every native block: reserves a
 * `bhStyle` attribute via register_block_type_args and renders it with
 * BHY_Style::scoped_inline_style() (the same resolver BH_Element uses).
 * Stored shape is a plain `{ "group.property": "value" }` map; only the
 * attachment is WP-specific. JS half: assets/js/block-style-panel.js.
 */
class BHY_BlockStyle {
    public static function init(): void {
        // Adds the attribute to every block type, as core does for className/style.
        add_filter('register_block_type_args', [self::class, 'add_attribute'], 10, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
        // The one place a bhStyle map becomes front-end CSS; no-op without bhStyle.
        add_filter('render_block', [self::class, 'inject_style'], 10, 2);
    }

    /**
     * @param array<string, mixed> $args
     * @param mixed $block_type
     * @return array<string, mixed>
     */
    public static function add_attribute($args, $block_type): array {
        if (!isset($args['attributes']) || !is_array($args['attributes'])) $args['attributes'] = [];
        if (!isset($args['attributes']['bhStyle'])) {
            $args['attributes']['bhStyle'] = ['type' => 'object', 'default' => new stdClass()];
        }
        return $args;
    }

    public static function enqueue_editor_assets(): void {
        if (!class_exists('BHY_Style')) return;
        $handle = 'bhy-block-style-panel';
        wp_enqueue_script(
            $handle,
            OUS_URL . 'assets/js/block-style-panel.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-i18n', 'wp-data'],
            OUS_VER,
            true
        );
        // Property vocabulary comes only from style_schema_for_js().
        wp_localize_script($handle, 'bhyBlockStyleSchema', BHY_Style::style_schema_for_js());
        wp_set_script_translations($handle, 'the-self-hosted-self');
    }

    /**
     * Merges a block's resolved bhStyle onto its root element's style.
     * WHY: WP_HTML_Tag_Processor, not regex, so existing attributes can't be corrupted.
     *
     * @param array<string, mixed> $block
     */
    public static function inject_style(string $block_content, $block): string {
        $style_map = $block['attrs']['bhStyle'] ?? null;
        if (empty($style_map) || !is_array($style_map)) return $block_content;
        if (!class_exists('BHY_Style') || !class_exists('WP_HTML_Tag_Processor')) return $block_content;

        $decls = BHY_Style::scoped_inline_style($style_map);
        if ($decls === '') return $block_content;

        $processor = new WP_HTML_Tag_Processor($block_content);
        if (!$processor->next_tag()) return $block_content; // nothing to attach to: leave unstyled

        $existing = (string) $processor->get_attribute('style');
        $existing = $existing !== '' && substr(trim($existing), -1) !== ';' ? trim($existing) . ';' : trim($existing);
        $processor->set_attribute('style', $existing . $decls);

        return $processor->get_updated_html();
    }
}
