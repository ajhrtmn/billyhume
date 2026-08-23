<?php
/**
 * Plugin Name: BH Tickets
 * Description: In-house support/issue ticketing, built on bh-crm's own identity model — a fan opens a ticket from their account portal, staff triage from wp-admin. No third-party helpdesk dependency.
 * Version:     1.0.2
 * Requires PHP: 8.2
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 1.0.2 — Real gap found in a functional-depth audit (this plugin's
// admin/portal UI was fully present, but "does it actually do the
// job" had never been checked end to end). BHT_Replies::maybe_notify()
// already handled staff-reply and requester-reply notifications, and
// its own doc comment openly admitted a brand-new, unassigned ticket
// notifies nobody at all — bht/ticket_created fired for the event log
// but nothing ever listened to it for a real notification. A support
// plugin whose own description promises "staff triage from wp-admin"
// needs staff to actually find out a ticket exists rather than relying
// on someone happening to check the list. Added
// BHT_Tickets::notify_staff_new_ticket(), called from create():
// notifies every account holding bhcore_manage_tickets (matches
// get_users(['capability' => ...]) used elsewhere in this ecosystem —
// bh-courses' class-sessions.php/class-admin.php), skipping the
// creator themself so a staff member opening their own ticket doesn't
// self-notify. Verified live: submitted a real ticket through the
// portal (/account/?panel=tickets), confirmed via direct DB query that
// this install currently has exactly one staff account (billy, the
// ticket's own creator) — correctly produced zero notification rows,
// since the only eligible recipient was the creator being skipped by
// design. The "notify a DIFFERENT staff member" path itself couldn't
// be exercised without a second real staff account in this
// environment; logic reviewed carefully instead (same get_users()/
// OUS_Notifications::notify() shape already proven working in
// maybe_notify() just above it in class-replies.php).

// 1.0.1 — Ecosystem quality Phase 2, brick 4/13: added native return
// types and parameter types across all 9 includes files (70 findings,
// both mechanical level-6 categories). Purely additive typing, no
// behavior change. This plugin is now clean at PHPStan level 6 in
// isolation.
// NOT runtime-verified against a live install.
// 1.0.0 — First cut. Phase 3 of the OSS-integration master plan:
// support/ticketing built in-house rather than integrating an existing
// WordPress helpdesk plugin (Awesome Support was surveyed as UX
// reference only — canned-response and assignment-model ideas, never
// installed as a dependency), for the same reasoning that already ruled
// out FluentCRM/Groundhogg for the marketing layer: a real third-party
// ticketing plugin brings its own separate contact model, which would
// compete with bh-crm's own wp_users-based identity rather than
// building on it.
// Two tables (bhtickets_tickets, bhtickets_replies — class-activator.php)
// — a ticket is its own lifecycle, not a CPT, same "a table when the
// shape doesn't fit post/meta" convention bh-crm's own bhcrm_notes/
// bhcrm_projects already established. Every ticket belongs to a real
// wp_user; links to its requester via BHCRM_Links (bh-crm's existing
// generic typed relationship table) when bh-crm is active, and
// contributes a summary to bh-crm's per-person Activity section via the
// existing bh_crm_activity_summary filter — entirely optional, this
// plugin works standalone with zero other feature plugins installed.
// Staff triage: a plain top-level wp-admin page (BHT_Admin, capability
// 'bhcore_manage_tickets', new in own-ur-shit 3.10.3) — a real
// top-level add_menu_page() call, not a risky cross-plugin submenu, per
// CLAUDE.md's documented page-hook-resolution incident. Fan-facing: a
// "My Tickets" panel on the account portal (BHT_Portal, bhi_portal_panels
// filter) — open/view/reply to your own tickets, ownership re-checked
// server-side on every POST.
// First real NEW (not retrofitted) OUS_Integration registration
// (own-ur-shit 3.10.0) — 'bh-tickets' contract, builtin_class =>
// 'BHT_Tickets', no enhancer registered yet (by design — this contract
// starts with only a built-in implementation).
// NOT runtime-verified against a live WordPress+MySQL install this
// session; `php -l` clean on every file.
define('BHT_VER',  '1.0.2');
define('BHT_PATH', plugin_dir_path(__FILE__));
define('BHT_URL',  plugin_dir_url(__FILE__));

foreach (['tables', 'activator', 'tickets', 'replies', 'admin', 'portal', 'debug', 'test-suite'] as $f) {
    require_once BHT_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHT_Activator', 'activate']);

/**
 * Depends only on the core plugin. A genuine peer to bh-crm (optionally
 * enriched, never required — see class-tickets.php's BHCRM_Links/
 * bh_crm_activity_summary touches, all class_exists()-guarded), never a
 * dependency of it or of anything else.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Tickets</strong> requires the <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('plugins_loaded', ['BHT_Activator', 'maybe_upgrade']);
    BHT_Admin::init();
    BHT_Portal::init();
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

    // Optional enrichment of bh-crm's per-person Activity section —
    // harmless to register even if bh-crm is never active.
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
