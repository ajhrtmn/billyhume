<?php
if (!defined('ABSPATH')) exit;

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
                add_submenu_page('own-ur-shit', $item['label'], $item['label'], 'manage_options', $item['slug'], $item['callback']);
            }
        }
    }
}
