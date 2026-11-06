<?php
/**
 * Plugin Name: BH MailPoet
 * Description: Bridges bh-crm's contact list into MailPoet subscriber lists, so MailPoet (not a hand-rolled sender) is the ecosystem's email/marketing delivery engine. Entirely inert if MailPoet isn't installed.
 * Version:     1.2.0
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHMP_VER',  '1.2.0');

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

// Self-registration on the ecosystem dashboard.
add_filter('ous_registered_plugins', function ($plugins) {
    $plugins['bh-mailpoet'] = [
        'label'       => 'BH MailPoet',
        'file'        => 'bh-mailpoet/bh-mailpoet.php',
        'depends_on'  => ['mailpoet'],
        'check_class' => 'BHMP_Sync',
        'description' => 'Syncs bh-crm contacts into a MailPoet subscriber list, so MailPoet\'s own automations/newsletters have current data. Send/automation logic stays in MailPoet — this plugin only keeps list membership current.',
        'dashboard_link' => 'admin.php?page=ous-debug',
        'bundled_zip' => 'bh-mailpoet.zip',
    ];
    // MailPoet is a wordpress.org dependency; isset() avoids clobbering core's own entry.
    if (!isset($plugins['mailpoet'])) {
        $plugins['mailpoet'] = [
            'label'       => 'MailPoet',
            'file'        => 'mailpoet/mailpoet.php',
            'wporg_slug'  => 'mailpoet',
            'check_class' => '\MailPoet\API\API',
            'description' => 'Required for BH MailPoet — self-hosted email/newsletter sending and automation, not reimplemented here.',
        ];
    }
    return $plugins;
});
