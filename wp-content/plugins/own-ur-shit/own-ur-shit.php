<?php
/**
 * Plugin Name: Own Ur Shit
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.5.4
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 3.5.1 — a shared, opt-in wide-layout fix for custom post-edit
// screens, AJ's own ask after looking at the contest edit screen
// specifically: "many admin pages suffer from the same issues." New
// OUS_AdminLayout (class-admin-layout.php): several post types in
// this ecosystem (bh_contest, bh_submission, bhm_tier, bh_course) were
// still on WordPress's default two-column post-edit chrome — one
// narrow stacked column of meta boxes plus a fixed ~280px sidebar —
// wasting real horizontal space on a wide screen. Confirmed live: the
// sidebar's own "Contest Rules & Results" box was visibly overflowing
// its column with real content, while the main column sat mostly
// empty beside it.
//
// AJ's own follow-up after seeing a layout-only first cut: "the
// sidebar is awful and should be the main content... I'd be fine with
// no sidebar, just a bunch of well laid out widgets." Final shape:
// every meta box (Publish included) becomes one card in a single
// uniform CSS grid — no distinct sidebar column at all —
// using display:contents on WordPress's own nested container divs to
// flatten its DOM structure for LAYOUT purposes only (the elements
// stay exactly where WordPress put them, so its own drag-reorder JS,
// which operates on the DOM/event model rather than visual position,
// keeps working). Cards reuse this ecosystem's existing --bhy-*
// design tokens (already loaded on every one of these screens) rather
// than staying raw WordPress white-box chrome — subtle shadow,
// consistent padding, the same uppercase card-header treatment
// .bhy-card already uses elsewhere. A second follow-up ("it looks
// awful in the screenshots") is what prompted that visual pass — the
// very first cut only rearranged stock metaboxes without restyling
// them at all.
//
// Two real bugs caught live during this pass, both fixed: (1) a
// title-only CPT (no 'editor' support) gets a DIFFERENT WordPress core
// template shape than a normal post-editor screen — normal-priority
// meta boxes land in a separate #postbox-container-2 sibling, not
// nested inside #post-body-content — a first cut that only widened
// #post-body-content's margin left the two containers stacking
// vertically instead of side by side, pushing everything thousands of
// pixels down the page; the display:contents approach handles both
// DOM shapes identically. (2) bh_contest/bh_submission were both
// missing 'edit_item'/'add_new_item' labels, so every edit screen
// showed generic "Edit Post"/"Add Post" — fixed (bh-contest 3.6.4).
//
// Entirely opt-in per post type via the
// 'ous_wide_admin_layout_post_types' filter, and a complete no-op
// below a 1200px viewport — WordPress's own default single-column
// mobile layout is untouched (AJ's own explicit ask: "make sure we
// are still highly mobile friendly, for admin and client GUIs").
// Verified live at 1110px (mobile-style single column, WP default
// unchanged), 1366px and 1600px (every field across Publish/Contest
// Rules & Results/Voting Categories/Judging Format/Rounds confirmed
// present with real saved content, no horizontal overflow at either
// width).
//
// Final round: AJ's own follow-up, "no so much white space cuz of the
// grid... it just looks wrong." A real CSS Grid limitation, not a bug
// — Grid lays out in strict rows, so a short card (Publish) beside a
// much taller one (Contest Rules & Results) leaves a large dead gap
// underneath it that grid-auto-flow:dense can't fix (it only backfills
// LATER gaps, not one directly beside a tall same-row neighbor).
// Switched from CSS Grid to CSS multi-columns (`columns: 360px 3`,
// `break-inside: avoid` on each card, `column-span: all` on the
// title) — genuine top-to-bottom masonry-style packing with zero JS
// measurement pass. One real tradeoff, accepted: reading order becomes
// top-to-bottom-then-next-column rather than left-to-right-then-wrap
// — fine here since these are independent cards, not a sequence.
// Verified live: cards now visibly pack tight with no orphaned
// whitespace under short ones.

// 3.5.0 — new accountability audit log, AJ's own ask: "audit, do
// everything important, and anything those important things touch...
// yes granular diffs... admin only." New OUS_Audit (class-audit.php):
// a synchronous accountability log (bhcore_audit_log) distinct from
// BH_Event's per-person ACTIVITY timeline — that answers "what did
// this person do," this answers "who changed WHAT to WHAT on a thing
// that isn't necessarily their own" (a tier's price, a segment someone
// else built, another user's role).
//
// log_diff() stores granular before/after field diffs; log() covers
// plain "X happened" actions (deletions, rejections). require_cap() is
// a drop-in for the `if (!current_user_can($cap)) wp_die(...)` pattern
// used everywhere in this ecosystem's admin-post handlers — it does
// NOT log every single denial (pure noise), only once a per-user
// denial count crosses a concerning threshold within a short window
// (AJ's own "log denies and fails if they exceed a concerning
// amount"). Also hooks WordPress's own set_user_role action, so
// granting/revoking any role (including the new Studio Manager) from
// the Users screen is tracked for free, no bespoke UI needed.
//
// Pruning is row-count bound, not time-bound — "keep it unless or
// until it becomes too much extra bloat" (AJ's own framing): once the
// table crosses 20,000 rows, the oldest are trimmed back down to
// 15,000, checked cheaply (throttled via transient) on write rather
// than a separate cron.
//
// Read side: a new "Audit Log" Debug Tools section (already
// manage_options-only — admin-only per AJ's own ask, no new gate
// needed). Wired into the highest-value "important things" this pass:
// bh-crm's segment/project deletion (bh-crm 1.9.3), bh-monetization-
// woo's tier price changes/deletion (bh-monetization-woo 0.4.17), and
// bh-contest's submission rejection (bh-contest 3.6.2). Verified live:
// created and deleted a real segment, confirmed the audit row recorded
// the actor/action/object/diff correctly and rendered in Debug Tools.

// 3.4.91 — debug-log wiring pass (AJ's own ask: "wire any of those new
// events into the debug log... that would be useful and helpful for
// future dev"). Rather than mirroring every routine BH_Event emission
// into OUS_DebugLog (that's what the activity timeline is for),
// OUS_Notifications::send_email_now() now logs a warning specifically
// when wp_mail() returns false — previously a queued notification
// email failing to send was completely silent, with nothing anywhere
// telling a dev it happened. Same fix applied to bh-contest's own two
// direct wp_mail() call sites (bh-contest 3.6.1) and BHCRM_Links::
// link()'s insert failure path (bh-crm 1.9.2).

// 3.4.90 — permissions audit follow-through (AJ's own ask: "audit user
// roles and permissions... admins and site managers should have access
// to a good chunk of this... user-owned relationships where admin sees
// all might be a little more restrictive to non-admin managers"). A
// prior background audit found: no custom role existed at all (only
// capability grants on the built-in 'editor' role), and bhcore_manage_
// crm gated a person's phone number/wallet balance/purchase history/
// refund-fraud flags identically to the plain person list — no split
// between "can see the roster" and "can see private/financial data."
//
// New: OUS_Roles::MANAGER_ROLE ('bhcore_studio_manager', label "Studio
// Manager") — this ecosystem's FIRST real custom WordPress role
// (register_role(), not just a capability grant on an existing role),
// cloned from editor's own capability set at registration time so it
// can manage bh_contest/bh_course/bh_lesson content (all use the
// default 'post' capability_type) plus bhcore_design_site/
// bhcore_manage_crm. Deliberately distinct from 'editor' rather than
// just adding more caps to editor, so the new sensitive-data
// restriction below can apply to a genuinely non-admin "manager"
// account without also having to strip anything back off of editor
// (a real behavior change for any site already relying on editor's
// existing CRM access).
//
// New capability: bhcore_view_crm_sensitive, administrator-only —
// gates the actual sensitive fields (see bh-crm 1.9.1 and
// bh-monetization-woo 0.4.15's own changelogs for the two real call
// sites this now protects).
//
// idempotent: add_role() is a silent no-op if the role already exists,
// so ensure_manager_role() only ever creates it once and never clobbers
// capabilities an admin has since hand-customized on it.

// 3.4.89 — real bug, caught live while wiring more emitters into the
// CRM's unified activity timeline (bh-crm 1.9.0's own changelog):
// BH_Event::handle_ingest_job()'s INSERT used $wpdb->prepare()'s %s
// placeholder for dedup_key, which silently casts a PHP null to an
// empty string, not SQL NULL. dedup_key carries a UNIQUE key, so
// EVERY event emitted without an explicit dedup_key (the common,
// "append-only" case — most emit() call sites across this whole
// ecosystem) collided with the very first such row ever inserted and
// was silently dropped by INSERT IGNORE, ever since this table
// existed. Confirmed directly against the live table: dedup_key was
// stored as '' rather than NULL, and only ONE non-deduped event had
// ever actually landed. This was silent, ecosystem-wide event-tracking
// data loss, not a cosmetic bug. Fixed by branching the insert: a null
// dedup_key now writes a literal SQL NULL (never touching the unique
// index), a real dedup_key keeps the existing INSERT IGNORE dedup
// behavior. One-time data fix also applied directly to the live table
// (UPDATE ... SET dedup_key = NULL WHERE dedup_key = ''). Verified
// live: a fresh non-deduped emit landed correctly and rendered in
// bh-crm's activity timeline immediately after the fix, where it had
// silently failed to appear before it.
//
// Also added BH_Event::for_user() — a thin, type-agnostic per-user
// read helper (newest-first, payload/context pre-decoded) backing the
// same timeline work, since no such read method existed despite
// per-user reads already happening ad hoc (bh-crm's own
// BHCRM_Event_Activity queried the table directly instead).
//
// Also added an 'bhcore/email_sent' emit() to
// OUS_Notifications::send_email_now() — the single choke point every
// queued notification email sends through — so a person's activity
// timeline now includes "received an email" entries, addressing AJ's
// "no email/communication log tied to a person record" gap.

// 3.4.88 — portal styling QA pass, AJ's "wrap up the CRM, then make
// sure styles look sleek and professional on desktop and mobile, not
// clunky/cramped" request. Three real bugs found and fixed against the
// live front-end portal (/account/):
//  1. class-portal.php's own inline <style> block referenced a
//     fictional, never-defined token scheme (--bhy-color-bg etc) that
//     doesn't exist anywhere in this codebase — every declaration
//     silently fell through to hardcoded generic-WP-blue fallbacks, so
//     the portal NEVER showed the real site brand (warm cream/
//     terracotta, --bh-* tokens from class-style.php) on any load,
//     ever. Rewrote every reference to the correct --bh-* names. Also
//     added the portal's first real mobile breakpoint (@media
//     max-width:782px — sidebar collapses to a horizontal scrollable
//     tab strip) since none existed before at all.
//  2. BHI_PublicProfile::maybe_enqueue()'s enqueue gate checked
//     has_shortcode($post->post_content, 'bh_profile'), which can
//     never be true on the portal's own Profile panel — the portal is
//     a custom template_redirect-intercepted virtual page with no real
//     $post/post_content. public-profile.css (correct, --bh-*-token-
//     based) never loaded there; the edit form rendered as completely
//     raw unstyled HTML. Added an additional
//     get_query_var(BHI_Portal::QUERY_VAR) check to the gate.
//  3. class-notifications.php's inline notification-list CSS used
//     hardcoded WP-admin-bar colors (admin blue #72aee6 etc) — fine
//     for the admin-bar dropdown this markup also serves, but jarring
//     against the brand when the same render_portal_panel() output
//     shows up in the front-end portal. Switched to --bh-* tokens with
//     the original hardcoded values kept as fallbacks, so the
//     admin-bar context is visually unchanged.
// Also added a new shared .bhi-portal-section card-wrapper class to
// class-portal.php's stylesheet (bh-monetization-woo's Membership &
// Wallet panel was the first consumer — see that plugin's own 0.4.13
// changelog) so panels stop hand-rolling section separation that then
// drifts from each other.
// All three fixes verified live in-browser: before/after screenshots
// confirming correct brand colors, a properly laid-out and labeled
// Profile edit form, on-brand notification cards, and the new mobile
// breakpoint working correctly at 375x812, zero console/PHP errors.

// 3.4.85 — real bug sweep, not a feature pass: while building bh-
// monetization-woo's first ServerSideRender block this session, hit a
// confirmed WordPress bug pattern — a class's own init() method,
// itself only ever invoked AS an 'init' hook callback, was internally
// registering a SECOND add_action('init', ...) of its own. Since
// WP_Hook never revisits a priority bucket it has already passed in
// the same request, that inner registration silently never fires,
// ever, with zero error anywhere — confirmed directly against a
// minimal WP_Hook reproduction, not assumed. A background audit swept
// this whole ecosystem for the same pattern and found it FIVE more
// times in this plugin alone: OUS_Jobs::init() (Action Scheduler's own
// bootstrap AND the cron-scheduling check both dead), OUS_Notifications
// ::init() ([bh_notifications] shortcode AND the queued-email job
// handler both dead), BH_Event::init() (the event-ingest job handler
// dead), BH_Identity::init() (a guest's first-touch identity cookie
// never issued), OUS_Toast::init() (a guest's queued toast never
// persisted to their next request). Fixed by calling ::init() directly
// at this file's own top-level bootstrap point instead of deferring
// through a second 'init' hook layer — see each call site's own inline
// comment below for specifics. Also fixed the ALREADY-FLAGGED (but
// previously unfixed) OUS_Gutenberg_Block::init() — currently inert
// for an unrelated reason (BH_Element_Prefab no longer exists post
// page-builder-delete), but wrong regardless.
// A second, real fatal was caught fixing the FIRST one: turning on
// OUS_Jobs::init()'s Action Scheduler bootstrap for the first time
// (it was dead code before) collided with WooCommerce's own bundled
// copy of the exact same library — "Cannot redeclare
// as_enqueue_async_action()" — since both plugins vendor it under the
// same global function names. Fixed with a class_exists('ActionScheduler')
// guard, itself needing a SECOND fix once first written: own-ur-shit's
// own main file loads before woocommerce's (folder-name sort order), so
// checking class_exists() at this file's top-level/file-parse time is
// too early regardless — deferred to 'plugins_loaded' (guaranteed to
// fire only after every active plugin's main file has been read),
// same "don't trust class_exists() before plugins_loaded" principle
// already documented in bh-contest.php's own bootstrap, this time
// actually enforced rather than just cited.
// RUNTIME-VERIFIED end to end on this actual install: booted WordPress
// fresh with WP_DEBUG_LOG on after each fix (caught both the
// Action-Scheduler fatal AND its own too-early-class_exists() follow-on
// bug this way, not by static reading), confirmed every one of the 6
// previously-dead registrations is now genuinely live — shortcode_
// exists('bh_notifications'), the bhcore_send_notification_email AND
// bhcore_ingest_event job handlers present in OUS_Jobs' real handler
// registry, BH_Identity::maybe_issue_cookie and OUS_Toast::
// maybe_set_guest_cookie both confirmed hooked onto 'init' at their
// intended priority via direct $wp_filter inspection, and
// class_exists('ActionScheduler') correctly true (WooCommerce's copy,
// no conflict) — then loaded a real wp-admin screen and the real front
// page with WP_DEBUG_LOG on, zero errors either place.

// 3.4.84 — vendor/fpdf/fpdf.php was committed on its own in the previous
// pass without the font metric files (font/*.json) FPDF's core fonts
// (Helvetica, Times, Courier) actually load at render time — a gap only
// surfaced once bh-courses' new certificate-of-completion feature
// (class-certificates.php) tried to actually render a PDF and hit
// "file_get_contents(.../font/helvetica.json): Failed to open stream"
// live, caught via a temporary WP_DEBUG_LOG flip. Fixed by vendoring the
// four Helvetica metric files (helvetica.json/b/i/bi.json) from the same
// upstream (setasign/fpdf) fpdf.php itself was pulled from — RUNTIME-
// VERIFIED end to end on this install: generated a real single-page PDF
// via BHC_Certificates against a real course/user/completion row, the
// output file identified as a genuine "PDF document, version 1.3."

// 3.4.71 — 2026-07-12 — three more rounds of direct live feedback, all
// addressed in one pass: (1) "bloated, poorly proportioned... good gaps/
// padding/margins" + "all three need to feel cohesive" — the Library
// rail's list rows now reuse .bhy-rail-item/.bhy-rail-subheading VERBATIM
// (the exact classes Live Views' own story-button list already uses in
// the same rail) instead of a parallel bhds-library-item class with
// slightly different numbers; the canvas toolbar/state-strip/Controls
// panel were re-measured against tokens already used elsewhere in this
// rail (7px/14px row padding, 11px uppercase headings) instead of
// inventing a new scale; the background-toggle went from three separately
// -bordered boxes to one connected segmented control; canvas padding/min-
// height reduced; the Controls panel heading now reuses .bhy-controls h3
// verbatim. (2) "This is kinda my dream" — a real Storybook screenshot
// showing NESTED, disclosure-triangle story trees (states nested inside
// their component, not a separate tab strip) and a SOLID PILL selected-
// row highlight, not a left-border tint. Named fixture states are now
// tree rows nested under their Component/Primitive (renderNestedStates()),
// disclosure-triangle expandable, lazily fetched and cached per item; the
// separate state-tab strip above the canvas is GONE (its markup, CSS, and
// renderStateTabs()/loadStates() are removed outright) — the canvas
// toolbar is now purely the light/dark/grid background toggle, matching
// what that position actually is in real Storybook. The solid-pill
// selected style (.bhy-rail-item.active/.bhy-story-btn.active) is applied
// GLOBALLY, so Structure's own tree/Live-Views list picks it up too —
// deliberate, since "cohesive" means the same look everywhere, not just
// in Library. (3) "I wanted already seeded mocked up components to work
// from" / "real seeded data is the point" — BH_Element_Prefab now seeds
// four starter Components on 'init' (idempotent via an option flag AND a
// per-slug existence check, so it never duplicates or clobbers anything
// AJ has since edited): Contest Stat Row, CTA Banner, Section Header,
// Action Button Row — built entirely from this ecosystem's own existing
// primitives, shaped around bh-contest's actual UI. Also hardened
// postApi() the same way api() was hardened last pass (a failed POST/
// DELETE used to silently resolve with a WP_Error body instead of
// rejecting — "New Component" only ever knew "!data.id," never why) and
// switched "New Component"/state save/delete to show the REAL server
// error text instead of a generic alert. No live browser this pass —
// reasoned through against the existing, working rail-tab/pane mechanism
// and the already-verified render_definition()/BH_Element_State code
// paths, brace/syntax + node --checked (all three top-level IIFEs
// individually), but the nested-tree interaction itself has not been
// clicked through live yet.

// 3.4.77 — 2026-07-12 — REAL, LIVE-CONFIRMED BUG FIX: 3.4.76 broke
// admin.php?page=bh-design itself — a logged-in admin got WordPress
// core's own "Sorry, you are not allowed to access this page" wp_die(),
// immediately after this plugin gained a new page. Root cause: class-
// component-studio.php's add_menu() registered its Components list with
// a REAL parent slug (add_submenu_page('bh-design', ...)) — this is a
// known, ALREADY-DOCUMENTED footgun in this exact codebase (see class-
// style-gallery.php's own 3.4.31 changelog note): WordPress implicitly
// pairs a top-level menu's bare slug with its first-registered
// submenu's own capability/callback, and adding another real submenu
// under the same parent can disturb that pairing depending on
// admin_menu hook registration order. Every other page in this plugin
// (BHY_Gallery::add_menu(), BH_Studio::add_menu()) already avoids this
// by registering with parent_slug = null ("hidden, reachable by direct
// link only") instead — I reintroduced a bug class this codebase had
// already fixed once, by not checking for the existing convention
// before adding a new admin_menu registration. Fixed: add_menu() now
// uses the same null-parent pattern; Components is reachable directly
// at wp-admin/edit.php?post_type=bh_component (not yet linked from
// anywhere in the UI — a real follow-up, not done this pass).
//
// 3.4.76 — 2026-07-12 — PAGE-BUILDER-REBUILD-PLAN.md's prototype
// (class-component-studio.php, 3.4.76's own new file, see its docblock
// for the full architecture) extended twice more this same pass, both
// direct, live, mid-build catches from AJ:
// (1) "The playing bar would just be one of the many nested components
// underneath, no?" — correct, and the in-progress "Contest Page"
// Component was about to make the OLD system's exact mistake (copy the
// Now-Playing Bar's markup inline instead of reusing it) wearing new
// clothes. Added a real second block type, bh/component-ref — embeds
// ANOTHER bh_component post's live content by reference (core WordPress's
// own do_blocks(), not a hand-rolled second renderer), with a published-
// only guard and a depth-8 cycle guard. The seed data was restructured
// to match: Contest Header, Category Tabs, and Track List are now three
// MORE independent Components (not folded into one blob), each
// communicating with the others ONLY through plain CustomEvents on
// `document` (bhcb:category, bhcb:play) — no direct references between
// them — and "Contest Page (Full GUI Smoke Test)" is composed ENTIRELY
// of four bh/component-ref blocks pointing at real post ids (Header,
// Tabs, Track List, and the already-seeded Now-Playing Bar), with zero
// HTML/CSS/JS of its own. This is what AJ's own smoke-test bar asked
// for: "I meant the ENTIRE player for the contests, the whole entire GUI
// for that" — genuinely composed of real, independent, reusable pieces,
// not one hand-assembled page.
// (2) "I dont want to be dependent on WP propriety shit... it really
// needs really good abstractions so we can eventually migrate away from
// everything WP dependent." Worth stating the boundary explicitly rather
// than leaving it implicit: every bh/custom-block's actual authored
// content (html/css/js/bindings) is plain, portable data — zero WP-
// specific syntax inside it, trivially exportable to any future system.
// The WP-specific parts (the block-editor authoring UI, post_content's
// block-comment serialization, the wp/v2 REST auto-routes) are confined
// to the AUTHORING SHELL, never leak into what's actually stored per
// Component, and never touch the PUBLIC render path at all (render_
// custom_block()/render_component_ref() output plain HTML/CSS/JS — a
// site visitor's browser never loads wp-block-editor or any other WP
// admin package). This is the concrete rule going forward: if a future
// change ever makes a Component's stored content only make sense INSIDE
// WordPress, that's the one thing to push back on hardest — the editing
// shell is allowed to be WP-shaped; the data isn't.
//
// 3.4.70 — 2026-07-12 — REAL RE-ARCHITECTURE, live-confirmed feedback:
// "they should be the same interface aesthetically... and functionally,
// just swapping between what is there specifically in the rail," plus "the
// old .bhds-library-preview gave the canvas a literal #1a1a1a black
// background" (visible live — this is what actually made Library look like
// a bolted-on second app). The top-level Structure|Library switch, which
// wrapped TWO ENTIRELY SEPARATE panel layouts (#bhds-mode-structure vs
// #bhds-mode-library), is GONE. There is now exactly ONE shell
// (.bhy-unified: rail | canvas | inspector) rendered unconditionally —
// "Library" is a THIRD .bhy-rail-tab inside the SAME .bhy-rail-tabs strip
// that already held Structure/Live Views (render_left_rail()), reusing the
// EXISTING generic rail-tab click handler for tab/pane switching and
// localStorage persistence, with one addition: it now also sets
// data-active-rail on #bhds-app-root and fires a 'bhds:rail-tab'
// CustomEvent. Pure CSS attribute selectors ([data-active-rail="library"])
// swap which of two permanent siblings is visible inside the SAME canvas
// column (.bhy-canvas-structure-pane / .bhy-canvas-library-pane) and the
// SAME inspector column (.bhy-inspector-structure-pane /
// .bhy-inspector-library-pane) — never two different DOM shells. Every
// surface in the Library's own markup (search input, nav list, toolbar,
// state tabs, canvas, Controls panel) now draws from this file's existing
// --bhy-* tokens, the SAME tokens Structure's rail/canvas/inspector already
// use, specifically so the two read as one application; the black canvas
// background is gone (default light, matching --bhy-surface exactly), with
// an OPT-IN light/dark/grid toggle kept as a real, useful preview
// capability that only ever affects the small canvas rectangle. All
// Phase 1-4 JS logic (loadLibrary/selectItem/loadStates/openFixturesEditor/
// instantiatePrefab/etc.) is UNCHANGED — every element id it reads/writes
// is identical to before this pass; only setMode()/.bhds-mode-tabs
// machinery was replaced with selectRailTab()/the rail's own tab system,
// and the old render_library_panel() method (dead code after this
// rearchitecture) was deleted outright rather than left orphaned. Also
// hardened the Library's api() helper (found live: it never threw on a
// well-formed-but-wrong-shape response — a WP_Error IS valid JSON — so a
// real route failure silently looked identical to "the Library has no
// data" instead of surfacing a diagnosable error) to throw a real, logged
// Error on any non-ok or WP_Error-shaped response. No live browser this
// pass beyond the earlier confirmed bug reports — reasoned through against
// the existing, already-working rail-tab/pane mechanism and brace/syntax +
// node --checked (all three top-level IIFEs individually), but the
// re-architecture itself has not been clicked through live yet — please
// verify Structure and Library now genuinely share one shell before
// trusting this fully.

// 3.4.75 — 2026-07-12 — REAL, LIVE-CONFIRMED FIX: "Close, they jump to
// the start, not back one level" (the 3.4.74 breadcrumb/back-button work,
// tested live). Root cause: class-element.php's get_placements() only
// ever cast library_component_id to a real int — id and
// parent_placement_id came back as plain STRINGS (wpdb ARRAY_A over
// MySQL's text protocol), which JSON-encodes as quoted strings. Two
// failures fell out of that in goUpOneLevel()/buildAncestryChain()
// (element-builder.js): a root node's parent_placement_id is the STRING
// "0", which is TRUTHY in JS (only the NUMBER 0 is falsy) — so root
// nodes register as one, but a "0" is a non-empty string; and any id lookup done
// with strict/implicit equality against a mixed string/number pair could
// silently fail. Fixed at the JS call sites (Number(...) normalization,
// same defensive pattern this file's own docblock already uses
// elsewhere) AND at the source: get_placements()/get_placement() now
// also cast id and parent_placement_id to real ints, same treatment
// library_component_id already got, closing this off for every other
// current and future consumer of this array, not just this one call
// site.
//
// 3.4.74 — 2026-07-12 — Component-editing UX rework, AJ's own live
// feedback on the 3.4.73 screenshots: "I find things a bit confusing,
// especially in the drill down on the inspector" -> (asked what
// specifically) "Just cludgy and poorly designed and thought out I
// think, for how the user will need to use it." -> "theres no 'drill
// up' as it were... its not user friendly or at all intuitive to use."
// -> "Really consider human friendly workflow and layout practices."
// Three concrete, structural fixes in assets/js/element-builder.js (this
// plugin's own visual-builder engine, mounted inside class-style-
// gallery.php's rail — no PHP changed this pass):
// (1) ISOLATION — renderCanvas() used to ALWAYS render the entire live
// Site/CRM tree underneath the "Editing Library Component" banner, even
// while sandboxed inside one Component (exactly what the screenshots
// showed: SITE, CRM profile page, project tracker board, all still
// listed while "editing" Contest Stat Row). This whole tool is explicitly
// modeled on Storybook, and Storybook never shows your unrelated app
// routes while isolating one component — this plugin shouldn't either.
// While state.libraryEditing is set, the rail now renders ONLY the
// sandbox surface's own tree; Site and every live surface are skipped
// outright, not just deprioritized.
// (2) REAL "DRILL UP" — the previous "Done" control (the inspector's
// close/dismiss button) hardcoded selectSite() no matter how deep a
// placement was nested, so one click from three levels deep jumped all
// the way to the global Site root (and, worse, while editing a Component
// would have broken the new isolation above entirely, since selectSite()
// doesn't know about libraryEditing). New goUpOneLevel() goes to the
// IMMEDIATE parent (or, for a top-level node, back to the Component-root
// list) — genuine one-level "drill up", renamed "‹ Back" so it reads as
// navigation, not dismissal.
// (3) BREADCRUMB — new buildAncestryChain() + a .bhel-breadcrumb row at
// the top of every placement's inspector: the full clickable path from
// Component root down to (but not including) the selected node, with the
// node's own label trailing as plain "you are here" text — the always-
// visible path back up that was simply missing before, not something you
// had to already know was possible via the tree. See element-builder.css's
// new .bhel-breadcrumb* rules for the visual language (small, underline-
// on-hover links, thin bottom rule — deliberately quiet, not another loud
// banner).
// None of this touches PHP/REST/data shape at all — purely the existing
// selection/navigation JS, reusing state.selection, getPlacementsArray(),
// and the type-label lookups already used elsewhere in this same file.
// Not live-verified — no browser here — but node --checked clean and
// every new function only reads state this file already maintains
// correctly elsewhere; please reload, re-enter editing a Component, and
// confirm the rail is actually isolated and the breadcrumb/back button
// both go where they say they will.
//
// 3.4.73 — 2026-07-12 — REAL, LIVE-CONFIRMED FIX for the 404, found via
// the 3.4.72 diagnostic logging below (which did its job and is now
// removed — both the register_routes() log line and the rest_api_init
// route-table dump confirmed the route WAS correctly registered, ruling
// out opcache/registration entirely). AJ's OUS_DebugLog dump showed the
// actual failing request URL: "POST .../wp-json/ous/v1/elements/elements/
// prefabs" — note "elements/elements/prefabs", doubled. Root cause: cfg.
// restUrl (class-element-builder.php's enqueue_assets(), unchanged) is
// esc_url_raw(rest_url('ous/v1/elements/')) — it ALREADY ends in
// 'elements/' by design, so every OTHER caller of api()/postApi() in this
// codebase correctly passes just the tail (e.g. 'placements/123'). But
// class-style-gallery.php's Library-tab JS (Phase 0-4 of LIBRARY-
// STRUCTURE-HYBRID-DESIGN-PLAN.md, all written across earlier passes)
// called api('elements/types'), api('elements/prefabs'), api('elements/
// states?...'), postApi('elements/prefabs', ...), postApi('elements/
// states...', ...), and one raw fetch(cfg.restUrl + 'elements/preview')
// — every single one prepending 'elements/' a SECOND time on top of what
// restUrl already has. This has apparently been broken since Phase 0 —
// not something this session's edits introduced — it just took a real
// browser + log dump to actually see the doubled path, which no amount
// of re-reading register_routes() in isolation could have caught (that
// method was correct the entire time). Fixed all 8 call sites to drop
// the redundant 'elements/' prefix: 'types', 'prefabs',
// 'prefabs/{id}/preview', 'states?...', 'states', 'states/{id}',
// 'preview'. This should fix "New Component", the Library list actually
// populating (GET elements/types + elements/prefabs were failing the
// exact same way), fixture-state load/save/delete, and the Library
// preview render — all of Phase 0-4 was hitting this same 404 underneath
// whatever else was reported. Please reload and re-test everything in
// the Library tab now that the actual transport bug is gone.
//
// 3.4.72 — 2026-07-12 — TWO items, both AJ's own live reports:
// (1) The confirmed-live "HTTP 404 for elements/prefabs: No route was
// found matching the URL and request method" on "New Component". Every
// file this route touches — class-element-prefab.php's register_routes()
// itself, this file's require-loop (element-prefab IS in the loader
// array) and its 'init'->register_routes() hookup (both present,
// unchanged, correct), class-element-builder.php's enqueue_assets()
// (confirmed it DOES run on the Design Suite page — BHY_Gallery::
// enqueue_widgets_assets() calls it directly on the bh-style/bh-design
// hook, bypassing the OTHER, retired maybe_enqueue() hook-string check
// that only matches the separate standalone Element Builder page; that
// retired gate was my first suspect and is a dead end, ruled out this
// pass) — was re-read line by line and is structurally correct; the
// route registration is byte-identical in shape to every other working
// route in this codebase. Since a genuinely well-formed rest_no_route
// response means PHP completed the request without fataling, the most
// likely real explanation is something outside this file's own logic —
// most plausibly a stale opcode-cache copy of class-element-prefab.php
// still being served after the edit (some hosts run opcache.validate_
// timestamps=0, which won't pick up a changed file until PHP-FPM/Apache
// restarts or opcache is reset). Rather than guess again blind, added
// two TEMP DIAGNOSTIC log lines (OUS_DebugLog, same "check logs" workflow
// AJ already uses): one inside register_routes() itself confirming it
// executed (logs this file's own mtime, so a stale-cache mismatch is
// directly visible), and one on a late (priority 999) rest_api_init hook
// in this file dumping whether '/ous/v1/elements/prefabs' is actually in
// the REST server's final route table for that request. Please reload,
// click "New Component" again, and check OUS_DebugLog's Console for
// "element_prefab_register_routes" and "rest_route_dump" — their
// presence/absence pinpoints exactly which stage is failing. Both are
// safe to delete once this is confirmed fixed.
// (2) Density pass: "for the inspector, I really like the unity
// inspector. Its tight and clean and professional looking, not bloated
// bubble bulky" / "Across the board really, to flabby currently." See
// class-style-gallery.php's own new comment block (render_script(), right
// after the design-system <style> tag) for the full approach — a scoped
// #bhds-app-root override of the existing --bhy-space-N/--bhy-text-*/
// --bhy-radius* tokens (smaller values, same variable names, so every
// existing token-based rule further down the file automatically picks it
// up) plus a handful of targeted !important overrides for the few rules
// that use a hardcoded px value instead of a token. Global --bhy-* tokens
// (class-ui.php) themselves are UNCHANGED — this only affects the Design
// Suite screen, not every other admin page built on the same design
// system. Not live-verified — please reload and confirm this actually
// reads as "Unity Inspector tight" rather than just "smaller."
//
// 3.4.69 — 2026-07-12 — REAL, LIVE-CONFIRMED BUG FIX: "Library doesn't
// even open into anything" (AJ, live on the actual site). Root cause: the
// Library tab's entire inline <script> (class-style-gallery.php's third
// IIFE, everything Phase 0-4 of LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md
// built) is printed directly in the admin page's HTML body, so it runs at
// parse time — but it started with `if (!cfg.restUrl) return;`, reading
// window.bhElementBuilderConfig, which is only defined by a wp_localize_
// script() call attached to the 'bh-element-builder' handle, enqueued with
// in_footer=true. That config script tag doesn't exist in the DOM yet when
// this inline block runs, so cfg.restUrl was ALWAYS undefined at that
// point, and the old code returned immediately — silently skipping
// EVERYTHING below it, including the basic Structure/Library tab-click
// listener, which has nothing to do with REST at all. Net effect: clicking
// "Library" did visibly nothing, with no console error, and (since nothing
// past that guard ever ran) every Phase 1-4 feature built on top of it was
// unreachable too — this single ordering bug is almost certainly why "none
// of it" appeared to work. Fix: the real work now lives in a named init()
// function, called on 'DOMContentLoaded' (footer scripts execute
// synchronously during parsing, strictly before that event fires, so
// bhElementBuilderConfig is guaranteed to exist by the time init() runs),
// with an immediate-call fallback for the rare case this script itself
// loads after DOMContentLoaded already fired. Also added a console.error
// if cfg.restUrl is STILL missing after that, instead of a silent no-op,
// so a genuinely different failure (bh-element-builder not enqueued at
// all) is now visible instead of looking identical to this bug. This is a
// real, confirmed-live fix, not a reasoned-through one — but the
// downstream Phase 1-4 features it unblocks are still only brace/syntax-
// checked, never executed; please re-verify those now that they can
// actually run.

