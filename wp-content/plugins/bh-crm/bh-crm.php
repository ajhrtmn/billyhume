<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     1.3.5
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

define('BHCRM_VER',  '1.3.5');
define('BHCRM_PATH', plugin_dir_path(__FILE__));
define('BHCRM_URL',  plugin_dir_url(__FILE__));

// 1.3.5 — 2026-07-12 — QA/DRY pass, direct response to a live screenshot
// (Design Suite's "Live Views" tab, own-ur-shit 3.4.50) showing this
// plugin's own hand-styled CRM preview registered under a made-up key
// ('bh-crm-profile-live') instead of the surface's REAL registered slug
// ('bh_crm_profile') — own-ur-shit's own auto-story generator (3.4.48)
// had no way to know these were the same page, so both showed up side
// by side. class-style-surface.php's register() now keys under the real
// slug; the auto-generator's own "skip if this key already has a story"
// guard now correctly recognizes it and no longer generates a redundant
// plain fallback. Bonus, unplanned fix from the same key change: the
// tree's own selection-sync (own-ur-shit's element-builder.js) can now
// actually match this story back to a real tree node, so picking this
// Live View also correctly selects the CRM profile Surface node — it
// couldn't before, since 'bh-crm-profile-live' was never a slug the
// tree recognized. See class-style-surface.php's own updated docblock.
// 1.3.4 — 2026-07-12 — doc-only pass, no functional code change. New
// PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md (plugins root) is a detailed,
// phased build plan for duplicating TrackIt's (a macOS music-producer
// task tracker AJ named directly) full feature set inside the Project
// Tracker: reusable checklists, timestamped fixes, a feedback log,
// stall analytics, separate scenes/boards, and linked local audio/MIDI
// files (honestly scoped as the least faithfully portable of the six,
// with a concrete recommendation). class-projects.php's own docblock
// now points to it. NOT built this pass — explicitly deferred per AJ's
// own "we are in the middle of other things... keep moving forward on
// the other stuff."
//
// 1.3.3 — 2026-07-12 — DESIGN-SUITE-UNIFICATION-PLAN.md "NO SPECIAL-
// CASED PAGES" Step 1, the first real one (Step 0 was just proving the
// canvas could show live data). The 'bh_crm_profile' surface's three
// framework-chosen 'header'/'main'/'sidebar' slots are gone — collapsed
// to ONE 'root' slot (class-people.php's register_element_surface()).
// render_detail() now makes a single render_slot() call instead of
// three; class-style-surface.php's live preview updated to match. This
// is a real, if small, proof that a page's layout no longer has to be
// something a plugin author pre-decides in PHP — whatever structure AJ
// wants (a header-looking section, a two-column split, whatever) is now
// something he builds himself as ordinary child nodes under one slot,
// the same mechanism as adding a button. Confirmed zero data loss: all
// three old slots were empty on the live install (verified via
// screenshot before this change), so nothing needed migrating. NOT yet
// done: the rest of render_detail() (identity header, fields table,
// tags/notes editors, project tracker section) is still fixed PHP
// output, not node-tree content — that is Step 2+, explicitly out of
// scope for this pass per the design doc's own phased plan.
//
// 1.3.2 — 2026-07-12 — new class-style-surface.php registers a real,
// LIVE-rendered "CRM profile page (live)" bhy_style_surfaces entry —
// direct response to a live screenshot showing the Design Suite canvas
// stuck on an unrelated bh-contest demo no matter what CRM node was
// selected. Unlike every other plugin's hand-authored HTML mockup
// preview, this one calls the real BH_Element::render_slot() for the
// 'bh_crm_profile' surface's header/main/sidebar at context 0 — what
// you build in the tree now actually shows up here. See that file's own
// docblock for the honest scope note: this does NOT yet replace class-
// people.php's own admin detail page template with pure node-tree
// rendering (that page still has fixed wp-admin chrome outside
// BH_Element) — that is the larger, explicitly-scoped-out-of-this-pass
// follow-up recorded in DESIGN-SUITE-UNIFICATION-PLAN.md's latest
// status note.
//
// 1.3.1 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2: gives
// 'bh/sticky-card' (class-projects.php) a real 'attrs'/'tags' manifest —
// ['div','article'] tags plus a pre-declared, enum-validated structured
// data-attr ('data-status') — as this phase's bh-crm-side example of
// BH_Element::register_type()'s new attrs/tags contract. No storage/
// schema change; own-ur-shit's class-element.php/class-style.php own the
// actual rendering/sanitization this depends on.

// 1.3.0 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (menu
// restructure only): new class-hub.php (BHCRM_Hub) registers a new
// top-level "CRM" menu (bh-crm-hub); People and a new Project Tracker
// listing (BHCRM_Projects::render_boards(), class-projects.php) are
// relocated under it via own-ur-shit's OUS_Registry/OUS_MenuMerge
// 'parent' extension, gated on the new 'bhcore_manage_crm' capability
// instead of 'manage_options'. No board/kanban/inspector logic changed
// — see class-hub.php's and class-projects.php's own docblocks. Standing
// caveat: reasoning/brace-balance-checked only, no live PHP/MySQL/
// WordPress execution available this session — see class-hub.php's
// docblock for the specific smoke-test recommendation (non-admin editor
// account, after an OPcache reset) given this install's documented
// standalone-page registration history.

