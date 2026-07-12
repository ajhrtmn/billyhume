<?php
/**
 * Plugin Name: Own Ur Shit
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.4.45
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

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
define('OUS_VER', '3.4.45');

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
foreach (['registry', 'dashboard', 'installer', 'activation-manager', 'banner', 'menu-merge', 'debug', 'debug-log', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'content', 'commerce', 'portal', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'element-data', 'element', 'element-prefab', 'element-builder', 'design-suite'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
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
add_action('init',          ['OUS_Jobs', 'init']);
add_action('init',          ['OUS_Notifications', 'init']);
add_action('init',          ['OUS_Roles', 'init']);
add_action('init',          ['OUS_DebugLog', 'init']);
add_action('init',          ['OUS_TestRunner', 'init']);
add_action('init',          ['OUS_CoreTestSuite', 'init']);
add_action('init',          ['OUS_ReliabilityTestSuite', 'init']);
// BH_Studio's own init() registers this pass's default block types with
// BH_Content — must fire after 'content' (BH_Content itself) has loaded,
// which own-ur-shit.php's require order above already guarantees, and
// after (or during) the same 'init' hook everything else here uses, so
// no separate hook priority juggling is needed.
add_action('init',          ['BH_Studio', 'init']);
add_action('init',          ['OUS_StudioTestSuite', 'init']);
add_action('init',          ['OUS_ApiDocs', 'init']);
add_action('init',          ['OUS_CodebaseDocs', 'init']);
add_action('init',          ['BH_Event', 'init']);
add_action('init',          ['BH_Identity', 'init']);
add_action('init',          ['OUS_Toast', 'init']);
// Element builder (ELEMENT-BUILDER-DESIGN-PLAN.md) — BH_Element_Data
// before BH_Element purely for readability (registers the data
// sources before the element types that might reference them by
// slug); neither init() actually depends on load order since both
// only populate their own private in-memory registries on this same
// 'init' hook, read later by BH_Element::render_slot() at render time.
add_action('init',          ['BH_Element_Data', 'init']);
add_action('init',          ['BH_Element', 'init']);
// The prefab system (a genuine addition beyond ELEMENT-BUILDER-DESIGN-
// PLAN.md's own scope — see that doc's trailing status note and class-
// element-prefab.php's own docblock) — registers ONLY the ous/v1/elements/
// prefabs/* REST routes; no admin_menu/Debug Tools section of its own,
// its UI is additive controls inside BH_Element_Builder (§ this pass).
add_action('init',          ['BH_Element_Prefab', 'init']);
// The Phase-4 visual builder GUI (ELEMENT-BUILDER-DESIGN-PLAN.md §4) —
// its BH_Element/BH_Element_Data REST-driven logic and localize-config
// still need 'init', unchanged. BH_Element's own existing bare-list
// Debug Tools section ('bh-element' key) is untouched — see
// class-element-builder.php's docblock for the full reasoning.
add_action('init',          ['BH_Element_Builder', 'init']);
// 3.4.30 — DESIGN-SUITE-UNIFICATION-PLAN.md real structural fix: the
// add_action('admin_menu', ['BH_Element_Builder', 'add_menu']) line that
// used to live here is REMOVED. This GUI no longer registers its own
// 'bh-element-builder' submenu of 'bh-design' — it's now a tab inside
// BHY_Gallery's one 'bh-style' page (BH_Element_Builder::render_shell(),
// called directly from BHY_Gallery::render(); BH_Element_Builder::
// add_menu() itself is left fully defined in class-element-builder.php,
// just unhooked, same "leave it, don't delete it" posture as every other
// retired access point in this ecosystem).
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