// 3.4.68 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 4:
// linked instances, AJ's confirmed scope of leaf-value overrides only (no
// per-instance structural changes — anything beyond attrs/style requires
// editing the master Component or detaching). New bhcore_element_placements
// column library_component_id (class-identity-activator.php DB_VERSION
// 1.12): 0 = an ordinary placement (every pre-existing row, unchanged
// behavior); non-zero = a linked instance — ONE row whose 'config' is
// repurposed as an index => {attrs, style} leaf-override map, no real child
// placement rows, structure entirely virtual. BH_Element::render_placement()
// gained a branch (checked BEFORE its own get_type() lookup) that delegates
// straight to the new BH_Element_Prefab::render_linked_instance(), which
// re-reads the master's CURRENT definition on every render (render_
// definition() gained an $overrides param, same shallow attrs/style merge
// used everywhere overrides are applied) — publishing an edit to the master
// updates every linked instance with zero action needed anywhere else, by
// construction. New BH_Element_Prefab methods: instantiate_linked() (the
// single-row linked insert), detach_instance() (folds overrides into a copy
// of the master's definition, materializes it as real child rows under the
// SAME placement id via the existing unmodified instantiate() routed through
// a throwaway scratch prefab, then flips that row to an ordinary
// library_component_id=0 bh/container). New REST routes: POST .../
// prefabs/{id}/instantiate gained an optional "linked":true body flag
// (default/omitted keeps Phase 3's detached-copy behavior unchanged); POST
// .../placements/{id}/overrides (replace the whole override map) and POST
// .../placements/{id}/detach are new. element-builder.js: the Components
// palette section now offers "Insert linked" (default, primary) alongside
// "Insert a copy"; the tree shows a 🔗 badge + the master's name on any
// linked-instance node; a dedicated inspector (renderLinkedInstanceInspector())
// replaces the normal Element/Style/Data sections for one — master name,
// "Detach from Library," and a deliberately minimal per-node JSON overrides
// editor (one row per master definition entry), matching the same
// flat/functional-over-polished posture the Library tab's own fixtures
// editor (Phase 2) already established. No live browser/DB this pass —
// reasoned through against the already-working instantiate()/render_
// definition() code paths and brace/syntax + node --checked, but not
// executed. Please verify, in this order, against a real install: place a
// linked instance, confirm it renders the master; edit an override and
// confirm it applies without touching the master; publish an edit to the
// master from the Library tab and confirm the linked instance updates with
// no action taken on it directly; detach it and confirm it becomes a real,
// independent, freely-divergent subtree with the override baked in.

// 3.4.67 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 3:
// the add-child picker (element-builder.js) turns out to already have been
// the Library, largely built in an earlier pass as the "Prefabs" palette
// section — instantiatePrefab() already gives exactly the detached-copy
// semantics §5.3/Phase 3 calls for (a fresh, independent set of placement
// rows every insert, editing the copy never touches the saved Component).
// Renamed that section's header "Prefabs" -> "Components" for terminology
// consistency with the Library tab (no schema/route change — the table is
// still literally bhcore_element_prefabs). The one real capability gap
// this pass closes: capability intersection (§5.3's own explicit call) —
// renderPrefabSection() now filters state.prefabs against the target
// surface's own admitted-types manifest (state.typesBySurface[surface],
// the SAME per-surface manifest the plain-type list already filters
// against) and only offers a Component whose every contained element type
// is actually admitted there; a Component that would place a
// surface-inadmissible type is silently withheld rather than offered and
// failing later. Deliberately stops here — Phase 4 (linked instances) is
// explicitly gated in the design doc's own §9 open-question #1 on AJ
// confirming the override boundary first, so it is not started
// unprompted. No live browser this pass — reasoned through against the
// existing, already-working typesBySurface/prefabs data on hand and
// node --checked, not executed.

