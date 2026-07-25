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

        add_action('current_screen', [self::class, 'maybe_hide_editor']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes'], 10, 2);
        add_action('save_post', [self::class, 'handle_save'], 20); // 20: after other metaboxes' own save_post handlers that might read/adjust post_content
    }

    // Real bug, caught live: remove_post_type_support('editor') called
    // from inside 'add_meta_boxes' DOES hide the classic editor
    // screen's content box (edit-form-advanced.php checks support at
    // render time, after that hook fires) but has NO effect on the
    // block editor — Gutenberg decides whether to show the content
    // canvas much earlier in the request (building the editor's own
    // settings/REST schema), before 'add_meta_boxes' ever fires.
    // Confirmed live: a managed page's title AND its old paragraph
    // content were both still fully visible/editable in the block
    // editor canvas after this call ran too late. 'current_screen'
    // fires early enough on every wp-admin page load (well before
    // block-editor initialization) for the support removal to actually
    // take effect for both editors — the exact hook several real page-
    // builder plugins use for this same "replace the block editor
    // canvas" technique.
    public static function maybe_hide_editor($screen) {
        if (!$screen || $screen->base !== 'post' || !in_array($screen->post_type, self::MANAGED_POST_TYPES, true)) return;
        $post_id = (int) ($_GET['post'] ?? $_POST['post_ID'] ?? 0);
        if (!$post_id || get_post_meta($post_id, self::META_KEY, true) !== '1') return;
        remove_post_type_support($screen->post_type, 'editor');
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

        if ($managed) {
            $design_suite_url = add_query_arg(['page' => 'bh-design', 'surface' => 'bh_page', 'context_id' => $post->ID], admin_url('admin.php'));
            echo '<p>This page\'s content is managed by the Design Suite — the content editor above is hidden because editing it here would have no effect on what visitors see.</p>';
            echo '<p><a href="' . esc_url($design_suite_url) . '" class="button button-primary button-hero">Open in Design Suite &rarr;</a></p>';
            echo '<p class="description">Turning the toggle below off does NOT delete what you\'ve built — it just switches this page back to showing its old native content (frozen as it was when you opted in). Your Design Suite content is still there if you turn it back on.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1" checked> Build this page with Design Suite</label>';
        } else {
            echo '<p class="description">Build this page\'s content as a Design Suite node tree instead of the editor above — the same builder used for CRM profile pages, lesson extras, and the rest of this ecosystem\'s "no special-cased pages" system.</p>';
            echo '<label><input type="checkbox" name="ous_design_suite_managed" value="1"> Build this page with Design Suite</label>';
            echo '<p class="description">Nothing in the editor above is touched unless you check this box and save — and even then, whatever you\'ve already written is kept, not discarded (see below).</p>';
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
    }
}
