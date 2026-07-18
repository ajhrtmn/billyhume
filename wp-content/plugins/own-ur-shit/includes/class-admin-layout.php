<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_AdminLayout — a shared, pure-CSS fix for a real pattern across
 * this ecosystem: several custom post types (bh_contest, bh_submission,
 * bhm_tier, bh_course) still use WordPress's default two-column
 * post-edit chrome — one narrow stacked column of meta boxes plus a
 * fixed ~280px sidebar — on screens with far more horizontal room than
 * that layout ever uses. AJ's own framing, after looking at the
 * contest edit screen specifically: "many admin pages suffer from the
 * same issues." Confirmed live: the sidebar's own "Contest Rules &
 * Results" box was visibly overflowing its column with real content
 * (submission window fields, contact-info checkboxes, a Discord
 * webhook field), while the main column sat mostly empty to its right.
 *
 * This does NOT move any meta box between 'normal'/'side' registration
 * context (that's a DOM-container change, not a CSS one, and would
 * mean re-deciding which box belongs where in every affected plugin).
 * Instead: normal-context boxes flow into a real CSS grid
 * (#normal-sortables), and the sidebar column widens substantially on
 * wide viewports — both changes WordPress's own metabox drag-reorder
 * JS (postboxes.js, jQuery UI Sortable) tolerates fine, since it only
 * ever reorders DOM nodes, never assumes a fixed single-column layout.
 * Below OUS_AdminLayout::BREAKPOINT this is a complete no-op — narrow
 * viewports keep WordPress's own default single-column layout exactly
 * as before.
 *
 * Opt-in per post type via the 'ous_wide_admin_layout_post_types'
 * filter (default list below) — any plugin can add its own post type
 * without touching this file, same zero-central-registration shape
 * OUS_Notifications/OUS_Jobs/ous_debug_tools already use elsewhere in
 * this ecosystem.
 */
class OUS_AdminLayout {
    // Real bug, caught live: 1200px was an all-or-nothing gate, not a
    // graceful degradation point — the masonry treatment below
    // (`columns: 360px 3`) is ALREADY fluid on its own (the browser
    // computes min(3, floor(available_width / 360)) columns, naturally
    // collapsing to 2 then 1 as the viewport narrows, no media query
    // needed for that part). The only thing gated on this constant is
    // whether the nicer card treatment applies AT ALL — so anywhere
    // between roughly 850px (WordPress's own admin sidebar collapse
    // point) and 1200px got the OLD cramped stock two-column layout
    // back with zero warning, the exact "sidebar overflowing, main
    // column empty" problem this whole class exists to fix. Lowered to
    // WordPress core's own well-known admin-menu collapse breakpoint
    // (782px, where wp-admin's sidebar itself already goes icon-only) —
    // below that is genuinely mobile/tablet territory where WP's own
    // plain stacked default is the right call; everything wider now
    // gets the masonry treatment, closing the awkward gap.
    const BREAKPOINT = 782; // px — below this, WordPress's own default layout is left untouched

    public static function init() {
        add_action('admin_head-post.php', [self::class, 'maybe_print_css']);
        add_action('admin_head-post-new.php', [self::class, 'maybe_print_css']);
    }

    private static function default_post_types() {
        return ['bh_contest', 'bh_submission', 'bhm_tier', 'bh_course'];
    }

    public static function maybe_print_css() {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post') return;
        $post_types = apply_filters('ous_wide_admin_layout_post_types', self::default_post_types());
        if (!in_array($screen->post_type, $post_types, true)) return;

        $bp = self::BREAKPOINT;
        echo '<style id="ous-admin-wide-layout">
        @media (min-width: ' . (int) $bp . 'px) {
            /* AJ\'s own call after seeing the first cut of this fix:
               "the sidebar is awful and should be the main content,
               but better laid out and streamlined... I\'d be fine with
               no sidebar, just a bunch of well laid out widgets." No
               more distinct sidebar column at all — every meta box
               (Publish included) becomes one card in a single uniform
               grid, regardless of whether WordPress itself considers
               it \'normal\', \'side\', or \'advanced\' priority.
               \'side\'/\'normal\'/\'advanced\' still control DEFAULT
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
               itself. Confirmed live this handles BOTH DOM shapes a
               CPT can have — a normal post-editor screen AND a
               title-only one (bh_contest/bh_submission), which
               previously needed two different CSS strategies because
               of a real WordPress core template difference between
               the two (see this file\'s git history for the full story
               if that distinction ever matters again). */
            #poststuff #post-body,
            #poststuff #post-body-content,
            #poststuff #postbox-container-1,
            #poststuff #postbox-container-2,
            #poststuff #normal-sortables,
            #poststuff #side-sortables,
            #poststuff #advanced-sortables {
                display: contents;
            }

            /* AJ\'s own follow-up: "no so much white space cuz of the
               grid... it just looks wrong." Fair — CSS Grid lays boxes
               out in strict ROWS, so a short card next to a tall one
               (Publish next to the much longer Contest Rules &
               Results) leaves a large dead gap under the short one;
               grid-auto-flow:dense only backfills LATER gaps, it can\'t
               fix a gap directly beside a tall neighbor in the same
               row. Real fix: CSS multi-column layout (not CSS Grid) —
               genuine top-to-bottom masonry-style packing, no build
               step or JS measurement pass needed. The one real
               tradeoff: reading order becomes top-to-bottom-then-next-
               column (newspaper-style) rather than grid\'s left-to-
               right-then-wrap — acceptable here since these are
               independent cards, not a sequence that has to be read
               in a specific order. */
            #poststuff {
                columns: 360px 3;
                column-gap: 16px;
            }

            /* AJ\'s own follow-up after seeing the layout-only fix
               live: "it looks awful in the screenshots" — fair; the
               above only rearranged stock WordPress metaboxes, still
               flat white boxes with a harsh 1px border and a cramped
               header. This ecosystem already has a real design system
               for exactly this — own-ur-shit\'s --bhy-* tokens
               (class-ui.php) — and it\'s already loaded on every one of
               these screens (print_design_system_css() fires on any
               screen id containing "bh"/"ous", which every post type
               in this ecosystem does), just never applied to WordPress
               core\'s own .postbox chrome before now. Reusing the exact
               tokens .bhy-card itself uses elsewhere, rather than
               inventing a second visual language. */
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
