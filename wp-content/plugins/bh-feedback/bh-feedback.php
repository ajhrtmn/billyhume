<?php
/**
 * Plugin Name: BH Feedback
 * Description: Paid feedback on a track — a fan pays with wallet credit for a quick-take or detailed written review; any account with the Reviewer job claims it from a shared queue. Depends only on The Self-Hosted Self's shared identity/wallet.
 * Version:     0.2.0
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).
define('BHF_PATH', plugin_dir_path(__FILE__));
define('BHF_URL',  plugin_dir_url(__FILE__));
define('BHF_VER',  '0.2.0');

/**
 * A genuine PEER to bh-courses/bh-contest/bh-streaming/bh-monetization-woo
 * — depends only on the-self-hosted-self (shared identity, roles/capabilities,
 * style tokens). bh-monetization-woo is a SOFT dependency (checked via
 * class_exists() at init time, never at file-parse time, same posture
 * every other plugin in this ecosystem takes): if it's active, a
 * request is paid for via BHM_Wallet; if it isn't, submission is
 * simply disabled with an explicit notice rather than a confusing
 * broken form (see class-requests.php's own guard). bh-streaming is
 * also soft: if BHS_AudioHash exists, a submission is checked against
 * it for "has this exact file already been submitted before" — pure
 * bonus, never required.
 */
foreach (['tables', 'activator', 'post-types', 'pricing', 'requests', 'queue', 'annotations', 'admin', 'portal-panel', 'test-suite'] as $f) {
    require_once BHF_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHF_Activator', 'activate']);
add_action('plugins_loaded', ['BHF_Activator', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Feedback</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHF_PostTypes', 'register']);
    add_action('init', ['BHF_Requests', 'init']);
    add_action('init', ['BHF_Queue', 'init']);
    add_action('init', ['BHF_Annotations', 'init']);
    add_action('init', ['BHF_Admin', 'init']);
    add_action('init', ['BHF_PortalPanel', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHF_TestSuite', 'init']);
});
