<?php
if (!defined('ABSPATH')) exit;

/**
 * DESIGN-SUITE-PAGE-MANAGER-PLAN.md Phase 1 — data model + render path
 * only, no admin UX yet (that's Phase 2/3). Per that doc's own §0:
 * Option B was chosen — a real wp_posts row still exists (real
 * permalink, post_type, revisions/trash/search/Yoast/sitemaps, all
 * untouched), but its RENDERED BODY becomes 100% node-tree for any post
 * that opts in. This is a thin generalization of a pattern already
 * proven twice elsewhere in this ecosystem (bh-crm's CRM profile pages,
 * bh-courses' lesson pages) — one new surface, one postmeta flag, one
 * `the_content` filter, no new table.
 *
 * `_bh_design_suite_managed` (postmeta, '1' or absent) is the single
 * source of truth for "is this specific post's content owned by the
 * Design Suite or by WP's native editor" — per-POST, not per-post-type
 * (see the plan doc's §4 for why: a page-by-page opt-in, not a blanket
 * post-type switch).
 *
 * Surface context is per-POST (surface_context_id = the WP post ID),
 * same convention every other per-entity surface in this ecosystem
 * already uses (bh-crm's per-profile context = user_id, bh-courses'
 * per-lesson context = lesson_id).
 */
class OUS_PageSurface {
    const META_KEY = '_bh_design_suite_managed';

    // Plan §4: the generic page/post mechanism is scoped to WP's own
    // built-in types only — a plugin-owned CPT (bh_course, bh_lesson,
    // bh_contest entries, etc.) already has, or is getting, its own
    // purpose-built surface with its own semantics; folding it into
    // this generic one too would create two competing systems fighting
    // over the same post. `page` is recommended default-on eventually
    // (Phase 4, pending AJ's sign-off); `post` stays opt-in-only — but
    // the TOGGLE MECHANISM itself (this file) is identical for both,
    // that recommendation only affects the "+ New Page" default set in
    // a later phase.
    const MANAGED_POST_TYPES = ['page', 'post'];