// 3.4.66 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 2:
// named fixture states — the Storybook Default/Empty/Viral-style variant
// tabs, per AJ's own "fixture/mock data per state" answer. New table
// bhcore_element_states (class-identity-activator.php DB_VERSION 1.11) and
// a new BH_Element_State class (class-element-state.php) hold them — one
// shared table for both a Library Component (owner_kind 'component',
// owner_key its prefab id) and a code-registered Primitive type (owner_kind
// 'type', owner_key its type slug), per the design doc's own §4.2 call.
// register_type() gained an optional 'states' manifest key so a type's
// author can ship default states inline; BH_Element::
// maybe_seed_default_states() lazily inserts any that don't already exist
// the first time a type's states are actually requested, and never
// overwrites a row someone has since edited by hand. BH_Element_Data::
// resolve() (added ahead of this bump, already brace-checked) now honors a
// '__fixtures' map on $ctx before ever touching a real data source — the
// ONLY place fixture mode plugs into resolution, so the exact same binding
// descriptor is genuinely portable between a Library preview and a live
// Structure render, per "binding ubiquitous across both tabs." The Library
// tab's own script (class-style-gallery.php) gained a Storybook-style state
// tab strip above the preview (one tab per named state, "+ State" to add
// one, click the active tab again to edit/delete it) and a deliberately
// minimal flat fixtures editor (source-slug => mock-value rows) — not a
// rebuild of the Structure tab's full per-binding inspector, which is a
// reasonable later refinement, not a blocker here. Component previews pass
// the active state via a new ?state_id= param on GET .../prefabs/{id}/
// preview; Primitive previews pass the state's fixtures inline as
// ctx.__fixtures on the existing POST .../elements/preview route — no new
// preview route needed for either case. No live browser/DB this pass —
// every route and the JS wiring is reasoned through against the already-
// working prefab-preview and add-child-picker preview code paths and
// brace/syntax-checked (including node --check on the extracted IIFE), but
// not executed. Please verify: create a state, confirm its fixtures render
// in the preview, edit and re-select it, delete it, and confirm a type's
// code-declared 'states' manifest entries actually get seeded on first
// request, against a real install before relying on this.

// 3.4.65 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 1:
// the Library tab stops being read-only. "New Component" and "Edit this
// Component" now open a real authoring session — a new internal
// '__library' sandbox surface (class-element.php's register_library_
// surface(), excluded from the ordinary Structure boot-load and Preview-
// surface list via a new 'internal' surface flag) reuses the EXISTING
// tree/inspector/add-child/reorder/save machinery unchanged, just pointed
// at (surface='__library', context_id=that Component's own id) instead of
// a live page — per the design doc's own "one editor, two modes"
// decision, this is a bridge (window.bhElementLibrary in element-
// builder.js: enterEdit/exitEdit/publish), not a second editor. Editing
// an existing Component hydrates its sandbox from the currently-published
// definition the first time (via the existing prefab instantiate route,
// now also usable against the sandbox), and "Publish" snapshots the
// sandbox back into the real Component via a new nested-aware
// definition_from_slot() helper (class-element-prefab.php) — a real
// capability fix over the old save_from_slot(), which silently dropped
// nested children; both a Component's root slot supporting more than one
// top-level sibling and genuine parent/child nesting now round-trip
// correctly. rest_update() gained a surface+slot re-derive mode alongside
// its existing raw-definition mode. No live browser this pass — the
// hydrate/publish round trip is reasoned through against the actual
// shapes of every route involved but not executed; worth an early,
// careful click-through once there's a real screen to check it against.

// 3.4.64 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 0:
// the first real slice of the Library/Structure rebuild AJ asked for. A
// top-level "Library | Structure" tab switch now sits above the Design
// Suite shell (class-style-gallery.php), localStorage-persisted
// (bhdsActiveMode). Structure is exactly today's tree/canvas/inspector,
// completely unchanged — Phase 0's own scope is explicit that nothing
// about the existing tree wiring should move. Library is new: a read-
// only, Storybook-shaped two-column browser — Primitives (every
// registered element type, via the existing GET /elements/types) and
// Components (every saved prefab, via GET /elements/prefabs) grouped by
// category in a left sidebar, an isolated preview on the right. Selecting
// a Primitive POSTs a never-persisted (id:0) placement to the existing
// /elements/preview route, same mechanism the add-child picker's own
// preview already uses; selecting a Component hits a genuinely new, small
// route — GET /elements/prefabs/{id}/preview (class-element-prefab.php)
// — a thin REST wrapper around render_definition(), which already
// renders a prefab's tree with zero DB writes for the Gutenberg embed
// block. No authoring, no fixture states, no linked instances yet — those
// are Phases 1/2/4, sequenced deliberately after this shell is confirmed
// to feel right on a real screen (this pass, like every recent one, had
// no live browser to verify against).

// 3.4.63 — 2026-07-12 — AJ's own ask: "delete individual logs, hide or
// mute specific log codes... like Visual Studio" for the Console & Logs
// section (OUS_DebugLog). This schema has no discrete error-code field
// (levels are only error/warning/info, by design), so the practical
// equivalent of "mute this diagnostic" is muting by the exact (source,
// message) a row actually has — read server-side from the row being
// muted, never trusted from a round-tripped form field. Two new actions
// (delete_log_row, mute_log_signature/unmute_log_signature), a small
// options-stored mute list (never touches the log table's own rows —
// muted entries are still logged, only hidden from the default view), a
// "Muted (N)" panel with per-entry Unmute, and a "show muted rows" toggle
// so muting is always visibly reversible, never a silent, permanent
// vanish. build_filters() applies the mute exclusion by default; every
// existing filter (level/source/user/date/request) is untouched.

// 3.4.62 — 2026-07-12 — AJ's own explicit visual reference: storybook.js's
// Controls panel (a fixed Name column, one clean row per property, thin
// row dividers, no per-field label-above-input stacking) — NOT a request
// to embed Storybook's actual runtime/build step, which would conflict
// directly with this ecosystem's "no build pipeline assumed, runs on
// ordinary shared hosting" standing architecture. Scoped as a pure CSS/
// markup pass on the inspector's Style — Advanced property rows and the
// Custom class/CSS rows (element-builder.js's renderStylePropertyField()
// now wraps its select/color-popup/custom-input together in one
// .bhel-field-controls container so the grid table works even for a
// property with more than one control; element-builder.css's new
// ".bhel-style-group-body > div.bhel-field-row" grid rules do the actual
// visual work). Deliberately excludes the Custom JS section's checkbox
// confirmation row (a <label>, not a <div>, and not a Name/Control pair)
// so it isn't forced into a layout that doesn't fit it. First slice of
// what's realistically a longer polish pass (the rail tree's row/icon
// treatment is the next natural target) — not attempting the whole
// ecosystem's admin chrome in one edit.

// 3.4.61 — 2026-07-12 — two fixes/completions picked up after a real
// site-down incident:
//
// (1) THE FATAL: class-ui.php's admin_page_css() returns one long plain
// single-quoted PHP string (not a heredoc). Two comments added in 3.4.60's
// own contain:layout fix contained unescaped apostrophes ("story's",
// "bh-contest's") — exactly the recurring "unescaped apostrophe silently
// terminates a long single-quoted string" bug class this ecosystem has
// hit before (see VISION.md). Everything after the second apostrophe was
// parsed as raw PHP, producing a fatal on every page load. Escaped both;
// confirmed with a purpose-built PHP-lexing brace/string checker that the
// whole file (and every other file touched in that edit batch) is
// balanced again. NOT the Query Monitor integration — that class
// hierarchy was re-checked and is fine.
//
// (2) Finished wiring AJ's "pick up where you left off" UI-state
// persistence, which was left half-declared (helpers written, never
// called) when the crash above interrupted it: class-style-gallery.php's
// render_script() now actually calls bhyPersistDetails() on both
// foldable rail <details> sections (Live View outline, Site tree), and
// persists/restores which rail tab (Structure vs Live Views) and which
// Live View story was last active. element-builder.js's inspector groups
// (each dynamic Style — Advanced property group, Custom class/CSS,
// Custom JS) now call the same shared bhyPersistDetails() helper too, so
// fold state survives a reload there as well — AJ's explicit follow-up
// ask ("inspector state should be considered too").

// 3.4.60 — 2026-07-12 — two live-confirmed fixes, straight off AJ's own
// screenshot: "Live View tree isnt showing the selected tree."
//
// (1) Real bug: TWO separate click listeners were bound to the same
// .bhy-story-btn buttons — one (registered first) dispatched
// 'bhel:select-surface' to sync the tree/outline, one (registered
// second) toggled which .bhy-story-frame carried the 'active' class.
// Listeners on the same element/event fire in registration order, so
// the sync dispatch fired and rebuilt element-builder.js's outline
// BEFORE the active class had actually moved — renderDemoOutline()
// reads '.bhy-story-frame.active' directly, so it was always one click
// behind, showing the PREVIOUS surface's markup over the NEW surface's
// canvas (exactly the screenshot: contest player on screen, CRM profile
// markup in the outline). Merged into one handler, active-class-toggle
// first, dispatch second; the now-redundant second listener is removed.
//
// (2) "its not folded into the other thing yet either" — the Live View
// section (render_left_rail()) was a fixed, always-open box while every
// other grouped section in this app (.bhel-style-group, the token
// groups on this same rail) is a real <details>/<summary> disclosure.
// Switched to match — open by default, same visibility behavior as
// before, but genuinely foldable now like everything else.

// 3.4.59 — 2026-07-12 — AJ's own ask, folded into the bh-contest
// conversion work rather than deferred as a separate pass: "is there a
// way to... litterally do it all via the builder instead of hard coded
// files" for JS specifically, plus "easy ways to wire up UI events to
// actions... 'On click' could trigger UI and server side stuff via
// fetch." Two genuinely different features, two genuinely different
// trust levels:
//
// (1) "On click" ACTIONS (p.config.actions, any placement) — a plain,
// codeless list builder in the inspector (element-builder.js's new
// renderActionsSection()): trigger (click/mouseenter/mouseleave/submit)
// + kind (toggle a CSS class / call a URL via fetch / navigate to a
// URL) + that kind's own params. class-element.php's new
// build_actions_js() maps each entry to a small, FIXED, reviewed JS
// snippet server-side — never raw script — so this needs no capability
// gate; anyone who can edit a placement at all can wire one up.
//
// (2) Custom JS (p.config.custom_js) — real, raw JavaScript, rendered
// scoped to one placement's own DOM element (wrap_placement_html()).
// This one IS dangerous (arbitrary code on the live site for every
// visitor), so it's gated for real: a new administrator-only capability
// (OUS_Roles::DEFAULT_CAPS['bhcore_author_custom_js']), enforced at
// save_placement() — the ONE write path every caller (REST, Debug
// Tools, prefabs) funnels through, not just checked in the GUI — plus a
// client-side "I understand this runs unreviewed" confirmation checkbox
// before the field is even usable. Explicitly NOT the same ask as "new
// PHP via the interface," which stays a hard no — arbitrary server-side
// code from an admin text field is how a site gets owned; the
// underlying need for real server-side data is what BH_Element_Data's
// existing registered-source system (register_source(), a real API
// call, not text) already serves — see bh-contest 3.2.0's own new
// bh_contest.vote_count/track_count/days_remaining sources for a live
// example of that same session's work.

// 3.4.58 — 2026-07-12 — AJ's own ask, framed as core debug-tooling work
// deliberately done BEFORE the bh-contest conversion starts (not after):
// "good use of Query Monitor where needed." New includes/class-qm-
// integration.php registers a real QM_Collector + QM_Output pair — Query
// Monitor's own admin-toolbar panel now gets an "Own Ur Shit" tab
// showing THIS request's own OUS_DebugLog entries (errors/warnings/info,
// same fields Debug Tools' Console & Logs table already shows), so
// triaging a bug while actively building bh-contest's real surface
// doesn't mean bouncing between QM and a separate admin screen. Backed
// by a new zero-extra-query in-memory buffer (class-debug-log.php's
// request_buffer(), appended to at the end of the existing log() method)
// — entirely additive, no change to what already gets logged or how.
// Fully optional/degrading: every QM-facing class here is itself guarded
// by class_exists('QM_Collector')/class_exists('QM_Output_Html'), and the
// filters this hooks only ever fire if Query Monitor is actually active,
// so an install without QM installed is completely unaffected.

// 3.4.57 — 2026-07-12 — direct UX follow-up: "move the Live view tree up
// so you don't have to scroll to the bottom just to edit the thing you
// want, and make it not shitty looking." The Live View outline section
// (#bhy-rail-demo-outline-section) previously sat BELOW the real Site
// tree in the Structure rail pane — a real problem since the Site tree is
// a permanent, often-long fixture, meaning reaching a Live View's outline
// meant scrolling past all of it first. Reordered above it instead
// (class-style-gallery.php's render_left_rail()). Also gave it real
// visual chrome it never had — a tinted card with a left accent bar (same
// visual language .bhy-rail-item.active already uses for "selected"),
// its own scroll region capped at 280px so a big Live View's outline
// doesn't itself force the whole rail to scroll, and cleaner row styling
// on the outline rows/labels themselves (assets/css/element-builder.css).

// 3.4.56 — 2026-07-12 — three more same-session follow-ups on the demo
// outline/style feature, in order:
//
// (1) "add arbitrary class names and custom CSS to things as needed" —
// both the session-only demo-element style panel AND (the real, persisted
// version) the real placement's own "Style — Advanced" section
// (renderStyleAdvancedSection()) gained an "Extra CSS class(es)" +
// "Custom CSS" pair. For real placements this round-trips through
// p.config.style.custom_class/custom_css exactly like every other style
// field — class-element.php's wrap_placement_html() now reads both at
// render time (appended onto the class="..." attribute build_html_attrs()
// already builds, and onto whatever BHY_Style::scoped_inline_style()
// resolved), so it applies on the real front-end too, not just the live
// preview.
//
// (2)/(3) a genuine overcorrection, caught immediately by AJ ("Dipshit,
// the styles still stay in the inspector, the tree just gets naturally
// folded into the rail like the other shit"): an earlier edit this same
// pass moved BOTH the read-only outline tree AND its style panel into the
// left rail. That was wrong — only the TREE belongs in the rail (same as
// every other tree in this app), the STYLE PANEL for whatever's selected
// stays in the inspector, same as a real placement's style controls
// already do. Reverted the style-panel relocation; #bhy-rail-demo-style-
// mount is gone, renderDemoOutline() builds the tree into the rail only
// and keeps appending the style panel to inspectorEl as before.

// 3.4.55 — 2026-07-12 — live-confirmed fix, straight off AJ's own
// screenshot right after 3.4.54 shipped: "styles are not doing their
// thing" — the canvas was rendering fully unstyled (black bg, default
// font, overlapping text). Root cause: TWO CSS selectors this whole
// gallery depends on only make sense inside a real Document, and 3.4.54
// swapped every canvas story from a real iframe document to a shadow
// root, which has neither a root element nor a <body> element:
//   (1) BHY_Style::inline_css() prints `:root{--bh-bg:...}` — inside a
//       shadow root, `:root` matches nothing, so every `var(--bh-*)`
//       reference in every surface's own CSS silently resolved to
//       nothing. Fixed by rewriting `:root` -> `:host` on the #bhy-vars
//       tag right after it's parsed in (render_script()'s shadow-attach
//       code), and the same rewrite in refreshAllFrames()'s live-edit
//       path so later token edits don't regress this.
//   (2) preview_doc()'s own `body{margin:0;background:var(--bh-bg);...}`
//       rule matches nothing either — only body's CHILDREN get moved
//       into the shadow root, never a real <body> element for that
//       selector to match. Changed to `:host{...}` (the shadow-DOM
//       equivalent of "the box everything sits inside" — the frame div
//       itself, which the moved children then fill exactly like a real
//       <body> would).
// Both are real regressions from 3.4.54, not new work — caught and fixed
// within the same session rather than left for the next screenshot.

// 3.4.54 — 2026-07-12 — two more direct follow-ups on the same demo-
// outline feature from 3.4.53, both same-session:
//
// (1) "the read only tree should be for structure of the thing only, we
// still need to edit the styles of each thing" — renderDemoOutline()
// (element-builder.js) keeps the outline tree itself read-only/structure-
// only (confirmed correct), but clicking a row now also opens a style
// panel for that exact element (background/text color, padding, border-
// radius, font size), writing LIVE, SESSION-ONLY inline styles directly
// to that DOM node. Explicitly not persisted — these demo mockups have no
// backing placement row to save to; the panel says so plainly rather than
// pretending to save. A real persisted version of this is the same
// "convert this mockup into a real BH_Element surface" migration CRM/LMS
// lessons already got, not a quiet half-build here.
//
// (2) "never use iframes unless we have to or it really is the ideal" —
// direct answer to this same pass's earlier iframe-isolation question,
// and AJ's explicit call once given the Shadow-DOM alternative: canvas
// stories are no longer <iframe srcdoc>, they're same-document divs with
// a real attachShadow({mode:'open'}) root (class-style-gallery.php's
// render_canvas()/render_script(), element-builder.js's renderDemoOutline()/
// applyLivePreview()). Shadow DOM keeps the actual thing iframes were
// bought for here — a surface's own stylesheet never bleeding onto
// wp-admin chrome, and vice versa — without a second document/contentDocument
// boundary, which was the direct cause of several sync bugs fixed earlier
// this session. Every `.contentDocument` read is now `.shadowRoot`; a
// ShadowRoot has no `.body`, so renderDemoOutline()'s single-root walk is
// now a flat multi-child walk instead (see that function's own comment).
// Real, disclosed limitation: a `<script>` tag inside a surface's markup
// would NOT execute inside a shadow root — confirmed as a non-issue by
// checking every current `bhy_style_surfaces`/`bh_element_surfaces`
// registration, all of which are static server-rendered HTML with no
// inline scripts.

// 3.4.53 — 2026-07-12 — two pieces, both direct live-feedback follow-ups
// on the SAME screenshot: "still not doing what it's supposed to"
// (picking a demo-only Live View left the inspector showing a stale,
// unrelated CRM placement), then "can we still have 'trees' for the
// plugin live views?" once the first fix explained there's genuinely no
// editable tree for a hand-authored mockup.
//
// (1) element-builder.js's 'bhel:select-surface' listener now sets a new
// state.selection.type === 'demo' when the clicked story's surface slug
// ISN'T a real registered BH_Element surface (was previously a silent
// no-op, leaving stale content on screen) — renderInspector() gained a
// matching branch that clearly explains what's being shown and why
// there's nothing to edit, instead of just looking broken/unresponsive.
//
// (2) new renderDemoOutline() — since these mockups have no real
// placement/tree DATA, this builds a genuinely useful substitute: a
// READ-ONLY outline tree parsed straight from the canvas iframe's actual
// DOM (tag/id/class per row), click-to-scroll+highlight the matching
// element in the canvas. class-style-gallery.php's preview_doc() gained
// one small injected <style> (.bhel-outline-highlight) INSIDE each
// iframe's own document for the highlight to be visible at all — this
// page's own CSS can never reach inside an iframe, by design (see the
// iframe-isolation reasoning flagged directly to AJ this same pass, in
// response to "is this shit really using iframes?" — yes, deliberately,
// for real style isolation between this admin page and N different
// plugins' own real front-end stylesheets; the real cost of that choice
// is exactly this class of extra cross-document plumbing).

