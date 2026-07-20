<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     2.4.6
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 2.4.0 — mobile-responsive kanban layout (782px breakpoint), auto-mark-done
// when a card is dropped in the last ("done") column (one-directional — moving
// a card back out does not un-check it, so completions can't be silently lost),
// and a top-level board rollup showing each card's full recursive sub-task
// tally via new BHCRM_Projects::rest_rollups() (bh-crm/v1/rollups).

// 2.3.0 — inline-editable title/description on sub-task cards (saves on blur,
// no page reload) and a real progress bar (BHCRM_Subtasks::render_progress_bar())
// replacing the old plain "X/Y done" text, at both board level and per-card.

// 2.2.0 — rebuilt BHCRM_Subtasks so every nesting level renders as a full
// multi-column kanban board (reusing kanban-board.css/.js) instead of the
// flat checklist 2.0.0/2.1.0 had built. New 'column' schema attr on
// 'bhcrm/sub-card'; all nesting levels share one column vocabulary (the
// parent project's own columns_config) rather than a per-level column set.

// 2.1.0 — four UI improvements to the nested sub-task tracker: breadcrumb
// collapse past 5 segments, a non-blocking size warning past 50 nodes in a
// card's whole tree, sibling drag-reorder at any level (via fetch() to an
// admin-post handler rather than a <form>, since nested <form> elements
// break inside certain WP admin contexts), and bulk "one per line" add.

// 2.0.0 — real nested sub-task tracking view (class-subtasks.php), replacing
// Content Studio for this purpose: breadcrumb trail, recursive done/total
// rollup at every level, add/toggle/edit/delete at any depth. New 'uid' attr
// on 'bhcrm/sub-card' gives each nested sub-task a stable identifier that
// survives a reorder or sibling edit elsewhere in the tree — needed since
// linking a sub-task to a person/project (BHCRM_Links) requires a stable id.
// Note: a placement's config stores each attr as {literal: 'x'}, not a flat
// {title: 'x'} map — read card titles through BHCRM_Subtasks::card_title().

// 1.9.3 — segment/project deletion now logs to OUS_Audit before the row is
// gone, and both delete handlers route their capability check through
// OUS_Audit::require_cap() instead of a bare current_user_can().

// 1.9.2 — BHCRM_Links::link()'s insert now logs an error if the write fails,
// instead of silently proceeding with a bogus $link_id.

// 1.9.1 — permissions audit fixes: (1) BHCRM_People::render_profile()'s phone
// number line now requires bhcore_view_crm_sensitive (admin-only), not just
// bhcore_manage_crm. (2) Every non-destructive CRM admin-post handler (save
// note, save/bulk tag, export, save segment, project create/save-columns/
// link/unlink) switched from manage_options to bhcore_manage_crm, since these
// were wp_die()'ing for any editor/manager who could see the CRM menu but
// not use it. Segment/project delete intentionally stay manage_options.

// 1.9.0 — unified per-person activity timeline: BH_Event::emit() calls added
// at project links, contest submissions, wallet activity, and outbound email
// write points, with labels in BHCRM_Event_Activity::type_label(). (Surfaced
// a pre-existing ecosystem-wide event-ingest data-loss bug, fixed separately
// in own-ur-shit 3.4.89.)

// 1.8.1 — project creation no longer requires a person in context. Added a
// "Create project" form to the Project Tracker index; fixed board dispatch
// and render_board()'s "back" link, which previously hard-required a truthy
// $uid to open a project's board (an unowned project couldn't be viewed).

// 1.8.0 — projects<->people relationship redesign: bhcrm_projects.crm_person_id
// was a hard single-owner column with no room for a collaborator/watcher and
// no way to extend to other entity pairs. New class-links.php: a generic
// typed relationship table (bhcrm_links: from_type, from_id, to_type, to_id,
// relation, created_at) supporting any number of people per project under a
// typed relation, and reusable for any future entity pair with zero schema
// changes. crm_person_id is kept as a legacy fallback (still written on
// create()) but no longer read as source of truth anywhere; migrate_legacy_
// project_owners() backfills existing projects into real 'owner' links once,
// idempotently.

// 1.7.2 — wrapped each section of the person detail page (Profile, Tags,
// Notes, Projects, Activity) in .bhy-card for visual separation — previously
// bare h2/h3/p elements with no card grouping.

// 1.7.1 — wrapped the People/CRM list page toolbar in one flex column with a
// consistent --bhy-space-3 gap, replacing loose <p>/<input> elements that
// relied on inconsistent default paragraph margins. Widened the Activity
// column (min-width:220px) to stop its summary text wrapping awkwardly.

// 1.3.6 — kanban-board.js's hand-rolled HTML5 drag-and-drop (which only
// supported dropping at the end of a column) replaced with SortableJS
// (assets/js/vendor/sortable.min.js, vendored). reorderFromDom() rebuilds
// state.placements from the post-drop DOM and re-saves through the same
// saveSlot() every other edit uses. Drag now only initiates from a small
// handle (⋮⋮) since cards contain interactive controls that would otherwise
// fight with drag detection. forceFallback:true forces SortableJS's pointer-
// event simulation instead of the native HTML5 draggable API, which some
// automated-drag tooling can't trigger and which has generally weaker
// cross-browser/touch support.
// 2.4.6 — the kanban preview's Design Suite entry (class-style-surface.php)
// inherited the gallery's brand font-family token, so a Typography pick
// restyled this fake wp-admin screen too. Fixed with an explicit
// system-font-stack override.
define('BHCRM_VER',  '2.4.6');

