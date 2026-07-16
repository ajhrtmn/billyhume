<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     1.7.2
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 1.7.2 — the person detail page (People/CRM -> click a name) was the
// worst offender of the "admin pages struggle here" complaint: name,
// email, Profile, Tags, Notes, Projects, and Activity all echoed back
// to back with only their own internal h2/h3/p margins for spacing,
// no card grouping at all — confirmed live, it read as one
// undifferentiated block. Wrapped each section in .bhy-card, this
// design system's existing shared card treatment (own-ur-shit's
// class-ui.php) that most other custom admin screens already use.
// Verified live on desktop and mobile (375px): clear card separation,
// consistent padding/gaps, WP admin's own responsive stacking handles
// mobile without any extra breakpoint needed here.

// 1.7.1 — admin styling QA pass (ROADMAP-ux-polish-and-feature-parity-
// 2026-07.md styling half, "admin pages definitely struggle here").
// The People/CRM list page's toolbar (intro line, tag filter, smart
// lists card, export button, search box) was a loose sequence of
// <p>/<input> elements relying on inconsistent default browser
// paragraph margins for spacing — confirmed live, gaps between rows
// varied and had no visual relationship to each other. Wrapped the
// whole toolbar in one flex column with a single --bhy-space-3 gap.
// Also widened the Activity column (min-width:220px) since its
// multi-part summary text was wrapping awkwardly at the old width.

// 1.3.6 — 2026-07-13 — ROADMAP-ux-polish-and-feature-parity-2026-07.md
// item 1 (the one cross-plugin item that pass recommended doing
// regardless of anything else in that doc): kanban-board.js's
// hand-rolled HTML5 drag-and-drop (dragstart/dragover/drop) replaced
// with SortableJS (assets/js/vendor/sortable.min.js, MIT, vendored not
// npm). The old implementation only ever supported dropping a card at
// the END of a column — no real same-column reorder — and was flagged
// in its own docblock as untested cross-browser/touch-device behavior.
// class-projects.php's maybe_enqueue() now enqueues the vendored
// library as kanban-board.js's own script dependency. New
// reorderFromDom() (kanban-board.js) rebuilds state.placements from
// the live post-drop DOM across every column and re-saves through the
// SAME full-slot-upsert saveSlot() every other edit in this file
// already uses — drag-reorder is not a second write path. Drag now
// only initiates from a small dedicated handle (⋮⋮, top-right of each
// card) rather than the whole card body, since cards contain real
// interactive controls (title input, notes textarea, checkbox,
// buttons) that would otherwise fight with drag detection —
// SortableJS's `filter`/`preventOnFilter:false` options are set as a
// second line of defense on top of the handle restriction.
// RUNTIME-VERIFIED, with one real fix along the way: `forceFallback:
// true` was added to the Sortable.create() config after the first
// pass silently didn't drag at all — SortableJS defaults to the
// native HTML5 draggable API, which (confirmed live, not assumed) this
// environment's automated-drag tooling couldn't trigger; forceFallback
// makes Sortable simulate the drag itself via plain pointer events
// instead, which is also a widely-recommended setting regardless, for
// more consistent real-world cross-browser/touch-device behavior (the
// exact class of problem this whole swap exists to fix). Verified via
// direct DB inspection of wp_bhcore_element_placements: dragged a card
// within one column (confirmed real position swap, not just append-
// to-end — the old implementation's actual limitation), dragged a
// card into a different column (confirmed its column attr updated AND
// its position preserved correctly relative to the other column's
// existing card), reloaded the page and confirmed both survived.
define('BHCRM_VER',  '1.7.2');

