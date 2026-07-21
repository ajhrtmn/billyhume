<?php
if (!defined('ABSPATH') ) exit;

/**
 * Preserves the "expansive CSS properties + databinding" surface from
 * the custom page-builder's inspector after its deletion
 * (PAGE-BUILDER-DELETE-KEEP-AUDIT.md) — but NOT rebuilt as a bespoke
 * block or a bespoke inspector shell, which would have been the exact
 * same mistake at a smaller scale. Instead: a generic "Advanced Styles"
 * panel added to EVERY block in the native block editor via WordPress's
 * own extension points — `register_block_type_args` (server) and
 * `editor.BlockEdit` (client) — the same "attach to what already
 * exists" posture `BH_Content` already took toward Gutenberg's block
 * model rather than inventing a parallel canvas.
 *
 * What this does NOT do: add a new block type, a new post type, or a
 * new data model. Every block already has `attrs` (round-tripped in its
 * block comment, `<!-- wp:paragraph {"bhStyle":{...}} -->`) — this just
 * reserves one attribute name (`bhStyle`) on that existing mechanism and
 * gives it a real editing UI. Saving, undo/history, revisions — all of
 * it is Gutenberg's own, unmodified. That's the whole point.
 *
 * The property vocabulary itself is NOT new either — it's
 * `BHY_Style::PROPERTY_MAP`/`scoped_inline_style()`/`style_schema_for_js()`
 * (class-style.php), the same resolver `BH_Element::render_placement()`
 * already uses for a placement's `config.style` map. This class is
 * genuinely just "give that existing, well-designed resolver a UI
 * surface on ordinary blocks too" — not a second implementation.
 *
 * Portability note: the stored shape is plain —
 * `bhStyle` is a flat `{ "group.property": "value" }` object, the exact
 * same map shape `scoped_inline_style()` already accepts and
 * `BH_Element` placements already store in `config.style`. Nothing WP-
 * specific about the stored data itself; only the attachment mechanism
 * (a Gutenberg block attribute) is WP-specific, and that's confined to
 * this one file plus its JS half (assets/js/block-style-panel.js).
 */
class BHY_BlockStyle {
    public static function init() {
        // Reserves the attribute on every registered block type BEFORE
        // it's registered — the block-args filter WordPress itself
        // provides for exactly this ("add an attribute to every block
        // without editing every block's own registration"), same
        // mechanism WordPress core itself uses internally for e.g. the
        // universal `className`/`style` attributes.
        add_filter('register_block_type_args', [self::class, 'add_attribute'], 10, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
        // Priority 10, both args (content + parsed block) — this is the
        // one, single place a bhStyle map actually becomes real CSS on
        // the front end. Runs for every block on every page load;
        // returns immediately, doing nothing, for the overwhelming
        // majority of blocks that never set bhStyle.
        add_filter('render_block', [self::class, 'inject_style'], 10, 2);
    }

    public static function add_attribute($args, $block_type) {
        if (!isset($args['attributes']) || !is_array($args['attributes'])) $args['attributes'] = [];
        if (!isset($args['attributes']['bhStyle'])) {
            $args['attributes']['bhStyle'] = ['type' => 'object', 'default' => new stdClass()];
        }
        return $args;
    }

    public static function enqueue_editor_assets() {
        if (!class_exists('BHY_Style')) return; // harmless no-op — same posture as every other cross-class touch in this ecosystem
        $handle = 'bhy-block-style-panel';
        wp_enqueue_script(
            $handle,
            OUS_URL . 'assets/js/block-style-panel.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-i18n', 'wp-data'],
            OUS_VER,
            true
        );
        // The one, single source of truth for what properties/presets
        // exist — style_schema_for_js() (class-style.php) already builds
        // exactly this shape for a client to read; nothing about the
        // property vocabulary is duplicated here in JS or PHP.
        wp_localize_script($handle, 'bhyBlockStyleSchema', BHY_Style::style_schema_for_js());
        wp_set_script_translations($handle, 'own-ur-shit');
    }

    /**
     * The one real render-time job: resolve a block's bhStyle map into
     * an inline style declaration string (via the SAME
     * scoped_inline_style() BH_Element already uses) and merge it onto
     * that block's own root element, without disturbing any style/class
     * the block already emits on its own.
     *
     * Uses WP_HTML_Tag_Processor (core since 6.2) rather than a regex —
     * regex-editing arbitrary block HTML for attribute injection is
     * exactly the class of bug this ecosystem has been bitten by before
     * with hand-rolled string surgery; the tag processor parses real
     * HTML token boundaries, so it can't corrupt an existing style/class
     * attribute the way a naive string-replace could.
     */
    public static function inject_style($block_content, $block) {
        $style_map = $block['attrs']['bhStyle'] ?? null;
        if (empty($style_map) || !is_array($style_map)) return $block_content;
        if (!class_exists('BHY_Style') || !class_exists('WP_HTML_Tag_Processor')) return $block_content;

        $decls = BHY_Style::scoped_inline_style($style_map);
        if ($decls === '') return $block_content;

        $processor = new WP_HTML_Tag_Processor($block_content);
        if (!$processor->next_tag()) return $block_content; // no real element to attach to (e.g. a block that renders nothing) — degrade to unstyled, never fatal

        $existing = (string) $processor->get_attribute('style');
        $existing = $existing !== '' && substr(trim($existing), -1) !== ';' ? trim($existing) . ';' : trim($existing);
        $processor->set_attribute('style', $existing . $decls);

        return $processor->get_updated_html();
    }
}
