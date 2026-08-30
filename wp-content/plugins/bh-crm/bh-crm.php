<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     2.4.23
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHCRM_VER',  '2.4.23');

define('BHCRM_PATH', plugin_dir_path(__FILE__));
define('BHCRM_URL',  plugin_dir_url(__FILE__));

// 1.3.5 — class-style-surface.php's register() now keys its Design Suite
// "Live Views" entry under the surface's real registered slug
// ('bh_crm_profile') instead of a made-up key, so the auto-story generator's
// "skip if this key already has a story" guard recognizes it (previously
// generated a redundant duplicate) and the tree's selection-sync can match
// it back to the real tree node.
// 1.3.4 — doc-only pass, no functional code change. New
// PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md (plugins root) is a phased build
// plan for TrackIt-parity features (checklists, timestamped fixes, feedback
// log, stall analytics, scenes/boards, linked audio/MIDI). Not built this
// pass — deferred.
//
// 1.3.3 — the 'bh_crm_profile' surface's three fixed 'header'/'main'/
// 'sidebar' slots collapsed to one 'root' slot; render_detail() now makes a
// single render_slot() call instead of three. All three old slots were
// confirmed empty on the live install, so nothing needed migrating. The rest
// of render_detail() (identity header, fields table, tags/notes editors,
// project tracker section) remains fixed PHP output, not node-tree content.
//
// 1.3.2 — new class-style-surface.php registers a real, live-rendered "CRM
// profile page (live)" bhy_style_surfaces entry, calling the real
// BH_Element::render_slot() for the 'bh_crm_profile' surface instead of a
// hand-authored HTML mockup. Does not yet replace class-people.php's own
// admin detail page template with pure node-tree rendering.
//
// 1.3.1 — gives 'bh/sticky-card' a real 'attrs'/'tags' manifest (['div',
// 'article'] tags plus an enum-validated 'data-status' data-attr), as the
// bh-crm-side example of BH_Element::register_type()'s attrs/tags contract.

// 1.3.0 — new class-hub.php (BHCRM_Hub) registers a top-level "CRM" menu;
// People and Project Tracker listing are relocated under it via
// OUS_Registry/OUS_MenuMerge's 'parent' extension, gated on a new
// 'bhcore_manage_crm' capability instead of 'manage_options'. No board/
// kanban/inspector logic changed.

// 1.2.0 — Project tracker: a kanban-like nested-sticky-note project board
// built on the-self-hosted-self's element builder system. New class-projects.php
// (bhcrm_projects table, 'bh/sticky-card' BH_Element type, the
// 'bhcrm_project_board' surface, 'bhcrm/sub-card' block type for recursive
// sub-task nesting, render-time roll-up counting) and class-debug.php (Debug
// Tools seed/reset action). New assets/js/kanban-board.js — saves through
// the existing ous/v1/elements/placements REST bridge. Roll-up is
// informational only — no auto-complete-parent write-back.

// 1.1.2 — registers the 'bh_crm_profile' surface with BH_Element
// (class-people.php, guarded by class_exists('BH_Element')) and adds three
// render_slot() call sites (header/main/sidebar) inside render_detail() —
// additive only.

// 1.1.1 — class-notes.php's handle_save() now also queues a toast
// (OUS_Toast::queue()) before its admin-post redirect, in addition to the
// existing plain-text $_GET['bhcrm_msg'] notice. Degrades to a no-op on an
// older the-self-hosted-self core.

// 1.1.0 — this plugin is now a BH_Event consumer and emitter: added
// class-event-activity.php (contributes an "Event Tracking" section to
// bh_crm_activity_summary, reading bhcore_events directly, bounded/
// prepared); class-notes.php and class-tags.php each emit a
// 'bhcrm/note_saved' / 'bhcrm/tags_saved' event at the tail of handle_save().
foreach (['tables', 'people', 'notes', 'tags', 'segments', 'export', 'event-activity', 'links', 'projects', 'subtasks', 'card-log', 'debug', 'hub', 'style-surface', 'test-suite'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHCRM_Links', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Projects', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Notes', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Segments', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_CardLog', 'activate']);

/**
 * Depends only on the core plugin. Deliberately a peer to bh-contest and
 * bh-streaming, not a dependency of either — each stands alone and can
 * optionally enrich the other's person view through a filter (see
 * class-people.php's activity-contribution contract).
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH CRM</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // Registered here (inside plugins_loaded, before 'admin_menu' fires) so
    // this top-level parent exists before OUS_MenuMerge's relocation pass at
    // priority 999 needs it. People/Project Tracker are relocated as submenus
    // under it by OUS_MenuMerge (see bh-crm's 'admin_menus' entry in
    // class-registry.php).
    BHCRM_Hub::init();

    add_action('admin_post_bhcrm_save_note', ['BHCRM_Notes', 'handle_save']);
    add_action('admin_post_bhcrm_save_tags', ['BHCRM_Tags', 'handle_save']);
    add_action('admin_post_bhcrm_bulk_tag',  ['BHCRM_Tags', 'handle_bulk_tag']);
    add_action('admin_post_bhcrm_export',    ['BHCRM_Export', 'handle']);

    // Registers the 'bh_crm_profile' surface so BH_Element's palette/
    // placements/REST bridge know this page exists; no-op if BH_Element isn't
    // present.
    if (class_exists('BH_Element')) {
        add_filter('bh_element_surfaces', ['BHCRM_People', 'register_element_surface']);
        BHCRM_StyleSurface::init();
    }

    BHCRM_Links::init(); // must run before BHCRM_Projects::init() — projects write links on create()
    BHCRM_Projects::init();
    BHCRM_Subtasks::init();
    BHCRM_CardLog::init();
    BHCRM_Notes::init();
    BHCRM_Tags::init();
    BHCRM_Segments::init();
    // Feeds every saved CRM list into OUS_Campaigns' own audience picker
    // — see class-segments.php's docblock. This is a contribution to the
    // 'email_broadcast' OUS_Integration contract (registered in
    // the-self-hosted-self.php, which actually owns OUS_Campaigns), not a
    // registration of that contract itself — bh-crm doesn't own the
    // built-in default, it just makes it more useful once active.
    add_filter('bhcore_campaign_segments', ['BHCRM_Segments', 'register_campaign_segments']);
    BHCRM_Debug::init();
    if (class_exists('OUS_TestRunner')) BHCRM_TestSuite::init();

    // Gated on BH_Event actually being present — no-op otherwise.
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhcrm/note_saved', ['user_id' => 'int']);
            BH_Event::register_event_type('bhcrm/tags_saved', ['user_id' => 'int', 'tags' => 'string[]']);
        }
        if (class_exists('BHCRM_Event_Activity')) {
            BHCRM_Event_Activity::init();
        }
    });
});