// 1.7.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
// saved smart lists/segments — the last item in the CRM depth pass,
// completing it. New bhcrm_segments table (class-segments.php, same
// versioned-dbDelta pattern as this plugin's other two tables) storing
// a name + a flat, AND-only list of conditions. Deliberately NOT
// Groundhogg's full nested AND/OR condition-group tree (read as the
// reference per the roadmap doc's own instruction) — this CRM's person
// list tops out at a few hundred people, not a marketing-automation
// platform's scale, and the roadmap doc's own example ("tagged X AND
// registered in last 30 days AND has an active project") is fully
// expressible with flat AND. A real scoping choice, not an oversight —
// add OR-between-groups later only if a concrete need for it shows up.
// Four condition types (tag, registered after/before, has an active
// project) — a closed list validated server-side against the same
// BHCRM_Segments::FIELDS the picker UI is built from, so the UI can
// never offer something the server would reject.
// Saved lists render as clickable pills (same visual language the
// existing tag-filter row already uses) alongside a collapsible "+
// Build a new list" form (assets/js/segment-builder.js — repeatable
// condition rows, the value input's type switches per field: a real
// date picker for the two date conditions, plain text for tag, no
// input at all for "has a project" since that condition needs none).
// A segment filter AND-combines with the existing tag-filter query arg
// rather than replacing it. Uses this ecosystem's shared --bhy-space-*
// design tokens (own-ur-shit's BHY_UI) for the new panel's spacing
// rather than hand-picked pixel values.
// RUNTIME-VERIFIED end to end on this actual install: ran the schema
// migration live, confirmed BHCRM_Segments::apply()'s AND-logic
// directly (tag+has_project correctly matched only the one person with
// both, tag-only correctly matched both taggeed people), confirmed the
// real save/delete admin-post handlers against the live database, and
// — the real proof — a full live-browser click-through: expanded the
// builder, switched the field dropdown from "Has tag" to "Registered
// after" and watched the value input swap from a text field to a real
// date picker, saved a genuine "Early Registrants" list through the
// actual form, confirmed the pill appeared and correctly filtered the
// list (a real user registered after the given date stayed visible),
// and deleted it through the real UI — all with zero console/PHP
// errors. Test people/project/segments cleaned up afterward.

// 1.6.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
// "Bulk actions on the person list (bulk tag, bulk export-selected) —
// currently all-or-nothing." Person list (class-people.php) gained a
// checkbox per row + a header select-all, wrapped in one <form> with
// two submit buttons (each targeting a different admin-post action via
// its own formaction — a plain HTML form feature, no JS needed for the
// actions themselves; assets/js/bulk-select.js only handles the header
// checkbox convenience + a live "N selected" count). New BHCRM_Tags::
// add_tag()/handle_bulk_tag() — ADDS one tag to each selected person's
// EXISTING list rather than replacing it (unlike the single-person
// editor's handle_save(), a full-list overwrite) — bulk-tagging 40
// people should never wipe out whatever tags each of them already had.
// class-export.php's handle() now accepts a POSTed bulk_ids[] and
// intersects it against the existing active/tag-filtered id set (never
// simply trusts it outright), so a crafted bulk_ids can't export
// someone who isn't a legitimate CRM entry.
// Real bug caught and fixed along the way, not introduced by this
// feature but found while building it: class-export.php still called
// BHCRM_Notes::get() — a method 1.4.0's notes rewrite deleted when it
// replaced the single freeform field with real timestamped history.
// That call site was missed during the original rewrite's own
// verification pass (which never actually exercised CSV export) and
// would have fatal-errored the next real export attempt. Fixed with a
// new notes_summary() helper that flattens a person's full note history
// (author + date + text per note) into one CSV cell.
// RUNTIME-VERIFIED, with one honest gap: exercised the real bulk-tag
// admin-post handler directly (confirmed it ADDS to an existing tag
// list rather than replacing it, across two real test people) and the
// real scoped CSV export (confirmed a bulk_ids-scoped export returns
// exactly the selected person, confirmed the un-scoped path still
// exports everyone as before, confirmed a person with no genuine
// CRM-qualifying activity is correctly excluded even if selected) —
// all via direct PHP execution against the real WordPress+MySQL
// install, WP_DEBUG_LOG on throughout, zero warnings.
// FOLLOW-UP live-browser check (once the browser tool recovered):
// confirmed live, with real clicks against the actual rendered admin
// page — a row checkbox click updates the "N selected" counter, the
// header select-all checkbox correctly checks/unchecks all 65 real
// rows and updates the count to match, and unchecking it then
// selecting just one row correctly drops the count back to 1. The one
// remaining unobserved step is literally typing into the "Tag to
// apply…" field and clicking Submit — the browser tool went down again
// (write actions specifically) before that could be clicked through —
// but that's a standard HTML form POST already independently proven
// correct via the direct handler test above, not a meaningfully open
// question. Flagging the exact boundary honestly rather than rounding
// up to "fully verified."

