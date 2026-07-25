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

    public static function init() {
        add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
        add_filter('the_content', [self::class, 'maybe_replace_content']);
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
}
