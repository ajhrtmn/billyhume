<?php
if (!defined('ABSPATH')) exit;

/**
 * Wide multi-column card layout for CPT edit screens (pure CSS; drag-reorder
 * untouched; off below BREAKPOINT). Opt in via ous_wide_admin_layout_post_types.
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
            /* One uniform card grid, no sidebar column. display:contents flattens the
               meta box wrappers for layout only, so postboxes.js drag groups still work;
               covers both normal and title-only (bh_contest/bh_submission) screens. */
            #poststuff #post-body,
            #poststuff #post-body-content,
            #poststuff #postbox-container-1,
            #poststuff #postbox-container-2,
            #poststuff #normal-sortables,
            #poststuff #side-sortables,
            #poststuff #advanced-sortables {
                display: contents;
            }

            /* Multi-column (not Grid) packs cards top-to-bottom like masonry, so a short
               card beside a tall one leaves no gap. Reading order becomes column-first,
               which is fine for independent cards. */
            #poststuff {
                columns: 360px 3;
                column-gap: 16px;
            }

            /* Give the stock meta boxes the same --bhy-* card treatment as .bhy-card,
               instead of flat white boxes with a harsh border. */
            #poststuff .postbox {
                /* Keep a card in one column; margin-bottom spaces stacked cards. */
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

            /* The title field (#titlediv) spans the full width above the cards, with
               the same card treatment. */
            #poststuff #titlediv,
            #poststuff #titlewrap {
                /* column-span: all breaks the title out of the column flow. */
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
