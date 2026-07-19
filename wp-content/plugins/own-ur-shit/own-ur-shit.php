<?php
/**
 * Plugin Name: Own Ur Shit
 * Description: The ecosystem core — shared accounts/profiles (with public profile pages), shared design tokens with a Storybook-patterned live preview gallery, a shared reports/moderation queue, and one dashboard for installing/activating everything else. The single required base; BH Contest and BH Streaming are separate feature plugins that depend on this one.
 * Version:     3.7.5
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 3.5.1 — a shared, opt-in wide-layout fix for custom post-edit screens, AJ's
// own ask after looking at the contest edit screen specifically: "many admin
// pages suffer from the same issues." New OUS_AdminLayout (class-admin-
// layout.php): several post types in this ecosystem (bh_contest, bh_submission,
// bhm_tier, bh_course) were still on WordPress's default two-column post-edit
// chrome — one narrow stacked column of meta boxes plus a fixed ~280px sidebar —
// wasting real horizontal space on a wide screen. Confirmed live: the sidebar's
// own "Contest Rules & Results" box was visibly overflowing its column with real
// content, while the main column sat mostly empty beside it.

// 3.5.0 — new accountability audit log, AJ's own ask: "audit, do everything
// important, and anything those important things touch... yes granular diffs...
// admin only." New OUS_Audit (class-audit.php): a synchronous accountability log
// (bhcore_audit_log) distinct from BH_Event's per-person ACTIVITY timeline —
// that answers "what did this person do," this answers "who changed WHAT to WHAT
// on a thing that isn't necessarily their own" (a tier's price, a segment
// someone else built, another user's role). log_diff() stores granular
// before/after field diffs; log() covers plain "X happened" actions (deletions,
// rejections). require_cap() is a drop-in for the `if (!current_user_can($cap))
// wp_die(...)` pattern used everywhere in this ecosystem's admin-post handlers —
// it does NOT log every single denial (pure noise), only once a per-user denial
// count crosses a concerning threshold within a short window (AJ's own "log
// denies and fails if they exceed a concerning amount"). Also hooks WordPress's
// own set_user_role action, so granting/revoking any role (including the new
// Studio Manager) from the Users screen is tracked for free, no bespoke UI
// needed.

// 3.4.91 — debug-log wiring pass (AJ's own ask: "wire any of those new events
// into the debug log... that would be useful and helpful for future dev").
// Rather than mirroring every routine BH_Event emission into OUS_DebugLog
// (that's what the activity timeline is for),
// OUS_Notifications::send_email_now() now logs a warning specifically when
// wp_mail() returns false — previously a queued notification email failing to
// send was completely silent, with nothing anywhere telling a dev it happened.

// 3.4.90 — permissions audit follow-through (AJ's own ask: "audit user roles and
// permissions... admins and site managers should have access to a good chunk of
// this... user-owned relationships where admin sees all might be a little more
// restrictive to non-admin managers"). A prior background audit found: no custom
// role existed at all (only capability grants on the built-in 'editor' role),
// and bhcore_manage_ crm gated a person's phone number/wallet balance/purchase
// history/ refund-fraud flags identically to the plain person list — no split
// between "can see the roster" and "can see private/financial data." New:
// OUS_Roles::MANAGER_ROLE ('bhcore_studio_manager', label "Studio Manager") —
// this ecosystem's FIRST real custom WordPress role (register_role(), not just a
// capability grant on an existing role), cloned from editor's own capability set
// at registration time so it can manage bh_contest/bh_course/bh_lesson content
// (all use the default 'post' capability_type) plus bhcore_design_site/
// bhcore_manage_crm.

// 3.4.89 — real bug, caught live while wiring more emitters into the CRM's
// unified activity timeline (bh-crm 1.9.0's own changelog):
// BH_Event::handle_ingest_job()'s INSERT used $wpdb->prepare()'s %s placeholder
// for dedup_key, which silently casts a PHP null to an empty string, not SQL
// NULL. dedup_key carries a UNIQUE key, so EVERY event emitted without an
// explicit dedup_key (the common, "append-only" case — most emit() call sites
// across this whole ecosystem) collided with the very first such row ever
// inserted and was silently dropped by INSERT IGNORE, ever since this table
// existed. Confirmed directly against the live table: dedup_key was stored as ''
// rather than NULL, and only ONE non-deduped event had ever actually landed.

// 3.4.88 — portal styling QA pass, AJ's "wrap up the CRM, then make sure styles
// look sleek and professional on desktop and mobile, not clunky/cramped"
// request. Three real bugs found and fixed against the live front-end portal
// (/account/): 1. class-portal.php's own inline <style> block referenced a
// fictional, never-defined token scheme (--bhy-color-bg etc) that doesn't exist
// anywhere in this codebase — every declaration silently fell through to
// hardcoded generic-WP-blue fallbacks, so the portal NEVER showed the real site
// brand (warm cream/ terracotta, --bh-* tokens from class-style.php) on any
// load, ever.

// 3.4.85 — real bug sweep, not a feature pass: while building bh- monetization-
// woo's first ServerSideRender block this session, hit a confirmed WordPress bug
// pattern — a class's own init() method, itself only ever invoked AS an 'init'
// hook callback, was internally registering a SECOND add_action('init', ...) of
// its own. Since WP_Hook never revisits a priority bucket it has already passed
// in the same request, that inner registration silently never fires, ever, with
// zero error anywhere — confirmed directly against a minimal WP_Hook
// reproduction, not assumed.

// 3.4.84 — vendor/fpdf/fpdf.php was committed on its own in the previous pass
// without the font metric files (font/*.json) FPDF's core fonts (Helvetica,
// Times, Courier) actually load at render time — a gap only surfaced once bh-
// courses' new certificate-of-completion feature (class-certificates.php) tried
// to actually render a PDF and hit "file_get_contents(.../font/helvetica.json):
// Failed to open stream" live, caught via a temporary WP_DEBUG_LOG flip. Fixed
// by vendoring the four Helvetica metric files (helvetica.json/b/i/bi.json) from
// the same upstream (setasign/fpdf) fpdf.php itself was pulled from — RUNTIME-
// VERIFIED end to end on this install: generated a real single-page PDF via
// BHC_Certificates against a real course/user/completion row, the output file
// identified as a genuine "PDF document, version 1.3.".

// 3.4.71 — 2026-07-12 — three more rounds of direct live feedback, all addressed
// in one pass: (1) "bloated, poorly proportioned... good gaps/ padding/margins"
// + "all three need to feel cohesive" — the Library rail's list rows now reuse
// .bhy-rail-item/.bhy-rail-subheading VERBATIM (the exact classes Live Views'
// own story-button list already uses in the same rail) instead of a parallel
// bhds-library-item class with slightly different numbers; the canvas
// toolbar/state-strip/Controls panel were re-measured against tokens already
// used elsewhere in this rail (7px/14px row padding, 11px uppercase headings)
// instead of inventing a new scale; the background-toggle went from three
// separately -bordered boxes to one connected segmented control; canvas
// padding/min- height reduced; the Controls panel heading now reuses .bhy-
// controls h3 verbatim. (2) "This is kinda my dream" — a real Storybook
// screenshot showing NESTED, disclosure-triangle story trees (states nested
// inside their component, not a separate tab strip) and a SOLID PILL selected-
// row highlight, not a left-border tint. Named fixture states are now tree rows
// nested under their Component/Primitive (renderNestedStates()), disclosure-
// triangle expandable, lazily fetched and cached per item; the separate state-
// tab strip above the canvas is GONE (its markup, CSS, and
// renderStateTabs()/loadStates() are removed outright) — the canvas toolbar is
// now purely the light/dark/grid background toggle, matching what that position
// actually is in real Storybook.

// 3.4.77 — 2026-07-12 — REAL, LIVE-CONFIRMED BUG FIX: 3.4.76 broke
// admin.php?page=bh-design itself — a logged-in admin got WordPress core's own
// "Sorry, you are not allowed to access this page" wp_die(), immediately after
// this plugin gained a new page. Root cause: class- component-studio.php's
// add_menu() registered its Components list with a REAL parent slug
// (add_submenu_page('bh-design', ...)) — this is a known, ALREADY-DOCUMENTED
// footgun in this exact codebase (see class- style-gallery.php's own 3.4.31
// changelog note): WordPress implicitly pairs a top-level menu's bare slug with
// its first-registered submenu's own capability/callback, and adding another
// real submenu under the same parent can disturb that pairing depending on
// admin_menu hook registration order.

// 3.4.75 — 2026-07-12 — REAL, LIVE-CONFIRMED FIX: "Close, they jump to the
// start, not back one level" (the 3.4.74 breadcrumb/back-button work, tested
// live). Root cause: class-element.php's get_placements() only ever cast
// library_component_id to a real int — id and parent_placement_id came back as
// plain STRINGS (wpdb ARRAY_A over MySQL's text protocol), which JSON-encodes as
// quoted strings.

// 3.4.68 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 4: linked
// instances, AJ's confirmed scope of leaf-value overrides only (no per-instance
// structural changes — anything beyond attrs/style requires editing the master
// Component or detaching). New bhcore_element_placements column
// library_component_id (class-identity-activator.php DB_VERSION 1.12): 0 = an
// ordinary placement (every pre-existing row, unchanged behavior); non-zero = a
// linked instance — ONE row whose 'config' is repurposed as an index => {attrs,
// style} leaf-override map, no real child placement rows, structure entirely
// virtual.

// 3.4.67 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 3: the
// add-child picker (element-builder.js) turns out to already have been the
// Library, largely built in an earlier pass as the "Prefabs" palette section —
// instantiatePrefab() already gives exactly the detached-copy semantics
// §5.3/Phase 3 calls for (a fresh, independent set of placement rows every
// insert, editing the copy never touches the saved Component). Renamed that
// section's header "Prefabs" -> "Components" for terminology consistency with
// the Library tab (no schema/route change — the table is still literally
// bhcore_element_prefabs).

// 3.4.66 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 2: named
// fixture states — the Storybook Default/Empty/Viral-style variant tabs, per
// AJ's own "fixture/mock data per state" answer. New table bhcore_element_states
// (class-identity-activator.php DB_VERSION 1.11) and a new BH_Element_State
// class (class-element-state.php) hold them — one shared table for both a
// Library Component (owner_kind 'component', owner_key its prefab id) and a
// code-registered Primitive type (owner_kind 'type', owner_key its type slug),
// per the design doc's own §4.2 call. register_type() gained an optional
// 'states' manifest key so a type's author can ship default states inline;
// BH_Element:: maybe_seed_default_states() lazily inserts any that don't already
// exist the first time a type's states are actually requested, and never
// overwrites a row someone has since edited by hand.

// 3.4.65 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 1: the
// Library tab stops being read-only. "New Component" and "Edit this Component"
// now open a real authoring session — a new internal '__library' sandbox surface
// (class-element.php's register_library_ surface(), excluded from the ordinary
// Structure boot-load and Preview- surface list via a new 'internal' surface
// flag) reuses the EXISTING tree/inspector/add-child/reorder/save machinery
// unchanged, just pointed at (surface='__library', context_id=that Component's
// own id) instead of a live page — per the design doc's own "one editor, two
// modes" decision, this is a bridge (window.bhElementLibrary in element-
// builder.js: enterEdit/exitEdit/publish), not a second editor. Editing an
// existing Component hydrates its sandbox from the currently-published
// definition the first time (via the existing prefab instantiate route, now also
// usable against the sandbox), and "Publish" snapshots the sandbox back into the
// real Component via a new nested-aware definition_from_slot() helper (class-
// element-prefab.php) — a real capability fix over the old save_from_slot(),
// which silently dropped nested children; both a Component's root slot
// supporting more than one top-level sibling and genuine parent/child nesting
// now round-trip correctly. rest_update() gained a surface+slot re-derive mode
// alongside its existing raw-definition mode.

// 3.4.64 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 0: the
// first real slice of the Library/Structure rebuild AJ asked for. A top-level
// "Library | Structure" tab switch now sits above the Design Suite shell (class-
// style-gallery.php), localStorage-persisted (bhdsActiveMode).

// 3.4.63 — 2026-07-12 — AJ's own ask: "delete individual logs, hide or mute
// specific log codes... like Visual Studio" for the Console & Logs section
// (OUS_DebugLog). This schema has no discrete error-code field (levels are only
// error/warning/info, by design), so the practical equivalent of "mute this
// diagnostic" is muting by the exact (source, message) a row actually has — read
// server-side from the row being muted, never trusted from a round-tripped form
// field.

// 3.4.62 — 2026-07-12 — AJ's own explicit visual reference: storybook.js's
// Controls panel (a fixed Name column, one clean row per property, thin row
// dividers, no per-field label-above-input stacking) — NOT a request to embed
// Storybook's actual runtime/build step, which would conflict directly with this
// ecosystem's "no build pipeline assumed, runs on ordinary shared hosting"
// standing architecture. Scoped as a pure CSS/ markup pass on the inspector's
// Style — Advanced property rows and the Custom class/CSS rows (element-
// builder.js's renderStylePropertyField() now wraps its select/color-
// popup/custom-input together in one .bhel-field-controls container so the grid
// table works even for a property with more than one control; element-
// builder.css's new ".bhel-style-group-body > div.bhel-field-row" grid rules do
// the actual visual work).

// 3.4.61 — 2026-07-12 — two fixes/completions picked up after a real site-down
// incident: (1) THE FATAL: class-ui.php's admin_page_css() returns one long
// plain single-quoted PHP string (not a heredoc). Two comments added in 3.4.60's
// own contain:layout fix contained unescaped apostrophes ("story's", "bh-
// contest's") — exactly the recurring "unescaped apostrophe silently terminates
// a long single-quoted string" bug class this ecosystem has hit before (see
// VISION.md).

// 3.4.60 — 2026-07-12 — two live-confirmed fixes, straight off AJ's own
// screenshot: "Live View tree isnt showing the selected tree." (1) Real bug: TWO
// separate click listeners were bound to the same .bhy-story-btn buttons — one
// (registered first) dispatched 'bhel:select-surface' to sync the tree/outline,
// one (registered second) toggled which .bhy-story-frame carried the 'active'
// class. Listeners on the same element/event fire in registration order, so the
// sync dispatch fired and rebuilt element-builder.js's outline BEFORE the active
// class had actually moved — renderDemoOutline() reads '.bhy-story-frame.active'
// directly, so it was always one click behind, showing the PREVIOUS surface's
// markup over the NEW surface's canvas (exactly the screenshot: contest player
// on screen, CRM profile markup in the outline).

// 3.4.59 — 2026-07-12 — AJ's own ask, folded into the bh-contest conversion work
// rather than deferred as a separate pass: "is there a way to... litterally do
// it all via the builder instead of hard coded files" for JS specifically, plus
// "easy ways to wire up UI events to actions... 'On click' could trigger UI and
// server side stuff via fetch." Two genuinely different features, two genuinely
// different trust levels: (1) "On click" ACTIONS (p.config.actions, any
// placement) — a plain, codeless list builder in the inspector (element-
// builder.js's new renderActionsSection()): trigger
// (click/mouseenter/mouseleave/submit) + kind (toggle a CSS class / call a URL
// via fetch / navigate to a URL) + that kind's own params. class-element.php's
// new build_actions_js() maps each entry to a small, FIXED, reviewed JS snippet
// server-side — never raw script — so this needs no capability gate; anyone who
// can edit a placement at all can wire one up. (2) Custom JS
// (p.config.custom_js) — real, raw JavaScript, rendered scoped to one
// placement's own DOM element (wrap_placement_html()). This one IS dangerous
// (arbitrary code on the live site for every visitor), so it's gated for real: a
// new administrator-only capability
// (OUS_Roles::DEFAULT_CAPS['bhcore_author_custom_js']), enforced at
// save_placement() — the ONE write path every caller (REST, Debug Tools,
// prefabs) funnels through, not just checked in the GUI — plus a client-side "I
// understand this runs unreviewed" confirmation checkbox before the field is
// even usable.

// 3.4.58 — 2026-07-12 — AJ's own ask, framed as core debug-tooling work
// deliberately done BEFORE the bh-contest conversion starts (not after): "good
// use of Query Monitor where needed." New includes/class-qm- integration.php
// registers a real QM_Collector + QM_Output pair — Query Monitor's own admin-
// toolbar panel now gets an "Own Ur Shit" tab showing THIS request's own
// OUS_DebugLog entries (errors/warnings/info, same fields Debug Tools' Console &
// Logs table already shows), so triaging a bug while actively building bh-
// contest's real surface doesn't mean bouncing between QM and a separate admin
// screen. Backed by a new zero-extra-query in-memory buffer (class-debug-
// log.php's request_buffer(), appended to at the end of the existing log()
// method) — entirely additive, no change to what already gets logged or how.

// 3.4.57 — 2026-07-12 — direct UX follow-up: "move the Live view tree up so you
// don't have to scroll to the bottom just to edit the thing you want, and make
// it not shitty looking." The Live View outline section (#bhy-rail-demo-outline-
// section) previously sat BELOW the real Site tree in the Structure rail pane —
// a real problem since the Site tree is a permanent, often-long fixture, meaning
// reaching a Live View's outline meant scrolling past all of it first. Reordered
// above it instead (class-style-gallery.php's render_left_rail()).

// 3.4.56 — 2026-07-12 — three more same-session follow-ups on the demo
// outline/style feature, in order: (1) "add arbitrary class names and custom CSS
// to things as needed" — both the session-only demo-element style panel AND (the
// real, persisted version) the real placement's own "Style — Advanced" section
// (renderStyleAdvancedSection()) gained an "Extra CSS class(es)" + "Custom CSS"
// pair. For real placements this round-trips through
// p.config.style.custom_class/custom_css exactly like every other style field —
// class-element.php's wrap_placement_html() now reads both at render time
// (appended onto the class="..." attribute build_html_attrs() already builds,
// and onto whatever BHY_Style::scoped_inline_style() resolved), so it applies on
// the real front-end too, not just the live preview. (2)/(3) a genuine
// overcorrection, caught immediately by AJ ("Dipshit, the styles still stay in
// the inspector, the tree just gets naturally folded into the rail like the
// other shit"): an earlier edit this same pass moved BOTH the read-only outline
// tree AND its style panel into the left rail.

// 3.4.55 — 2026-07-12 — live-confirmed fix, straight off AJ's own screenshot
// right after 3.4.54 shipped: "styles are not doing their thing" — the canvas
// was rendering fully unstyled (black bg, default font, overlapping text). Root
// cause: TWO CSS selectors this whole gallery depends on only make sense inside
// a real Document, and 3.4.54 swapped every canvas story from a real iframe
// document to a shadow root, which has neither a root element nor a <body>
// element: (1) BHY_Style::inline_css() prints `:root{--bh-bg:...}` — inside a
// shadow root, `:root` matches nothing, so every `var(--bh-*)` reference in
// every surface's own CSS silently resolved to nothing.

// 3.4.54 — 2026-07-12 — two more direct follow-ups on the same demo- outline
// feature from 3.4.53, both same-session: (1) "the read only tree should be for
// structure of the thing only, we still need to edit the styles of each thing" —
// renderDemoOutline() (element-builder.js) keeps the outline tree itself read-
// only/structure- only (confirmed correct), but clicking a row now also opens a
// style panel for that exact element (background/text color, padding, border-
// radius, font size), writing LIVE, SESSION-ONLY inline styles directly to that
// DOM node. Explicitly not persisted — these demo mockups have no backing
// placement row to save to; the panel says so plainly rather than pretending to
// save.

// 3.4.53 — 2026-07-12 — two pieces, both direct live-feedback follow-ups on the
// SAME screenshot: "still not doing what it's supposed to" (picking a demo-only
// Live View left the inspector showing a stale, unrelated CRM placement), then
// "can we still have 'trees' for the plugin live views?" once the first fix
// explained there's genuinely no editable tree for a hand-authored mockup. (1)
// element-builder.js's 'bhel:select-surface' listener now sets a new
// state.selection.type === 'demo' when the clicked story's surface slug ISN'T a
// real registered BH_Element surface (was previously a silent no-op, leaving
// stale content on screen) — renderInspector() gained a matching branch that
// clearly explains what's being shown and why there's nothing to edit, instead
// of just looking broken/unresponsive. (2) new renderDemoOutline() — since these
// mockups have no real placement/tree DATA, this builds a genuinely useful
// substitute: a READ-ONLY outline tree parsed straight from the canvas iframe's
// actual DOM (tag/id/class per row), click-to-scroll+highlight the matching
// element in the canvas. class-style-gallery.php's preview_doc() gained one
// small injected <style> (.bhel-outline-highlight) INSIDE each iframe's own
// document for the highlight to be visible at all — this page's own CSS can
// never reach inside an iframe, by design (see the iframe-isolation reasoning
// flagged directly to AJ this same pass, in response to "is this shit really
// using iframes?" — yes, deliberately, for real style isolation between this
// admin page and N different plugins' own real front-end stylesheets; the real
// cost of that choice is exactly this class of extra cross-document plumbing).

// 3.4.52 — 2026-07-12 — direct response to AJ's own "let's be smart about tests"
// ask, right after a run of THREE real bugs in the BH_Element/Design Suite
// canvas layer were each only caught by a live screenshot round-trip tonight
// (the empty-slot wrapper, the doubled REST preview path, the surface-key
// mismatch). New class-element-test- suite.php (BH_Element_TestSuite) — same
// "runs from Debug Tools, no CLI/PHPUnit needed" pattern every other *_TestSuite
// class here already uses — adds regression coverage for the two of those three
// bugs that ARE testable from a pure PHP assertion (render_slot()'s empty-slot
// wrapper; the color-token schema's colorTokens values being real CSS vars, not
// bare names — the shape the new swatch dropdown depends on).

// 3.4.28 — 2026-07-11 — element-builder.js/.css UX pass over the 3.4.27
// inspector: the "Style — Advanced" property groups (§2.6) are now collapsible
// disclosures (collapsed by default, auto-open only when a group already carries
// an active override) instead of ~11 always-open fieldsets stacked in a row, so
// the panel reads as "what's actually customized" at a glance. Added the §2.5
// responsive behavior the prior pass's plain 1100px block-stack didn't cover: a
// real 3-tier layout (>=1200px full three-pane, 783-1199px palette-as-overlay
// behind a toggle button mirroring WP admin's own folded-sidebar convention,
// <=782px single-column stack with the inspector as a bottom sheet — drag
// handle, "Done" dismiss, slide-up transform — matching WP admin's own 782px
// breakpoint) plus a ~44px minimum touch target on every interactive control at
// that narrowest width.

// 3.4.27 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2's inspector UI
// (§2.6), the one piece 3.4.26 deliberately left unshipped: class-element-
// builder.php's GUI (assets/js/element-builder.js/.css) gained a "Style —
// Advanced" section (every §2.6 property group as a dynamic preset picker +
// custom-value escape hatch) and an "HTML Attributes" section (tag picker, per-
// type attr fields, repeatable custom data-* row editor) — both built entirely
// from REST-exposed manifests (GET .../elements/types' existing attrs/tags keys,
// plus a new GET .../elements/style-schema route backed by BHY_Style::
// style_schema_for_js(), class-style.php), never hardcoded per element type or
// property group client-side. Both sections write into
// config.style/config.htmlAttrs and save through the EXISTING POST
// .../placements route unmodified — no REST route changed shape.

// 3.4.26 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2, ALL property
// groups shipped in one pass (no [core]/[adv] deferral, per AJ's explicit
// instruction): BHY_Style::scoped_inline_style() (class-style.php) resolves a
// placement's config.style map — bare --bh-* token keys (§2.3, unchanged) PLUS
// new namespaced "group.property" keys (§2.6:
// sizing/spacing/background/typography/
// border/display+flex+grid/position/effects+transforms/overflow+ visibility) —
// into an inline style="" attribute; new safe_length()/ safe_enum() validators
// added alongside the existing safe_color()/ safe_number().
// BH_Element::render_placement() (class-element.php) now builds each placement's
// OWN wrapper element (tag + class + data- placement-id/data-type + the resolved
// style + a strictly-allowlisted htmlAttrs set — id/class/title/aria-
// label/href+target+rel-when-tag-is- 'a'/custom data-*), moved out of
// render_slot() so REST preview (rest_preview()) gets an identical wrapper.
// register_type()'s $args contract gained 'attrs' (per-attribute allowlist) and
// 'tags' (allowed semantic tags, first = default, defaults to ['div']) — GET
// /elements/types now exposes both so inspector JS can build controls per-type
// dynamically.

// 3.4.25 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (menu
// restructure only — no inspector unification): new top-level "Design Suite"
// menu (bh-design, new class-design-suite.php / BH_Design_Suite) and, in bh-crm,
// a new top-level "CRM" menu (bh-crm-hub, new bh-crm/includes/class-hub.php /
// BHCRM_Hub). BH_Design_Suite's own top-level/first-submenu callback
// deliberately REUSES BHY_Gallery:: render() (the real, working Style page)
// rather than a placeholder — there is no unified inspector shell yet (that's
// Phase 3), so pointing the new landing page at the one real screen that already
// exists is more honest than a stub.

// 3.4.24 — 2026-07-11 — Remaining ELEMENT-BUILDER-DESIGN-PLAN.md §6 phases: the
// Portal registered as a real bh_element_surfaces contributor with one new
// element-composed panel (BHI_Portal::
// register_element_surface()/register_elements_panel(), class-portal.php); a
// real container element type (bh/container, class-element.php) whose content is
// an embedded BH_Content subtree — the §1.1 hybrid-nesting bridge into the
// EXISTING BH_Studio canvas, not a second tree editor — with
// BH_Element::save_placement() auto-assigning content_context_id = the
// placement's own id for container types on first save; and a real DELETE
// /elements/placements/{id} REST route (class-element.php), closing the one gap
// that route's own docblock previously named. Also ships a genuinely NEW
// addition beyond the design doc's own scope, per AJ's mid-build request: the
// prefab system (new BH_Element_Prefab, class-element-prefab.php; new
// bhcore_element_prefabs table, class-identity-activator.php DB_VERSION 1.10) —
// named, reusable, deep- copyable saved compositions of placements, with "Save
// as Prefab" / prefab-picker controls added to BH_Element_Builder's existing GUI
// (assets/js/element-builder.js, assets/css/element-builder.css) — see ELEMENT-
// BUILDER-DESIGN-PLAN.md's own trailing status note for the honest "this wasn't
// in the original doc" framing.

// 3.4.23 — 2026-07-11 — Element builder, §4/§6-step-2 GUI phase of ELEMENT-
// BUILDER-DESIGN-PLAN.md: new BH_Element_Builder (class-element-builder.php) — a
// three-pane visual builder (palette / canvas / inspector) cloned from
// BHY_Gallery's Storybook layout, shipped as a NEW, additive Debug Tools section
// ("Element Builder (Visual)") rather than a standalone admin page, per this
// install's documented hook-resolution bug affecting standalone/submenu-of- ous-
// debug pages (see class-api-docs.php's docblock and this class's own docblock
// for the incident). New assets/js/element-builder.js + assets/css/element-
// builder.css (no build step, vanilla JS, enqueued only on the Debug Tools
// screen).

// 3.4.19 — Debug Tools reorganization pass: added an optional 'group' key to the
// ous_debug_tools registration array shape (self::GROUP_* constants in class-
// debug.php) so sections render bucketed by purpose (Monitoring & Health /
// Reference & Docs / Seed & Reset Tools / Diagnostics & Tools default) instead
// of flat registration order, with a grouped "Jump to" quicknav to match. Purely
// additive — no existing add_filter('ous_debug_tools', ...) call site had to
// change shape to keep working; every current registrant was also updated to set
// an explicit 'group' so the new grouping takes effect immediately.

// 3.4.15 — confirmed via Query Monitor (capability-checks + admin-screen panels,
// installed temporarily on the live site): the standalone admin.php?page=ous-
// api-docs / ous-codebase-docs pages fail because WordPress's own
// get_current_screen()/hook_suffix resolution falls back to the PARENT page's
// hook instead of the submenu's, on every request, regardless of correct
// registration/capability — a genuine WordPress-core page-hook lookup issue, not
// caching or capabilities (the two things chased hardest earlier). Since the
// Debug Tools SECTION versions of both pages are confirmed working end to end,
// the two standalone add_menu() registrations are now unhooked entirely (methods
// left defined, just not called) rather than left as dead, permanently-broken
// links sitting in the sidebar.

// 3.4.14 — Stopped chasing the standalone-page access-denial bug (registration
// and capability both confirmed correct via logging, yet WordPress still blocked
// admin.php?page=ous-api-docs / ous-codebase-docs every time — root cause never
// found despite five diagnostic passes) and sidestepped it instead: both API
// Docs and Codebase Docs now render their REAL content as sections directly on
// the Debug Tools page (ous-debug), the one page that has never once failed to
// load all session. class-api-docs.php's render_debug_section() (previously just
// a diagnostic panel) and class-codebase-docs.php's new render_section() both
// call a shared render_content() method, factored out of each class's standalone
// render() so neither duplicates the actual body markup. Debug Tools' own "API
// Docs"/"Codebase Docs" buttons now jump to these sections (#ous-section-api-
// docs / #ous-section-codebase-docs) instead of linking to the still-broken
// standalone pages, which remain registered as a secondary access point but
// should not be relied on.

// 3.4.13 — CONFIRMED via 3.4.12's render()-entry log: render() never runs at all
// for Codebase Docs — WordPress is blocking the request at its own core dispatch
// level (the $_wp_submenu_nopriv mechanism: add_submenu_page() checks
// current_user_can() at the MOMENT it's called, on that specific request, and
// silently marks the page no-priv if it fails then — separate from the page
// callback entirely). Un-throttled the registration log and added the exact
// request URI + a same-request current_user_can() reading, specifically so the
// entry from the real failing click (not a nearby unrelated page load) is
// unambiguous.

// 3.4.12 — 3.4.11's is_locked()-gate removal confirmed NOT the fix (user reports
// no change in behavior). Added the one truly decisive diagnostic left: a log
// line as the literal first statement inside render() itself for both classes —
// this settles, once and for all, whether WordPress is blocking the request
// before OUR code ever runs (a genuine core-level gate this session hasn't found
// the cause of yet) or whether the callback IS running and something inside it
// is the actual problem.

// 3.4.11 — API Docs / Codebase Docs "not allowed" bug, actual fix (not another
// diagnostic pass): found that both were the ONLY two admin pages anywhere in
// this ecosystem that wrapped their own add_submenu_page() call in an
// is_locked() check before registering. Every other page (Debug Tools itself,
// Job Queue, every peer plugin's screens) registers unconditionally —
// is_locked() exists to gate DESTRUCTIVE seed/reset actions, not a read-only
// viewer page's mere existence in the menu, so conditionally skipping
// registration was the wrong design from the start, independent of whatever
// is_locked() itself was actually evaluating to on any given request.

// 3.4.10 — PHP restart on the live site confirmed OPcache was serving stale
// compiled code (explains several earlier "this fix didn't seem to take effect"
// moments this session) — after restarting, add_submenu_page() for both API Docs
// and Codebase Docs now confirmed returning a real hook_suffix, not FALSE.
// Registration is NOT the problem.

// 3.4.9 — API Docs / Codebase Docs still 404 with is_locked() confirmed NOT the
// cause (zero log entries even from the locked-branch logging 3.4.5/this pass
// added, meaning that branch never ran — but that was ambiguous, since the
// SUCCESS branch had no logging either, so "no log" couldn't distinguish "never
// called" from "ran fine"). Added logging to the success path too: both
// add_menu() methods now log whatever add_submenu_page() actually returned (a
// real hook suffix string, or FALSE on a genuine registration failure) every
// time they run, closing that ambiguity for the next reload.

// 3.4.8 — 3.4.7's own Portal fix had a real side effect: calling add_rewrite()
// synchronously at 'init' priority 10 meant its force_flush_and_verify() could
// run before other plugins' own default-priority rewrite registrations, and its
// unconditional wp_cache_flush() wiped the WHOLE object cache mid-request — very
// likely why API Docs started intermittently 404ing right after 3.4.7 shipped
// (is_locked()'s cached host checks, read later in the same request, got yanked
// out from under it). Fixed two ways: add_rewrite() is now deferred to 'init'
// priority 20 (still the same request/pass, just after other plugins' default-
// priority rewrite rules have registered), and wp_cache_flush() is now an
// ESCALATION only reached if the cheaper targeted cache evictions didn't already
// fix it, not called unconditionally on every throttled self-heal attempt.

// 3.4.7 — Portal's /account/ 404, finally actually found (not another caching-
// layer guess): class-portal.php's own init() was hooking add_rewrite() onto
// 'init' FROM INSIDE a callback that is itself currently running as part of
// 'init' (own-ur-shit.php's own add_action('init', ['BHI_Portal','init']) at
// default priority 10). PHP's foreach over that priority's callback array is a
// snapshot taken when iteration starts; a handler appended to the SAME priority
// after iteration has already begun isn't picked up until 'init' fires again —
// which, on a normal page load, never happens in that request.

// 3.4.6 — OUS_Jobs can now run on the REAL Action Scheduler library (Apache-2.0,
// github.com/woocommerce/action-scheduler — the same library WooCommerce itself
// bundles) instead of only its own hand-rolled wpdb-table queue. A one-click
// "Install Action Scheduler" button on Debug Tools -> Job Queue downloads the
// actual official release directly from GitHub onto the LIVE site (this dev
// sandbox has no outbound network access at all, confirmed by testing — so the
// library could not be vendored directly from here; fabricating placeholder code
// under a real project's name would be dishonest, so a real installer was built
// instead, same download_url()/unzip_file() mechanism OUS_Registry already uses
// for WooCommerce). register()/ enqueue() delegate to Action Scheduler's native
// add_action()/ as_enqueue_async_action() once installed, with ZERO call-site
// changes needed anywhere bh-registry/bh-streaming/etc. already call OUS_Jobs —
// until installed, every existing call transparently keeps using the original
// table-backed implementation exactly as before.

// 3.4.5 — real bug fix + new feature. (1) bh-contest's Live Console dropdown
// 403'd because its GET form dropped post_type on submit — see bh-contest 3.1.3
// for the fix; own-ur-shit itself was audited alongside it (bh-contest, BHY_*
// styles, bh-crm, Debug Tools) for the same bug class and no other instance was
// found. (2) New OUS_CodebaseDocs (class-codebase-docs.php, "Own Ur Shit →
// Codebase Docs"): renders CODEBASE-WALKTHROUGH.md as real in-admin HTML, and
// turns every file-path mention in that doc into a "View live code" toggle that
// fetches the file's ACTUAL current contents via a locked-down AJAX endpoint
// (realpath()-verified inside the plugins root, manage_options- gated, nonce-
// checked) — so the walkthrough can never silently drift from the real code the
// way a pasted-in snippet would. Deliberately left OUS_ApiDocs' existing
// dependency-free viewer alone rather than swapping in a Swagger-UI bundle, to
// keep this ecosystem's own "no external JS/CDN" viewer convention intact; the
// two pages cross-link instead.
define('OUS_VER', '3.7.5');

// 3.6.6 — Design Suite cleanup pass, AJ's own "bloated weird GUI and remnants of
// stuff" report: (1) Real leftover test data found and deleted directly from
// wp_bhcore_element_placements (id 3, a stray "bh/note" placement with literal
// text "rety78" styled in the accent color) — it was rendering live inside the
// bh_crm_profile surface's Design Suite preview since that surface renders REAL
// placements, not a mockup, and context 0 is the preview-only default context no
// real user profile ever uses. (2) Two real dead links fixed (class-
// dashboard.php, class-portal.php): both pointed at admin.php?page=bh-element-
// builder, a page deleted in an earlier cleanup pass and never replaced. The
// dashboard one now correctly points at Debug Tools' own real, functioning
// Element Builder section (a genuine add/remove/ reorder list, just scoped to
// dashboard/main); the portal one honestly states no admin UI exists for that
// surface/slot, since Debug Tools' section doesn't cover it. (3) Inspector UX
// fix, AJ's own follow-up ask: the "Live token preview" panel never had any real
// connection to whichever surface was selected in the canvas (correctly so —
// these are genuine GLOBAL tokens, one theme applied everywhere, never per-
// surface theming) but the UI never SAID that, reading as if something was
// broken/disconnected.


// 3.6.5 — new OUS_StyleSurface (includes/class-style-surface.php): registers the
// Media & CDN Setup wizard into the Design Suite gallery — own-ur-shit had ZERO
// bhy_style_surfaces of its own before this, so its own "it just works" wizard
// was invisible to the token editor. Real contrast bug caught live and fixed in
// the same pass: preview_doc()'s own `:host{color:var(--bh-text)}` (correct for
// every OTHER surface, which uses the dark brand theme) left this wizard's
// genuinely light wp-admin-style preview with light-on-light text, since --bh-
// text is a light color on the default dark theme — fixed by setting this
// preview's own explicit text color rather than inheriting the brand theme's.


// 3.6.4 — real "wonky character" bug in the Design Suite gallery, caught live:
// em-dashes/curly-quotes in a surface's preview HTML (e.g. bh-crm's own live-
// slot instructional text) rendered as garbled characters ("â€" instead of "—").
// Root cause: class- style-gallery.php's own JS decoded each surface's
// base64-encoded preview document with plain atob(), which returns a raw binary
// string (one JS character per BYTE, not a properly UTF-8-decoded string) — any
// multi-byte character came through as 2-3 separate mis-rendered characters the
// moment DOMParser parsed that raw byte string as text.

// 3.6.3 — real production fatal, caught live on the billyhume.wasmer.app deploy:
// "Uncaught Error: Class ActionScheduler not found" in class-jobs.php, site-wide
// 500 on every request. Root cause: action- scheduler.php's own bootstrap
// doesn't define the ActionScheduler facade class synchronously — it defers to a
// 'plugins_loaded' priority-1 callback it registers itself.

// 3.6.2 — new OUS_Metrics (includes/class-metrics.php): the shared creator-
// dashboard VISION.md's own roadmap has named since before this pass, built now
// as real foundational infrastructure rather than a later bolt-on — AJ's own
// explicit ask to grow this in tandem with bh-courses/bh-contest/bh-crm. Pure
// read/aggregate layer over bhcore_events (BH_Event's own table); writes nothing
// new.

// 3.6.1 — Slice 1 of ROADMAP-discoverability.md: new BH_SEO (includes/class-
// seo.php), a shared meta/OG/Twitter-Card/JSON-LD renderer plus an /llms.txt
// endpoint — a full grep beforehand confirmed zero meta/OG/schema.org output
// existed anywhere in this ecosystem. Reference consumer: BHI_PublicProfile's
// public profile view now calls BH_SEO::set_page_data() with a real schema.org
// Person block.


// 3.6.0 — Tier A of ROADMAP-guided-setup-wizards.md, built for real:
// OUS_MediaWizard (new includes/class-media-wizard.php), a guided media/CDN
// setup screen wrapping the already-installed Advanced Media Offloader plugin —
// six providers (Cloudflare R2 recommended by default, Amazon S3, Backblaze B2,
// DigitalOcean Spaces, Wasabi, and generic S3-compatible), each with plain-
// language tradeoffs, a direct deep link to that provider's own credentials
// dashboard, and a REAL live connection test (reuses ADVMO's own provider
// classes' checkConnection() — an actual headBucket() API call, never a format-
// only check) on save. Writes directly into ADVMO's own
// advmo_settings/advmo_credentials option shape, confirmed correct by reading
// GeneralSettings::sanitize()/sanitize_credentials() first.


// 3.5.9 — Real bug in 3.5.8's own player-bar fix, caught live by AJ ("definitely
// isn't at the bottom when the player bar is gone"): the --bh-bar-height CSS-
// variable approach was wrong because bh-contest's player.css also loads on
// Archive/Results-Reveal-only pages (shared fonts/theme vars) that never render
// the actual .bh-now-playing-bar element — :root still defined the property
// regardless, leaving a phantom ~84px gap under the button on pages with no bar
// to justify it. Replaced with real DOM detection: JS checks for .bh-now-
// playing-bar's actual presence and measured height (not a hardcoded number),
// re-checked on resize and via a MutationObserver (the bar is built client-side
// by player.js, not server-rendered, so script-order timing isn't guaranteed).


// 3.5.8 — Two AJ-flagged fixes to the technical-report widget. (1) It was
// colliding with bh-contest's fixed bottom player bar on contest pages. Fixed
// via CSS only, zero JS/DOM-detection needed: reads the same --bh-bar-height
// custom property bh-contest's own player.css already sets on :root (its .bh-
// toast component already positions itself above the bar the identical way) —
// cascades globally regardless of which stylesheet defined it, and the var()
// fallback (0px) means pages without that property behave exactly as before.


// 3.5.7 — Admin-menu-cleanup pass, item 1: Debug Tools' per-user "developer
// mode" gate. The audit's #1 flagged organizational problem — ~17 accumulated
// sections always visible to any manage_options user, mixing genuinely useful
// monitoring tools with pure dev/QA scaffolding.


// 3.5.6 — Log-pollution fix, flagged by AJ directly ("I just notice the logs get
// polluted quickly right now"). Traced to the exact same pattern copied across 5
// files (class-menu-merge.php, class-hub.php [bh-crm], class-studio.php, class-
// design-suite.php, class-style- gallery.php): every one of them logged an INFO
// row for a SUCCESSFUL admin-menu registration, throttled only to once per 60
// SECONDS, on every single admin page load — with OUS_DebugLog::MAX_ROWS capped
// at 1000, this filled the whole log within a handful of admin page visits,
// crowding out genuinely rare warning/error rows.


// 3.5.5 — Enriched the "report a technical difficulty" widget with real
// diagnostic context, per AJ's own follow-up ask. Two additions on top of the
// existing page-URL/browser capture: (1) a coarse "feature area" guess (BH
// Courses lesson/catalog, BH Contest player, BH Streaming player, portal UI)
// from known DOM root markers already present on the page — not a claim about
// which FILE is involved (this is client-side, it can't know that), but a real
// triage hint instead of making an admin guess from the URL alone. (2) A capped
// (last 12), sessionStorage-backed recent-action trail — every clicked
// button/link's visible label plus a relative timestamp, recording from page
// load (not just from when the widget opens), so a report filed a page or two
// after the actual problem still shows the path that led there.

// 3.5.4 — Ecosystem-wide "report a technical difficulty" widget, AJ's own ask.
// Reuses the existing BHI_Reports moderation queue (a new 'technical' category +
// the existing bhi/v1/reports REST endpoint) instead of standing up a second,
// parallel admin screen — every other report category requires a real
// target_type+target_id (a piece of content to point at); a bug report has none,
// so rest_submit() now allows target_id=0 specifically for the 'technical'
// category, and the admin queue's Target column shows a real label for it
// instead of a bare "technical #0".

// 3.5.3 — Two more BH_ShareCard styles: 'poster-frame' (centered type, bordered
// inset frame with corner registration-mark ticks) and 'poster-block' (a solid
// color block with a reversed-color eyebrow tag and a big single-letter
// monogram, title continuing onto the dark remainder) — genuinely distinct
// compositions, not recolors of the existing diagonal-band 'poster'. New STYLES
// const is the one place a style gets registered/labeled now, so consuming
// plugins' picker UIs read off it instead of each hardcoding their own copy of
// "which styles exist." Verified by rendering both to real PNGs and looking at
// them.

// 3.5.2 — New shared BH_ShareCard engine (includes/class-share-card.php):
// server-side generated (PHP GD, no headless browser/external service) 1200x630
// social-share PNG cards, two selectable visual styles — 'brand' reads the
// site's live BH_Style palette; 'poster' is a deliberately louder, stand-alone
// look (Bebas Neue on a diagonal accent band) independent of whatever theme
// preset is active. Two new vendored OFL-licensed fonts (assets/fonts/:
// BebasNeue-Regular.ttf, WorkSans-Variable.ttf — the latter fetched as Google
// Fonts' current variable-font release since the old static-weight files were
// removed upstream; GD renders its default instance fine, faux-bold via a 1px-
// offset double-draw where a bolder weight is wanted).

// 3.4.87 — QA fix: a full ecosystem-wide re-audit of every hook-timing fix
// claimed this session (both the "nested init callback silently never fires" bug
// class and the "wp_register_script() called too early" bug class) found one
// genuine incomplete-fix regression — the 3.4.85 changelog claimed
// OUS_Gutenberg_Block::init() was fixed alongside
// BH_Event/BH_Identity/OUS_Toast, and the METHOD BODY fix was real (init() calls
// register_block() directly, no nested 'init' hook), but the actual call site
// that invokes init() was never added anywhere — the class was still required
// via this file's own foreach loop, but nothing ever called
// OUS_Gutenberg_Block::init(), so register_block() never ran at all. Currently a
// double no-op either way (its own class_exists('BH_Element_Prefab') guard is
// false post-page-builder- delete), but wrong regardless, and would have
// silently stayed unregistered with zero error if that class ever came back.

// 3.4.86 — QA fix, part of an ecosystem-wide sweep for the same
// idempotency/ordering bug classes just caught live in bh-crm's new notes-with-
// reminders feature (this same session). bhcore_notifications gained an
// email_sent column (class-identity-activator.php, DB_VERSION 1.12 -> 1.13) and
// OUS_Notifications::send_queued_email() now claims it atomically (UPDATE ...
// WHERE email_sent = 0) before actually mailing — the queued email job can
// genuinely fire more than once (confirmed for the near-identical bh-crm
// reminder job: a manual test call plus Action Scheduler's own real background
// processing of the same scheduled job both ran it), and without this fix that
// would have meant a duplicate email with zero guard against it.

// 3.4.18 — new ecosystem-wide toast notification system: OUS_Toast (class-
// toast.php, new) + assets/js/toast.js + assets/css/toast.css. A real, no-build-
// step, dependency-free BHCoreToast.show(message, type) JS renderer (fixed top-
// right stack, auto-dismiss + manual close, role="status"/aria-live="polite"),
// enqueued globally on both admin_enqueue_scripts and wp_enqueue_scripts so any
// plugin in the ecosystem can call it from its own JS with zero setup.

// 3.4.17 — added BH_Identity::client_ids_for_user() (class-identity.php), a
// reverse lookup from user_id to the distinct client_id values already stamped
// on that user's own bhcore_events rows (there is no separate stitching table —
// see that class's docblock for why this is NOT a join against dedicated
// storage). Added to support bh-crm's new event-activity consumer (bh-
// crm/includes/class-event-activity.php, bh-crm 1.0.0 -> 1.1.0), which was wired
// to bh_crm_activity_summary this pass.

// 3.4.16 — hardened OUS_Jobs::handle_install_action_scheduler() (the "Install
// Action Scheduler" Debug Tools button, which a user reported did nothing when
// clicked): WP_Filesystem() and wp_mkdir_p() return values are now checked
// instead of ignored, download_url()'s full WP_Error message is surfaced
// verbatim (was already true before, kept so on purpose — helps diagnose Local-
// by-Flywheel's known outbound-SSL quirks), and success is no longer declared
// unless file_exists(OUS_Jobs::vendor_path()) is genuinely true after the move.
// Also added OUS_Dashboard's new Job Queue + Query Monitor status block (class-
// dashboard.php render()) and the new BH_Event/BH_Identity event-tracking layer
// (class-event.php, class-identity.php — see EVENT-TRACKING-ARCHITECTURE-
// PLAN.md, previously designed but never implemented) wired into the require-
// loop/init hooks below.

// superseded — kept only so a stray duplicate define() below this point
// (a recurring mistake this session) is easy to spot if it recurs:
// 3.4.4 — new OUS_ReliabilityTestSuite (class-reliability-test-suite.php), the
// first test coverage for OUS_ReliableStore and OUS_DebugLog::log_throttled() —
// both previously untested despite now being load-bearing (BHI_Auth's security
// throttles, the whole diagnostic-logging pipeline this session built out). Runs
// against the real options table with tagged/prefixed keys, cleaned up at the
// end of every run.

// 3.4.3 — continuation logging pass (per audit): BHI_Auth::register()'s
// wp_create_user() failure now logs the real WP_Error instead of discarding it.
// Standing caveat: reasoning/brace-balance-checked only.

// 3.4.2 — Portal's /account/ 404 is still unresolved on the live install per
// direct user report (rewrite rule confirmed missing every reload, but ZERO
// Portal log entries at all — not even the throttled "still broken" warning that
// should have fired at least once by now). Per explicit user direction, NOT
// chasing this further right now (it's not blocking other work) and NOT treating
// BHI_Portal's fix as a working reference elsewhere — but added one cheap,
// always-throttled diagnostic breadcrumb at the very top of add_rewrite() so the
// next person looking at this (me or the user) can tell in one page load whether
// the method is even being entered, rather than re-deriving that from scratch.

// 3.4.1 — Debug Tools sections are now real <details>/<summary> collapsibles,
// closed by default (the page is long enough with a dozen-plus registered
// sections that scrolling past all of them to find one is real friction), with
// each section's open/closed state remembered per-browser via localStorage so it
// doesn't reset every page load. Deliberately localStorage, not a server-side
// per-user option — this is cosmetic UI state, not anything that needs to
// survive across devices or matters if lost, and per this session's whole
// object-cache saga, sidestepping server-side persistence entirely for something
// this low-stakes is the more robust choice on an install whose cache layer has
// already proven unreliable more than once.

// 3.4.0 — a real, live-reported bug ("nothing is displayed with the tests")
// traced to the same root cause as this whole session's Portal/ API-Docs saga:
// set_transient()/get_transient() are backed entirely by this install's
// persistent object cache when one is active, and that cache is unreliable here
// — a transient write can report success while the very next request's read sees
// nothing. New class-reliable-store.php (OUS_ReliableStore) consolidates the
// direct-DB-bypass-the-cache pattern this session kept hand-rolling ad-hoc
// (BHI_Portal's throttle, OUS_TestRunner's first fix) into one shared,
// documented utility.

// 3.3.9 — two things: (1) real bug found in 3.3.8's own anchor-scroll fix — the
// sticky admin bar + this page's own sticky quick-nav both cover the top of the
// viewport, so a native browser anchor-jump landed the target section's heading
// BEHIND them, which looked identical to "still stuck at the top" (exactly what
// got reported after 3.3.8 shipped). Fixed with scroll-margin-top on every
// section plus a JS scrollIntoView + brief highlight flash as a second,
// independent safety net. (2) Added BHI_Profiles::user_ids_with_profile_data()
// per QA-REPORT-code-quality.md's cross-plugin finding #2 — bh-crm's class-
// people.php and class-export.php both ran identical raw SQL against this table
// directly instead of through the class that owns it; a pure extraction, no
// behavior change.

// 3.3.8 — Debug Tools page UX fix (explicit user report: running a test or
// clicking any button jumped back to the page TOP instead of staying near the
// result, and the page is long enough that this meant re-scrolling every single
// time). OUS_Debug::redirect() now carries a per-section anchor (every section
// already has/gained a stable 'ous-section-{key}' id) so a button click lands
// you back exactly where you clicked from — results were already rendered
// colocated inside their own section (Test Runner's own transient-backed report,
// e.g.), the only missing piece was the redirect itself dropping the anchor.

// 3.3.7 — request-correlation IDs shipped end to end: bhcore_debug_log gained a
// request_id column (BHI_Activator::DB_VERSION 1.6 -> 1.7),
// OUS_DebugLog::request_id() generates one short ID per PHP request and stamps
// it onto every log() call automatically (no call-site changes needed anywhere
// in the ecosystem), and Console & Logs gained a Request ID filter plus a
// clickable chip on every row that jumps straight to "everything else that
// happened during this exact request." Degrades safely on an install that hasn't
// migrated yet (has_request_id_column() checks the live schema, not just the
// stored DB_VERSION, before including the column in any insert — a not-yet-
// migrated install keeps logging, just without correlation IDs, rather than
// every log() call failing on an unknown-column error).

// 3.3.6 — first slice of a deliberately larger, ongoing logging-depth push
// (explicit user direction: debugging/logging needs to be "airtight" across the
// whole ecosystem, not just the Portal/API Docs incident that started this).
// This pass: BHI_Two_Factor::ajax_disable() now logs a security-relevant account
// change (2FA disabled) that previously left zero audit trail;
// BHI_Two_Factor::gate_login() now logs a real wrong- code attempt (throttled
// per-user), previously invisible.

// 3.3.5 — closes the real diagnostic gap the 3.3.3/3.3.4 back-and-forth exposed:
// both fixes only logged on FAILURE, so an empty Console & Logs table was
// ambiguous between "checked every request and genuinely fine" and "stopped
// running/self-healing entirely" — precisely the state the 3.3.4 throttle bug
// produced and that made it undiagnosable from log data alone. Added
// OUS_DebugLog::log_throttled() (logs at most once per N seconds per key,
// regardless of outcome) and wired it into OUS_Debug::is_locked() and
// BHI_Portal::add_rewrite() so a PASSING check now also leaves a periodic trace,
// and a check that's sitting out a throttle window while still broken logs THAT
// state explicitly (at 'warning') instead of silently doing nothing. "No log
// entries for this key in the last several minutes" is now itself a real,
// actionable signal — the check isn't running at all — rather than an empty
// table meaning nothing in particular. (see class-debug-log.php's own docblock
// for log_throttled() usage — intended for any check that runs on every request
// across this ecosystem, not just these two.).

// 3.3.4 — real bug found in 3.3.3's own fix: BHI_Portal's rewrite self-heal
// throttle used get_transient()/set_transient(), which on an install with a
// persistent object cache active stores the transient IN that same cache —
// exactly the layer this whole fix exists to not trust. A stuck/broken cache
// could make the throttle read "already attempted" forever, silently skipping
// the self-heal on every request with zero log trace, which is indistinguishable
// from "working, just waiting" from the outside.

// 3.3.3 — fixed the real reported bug: BHI_Portal's /account/ 404 and API Docs'
// "not allowed to access this page" both came from user-facing symptoms of the
// SAME underlying pattern (a persistent object cache serving stale option reads
// across requests — confirmed on this specific install via each class's own
// Debug Tools diagnostic). Both previously relied on a one-shot "did this
// already run" flag that could mark itself successful without the write actually
// having persisted, requiring a manual Settings -> Permalinks -> Save to fix.
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
foreach (['registry', 'dashboard', 'installer', 'activation-manager', 'setup-wizard', 'banner', 'menu-merge', 'menu-icons', 'debug', 'debug-log', 'qm-integration', 'reliable-store', 'test-runner', 'core-test-suite', 'reliability-test-suite', 'api-docs', 'profiles', 'public-profile', 'reports', 'auth', 'two-factor', 'identity-activator', 'style', 'ui', 'style-gallery', 'notifications', 'jobs', 'roles', 'audit', 'revisions', 'search', 'admin-layout', 'content', 'commerce', 'portal', 'portal-layout', 'menu-sync', 'studio', 'studio-test-suite', 'codebase-docs', 'event', 'identity', 'toast', 'element-data', 'element', 'element-test-suite', 'design-suite', 'gutenberg-block', 'block-style', 'share-card', 'media-wizard', 'seo', 'metrics', 'style-surface'] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHI_Activator', 'activate']);
register_activation_hook(__FILE__, ['OUS_Roles', 'activate']);
register_activation_hook(__FILE__, ['OUS_Audit', 'activate']);
register_activation_hook(__FILE__, ['OUS_Revisions', 'activate']);
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
add_action('init',          ['OUS_MediaWizard', 'init']);
add_action('init',          ['BH_SEO', 'init']);
add_action('init',          ['OUS_Metrics', 'init']);
add_action('init',          ['OUS_StyleSurface', 'init']);
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
add_action('init',          ['OUS_Revisions', 'init']);
add_action('init',          ['OUS_Search', 'init']);
add_action('init',          ['OUS_SetupWizard', 'init']);
add_action('init',          ['OUS_PortalLayout', 'init']);
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
// QA fix, simulation pass: BHI_Auth::init() was never called anywhere —
// register_routes() (the REST endpoints) gets wired separately via
// rest_api_init below, so login/register/session worked, but init()
// itself (admin_post_bhi_verify_email + the wp_footer verification-toast
// handler) was completely orphaned. Confirmed via a real HTTP hit on
// admin-post.php?action=bhi_verify_email with a fresh, valid token: the
// user meta never updated and the redirect carried no bhi_verified param
// at all — the exact same silent-dead-hook failure mode as the three
// classes just above.
BHI_Auth::init();
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
