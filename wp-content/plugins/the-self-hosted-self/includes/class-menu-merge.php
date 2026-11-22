<?php
if (!defined('ABSPATH')) exit;

// merge() honors optional 'parent' (default 'ous') and 'capability'
// (default 'manage_options') keys per admin_menus entry. An explicit 'parent' => null
// (array_key_exists, not ??) registers a hidden page via add_submenu_page(null, ...).

/**
 * Moves each plugin custom admin page (never CPT lists) under the core menu at priority 999.
 * WHY: passes the real callback; the page hook comes from slug + parent, so an empty
 * callback under a new parent never dispatches.
 */
class OUS_MenuMerge {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'merge'], 999);
    }

    public static function merge(): void {
        foreach (OUS_Registry::all() as $key => $info) {
            if (empty($info['admin_menus'])) continue;
            if (OUS_Registry::status($key) !== 'active') continue;

            foreach ($info['admin_menus'] as $item) {
                if (!empty($item['old_parent'])) {
                    remove_submenu_page($item['old_parent'], $item['slug']);
                }
                $parent = array_key_exists('parent', $item) ? $item['parent'] : 'ous';
                $capability = $item['capability'] ?? 'manage_options';
                $hook = add_submenu_page($parent, $item['label'], $item['label'], $capability, $item['slug'], $item['callback']);
                // Log only failures: success happens every request and would flood the log's row cap.
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
