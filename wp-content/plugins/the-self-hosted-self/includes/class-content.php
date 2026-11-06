<?php
if (!defined('ABSPATH')) exit;

/**
 * Content-block interface: a document is a tree of typed blocks
 *   ['type' => 'bhc/text', 'attrs' => [...], 'children' => [...]]
 * with each type registered once by its owning plugin. Owns registration,
 * validation, storage and rendering, not any authoring UI.
 * Storage: a post-attached document lives in post_content as Gutenberg
 * block markup; any other context is JSON in bhcore_content.
 * Type names are `prefix/name`, matching Gutenberg block naming.
 */
class BH_Content {
    /** @var array<string, array{schema: array<string, mixed>, renderer: callable}> */
    private static $types = [];

    public static function init(): void {
        // Registration happens via register_block_type() from consumers.
    }

    /**
     * @param string   $type     Namespaced block type, e.g. 'bhc/quiz'.
     * @param array<string, mixed> $schema ['attr' => ['type' => 'string|int|bool|array|html|url', 'default' => ...]]
     * @param callable $renderer function(array $attrs, array $rendered_children, array $block): string
     */
    public static function register_block_type(string $type, array $schema, callable $renderer): void {
        self::$types[$type] = ['schema' => $schema, 'renderer' => $renderer];
    }

    public static function is_registered(string $type): bool {
        return isset(self::$types[$type]);
    }

    /** @return array<int, string> */
    public static function get_registered_types(): array {
        return array_keys(self::$types);
    }

    /**
     * Validate + coerce a block tree. Unknown types are dropped, so a
     * deactivated plugin's blocks just don't render.
     */
    /**
     * @param array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    public static function validate(array $tree): array {
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

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerce($value, string $type) {
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
    /** @param array<int, array<string, mixed>> $tree */
    public static function render(array $tree): string {
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
     * $context_type 'post' uses post_content; any other namespaced context
     * ('bhc_lesson', ...) is stored in bhcore_content.
     */
    /** @return array<int, array<string, mixed>> */
    public static function get(string $context_type, int $context_id): array {
        if ($context_type === 'post') {
            $post = get_post((int) $context_id);
            if (!$post) return [];
            return self::gutenberg_blocks_to_tree(parse_blocks($post->post_content));
        }

        global $wpdb;
        $table = OUS_Tables::content();
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT blocks FROM $table WHERE context_type = %s AND context_id = %d",
            $context_type, (int) $context_id
        ));
        if (!$raw) return [];
        $tree = json_decode($raw, true);
        return is_array($tree) ? $tree : [];
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    public static function save(string $context_type, int $context_id, array $tree): array {
        $clean = self::validate($tree);

        if ($context_type === 'post') {
            $blocks = self::tree_to_gutenberg_blocks($clean);
            // WHY: wp_update_post() unslashes every field, which corrupts the
            // < escapes serialize_blocks() emits; wp_slash() counters it
            // (as core's REST posts controller does).
            wp_update_post(wp_slash([
                'ID' => (int) $context_id,
                'post_content' => serialize_blocks($blocks),
            ]));
            return $clean;
        }

        global $wpdb;
        $table = OUS_Tables::content();
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

    // parse_blocks()'s shape maps almost directly onto ours.
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function gutenberg_blocks_to_tree(array $blocks): array {
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

    /**
     * @param array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private static function tree_to_gutenberg_blocks(array $tree): array {
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
