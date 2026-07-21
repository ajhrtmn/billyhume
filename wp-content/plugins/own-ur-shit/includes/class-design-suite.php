<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the top-level "Design Suite" admin menu (slug bh-design).
 * Its own callback deliberately reuses BHY_Gallery::render() rather than
 * a placeholder — that render() method is the real unified shell: a
 * "Site Styles" tab and a "Widgets & Elements" tab
 * (BH_Element_Builder::render_shell(), inlined) in the SAME page,
 * switched with plain JS, never a navigation. class-style-gallery.php's
 * and class-studio.php's own add_menu() methods register their slugs
 * ('bh-style', 'bh-studio') with a null/hidden parent instead of a
 * second visible menu row — deep links to those slugs still resolve,
 * they just don't appear in the sidebar. BH_Studio's Content Studio has
 * no menu entry at all; its canvas opens as a modal iframe from inside
 * this unified shell instead. BHY_Gallery::enqueue_media() also
 * enqueues the media picker on this 'bh-design' hook suffix, not just
 * 'bh-style'.
 *
 * Follows DESIGN-SUITE-UNIFICATION-PLAN.md §1.4's six standalone-page
 * mitigation rules:
 *   1. Unconditional registration — no is_locked()/environment
 *      conditional wraps this add_menu_page() call.
 *   2. Default admin_menu priority (10) — registered directly at file
 *      load (own-ur-shit.php), same style as OUS_Dashboard::add_menu().
 *   3. A real callback (BHY_Gallery::render()), never an empty string.
 *   4. Gated on 'bhcore_design_site' — a capability OUS_Roles grants to
 *      administrator AND editor on the 'init' hook, which always fires
 *      before 'admin_menu', so the capability is genuinely present at
 *      registration time for both roles.
 *   5. A TOP-LEVEL menu (add_menu_page()), not a submenu of a submenu —
 *      the exact shape §1.4 identifies as proven-working in this
 *      ecosystem (own-ur-shit, ous-debug) versus the failure-prone shape
 *      (both broken pages were submenus of the ous-debug submenu chain).
 *   6. Registration-result logging via OUS_DebugLog::log_throttled(),
 *      the same pattern class-api-docs.php/class-codebase-docs.php use.
 *
 * NOT runtime-verified — no live WordPress/browser/PHP execution is
 * available in this environment. This install has a documented,
 * multi-session, never-fully-root-caused history with exactly this class
 * of bug (see class-api-docs.php's docblock and VISION.md's "New
 * dev/admin-only pages default to a Debug Tools SECTION" entry) —
 * smoke-test this menu especially carefully, ideally logged in as a
 * non-admin 'editor'-role account holding only 'bhcore_design_site' (not
 * as an admin, whose manage_options masks capability-scoping bugs), and
 * ideally after an OPcache reset, before relying on this in production.
 */
class BH_Design_Suite {
    const CAP = 'bhcore_design_site';

    public static function add_menu() {
        $hook = add_menu_page('Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render'], OUS_MenuIcons::design_suite(), 4);
        self::log_result('bh-design (top-level)', $hook);

        // Relabels the auto-created duplicate first submenu item, same
        // trick OUS_Dashboard::add_menu() already uses for 'own-ur-shit'.
        $hook2 = add_submenu_page('bh-design', 'Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render']);
        self::log_result('bh-design (relabeled first submenu)', $hook2);
    }

    // Only the failure case is worth a log row — avoids an INFO row on
    // every successful registration, every admin page load.
    private static function log_result($what, $hook) {
        if ($hook !== false || !class_exists('OUS_DebugLog')) return;
        OUS_DebugLog::log('error',
            'add_menu_page()/add_submenu_page() for ' . $what . ' FAILED (returned false).',
            ['current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BH_Design_Suite::add_menu()'
        );
    }
}