// 3.4.52 — 2026-07-12 — direct response to AJ's own "let's be smart
// about tests" ask, right after a run of THREE real bugs in the
// BH_Element/Design Suite canvas layer were each only caught by a live
// screenshot round-trip tonight (the empty-slot wrapper, the doubled
// REST preview path, the surface-key mismatch). New class-element-test-
// suite.php (BH_Element_TestSuite) — same "runs from Debug Tools, no
// CLI/PHPUnit needed" pattern every other *_TestSuite class here already
// uses — adds regression coverage for the two of those three bugs that
// ARE testable from a pure PHP assertion (render_slot()'s empty-slot
// wrapper; the color-token schema's colorTokens values being real CSS
// vars, not bare names — the shape the new swatch dropdown depends on).
// The REST-path bug and the tree/canvas DOM-sync bug are explicitly
// documented in that file's own trailing comment as NOT coverable this
// way — pure client-side JS string-building and live-DOM coordination,
// respectively, need a real browser pass, not a server-side assertion.
// Wired into own-ur-shit.php's existing require/init pattern identically
// to every other test suite. No DB fixtures needed for any assertion in
// this suite — every one runs against either an unregistered
// surface/context id (guaranteed empty, no cleanup needed) or the
// framework's own always-present style schema.
// 3.4.51 — 2026-07-12 — direct response to live feedback on the rail's
// "Preview" tab: (1) renamed to "Live Views" — a bad name for what it
// actually does (class-style-gallery.php's render_left_rail()). (2) Real
// bug, live-confirmed: clicking a Live View correctly swapped the
// canvas, jumped the rail back to Structure, but left the inspector
// showing whatever placement was selected before — completely unrelated
// to what the canvas now showed. The tree-to-canvas sync
// (element-builder.js's fireSelectionEvent(), 3.4.38) was always
// one-way; nothing sent selection the OTHER direction. New
// 'bhel:select-surface' CustomEvent: the story-button click handler
// (class-style-gallery.php) now dispatches it, and element-builder.js
// listens and calls its own existing selectSurface() — for any REAL
// registered BH_Element surface only; a hand-authored demo-only mockup
// (bh-contest, bh-streaming, etc.) has no tree node to select, so those
// are a disclosed no-op. See both files' own updated comments at the
// respective call sites.
// 3.4.50 — 2026-07-12 — two pieces, both direct responses to AJ's own
// ask: "would be cool if the color and font selectors could preview what
// they look like... with like swatches in the dropdown next to the
// option or something."
//
// (1) DRY pass on 3.4.49's own live-preview wiring, done immediately
// after it shipped rather than left to drift: element-builder.js had
// ~20 separate call sites all pasting the identical
// `state.dirtyKeys[dirtyKey(loc)] = true; schedulePreviewUpdate(loc);`
// pair. New `markLocDirty(loc)` (right next to `dirtyKey()`) names that
// sequence once; every call site now just calls it. `markDirty()` itself
// simplified to call it too instead of duplicating the same two lines a
// third way.
//
// (2) Real swatch/font previews. Per-placement color style fields
// (`renderStylePropertyField()`'s 'token-only' branch) now render a real
// custom dropdown (new `buildColorTokenPopup()`) with an actual color
// swatch next to each token name — a native `<select><option>` can't
// show a background-color swatch (browsers ignore it), so this is a
// small custom popup layered on top of the EXISTING, untouched native
// `<select>` (kept as the real source of truth, just visually hidden —
// the popup only ever sets `select.value` and dispatches a real 'change'
// event, so 100% of the actual write-back/dirty-marking/live-preview
// logic is still the one pre-existing handler, never duplicated).
// `BHY_Style::style_schema_for_js()`'s `colorTokens` map now returns
// each token's REAL CSS custom property name (`--bh-accent` etc.)
// instead of just echoing the token's own name back — nothing
// previously read those values (every consumer only iterated keys), so
// this is a safe, non-breaking payload shape change, and it's what lets
// a swatch literally be `background: var(--bh-accent)`. Font selectors
// (a separate, site-level Global Styles control, `BHY_UI::font_field()`)
// got a much simpler fix: each `<option>` DOES support inline
// font-family styling in every real browser, so it just needed one
// added `style="font-family:'Name', sans-serif;"` per option — the
// actual webfont files are now also enqueued on the admin page itself
// (`BHY_Gallery::enqueue_media()`), not just inside the canvas iframes
// as before, or the font-family style would have had nothing real to
// render. `node --check` clean on the JS; every touched PHP file brace-
// balance-checked. NOT runtime-verified — no live browser available
// this pass; needs a restart + hard refresh + an actual look at both
// dropdowns before trusting the swatches/fonts render correctly.
// 3.4.49 — 2026-07-12 — real bug fix, live-confirmed via screenshot: an
// edited-but-unsaved field (a Note placement's text, typed but not yet
// "Save all changes"-d) showed NOTHING in the canvas — not stale, not
// slow, genuinely never wired up at all. `POST /elements/preview`
// (`BH_Element::rest_preview()`) already existed for exactly this — its
// own docblock says so — but was dead code, never called from
// element-builder.js. Fixed: every field-edit path that marks a
// placement dirty (`markDirty()`, plus every other direct
// `dirtyKeys[...] = true` write site — bind toggles, style tokens, the
// Style-Advanced property groups, custom-value inputs, the Enabled
// checkbox) now also calls a new debounced (400ms) `schedulePreviewUpdate()`,
// which POSTs the placement's current in-memory config to `/elements/
// preview` and patches the result into whichever `.bhy-story-frame`
// iframe matches the surface — a SAVED placement's existing DOM node is
// found by `data-placement-id` and outerHTML-replaced; a brand-new
// unsaved one (no existing DOM node yet) is appended into its slot's
// `.bh-element-slot` container and tagged with a synthetic
// `data-bhel-preview-key` so the NEXT edit finds and replaces that same
// temp node instead of duplicating it. Best-effort UX sugar only — a
// failed preview call never blocks editing or the real save path. See
// `markDirty()`'s own updated comment in element-builder.js for the full
// mechanics and the one disclosed v1 edge case (only the currently-
// selected unsaved sibling gets a tracked preview key).
//
// SAME PASS, second fix, found by actually trying the above and getting
// another live screenshot showing still-nothing: BH_Element::render_slot()
// used to `return ''` immediately for any slot with zero saved
// placements — no wrapper `.bh-element-slot` div at all. The new live-
// preview JS anchors on that wrapper to insert an unsaved node, so the
// single most common real-world case (a brand-new page's first-ever
// edit, before anything has been saved once) had no wrapper to insert
// into — the append silently no-op'd. Fixed: render_slot() now always
// emits its wrapper div, empty or not; every existing call site already
// echoes its return value unconditionally (never branches on
// truthiness), so this is safe with zero caller changes. See that
// method's own updated docblock.
//
// SAME PASS, third fix, found by trying the above TWICE and still
// getting a live screenshot of nothing happening: the new preview call
// itself was hitting a 404, silently swallowed by its own catch(){}.
// `cfg.restUrl` (class-element-builder.php's wp_localize_script() call)
// already ends in '.../ous/v1/elements/' — every other api() call in
// this file passes a bare path with no leading slash and no 'elements/'
// prefix (api('surfaces'), api('site-tokens'), and a PRE-EXISTING
// api('preview', ...) bind-field-preview call elsewhere in this exact
// file that already got it right). The new live-preview code instead
// called api('/elements/preview', ...) — a leading slash plus a
// redundant 'elements/' segment, producing a doubled, 404ing URL. Fixed
// to match the file's own established convention. `node --check` clean;
// PHP brace-balance-checked.
// 3.4.48 — 2026-07-12 — real bug fix, live-confirmed via screenshot:
// clicking a tree node (CRM's Project Tracker board, in the reported
// case) never updated the Design Suite canvas. Root cause: the canvas's
// "Preview surface" stories only ever existed for surfaces a plugin
// separately hand-registered into `bhy_style_surfaces` under ITS OWN,
// DIFFERENT key (e.g. bh-crm's class-style-surface.php registers
// 'bh-crm-profile-live', not the real surface slug 'bh_crm_profile').
// element-builder.js's tree-selection sync always fires the REAL slug —
// it could only ever coincidentally match the one surface whose default-
// active story happened to still be on screen; every other surface
// (bhcrm_project_board, bh_courses_lesson, the portal/dashboard
// surfaces) had NO matching story at all, so the click silently did
// nothing. Fix: new BH_Element::render_surface_preview($slug, $context_id)
// (class-element.php) generically renders ANY registered surface's real
// slot content by looping its own declared slots; class-style-gallery.php's
// render_shell() now auto-fills a canvas story for EVERY BH_Element
// surface, keyed by its real slug, for any surface that doesn't already
// have a hand-authored one at that exact key — so this now works for
// every current and future surface without per-plugin registration.
// bh-crm's own class-style-surface.php mirror is now redundant but left
// in place (harmless, renders under its own separate key). Standing
// caveat: reasoning/brace-balance-checked only, not yet re-verified live
// after this fix — needs a PHP restart + hard refresh to confirm the
// Project Tracker board (and every other non-CRM surface) now actually
// switches the canvas on click.
//
// Same pass, second fix — direct response to AJ's own framing: "everything
// is custom and not preregistered unless it's from a plugin." Before this,
// the built-in palette was only bh/note + bh/container; register_type()
// register_generic_primitives() (class-element.php) adds four more true,
// code-free primitives every Wix/Webflow/HubSpot-style builder ships
// intrinsically — bh/heading (tag-picker doubles as h1-h6 level choice),
// bh/image, bh/button (a/button tag choice, same mechanism bh/stat-card
// already uses for div/a), and bh/divider (hr). register_type() itself
// isn't going anywhere — a real DATA-BOUND widget (bh/stat-card, bh-crm's
// bh/sticky-card) legitimately still needs PHP behind it — but the base
// palette no longer forces a detour through plugin code for a plain
// heading or button. wrap_placement_html() also gained real void-element
// handling (hr/br/img/input/meta/link render with no closing tag) rather
// than relying on browser leniency for the new bh/divider.
// 3.4.47 — 2026-07-12 — "no special-cased pages," applied to Gutenberg
// (the two named highest-risk/last-in-sequence items being LMS lessons
// and Gutenberg — this ships the lower-risk of Gutenberg's own two
// options: "a custom block that hosts a node subtree," NOT "replacing
// block-editor authoring entirely," which stays out of scope). New
// class-gutenberg-block.php (OUS_Gutenberg_Block) registers one dynamic
// block, 'own-ur-shit/element-prefab' — an author picks an existing
// saved BH_Element_Prefab from a dropdown (assets/js/element-prefab-
// block.js, plain ES5-safe JS against WP core's own wp.blocks/
// wp.element globals, no build step), and its render_callback renders
// that prefab's tree live wherever the block sits in a post, for every
// real visitor, using the viewing visitor's own user_id as render
// context (not an admin's).
//
// class-element-prefab.php gained render_definition() — a READ-ONLY,
// zero-database-write render of a prefab's definition, the direct
// opposite of the existing instantiate() (which always persists new
// rows; calling that on every page view would leak a row per view).
// Negative in-memory-only fake ids are used so a 'live'-flagged type's
// data-bhel-live marker can never collide with (or be confused for) a
// real placement id — §3.2's resolve REST route just 404s harmlessly
// for a negative id instead of risking a coincidental collision.
//
// class-element.php's render_placement() gained one small, backward-
// compatible addition to support this: a placement array MAY now carry
// an inline 'content_tree' key (checked before the existing
// content_context_id DB lookup) — real DB-backed placements never have
// this key, so every existing call site (render_slot(), the builder
// GUI) is a no-op here; only render_definition()'s in-memory fake rows
// use it, for a container entry's snapshotted inner content.
//
// 3.4.46 — 2026-07-12 — DESIGN-SUITE-UNIFICATION-PLAN.md §3.2 v1: data-
// binding "runtime re-resolution + output formatters," Phase 5 of §4's
// build order. class-element-data.php gained register_formatter()/
// registered_formatters() (zero-central, mirrors register_source()
// exactly) and resolve() now applies an optional 'format' key inside a
// binding's 'bind' object after a successful resolve — one more rung on
// the existing never-fatal fallback ladder, not a new failure mode. One
// first-party formatter shipped: 'compact_number' (1204 -> "1.2k").
// class-element.php gained register_type()'s new 'live' manifest flag,
// a data-bhel-live="1" wrapper marker (wrap_placement_html()), and
// POST ous/v1/elements/resolve (rest_resolve()) — DELIBERATELY not the
// design doc's originally-sketched "client supplies arbitrary bindings"
// body shape; this route only accepts a placement_id and re-resolves
// that placement's OWN already-stored bindings server-side, so a caller
// can never point it at a source/args pair the placement wasn't already
// configured with. New assets/js/element-live.js is a thin transport +
// DOM-patch layer (never a second resolver) that polls this route every
// 20s for any '[data-bhel-live]' node and patches its '[data-bhel-
// bind]' child in place. 'bh/stat-card' (the existing bhcore_events.
// count demo element) is the one type marked 'live' => true and its
// demo-seeding button's binding now also carries 'format' =>
// 'compact_number', so both new pieces are exercised by the same
// existing one-click Debug Tools demo, not a newly-invented one.
// Enqueued only on the own-ur-shit dashboard page (class-dashboard.php)
// where that demo element can actually appear; the script no-ops if it
// finds zero live nodes, so this costs nothing elsewhere. v2 (chained
// bindings) and v3 (client-state binding) remain designed-not-built
// (§3.2), unchanged by this pass.
//
// 3.4.45 — 2026-07-12 — found the REAL bug behind 3.4.43/3.4.44
// visibly not applying on a fresh-restarted live site (AJ confirmed a
// real OPcache restart happened — this was never caching). Root cause:
// element-builder.js's iconBtn() gives every row action/toggle button
// WordPress core's OWN 'button' class. ".wp-core-ui .button" is two
// classes (CSS specificity 0,2,0) — higher than the plain-element
// selectors 3.4.43/3.4.44 used (0,1,1 and 0,1,0), so WP's default
// border+background chrome was winning every time no matter what got
// written in this file's own CSS. Fixed by raising selector specificity
// (assets/css/element-builder.css's own updated comment) rather than
// stripping the shared 'button' class from iconBtn() itself, since that
// function is used by other buttons elsewhere not screenshot-verified
// this session — safer to fix the two call sites that actually have the
// problem than change a shared helper's behavior everywhere.
//
// 3.4.44 — 2026-07-12 — follow-up polish on 3.4.43's row chrome fix,
// direct response to: toggle arrow "takes a lot of space for what it
// does," glyphs "not centered well," rows feeling "off to the right,"
// and "no real good margin/gap/padding" against the rail's own edges.
// Toggle shrunk 22px -> 18px (still a mouse-appropriate target on
// desktop; the existing 44px mobile touch-target floor is untouched),
// action buttons 22px -> 20px, both centered on both axes with a fixed
// line-height. Rail column widened 300px -> 330px per "maybe make a
// touch wider." The reparented .bhel-canvas (element-builder.css) now
// gets real horizontal padding when embedded in this rail specifically
// (class-style-gallery.php's own new scoped rule), and its own
// redundant border is dropped in that context so cards don't read as
// double-boxed against the rail's outer border.
//
// 3.4.43 — 2026-07-12 — direct response to a live screenshot showing
// the 3.4.42 density pass's row action buttons still looking "wonky...
// not clean or sleek." Root cause: they were still rendering with
// WordPress core's default ".button" chrome (visible border + white
// background on every single glyph), so a row of 4 actions read as 4
// separate boxes bolted together, not a toolbar. Stripped to flat
// ghost icon buttons — no border, transparent until hover — and
// unified the expand/collapse toggle's sizing/chrome into the exact
// same rule so every small control in a row shares one visual
// language. Also fixed a real ordering bug: .bhel-tree-toggle's old
// standalone rule (padding:2px 4px, no width/height) was declared
// AFTER the new shared rule and would have partially overridden it —
// removed, sizing now lives only in the shared rule.
//
// 3.4.42 — 2026-07-12 — density/intentionality pass on the tree rows,
// direct response to "the left side buttons... just too big... dont
// really fit the space... looks like everything was shoved in." Root
// cause: the literal '+ child' button text was wide enough on its own
// to force the whole actions cluster onto its own wrapped line for
// nearly every node — element-builder.js's renderTreeNode()/render
// SlotInspector() (canvas add-child row) both shrink it to '+' (the
// tooltip already says "Add a child..." in full). Every row action
// button is now a uniform 22x22px square instead of variable-width
// text buttons. Row padding/margin tightened, and the meta line (e.g.
// "bh/stat-card · #1") is now a small trailing inline detail next to
// the label instead of its own full-width second line, halving the
// per-row height. Net effect: rows read as a deliberate compact
// toolbar instead of overflow that happened to wrap.
//
// 3.4.41 — 2026-07-12 — rail widened 260px -> 300px and per-row padding
// tightened (still cramped-but-functional per AJ's own confirmation
// that 3.4.40's wrap fix worked) so more of the tree fits without
// scrolling. Also added the FIRST mobile breakpoint the unified Design
// Suite grid has ever had — @media (max-width:900px) stacks rail/
// canvas/inspector into one column instead of the fixed three-column
// grid actively breaking on a phone-width screen. Explicitly NOT a full
// mobile redesign — AJ: "may need to rethink things a bit for that
// eventually, but we can cross that bridge" — this is the minimum fix
// so nothing actively overflows/breaks in the meantime.
//
// 3.4.40 — 2026-07-12 — direct response to a live screenshot showing
// the left rail squishing text ("Page content" wrapping mid-word
// against a crushed "+ child" button) at its real ~250px width. Root
// cause: .bhel-card and .bhel-slot-content-row forced toggle+label+
// meta+actions onto one flex row with no fallback. Fixed with flex-wrap
// instead of a rigid single row — the label/meta group and the actions
// group each get their own line once they don't both fit, no
// horizontal scroll, no markup change. Also added a hard overflow-x:
// hidden + max-width:100% floor on the whole left rail so nothing in
// it can ever force horizontal scroll again, whatever gets added to it
// later. See assets/css/element-builder.css's own updated comment for
// the exact rule changes.
//
// 3.4.39 — 2026-07-12 — direct response to live-screenshot feedback that
// the rail was "so confusing" it mixed the actual node tree with the
// unrelated demo-page "Preview surface" picker, and that the Slot
// inspector was too bare to be a real control surface. Left rail is now
// two real tabs: "Structure" (the tree, default) and "Preview" (the
// story picker, clearly labeled as just live demo pages for checking a
// style at a glance, not part of what you're building) — picking a
// preview surface auto-returns you to Structure so you're never
// stranded off the tree. The Slot inspector (and the mostly-empty
// Surface inspector) now show a real, clickable list of what's actually
// inside them (placements / slots respectively) using the same card
// row styling, instead of a dead-end "expand it in the tree" sentence.
//
// 3.4.38 — 2026-07-11 — confirmed (via a zoomed live screenshot plus a
// direct code read of render_left_rail(), which has exactly one "Site"
// .bhy-rail-heading) that the apparent "duplicate SITE heading" reported
// from an earlier screenshot was a misreading of one continuous rail
// section (the reparented topbar's "Save all changes" button sitting
// directly above the single PHP-rendered "Site" heading) — no code
// change was needed for that part, and this note exists so the next
// pass doesn't re-investigate a bug that isn't real. Fixed the actual
// remaining gap instead: the center canvas's "Preview surface" story
// picker was a fully independent selection system from the tree — no
// registered surface/slot/placement tree node click ever changed which
// surface's live iframe was showing. element-builder.js's
// fireSelectionEvent() now includes the selected node's surface slug in
// the 'bhel:selection' CustomEvent detail; class-style-gallery.php's
// render_script() has a new listener that clicks the matching
// .bhy-story-btn whenever that surface slug is present, keeping both
// selection systems in sync without either file reaching into the
// other's internals beyond that one event (same coordination boundary
// the Site/placement inspector toggle already uses).
//
// 3.4.37 — 2026-07-11 — the 3.4.36 "FINAL ARCHITECTURE" tree still made
// you pick a surface/slot via a raw topbar form before you could see its
// placements; every registered surface and slot is now a real,
// automatically-populated tree node under "Site" instead (Site -> Surface
// -> Slot -> real placements), the topbar shrank to a status line plus
// one global "Save all changes" action, and "Save as Prefab" moved from
// the topbar onto the Slot node it actually concerns. See assets/js/
// element-builder.js's own updated docblock for the full mechanics.
//
// 3.4.36 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md "FINAL
// ARCHITECTURE": collapses the "Library / Pages / Global Styles" three
// parallel left-rail sections (built through 3.4.35) into exactly ONE
// tree, rooted at a synthetic "Site" node — selecting Site shows global
// style tokens in the one shared inspector, every page/section/button/
// form/list/menu is a child at any depth of Site or another node through
// the SAME parent_placement_id mechanism, and the Library palette is now
// a contextual add-child popup instead of a standing panel. class-
// element.php gained get_placement()/get_subtree() and the GET/POST
// .../elements/site-tokens REST pair; class-element-prefab.php gained
// full-subtree snapshot/restore (save_from_node(), a real id-remapping
// pass in instantiate()); class-style-gallery.php's left rail is now one
// "Site" tree mount plus the unchanged Preview-surface picker, and its
// Global Styles panel toggles against the placement inspector via a
// 'bhel:selection' event instead of the old per-group rail buttons;
// element-builder.js's canvas is now the one tree (Site node + the
// existing recursive renderTreeNode()), its inspector branches on
// selection type, and its old standing palette is now a floating add-
// child popup with a real right-click context menu. See each file's own
// updated docblock for the full mechanics; DESIGN-SUITE-UNIFICATION-
// PLAN.md's top status note is updated to reflect what's actually built.
//
// 3.4.35 — 2026-07-11 — Two fixes on the unified Designer shell
// (class-style-gallery.php, assets/js/element-builder.js,
// assets/css/element-builder.css): (1) the widget canvas/inspector
// mounts no longer start "display:none" and the left-rail's Global
// Styles click handler no longer hides them — canvas and inspector are
// now always visible, per the "no hidden modes, ever" rule (closes the
// bug where .bhel-canvas landed in a hidden pane on first load); (2)
// Global Styles' token panel now shares real markup with the widget
// inspector instead of a separately-coded look-alike — section headers
// use the same "h3, small-caps, underline" rule as .bhel-inspector h3,
// and the less-common colors / 8 category swatches are now collapsible
// "bhel-style-group" disclosures (the SAME class the inspector's
// Style — Advanced ▸ BACKGROUND/▸ BORDER groups use), plus the topbar's
// raw numeric Context ID spinner is tucked behind its own such
// disclosure instead of sitting bare next to Surface/Slot. Color
// swatches were already one shared .bhy-swatch-card component built by
// BHY_UI::swatch_field() (PHP) and renderStyleField() (JS) — this pass
// didn't need to touch that part, only the surrounding chrome.
//
// 3.4.34 — 2026-07-11 — Activates the parent_placement_id seam
// (bhcore_element_placements, DB_VERSION 1.9's own reserved column, no
// schema change) as a real tree of placements within one surface/slot,
// closing the Design Suite "Pages" rail's honestly-disclosed flat-list
// gap: class-element.php's render_slot()/render_placement() are now
// tree-aware (one query per slot, in-memory parent=>children map, no
// N+1), save_placement() validates a parent stays within the same
// surface/context/slot and rejects any change that would create a cycle,
// and the REST save route accepts/persists parent_placement_id with
// per-parent-group position; element-builder.js's canvas is now a real
// recursive tree renderer (expand/collapse, per-depth indent, "+ child",
// sibling-scoped up/down, a "Parent" inspector control to move a node to
// a different parent) built client-side from the UNCHANGED flat GET
// .../placements response. The bh/container -> BH_Content bridge is a
// separate, untouched mechanism; the CRM Project Tracker's kanban sub-
// cards (bh-crm) use that same untouched bridge, not this seam.
//
// 3.4.33 — 2026-07-11 — Visual/UX polish pass on the 3.4.32 unified
// Design Suite shell (class-style-gallery.php, class-ui.php,
// assets/css/element-builder.css): consistent hover/selected/focus
// states using the shared --bhy- design tokens, clearer typographic
// hierarchy in rail headings and inspector section headers, a rotating-
// caret disclosure indicator for the Style — Advanced property groups,
// and uniform control heights/spacing throughout. Pure styling - no
// structure, data flow, REST calls, or PHP rendering logic changed.
//
// 3.4.32 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md "Interaction-
// model spec" build: the 3.4.30 tab switcher ("Site Styles" / "Widgets &
// Elements" as two whole panels on 'bh-style') is removed. BHY_Gallery's
// render_shell() now builds ONE left-rail/canvas/inspector layout, and
// physically reparents BH_Element_Builder's existing DOM (unchanged
// element-builder.js) into that rail's Library/Pages sections and the
// shared canvas/inspector columns instead of showing/hiding a second
// whole panel. See class-style-gallery.php's own docblock/render_shell().
// No REST/data logic changed anywhere; class-element-builder.php and
// assets/js/element-builder.js are byte-for-byte unchanged.
//
// 3.4.31 — 2026-07-11 — Real menu-duplication fix from the QA walkthrough:
// 'bh-style' (Designer) and 'bh-crm' (People) were each registered a
// SECOND time as their own independently-visible sidebar submenu,
// alongside 'bh-design'/'bh-crm-hub''s existing top-level entries that
// already call the exact same render callback — two sidebar rows landing
// on byte-identical output by coincidence, not one shared destination.
// Both now register with a null/hidden parent (the same "reachable by
// direct link only" pattern class-studio.php already established for
// 'bh-studio'), so the top-level entry is the ONE visible row and the
// slug stays live for every existing deep link/redirect. BHY_Gallery's
// render() body also split into a new portable render_shell($args)
// method (small 'default_tab' config) so future mount points can embed
// it without copying markup. See class-style-gallery.php, class-registry.
// php, and class-menu-merge.php (fixed a '??' vs array_key_exists() bug
// that would have silently ignored an explicit null 'parent').
//
// 3.4.30 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md real structural
// fix, correcting 3.4.29's own framing: "Element Builder is part of the
// Design Suite menu, not Debug Tools" was still THREE adjacent pages
// under one parent, not the ONE interface asked for. This pass merges
// BH_Element_Builder's palette/canvas/inspector into BHY_Gallery's
// existing 'bh-style' page as a "Widgets & Elements" tab alongside the
// existing "Site Styles" tab (same tree/canvas/inspector shell, see
// class-style-gallery.php's own docblock/render()). The
// add_action('admin_menu', ['BH_Element_Builder', 'add_menu']) line that
// used to register its own submenu below is now REMOVED (method left
// fully defined in class-element-builder.php, just unhooked, per this
// ecosystem's standing convention) — BH_Design_Suite's top-level
// 'bh-design' menu now points at exactly one real page. BH_Studio's
// standalone 'bh-studio' page is similarly no longer a menu destination
// (class-studio.php's add_menu() now registers it with a null/hidden
// parent) — a container element's nested content opens it as a MODAL
// iframe from inside the unified shell instead (element-builder.js's
// openStudioModal()), never a full page navigation. No REST/data-layer
// route or shape changed anywhere in this pass — see each touched file's
// own docblock for the reasoning specific to it.
//
// 3.4.29 — 2026-07-11 — Element Builder is now entirely part of the
// Design Suite, not Debug Tools in any way: BH_Element_Builder gained a
// real add_menu() registering it as a submenu of the top-level
// bh-design menu (mirroring BHY_Gallery/BH_Studio and every §1.4
// mitigation rule), and its old add_filter('ous_debug_tools', ...)
// registration is no longer hooked. Same shared render body, only the
// access point changed. See class-element-builder.php's own docblock
// for the full reasoning. class-dashboard.php's and class-portal.php's
// "Debug Tools -> Element Builder" cross-references were updated to
// point at the new Design Suite -> Element Builder location instead.
// BH_Element's own separate bare add/remove/reorder Debug Tools section
// ('bh-element' key) is untouched — it's a different, lower-level raw-
// placement CRUD tool, not "the element builder" this instruction meant.