// 2.4.5 — registered the kanban Project Tracker board as its own Design
// Suite surface (class-style-surface.php) — previously the gallery only
// showed the CRM profile page. Fixed a light-on-light text-contrast bug:
// kanban-board.css expects a real light wp-admin background.

// 2.4.4 — live "N of M people match" preview for the segment builder
// (BHCRM_Segments::ajax_preview(), segment-builder.js's debounced
// runPreview()), using the same sanitize_conditions()/apply() pair the save
// path uses so preview and save can never drift apart.

// 2.4.3 — subtask-board reorder save's failure handler called
// window.location.reload() unconditionally, silently discarding a drag-drop
// on network failure with no error shown. Now retries with backoff (a
// full-layout save is idempotent) and only reloads, with a visible error
// toast, once retries are exhausted. saveField() gets the same treatment.

// 2.4.2 — first contributor to OUS_Metrics dashboard (People tracked,
// Relationship links widgets in class-people.php), using
// event_trend_monthly() rather than the 30-day event_trend() since a
// relationship graph moves slower than votes/enrollments and a daily
// sparkline would mostly be noise. class_exists()-guarded.

// 2.4.1 — class-hub.php's log_result() now only logs a registration
// FAILURE, not every successful admin-menu registration on every page load.


// 1.7.0 — saved smart lists/segments. New bhcrm_segments table storing a name
// + flat, AND-only list of conditions — deliberately not a nested AND/OR
// group tree, since this CRM's person list tops out at a few hundred people
// and flat AND covers the real use cases. Four condition types (tag,
// registered after/before, has an active project), validated server-side
// against BHCRM_Segments::FIELDS so the UI can never offer something the
// server would reject. A segment filter AND-combines with the existing
// tag-filter query arg rather than replacing it.

// 1.6.0 — bulk actions on the person list: checkbox per row + header
// select-all in one <form> with two submit buttons (each targeting a
// different admin-post action via formaction). New BHCRM_Tags::add_tag()/
// handle_bulk_tag() ADDS one tag to each selected person's existing list
// rather than replacing it. class-export.php's handle() intersects a POSTed
// bulk_ids[] against the existing active/tag-filtered id set rather than
// trusting it outright. Fixed a latent bug found while building this:
// class-export.php still called the since-removed BHCRM_Notes::get() (1.4.0
// replaced it with timestamped history) — would have fatal-errored the next
// export attempt; replaced with a new notes_summary() helper.

// 1.5.0 — tag chips + autocomplete in the person editor, replacing the plain
// comma-separated text input. Storage/handle_save()/BH_Event payload
// unchanged. New assets/js/tag-chips.js is progressive enhancement — the
// original plain text input stays in the DOM (hidden) as the real form field
// handle_save() reads, so a JS-off browser degrades to the old behavior.

// 1.4.0 — notes rebuilt as timestamped history + authorship + reminders,
// replacing the single-overwrite freeform `_bhcrm_notes` user meta field.
// New bhcrm_notes table — every note is its own row, appended not
// overwritten; pre-existing notes are migrated forward once, lazily, as a
// single legacy-labeled note. Reminders schedule via OUS_Jobs::enqueue() and
// notify through OUS_Notifications::notify(), addressed to the note's
// original author. Fixed two bugs found during verification: (1)
// list_for_person()'s ORDER BY created_at DESC had no id tiebreaker, so
// display order was non-deterministic when two notes landed in the same
// second. (2) handle_reminder_job() checked its own reminder_dismissed flag
// but never set it, so a reminder could fire twice; fixed with an atomic
// UPDATE ... WHERE reminder_dismissed = 0 claim-check before notifying.
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
// built on own-ur-shit's element builder system. New class-projects.php
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
// older own-ur-shit core.

// 1.1.0 — this plugin is now a BH_Event consumer and emitter: added
// class-event-activity.php (contributes an "Event Tracking" section to
// bh_crm_activity_summary, reading bhcore_events directly, bounded/
// prepared); class-notes.php and class-tags.php each emit a
// 'bhcrm/note_saved' / 'bhcrm/tags_saved' event at the tail of handle_save().
foreach (['people', 'notes', 'tags', 'segments', 'export', 'event-activity', 'links', 'projects', 'subtasks', 'debug', 'hub', 'style-surface', 'test-suite'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHCRM_Links', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Projects', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Notes', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Segments', 'activate']);

/**
 * Depends only on the core plugin. Deliberately a peer to bh-contest and
 * bh-streaming, not a dependency of either — each stands alone and can
 * optionally enrich the other's person view through a filter (see
 * class-people.php's activity-contribution contract).
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH CRM</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
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
    BHCRM_Notes::init();
    BHCRM_Tags::init();
    BHCRM_Segments::init();
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
