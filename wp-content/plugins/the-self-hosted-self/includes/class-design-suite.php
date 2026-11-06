<?php
if (!defined('ABSPATH')) exit;

/**
 * Top-level "Design Suite" menu (slug bh-design), rendered by
 * BHY_Gallery::render(). The 'bh-style'/'bh-studio' slugs register with a
 * hidden parent so deep links resolve without extra sidebar rows.
 * WHY: registered unconditionally, top-level (not a nested submenu), with a
 * real callback and a capability OUS_Roles grants on 'init'; this install
 * has a history of standalone pages being denied by WP page-hook resolution.
 */
class BH_Design_Suite {
    const CAP = 'bhcore_design_site';

    public static function add_menu(): void {
        $hook = add_menu_page('Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render'], OUS_MenuIcons::design_suite(), 4);
        self::log_result('bh-design (top-level)', $hook);

        // Relabels the auto-created duplicate first submenu item.
        $hook2 = add_submenu_page('bh-design', 'Design Suite', 'Design Suite', self::CAP, 'bh-design', ['BHY_Gallery', 'render']);
        self::log_result('bh-design (relabeled first submenu)', $hook2);
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
