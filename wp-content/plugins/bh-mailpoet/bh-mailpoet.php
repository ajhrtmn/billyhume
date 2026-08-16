<?php
/**
 * Plugin Name: BH MailPoet
 * Description: Bridges bh-crm's contact list into MailPoet subscriber lists, so MailPoet (not a hand-rolled sender) is the ecosystem's email/marketing delivery engine. Entirely inert if MailPoet isn't installed.
 * Version:     1.1.3
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 1.1.3 — Added the missing 'bundled_zip' key to this plugin's own
// 'ous_registered_plugins' self-registration (found while auditing
// bundled-zip coverage ecosystem-wide — own-ur-shit 3.10.18's own
// changelog has the full story). Also newly hardcoded into
// OUS_Registry::DEFAULTS itself, closing the same chicken-and-egg gap
// bh-courses/bh-registry/bh-feedback already had fixed: this plugin's
// self-registration only ever fires once it's already active, so an
// inactive install was invisible to the ecosystem dashboard.

// 1.1.2 — Real bug fix found while investigating a Phase 4 dead-code-
// triage flag: BHMP_Sync::remove_contact() was fully implemented and
// its own docblock literally says "Account deletion / explicit 'stop
// syncing this person' path," but nothing anywhere ever called it —
// class-sync.php's init() was a deliberate no-op ("mirrors BHM_Wallet's
// own init() reasoning"), and no ecosystem plugin has any account-
// deletion hook at all right now. Net effect: deleting a WordPress
// account never removed that person's MailPoet subscription — a real,
// silent gap, mildly privacy-relevant. Fixed by hooking remove_contact()
// to WordPress's own `delete_user` action (fires before the user row
// is actually removed, so remove_contact()'s internal get_userdata()
// call still resolves) via a thin void-returning closure (PHPStan
// flags a bool-returning value from an action callback). remove_contact()
// itself already no-ops harmlessly via its class_exists('\MailPoet\API\
// API') guard when MailPoet isn't installed, so this hook is safe
// unconditionally. NOT runtime-verified against a live MailPoet
// install — same standing caveat as the rest of this plugin.

// 1.1.1 — Ecosystem quality Phase 2, brick 1 of 13 (this plugin had the
// fewest PHPStan level-6 findings of all 12, so it's the first): added
// native return types and parameter types to every method that was
// missing one (class-debug.php, class-instant-sync.php,
// class-scheduled-sync.php, class-sync.php — 32 findings total, all
// the two purely mechanical categories: "no return type specified" and
// "parameter with no type specified"). Hook-callback parameters whose
// real value comes from WordPress/WooCommerce (sync_from_user_id,
// sync_from_order, sync_from_entitlement, sync_from_event) are typed
// `mixed` at the boundary and cast internally, same as the existing
// (int) casts those methods already did — no behavior change, purely
// additive type declarations. This plugin is now clean at PHPStan
// level 6 in isolation; phpstan.neon itself stays at level 5
// ecosystem-wide until all 12 plugins are done (see phpstan.neon's own
// comment once that flip happens) so Phase 1's CI gate doesn't break
// mid-effort.
// NOT runtime-verified against a live MailPoet install (same
// disclosure as this plugin's original build — nothing here changes
// behavior, only adds compile-time type declarations).
// 1.1.0 — Registers this plugin as the enhancer for own-ur-shit 3.10.0's
// new 'email_broadcast' OUS_Integration contract (OUS_Integration::
// register('email_broadcast', ['enhancer_class' => 'BHMP_Sync'])) —
// Phase 1 of the OSS-integration master plan. Makes explicit, in one
// visible place (Debug Tools -> Integration Contracts), what was already
// true implicitly: OUS_Campaigns (own-ur-shit core) is the always-works
// built-in broadcaster, this plugin is what upgrades that to real
// MailPoet-backed list sync/automation when installed. No behavior
// change to BHMP_Sync/BHMP_ScheduledSync/BHMP_InstantSync themselves.
// NOT runtime-verified against a live WordPress+MySQL install this
// session; `php -l` clean.

// 1.0.0 — First cut. bh-crm stays the source of truth for who a contact
// is (profile, tags, segments); this plugin's only job is keeping a
// MailPoet subscriber list in sync with that, so MailPoet's own
// automation engine (list-based triggers) has fresh data to work off
// of. Two sync paths: BHMP_ScheduledSync (a daily full resync via
// OUS_Jobs, catches everything eventually) and BHMP_InstantSync (a
// handful of real-time hooks — user_register/profile_update natively,
// woocommerce_order_status_completed and bhm_entitlement_granted as
// real WP actions, plus every BH_Event type via the new
// 'bh_event_emitted' action own-ur-shit 3.9.9 added specifically to
// make this plugin possible without polling bhcore_events).
// Deliberately NOT wired to MailPoet's Automation-builder API or its
// transactional-send API — this plugin only manages list membership;
// MailPoet's own automations (trigger = "subscriber added to list") are
// what actually decide what to send. Keeps this plugin small and keeps
// send/template/automation logic entirely inside MailPoet, where it's
// actually built for.
// Every entry point in includes/class-sync.php is guarded by
// class_exists('\MailPoet\API\API') at call time — this plugin loads
// and shows up on the ecosystem dashboard even if MailPoet is never
// installed, it just does nothing until it is.
// NOT runtime-verified against a live MailPoet install this session —
// \MailPoet\API\API::MP('v1')'s method names (addSubscriber,
// subscribeToList, getLists, unsubscribeFromLists) are from MailPoet's
// documented public API, not confirmed against the actual installed
// plugin source. Confirm these against the real MailPoet codebase (or
// its own developer docs) before relying on this in production; `php -l`
// is clean on every file here, but that only proves valid PHP syntax,
// not that these calls match MailPoet's real method signatures.
define('BHMP_VER',  '1.1.3');
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
