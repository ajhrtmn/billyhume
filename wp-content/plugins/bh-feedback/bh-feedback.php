<?php
/**
 * Plugin Name: BH Feedback
 * Description: Paid feedback on a track — a fan pays with wallet credit for a quick-take or detailed written review; any account with the Reviewer job claims it from a shared queue. Depends only on Own Ur Shit's shared identity/wallet.
 * Version:     0.1.4
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.1.4 — This plugin's first PHPStan pass (newly added to
// phpstan.neon's scanned paths this round). One finding: get_userdata()
// needed an int, not the string post_author property it was given
// directly (class-admin.php).
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run. `php -l` clean.

// 0.1.0 — first build. Ecosystem depth-pass Tier 1d, per
// ROADMAP-feedback-and-courses-v2.md's own scoping: v1 ships quick-take
// + detailed written feedback only; the timestamped-audio-annotation
// tier is explicitly deferred (architecturally the same "pause a step,
// show an overlay" mechanic already shipped for bh-courses' video
// annotations this session — worth reusing that UI pattern directly
// when that tier is actually built, not inventing a second one).
//
// Reviewer model (AJ's own call, ecosystem depth-pass Tier 1d design
// pass): a SELF-SERVE CLAIM QUEUE, not admin-assigned — any account
// holding the `bhcore_review_submissions` capability can browse open
// requests and claim one. That capability GATES THE WHOLE REVIEWER
// ROLE (claiming, reviewing, and seeing the queue are one bundle), not
// just queue visibility — matches the existing Roles-as-jobs UI
// (own-ur-shit's OUS_RoleAssignment): granting the "Reviewer" job is
// granting everything reviewing needs, nothing more to wire up
// separately.
define('BHF_PATH', plugin_dir_path(__FILE__));
define('BHF_URL',  plugin_dir_url(__FILE__));
define('BHF_VER',  '0.1.4');

/**
 * A genuine PEER to bh-courses/bh-contest/bh-streaming/bh-monetization-woo
 * — depends only on own-ur-shit (shared identity, roles/capabilities,
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
foreach (['activator', 'post-types', 'pricing', 'requests', 'queue', 'admin', 'portal-panel', 'test-suite'] as $f) {
    require_once BHF_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHF_Activator', 'activate']);
add_action('plugins_loaded', ['BHF_Activator', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Feedback</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHF_PostTypes', 'register']);
    add_action('init', ['BHF_Requests', 'init']);
    add_action('init', ['BHF_Queue', 'init']);
    add_action('init', ['BHF_Admin', 'init']);
    add_action('init', ['BHF_PortalPanel', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHF_TestSuite', 'init']);
});