// 3.4.28 — 2026-07-11 — element-builder.js/.css UX pass over the 3.4.27
// inspector: the "Style — Advanced" property groups (§2.6) are now
// collapsible disclosures (collapsed by default, auto-open only when a
// group already carries an active override) instead of ~11 always-open
// fieldsets stacked in a row, so the panel reads as "what's actually
// customized" at a glance. Added the §2.5 responsive behavior the prior
// pass's plain 1100px block-stack didn't cover: a real 3-tier layout
// (>=1200px full three-pane, 783-1199px palette-as-overlay behind a
// toggle button mirroring WP admin's own folded-sidebar convention,
// <=782px single-column stack with the inspector as a bottom sheet —
// drag handle, "Done" dismiss, slide-up transform — matching WP admin's
// own 782px breakpoint) plus a ~44px minimum touch target on every
// interactive control at that narrowest width. The existing up/down
// reorder buttons (never drag-and-drop) are unchanged and remain the
// primary reorder path at every width, per this ecosystem's own mobile-
// friendly-first posture. No REST route, storage shape, or PHP behavior
// changed this pass — purely JS/CSS. NOT runtime-verified: no live
// browser available in this environment; reasoned through against the
// DOM/class names element-builder.js already emits.

// 3.4.27 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2's
// inspector UI (§2.6), the one piece 3.4.26 deliberately left unshipped:
// class-element-builder.php's GUI (assets/js/element-builder.js/.css)
// gained a "Style — Advanced" section (every §2.6 property group as a
// dynamic preset picker + custom-value escape hatch) and an "HTML
// Attributes" section (tag picker, per-type attr fields, repeatable
// custom data-* row editor) — both built entirely from REST-exposed
// manifests (GET .../elements/types' existing attrs/tags keys, plus a
// new GET .../elements/style-schema route backed by BHY_Style::
// style_schema_for_js(), class-style.php), never hardcoded per element
// type or property group client-side. Both sections write into
// config.style/config.htmlAttrs and save through the EXISTING POST
// .../placements route unmodified — no REST route changed shape.
// NOT runtime-verified: no live PHP/WordPress/browser execution
// available in this environment: static/brace-checked only.

// 3.4.26 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2, ALL
// property groups shipped in one pass (no [core]/[adv] deferral, per
// AJ's explicit instruction): BHY_Style::scoped_inline_style()
// (class-style.php) resolves a placement's config.style map — bare
// --bh-* token keys (§2.3, unchanged) PLUS new namespaced
// "group.property" keys (§2.6: sizing/spacing/background/typography/
// border/display+flex+grid/position/effects+transforms/overflow+
// visibility) — into an inline style="" attribute; new safe_length()/
// safe_enum() validators added alongside the existing safe_color()/
// safe_number(). BH_Element::render_placement() (class-element.php) now
// builds each placement's OWN wrapper element (tag + class + data-
// placement-id/data-type + the resolved style + a strictly-allowlisted
// htmlAttrs set — id/class/title/aria-label/href+target+rel-when-tag-is-
// 'a'/custom data-*), moved out of render_slot() so REST preview
// (rest_preview()) gets an identical wrapper. register_type()'s $args
// contract gained 'attrs' (per-attribute allowlist) and 'tags' (allowed
// semantic tags, first = default, defaults to ['div']) — GET
// /elements/types now exposes both so inspector JS can build controls
// per-type dynamically. First-party types 'bh/note'/'bh/container' got
// real tag lists; 'bh/stat-card' is this phase's tag-choice +
// href/target/rel demonstration type (tags => ['div','a']). The
// POST .../placements route needed NO changes — 'config' was already
// accepted and stored verbatim, so style/htmlAttrs ride the existing
// upsert path unmodified. Inspector UI (class-element-builder.php /
// element-builder.js/css) is NOT part of this pass — see this file's
// own status note in DESIGN-SUITE-UNIFICATION-PLAN.md for what's open.
// NOT runtime-verified: no live PHP/WordPress/browser execution
// available in this environment: static/brace-checked only.

// 3.4.25 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (menu
// restructure only — no inspector unification): new top-level "Design
// Suite" menu (bh-design, new class-design-suite.php / BH_Design_Suite)
// and, in bh-crm, a new top-level "CRM" menu (bh-crm-hub, new
// bh-crm/includes/class-hub.php / BHCRM_Hub). BH_Design_Suite's own
// top-level/first-submenu callback deliberately REUSES BHY_Gallery::
// render() (the real, working Style page) rather than a placeholder —
// there is no unified inspector shell yet (that's Phase 3), so pointing
// the new landing page at the one real screen that already exists is
// more honest than a stub. BHY_Gallery::add_menu() and BH_Studio::
// add_menu() now register 'bh-style'/'bh-studio' as submenus of
// 'bh-design' instead of 'own-ur-shit' (slugs unchanged — every existing
// admin.php?page=bh-style/bh-studio deep link keeps working), with their
// capability changed from 'manage_options' to the new 'bhcore_design_site'
// capability (class-roles.php) so a non-admin employee holding it can
// actually see these menus, not just admins. OUS_MenuMerge::merge()
// (class-menu-merge.php) gained optional 'parent'/'capability' keys on
// each OUS_Registry admin_menus entry (default 'own-ur-shit'/
// 'manage_options', fully backward compatible with bh-registry's and
// bh-monetization-woo's existing entries, which set neither) — bh-crm's
// entry (class-registry.php) now sets 'parent' => 'bh-crm-hub' and
// 'capability' => 'bhcore_manage_crm' for both its People submenu and a
// new Project Tracker submenu (BHCRM_Projects::render_boards(), new).
// Two new capabilities registered via the EXISTING OUS_Roles mechanism
// (class-roles.php) — bhcore_design_site, bhcore_manage_crm — granted to
// 'administrator' (default, so nothing an admin can already do changes)
// and, as a documented STOPGAP, to 'editor' (this ecosystem has no
// dedicated non-admin "staff" role yet; see class-roles.php's own
// comment on this). Every new/changed add_menu_page()/add_submenu_page()
// call follows DESIGN-SUITE-UNIFICATION-PLAN.md §1.4's six mitigation
// rules for this install's documented standalone-page hook-resolution
// bug: unconditional registration, default admin_menu priority, real
// (never stub) callbacks, a capability the current user actually holds
// at registration time, TOP-LEVEL menus (not submenus-of-submenus), and
// registration-result logging via OUS_DebugLog::log_throttled(), same
// pattern class-api-docs.php/class-codebase-docs.php already use.
// Nothing in BH_Element/BH_Element_Data/BHY_Style/the inspector/builder
// UI logic changed — this pass is menu location and capability only.
// Standing caveat: reasoning/brace-balance-checked only, no live
// PHP/MySQL/WordPress/REST/browser execution available in this
// environment — this install has FIVE-PLUS prior diagnostic passes on
// exactly this class of bug (see class-api-docs.php's docblock), so
// smoke-test both new top-level menus especially carefully, ideally
// logged in as a non-admin 'editor'-role account holding only the new
// capabilities (not an admin, whose manage_options masks capability-
// scoping bugs), after an OPcache reset, before trusting this in
// production.

// 3.4.24 — 2026-07-11 — Remaining ELEMENT-BUILDER-DESIGN-PLAN.md §6
// phases: the Portal registered as a real bh_element_surfaces
// contributor with one new element-composed panel (BHI_Portal::
// register_element_surface()/register_elements_panel(), class-portal.php);
// a real container element type (bh/container, class-element.php) whose
// content is an embedded BH_Content subtree — the §1.1 hybrid-nesting
// bridge into the EXISTING BH_Studio canvas, not a second tree editor —
// with BH_Element::save_placement() auto-assigning content_context_id =
// the placement's own id for container types on first save; and a real
// DELETE /elements/placements/{id} REST route (class-element.php),
// closing the one gap that route's own docblock previously named. Also
// ships a genuinely NEW addition beyond the design doc's own scope, per
// AJ's mid-build request: the prefab system (new BH_Element_Prefab,
// class-element-prefab.php; new bhcore_element_prefabs table,
// class-identity-activator.php DB_VERSION 1.10) — named, reusable, deep-
// copyable saved compositions of placements, with "Save as Prefab" /
// prefab-picker controls added to BH_Element_Builder's existing GUI
// (assets/js/element-builder.js, assets/css/element-builder.css) — see
// ELEMENT-BUILDER-DESIGN-PLAN.md's own trailing status note for the
// honest "this wasn't in the original doc" framing.

// 3.4.23 — 2026-07-11 — Element builder, §4/§6-step-2 GUI phase of
// ELEMENT-BUILDER-DESIGN-PLAN.md: new BH_Element_Builder
// (class-element-builder.php) — a three-pane visual builder (palette /
// canvas / inspector) cloned from BHY_Gallery's Storybook layout,
// shipped as a NEW, additive Debug Tools section ("Element Builder
// (Visual)") rather than a standalone admin page, per this install's
// documented hook-resolution bug affecting standalone/submenu-of-
// ous-debug pages (see class-api-docs.php's docblock and this class's
// own docblock for the incident). New assets/js/element-builder.js +
// assets/css/element-builder.css (no build step, vanilla JS, enqueued
// only on the Debug Tools screen). Reads/writes the EXISTING
// ous/v1/elements/* REST bridge only — no new route, no duplicated
// BH_Element/BH_Element_Data logic. BH_Element's own bare add/remove/
// reorder Debug Tools section ('bh-element' key) is untouched and still
// works standalone. Standing caveat: reasoning/brace-balance-checked
// only, no live browser/WordPress/REST execution available this
// session — smoke-test the full load/add/reorder/bind/style/save round
// trip against a real install before relying on this in production.
//
// 3.4.22 — 2026-07-10 — Element builder, §5.2 surface expansion +
// §3.4 REST bridge of ELEMENT-BUILDER-DESIGN-PLAN.md: BH_Element gains
// register_routes()/rest_get_surfaces()/rest_get_types()/rest_get_sources()/
// rest_get_placements()/rest_save_placements()/rest_preview()
// (class-element.php), all under 'ous/v1', all manage_options-gated,
// mirroring BH_Studio::register_routes()'s exact auth posture — no new
// admin UI ships from these routes this pass, they exist for a future
// GUI/REST client. The 'bh_crm_profile' surface (bh-crm's
// BHCRM_People::register_element_surface(), class-people.php) is now
// registered and rendered via three new BH_Element::render_slot() call
// sites in BHCRM_People::render_detail() (header/main/sidebar), still
// additive-only around the existing profile page. Standing caveat:
// reasoning/brace-balance-checked only, no live PHP/MySQL/WordPress
// REST dispatch available this session — smoke-test every new route
// and the CRM profile's three new slots against a real install before
// relying on this in production.
//
// 3.4.21 — Element builder, Phase 1 + Phase 2 of
// ELEMENT-BUILDER-DESIGN-PLAN.md: new BH_Element (class-element.php,
// type registry + bhcore_element_placements storage + render_slot()/
// render_placement()) and BH_Element_Data (class-element-data.php, the
// declarative data-binding resolver — register_source()/resolve()) —
// see each class's own docblock for the full contract, especially
// BH_Element_Data::resolve()'s fallback behavior on a missing/invalid/
// erroring binding (never fatal, always degrades to the attribute's
// literal default). New bhcore_element_placements table, DB_VERSION
// 1.9 (class-identity-activator.php). One real end-to-end slice wired
// this pass: the 'dashboard' surface (OUS_Dashboard::
// register_element_surface(), class-dashboard.php) with a 'main' slot
// rendered via BH_Element::render_slot() below the existing status
// block, two first-party element types ('bh/note' static,
// 'bh/stat-card' data-bindable), and one first-party data source
// ('bhcore_events.count', wrapping a direct bhcore_events query since
// BH_Event exposes no public count helper today). Placements are
// managed via a new Debug Tools "Element Builder" section (add/remove/
// reorder, plus a one-click "add live stat-card" bound-data demo) — the
// visual drag/drop builder GUI from the design doc's later phase is
// NOT built this pass. Standing caveat: reasoning/brace-balance-checked
// only, no live PHP/MySQL/WordPress execution available this session —
// please smoke-test dbDelta() actually creating the new table, a
// placement round-tripping through save/render, and the bound
// stat-card resolving a real bhcore_events count before relying on
// this in production.
//
// 3.4.20 — Debug Tools: group headings (added in 3.4.19) are now
// collapsible <details> in their own right, not just the sections
// nested inside them, with default-OPEN state and their own
// ous_debug_group_open_ localStorage namespace (kept separate from the
// existing per-section ous_debug_section_open_ keys); the anchor-jump
// script now also force-opens a target section's ancestor group. See
// class-debug.php's own docblock for the full writeup. Standing caveat:
// reasoning/brace-balance-checked only, no live PHP/browser execution
// available this session — please confirm the group collapse/expand and
// anchor-jump-through-a-collapsed-group behavior on the live site.

