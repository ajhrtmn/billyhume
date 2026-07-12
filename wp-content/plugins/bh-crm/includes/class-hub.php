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
        $hook = add_menu_page('CRM', 'CRM', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render'], 'dashicons-groups', 5);
        self::log_result('bh-crm-hub (top-level)', $hook);

        $hook2 = add_submenu_page('bh-crm-hub', 'People', 'People', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render']);
        self::log_result('bh-crm-hub (relabeled first submenu)', $hook2);
    }

    private static function log_result($what, $hook) {
        if (!class_exists('OUS_DebugLog')) return; // harmless no-op if the core logger didn't load — same posture as every other class_exists() guard in this ecosystem
        OUS_DebugLog::log_throttled('info', 'bhcrm_hub_menu_' . sanitize_key($what), 60,
            'add_menu_page()/add_submenu_page() for ' . $what . ' returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
            ['hook_suffix' => $hook, 'current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BHCRM_Hub::add_menu()'
        );
    }
}
