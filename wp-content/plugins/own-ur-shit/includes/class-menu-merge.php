<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.25 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (§1.2):
// merge() now honors two new OPTIONAL keys on each admin_menus entry —
// 'parent' (defaults to 'own-ur-shit', unchanged behavior for every
// existing registrant that doesn't set it) and 'capability' (defaults
// to 'manage_options', likewise unchanged for existing registrants).
// bh-crm's own registry entry (class-registry.php) is the first to set
// both, relocating People + a new Project Tracker submenu under the new
// 'bh-crm-hub' top-level menu with the 'bhcore_manage_crm' capability.
// Also added registration-result logging (OUS_DebugLog::log_throttled())
// per the design doc's §1.4 rule 6 — this pass had none before.
//
// OUS_VER 3.4.31 — merge() now distinguishes "no 'parent' key at all"
// from "'parent' key explicitly set to null" (array_key_exists() instead
// of ??, which treats both the same). This is what makes bh-crm's People
// entry able to register as a hidden page (WordPress's own documented
// add_submenu_page(null, ...) pattern, already used by class-studio.php
// for 'bh-studio') instead of always falling back to a visible parent —
// see class-registry.php's own changelog note for why. No behavior
// change for any existing registrant, since none of them set 'parent'
// to null before this pass.

/**
 * Relocates each ecosystem plugin's genuinely custom admin pages (never
 * CPT or taxonomy list-tables — see class-registry.php's docblock for
 * why those are deliberately left alone) as direct submenus under Own
 * Ur Shit. Runs at a very late admin_menu priority (999), after every
 * other plugin has already registered its own menus at the default
 * priority, then relocates what's already there.
 *
 * Nothing in bh-contest or bh-streaming changes to make this happen —
 * they still register their own menus exactly as before, unaware this
 * class exists. Delete this plugin, and every relocated page just goes
 * back to living under its original plugin's own top-level menu.
 *
 * Uses the REAL callback for each page, explicitly, rather than an
 * empty string relying on WordPress "reusing" a page already registered
 * under a different parent — that technique does not reliably work for
 * custom pages (their internal hook name is derived from BOTH the page
 * slug and its parent, so registering the same slug under a new parent
 * with an empty callback does not correctly dispatch to the original
 * render function). Passing the real callback again sidesteps that
 * entirely: this just creates a second, fully independent, correctly-
 * functioning registration that happens to render the same content.
 */
class OUS_MenuMerge {
    public static function init() {
        add_action('admin_menu', [self::class, 'merge'], 999);
    }

    public static function merge() {
        foreach (OUS_Registry::all() as $key => $info) {
            if (empty($info['admin_menus'])) continue;
            if (OUS_Registry::status($key) !== 'active') continue;

            foreach ($info['admin_menus'] as $item) {
                if (!empty($item['old_parent'])) {
                    remove_submenu_page($item['old_parent'], $item['slug']);
                }
                $parent = array_key_exists('parent', $item) ? $item['parent'] : 'own-ur-shit';
                $capability = $item['capability'] ?? 'manage_options';
                $hook = add_submenu_page($parent, $item['label'], $item['label'], $capability, $item['slug'], $item['callback']);
                // Log-pollution fix, flagged by AJ directly ("logs get
                // polluted quickly") — this used to log an INFO row for
                // EVERY successful registration, throttled only to once
                // per 60 SECONDS per slug, on every single admin page
                // load. At real usage volume that fills the debug log's
                // whole 1000-row cap (OUS_DebugLog::MAX_ROWS) within a
                // handful of admin page visits, crowding out genuinely
                // rare warning/error rows. The duplicate-menu-
                // registration bug class this was built to catch is
                // real (documented in this file's own changelog above)
                // — but the signal that actually matters is FAILURE
                // ($hook === false), never "registered exactly as
                // expected," which is what happens on every request.
                // Only the failure case still logs now, unthrottled
                // (it's rare enough that throttling was never the point).
                if ($hook === false && class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error',
                        'OUS_MenuMerge: add_submenu_page() for ' . $item['slug'] . ' (parent ' . $parent . ', capability ' . $capability . ') FAILED (returned false).',
                        ['parent' => $parent, 'capability' => $capability],
                        'OUS_MenuMerge::merge()'
                    );
                }
            }
        }
    }
}