// 3.4.19 — Debug Tools reorganization pass: added an optional 'group'
// key to the ous_debug_tools registration array shape (self::GROUP_*
// constants in class-debug.php) so sections render bucketed by purpose
// (Monitoring & Health / Reference & Docs / Seed & Reset Tools /
// Diagnostics & Tools default) instead of flat registration order, with
// a grouped "Jump to" quicknav to match. Purely additive — no existing
// add_filter('ous_debug_tools', ...) call site had to change shape to
// keep working; every current registrant was also updated to set an
// explicit 'group' so the new grouping takes effect immediately. See
// class-debug.php's own docblock for the full writeup. Standing caveat:
// reasoning/brace-balance-checked only, no live PHP execution available
// this session — please confirm the grouped page renders correctly.

// 3.4.15 — confirmed via Query Monitor (capability-checks + admin-screen
// panels, installed temporarily on the live site): the standalone
// admin.php?page=ous-api-docs / ous-codebase-docs pages fail because
// WordPress's own get_current_screen()/hook_suffix resolution falls
// back to the PARENT page's hook instead of the submenu's, on every
// request, regardless of correct registration/capability — a genuine
// WordPress-core page-hook lookup issue, not caching or capabilities
// (the two things chased hardest earlier). Since the Debug Tools
// SECTION versions of both pages are confirmed working end to end, the
// two standalone add_menu() registrations are now unhooked entirely
// (methods left defined, just not called) rather than left as dead,
// permanently-broken links sitting in the sidebar. Also fixed the one
// remaining internal link between the two (Codebase Docs' "Open API
// Docs" cross-link) to point at the section anchor instead of the now-
// unregistered standalone page. See VISION.md's "New dev/admin-only
// pages default to a Debug Tools SECTION" entry for the full incident
// writeup. Standing caveat: reasoning/brace-balance-checked only —
// please confirm the sidebar no longer shows API Docs/Codebase Docs as
// separate top-level-adjacent entries, and that both sections still
// work fine on Debug Tools.

// 3.4.14 — Stopped chasing the standalone-page access-denial bug
// (registration and capability both confirmed correct via logging, yet
// WordPress still blocked admin.php?page=ous-api-docs / ous-codebase-docs
// every time — root cause never found despite five diagnostic passes)
// and sidestepped it instead: both API Docs and Codebase Docs now render
// their REAL content as sections directly on the Debug Tools page
// (ous-debug), the one page that has never once failed to load all
// session. class-api-docs.php's render_debug_section() (previously just
// a diagnostic panel) and class-codebase-docs.php's new render_section()
// both call a shared render_content() method, factored out of each
// class's standalone render() so neither duplicates the actual body
// markup. Debug Tools' own "API Docs"/"Codebase Docs" buttons now jump
// to these sections (#ous-section-api-docs / #ous-section-codebase-docs)
// instead of linking to the still-broken standalone pages, which remain
// registered as a secondary access point but should not be relied on.
// Standing caveat: reasoning/brace-balance-checked only — please reload
// Debug Tools and confirm both sections now show real content inline.

// 3.4.13 — CONFIRMED via 3.4.12's render()-entry log: render() never
// runs at all for Codebase Docs — WordPress is blocking the request at
// its own core dispatch level (the $_wp_submenu_nopriv mechanism:
// add_submenu_page() checks current_user_can() at the MOMENT it's
// called, on that specific request, and silently marks the page
// no-priv if it fails then — separate from the page callback entirely).
// Un-throttled the registration log and added the exact request URI +
// a same-request current_user_can() reading, specifically so the entry
// from the real failing click (not a nearby unrelated page load) is
// unambiguous. Also added a temporary workaround in class-debug.php:
// hand-built, guaranteed-correct admin.php?page= links to both pages
// printed directly on the Debug Tools page itself, since a live bug
// report showed the WordPress-generated SIDEBAR link for these two
// pages resolving to a broken bare front-end path instead — a second,
// separate bug from the access-denial one, not yet root-caused either,
// worked around rather than left blocking. Standing caveat: please
// click Codebase Docs (via the new button on Debug Tools, not the
// sidebar) once and paste back the newest matching log line.

// 3.4.12 — 3.4.11's is_locked()-gate removal confirmed NOT the fix (user
// reports no change in behavior). Added the one truly decisive
// diagnostic left: a log line as the literal first statement inside
// render() itself for both classes — this settles, once and for all,
// whether WordPress is blocking the request before OUR code ever runs
// (a genuine core-level gate this session hasn't found the cause of
// yet) or whether the callback IS running and something inside it is
// the actual problem. Standing caveat: purely diagnostic, no behavior
// change; please click into both pages once more and report exactly
// what Console & Logs shows (or doesn't show) from "render() was
// entered."

// 3.4.11 — API Docs / Codebase Docs "not allowed" bug, actual fix (not
// another diagnostic pass): found that both were the ONLY two admin
// pages anywhere in this ecosystem that wrapped their own
// add_submenu_page() call in an is_locked() check before registering.
// Every other page (Debug Tools itself, Job Queue, every peer plugin's
// screens) registers unconditionally — is_locked() exists to gate
// DESTRUCTIVE seed/reset actions, not a read-only viewer page's mere
// existence in the menu, so conditionally skipping registration was the
// wrong design from the start, independent of whatever is_locked()
// itself was actually evaluating to on any given request. Both classes'
// add_menu() now register unconditionally, matching every other working
// page. Standing caveat: still reasoning-checked only, not yet clicked
// on the live install — please try both pages now.

// 3.4.10 — PHP restart on the live site confirmed OPcache was serving
// stale compiled code (explains several earlier "this fix didn't seem to
// take effect" moments this session) — after restarting, add_submenu_page()
// for both API Docs and Codebase Docs now confirmed returning a real
// hook_suffix, not FALSE. Registration is NOT the problem. But
// add_submenu_page() returns a real hook_suffix even when the CURRENT
// user lacks the registered capability — WordPress's actual access gate
// for that case is a separate current_user_can() re-check done when the
// page is actually requested, which a successful registration log can't
// rule out. Added a direct current_user_can('manage_options') check,
// logged with the exact request URI, to settle this definitively.
// Standing caveat: diagnostic only, still narrowing down root cause.

// 3.4.9 — API Docs / Codebase Docs still 404 with is_locked() confirmed
// NOT the cause (zero log entries even from the locked-branch logging
// 3.4.5/this pass added, meaning that branch never ran — but that was
// ambiguous, since the SUCCESS branch had no logging either, so "no log"
// couldn't distinguish "never called" from "ran fine"). Added logging to
// the success path too: both add_menu() methods now log whatever
// add_submenu_page() actually returned (a real hook suffix string, or
// FALSE on a genuine registration failure) every time they run, closing
// that ambiguity for the next reload. Standing caveat: diagnostic-only
// change, root cause still not confirmed — waiting on the next log
// check to narrow it down further.

// 3.4.8 — 3.4.7's own Portal fix had a real side effect: calling
// add_rewrite() synchronously at 'init' priority 10 meant its
// force_flush_and_verify() could run before other plugins' own
// default-priority rewrite registrations, and its unconditional
// wp_cache_flush() wiped the WHOLE object cache mid-request — very
// likely why API Docs started intermittently 404ing right after 3.4.7
// shipped (is_locked()'s cached host checks, read later in the same
// request, got yanked out from under it). Fixed two ways: add_rewrite()
// is now deferred to 'init' priority 20 (still the same request/pass,
// just after other plugins' default-priority rewrite rules have
// registered), and wp_cache_flush() is now an ESCALATION only reached
// if the cheaper targeted cache evictions didn't already fix it, not
// called unconditionally on every throttled self-heal attempt. Also
// regenerated four stale bundled zips (bh-contest, bh-courses,
// bh-monetization-woo, bh-registry) flagged on the Bundled Zip
// Freshness table. Standing caveat: reasoning-checked only, not yet
// confirmed against the live install — please reload a few pages
// (including /account/ and API Docs) and check whether both stay
// reachable now.

// 3.4.7 — Portal's /account/ 404, finally actually found (not another
// caching-layer guess): class-portal.php's own init() was hooking
// add_rewrite() onto 'init' FROM INSIDE a callback that is itself
// currently running as part of 'init' (own-ur-shit.php's own
// add_action('init', ['BHI_Portal','init']) at default priority 10).
// PHP's foreach over that priority's callback array is a snapshot taken
// when iteration starts; a handler appended to the SAME priority after
// iteration has already begun isn't picked up until 'init' fires again
// — which, on a normal page load, never happens in that request. So
// add_rewrite() was successfully scheduling itself to run on a request
// that would never come, which is exactly why even its own always-
// throttled diagnostic breadcrumb never once appeared in Console & Logs
// — the method was never being entered, not failing partway through.
// Fixed by calling add_rewrite() directly from inside init() instead of
// re-hooking it — we're already executing inside 'init' at that point,
// so a direct call runs it immediately, every request, no re-hooking
// needed. See class-portal.php's own comment at the fix site for the
// full mechanics. Standing caveat: reasoning-checked, brace-balance-
// checked, and this specific WP_Hook same-priority-snapshot behavior is
// a well-documented WordPress core mechanic (not a guess) — but this has
// NOT yet been clicked/reloaded on the live install. Please hard-refresh
// /account/ and check Debug Tools -> Portal after this update and report
// back whether the rewrite rule now shows as persisted.

// 3.4.6 — OUS_Jobs can now run on the REAL Action Scheduler library
// (Apache-2.0, github.com/woocommerce/action-scheduler — the same
// library WooCommerce itself bundles) instead of only its own
// hand-rolled wpdb-table queue. A one-click "Install Action Scheduler"
// button on Debug Tools -> Job Queue downloads the actual official
// release directly from GitHub onto the LIVE site (this dev sandbox has
// no outbound network access at all, confirmed by testing — so the
// library could not be vendored directly from here; fabricating
// placeholder code under a real project's name would be dishonest, so a
// real installer was built instead, same download_url()/unzip_file()
// mechanism OUS_Registry already uses for WooCommerce). register()/
// enqueue() delegate to Action Scheduler's native add_action()/
// as_enqueue_async_action() once installed, with ZERO call-site changes
// needed anywhere bh-registry/bh-streaming/etc. already call OUS_Jobs —
// until installed, every existing call transparently keeps using the
// original table-backed implementation exactly as before. See
// class-jobs.php's own docblock for the full reasoning. Standing
// caveat: reasoning/brace-balance-checked only, the install button
// itself has not been clicked against the live site yet — please try it
// and report back what Debug Tools -> Job Queue shows.

// 3.4.5 — real bug fix + new feature. (1) bh-contest's Live Console
// dropdown 403'd because its GET form dropped post_type on submit — see
// bh-contest 3.1.3 for the fix; own-ur-shit itself was audited alongside
// it (bh-contest, BHY_* styles, bh-crm, Debug Tools) for the same bug
// class and no other instance was found. (2) New OUS_CodebaseDocs
// (class-codebase-docs.php, "Own Ur Shit → Codebase Docs"): renders
// CODEBASE-WALKTHROUGH.md as real in-admin HTML, and turns every
// file-path mention in that doc into a "View live code" toggle that
// fetches the file's ACTUAL current contents via a locked-down AJAX
// endpoint (realpath()-verified inside the plugins root, manage_options-
// gated, nonce-checked) — so the walkthrough can never silently drift
// from the real code the way a pasted-in snippet would. Deliberately
// left OUS_ApiDocs' existing dependency-free viewer alone rather than
// swapping in a Swagger-UI bundle, to keep this ecosystem's own "no
// external JS/CDN" viewer convention intact; the two pages cross-link
// instead. Standing caveat: reasoning/brace-balance-checked only, not
// yet clicked on the live install.
// 3.4.78 — PAGE-BUILDER-DELETE-KEEP-AUDIT.md cleanup, completed: the 8
// confirmed-dead builder-UI files (assets/js/element-builder.js,
// assets/css/element-builder.css, class-element-builder.php,
// class-element-prefab.php, class-element-state.php,
// class-component-studio.php, assets/js/component-studio.js,
// assets/css/component-studio.css) are now actually deleted, not just
// unhooked (they'd only been dropped from the require loop in the prior
// pass). class-style-gallery.php's surgical trim is also done: render(),
// a reconstructed render_sidebar(), and render_script() rewritten back
// to "site-wide design tokens with a live preview" — the Structure/
// Library rail/canvas/inspector shell (render_shell(), render_left_
// rail(), the Library canvas/inspector panes, enqueue_widgets_assets())
// is gone, save()/add_menu()/enqueue_media()/render_canvas()/
// preview_doc()/render_token_preview()/render_controls() kept as-is.
// class-design-suite.php needed no change — its 'bh-design' top-level
// menu already pointed straight at BHY_Gallery::render(), confirmed by
// re-reading it fresh this pass.
//
// Two real, LIVE-CONFIRMED bugs found and fixed in the course of this
// same cleanup, both in class-ui.php's admin_page_css() — a giant
// single-quoted PHP string the earlier CSS-porting pass (this same
// session) had put two real, unescaped apostrophes/quotes into
// ("They're genuinely...", "class-style-gallery.php's own...", and a
// CSS `content: '▸';` rule), each one silently truncating the PHP
// string at that exact character and turning the rest of the file's
// CSS text into stray PHP tokens — a real site-wide fatal parse error
// (own-ur-shit.php's require loop loads class-ui.php on every single
// request, front-end and admin alike), reported live by AJ as "There
// has been a critical error" across the whole install. Root-caused by
// temporarily flipping WP_DEBUG_DISPLAY/WP_DEBUG_LOG on in wp-config.php
// (reverted immediately after), reading the exact parse-error line out
// of the resulting debug.log, and fixing both occurrences by escaping/
// rewording around the stray quotes. Confirmed fixed live by AJ.
//
// Also new this pass, both AJ's own asks mid-cleanup: (1)
// BHY_Style::custom_sliders() — a `bhy_style_custom_sliders` filter a
// peer plugin registers a slider through from its own bootstrap (same
// shape as the existing `bhy_style_surfaces` filter), rendered in the
// Design Suite's new "Plugin adjustments" group with the exact same
// BHY_UI::slider_row() every built-in scale slider uses, saved through
// the same option, emitted as a real --bh-custom-<key> CSS var via
// BHY_Style::inline_css() — a plugin's own token, styled and wired
// identically to the built-ins, not a second-class add-on. (2) The
// "expansive CSS properties + databinding" capability AJ didn't want
// lost when the builder's inspector went away is NOT reinvented here —
// it already exists, live, as BH_Element_Data (the resolver) and
// BHY_Style::PROPERTY_MAP/scoped_inline_style()/style_schema_for_js()
// (class-style.php) for placement-level style overrides; what's missing
// is a GUI surface for it now that the builder's inspector is gone. See
// chat for the recommendation: a native Gutenberg InspectorControls
// sidebar panel on bh/component-ref (vanilla wp.element.createElement,
// no JSX/build), reading style_schema_for_js() directly — not built yet,
// pending AJ's go-ahead.
// 3.4.79 — BHY_BlockStyle (class-block-style.php + assets/js/block-
// style-panel.js): the "Advanced Styles" generic InspectorControls
// panel on every native block, closing the gap left when bh/component-
// ref (and the rest of class-component-studio.php) was deleted this
// same pass. Reserves one `bhStyle` attribute on every block type via
// `register_block_type_args`, gives it a real editing UI via
// `editor.BlockEdit` (vanilla wp.element.createElement, no JSX/build),
// and resolves it to real inline CSS at render time via `render_block`
// + BHY_Style::scoped_inline_style() (class-style.php, unchanged,
// already used by BH_Element::render_placement()) using
// WP_HTML_Tag_Processor rather than regex. NOT runtime-verified — no
// live WordPress execution available in this environment; syntax-
// checked (brace/string-balance) but not clicked on the real editor.
// Smoke-test: add a block, open Advanced Styles, set a spacing/color
// value, confirm it round-trips through save/reload and appears as
// real inline CSS on the front end.
//
// 3.4.80 — BHY_BlockStyle fix: the 3.4.79 smoke-test above was finally
// run against a real WordPress+MySQL install (first real execution
// this class has ever had) and it failed. The Style panel appeared and
// setAttributes({ bhStyle }) worked live in the editor's data store,
// but the value never survived save — the saved block comment came
// back with no `bhStyle` at all (`wp_posts.post_content` inspected
// directly to confirm). Root cause: `register_block_type_args`
// (class-block-style.php) only reserves `bhStyle` server-side, for
// REST/render-time validation. Gutenberg's own block SERIALIZER runs
// entirely client-side and only writes attributes the block's CLIENT
// type registry actually declares — assets/js/block-style-panel.js
// only ever added an `editor.BlockEdit` filter (the UI), never a
// `blocks.registerBlockType` filter to declare the attribute on the
// client side, so the serializer silently dropped it every time,
// looking like a working feature right up until the moment of save.
// Fix: block-style-panel.js now also filters
// `blocks.registerBlockType` and adds `bhStyle: { type: 'object',
// default: {} }` to every block's client-side attribute list, mirroring
// the PHP side exactly. Re-verified end-to-end after the fix: set a
// background-color token on a paragraph block, saved, confirmed
// `{"bhStyle":{"bg.color":"@token:color_accent"}}` in the raw DB row,
// reloaded the editor (value persisted in the panel), and confirmed
// `style="background-color:var(--bh-accent);"` on the real front-end
// `<p>` via the page preview. This IS runtime-verified, on this actual
// install, not reasoned through.
//
// 3.4.81 — BHY_BlockStyle editor-canvas live preview: AJ's own direct
// feedback after the 3.4.80 fix — the panel round-tripped correctly by
// then, but only ever became visible on the real front end; the editor
// canvas itself stayed completely unstyled while editing, so a wrong/
// stale value (bad token name, a preset key that no longer exists)
// wouldn't surface until you actually loaded the page. Added a client-
// side mirror of BHY_Style::resolve_style_value() (assets/js/block-
// style-panel.js: resolvePreviewValue()/bhStyleToPreviewStyle(), built
// entirely from the same bhyBlockStyleSchema payload the panel itself
// already renders from — no second property vocabulary to keep in
// sync) and an `editor.BlockListBlock` filter (the same wrapperProps
// extension point core itself uses for alignment classes) that merges
// the resolved style onto the block's own wrapper element in the
// canvas. Deliberately NOT a security boundary — this only ever feeds
// a React inline style in the logged-in editor; scoped_inline_style()
// server-side remains the sole sanitizer for what reaches real page
// HTML. Approximate for the handful of block types whose own root
// element isn't the wrapper this filter targets; correct for the
// overwhelming majority (paragraphs, headings, groups, images, etc.).
//
// RUNTIME-VERIFIED, with one real bug caught and fixed in the same
// pass: the first version of this filter gated its own registration on
// `wp.blockEditor.BlockListBlock` existing (`if (BlockListBlock) { ... }`)
// — confirmed on this actual install that export is undefined in the
// current WordPress/Gutenberg version, so the guard silently skipped
// `addFilter()` entirely and the feature never fired, no error, nothing
// in the console. `editor.BlockListBlock` is still a real filter name
// Gutenberg's internal (non-exported) block-list renderer applies
// regardless of whether the component is part of the package's public
// surface — addFilter() only needs the NAME, not a reference to the
// component. Removed the guard; confirmed via
// `wp.hooks.hasFilter('editor.BlockListBlock', 'bhy/advanced-styles-
// preview')` returning true after the fix, then confirmed the actual
// DOM: selected a paragraph, set background-color to the "accent"
// token, and read the live editor iframe's own node
// (`style="background-color: var(--bh-accent); ..."`) — no save
// required.
//
// Same pass surfaced one more real, separate, pre-existing gap (not
// caused by this feature, just finally visible because of it): even
// with the preview correctly emitting `var(--bh-accent)` in both the
// canvas and the front end, `--bh-accent` itself resolved to nothing
// anywhere except bh-portal/profile-style pages — confirmed via
// `getComputedStyle(...).getPropertyValue('--bh-accent')` returning ''
// on an ordinary page, in the editor iframe, AND in wp-admin itself.
// `BHY_Style::inline_css()` was only ever echoed by the specific pages
// that already knew to ask for it (class-public-profile.php, class-
// portal.php, the gallery preview) — never site-wide. Fixed by adding
// `BHY_Style::init()` (class-style.php) — a `wp_head` hook (every real
// front-end page) plus a `block_editor_settings_all` filter (the
// editor iframe's own supported raw-CSS injection point, since the
// iframe has its own document and doesn't inherit admin `wp_head`).
// Neither hook disturbs the existing per-page echoes — CSS's own
// last-declaration-wins cascade still lets an entity-specific override
// win exactly as before; this only adds the site DEFAULT that was
// missing everywhere else.
//
// 3.4.82 — UX-AUDIT-2026-07.md's top recommendation: one shared "nothing
// to show" component, BHY_Style::empty_state_html(), fixing a pattern
// found independently on two unrelated plugins (bh-courses' catalog and
// bh-streaming's library both showed a bare one-line "No X found/
// match." with no explanation and no next step, while WooCommerce's own
// default empty state — one plugin away — already does this correctly).
// Self-contained inline SVG icons (not dashicons, which isn't enqueued
// on the front end by default) and a self-contained inline <style>
// block, so this drops into any front-end template with zero extra
// enqueue to remember. RUNTIME-VERIFIED, with two real bugs caught and
// fixed in the same pass, both confirmed live: (1) the icon wrapper is
// a <span> (inline by default) — width/height CSS on a non-replaced
// inline element is simply ignored by the browser, so the icon
// rendered at a huge, uncontrolled size until `display: inline-block`
// was added; (2) the <style> block was originally guarded to print
// only once per request (a `static` flag) — this silently broke the
// moment a JS consumer (bh-streaming's player.js) swapped
// `element.innerHTML` between two different rendered variants on the
// same view, since the second swap destroys whatever was inside that
// container, including a `<style>` tag injected as part of the first
// fragment. Fixed by embedding the style block on every call instead of
// guarding it — a trivial amount of duplicated CSS text against a
// component that only sometimes worked depending on how its caller
// happened to use the markup. Verified on both desktop and mobile
// (375px) viewports for both the zero-data and filtered variants.
//
// 3.4.83 — the actual fix, not just a one-time correction, for a real
// incident this same session: four of this plugin's own bundled
// peer-plugin zips (own-ur-shit/bundled/*.zip) sat stale through an
// entire session's real work, invisible unless someone happened to
// check OUS_Registry::bundled_zip_report() — before this pass,
// OUS_Installer::install()ing any of them would have silently
// overwritten real, current code with a week-old copy, no warning, no
// error, just a confusing "I fixed this, why is it broken again" days
// later. Two halves, "detect" already existed, this adds "fix" and
// "prevent":
//   1. OUS_Registry::regenerate_bundled_zip() — rebuilds a bundled zip
//      directly from the plugin's own live source using ZipArchive
//      (PHP core, no shell-out), writes to a temp file and verifies
//      the rebuilt zip's own version header matches what's actually on
//      disk BEFORE atomically replacing the old zip — never a partial/
//      corrupt write becoming the new "source of truth." A one-click
//      "Regenerate bundled zip" button now sits next to every STALE row
//      in the existing Bundled Zip Freshness report (Debug Tools),
//      wired through the same shared button()/handle-dispatch
//      convention every other Debug Tools action already uses.
//   2. OUS_Installer::install_from_bundle() now refuses outright (a
//      real, surfaced error — "bundle_stale" — not a silent no-op) when
//      a plugin already installed on disk has a HIGHER version than
//      the bundled zip it's about to be reinstalled from. Only a
//      first-time install (nothing on disk yet) is exempt, since
//      there's nothing "stale" about the only copy that exists.
// RUNTIME-VERIFIED end to end on this actual install, not reasoned
// through: artificially staled bh-registry's bundled zip (bumped its
// live version by one patch digit), confirmed the report correctly
// flagged it and showed the new button, clicked "Regenerate bundled
// zip" in the real admin UI, confirmed the rebuilt zip's own version
// header matched and the report went back to "up to date" — then did
// the same for bh-courses' own genuinely-stale zip (real staleness
// from this same session's earlier LMS work, not staged), confirming
// this closes a real, live gap, not just a hypothetical one.
define('OUS_VER', '3.5.4');

