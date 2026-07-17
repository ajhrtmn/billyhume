<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Content — the content-block interface (ROADMAP-platform-evolution.md
 * Section 2 item 1 / Section 3). This is the FIRST of the new `BH_*`
 * cross-cutting interfaces (see class-commerce.php for the second) —
 * new shared, foundational contracts get the plain `BH_` prefix rather
 * than `OUS_`/`bhcore_` (existing shared services) or any single
 * plugin's own prefix, per the roadmap doc's own naming convention.
 *
 * Contract: a content document is a TREE of typed blocks —
 *   ['type' => 'bhc/text', 'attrs' => [...], 'children' => [...]]
 * — each type registered ONCE by whichever plugin owns it, via
 * register_block_type($type, $schema, $renderer), the same
 * zero-central-registration shape OUS_Notifications/OUS_Jobs/
 * ous_debug_tools already use successfully elsewhere in this
 * ecosystem. This class owns registration, schema validation, storage,
 * and rendering; it does NOT own any particular authoring UI — a form,
 * a drag-drop canvas, or (later) something fully custom can all sit on
 * top of this same contract without this class or any block type's
 * renderer needing to change.
 *
 * Storage, per the roadmap's own recommendation (Section 3, "layer over
 * standard Gutenberg" as the first implementation rather than a
 * from-scratch canvas): a document attached to a real WordPress post
 * lives in that post's own `post_content`, using Gutenberg's existing
 * comment-delimited block JSON format — reusing `parse_blocks()`/
 * `serialize_blocks()` costs nothing and stays compatible with core WP
 * tooling (the block editor, revisions, etc.) for free. A document NOT
 * attached to a post (a lesson step tree, a tier's benefit list) uses
 * the exact same block-tree shape, just persisted as plain JSON in the
 * new `bhcore_content` table instead — same contract, different
 * storage backend, chosen by context, never by the caller reaching
 * around this class.
 *
 * `type` strings are namespaced `plugin-prefix/name` (e.g. `bhc/quiz`,
 * `bhm/tier-benefits`) — the same convention Gutenberg itself uses for
 * block names, so a WordPress-backed renderer can register a REAL
 * Gutenberg block under the identical name later without a rename.
 */
class BH_Content {
    /** @var array<string, array{schema: array, renderer: callable}> */
    private static $types = [];

    public static function init() {
        // Nothing to hook yet — registration happens via
        // register_block_type(), called by consumers on their own
        // 'init' (after this class's file has loaded, guaranteed by
        // own-ur-shit's own require order — see own-ur-shit.php).
    }

    /**
     * @param string   $type     Namespaced block type, e.g. 'bhc/quiz'.
     * @param array    $schema   Plain attribute schema:
     *                           ['attr_name' => ['type' => 'string|int|bool|array', 'default' => ...]]
     *                           — deliberately not JSON-Schema-complete; enough to
     *                           validate/coerce the shapes this ecosystem actually needs.
     * @param callable $renderer function(array $attrs, array $rendered_children, array $block): string
     */
    public static function register_block_type($type, array $schema, callable $renderer) {
        self::$types[$type] = ['schema' => $schema, 'renderer' => $renderer];
    }

    public static function is_registered($type) {
        return isset(self::$types[$type]);
    }

    public static function get_registered_types() {
        return array_keys(self::$types);
    }

    /**
     * Validate + coerce one block tree against registered schemas.
     * Unknown block types are dropped (not fataled) — a document
     * authored while a plugin was active, then read after that plugin
     * was deactivated, degrades gracefully to "that block just doesn't
     * render" rather than breaking the whole document, matching this
     * ecosystem's existing class_exists()-guarded-degrade convention.
     */
    public static function validate(array $tree) {
        $clean = [];
        foreach ($tree as $block) {
            $type = $block['type'] ?? '';
            if (!self::is_registered($type)) continue;
            $schema = self::$types[$type]['schema'];
            $attrs = [];
            foreach ($schema as $key => $def) {
                $raw = $block['attrs'][$key] ?? ($def['default'] ?? null);
                $attrs[$key] = self::coerce($raw, $def['type'] ?? 'string');
            }
            $children = isset($block['children']) && is_array($block['children'])
                ? self::validate($block['children'])
                : [];
            $clean[] = ['type' => $type, 'attrs' => $attrs, 'children' => $children];
        }
        return $clean;
    }

    private static function coerce($value, $type) {
        switch ($type) {
            case 'int':    return (int) $value;
            case 'bool':   return (bool) $value;
            case 'array':  return is_array($value) ? $value : [];
            case 'html':   return wp_kses_post((string) $value);
            case 'url':    return esc_url_raw((string) $value);
            default:       return sanitize_text_field((string) $value);
        }
    }

    /** Render a validated tree to HTML, depth-first (children before parent). */
    public static function render(array $tree) {
        $out = '';
        foreach ($tree as $block) {
            $type = $block['type'] ?? '';
            if (!self::is_registered($type)) continue;
            $rendered_children = self::render($block['children'] ?? []);
            $renderer = self::$types[$type]['renderer'];
            $out .= (string) call_user_func($renderer, $block['attrs'] ?? [], $rendered_children, $block);
        }
        return $out;
    }

    /* ---------- storage: WordPress-backed implementation ---------- */

    /**
     * @param string $context_type 'post' for a real WP post (uses post_content),
     *                              anything else is a plain namespaced context
     *                              ('bhc_lesson', 'bhm_tier_benefits', ...) stored
     *                              in bhcore_content.
     * @param int    $context_id   A post ID (context_type === 'post') or whatever
     *                              ID makes sense within that context namespace.
     */
    public static function get($context_type, $context_id) {
        if ($context_type === 'post') {
            $post = get_post((int) $context_id);
            if (!$post) return [];
            return self::gutenberg_blocks_to_tree(parse_blocks($post->post_content));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bhcore_content';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT blocks FROM $table WHERE context_type = %s AND context_id = %d",
            $context_type, (int) $context_id
        ));
        if (!$raw) return [];
        $tree = json_decode($raw, true);
        return is_array($tree) ? $tree : [];
    }

    public static function save($context_type, $context_id, array $tree) {
        $clean = self::validate($tree);

        if ($context_type === 'post') {
            $blocks = self::tree_to_gutenberg_blocks($clean);
            wp_update_post([
                'ID' => (int) $context_id,
                'post_content' => serialize_blocks($blocks),
            ]);
            return $clean;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bhcore_content';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE context_type = %s AND context_id = %d",
            $context_type, (int) $context_id
        ));
        $json = wp_json_encode($clean);
        if ($exists) {
            $wpdb->update($table, ['blocks' => $json], ['id' => (int) $exists]);
        } else {
            $wpdb->insert($table, [
                'context_type' => $context_type,
                'context_id' => (int) $context_id,
                'blocks' => $json,
            ]);
        }
        return $clean;
    }

    /* ---------- Gutenberg block <-> BH_Content tree mapping ---------- */

    // parse_blocks()'s own shape (blockName/attrs/innerBlocks/innerHTML)
    // maps almost directly onto ours (type/attrs/children) — this is
    // the whole reason storing straight into post_content costs nothing.
    private static function gutenberg_blocks_to_tree(array $blocks) {
        $tree = [];
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) continue; // freeform/classic HTML between blocks — not part of our typed tree
            $tree[] = [
                'type' => $block['blockName'],
                'attrs' => is_array($block['attrs']) ? $block['attrs'] : [],
                'children' => self::gutenberg_blocks_to_tree($block['innerBlocks'] ?? []),
            ];
        }
        return $tree;
    }

    private static function tree_to_gutenberg_blocks(array $tree) {
        $blocks = [];
        foreach ($tree as $node) {
            $inner_blocks = self::tree_to_gutenberg_blocks($node['children'] ?? []);
            $blocks[] = [
                'blockName' => $node['type'],
                'attrs' => $node['attrs'] ?? [],
                'innerBlocks' => $inner_blocks,
                'innerHTML' => '',
                'innerContent' => $inner_blocks ? array_fill(0, count($inner_blocks), null) : [''],
            ];
        }
        return $blocks;
    }
}
