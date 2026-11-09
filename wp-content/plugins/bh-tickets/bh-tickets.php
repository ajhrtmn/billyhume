<?php
/**
 * Plugin Name: BH Tickets
 * Description: In-house support/issue ticketing, built on bh-crm's own identity model — a fan opens a ticket from their account portal, staff triage from wp-admin. No third-party helpdesk dependency.
 * Version:     1.0.5
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).
define('BHT_VER',  '1.0.5');
define('BHT_PATH', plugin_dir_path(__FILE__));
define('BHT_URL',  plugin_dir_url(__FILE__));

foreach (['tables', 'activator', 'tickets', 'replies', 'admin', 'portal', 'support', 'debug', 'test-suite'] as $f) {
    require_once BHT_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHT_Activator', 'activate']);

/** Depends only on core; bh-crm integration is optional and class_exists()-guarded. */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Tickets</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('plugins_loaded', ['BHT_Activator', 'maybe_upgrade']);
    BHT_Admin::init();
    BHT_Portal::init();
    BHT_Support::init();
    BHT_Debug::init();
    if (class_exists('OUS_TestRunner')) BHT_TestSuite::init();

    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bht/ticket_created', ['subject' => 'string', 'category' => 'string', 'priority' => 'string']);
            BH_Event::register_event_type('bht/reply_added', ['is_staff' => 'bool']);
            BH_Event::register_event_type('bht/status_changed', ['status' => 'string']);
        }
        if (class_exists('OUS_Integration')) {
            OUS_Integration::register('bh-tickets', [
                'label' => 'Support / ticketing',
                'description' => 'Fan-facing support tickets, tied to bh-crm\'s own identity model.',
                'builtin_class' => 'BHT_Tickets',
            ]);
        }
    }, 20);

    add_filter('bh_crm_activity_summary', ['BHT_Tickets', 'register_crm_activity'], 10, 2);
});

add_filter('ous_registered_plugins', function ($plugins) {
    $plugins['bh-tickets'] = [
        'label' => 'BH Tickets',
        'file' => 'bh-tickets/bh-tickets.php',
        'depends_on' => [],
        'check_class' => 'BHT_Tickets',
        'description' => 'In-house support/issue ticketing built on bh-crm\'s own identity model — no third-party helpdesk dependency.',
        'dashboard_link' => 'admin.php?page=bh-tickets',
        'bundled_zip' => 'bh-tickets.zip',
    ];
    return $plugins;
});