// 3.5.4 — Ecosystem-wide "report a technical difficulty" widget, AJ's
// own ask. Reuses the existing BHI_Reports moderation queue (a new
// 'technical' category + the existing bhi/v1/reports REST endpoint)
// instead of standing up a second, parallel admin screen — every other
// report category requires a real target_type+target_id (a piece of
// content to point at); a bug report has none, so rest_submit() now
// allows target_id=0 specifically for the 'technical' category, and
// the admin queue's Target column shows a real label for it instead of
// a bare "technical #0". A small floating "Report a problem" widget
// (wp_footer, logged-in front-end visitors only) auto-captures the
// current page URL and browser UA into the report so whoever triages
// the queue doesn't need a back-and-forth to find out what page/
// browser it happened on. Verified live end-to-end: submitted a real
// report from the front end, confirmed the toast, and confirmed it
// landed in Own Ur Shit → Reports with the correct label/category/
// captured context.

// 3.5.3 — Two more BH_ShareCard styles: 'poster-frame' (centered type,
// bordered inset frame with corner registration-mark ticks) and
// 'poster-block' (a solid color block with a reversed-color eyebrow tag
// and a big single-letter monogram, title continuing onto the dark
// remainder) — genuinely distinct compositions, not recolors of the
// existing diagonal-band 'poster'. New STYLES const is the one place a
// style gets registered/labeled now, so consuming plugins' picker UIs
// read off it instead of each hardcoding their own copy of "which
// styles exist." Verified by rendering both to real PNGs and looking
// at them.

// 3.5.2 — New shared BH_ShareCard engine (includes/class-share-card.php):
// server-side generated (PHP GD, no headless browser/external service)
// 1200x630 social-share PNG cards, two selectable visual styles —
// 'brand' reads the site's live BH_Style palette; 'poster' is a
// deliberately louder, stand-alone look (Bebas Neue on a diagonal
// accent band) independent of whatever theme preset is active. Two new
// vendored OFL-licensed fonts (assets/fonts/: BebasNeue-Regular.ttf,
// WorkSans-Variable.ttf — the latter fetched as Google Fonts' current
// variable-font release since the old static-weight files were removed
// upstream; GD renders its default instance fine, faux-bold via a
// 1px-offset double-draw where a bolder weight is wanted). First
// consumers are bh-courses (course-completion card) and bh-contest
// ("entered"/"vote for me" cards) — this class has zero knowledge of
// either, pure reusable rendering. Caught and fixed two real bugs by
// actually rendering test PNGs and looking at them, not just reading
// the coordinates: the poster style's eyebrow label originally
// overlapped the display-type headline (needed more vertical clearance
// than the eyebrow's own font size suggested), and the bottom-right
// wordmark was rendered in a near-black color that was invisible
// against the near-black background in the one corner the diagonal
// accent band doesn't reach.

// 3.4.87 — QA fix: a full ecosystem-wide re-audit of every hook-timing
// fix claimed this session (both the "nested init callback silently
// never fires" bug class and the "wp_register_script() called too
// early" bug class) found one genuine incomplete-fix regression — the
// 3.4.85 changelog claimed OUS_Gutenberg_Block::init() was fixed
// alongside BH_Event/BH_Identity/OUS_Toast, and the METHOD BODY fix was
// real (init() calls register_block() directly, no nested 'init' hook),
// but the actual call site that invokes init() was never added anywhere
// — the class was still required via this file's own foreach loop, but
// nothing ever called OUS_Gutenberg_Block::init(), so register_block()
// never ran at all. Currently a double no-op either way (its own
// class_exists('BH_Element_Prefab') guard is false post-page-builder-
// delete), but wrong regardless, and would have silently stayed
// unregistered with zero error if that class ever came back. Fixed by
// adding the missing direct call alongside the other three. Caught by
// spawning a follow-up audit specifically instructed to RE-VERIFY every
// claimed fix rather than trust the changelog — the right lesson from
// this: a "fixed" comment in a changelog is a claim, not proof; the
// only thing that proves a fix is checking the actual call site exists
// and runs, which is exactly the gap this incomplete fix slipped
// through. RUNTIME-VERIFIED: booted WordPress with WP_DEBUG_LOG on,
// confirmed zero errors from the new call site.

// 3.4.86 — QA fix, part of an ecosystem-wide sweep for the same
// idempotency/ordering bug classes just caught live in bh-crm's new
// notes-with-reminders feature (this same session). bhcore_notifications
// gained an email_sent column (class-identity-activator.php, DB_VERSION
// 1.12 -> 1.13) and OUS_Notifications::send_queued_email() now claims
// it atomically (UPDATE ... WHERE email_sent = 0) before actually
// mailing — the queued email job can genuinely fire more than once
// (confirmed for the near-identical bh-crm reminder job: a manual test
// call plus Action Scheduler's own real background processing of the
// same scheduled job both ran it), and without this fix that would
// have meant a duplicate email with zero guard against it. A call site
// with no notification_id (an old queued job already pending when a
// site upgrades to this version) just sends once with no dedup, same
// as it always has — not a regression for anything already in flight.
// RUNTIME-VERIFIED end to end: ran the schema migration live, confirmed
// the new column exists, and — the real proof, not just checking a
// flag — hooked pre_wp_mail to COUNT actual send attempts and called
// send_queued_email() three times with the same notification_id,
// confirming exactly one real send happened.

// 3.4.18 — new ecosystem-wide toast notification system: OUS_Toast
// (class-toast.php, new) + assets/js/toast.js + assets/css/toast.css. A
// real, no-build-step, dependency-free BHCoreToast.show(message, type)
// JS renderer (fixed top-right stack, auto-dismiss + manual close,
// role="status"/aria-live="polite"), enqueued globally on both
// admin_enqueue_scripts and wp_enqueue_scripts so any plugin in the
// ecosystem can call it from its own JS with zero setup. For this
// ecosystem's many classic admin-post.php POST+redirect flows (not
// AJAX), OUS_Toast::queue($message, $type) hands a one-shot toast off
// across the redirect via OUS_ReliableStore (NOT transients — this
// install's persistent object cache has repeatedly lost transient
// writes between requests, see class-reliable-store.php's own docblock;
// a "your action saved" toast silently never appearing would be that
// same bug again), keyed per logged-in user (or a short-lived per-guest
// cookie id for logged-out visitors). Wired into: bh-crm's note-save
// redirect (class-notes.php), own-ur-shit's shared Debug Tools button
// dispatch (class-debug.php — covers "Run due jobs now" and every other
// registered debug action) and OUS_Jobs' Action Scheduler installer
// (class-jobs.php); bh-courses' AJAX mark-step-complete handler gets
// BHCoreToast.show() called directly from its JS success/failure
// callback instead (no redirect to hand off across). bh-contest's vote
// flow already has its own working toast() implementation
// (assets/js/player.js) and was deliberately left alone rather than
// duplicated. See EVENT-TRACKING-ARCHITECTURE-PLAN.md's BH_Event for the
// pre-existing event layer this supplements — toasts are UI feedback,
// additive only, no existing WordPress admin notice was removed or
// replaced anywhere in this pass. Standing caveat: reasoning/brace-
// balance-checked only, no live PHP+MySQL or browser execution in this
// pass — not runtime-verified. Please click bh-crm's "Save notes" (the
// simplest wired action) and confirm a toast appears once, then does NOT
// repeat on a plain refresh.

// 3.4.17 — added BH_Identity::client_ids_for_user() (class-identity.php),
// a reverse lookup from user_id to the distinct client_id values
// already stamped on that user's own bhcore_events rows (there is no
// separate stitching table — see that class's docblock for why this
// is NOT a join against dedicated storage). Added to support bh-crm's
// new event-activity consumer (bh-crm/includes/class-event-activity.php,
// bh-crm 1.0.0 -> 1.1.0), which was wired to bh_crm_activity_summary
// this pass. Also broadened class-event.php's Debug Tools "Event
// Tracking" section with a 7-day per-type-per-day breakdown and a
// top-active-users list, alongside the pre-existing 7-day per-type
// count table. Standing caveat: reasoning/brace-balance-checked only,
// no live PHP+MySQL execution in this pass — not runtime-verified.

// 3.4.16 — hardened OUS_Jobs::handle_install_action_scheduler() (the
// "Install Action Scheduler" Debug Tools button, which a user reported
// did nothing when clicked): WP_Filesystem() and wp_mkdir_p() return
// values are now checked instead of ignored, download_url()'s full
// WP_Error message is surfaced verbatim (was already true before, kept
// so on purpose — helps diagnose Local-by-Flywheel's known outbound-SSL
// quirks), and success is no longer declared unless
// file_exists(OUS_Jobs::vendor_path()) is genuinely true after the
// move. Also added OUS_Dashboard's new Job Queue + Query Monitor status
// block (class-dashboard.php render()) and the new BH_Event/BH_Identity
// event-tracking layer (class-event.php, class-identity.php — see
// EVENT-TRACKING-ARCHITECTURE-PLAN.md, previously designed but never
// implemented) wired into the require-loop/init hooks below. Standing
// caveat: reasoning/brace-balance-checked only, no live PHP+MySQL
// execution in this pass — not runtime-verified.

// superseded — kept only so a stray duplicate define() below this point
// (a recurring mistake this session) is easy to spot if it recurs:
// 3.4.4 — new OUS_ReliabilityTestSuite (class-reliability-test-suite.php),
// the first test coverage for OUS_ReliableStore and
// OUS_DebugLog::log_throttled() — both previously untested despite now
// being load-bearing (BHI_Auth's security throttles, the whole
// diagnostic-logging pipeline this session built out). Runs against the
// real options table with tagged/prefixed keys, cleaned up at the end
// of every run. Standing caveat: written and brace-balance-checked, but
// never actually executed — the Test Runner itself needs to be clicked
// on the live install to confirm these pass for real.

// 3.4.3 — continuation logging pass (per audit): BHI_Auth::register()'s
// wp_create_user() failure now logs the real WP_Error instead of
// discarding it. Standing caveat: reasoning/brace-balance-checked only.

// 3.4.2 — Portal's /account/ 404 is still unresolved on the live
// install per direct user report (rewrite rule confirmed missing every
// reload, but ZERO Portal log entries at all — not even the throttled
// "still broken" warning that should have fired at least once by now).
// Per explicit user direction, NOT chasing this further right now (it's
// not blocking other work) and NOT treating BHI_Portal's fix as a
// working reference elsewhere — but added one cheap, always-throttled
// diagnostic breadcrumb at the very top of add_rewrite() so the next
// person looking at this (me or the user) can tell in one page load
// whether the method is even being entered, rather than re-deriving
// that from scratch. See class-portal.php's own comment at that line.

// 3.4.1 — Debug Tools sections are now real <details>/<summary>
// collapsibles, closed by default (the page is long enough with a
// dozen-plus registered sections that scrolling past all of them to
// find one is real friction), with each section's open/closed state
// remembered per-browser via localStorage so it doesn't reset every
// page load. Deliberately localStorage, not a server-side per-user
// option — this is cosmetic UI state, not anything that needs to survive
// across devices or matters if lost, and per this session's whole
// object-cache saga, sidestepping server-side persistence entirely for
// something this low-stakes is the more robust choice on an install
// whose cache layer has already proven unreliable more than once. A
// section reached via the quick-nav/redirect anchor force-opens
// regardless of its stored state — landing on your test results while
// the section hiding them stays closed would defeat the whole point.

// 3.4.0 — a real, live-reported bug ("nothing is displayed with the
// tests") traced to the same root cause as this whole session's Portal/
// API-Docs saga: set_transient()/get_transient() are backed entirely by
// this install's persistent object cache when one is active, and that
// cache is unreliable here — a transient write can report success while
// the very next request's read sees nothing. New class-reliable-store.php
// (OUS_ReliableStore) consolidates the direct-DB-bypass-the-cache
// pattern this session kept hand-rolling ad-hoc (BHI_Portal's throttle,
// OUS_TestRunner's first fix) into one shared, documented utility.
// OUS_TestRunner's report storage now uses it (fixes the reported bug
// directly). More importantly: BHI_Auth's login-fail lockout and
// registration-rate-limit counters ALSO used plain transients — on this
// install, that meant those SECURITY throttles could silently fail
// open, not just show a UX glitch. Both now go through OUS_ReliableStore
// too. NOTE per explicit user instruction: BHI_Portal's own rewrite-rule
// fix is NOT being treated as a proven-working reference for this pass
// — the user reports Portal still isn't fully working, so this fix
// stands on its own reasoning, not on "the same pattern that fixed
// Portal" (which hasn't been confirmed fixed). Standing caveat
// unchanged: reasoning/brace-balance-checked only.

// 3.3.9 — two things: (1) real bug found in 3.3.8's own anchor-scroll
// fix — the sticky admin bar + this page's own sticky quick-nav both
// cover the top of the viewport, so a native browser anchor-jump landed
// the target section's heading BEHIND them, which looked identical to
// "still stuck at the top" (exactly what got reported after 3.3.8
// shipped). Fixed with scroll-margin-top on every section plus a JS
// scrollIntoView + brief highlight flash as a second, independent
// safety net. (2) Added BHI_Profiles::user_ids_with_profile_data() per
// QA-REPORT-code-quality.md's cross-plugin finding #2 — bh-crm's
// class-people.php and class-export.php both ran identical raw SQL
// against this table directly instead of through the class that owns
// it; a pure extraction, no behavior change.

