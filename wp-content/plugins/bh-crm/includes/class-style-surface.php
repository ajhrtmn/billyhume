<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.3.2 — direct response to a live screenshot showing the Design
 * Suite's canvas rendering an unrelated bh-contest demo page no matter
 * what CRM tree node was selected. Every OTHER plugin's own bhy_style_
 * surfaces registration (bh-contest/includes/class-style-surfaces.php,
 * bh-streaming/includes/class-style-surface.php, etc.) is a hand-
 * authored, hardcoded HTML mockup — genuinely fine for those, since
 * their real pages are outside wp-admin and can't be iframed directly.
 * This one is deliberately different: it renders the ACTUAL, LIVE
 * 'bh_crm_profile' surface's header/main/sidebar slots via BH_Element::
 * render_slot() — the exact same call class-people.php's real detail
 * page makes — so what you see in the canvas IS what you're building,
 * not a canned stand-in. Context id 0 is used (the tree/inspector's own
 * default surfaceContext for every surface until a real per-entity id
 * is picked — class-element-builder.php's own docblock), so this always
 * matches whatever the tree/inspector are showing by default.
 *
 * This is step one of DESIGN-SUITE-UNIFICATION-PLAN.md's "no special-
 * cased pages" direction (see that doc's own updated status note): a
 * real live-rendered page in the canvas, using the real slot data,
 * instead of a disconnected demo. It does NOT yet replace class-
 * people.php's own admin detail page with pure node-tree rendering —
 * that page still has its own fixed wp-admin chrome (identity header,
 * fields table, tags/notes editors, project tracker section) outside
 * BH_Element entirely, which is a separate, larger follow-up honestly
 * out of scope for this pass. What this DOES prove end-to-end: a slot's
 * content, once you add/style placements in it, shows up for real here
 * — no admin-only, no fabricated stand-in.
 *
 * DRY fix, own-ur-shit 3.4.48/3.4.50 QA pass: own-ur-shit's own
 * class-style-gallery.php later grew a GENERIC auto-story generator for
 * every registered BH_Element surface (BH_Element::render_surface_preview()),
 * keyed by each surface's REAL slug — but this file was still registering
 * its nicer, hand-styled version under a DIFFERENT key
 * ('bh-crm-profile-live'), so both ended up listed side by side in the
 * Live Views tab, showing near-duplicate content under two different
 * names. Fixed by registering under the surface's own real slug
 * ('bh_crm_profile') instead — class-style-gallery.php's auto-generator
 * explicitly skips creating a story for any key that already has a
 * hand-authored one, so this one nicer version now wins outright rather
 * than existing alongside a redundant plain fallback. This also means
 * this story's key now matches what the tree's own selection-sync
 * (element-builder.js's fireSelectionEvent()/'bhel:select-surface'
 * listener) actually looks for, so picking this Live View now correctly
 * selects the matching Surface node in the tree too — previously it
 * couldn't, since 'bh-crm-profile-live' was never a real surface slug
 * the tree recognized.
 */
class BHCRM_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        if (!class_exists('BH_Element')) return $surfaces; // same guard every other BH_Element integration in this plugin uses
        $surfaces['bh_crm_profile'] = [
            'group'  => 'CRM',
            'label'  => 'CRM profile page (live)',
            'render' => [self::class, 'profile_preview'],
        ];
        // Design Suite gallery gap: the kanban Project Tracker
        // (class-projects.php/kanban-board.js) has real, shipped UI
        // that was never registered here at all — the gallery only
        // ever showed the profile page. This is a hand-authored
        // mockup (like bh-contest/bh-courses/etc.'s own surfaces), not
        // a live BH_Element render — the kanban board is its own
        // separate system with its own DB table, entirely built
        // client-side from JSON, not something render_slot() reaches.
        $surfaces['bh-crm-kanban'] = [
            'group'  => 'CRM',
            'label'  => 'Project Tracker (kanban)',
            'render' => [self::class, 'kanban_preview'],
        ];
        return $surfaces;
    }

    public static function kanban_preview() {
        ob_start();
        ?>
<div style="background:#f0f0f1;color:#1d2327;padding:8px;">
<div class="bhcrm-kanban-board" style="display:flex;gap:12px;padding:12px;overflow-x:auto;">
    <div class="bhcrm-kanban-column" data-column="To Do" style="min-width:220px;">
        <div class="bhcrm-kanban-column-header">To Do<span class="bhcrm-kanban-column-count"> (2)</span></div>
        <div class="bhcrm-kanban-column-cards">
            <div class="bhcrm-kanban-card">
                <div class="bhcrm-kanban-card-drag-handle">&#8942;&#8942;</div>
                <div class="bhcrm-kanban-card-title-row">Finalize tour dates</div>
            </div>
            <div class="bhcrm-kanban-card">
                <div class="bhcrm-kanban-card-drag-handle">&#8942;&#8942;</div>
                <div class="bhcrm-kanban-card-title-row">Book studio time</div>
            </div>
        </div>
    </div>
    <div class="bhcrm-kanban-column" data-column="In Progress" style="min-width:220px;">
        <div class="bhcrm-kanban-column-header">In Progress<span class="bhcrm-kanban-column-count"> (1)</span></div>
        <div class="bhcrm-kanban-column-cards">
            <div class="bhcrm-kanban-card">
                <div class="bhcrm-kanban-card-drag-handle">&#8942;&#8942;</div>
                <div class="bhcrm-kanban-card-title-row">Mix single #3</div>
            </div>
        </div>
    </div>
    <div class="bhcrm-kanban-column" data-column="Done" style="min-width:220px;">
        <div class="bhcrm-kanban-column-header">Done<span class="bhcrm-kanban-column-count"> (1)</span></div>
        <div class="bhcrm-kanban-column-cards">
            <div class="bhcrm-kanban-card is-done">
                <div class="bhcrm-kanban-card-drag-handle">&#8942;&#8942;</div>
                <div class="bhcrm-kanban-card-title-row">Release cover art</div>
            </div>
        </div>
    </div>
</div>
</div>
        <?php
        return ['css_url' => BHCRM_URL . 'assets/css/kanban-board.css', 'html' => ob_get_clean()];
    }

    private static function css_url() {
        return OUS_URL . 'assets/css/public-profile.css'; // real front-end profile styling (.bhi-profile*), not admin CSS — this is meant to look like the actual page, not the wp-admin detail view
    }

    public static function profile_preview() {
        // 1.3.3 — updated for the single 'root' slot (register_element_
        // surface()'s own docblock, "NO SPECIAL-CASED PAGES" Step 1) —
        // was three separate render_slot() calls (header/main/sidebar),
        // now one, matching the real page's own render_detail().
        $ctx = ['user_id' => 0];
        ob_start();
        ?>
<div class="bhi-profile">
    <div class="bhi-profile__header">
        <img class="bhi-profile__avatar" src="<?php echo esc_url(get_avatar_url(0, ['default' => 'mystery'])); ?>" alt="">
        <div class="bhi-profile__name">Sample person</div>
    </div>
    <div class="bhi-profile__bio">
        <p style="margin:0;color:var(--bh-text-dim);">This is the real, live "root" slot content for the <code>bh_crm_profile</code> surface at context 0 — add and style placements in the tree and they render here for real, not as a mockup. No more separate header/main/sidebar zones — build whatever layout you want directly.</p>
    </div>
    <?php echo BH_Element::render_slot('bh_crm_profile', 0, 'root', $ctx); ?>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}