    public static function init() {
        add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
        add_filter('the_content', [self::class, 'maybe_replace_content']);

        add_action('add_meta_boxes', [self::class, 'add_meta_boxes'], 10, 2);
        add_action('save_post', [self::class, 'handle_save'], 20); // 20: after other metaboxes' own save_post handlers that might read/adjust post_content

        add_action('init', [self::class, 'register_page_content_block']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_block_editor_assets']);
    }

    public static function register_element_surface($surfaces) {
        $surfaces['bh_page'] = [
            'group' => 'Site',
            'label' => 'Pages',
            'slots' => [
                'root' => ['label' => 'Page content'],
            ],
            'context' => ['type' => 'post', 'param' => 'post_id'],
            // Preview context for the builder GUI's canvas — the most
            // recently published Design-Suite-managed page stands in as
            // a representative subject, same "no single 'the' X exists
            // outside a real id" reasoning every other per-entity
            // surface's own preview_ctx already uses. Falls back to 0
            // (an empty, harmless slot) if none exist yet.
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

    // Full REPLACEMENT of $content, not an append — unlike bh-courses'
    // own lesson-extras surface (which deliberately appends AFTER a
    // pre-existing step-walker system it was careful not to touch), a
    // Design-Suite-managed page has no such pre-existing system to
    // preserve alongside; the node tree IS the page's content once
    // opted in, per the plan doc's §1.
    public static function maybe_replace_content($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        if (!$post_id || get_post_meta($post_id, self::META_KEY, true) !== '1') return $content;
        if (!class_exists('BH_Element')) return $content;

        return BH_Element::render_slot('bh_page', $post_id, 'root');
    }

    /* =================================================================
     * Phase 2 — post-edit-screen metabox (the toggle + non-destructive
     * opt-in wrap + hiding the native editor once managed).
     * ================================================================= */

    public static function add_meta_boxes($post_type, $post) {
        if (!in_array($post_type, self::MANAGED_POST_TYPES, true)) return;

        add_meta_box(
            'ous_design_suite_toggle', 'Design Suite',
            [self::class, 'render_metabox'], $post_type, 'normal', 'high'
        );
    }

    public static function render_metabox($post) {
        wp_nonce_field('ous_design_suite_toggle', 'ous_design_suite_nonce');
        $managed = get_post_meta($post->ID, self::META_KEY, true) === '1';

        // Revised after AJ's own call (2026-07-25): the Design Suite's
        // visual tree/canvas builder was deliberately deleted in an
        // earlier session in favor of WordPress's own block editor
        // (see class-style-gallery.php's docblock) — so hiding the
        // native editor and pointing at a separate "Open in Design
        // Suite" screen no longer makes sense; that screen can't show
        // or edit a placement tree, it never could after that
        // deletion. The editor stays fully visible either way now.
        // This toggle's only remaining job: whether the FRONT END
        // fully replaces this page's rendered output with its
        // bh_page/root node tree (see maybe_replace_content()) — an
        // independent, additive "Page Content (Design Suite)" block is
        // also insertable directly in the editor below for anyone who
        // wants Design-Suite-rendered content INSIDE an otherwise
        // normal page, without the full-page replacement this toggle
        // does.
        if ($managed) {
            echo '<p>This page\'s FRONT-END output is fully replaced by its Design Suite content — the editor below still works normally, but what visitors see is generated separately, not from what\'s written here.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1" checked> Replace this page\'s output with its Design Suite content</label>';
            echo '<p class="description">Turning this off does NOT delete anything — it just switches the front end back to showing this editor\'s own content (frozen as it was when you opted in). Turning it back on shows the exact same Design Suite content again.</p>';
        } else {
            echo '<p class="description">Fully replace this page\'s front-end output with a separate Design Suite node tree, instead of what\'s written in the editor below — the same underlying system as CRM profile pages and lesson extras. Most pages don\'t need this; for adding a Design-Suite-rendered section INSIDE a normal page, insert the "Page Content (Design Suite)" block below instead.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1"> Replace this page\'s output with its Design Suite content</label>';
            echo '<p class="description">Nothing in the editor below is touched unless you check this box and save — and even then, whatever you\'ve already written is kept, not discarded (wrapped as a starting node you can still edit).</p>';
        }
    }

    public static function handle_save($post_id) {
        if (!isset($_POST['ous_design_suite_nonce']) || !wp_verify_nonce($_POST['ous_design_suite_nonce'], 'ous_design_suite_toggle')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!in_array(get_post_type($post_id), self::MANAGED_POST_TYPES, true)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $checked = !empty($_POST['ous_design_suite_managed']);
        $was_managed = get_post_meta($post_id, self::META_KEY, true) === '1';

        if ($checked && !$was_managed) {
            // The single most important non-destructive guarantee in
            // the whole plan (§2): whatever was already written is
            // never silently discarded on opt-in. Auto-wrapped as one
            // starting bh/note placement in the new root slot — from
            // that point on it's a real, editable/deletable/
            // restructurable node like any other, not a frozen relic.
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
            // Opting back out is deliberately NOT fully symmetric (plan
            // §2's own last bullet, §4's judgment call) — post_content
            // stays frozen at whatever it was at opt-in time; the node
            // tree's placements are left standing (harmless orphaned
            // data if never re-enabled, and exactly what re-enabling
            // shows if it is) rather than attempting a lossy
            // reconstruction back into post_content HTML.
            delete_post_meta($post_id, self::META_KEY);
        }

        self::sync_page_content_block($post_id);
    }

    /* =================================================================
     * "Page Content (Design Suite)" block — AJ's own call (2026-07-25),
     * once it was clear the old visual tree/canvas builder had been
     * deleted (see class-style-gallery.php's docblock): rather than
     * hiding the native editor and pointing at a separate screen that
     * can no longer show a placement tree at all, this block lives
     * INSIDE the normal block editor like any other block — insert it,
     * type into it, move it, delete it, same as a paragraph or image
     * block. One bridging block, not a full per-BH_Element-type block
     * set (that's real, separate, much bigger scope than this pass —
     * a whole future rebuild of the Structure/Library layer that was
     * just deleted, not a Phase 3 add-on).
     *
     * The block's own attribute IS the authoritative content the
     * author sees/edits in Gutenberg. On save, that content is synced
     * out to a single bh/note placement in the SAME bh_page/root slot
     * Phase 1/2 already render from — so the front end still renders
     * through the existing, already-tested render_slot() call, "under
     * the hood," exactly as originally scoped, rather than needing a
     * second rendering path. A page can have both this block AND the
     * full-replacement toggle above; the toggle's the_content filter
     * simply wins when it's on (same "one clear owner" rule, not two
     * competing renderers).
     */
    public static function register_page_content_block() {
        register_block_type('bh/page-content', [
            'attributes' => [
                'content' => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => [self::class, 'render_page_content_block'],
        ]);
    }

    public static function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'ous-page-content-block',
            OUS_URL . 'assets/js/page-content-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            OUS_VER,
            true
        );
    }

    // Server-rendered (dynamic) — always reads the CURRENT placement
    // state via render_slot(), not the block's own possibly-stale
    // attribute value, so a future direct edit to the placement (via
    // REST, Debug Tools, etc.) is reflected immediately without
    // needing this specific post re-saved.
    public static function render_page_content_block($attrs) {
        $post_id = get_the_ID();
        if (!$post_id || !class_exists('BH_Element')) return '';
        return BH_Element::render_slot('bh_page', $post_id, 'root');
    }

    // Called from handle_save() above — parses the just-saved
    // post_content for this block's own current attribute value and
    // upserts ONE bh/note placement to match. A post with no
    // bh/page-content block in it is a no-op; a post with more than
    // one is deliberately unsupported in this v1 (last one wins) —
    // multiple independent Design-Suite sections per ordinary page is
    // real, plausible future scope, not assumed needed yet.
    private static function sync_page_content_block($post_id) {
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
