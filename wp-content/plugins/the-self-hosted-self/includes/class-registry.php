<?php
if (!defined('ABSPATH')) exit;

/**
 * This site plugins: listed on Site Tools, admin pages relocated by OUS_MenuMerge, and
 * registered for GitHub Updates ('github' => true). WHY: only custom admin pages move,
 * with their real callbacks; CPT menus are never touched.
 */
class OUS_Registry {
    private const DEFAULTS = [
        // WHY: hardcoded rather than self-registered, because an inactive plugin's
        // filter never runs and it could not otherwise be activated or updated here.
        'bh-courses' => [
            'label' => 'Courses',
            'file' => 'bh-courses/bh-courses.php',
            'check_class' => 'BHC_PostTypes',
            'description' => 'Courses built from lessons (video, text, quizzes, downloads) with progress tracking, notes and sales pages.',
            'dashboard_link' => 'edit.php?post_type=bh_course',
            'github' => true,
        ],
        // No 'admin_menus': Results/Live Console stay under the Contest menu.
        'bh-contest' => [
            'label' => 'Contests',
            'file' => 'bh-contest/bh-contest.php',
            'check_class' => 'BH_Admin',
            'description' => 'Music contest voting, live reveal, and results.',
            'dashboard_link' => 'edit.php?post_type=bh_contest',
            'github' => true,
        ],
        'bh-monetization-woo' => [
            'label' => 'Sales (WooCommerce)',
            'file' => 'bh-monetization-woo/bh-monetization-woo.php',
            'depends_on' => ['woocommerce'],
            'check_class' => 'BHM_Gate',
            'description' => 'Selling courses and supporter tiers through WooCommerce: course products, gift access, launch coupons, guest checkout.',
            'dashboard_link' => 'admin.php?page=bhm-settings',
            'github' => true,
            'admin_menus' => [
                ['slug' => 'bhm-settings', 'label' => 'Monetization Settings', 'callback' => ['BHM_Admin', 'render'], 'parent' => 'woocommerce'],
            ],
        ],
        'bh-tickets' => [
            'label' => 'Support messages',
            'file' => 'bh-tickets/bh-tickets.php',
            'check_class' => 'BHT_Tickets',
            'description' => 'The Support page contact form and private course feedback, answered from wp-admin.',
            'dashboard_link' => 'admin.php?page=bh-tickets',
            'github' => true,
        ],
        'bh-crm' => [
            'label' => 'People (CRM)',
            'file' => 'bh-crm/bh-crm.php',
            'check_class' => 'BHCRM_People',
            'description' => 'A list of everyone who has an account, voted, or bought something, with notes and tags.',
            'dashboard_link' => 'admin.php?page=bh-crm',
            'github' => true,
            // WHY: People's 'parent' is null because BHCRM_Hub already registers
            // 'bh-crm-hub' with this same callback; a second parent would show two
            // rows. merge() must treat an explicit null differently from absent.
            'admin_menus' => [
                ['slug' => 'bh-crm', 'label' => 'People', 'callback' => ['BHCRM_People', 'render'], 'parent' => null, 'capability' => 'bhcore_manage_crm'],
                ['slug' => 'bh-crm-projects', 'label' => 'Project Tracker', 'callback' => ['BHCRM_Projects', 'render_boards'], 'parent' => 'bh-crm-hub', 'capability' => 'bhcore_manage_crm'],
            ],
        ],
        'bh-mailpoet' => [
            'label' => 'Mailing list sync',
            'file' => 'bh-mailpoet/bh-mailpoet.php',
            'depends_on' => ['mailpoet'],
            'check_class' => 'BHMP_Sync',
            'description' => 'Copies People (CRM) contacts into a MailPoet list, so MailPoet newsletters reach them.',
            'dashboard_link' => 'admin.php?page=ous-debug',
            'github' => true,
        ],
        'woocommerce' => [
            'label' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'check_class' => 'WooCommerce',
            'description' => 'Checkout, orders and payments.',
        ],
        'mailpoet' => [
            'label' => 'MailPoet',
            'file' => 'mailpoet/mailpoet.php',
            'description' => 'Newsletters and the mailing list.',
        ],
    ];

    // Built-ins plus filter contributions, every entry defaulted to the full key set.
    /** @return array<string, array<string, mixed>> */
    public static function all(): array {
        $plugins = apply_filters('ous_registered_plugins', self::DEFAULTS);
        foreach ($plugins as $key => &$info) {
            $info = array_merge([
                'depends_on' => [], 'check_class' => '', 'description' => '',
                'dashboard_link' => '', 'github' => false, 'admin_menus' => [],
            ], $info);
        }
        return $plugins;
    }

    // 'missing', 'inactive', or 'active'. Uses get_plugins() for file presence,
    // since class_exists() is false for an installed plugin not loaded yet.
    public static function status(string $key): string {
        $registry = self::all();
        if (!isset($registry[$key])) return 'missing';
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $file = $registry[$key]['file'];
        if (!isset(get_plugins()[$file])) return 'missing';
        return is_plugin_active($file) ? 'active' : 'inactive';
    }

    public static function version(string $key): string {
        $registry = self::all();
        if (!isset($registry[$key])) return '';
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        return get_plugins()[$registry[$key]['file']]['Version'] ?? '';
    }
}
