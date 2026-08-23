<?php
/**
 * Plugin Name: BH MailPoet
 * Description: Bridges bh-crm's contact list into MailPoet subscriber lists, so MailPoet (not a hand-rolled sender) is the ecosystem's email/marketing delivery engine. Entirely inert if MailPoet isn't installed.
 * Version:     1.1.4
 * Requires PHP: 8.2
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHMP_VER',  '1.1.4');

define('BHMP_PATH', plugin_dir_path(__FILE__));
define('BHMP_URL',  plugin_dir_url(__FILE__));

// The MailPoet list this plugin syncs bh-crm contacts into. A single
// flat list in Phase 1 (see the plugin description above and
// CLAUDE.md's Phase 2/3 notes) — filterable so a future pass or a
// manual admin change doesn't need a code edit.
if (!defined('BHMP_DEFAULT_LIST_NAME')) {
    define('BHMP_DEFAULT_LIST_NAME', 'The Self-Hosted Self Fans');
}

foreach (['sync', 'scheduled-sync', 'instant-sync', 'debug'] as $f) {
    require_once BHMP_PATH . "includes/class-$f.php";
}

/**
 * Gated behind plugins_loaded rather than checked directly here — same
 * reasoning as every other peer plugin in this ecosystem (see
 * bh-contest.php): WordPress loads active plugins' files in alphabetical
 * folder order, so a direct class_exists() check at file-parse time
 * could run before own-ur-shit's own file has loaded yet.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH MailPoet</strong> requires the <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    BHMP_Sync::init();
    BHMP_ScheduledSync::init();
    BHMP_InstantSync::init();
    BHMP_Debug::init();

    // Third OUS_Integration registration (own-ur-shit 3.10.0) — merges
    // onto the 'email_broadcast' contract own-ur-shit.php already
    // registers (builtin_class => OUS_Campaigns), adding only the
    // enhancer side. Priority 20 on 'init', same as own-ur-shit's own
    // registration of this key — own-ur-shit's file-parse-time
    // add_action() call is always added to the hook first (before this
    // plugin's plugins_loaded callback even runs), so its builtin_class
    // registration is guaranteed to land before this merge, per
    // OUS_Integration::register()'s own docblock.
    add_action('init', function () {
        if (class_exists('OUS_Integration')) {
            OUS_Integration::register('email_broadcast', ['enhancer_class' => 'BHMP_Sync']);
        }
    }, 20);

    // Softer notice — this plugin stays active and harmless without
    // MailPoet, it just has nothing to do yet. Only shown on this
    // plugin's own Debug Tools section context isn't guaranteed, so a
    // light global admin notice is the more reliable way to surface it;
    // dismissible so it doesn't nag once someone's aware.
    add_action('admin_notices', function () {
        if (class_exists('\MailPoet\API\API')) return;
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-info is-dismissible"><p><strong>BH MailPoet</strong> is active but MailPoet itself isn\'t installed yet — contact sync is idle until it is. Install MailPoet from the The Self-Hosted Self dashboard or WordPress.org.</p></div>';
    });
}, 20); // after BHCORE_LOADED-defining bootstrap, same ordering convention as bh-contest.php

// Self-registration on the ecosystem dashboard (own-ur-shit's
// class-registry.php) — harmless even before this plugin is active
// anywhere else, per that file's own docblock.
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
    // MailPoet itself — a third-party WordPress.org plugin, not ours to
    // author or bundle, same 'wporg_slug' pattern class-registry.php's
    // own docblock documents for WooCommerce. isset() guard so this
    // never fights own-ur-shit's own copy if it's ever added there too.
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
