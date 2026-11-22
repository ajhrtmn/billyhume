<?php
/**
 * Plugin Name: BH MailPoet
 * Description: Copies People (CRM) contacts into a MailPoet list so newsletters reach them. Does nothing without MailPoet.
 * Version:     1.2.2
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHMP_VER',  '1.2.2');

define('BHMP_PATH', plugin_dir_path(__FILE__));
define('BHMP_URL',  plugin_dir_url(__FILE__));

// The single MailPoet list bh-crm contacts sync into (filterable).
if (!defined('BHMP_DEFAULT_LIST_NAME')) {
    define('BHMP_DEFAULT_LIST_NAME', 'The Self-Hosted Self Fans');
}

foreach (['sync', 'scheduled-sync', 'instant-sync', 'debug'] as $f) {
    require_once BHMP_PATH . "includes/class-$f.php";
}

/** WHY: gated on plugins_loaded; plugins load alphabetically, so core may not be loaded at parse time. */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH MailPoet</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHMP_Sync::init();
    BHMP_ScheduledSync::init();
    BHMP_InstantSync::init();
    BHMP_Debug::init();

    // Adds the enhancer side of core's 'email_broadcast' integration. Core's init/20 registration is
    // added at parse time, so its builtin_class always lands before this merge.
    add_action('init', function () {
        if (class_exists('OUS_Integration')) {
            OUS_Integration::register('email_broadcast', ['enhancer_class' => 'BHMP_Sync']);
        }
    }, 20);

    // Dismissible notice when MailPoet is missing; the plugin stays inert.
    add_action('admin_notices', function () {
        if (class_exists('\MailPoet\API\API')) return;
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-info is-dismissible"><p><strong>BH MailPoet</strong> is active but MailPoet itself isn\'t installed yet — contact sync is idle until it is. Install MailPoet from the Self-Hosted Self dashboard or WordPress.org.</p></div>';
    });
}, 20); // after core's BHCORE_LOADED bootstrap

