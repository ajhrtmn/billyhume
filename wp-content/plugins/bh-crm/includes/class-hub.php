<?php
if (!defined('ABSPATH')) exit;

/**
 * DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (§1.1) — the new top-level
 * "CRM" admin menu (slug bh-crm-hub). This class registers ONLY the
 * top-level page itself. The real submenus — People (slug bh-crm) and
 * Project Tracker (slug bh-crm-projects) — are attached separately by
 * own-ur-shit's OUS_MenuMerge::merge() at admin_menu priority 999, via
 * the 'parent' => 'bh-crm-hub' / 'capability' => 'bhcore_manage_crm' keys
 * on bh-crm's OUS_Registry admin_menus entry (own-ur-shit/includes/
 * class-registry.php) — this class deliberately does NOT add_submenu_page()
 * for either of those itself, to avoid a double-registration race against
 * that later merge pass.
 *
 * Mirrors OUS_Dashboard::add_menu()'s own top-level + same-slug-relabeled-
 * submenu trick (own-ur-shit/includes/class-dashboard.php) exactly, per
 * the design doc's §1.1: the top-level's own callback is the REAL
 * BHCRM_People::render() (never an empty stub), registered a second time
 * under the 'bh-crm-hub' slug — the same "register the real callback
 * twice under different slugs, never rely on empty-callback slug reuse"
 * rule OUS_MenuMerge's own docblock already establishes. People's own
 * deep-link slug ('bh-crm') is unaffected — every existing
 * admin.php?page=bh-crm&... link keeps working.
 *
 * Follows all six of DESIGN-SUITE-UNIFICATION-PLAN.md §1.4's standalone-
 * page mitigation rules: unconditional registration, default admin_menu
 * priority (registered directly from bh-crm.php's plugins_loaded
 * bootstrap, not deferred to an odd priority), a real callback, a
 * capability the current user actually holds at registration time
 * (bhcore_manage_crm, granted to administrator + editor by own-ur-shit's
 * OUS_Roles on the 'init' hook, which always fires before 'admin_menu'),
 * a TOP-LEVEL menu (not a submenu of a submenu), and registration-result
 * logging.
 *
 * NOT runtime-verified — no live WordPress/browser/PHP execution is
 * available in this environment. This install has a documented,
 * multi-session history with exactly this class of standalone-page bug
 * (see own-ur-shit's class-api-docs.php docblock) — smoke-test this menu
 * especially carefully, ideally logged in as a non-admin 'editor'-role
 * account holding only 'bhcore_manage_crm' (not as an admin, whose
 * manage_options masks capability-scoping bugs), and ideally after an
 * OPcache reset, before relying on this in production.
 */
class BHCRM_Hub {
    const CAP = 'bhcore_manage_crm';

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu() {
        // QA reframe: renamed the visible top-level menu from "CRM" to
        // "Studio" — this plugin is really the full artist project-
        // management tool (people + projects +,
        // going forward, features/issues), not just a contact database,
        // and "CRM" undersold that. Slugs (bh-crm-hub, bh-crm) are
        // UNCHANGED — every existing admin.php?page=bh-crm&... deep
        // link, bookmark, and cross-plugin reference keeps working;
        // only the label a person actually sees in the sidebar changed.
        // Was "Studio" — a real naming collision with BH_Studio, the
        // separate internal Element Builder content tool (also
        // "Studio" in code, though it has no top-level menu of its
        // own). This hub is actually People + Project Tracker, so
        // "People" is what it is, and frees "Studio" to mean only the
        // Element Builder going forward. Custom icon (OUS_MenuIcons::
        // people()) replaces the generic dashicons-groups as part of
        // the same shared-icon-family pass across every OUS-owned menu.
        $hook = add_menu_page('People', 'People', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render'], OUS_MenuIcons::people(), 5);
        self::log_result('bh-crm-hub (top-level)', $hook);

        $hook2 = add_submenu_page('bh-crm-hub', 'People', 'People', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render']);
        self::log_result('bh-crm-hub (relabeled first submenu)', $hook2);
    }

    // Log-pollution fix — this used to log an
    // INFO row for every SUCCESSFUL registration too, throttled only to
    // once per 60 seconds, on every admin page load. Same fix as
    // OUS_MenuMerge::merge()'s own version of this exact pattern: only
    // the failure case is worth a log row at all.
    private static function log_result($what, $hook) {
        if ($hook !== false || !class_exists('OUS_DebugLog')) return;
        OUS_DebugLog::log('error',
            'add_menu_page()/add_submenu_page() for ' . $what . ' FAILED (returned false).',
            ['current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BHCRM_Hub::add_menu()'
        );
    }
}