// 1.2.0 — 2026-07-11 — Project tracker: a kanban-like nested-sticky-note
// project board built ON TOP OF own-ur-shit's element builder system,
// not a parallel UI. New class-projects.php (BHCRM_Projects — the
// bhcrm_projects table, the 'bh/sticky-card' BH_Element type, the
// 'bhcrm_project_board' surface, the 'bhcrm/sub-card' BH_Content block
// type for recursive sub-task nesting, render-time roll-up counting) and
// class-debug.php (BHCRM_Debug — this plugin's first Debug Tools
// section, a "Seed Project Tracker Demo Data" seed/reset action matching
// bh-registry's BHR_Debug pattern exactly). New assets/js/kanban-
// board.js + assets/css/kanban-board.css — a bespoke board presentation
// layer that saves through the EXISTING ous/v1/elements/placements REST
// bridge, not a new data model. BHCRM_People::render()/render_detail()
// gained a project_id dispatch branch and a "Projects" section — both
// additive, no existing person-page output changed or removed. See
// class-projects.php's own docblock for the kanban-column judgment call
// (attribute-per-card, not slot-per-column) and the roll-up semantics
// chosen (informational only, no auto-complete-parent write-back).
// Standing caveat: reasoning/brace-balance-checked only, no live PHP/
// MySQL/WordPress/REST/browser execution available this session.

// 1.1.2 — 2026-07-10 — Element builder, ELEMENT-BUILDER-DESIGN-PLAN.md
// §5.2 surface expansion: registers the 'bh_crm_profile' surface with
// BH_Element (BHCRM_People::register_element_surface(), guarded by
// class_exists('BH_Element'), class-people.php) and adds three
// BH_Element::render_slot() call sites (header/main/sidebar) inside
// BHCRM_People::render_detail() — additive only, no existing profile
// page output changed or removed. Standing caveat: reasoning/brace-
// balance-checked only, no live PHP/MySQL/WordPress execution
// available this session.

// 1.1.1 — class-notes.php's handle_save() now also queues a toast
// (OUS_Toast::queue(), own-ur-shit 3.4.18+) right before its existing
// admin-post redirect, so "Notes saved." shows as a real toast in
// addition to the plain-text $_GET['bhcrm_msg'] notice it already
// carried — additive only. See class-notes.php's own comment at the
// call site; degrades to a no-op on an older own-ur-shit core.

// 1.1.0 — this plugin is now also a genuine BH_Event consumer AND
// emitter (own-ur-shit's event-tracking layer, class-event.php): added
// class-event-activity.php, which contributes an "Event Tracking"
// section to bh_crm_activity_summary (reading {$wpdb->prefix}
// bhcore_events directly, bounded/prepared) alongside the pre-existing
// bh-contest/bh-streaming/bh-courses contributions; class-notes.php and
// class-tags.php now each emit a 'bhcrm/note_saved' / 'bhcrm/tags_saved'
// event at the tail of their own handle_save() (additive only — no
// change to either save path's actual behavior). Standing caveat:
// reasoning/brace-balance-checked only, no live PHP+MySQL execution in
// this pass — not runtime-verified.
foreach (['people', 'notes', 'tags', 'export', 'event-activity', 'projects', 'debug', 'hub', 'style-surface'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHCRM_Projects', 'activate']);

/**
 * Depends only on the core plugin (shared identity) — genuinely nothing
 * else. This is deliberately a PEER to bh-contest and bh-streaming, not
 * something either of them requires or that requires either of them:
 * each stands alone, and each can optionally enrich the other's view of
 * a person through a filter (see class-people.php's docblock for the
 * activity-contribution contract). Delete bh-contest entirely and this
 * plugin's person list still works — it just has one less voice
 * contributing to each person's activity section.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH CRM</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1: this plugin now DOES
    // register its own top-level page — BHCRM_Hub::add_menu() (new,
    // class-hub.php), registered directly here (inside plugins_loaded,
    // which always fires before 'admin_menu') so its 'admin_menu'
    // registration lands before OUS_MenuMerge's relocation pass at
    // priority 999, which needs this top-level parent to already exist.
    // People and Project Tracker themselves are STILL relocated as
    // submenus under it by OUS_MenuMerge (see the 'admin_menus' entry
    // for bh-crm in the core's class-registry.php) — only the top-level
    // parent itself is new here.
    BHCRM_Hub::init();

    add_action('admin_post_bhcrm_save_note', ['BHCRM_Notes', 'handle_save']);
    add_action('admin_post_bhcrm_save_tags', ['BHCRM_Tags', 'handle_save']);
    add_action('admin_post_bhcrm_export',    ['BHCRM_Export', 'handle']);

    // Element builder (ELEMENT-BUILDER-DESIGN-PLAN.md §5.2) — registers
    // the 'bh_crm_profile' surface so BH_Element's palette/placements/
    // REST bridge know this page exists, gated on the core class the
    // same "harmless no-op otherwise" way as the BH_Event registration
    // just below it.
    if (class_exists('BH_Element')) {
        add_filter('bh_element_surfaces', ['BHCRM_People', 'register_element_surface']);
        BHCRM_StyleSurface::init(); // 1.3.2 — real live-rendered "Preview surface" entry, see that file's own docblock
    }

    // Project tracker (1.2.0) — BHCRM_Projects::init() itself guards its
    // own BH_Element/BH_Content registrations with class_exists(), same
    // "harmless no-op otherwise" posture as every other optional
    // integration in this bootstrap; this call is unconditional the same
    // way BHCRM_Notes::init()/BHCRM_Tags::init() (implicitly, via their
    // admin_post hooks above) are — the class itself decides what's safe
    // to actually register.
    BHCRM_Projects::init();
    BHCRM_Debug::init();

    // BH_Event registration + this plugin's own contribution to
    // bh_crm_activity_summary — both gated on the core event-tracking
    // class actually being present, same "harmless no-op otherwise"
    // convention every other BH_Event consumer in this ecosystem
    // follows (see bh-courses/includes/class-progress.php::init()).
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
