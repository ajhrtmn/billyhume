<?php
if (!defined('ABSPATH')) exit;

/**
 * Top-level Design Suite menu (bh-design), rendered by BHY_Gallery::render().
 * WHY: top-level with a real callback and a granted capability; standalone submenu
 * pages have been denied by page-hook resolution on this install.
 */
class BH_Design_Suite {
    const CAP = 'bhcore_design_site';

    public static function add_menu(): void {
        $hook = add_menu_page('Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render'], OUS_MenuIcons::design_suite(), 4);
        self::log_result(OUS_Words::get('admin.design_suite.bh_design_top_level'), $hook);

        // Relabels the auto-created duplicate first submenu item.
        $hook2 = add_submenu_page('bh-design', 'Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render']);
        self::log_result(OUS_Words::get('admin.design_suite.bh_design_relabeled_first_submenu'), $hook2);
    }

    // Logs failures only.
    /** @param string|false $hook */
    private static function log_result(string $what, $hook): void {
        if ($hook !== false || !class_exists('OUS_DebugLog')) return;
        OUS_DebugLog::log('error',
            'add_menu_page()/add_submenu_page() for ' . $what . ' FAILED (returned false).',
            ['current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BH_Design_Suite::add_menu()'
        );
    }
}
