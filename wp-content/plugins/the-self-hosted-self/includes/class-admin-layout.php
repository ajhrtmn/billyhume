<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure-CSS wide layout for CPT edit screens: flattens WP's meta box
 * containers into a multi-column card layout. Meta box contexts and
 * postboxes.js drag-reorder are untouched; below BREAKPOINT nothing changes.
 * Opt in via the 'ous_wide_admin_layout_post_types' filter.
 */
class OUS_AdminLayout {
    // WP core's admin-menu collapse point; the column layout itself is fluid.
    const BREAKPOINT = 782; // px

    public static function init(): void {
        add_action('admin_head-post.php', [self::class, 'maybe_print_css']);
        add_action('admin_head-post-new.php', [self::class, 'maybe_print_css']);
    }

    /** @return array<int, string> */
    private static function default_post_types(): array {
        return ['bh_contest', 'bh_submission', 'bhm_tier', 'bh_course'];
    }

    public static function maybe_print_css(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post') return;
        $post_types = apply_filters('ous_wide_admin_layout_post_types', self::default_post_types());
        if (!in_array($screen->post_type, $post_types, true)) return;

        $bp = self::BREAKPOINT;
        echo '<style id="ous-admin-wide-layout">
        @media (min-width: ' . (int) $bp . 'px) {
            /* No distinct sidebar column at all — every meta box
               (Publish included) becomes one card in a single uniform
               grid, regardless of whether WordPress itself considers
               it \'normal\', \'side\', or \'advanced\' priority.
               \'side\'/\'normal\'/\'advanced\' still control default
               ordering (grid auto-flow follows DOM order) and which
               drag-reorder GROUP a box belongs to (WordPress\'s own
               postboxes.js keeps those three sortable groups
               independent) — this only changes how they\'re
               positioned on screen, not that underlying grouping.
               display:contents on every intermediate wrapper
               (#post-body-content, #postbox-container-1/-2,
               #normal-sortables, #side-sortables, #advanced-sortables)
               flattens WordPress\'s nested container structure for
               LAYOUT purposes only — the elements themselves stay in
               the DOM exactly where WordPress put them (so its own
               JS, which operates on the DOM/event model rather than
               visual position, keeps working), they just stop
               generating their own box, letting every .postbox several
               levels deep become a direct grid item on #post-body
               itself. Handles both DOM shapes a CPT can have — a
               normal post-editor screen and a title-only one
               (bh_contest/bh_submission), which previously needed two
               different CSS strategies due to a WordPress core
               template difference between the two. */
            #poststuff #post-body,
            #poststuff #post-body-content,
            #poststuff #postbox-container-1,
            #poststuff #postbox-container-2,
            #poststuff #normal-sortables,
            #poststuff #side-sortables,
            #poststuff #advanced-sortables {
                display: contents;
            }

            /* CSS Grid lays boxes out in strict rows, so a short card
               next to a tall one (Publish next to the much longer
               Contest Rules & Results) leaves a dead gap under the
               short one; grid-auto-flow:dense only backfills later
               gaps, it can\'t fix a gap directly beside a tall
               neighbor in the same row. CSS multi-column layout (not
               CSS Grid) gives genuine top-to-bottom masonry-style
               packing instead, no build step or JS measurement pass
               needed. Tradeoff: reading order becomes top-to-bottom-
               then-next-column (newspaper-style) rather than grid\'s
               left-to-right-then-wrap — acceptable since these are
               independent cards, not a sequence read in a specific
               order. */
            #poststuff {
                columns: 360px 3;
                column-gap: 16px;
            }

            /* The layout-only fix above just rearranged stock
               WordPress metaboxes, still flat white boxes with a
               harsh 1px border and a cramped header. This ecosystem
               already has a design system for exactly this —
               the-self-hosted-self\'s --bhy-* tokens (class-ui.php) — already
               loaded on every one of these screens
               (print_design_system_css() fires on any screen id
               containing "bh"/"ous"), just never applied to WordPress
               core\'s own .postbox chrome before now. Reuses the same
               tokens .bhy-card uses elsewhere rather than inventing a
               second visual language. */
            #poststuff .postbox {
                /* break-inside:avoid is the multi-column equivalent of
                   grid\'s own "don\'t split a card across two rows" —
                   here it stops a card being split across two
                   COLUMNS instead. margin-bottom (not grid\'s gap)
                   is what creates vertical space between stacked
                   cards within a column now. */
                break-inside: avoid;
                margin: 0 0 16px;
                background: var(--bhy-surface, #fff);
                border: 1px solid var(--bhy-border, #dcdcde);
                border-radius: var(--bhy-radius, 8px);
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }
            #poststuff .postbox .postbox-header {
                border-bottom: 1px solid var(--bhy-border, #dcdcde);
                min-height: auto;
            }
            #poststuff .postbox .postbox-header h2.hndle {
                font-size: var(--bhy-text-sm, 12px);
                text-transform: uppercase;
                letter-spacing: .04em;
                color: var(--bhy-ink-dim, #646970);
                padding: var(--bhy-space-3, 12px) var(--bhy-space-4, 16px);
            }
            #poststuff .postbox .inside {
                padding: var(--bhy-space-4, 16px) var(--bhy-space-5, 20px);
                margin: 0;
                font-size: var(--bhy-text-base, 13px);
            }
            #poststuff .postbox:hover {
                box-shadow: 0 2px 6px rgba(0, 0, 0, .07);
            }

            /* The title field isn\'t a .postbox — it\'s WordPress\'s own
               #titlediv, a direct child of #post-body-content (now
               flattened into #poststuff\'s own grid alongside every
               card) — full-width across the top rather than becoming
               just another narrow card, with the same card treatment
               as everything else instead of WordPress\'s bare default
               (a single oversized borderless input floating on the
               page background). */
            #poststuff #titlediv,
            #poststuff #titlewrap {
                /* column-span:all is the multi-column equivalent of
                   grid\'s "grid-column: 1 / -1" — breaks the title out
                   of the column flow to sit full-width above it,
                   pushing every card below into a fresh set of
                   columns starting under it. */
                column-span: all;
                background: var(--bhy-surface, #fff);
                border: 1px solid var(--bhy-border, #dcdcde);
                border-radius: var(--bhy-radius, 8px);
                padding: var(--bhy-space-2, 8px) var(--bhy-space-4, 16px);
                margin-bottom: 16px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }
            #poststuff #titlewrap #title { border: none; box-shadow: none; }

        }
        </style>';
    }
}
