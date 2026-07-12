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
 */
class BHCRM_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        if (!class_exists('BH_Element')) return $surfaces; // same guard every other BH_Element integration in this plugin uses
        $surfaces['bh-crm-profile-live'] = [
            'group'  => 'CRM',
            'label'  => 'CRM profile page (live)',
            'render' => [self::class, 'profile_preview'],
        ];
        return $surfaces;
    }

    private static function css_url() {
        return OUS_URL . 'assets/css/public-profile.css'; // real front-end profile styling (.bhi-profile*), not admin CSS — this is meant to look like the actual page, not the wp-admin detail view
    }

    public static function profile_preview() {
        $ctx = ['user_id' => 0];
        ob_start();
        ?>
<div class="bhi-profile">
    <div class="bhi-profile__header">
        <img class="bhi-profile__avatar" src="<?php echo esc_url(get_avatar_url(0, ['default' => 'mystery'])); ?>" alt="">
        <div class="bhi-profile__name">Sample person</div>
    </div>
    <?php echo BH_Element::render_slot('bh_crm_profile', 0, 'header', $ctx); ?>
    <div class="bhi-profile__bio">
        <p style="margin:0;color:var(--bh-text-dim);">This is the real, live "header" / "main column" / "sidebar" slot content for the <code>bh_crm_profile</code> surface at context 0 — add and style placements in the tree and they render here for real, not as a mockup.</p>
    </div>
    <?php echo BH_Element::render_slot('bh_crm_profile', 0, 'main', $ctx); ?>
    <?php echo BH_Element::render_slot('bh_crm_profile', 0, 'sidebar', $ctx); ?>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}
