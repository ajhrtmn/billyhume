<?php
if (!defined('ABSPATH')) exit;

/**
 * 3.4.31 UPDATE — the one remaining accidental duplication the QA
 * walkthrough flagged: BHY_Gallery's own add_menu() (class-style-
 * gallery.php) was ALSO registering 'bh-style' as its own second,
 * independently-visible submenu under this class's 'bh-design' parent —
 * so "Design Suite" (this class's top-level entry) and "Designer" (that
 * second entry) were two separate sidebar rows both landing on
 * BHY_Gallery::render()'s identical output. class-style-gallery.php's
 * add_menu() now registers 'bh-style' with a null/hidden parent instead
 * (same pattern class-studio.php already uses for 'bh-studio') — the
 * slug still resolves for existing deep links/redirects, it's just no
 * longer a second visible menu row. This class (BH_Design_Suite) needed
 * NO code change for that fix, same as the 3.4.30 pass below — the fix
 * lived entirely in the other registration.
 *
 * 3.4.30 UPDATE — DESIGN-SUITE-UNIFICATION-PLAN.md's real, final
 * structural fix. The Phase 1 note below ("menu relocation ONLY... no
 * unified shell exists yet") is now OUT OF DATE and was itself a
 * misread of what was actually asked for: even after the later
 * "Phase 3" three-pane build shipped (BH_Element_Builder), it landed as
 * a SEPARATE 'bh-element-builder' submenu next to 'bh-style' — three
 * adjacent pages under one parent menu, not one interface. Per the
 * user's explicit, repeated correction ("there is no difference between
 * the two" / "the designer is the builder"), that was wrong. As of
 * 3.4.30, add_menu() below still does exactly what it already did —
 * registers ONE top-level 'bh-design' menu whose own callback IS
 * BHY_Gallery::render() — but that render() method itself is now the
 * real unified shell: a "Site Styles" tab and a "Widgets & Elements"
 * tab (BH_Element_Builder::render_shell(), inlined) in the SAME page,
 * switched with plain JS, never a navigation. BH_Studio's Content
 * Studio no longer has ANY menu entry at all (class-studio.php's
 * add_menu() now registers with a null/hidden parent) — its canvas
 * opens as a modal iframe from inside the unified shell instead. This
 * class (BH_Design_Suite) itself needed NO code change for this pass —
 * it was already pointed at the one real page; the fix lived entirely
 * in what that page renders and in retiring the OTHER two pages'
 * add_menu() hookups (class-element-builder.php, class-studio.php,
 * own-ur-shit.php).
 *
 * Original Phase 1 note, kept for history:
 * DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (§4 item 1, §1.1/§1.4) — the
 * new top-level "Design Suite" admin menu (slug bh-design). This phase
 * is menu relocation ONLY: no unified Style+Element inspector shell
 * exists yet (that's Phase 3, §2.1 — the real BH_Design_Suite::render()
 * three-pane screen this class name is reserved for). Existing pages
 * (BHY_Gallery's Style page, BH_Studio's Content Studio) just move under
 * this new parent — see class-style-gallery.php's and class-studio.php's
 * own add_menu() methods, which now pass 'bh-design' as their parent
 * slug instead of 'own-ur-shit' (their own page slugs — bh-style,
 * bh-studio — and every existing admin.php?page=... deep link to them
 * are unchanged).
 *
 * This top-level menu's OWN callback (both the add_menu_page() entry and
 * the relabeled first submenu, mirroring OUS_Dashboard::add_menu()'s own
 * "top-level + same-slug submenu relabel" trick in class-dashboard.php)
 * deliberately REUSES BHY_Gallery::render() — the real, already-working
 * Style page — rather than a placeholder/stub. Per §1.4 rule 3 ("real
 * callback, not stub"), and since there is genuinely nothing else real
 * to land on yet at 'bh-design' itself until Phase 3 builds the actual
 * unified shell, pointing the landing page at the one real screen this
 * menu already contains is the honest choice, not an empty holding page.
 * BHY_Gallery::enqueue_media() was widened (one-line change) to also
 * enqueue the media picker on this 'bh-design' hook, since it previously
 * only matched the 'bh-style' hook suffix.
 *
 * Follows every one of §1.4's six standalone-page mitigation rules:
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
        $hook = add_menu_page('Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render'], 'dashicons-layout', 4);
        self::log_result('bh-design (top-level)', $hook);

        // Relabels the auto-created duplicate first submenu item, same
        // trick OUS_Dashboard::add_menu() already uses for 'own-ur-shit'.
        $hook2 = add_submenu_page('bh-design', 'Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render']);
        self::log_result('bh-design (relabeled first submenu)', $hook2);
    }

    private static function log_result($what, $hook) {
        if (!class_exists('OUS_DebugLog')) return; // harmless no-op if the logger didn't load for some reason — same posture as every other class_exists() guard in this ecosystem
        OUS_DebugLog::log_throttled('info', 'design_suite_menu_' . sanitize_key($what), 60,
            'add_menu_page()/add_submenu_page() for ' . $what . ' returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
            ['hook_suffix' => $hook, 'current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BH_Design_Suite::add_menu()'
        );
    }
}
