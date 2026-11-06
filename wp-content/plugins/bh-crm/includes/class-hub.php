<?php
if (!defined('ABSPATH')) exit;

/**
 * Top-level "People" menu (slug bh-crm-hub), whose callback is the real BHCRM_People::render().
 * WHY: only the top-level page is registered here; OUS_MenuMerge attaches the People/Project Tracker
 * submenus at admin_menu 999, so adding them here too would double-register.
 */
class BHCRM_Hub {
    const CAP = 'bhcore_manage_crm';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu(): void {
        $hook = add_menu_page('People', 'People', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render'], OUS_MenuIcons::people(), 5);
        self::log_result('bh-crm-hub (top-level)', $hook);

        $hook2 = add_submenu_page('bh-crm-hub', 'People', 'People', self::CAP, 'bh-crm-hub', ['BHCRM_People', 'render']);
        self::log_result('bh-crm-hub (relabeled first submenu)', $hook2);
    }

    // Logs only failed registrations.
    /** @param string|false $hook */
    private static function log_result(string $what, $hook): void {
        if ($hook !== false || !class_exists('OUS_DebugLog')) return;
        OUS_DebugLog::log('error',
            'add_menu_page()/add_submenu_page() for ' . $what . ' FAILED (returned false).',
            ['current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
            'BHCRM_Hub::add_menu()'
        );
    }
}
