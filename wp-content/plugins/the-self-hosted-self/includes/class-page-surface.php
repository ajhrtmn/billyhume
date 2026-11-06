<?php
if (!defined('ABSPATH')) exit;

/**
 * Lets an ordinary page/post opt in (postmeta `_bh_design_suite_managed` = '1')
 * to having its rendered body come from the 'bh_page' element surface instead
 * of post_content. The wp_posts row stays real. Context id = the post ID.
 */
class OUS_PageSurface {
    const META_KEY = '_bh_design_suite_managed';

    // Core types only; plugin CPTs have their own surfaces.
    // WHY: opt-in only, never default. Managed mode discards post_content at
    // render, so content built in Etch would silently vanish; the
    // bh/page-content block is the Etch-safe alternative.
    const MANAGED_POST_TYPES = ['page', 'post'];

    public static function init(): void {
        add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
        add_filter('the_content', [self::class, 'maybe_replace_content']);

        add_action('add_meta_boxes', [self::class, 'add_meta_boxes'], 10, 2);
        add_action('save_post', [self::class, 'handle_save'], 20); // 20: after other metaboxes' own save_post handlers that might read/adjust post_content

        add_action('init', [self::class, 'register_page_content_block']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_block_editor_assets']);

        add_action('before_delete_post', [self::class, 'handle_delete']);
    }

    // Real delete only (not trash, which is recoverable): removes the page's
    // placements so they do not orphan in bhcore_element_placements.
    public static function handle_delete(int $post_id): void {
        if (!in_array(get_post_type($post_id), self::MANAGED_POST_TYPES, true)) return;
        if (!class_exists('BH_Element')) return;
        BH_Element::delete_context('bh_page', $post_id);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface($surfaces): array {
        $surfaces['bh_page'] = [
            'group' => 'Site',
            'label' => 'Pages',
            'slots' => [
                'root' => ['label' => 'Page content'],
            ],
            'context' => ['type' => 'post', 'param' => 'post_id'],
            // Preview context: the most recent managed page, or 0 if none.
            'preview_ctx' => function () {
                $recent = get_posts([
                    'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
                    'meta_key' => self::META_KEY, 'meta_value' => '1',
                ]);
                return ['post_id' => $recent ? (int) $recent[0] : 0];
            },
        ];
        return $surfaces;
    }

    // Full replacement of $content, not an append: the node tree IS the page.
    public static function maybe_replace_content(string $content): string {
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        if (!$post_id || get_post_meta($post_id, self::META_KEY, true) !== '1') return $content;
        if (!class_exists('BH_Element')) return $content;

        return BH_Element::render_slot('bh_page', $post_id, 'root');
    }

    /* =================================================================
     * Post-edit-screen metabox: the managed toggle.
     * ================================================================= */

    // WHY: `object`, not \WP_Post. WooCommerce HPOS order screens fire
    // add_meta_boxes with an Order object, and a \WP_Post type hint fatals there.
    public static function add_meta_boxes(string $post_type, object $post): void {
        if (!in_array($post_type, self::MANAGED_POST_TYPES, true)) return;
        if (!$post instanceof \WP_Post) return;

        add_meta_box(
            'ous_design_suite_toggle', 'Design Suite',
            [self::class, 'render_metabox'], $post_type, 'normal', 'high'
        );
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('ous_design_suite_toggle', 'ous_design_suite_nonce');
        $managed = get_post_meta($post->ID, self::META_KEY, true) === '1';

        // The toggle only controls whether the front end replaces this page's
        // output with its bh_page/root node tree; the editor stays visible.
        $etch_warning = '<p class="description" style="color:#b45309;">&#9888; If this page is built in Etch (or any tool that edits the block editor\'s own content), turning this on means whatever it builds here will never actually appear on the live site — the front end shows the Design Suite content instead, silently. Use the "Page Content (Design Suite)" block below instead if you just want Design-Suite content alongside an Etch-built page.</p>';

        if ($managed) {
            echo '<p>This page\'s FRONT-END output is fully replaced by its Design Suite content — the editor below still works normally, but what visitors see is generated separately, not from what\'s written here.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1" checked> Replace this page\'s output with its Design Suite content</label>';
            echo '<p class="description">Turning this off does NOT delete anything — it just switches the front end back to showing this editor\'s own content (frozen as it was when you opted in). Turning it back on shows the exact same Design Suite content again.</p>';
            echo $etch_warning;
        } else {
            echo '<p class="description">Fully replace this page\'s front-end output with a separate Design Suite node tree, instead of what\'s written in the editor below — the same underlying system as CRM profile pages and lesson extras. Most pages don\'t need this; for adding a Design-Suite-rendered section INSIDE a normal page, insert the "Page Content (Design Suite)" block below instead.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1"> Replace this page\'s output with its Design Suite content</label>';
            echo '<p class="description">Nothing in the editor below is touched unless you check this box and save — and even then, whatever you\'ve already written is kept, not discarded (wrapped as a starting node you can still edit).</p>';
            echo $etch_warning;
        }
    }

    public static function handle_save(int $post_id): void {
        if (!isset($_POST['ous_design_suite_nonce']) || !wp_verify_nonce($_POST['ous_design_suite_nonce'], 'ous_design_suite_toggle')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array(get_post_type($post_id), self::MANAGED_POST_TYPES, true)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $checked = !empty($_POST['ous_design_suite_managed']);
        $was_managed = get_post_meta($post_id, self::META_KEY, true) === '1';

        if ($checked && !$was_managed) {
            // WHY: never discard existing content on opt-in; it becomes the
            // first bh/note placement in the root slot.
            $post = get_post($post_id);
            $existing_content = $post ? $post->post_content : '';
            if (class_exists('BH_Element')) {
                BH_Element::save_placement([
                    'surface' => 'bh_page', 'surface_context_id' => $post_id, 'slot' => 'root',
                    'position' => 0, 'element_type' => 'bh/note',
                    'config' => ['attrs' => ['text' => $existing_content]],
                ]);
            }
            update_post_meta($post_id, self::META_KEY, '1');
        } elseif (!$checked && $was_managed) {
            // Opting out leaves post_content as frozen at opt-in and keeps the
            // placements, rather than a lossy reconstruction into HTML.
            delete_post_meta($post_id, self::META_KEY);
        }

        self::sync_page_content_block($post_id);
    }

    /* =================================================================
     * "Page Content (Design Suite)" block. Its attribute is the authored
     * content; on save it syncs to one bh/note placement in bh_page/root, so the
     * front end renders through render_slot(). If the full-replacement toggle
     * is also on, its the_content filter wins.
     * ================================================================= */
    public static function register_page_content_block(): void {
        register_block_type('bh/page-content', [
            'attributes' => [
                'content' => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => [self::class, 'render_page_content_block'],
        ]);
    }

    public static function enqueue_block_editor_assets(): void {
        wp_enqueue_script(
            'ous-page-content-block',
            OUS_URL . 'assets/js/page-content-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            OUS_VER,
            true
        );
    }

    // Dynamic: reads current placement state via render_slot(), not the
    // possibly stale block attribute.
    /** @param array<string, mixed> $attrs */
    public static function render_page_content_block($attrs): string {
        $post_id = get_the_ID();
        if (!$post_id || !class_exists('BH_Element')) return '';
        return BH_Element::render_slot('bh_page', $post_id, 'root');
    }

    // Upserts one bh/note placement from this post's bh/page-content block.
    // No block is a no-op; with several blocks, the last one wins.
    private static function sync_page_content_block(int $post_id): void {
        if (!class_exists('BH_Element')) return;
        $post = get_post($post_id);
        if (!$post) return;

        $blocks = parse_blocks($post->post_content);
        $text = null;
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'bh/page-content') {
                $text = (string) ($block['attrs']['content'] ?? '');
            }
        }
        if ($text === null) return; // no such block in this save — nothing to sync

        $existing = BH_Element::get_placements('bh_page', $post_id, 'root');
        $placement = [
            'surface' => 'bh_page', 'surface_context_id' => $post_id, 'slot' => 'root',
            'position' => 0, 'element_type' => 'bh/note',
            'config' => ['attrs' => ['text' => $text]],
        ];
        if ($existing) $placement['id'] = (int) $existing[0]['id']; // update in place rather than accumulating duplicate rows on every save

        BH_Element::save_placement($placement);
    }
}