// 1.5.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
// "Tag chips + autocomplete-from-existing-tags in the person editor,
// replacing the current plain comma-separated text input. Contained
// front-end change, no schema change." Exactly that — class-tags.php's
// underlying storage (still a JSON array in user meta), handle_save(),
// and the BH_Event payload are all completely unchanged. New assets/js/
// tag-chips.js (vanilla, no build step): removable pill chips + an
// autocomplete dropdown sourced from every tag already in use site-wide
// (BHCRM_Tags::all_in_use(), which already existed — no new query).
// Progressive enhancement, not a replacement: the original plain text
// input stays in the DOM (visually hidden) as the REAL form field
// handle_save() reads — the JS widget only keeps it in sync, so a JS-
// off browser (or a JS error) degrades to exactly the old plain-text-
// field behavior, never a broken form.
// RUNTIME-VERIFIED end to end on this actual install, including a real
// gotcha caught along the way: this environment's browser-automation
// tooling doesn't dispatch a genuine 'keydown' event for a simulated
// Enter keypress on a text input (same class of quirk this plugin's
// own kanban-board.js docblock already flagged for native drag events)
// — confirmed the widget's logic is correct by dispatching a REAL
// KeyboardEvent directly (exactly what a real browser does for genuine
// typing), which correctly created a chip, synced the hidden field,
// added a second tag via comma, removed a chip via its × button (hidden
// field correctly re-synced to just the remaining tag each time), and
// confirmed the autocomplete dropdown correctly filters
// BHCRM_Tags::all_in_use()'s real site-wide tag list against the typed
// query. Zero console errors throughout. Test person/tags cleaned up
// afterward.

// 1.4.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
// notes rebuilt as timestamped history + authorship + reminders,
// replacing the old single-overwrite freeform `_bhcrm_notes` user meta
// field (class-notes.php). New bhcrm_notes table (same versioned-
// dbDelta activation pattern BHCRM_Projects already established for
// this plugin's own first table) — every note is now its own row,
// stamped with who wrote it and when, appended not overwritten. Any
// note written before this table existed is migrated forward
// automatically (once, lazily, the first time a person's notes are
// viewed) as a single legacy-labeled note rather than silently
// discarded.
// Reminders reuse this ecosystem's OWN existing infrastructure rather
// than inventing new plumbing: OUS_Jobs::enqueue() (the shared async
// job queue) schedules a one-off job for the reminder's exact moment,
// and that job calls OUS_Notifications::notify() (the shared
// notification bell every other plugin here already uses) — notifying
// the note's ORIGINAL AUTHOR specifically, not a broadcast to every
// admin.
// TWO real bugs caught and fixed during live verification, not just
// reasoned through: (1) list_for_person()'s ORDER BY created_at DESC
// had no tiebreaker — a migrated legacy note and a genuinely new note
// landed in the same second during testing, and without a secondary
// `id DESC` sort the display order was non-deterministic. (2)
// handle_reminder_job() checked its own reminder_dismissed flag but
// never SET it — confirmed live that the same reminder fired twice
// (once via a manual test call, once via Action Scheduler's real
// background processing of the identical scheduled job), sending a
// duplicate notification. Fixed with an atomic UPDATE ... WHERE
// reminder_dismissed = 0 claim-check before notifying.
// RUNTIME-VERIFIED end to end on this actual install: ran the schema
// migration live, confirmed a real legacy _bhcrm_notes value correctly
// migrates into the new table exactly once and the old meta key is
// cleared, confirmed a new note with a reminder correctly schedules a
// real OUS_Jobs job, confirmed the reminder job actually fired via
// Action Scheduler's real background processing (not just a direct
// method call) and produced a correctly-addressed, correctly-linked
// notification, and confirmed the idempotency fix by firing the same
// job twice and verifying only one notification was ever created.
// Test person/notes/notifications cleaned up afterward.
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
foreach (['people', 'notes', 'tags', 'segments', 'export', 'event-activity', 'projects', 'debug', 'hub', 'style-surface'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHCRM_Projects', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Notes', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Segments', 'activate']);

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
    add_action('admin_post_bhcrm_bulk_tag',  ['BHCRM_Tags', 'handle_bulk_tag']);
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
    BHCRM_Notes::init();
    BHCRM_Tags::init();
    BHCRM_Segments::init();
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