// 3.3.8 — Debug Tools page UX fix (explicit user report: running a test
// or clicking any button jumped back to the page TOP instead of staying
// near the result, and the page is long enough that this meant
// re-scrolling every single time). OUS_Debug::redirect() now carries a
// per-section anchor (every section already has/gained a stable
// 'ous-section-{key}' id) so a button click lands you back exactly where
// you clicked from — results were already rendered colocated inside
// their own section (Test Runner's own transient-backed report, e.g.),
// the only missing piece was the redirect itself dropping the anchor.
// Also added a sticky "Jump to:" quick-nav bar so the long-scrolling
// problem has a second, independent fix (jump anywhere in one click)
// beyond just landing correctly after an action.

// 3.3.7 — request-correlation IDs shipped end to end: bhcore_debug_log
// gained a request_id column (BHI_Activator::DB_VERSION 1.6 -> 1.7),
// OUS_DebugLog::request_id() generates one short ID per PHP request and
// stamps it onto every log() call automatically (no call-site changes
// needed anywhere in the ecosystem), and Console & Logs gained a
// Request ID filter plus a clickable chip on every row that jumps
// straight to "everything else that happened during this exact
// request." Degrades safely on an install that hasn't migrated yet
// (has_request_id_column() checks the live schema, not just the stored
// DB_VERSION, before including the column in any insert — a not-yet-
// migrated install keeps logging, just without correlation IDs, rather
// than every log() call failing on an unknown-column error).

// 3.3.6 — first slice of a deliberately larger, ongoing logging-depth
// push (explicit user direction: debugging/logging needs to be "airtight"
// across the whole ecosystem, not just the Portal/API Docs incident that
// started this). This pass: BHI_Two_Factor::ajax_disable() now logs a
// security-relevant account change (2FA disabled) that previously left
// zero audit trail; BHI_Two_Factor::gate_login() now logs a real wrong-
// code attempt (throttled per-user), previously invisible. See
// class-debug-log.php for this same pass's bigger addition: per-request
// correlation IDs, so scattered log entries from one failing request can
// finally be traced together instead of read as isolated, unrelated rows.

// 3.3.5 — closes the real diagnostic gap the 3.3.3/3.3.4 back-and-forth
// exposed: both fixes only logged on FAILURE, so an empty Console & Logs
// table was ambiguous between "checked every request and genuinely
// fine" and "stopped running/self-healing entirely" — precisely the
// state the 3.3.4 throttle bug produced and that made it undiagnosable
// from log data alone. Added OUS_DebugLog::log_throttled() (logs at
// most once per N seconds per key, regardless of outcome) and wired it
// into OUS_Debug::is_locked() and BHI_Portal::add_rewrite() so a
// PASSING check now also leaves a periodic trace, and a check that's
// sitting out a throttle window while still broken logs THAT state
// explicitly (at 'warning') instead of silently doing nothing. "No log
// entries for this key in the last several minutes" is now itself a
// real, actionable signal — the check isn't running at all — rather
// than an empty table meaning nothing in particular.
// (see class-debug-log.php's own docblock for log_throttled() usage —
// intended for any check that runs on every request across this
// ecosystem, not just these two.)

// 3.3.4 — real bug found in 3.3.3's own fix: BHI_Portal's rewrite
// self-heal throttle used get_transient()/set_transient(), which on an
// install with a persistent object cache active stores the transient IN
// that same cache — exactly the layer this whole fix exists to not
// trust. A stuck/broken cache could make the throttle read "already
// attempted" forever, silently skipping the self-heal on every request
// with zero log trace, which is indistinguishable from "working, just
// waiting" from the outside. Confirmed as the live symptom on the
// user's install (rewrite rule still missing after multiple reloads,
// zero matching OUS_DebugLog entries). Replaced with a direct,
// cache-bypassing DB read/write for the throttle timestamp — same
// technique the persistence check itself already used. Standing
// caveat unchanged: still no network path to the live install to
// confirm this against the real, reported failure.

// 3.3.3 — fixed the real reported bug: BHI_Portal's /account/ 404 and
// API Docs' "not allowed to access this page" both came from
// user-facing symptoms of the SAME underlying pattern (a persistent
// object cache serving stale option reads across requests — confirmed
// on this specific install via each class's own Debug Tools
// diagnostic). Both previously relied on a one-shot "did this already
// run" flag that could mark itself successful without the write
// actually having persisted, requiring a manual Settings -> Permalinks
// -> Save to fix. Replaced with self-verifying checks that read
// straight from the database (bypassing the object cache layer
// entirely) on every request: BHI_Portal::add_rewrite() now re-flushes
// and re-verifies (throttled to once per 60s) until the rewrite rule
// is confirmed actually persisted, not just attempted; OUS_Debug::is_locked()
// gained a third, cache-bypassing host check alongside its existing
// raw-HTTP_HOST and home_url() checks. Both log via OUS_DebugLog when
// they self-heal AND when they still fail after a real attempt, so a
// still-broken install after this fix points at something genuinely
// outside WordPress's own caching layer (reverse proxy/CDN, read-only
// DB, multisite domain mapping) instead of requiring another guess.
// Standing caveat: reasoning-checked and brace-balance-checked only —
// the user has WordPress running and reported these bugs live, but I
// still have no network path to their install from this sandbox, so
// this fix itself has not been confirmed against the real, reported
// failure yet.
define('OUS_PATH', plugin_dir_path(__FILE__));
define('OUS_URL',  plugin_dir_url(__FILE__));

// The one canonical signal a dependent plugin (bh-contest, bh-streaming,
// or anything built later) checks for — a plain constant rather than a
// specific class name, so which internal classes this plugin happens to
// contain can change later without quietly breaking every dependent's
// "is my dependency active" check.
define('BHCORE_LOADED', true);

/**
 * As of version 3.0.0, this plugin absorbed what used to be two separate
 * plugins — BH Identity (accounts/profiles/auth) and BH Style (design
 * tokens + the gallery) — into this one, alongside the hub/dashboard
 * role it already had. Class names (BHI_*, BHY_*) are unchanged from
 * when they were separate plugins specifically so nothing in bh-contest
 * or bh-streaming's actual feature code needed to change — only their
 * own bootstrap's dependency check does (see bh-contest.php /
 * bh-streaming.php for the other half of that).
 *
 * Reasoning for the merge, for whoever finds this later: running
 * identity and style as separate plugins meant every dependent plugin
 * had to defend against PHP's alphabetical plugin-load order — a real,
 * demonstrated source of bugs (a dependency check succeeding or failing
 * depending on which letter a folder name happened to start with). One
 * base plugin removes that whole class of problem for the pieces that
 * are, in practice, always installed together anyway. Contest and
 * Streaming stay genuinely separate — someone who only wants one of
 * them shouldn't have to install the other.
 */
foreach (['registry', 'dashboard', 'installer', 'activation-manager', 'banner', 'menu-merge', 'debug', 'debug-log', 'qm-integration', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'audit', 'admin-layout', 'content', 'commerce', 'portal', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'element-data', 'element', 'element-test-suite', 'design-suite', 'gutenberg-block', 'block-style', 'share-card'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
register_activation_hook(__FILE__, ['OUS_Audit', 'activate']);
register_deactivation_hook(__FILE__, function () {
    // Only the cron schedule this plugin itself created — never touches
    // any other plugin's scheduled events, and the job queue TABLE (and
    // anything still pending in it) is left completely alone, so
    // reactivating later picks up right where it left off.
    $timestamp = wp_next_scheduled(OUS_Jobs::CRON_HOOK);
    if ($timestamp) wp_unschedule_event($timestamp, OUS_Jobs::CRON_HOOK);
});
add_action('plugins_loaded', ['BHI_Activator', 'maybe_upgrade']);
add_action('init',          ['BHI_Auth', 'init']);
add_action('rest_api_init', ['BHI_Auth', 'register_routes']);
add_action('init',          ['BHI_PublicProfile', 'init']);
add_action('init',          ['BHI_Reports', 'init']);
add_action('rest_api_init', ['BHI_Reports', 'register_routes']);
add_action('init',          ['BHI_TwoFactor', 'init']);

add_filter('cron_schedules', ['OUS_Jobs', 'register_cron_schedule']);
// QA fix, 3.4.85: OUS_Jobs::init()/OUS_Notifications::init() both
// internally register a SECOND add_action('init', ...) of their own
// (ActionScheduler::init() at priority 1, register_shortcode() at
// default priority 10, an anonymous job-handler registrant at default
// priority 10) — but since these two init() methods were THEMSELVES
// only ever invoked as 'init' hook callbacks, that inner registration
// happened WHILE 'init' was already executing, and WordPress's WP_Hook
// never revisits an already-passed (or, for priority 1, never-reached-
// because-lower-than-the-currently-executing-bucket) priority in the
// same pass — confirmed directly against a minimal WP_Hook
// reproduction, not assumed. The result: ActionScheduler never
// bootstrapped, the [bh_notifications] shortcode never registered, and
// the queued-email job handler never wired up, silently, on every real
// request, with zero error anywhere. Fixed by calling ::init() directly
// here instead of deferring through another 'init' hook layer — this
// file's own top-level statements already run well before 'init' ever
// fires (WordPress finishes loading every active plugin's main file
// before firing plugins_loaded, which itself fires before init), so
// every inner add_action('init', ..., $priority) call these two
// classes make now registers in plenty of time to fire correctly,
// in proper priority order, during the one real 'init' pass. See
// class-jobs.php's/class-notifications.php's own init() docblocks for
// what each individually-nested registration is for.
OUS_Jobs::init();
OUS_Notifications::init();
add_action('init',          ['OUS_Roles', 'init']);
add_action('init',          ['OUS_Audit', 'init']);
add_action('init',          ['OUS_AdminLayout', 'init']);
add_action('init',          ['OUS_DebugLog', 'init']);
add_action('init',          ['OUS_QM_Integration', 'init']);
add_action('init',          ['OUS_TestRunner', 'init']);
add_action('init',          ['OUS_CoreTestSuite', 'init']);
add_action('init',          ['OUS_ReliabilityTestSuite', 'init']);
// New this pass (3.4.51 QA/testing follow-up) — see class-element-test-
// suite.php's own docblock for why: three real bugs in this exact layer
// were only caught by live screenshots tonight, one after another, each
// a class of mistake a cheap deterministic assertion would have caught
// immediately. class_exists() guard mirrors every other test suite's
// registration here — BH_Element itself is always loaded before this
// fires (require order above), but the guard costs nothing and matches
// convention.
if (class_exists('BH_Element')) add_action('init', ['BH_Element_TestSuite', 'init']);
// BH_Studio's own init() registers this pass's default block types with
// BH_Content — must fire after 'content' (BH_Content itself) has loaded,
// which own-ur-shit.php's require order above already guarantees, and
// after (or during) the same 'init' hook everything else here uses, so
// no separate hook priority juggling is needed.
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);
add_action('init',          ['OUS_CodebaseDocs', 'init']);
// QA fix, 3.4.85: same nested-'init' bug as OUS_Jobs/OUS_Notifications
// above (see that comment for the full explanation) — BH_Event::init()
// nests a job-handler registrant at priority 5, BH_Identity::init()
// nests maybe_issue_cookie() at priority 1, OUS_Toast::init() nests
// maybe_set_guest_cookie() at priority 1. All three were silently dead:
// a guest's first-touch identity/consent cookie never actually got
// issued, and a toast queued for a not-yet-cookied guest never
// persisted to their next request. Fixed the same way — call ::init()
// directly at this top-level point (well before 'init' fires) instead
// of through another 'init' hook layer.
BH_Event::init();
BH_Identity::init();
OUS_Toast::init();
// QA fix, 3.4.87: the 3.4.85 changelog claimed OUS_Gutenberg_Block::
// init() was fixed alongside the four classes just above — the fix
// itself (class-gutenberg-block.php's init() calling register_block()
// directly, no nested 'init' hook) WAS real, but the actual call site
// wiring it up was never added anywhere — a genuine incomplete-fix
// regression, caught by a follow-up audit specifically re-verifying
// every claimed fix rather than trusting the changelog. Currently a
// double no-op either way (register_block()'s own class_exists(
// 'BH_Element_Prefab') guard is false post-page-builder-delete), but
// wrong regardless, and would have silently stayed unregistered with
// zero error if that class ever came back.
OUS_Gutenberg_Block::init();
// Element builder (ELEMENT-BUILDER-DESIGN-PLAN.md) — BH_Element_Data
// before BH_Element purely for readability (registers the data
// sources before the element types that might reference them by
// slug); neither init() actually depends on load order since both
// only populate their own private in-memory registries on this same
// 'init' hook, read later by BH_Element::render_slot() at render time.
add_action('init',          ['BH_Element_Data', 'init']);
add_action('init',          ['BH_Element', 'init']);
// 3.4.78 follow-up — BHY_BlockStyle (class-block-style.php): the
// generic "Advanced Styles" InspectorControls panel added to every
// native block, AJ's own explicit ask not to lose the builder-era CSS-
// properties/databinding capability when its bespoke inspector was
// deleted. Hooks 'register_block_type_args'/'enqueue_block_editor_
// assets'/'render_block' directly (not gated behind a class_exists()
// guard the way peer-plugin touches are — this only touches WordPress
// core's own block registration and rendering, own-ur-shit's own
// BHY_Style, nothing optional).
add_action('init',          ['BHY_BlockStyle', 'init']);
// 3.4.81 follow-up — BHY_Style::init() (class-style.php): global
// wp_head/block_editor_settings_all token hooks, direct response to the
// gap the BHY_BlockStyle editor-canvas preview work above just exposed
// (see BHY_Style::init()'s own docblock) — --bh-* custom properties
// were only ever available on pages that already knew to echo
// inline_css() themselves (public profile/portal pages), never site-
// wide, so a token-based color set anywhere else produced a real but
// inert CSS declaration.
add_action('init',          ['BHY_Style', 'init']);
// PAGE-BUILDER-DELETE-KEEP-AUDIT.md (2026-07-13) — real, live-verified
// cleanup, not a guess: BH_Element/BH_Element_Data (the data model +
// render_slot() engine, immediately above) are confirmed LIVE — real
// pages in bh-contest, bh-crm, bh-courses, own-ur-shit's own dashboard/
// portal all render through render_slot() today. Everything that used
// to sit ON TOP of that engine as a custom hand-rolled authoring UI is
// gone as of this pass: BH_Element_Prefab (class-element-prefab.php —
// the custom Components/linked-instance/override system; WordPress's
// own native synced Patterns do this job directly), BH_Element_State
// (class-element-state.php — fixture states/Storybook-style preview
// contexts; confirmed ZERO consumers anywhere outside the now-deleted
// builder UI), BH_Element_Builder (class-element-builder.php — the
// enqueue/localize glue for the equally-deleted assets/js/element-
// builder.js canvas), and BH_Component_Studio (class-component-
// studio.php — this SAME session's own first attempt at a smaller
// replacement, held to the identical standard rather than protected
// from it: a bespoke per-Component HTML/CSS/JS block is still a bespoke
// editing mechanism where typed, native Gutenberg block types with real
// render_callback()s are simpler and more idiomatic). All four files
// (plus assets/js/element-builder.js, assets/css/element-builder.css,
// assets/js/component-studio.js, assets/css/component-studio.css) are
// deleted, not just unhooked — PAGE-BUILDER-DELETE-KEEP-AUDIT.md's own
// table has the full file-by-file reasoning and line counts.
//
// OUS_Gutenberg_Block (class-gutenberg-block.php) is DELIBERATELY LEFT
// IN PLACE, unlike the others — its own register_block() already guards
// on class_exists('BH_Element_Prefab') (true before this pass, false
// after), so it now silently no-ops instead of registering its embed
// block, the same "harmless no-op" posture every other optional
// integration in this ecosystem already uses. This is intentionally
// NOT a hard delete: the audit couldn't confirm from code alone whether
// any real published post actually embeds the 'own-ur-shit/element-
// prefab' block (that needs a real `post_content LIKE` query against
// the live database, not available in this environment) — if a real
// post out there uses it, this now renders nothing instead of fataling,
// which is the safe failure mode until that check happens.
add_filter('bh_element_surfaces', ['OUS_Dashboard', 'register_element_surface']);
// ELEMENT-BUILDER-DESIGN-PLAN.md §5.4 — Portal as a real bh_element_surfaces
// contributor, mirroring OUS_Dashboard's/BHCRM_People's own registration
// line here exactly. BHI_Portal::init() (called via the 'init' hook
// object-registered elsewhere in this same file, see BHY_Gallery/etc.
// below) separately hooks its own 'bhi_portal_panels' registrant for the
// one new element-composed panel this phase ships — see class-portal.php.
add_filter('bh_element_surfaces', ['BHI_Portal', 'register_element_surface']);

add_action('init', ['BHY_Gallery', 'init']);
add_action('init', ['BHY_UI', 'init_shared_admin_assets']);
BHY_UI::pin_hidden_submenus_to_bottom();

/**
 * Hub role: unchanged in spirit, reduced in scope now that identity and
 * style aren't separate installable things anymore — the registry only
 * needs to track bh-contest and bh-streaming from here on.
 */
add_action('admin_menu',    ['OUS_Dashboard', 'add_menu']);
// DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 — registered directly here
// (not deferred to the 'init' hook the way BHY_Gallery/BH_Studio's own
// add_menu() calls are) so it lands in the 'admin_menu' callback queue
// BEFORE those two plugins' own init()-hooked registrations, and well
// before OUS_MenuMerge's relocation pass at priority 999 — the top-level
// parent must exist before anything tries to attach a submenu to it
// (§1.2's sequencing hazard note). Same direct-registration style
// OUS_Dashboard::add_menu() uses immediately above.
add_action('admin_menu',    ['BH_Design_Suite', 'add_menu']);
add_action('init',          ['OUS_MenuMerge', 'init']);
add_action('init',          ['OUS_Debug', 'init']);
add_filter('ous_debug_tools', ['OUS_Registry', 'register_debug_section']);
add_action('admin_post_ous_activate', ['OUS_Dashboard', 'handle_activate']);
add_action('admin_post_ous_activate_all', ['OUS_Dashboard', 'handle_activate_all']);
add_action('admin_post_ous_activate_file', ['OUS_Dashboard', 'handle_activate_file']);
add_action('admin_post_ous_install',  ['OUS_Dashboard', 'handle_install']);
add_action('init',          ['OUS_Banner', 'init']);
add_action('admin_head',    ['OUS_Banner', 'maybe_print']);
add_action('admin_enqueue_scripts', ['OUS_Dashboard', 'enqueue_assets']);

/**
 * New cross-cutting interfaces (ROADMAP-platform-evolution.md Section 2/6):
 * BH_Content (content-block interface), BH_Commerce (commerce interface,
 * WooCommerce-backed today), BHI_Portal (the custom user-facing account
 * shell + wp-admin exclusion rollout). All three use the plain `BH_`/`BHI_`
 * prefixes already established for this ecosystem's shared, foundational
 * pieces — see each class's own docblock for the full contract.
 */
add_action('init', ['BH_Content', 'init']);
add_action('init', ['BHI_Portal', 'init']);
register_activation_hook(__FILE__, function () {
    // BHI_Portal::add_rewrite() also runs on every 'init', but the
    // rewrite rule needs an explicit flush once so /account/ resolves
    // immediately on activation rather than waiting for WordPress's own
    // rewrite cache to naturally regenerate.
    BHI_Portal::add_rewrite();
    flush_rewrite_rules();
});
