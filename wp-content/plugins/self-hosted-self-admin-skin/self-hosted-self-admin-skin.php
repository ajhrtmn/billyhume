<?php
/**
 * Plugin Name: Admin Skin — The Self-Hosted Self
 * Description: A wp-admin-only visual/UX mod — reskins the default WordPress dashboard with a calmer dark/light palette, real accessibility work (focus states, contrast, reduced-motion, larger touch targets), a genuinely mobile-friendly admin menu, and a couple of small "it just works" touches (a Cmd/Ctrl+K command palette, a light/dark toggle). Standalone and portable — works with any theme and any other plugins, never touches the front end at all.
 * Version:     0.31.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 0.31.0 — Direct field report ("Media & CDN Setup wizard provider
// cards appear dimmed/disabled"). This screen (own-ur-shit's
// class-media-wizard.php) is entirely gated behind
// class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory')
// — that plugin was installed but never ACTIVE on this local install,
// so the gated section had genuinely never rendered here before,
// meaning nobody had actually seen this screen live prior to this
// field report. Activated it locally to reproduce and confirm: real
// bug, not a screenshot artifact. Root cause matches the recurring
// shape from the WooCommerce fixes above — the provider-choice cards
// and credential blocks carry their own inline `background:#fff` (this
// file's markup predates the admin-skin token system and was never
// swept), plain white boxes on this skin's dark page background, which
// reads as flat/washed-out ("dimmed") next to the rest of the themed
// admin. Scoped the fix to this screen's own body class
// (.the-self-hosted-self_page_ous-media-setup) since the underlying
// markup is otherwise unclassed generic labels/divs shared by no other
// screen. Verified live (contrast sweep clean, screenshot confirms the
// cards now read as real themed surfaces) — deactivated Advanced Media
// Offloader again afterward to restore this install's original state.

// 0.30.0 — Direct field report ("WooCommerce Settings and WC Home
// dashboard render completely unstyled"), confirmed real via a full
// live contrast sweep (WCAG relative-luminance JS, walking the DOM for
// the nearest real background) rather than the screenshot alone. Root
// cause on every single finding this round was the SAME shape already
// documented in this file's own changelog for the block editor: WC's
// newer admin screens (WooCommerce Home, Payments settings, Shipping
// settings) are built on @woocommerce/components + Emotion CSS-in-JS,
// a genuinely different rendering layer from classic PHP admin markup
// that never inherits this skin's body.wp-admin-scoped rules, and each
// sub-component sets its OWN explicit background/text color rather
// than inheriting from a parent card. Found and fixed by walking each
// screen live, not by reading markup: WC Home's task/inbox cards
// (.woocommerce-task-card, .woocommerce-inbox-message, plus a second
// classless <span> duplicate of an already-fixed title — the same
// text rendered twice in the DOM, only one instance originally
// caught), the Stats Overview widget (.woocommerce-summary__item's own
// white background, .woocommerce-summary__item-delta's own grey pill,
// several components-truncate spans), the Jetpack traffic-stats promo
// card's bare classless <h2> and its @wordpress/components Button (the
// same hardcoded #1e1e1e Button-text bug found repeatedly elsewhere in
// this file), the task/inbox count badges (.woocommerce-badge, its own
// hardcoded light-grey pill), the "Store coming soon" site-visibility
// badge in the admin bar itself (site-wide, not WC-Home-specific — the
// <a> carries its own light pill background, not inherited), the
// Payments tab's "Official" extension badge / payment-method-count
// pill / "More payment options" expand button, and the Shipping tab's
// blank-state help copy plus its "Recommended shipping solutions"
// panel (another @woocommerce/components card, same pattern again).
// Re-verified clean via a full contrast sweep on all three screens
// after fixing — zero findings remaining on WC Home, Payments, and
// Shipping. NOT independently verified against dev-ous.wasmer.app —
// only checked live against localhost:10008; the original field report
// screenshots were taken against the deployed environment, so confirm
// there too once this deploys.

// 0.29.0 — Real bug, direct field report (mobile screenshots): the
// admin sidebar's nav icons rendered ON TOP of their own text labels
// on a real narrow/mobile viewport ("The Self-H[icon]ted Self",
// "Design Sui[icon]e", etc.), instead of to the left of them. Root
// cause: WP core's own .auto-fold rule (admin-menu.css) sets
// div.wp-menu-image to position:absolute with a fixed left offset
// sized for core's OWN default icon-only collapsed column — meant to
// pair with core's own .wp-menu-name{position:absolute;left:-999px}
// that hides the text label entirely in that state. This skin's
// mobile menu keeps the label visible (better UX than core's icon-
// only collapse) but never reset the ICON out of that absolute
// positioning to match, so it landed directly on top of the now-
// visible text instead of beside it. Confirmed via computed style
// before fixing (position:absolute, left:78px, sitting on the label)
// and reproduced/re-verified live using the real mobile menu toggle,
// not a guessed viewport state. Fixed by restoring the icon to normal
// flex flow in that same responsive state; verified no desktop
// regression afterward.

// 0.28.0 — Continuing the systematic sweep onto own-ur-shit's own
// Debug Tools screen (API Docs, Event Tracking sections). Two real
// bugs, both on `table.widefat` (a genuinely different WP-core table
// style than `.wp-list-table`, not consistently wrapped in the shared
// .bhy-table-wrap component either): the auto-generated API Docs
// section's REST-route table had every column header at a 1.22
// contrast ratio (90 flagged elements, one shared cause), and the
// Event Tracking widget's own stats table wasn't touched AT ALL —
// solid white, not wrapped in .bhy-table-wrap so the original narrower
// fix never reached it. Broadened from a .bhy-table-wrap-scoped rule
// to cover any table.widefat directly (background, borders, striping,
// thead/tbody text) rather than patching two more one-off selectors.
//
// A companion bug in own-ur-shit's own class-debug-log.php (separate
// plugin, own changelog entry below) was also found and fixed in the
// same pass: the log-level pill's warning state was rendering white
// text on a bright yellow background.
//
// Also worth noting for future contrast sweeps: this pass caught a
// real bug in the SWEEP SCRIPT itself, not the page — some computed
// background-colors (anything that started life as a color-mix()
// expression) serialize as `color(srgb r g b)` rather than
// `rgb(r,g,b)`, which the earlier ad hoc regex-based parser silently
// failed to match, producing several false "1.09 ratio" readings on
// .bhy-badge variants that were actually fine (7.5-10.3:1 once parsed
// correctly). Re-verified with a corrected parser before trusting any
// of this pass's findings.
//
// NOT runtime-verified beyond this session's own live browser checks.

// 0.27.0 — Systematic sweep of two more real, previously-unaudited
// surfaces, following a plan-vs-reality reconciliation pass ("We need
// to get all work together on the same page... so we don't waste time
// on completed tasks" — a stale planning doc had claimed several
// things were still open that were, in fact, already done; this
// version covers the two gaps that turned out to be genuinely real):
//
// 1. jQuery UI's datepicker (`.ui-datepicker`) — completely untouched,
//    still default jQuery UI theme (white bg, grey chrome). Found live
//    via the one confirmed real usage anywhere in this ecosystem:
//    WooCommerce's "Schedule" sale-price-dates control on the Product
//    edit screen. Themed header/calendar/today-highlight/button-pane
//    to match this skin's existing popover idiom.
//
// 2. The Customizer (`customize.php`) — a whole screen this skin's
//    CSS was already loading on (admin_enqueue_scripts fires there
//    same as anywhere else) but never actually reached: its <body>
//    carries `wp-customizer`, not `wp-admin`, and nearly every existing
//    rule in this file is scoped to body.wp-admin. Confirmed live
//    (getComputedStyle on document.body.className) before writing a
//    single rule, rather than guessing why nothing applied. Added a
//    parallel body.wp-customizer-scoped section covering the sidebar
//    chrome, section/panel titles, control inputs, and the Publish
//    button — deliberately leaving #customize-preview (the live-
//    preview IFRAME, which renders the real, already-themed front end)
//    untouched, same WYSIWYG-parity reasoning as the block-editor
//    canvas. A follow-up contrast sweep on the newly-dark screen found
//    and fixed four more explicit-color holdouts the container rules
//    didn't reach: the "You are customizing X" breadcrumb, the Help
//    toggle, plain <p> tags inside a customize-control (no .description
//    class to hook), the Themes panel's hardcoded-blue "Change" button,
//    and the nav-menu-locations "(Current: X)" label.
//
// NOT runtime-verified beyond this session's own live browser checks.

// 0.26.1 — Direct follow-up: "Make sure text still works." Re-ran the
// live contrast sweep (same real relative-luminance math, not a guess)
// after 0.26.0 and found real regressions/gaps in two categories:
//
// Regressions caused BY 0.26.0's own --wp-admin-theme-color override:
// the drag-and-drop zone over "Set featured image" tints its own
// background with that same variable at near-full opacity, so white
// drop-zone text now sat on bright cyan (1.55) — recolored the text,
// not the variable, since the variable itself is correct everywhere
// else. Two more Gutenberg-specific misses from the same pass:
// .editor-post-card-panel__title-name (a second, differently-named
// post-title element alongside .editor-document-bar__post-title) and
// .block-editor-block-breadcrumb__current's own span both still had
// near-black text (1.03).
//
// Pre-existing bugs, unrelated to 0.26.0, just never audited before
// and caught by the same sweep: the Dashboard's Activity widget
// ("Recently Published" h3 headings, its own date list items) and
// Recent Comments widget (WP core's own "even" row zebra-stripe
// background, rgb(246,247,247), never themed — the actual root cause
// of comment text reading as invisible, not a text-color bug at all;
// its "Reply" button inherited the same untouched light background) —
// plus .subsubsub's own count/li elements and WooCommerce's dismissible-
// notice close link, both hardcoded WP-core/plugin greys nothing here
// had reached yet. The .subsubsub a fix from a much earlier pass never
// actually won its own cascade (body.wp-admin a's specificity beat it)
// — a real, previously-undetected specificity bug, not new.
//
// NOT runtime-verified beyond this session's own live browser checks.

// 0.26.0 — Direct feedback on the Gutenberg pass, in two rounds: "Not
// quite magical and sleek", then "It's also just got a lot of random
// white splotch blocks." Both real, both found live via computed-style
// checks (not screenshot guesses at this viewport size):
//
// Round 1 ("not magical and sleek") — two things making the now-dark
// sidebar read as flat/functional rather than designed:
//   1. The Post/Block tab bar at the very top of the document sidebar
//      (.editor-sidebar__panel-tabs) still had a stark white
//      background — a genuinely different component than the
//      inserter's .components-tab-panel__tabs already covered, missed
//      entirely. Given the same glowing accent underline every other
//      tab component in this file already uses on its active state.
//   2. Root-caused a bigger pattern: a lot of @wordpress/components'
//      own focus/active/selected treatment reads the CSS custom
//      property --wp-admin-theme-color directly, not through anything
//      a *:focus-visible rule can reach — confirmed live that body's
//      own --wp-admin-theme-color was still WP core's stock #3858e9,
//      and that a plain mouse click (not keyboard) on the "Tags" panel
//      toggle doesn't even trigger :focus-visible on a <button> per
//      spec. Overriding the custom property itself, once, at the same
//      body.wp-admin scope core sets it at, fixes every downstream
//      consumer at the source — recolored to --shsas-focus (the
//      existing cyan focus token, not the accent) to match the
//      existing *:focus-visible outline rule already using it.
//      Also gave the Tags/Categories FormTokenField's own token pills
//      the same badge/pill "composing with light" treatment already
//      unified across .bhy-badge/.order-status/.ous-badge, instead of
//      leaving WP core's flat grey pills as a third, competing style.
//
// Round 2 ("random white splotch blocks") — rather than keep guessing
// from screenshots, scanned every element on the page live for a
// near-white computed background-color. Found four real, unrelated
// gaps in one pass: the block editor's OWN Notice component
// (.components-notice — a completely different class family than the
// classic-admin .notice/div.updated already covered, so a "This block
// contains unexpected content" warning was rendering solid white; same
// left-border-glow treatment applied, not a competing style), the
// classic meta-box area at the bottom of the block editor screen
// (.edit-post-meta-boxes-main — its own .postbox children were already
// themed, the CONTAINER around them wasn't), the bottom status bar
// (.interface-interface-skeleton__footer), and the block breadcrumb
// list living inside it (.block-editor-block-breadcrumb).
//
// NOT runtime-verified beyond this session's own live browser checks
// (computed-style scans + visual screenshot, both in this exact
// environment) — no separate QA pass on a different install.

// 0.25.1 — One more real Gutenberg bug found continuing the light-mode/
// editor sweep: the Block Inspector's own "block card" (the selected
// block's name + description shown at the very top of the tab — e.g.
// "Paragraph" / "Start with the basic building block...") measured a
// 1.03 contrast ratio, near-invisible. Same untouched WP-core near-
// black default as everything else fixed this pass; never reached
// because the existing .components-panel__body rule only covers PANEL
// bodies, and the block card sits outside/above any panel. Name gets
// full --shsas-text, description gets --shsas-text-dim — matching the
// same title/secondary-text hierarchy already used everywhere else in
// this file, not flat single-color text.

// 0.25.0 — Direct request: "Gutenberg editor needs theming." The
// sidebar/inspector/inserter chrome was already covered (0.9.x era,
// see that section's own docblock), and the canvas is deliberately
// left on the theme's own front-end tokens for a real WYSIWYG
// guarantee — but the TOP TOOLBAR itself (.edit-post-header: title,
// Save draft, Preview, Publish, undo/redo/list-view/options) was never
// reached at all. Confirmed live: a genuinely stark white bar sitting
// directly above an already-dark sidebar and canvas — not a contrast
// bug on its own (black text on white reads fine), the same "designed
// by two people" coherence break this whole session has hunted
// everywhere else, just in a screen that hadn't been opened yet this
// pass.
//
// A second, real contrast bug surfaced once the bar itself went dark:
// three of its own icon-only buttons (Document Overview, View,
// Options) render a near-black icon, genuinely invisible against the
// new dark background — confirmed these are live, enabled, functional
// buttons, not a muted disabled state. Traced to the actual source
// before fixing it in the wrong layer: each icon has no explicit fill
// attribute, so it inherits fill:currentColor from its OWN button's
// text color — fixed there, not by forcing every svg's fill directly,
// which would have also clobbered two buttons that were already
// correctly colored (Block Inserter's near-white icon on its blue
// background, Settings' white icon on its own pressed-dark state) —
// both deliberately excluded via :not(.is-primary):not(.is-pressed),
// confirmed live both still render correctly after the fix.

// 0.24.0 — Direct request: "confirm light mode ecosystem wide." Toggled
// the skin to light mode and re-ran the same live contrast-scan
// methodology across the dashboard, Plugins -> Add New, Metrics, and
// Debug Tools. Two real, severe bugs found, both invisible in dark
// mode by pure coincidence, not because dark mode was actually more
// correct:
//   - .welcome-panel-header-wrap — WP core's own fixed-dark decorative
//     header (present identically in both themes, unrelated to admin
//     color scheme in core's own design) had no admin-skin rule at
//     all. In dark mode, --shsas-text (light) happened to already be
//     readable against this always-dark zone. In light mode,
//     --shsas-text switches to a dark brown, producing dark-on-dark —
//     the "Welcome to WordPress!" heading was nearly invisible on
//     first load. Fixed by forcing this one zone's text to a fixed
//     light color regardless of theme, since its background is also
//     fixed regardless of theme.
//   - Every <code>/<kbd> tag ecosystem-wide (REST routes, table names,
//     option keys — dozens on Debug Tools alone) had ZERO admin-skin
//     coverage, relying entirely on WP core's default styling
//     (background: rgba(0,0,0,.07), a muted olive-green text color).
//     That combination happens to read fine against a dark surface
//     (measured fine in dark mode) but is a 1.28 contrast ratio —
//     functionally invisible — against a light one. Fixed with real
//     tokens (--shsas-surface-2 background, --shsas-text color, not
//     --shsas-accent — using the link/accent color here would make
//     every code snippet visually read as clickable when it isn't).
//     Verified correct in BOTH themes via a real toggle + reload, not
//     just light mode alone, since this rule now governs both.
// One false alarm caught and correctly dismissed before "fixing" it:
// the welcome panel's "Dismiss" close link also flagged as low-
// contrast by the scan, but a live screenshot confirmed it's
// correctly positioned within the same always-dark header zone as the
// heading — white text there is right, not a bug; the scan's
// background-detection just didn't walk to the correct ancestor.

// 0.23.1 — Two more real bugs on the same Plugins -> Add New screen,
// found from direct feedback ("still can't read text" / "card body
// text too") after 0.23.0's fixes weren't actually the full story:
//   - <cite>By <a>Author</a></cite> on every plugin card had a 1.34
//     contrast ratio (near-black text). My first sweep only checked
//     LEAF elements (no children) and structurally walked right past
//     this — <cite> has its own direct text node ("By ") alongside a
//     child <a>, exactly the shape a leaf-only scan misses. Real
//     lesson, not just a fix: an element WITH children can still carry
//     its own unstyled text directly; a thorough contrast scan can't
//     be leaf-only.
//   - The plugin description <p> was STILL near-black (1.34) after
//     0.23.0's .plugin-card/.plugin-card-bottom fixes, because neither
//     reached it: WP core's `.widefat ol, .widefat p, .widefat ul`
//     rule (meant for list-table body text) also matches this <p>,
//     since the whole plugin-browse grid sits inside a .widefat
//     wrapper structurally — confirmed via a live matching-rules
//     query. Checked whether this same root cause reaches the
//     Installed Plugins screen too (it doesn't — already correct
//     there) before considering this done.

// 0.23.0 — Plugins -> Add New screen, a genuinely unaudited screen
// this whole session (Track 1's checklist had .plugin-card flagged as
// never confirmed) — checked, and it really was broken, not a false
// alarm. Three real, distinct bugs found via a live contrast scan
// (not a guess):
//   - .plugin-card hardcoded background:#fff from WP core, with light
//     dark-theme text landing on top of it — nearly invisible. Fixed,
//     plus .plugin-card-bottom (the rating/compatibility footer strip),
//     a SEPARATE element with its own independently hardcoded
//     background that fixing .plugin-card alone didn't reach —
//     confirmed live before assuming one rule covered the whole card.
//   - .wp-filter .filter-links .current (the Featured/Popular/
//     Recommended/Favorites tab strip's active tab) measured a 1.02
//     contrast ratio — rgb(29,35,39) near-black text on the dark page
//     background, functionally invisible. Same failure mode as the
//     already-fixed .subsubsub .current, a genuinely different WP-core
//     convention despite looking almost identical — first chased the
//     WRONG .current element (the sidebar submenu's, already correctly
//     styled) before re-scoping the search correctly.
//   - The "Search Plugins" field label measured 2.13 — also fixed
//     rather than left as a smaller, still-real problem next to the
//     one just found.
// One real link-color case (3.28, below WCAG AA's 4.5:1) deliberately
// left alone: it's the SAME accent-blue link color used consistently
// on every other admin screen, not a local bug — fixing it here would
// make links inconsistent between screens, which is worse.

// 0.22.0 — Modal/overlay haze, the third and last of the three
// promised haze/focus surfaces (own words: "all three, start with
// sidebar" — sidebar and card-groups both shipped earlier this
// session). The command palette's backdrop already had a blur, but a
// hardcoded rgba(10,6,4,.55) + flat 2px blur, completely disconnected
// from the tokenized haze system the other two surfaces use — a real,
// if subtle, inconsistency: three separately-invented approximations
// of "the world recedes when something takes focus" instead of one
// shared system. Now reads --shsas-haze-saturate/-brightness through
// backdrop-filter (which supports saturate()/brightness() exactly like
// a regular filter) plus a new --shsas-haze-backdrop-blur token (kept
// separate from the smaller --shsas-focus-sibling-blur — a backdrop
// covers the whole page, not one adjacent card, so it earns its own
// tunable value). Turning the spectrum-blender knob now affects the
// sidebar, card groups, and this backdrop together. Verified live:
// backdrop-filter computes to blur(6px) saturate(0.45) brightness(0.8)
// — the exact haze token values — while the palette itself stays fully
// sharp on top.

// 0.21.0 — Depth-aware cross-document navigation. Direct feedback:
// "I love creating organic flow and transitions between relevant
// parts of the system... streaming navigation is huge for me" and
// "I like different transitions and animations depending on the depth
// of the navigation element in the UX narrative." wp-admin is a
// traditional server-rendered app — every sidebar click is a full page
// load, hard-cutting between screens. The Cross-Document View
// Transitions spec (Chrome/Edge 126+, CSS-only opt-in via
// `@view-transition { navigation: auto; }`, admin-skin.css) replaces
// that hard cut with two genuinely different treatments depending on
// what kind of move it is: staying within the same sidebar section
// slides laterally; jumping to a different top-level section gets a
// softer scale+fade "zoom through." Direction reverses correctly on
// browser back/forward via the Navigation API's real
// activation.navigationType.
//
// One real, load-bearing timing bug found and fixed by verifying live
// rather than trusting the plan on paper: the detection logic
// (reading navigation.activation, stamping <html> before the browser
// captures the transition pseudo-elements) needs its 'pagereveal'
// listener registered before that event fires — but it fires very
// early, before a footer-loaded script (admin-skin.js, in_footer=true,
// correct for everything else it does) ever runs. Confirmed live: by
// the time the footer script executed, navigation.activation already
// held the right data, but the listener always attached too late to
// see the event. Fixed with a new, narrow exception — a tiny
// synchronous inline script (shsas_print_nav_depth_script(),
// admin_head priority 1) carrying ONLY this one listener, everything
// else stays exactly where it was. Verified live across all three
// real cases: same-section forward (lateral/forward), cross-section
// forward (jump/forward), and browser back (jump/back) all detected
// correctly.
//
// Also fixed two real specificity bugs in the reduced-motion overrides
// (caught before shipping, not after) and a cross-contamination risk:
// without cleanup, a leftover nav-depth attribute from a page load
// would incorrectly apply a slide/zoom animation on top of the theme
// toggle's own unrelated same-document circular-wipe transition, since
// both share the same ::view-transition-old/new(root) pseudo-elements.
// Attributes are stripped 700ms after being set, well after their own
// transition's animation window has passed.

// 0.20.0 — Card-group focus mode, part 2 of the haze/focus system's
// three promised surfaces (sidebar shipped first as "fastest"; modals
// are the remaining one). Hovering any card in .ous-cards/.bhy-card-
// grid recedes its SIBLINGS into haze (desaturated/dimmed/blurred)
// while the hovered card stays exactly as sharp as always — same
// tokens as the sidebar's own haze/focus system, :has()-driven, zero
// JS. New --shsas-focus-sibling-blur token (kept separate from
// --shsas-focus-glow-blur, which sizes a drop-shadow radius, not a
// blur() filter — different axis of the same spectrum). Mirrored
// identically in the front-end theme (WooCommerce product grid) and
// bh-courses (catalog), so every card grid in the ecosystem now shares
// this behavior.
//
// One real specificity bug caught before shipping, in all three
// mirrors: the reduced-motion override was written at LOWER
// specificity than the :has()/:not() rule it was meant to suppress, so
// it would have silently lost the cascade and reduced-motion users
// would still have gotten the transition. Fixed by matching
// specificity in the override, same bug/fix applied identically
// across the admin skin, theme, and bh-courses versions of this rule.
// Verified live via a real pointer hover (not a simulated event): the
// hovered card computes filter:none, its sibling computes the full
// saturate/brightness/blur stack.

// 0.19.0 — View Transitions API on the theme toggle: a real circular
// "reveal wipe" expanding out from the actual click point when
// switching light/dark, using document.startViewTransition() +
// document.documentElement.animate() targeting
// ::view-transition-new(root) with an expanding clip-path circle().
// Genuinely underrated browser primitive (Chrome/Edge 111+, Safari
// 18+) most sites don't reach for — chosen because it's a real,
// literal instance of this ecosystem's own "composing with light"
// brief (a light/dark boundary radiating outward from where you
// clicked), not decoration bolted on for its own sake.
//
// Feature-detected with a full functional fallback (typeof check on
// document.startViewTransition) and gated on prefers-reduced-motion —
// unsupported/reduced-motion browsers get the exact same toggle
// behavior as before, just without the wipe. CSS disables the
// browser's own default cross-fade on the root pseudo-elements so it
// doesn't fight the JS-driven clip-path animation, and z-index orders
// the two snapshots so what's visible outside the expanding circle is
// genuinely the OLD theme showing through, not a fade.
//
// Verified live via direct API tracing (not just "no console errors"):
// startViewTransition succeeds, its .ready promise resolves, the
// pseudo-element-targeted animate() call succeeds, and .finished
// resolves cleanly — the full pipeline confirmed working end to end in
// a real browser, the same call shape shipped in production code.

// 0.18.0 — Wallet-stack default collapse, closing a real gap Track 3's
// design-vision fidelity check surfaced: the postbox accordion (0.16.0)
// built the mechanism (animatable open/closed via grid-template-rows)
// but WP core's own postbox open/closed state starts fully OPEN for
// every user, so a first-time mobile visitor still saw every dashboard
// card fully expanded — the exact vertical-space problem the Apple
// Wallet metaphor exists to solve was never actually produced, only
// the interaction to fix it manually.
//
// Fixed in admin-skin.js: on a visitor's first mobile-width (<=782px)
// page load only (localStorage-gated so it never fires again for them
// and never fights a later explicit choice), every postbox but the
// first per column gets collapsed by dispatching a real click on WP
// core's own .handlediv button — not a cosmetic class toggle. That
// matters: a real click runs through window.postboxes and persists the
// resulting closed state via WP's own user-meta ajax call, so it
// becomes a genuine saved preference, verified live to survive a fresh
// reload, not something a reload would silently undo.
//
// One real bug caught by verifying live rather than trusting the logic
// on paper: the first version fired on DOMContentLoaded and appeared to
// run (the localStorage flag got set) but nothing actually closed —
// root cause was a script-ordering race: WP core's postboxes.js binds
// its own .handlediv click delegation via jQuery, and there's no
// guarantee that binding exists yet when a DOMContentLoaded listener
// fires. Moved to window 'load', which only fires after every script
// (jQuery-based ones included) has executed — confirmed via direct
// testing that the simulated click now lands on a bound handler.
// Desktop width verified unaffected: the flag correctly never gets set
// and no box is force-collapsed there, matching the metaphor's other
// half (fan out to use available room at large sizes, already covered
// by the existing auto-fit card grids).

// 0.17.0 — Deleted code, no new features: own-ur-shit 3.10.32 migrated
// its .ous-card stylesheet and class-metrics.php's inline <style> onto
// the shared --bhy-* tokens, which this plugin's own token bridge
// (0.8.0) already remaps. That made an entire block of colour
// overrides here genuinely dead — .ous-card-desc/.ous-card-meta, the
// whole .ous-metrics-card group, and the .ous-metrics-spark polyline
// stroke — so they're removed rather than left as harmless-looking
// duplicates. Two places setting the same colour is exactly how the
// original inconsistency happened, and leaving dead !important rules
// around is how the NEXT person gets misled about where a colour
// actually comes from.
//
// Shape rules (radius/shadow/hover-glow, via the shared .postbox
// family selector) deliberately stay: the plugin's own CSS has no
// opinion about elevation, so that genuinely is this skin's job.
// Verified live on both screens after deleting: pixel-identical,
// with the card background resolving to the warm surface token and
// the sparkline stroke computing to the accent with no rule from this
// file involved.

// 0.16.1 — Last stock icon in the sidebar, found by auditing the live
// DOM for any menu item still falling back to a font glyph rather than
// assuming 0.13.0's sweep caught everything: WooCommerce's "Payments"
// item. Its generated class is a raw query string
// (`toplevel_page_admin?page=wc-settings&tab=checkout&from=PAYMENTS_
// MENU_ITEM`), so `?`, `=` and `&` made it unusable as a plain class
// selector — the earlier `.toplevel_page_woocommerce` selector simply
// never matched it. Fixed with an attribute substring match on the
// stable, meaningful tail (PAYMENTS_MENU_ITEM), which is both simpler
// and more robust than escaping a URL WooCommerce could reasonably
// re-order. Verified: 22 of 22 sidebar items now masked, zero stock
// dashicons remaining.

// 0.16.0 — The Wallet-stack pattern, direct request: "I like Apple's
// Wallet metaphor as a way to organize similar but different stacks of
// related things — almost like a containerized accordion — to reduce
// the vertical space of screen real estate at smaller sizes and make
// the most of the space available at larger sizes."
//
// Two halves, both real:
// (1) Postboxes now animate open/closed instead of jump-cutting.
// Verified live first that WP core collapses via a `.closed` CLASS
// with NO inline style — that's what makes this animatable at all.
// display can't be transitioned, so this uses the grid-template-rows
// 1fr -> 0fr technique with .inside forced back to display:block, so
// row height (not display) does the hiding. Guarded with :has() to
// only apply where a postbox genuinely has the standard
// header + .inside structure — anything unusual keeps core's own
// behavior rather than being force-fit into a grid that might break
// it. A closed card also drops to a shallower shadow: it's holding
// less, so it sits closer to the page — same "shadow is the signal"
// logic the hover state already uses. Verified on a real 3-metabox
// edit screen: no clipping, no overflow, heights correct.
// (2) Card GROUPS (.ous-cards, .bhy-card-grid) fan out into real
// columns via auto-fit, so the column COUNT follows actual available
// width rather than a hardcoded breakpoint — one column on a phone,
// several on a wide display, continuously.
//
// Deliberately NOT applied to WP's own #dashboard-widgets columns:
// those are drag-and-drop sortable with core JS that expects core's
// container structure, and re-gridding them would risk breaking
// sorting for a purely visual gain. A real "don't fight core where it
// costs functionality" call, not an oversight.

// 0.15.0 — Fluid sizing, direct request: "use fluid sizing tricks to
// condense things and reclaim space and optimize UI flow per screen
// and per GUI." Replaced fixed spacing literals with a real clamp()
// scale (--shsas-space-1..6 on a 4px grid, matching the Track 4
// design-system research and this ecosystem's own --bhy-space-*
// precedent) plus --shsas-row-h for dense repeated rows. Each step
// interpolates continuously between a tight small-screen value and a
// roomier large-screen one instead of snapping at a breakpoint — the
// old approach was one fixed value plus a 782px media query, so a
// 900px window got full desktop padding it couldn't afford. Verified
// across 375/600/768/900/1024/1280/1440/1920: rows run 44px -> 48px,
// space-5 runs 16px -> 24px, nothing overflows, no horizontal scroll.
// --shsas-row-h deliberately FLOORS at 44px (WCAG 2.5.5 comfortable-
// tap minimum) — that's an accessibility floor, not a style choice.
//
// Which surfaced a real pre-existing accessibility bug: WP core
// AUTO-folds the sidebar to icon-only below ~960px (body.auto-fold)
// and hard-sets those rows to 34px, well under the 44px minimum —
// at exactly the tablet width where touch is most plausible. Both
// .folded and .auto-fold now respect the row-height token (height:auto
// so min-height can actually govern rather than being pinned by core's
// fixed height).

// 0.14.1 — Real bug caught in a live responsive pass (direct
// reminder: "don't forget responsive at all screen sizes"): WP core
// hides non-essential admin-bar items below 782px, and that was
// silently taking BOTH of this plugin's own additions with it — the
// Cmd/K command palette and the light/dark toggle simply did not
// exist on mobile. Backwards for the palette especially: it's a
// quick-jump launcher, and mobile is exactly where it matters most,
// since the entire sidebar is collapsed behind a hamburger there.
// Both forced visible below 782px as compact icon-only buttons (label
// and ⌘K hint still hide so they don't eat the narrow bar), with a
// min-width keeping them at a real ~42px tap target rather than
// collapsing to the 18px icon.

// 0.14.0 — Admin-bar icons, completing the "replace all stock icons
// everywhere... and branded admin bar icons and such" sweep. Six real
// dashicon-driven items (WP logo, site name/home, updates, WP 6.3+'s
// own command palette, comments, "New") now use the same Lucide set
// and same mask-image technique as the sidebar, so they're recolorable
// by this skin's own tokens rather than stuck as font glyphs.
//
// Also replaced this skin's OWN two admin-bar icons, which were raw
// unicode characters (U+26B2 for the palette trigger, U+263C/U+263D
// for the theme toggle) — genuinely the weakest icons in the whole UI,
// since a text glyph renders as whatever the system font happens to
// have and can't be stroke-matched to anything else. Both are now
// masks: a real search icon, and a sun/moon pair keyed off
// :root[data-shsas-theme] so the artwork swaps with the theme purely
// in CSS. That let admin-skin.js drop its glyph-rewriting line
// entirely (it now only owns the spin timing) — the JS was writing a
// character that would have rendered underneath the mask, so removing
// it was required, not just tidying. PHP spans emptied for the same
// reason.

// 0.13.1 — Real bug, direct feedback ("menu too much white space"),
// found by measuring rather than eyeballing: every sidebar row was
// rendering 64px tall, not the 44px intended. WP admin leaves these
// links on `box-sizing: content-box`, so min-height:44px was the
// CONTENT box and 10px+10px vertical padding stacked on top of it
// (44 + 20 = 64). Across ~24 top-level items that's ~480px of
// accidental vertical space — exactly the "airy/empty" read.
// box-sizing:border-box makes min-height mean the real row height;
// padding trimmed 10px -> 7px to match, so the 44px WCAG 2.5.5 touch
// target is now the ACTUAL height rather than a floor padding
// inflated past. Icon container 30px -> 24px in the same pass (the
// drawn icon inside is only 20px, so 30px was 10px of pure padding
// around every icon compounding the same problem), with the dashicon
// glyph retuned 28px/30px -> 22px/24px to match — still meaningfully
// larger than WP core's 20px default, which was the original point.
//
// Plus, per "keep Half-Blood Prince in mind and keep magical
// animations": nav items now resolve OUT of haze on load — starting
// blurred/dim/slightly-left, staggered 22ms per item — so the sidebar
// reads as light gathering into focus down the column. Same
// composing-with-light idea as the resting icon haze/glow system,
// expressed in time rather than space. Deliberately short (260ms) and
// small (3px drift/blur): a felt arrival, never a loading screen
// between someone and their work. Full prefers-reduced-motion opt-out.

// 0.13.0 — Icon sweep completed across the whole sidebar, direct
// request: "replace all stock icons everywhere and all ecosystem icons
// and branded admin bar icons and such." 0.12.0 covered WP core's own
// 10 items; this covers everything else — this ecosystem's 6 branded
// menus (The Self-Hosted Self hub, Design Suite, People, Contests,
// Courses, Streaming), its 3 remaining raw-dashicon menus (OUS Debug,
// BH Social, Tickets), and WooCommerce's 5 (Store, Products, Payments,
// Analytics, Marketing). 24 Lucide icons vendored total.
//
// Deliberately done as skin-side CSS masks rather than by rewriting
// each plugin's own PHP menu icon, for a real documented reason:
// WP core's svg-painter.js rewrites every `fill` attribute (including
// fill="none") on a data-URI menu icon to one solid scheme color and
// strips strokes — OUS_MenuIcons' own class docblock documents hitting
// exactly this. Lucide is stroke-based, so passing it through
// add_menu_page() would render solid blobs. A CSS mask never goes
// through svg-painter at all. Honest trade-off: each plugin's own
// PHP icon still renders when this skin is inactive — correct
// fallback behavior, and it keeps every peer plugin as portable as it
// was before rather than coupling them to this skin.

// 0.12.0 — Real icons, finally delivered: "find good icon replacement
// even if not perfect. Something good looking is better than the
// shitty default." Vendored 10 Lucide icons (assets/icons/, ISC
// license, LICENSE file included) covering WP core's own standard
// sidebar items (Dashboard/Posts/Media/Pages/Comments/Appearance/
// Plugins/Users/Tools/Settings — the ones with a real WP-core
// menu-icon-{type} class to hook). CSS mask-image (not
// background-image) specifically so the existing --shsas-item-hue
// wayfinding color AND the 0.11.0 haze/focus-glow filter system both
// keep working unchanged on these icons — a mask recolors via
// background-color, a background-image cannot be recolored by CSS at
// all. Not exhaustive (peer-plugin top-level items keep their own
// custom icons via the existing img/svg rules) — a real, good pick
// shipped now rather than a stalled search for a theoretically
// perfect one, per direct instruction.

// 0.11.0 — The "haze" half of the two-rule accent system, direct
// request: "one is the architectural foundation, and the other is the
// 'half blood prince'-ification of WordPress in the neon rebel glow
// juice kind of way, with a smoky cloudy hazy atmospheric depth and
// clarity and focus hierarchy." A real depth-of-field mechanism, not
// another glow: the sidebar's per-item wayfinding hue (already built)
// now sits desaturated/dim at REST and sharpens to full saturation +
// brightness + a real glow-bloom drop-shadow on hover/current — the
// way a point of light gains a halo pulling into focus through haze.
// Applied to all three icon-rendering modes (dashicon :before, <img>,
// SVG background-image) — .before(7n+1)'s img/svg were deliberately
// left untouched, matching their pre-existing "never colorized"
// behavior rather than picking up an unintended reddish tint from this
// change's fallback value. Applied to the ICON only, never the text
// label, which stays fully legible always — blurring/desaturating
// actual readable text for atmosphere would be a real accessibility
// regression dressed up as a stylistic choice.
//
// Immediately followed by a direct refinement: "a spectrum blender of
// different states of matter, stepped out with defaults but easily
// utility-tunable." Refactored the hardcoded saturate/brightness/glow
// numbers into real --shsas-haze-*/--shsas-focus-* tokens in :root —
// every consumer (currently the sidebar icons, all three modes) now
// reads the same shared scale instead of each rule carrying its own
// magic numbers, so tuning "how hazy" or "how strong a glow" is one
// edit that updates everywhere at once. Sidebar is the first, smallest
// proof of concept — modal-backdrop haze and card-group spotlight
// (the other two places this pattern belongs, per direct instruction)
// are real, separate follow-on work, not attempted here.

// 0.10.5 — Matching own-ur-shit 3.10.30's font correction: "less
// kitschy fonts and still more diversity." Josefin Sans -> Jost — same
// real 1920s German geometric-sans lineage (Erbar/Kabel-influenced)
// but restrained, professional proportions instead of Josefin Sans's
// more idiosyncratic tall/elongated ones. Same webfont URL change in
// both enqueue functions (admin + login), same --shsas-font-display
// token. Also grounding going forward: reference common, proven design
// systems (Tailwind/Bootstrap/GitHub Primer/Basecamp/Material/Apple
// HIG/Fluent) as a real baseline for scale decisions (radius, spacing,
// elevation) rather than ad hoc values — this file's existing
// --shsas-radius:10px/--shsas-radius-sm:6px already roughly track
// Primer's 6px small radius and Tailwind's 8-12px range, a reasonable
// starting point to keep auditing against rather than a fresh
// departure needed right now.

// 0.10.4 — Track 1 continues: bh-monetization-woo's Tier edit screen
// (a classic-editor CPT, no block editor) surfaced a real, serious bug
// that could affect ANY classic-editor CPT in this ecosystem, not just
// this one: the generic `.wp-admin input[type="text"]` etc. rule never
// had !important, and WP core's own higher-specificity #title rule
// (an explicit white background) was winning outright — this skin's
// warm cream text on a stark white field, nearly invisible. Every
// other generic input has been one specific-enough competing core rule
// away from the identical failure this whole session; !important
// closes the class of bug, not just this screen, plus an explicit
// #title rule for certainty. bh-streaming's PRO Registration wizard
// also audited this round — genuinely clean already, no fixes needed.

// 0.10.3 — The real diversification work promised in 0.10.2's
// changelog: a new --shsas-item-hue custom property, set on each
// sidebar li via the existing 7-cycle nth-of-type selectors (same set
// that already colors each item's icon), now ALSO drives that item's
// hover left-bar and "you are here" background fill — previously both
// always used the single primary accent regardless of which colored
// section was active. A real, non-arbitrary use of the diversity
// already built into this file: the section you're actually in now
// glows its own wayfinding color instead of every section
// highlighting identically. Falls back to --shsas-accent for anything
// outside the 7-cycle (there isn't anything, but a safe default
// either way). Triple-checked coherent live across Dashboard, the
// ecosystem hub, and the post editor's Publish button before this
// commit, per direct instruction not to keep piling on unverified
// changes.

// 0.10.2 — Direct correction, immediately after 0.10.0/0.10.1's orange
// swap: "too much of a pendulum with the orange, intelligent color
// diversity, with the electric blue still primary, just find valuable
// places to emphasize more shit with color and contrast." Reverted
// --shsas-accent/--shsas-accent-text back to blue/white in all three
// token blocks (dark, light, prefers-color-scheme fallback) — the warm
// neutral-ramp correction from 0.10.0 stands, only the accent-hue
// experiment is reverted. The darker jewel-tone orange hex from 0.10.0
// is kept in the palette (still a real, useful hue for badges/sidebar
// icons) even though it's no longer the accent. 0.10.1's
// .components-button.is-primary fix (the Publish button) stays — it
// was correct regardless of which hue --shsas-accent points at, and
// now correctly renders blue again. Real diversification (using the
// other six hues at genuine semantic moments, not just recoloring the
// default) is the next actual work, tracked in the session's plan
// file rather than guessed at here.

// 0.10.1 — Immediately caught while verifying 0.10.0's palette swap
// live: the block editor's actual Publish button
// (.editor-post-publish-button__button, top toolbar) stayed WP core's
// hardcoded blue — the existing .is-primary rule only ever covered
// buttons living inside the sidebar/modal, never the toolbar. The
// single most-clicked button in the whole editor was quietly the one
// thing still "too blue" after the rest of the accent swap. Broadened
// to every .components-button.is-primary regardless of container.

// 0.10.0 — Palette correction, direct feedback ("I want a warmer grey,
// and more color diversity" / "too much cool and blue" / "Jarvis it
// up"): the neutral ramp (both themes, plus the prefers-color-scheme
// fallback — all three kept in sync, per this file's own established
// pattern) was rebuilt with a genuine warm undertone and real per-step
// hue drift, replacing a ramp that was uniformly cool/blue-leaning
// despite an old comment claiming "warm/cool alternation" it never
// actually delivered. The primary accent moved from blue to orange —
// warm (works WITH the new ramp instead of against it), genuinely
// Googie/Streamline-Moderne-coded, and keeps cyan as a visually
// distinct focus-ring hue instead of "hover and accent both read
// blue." --shsas-accent-text flips to dark ink in dark mode (white-on-
// orange measured a failing 2.35:1, dark-on-orange measured 7.67:1 —
// verified) and the light-mode jewel-tone orange was darkened one
// notch further than a simple desaturation would give specifically so
// white accent-text still clears 4.5:1 now that orange carries the
// PRIMARY-accent load, not just one hue among seven. Blue stays in the
// seven-neon set (still used in the sidebar's per-item color cycling,
// 1 of 7 items) — demoted from default, not removed.

// 0.9.3 — Track 1 moves to bh-courses (never opened this session
// before now): the course edit screen's own stats strip
// (.bhc-course-stats, "N lessons · published · N total steps") had
// correctly-inherited text color but a WP-core generic light-gray
// background — same failure mode as bh-contest's .bh-stat, different
// peer plugin's bespoke markup, confirmed via elementFromPoint scanning
// after the class-name text-content search initially missed it
// (screenshot pixel coordinates and DOM element bounds didn't line up
// on the first few tries — worth remembering that a text-content
// search across all descendants of the relevant postbox is more
// reliable than guessing screenshot pixel positions when a bug is
// small).

// 0.9.2 — Direct request: "make all GUIs from WP, Woo, OUS, etc look
// designed by one person." A real, confirmed gap: earlier fixes this
// session gave .ous-card/.ous-metrics-card (own-ur-shit's own hardcoded
// dashboards) the right COLORS but never the same SHAPE as a real
// .postbox — no elevation/shadow, no shared radius token, no hover-
// glow — so they read flatter/cheaper even once the palette matched.
// Folded both into the shared .postbox/.card raised-surface rule
// (background/border/radius/shadow/hover-glow all come from one place
// now). Same finding, smaller scale, in the three badge-pill systems
// (.bhy-badge, WooCommerce's .order-status, own-ur-shit's .ous-badge):
// slightly different blur (4px vs 6px) and shadow spread (8px vs 10px)
// between them — a small but real "different hand" tell — unified
// into one shared grouped rule. Net effect: fewer, more consistent
// rules (a real code-quality win, not just visual) AND every card/
// badge in the ecosystem now genuinely shares one shape language
// regardless of which system rendered it.

// 0.9.1 — Whole-ecosystem plan, Track 1, first surface: WooCommerce's
// Product edit screen, never actually opened before this session.
// Two real bugs: `.panel-wrap.product_data` (the tabbed General/
// Inventory/Shipping panel container) ships a hardcoded white
// background — a visible white strip even though the tab content
// above it was already correctly dark from generic form rules — and
// the short-description classic editor's own tab pills
// (.wp-switch-editor) + quicktags toolbar, a different, older editor
// mechanism than the block editor's @wordpress/components already
// fixed, unstyled light-gray.

// 0.9.0 — Matching own-ur-shit 3.10.29's font correction: Righteous
// (a 1970s-80s bubble-letter novelty face) replaced with Josefin
// Sans (modeled on Rudolf Koch's Kabel and Paul Renner's Futura, both
// 1927 — real period geometric-sans construction) after direct
// feedback that Righteous read as a "cutesy version of Streamline
// Moderne" rather than genuine period design reasoning. Same webfont
// URL change in both enqueue functions (admin + login), same
// --shsas-font-display token, and the h1 rule's weight/letter-spacing
// retuned specifically for Josefin Sans's real 600 weight (Righteous
// had only one weight and needed a synthesized fake bold) and its
// taller, more tracked-out period-appropriate proportions.

// 0.8.2 — Continuing the same "keep digging" audit: own-ur-shit's
// Metrics dashboard (.ous-metrics-card) is a FOURTH separate hardcoded
// styling mechanism found this session — its own inline <style> block
// echoed directly from class-metrics.php, unrelated to the --bhy-*
// tokens or the .ous-card stylesheet already fixed. Also covers the
// sparkline charts themselves, real inline SVGs with a hardcoded
// stroke="#2271b1" presentation attribute — legitimately overridden by
// a real CSS stroke rule (presentation attributes have the lowest
// possible specificity). Four distinct "this ecosystem hardcodes its
// own light colors" mechanisms found and fixed in one session now
// (--bhy-* tokens, .ous-card, .ous-metrics-card, plus every
// WP-core/WooCommerce/@wordpress-components gap from earlier rounds) —
// worth treating "assume there's a fifth" as the default going into
// any screen not yet actually walked live.

// 0.8.1 — Immediately re-auditing after 0.8.0's --bhy-* token bridge
// (checking whether it actually closed the gaps it was meant to,
// rather than assuming): confirmed live it DID fix bh-crm's "Smart
// lists" card and a .bhy-alert-info notice, but the ecosystem
// dashboard's own plugin-activation cards (admin.php?page=own-ur-shit,
// .ous-card) were still white — traced to a THIRD, separate styling
// mechanism: own-ur-shit/assets/css/admin.css, a small stylesheet that
// predates the --bhy-* token system and hardcodes its own light colors
// directly with no custom property to bridge at all. Fixed with a
// direct override, same as every non-token-based fix this session,
// plus the glow-for-importance treatment on its status badges
// (Active/Inactive/Missing, alpha/beta/experimental feature-maturity
// badges) — a plugin's active state is exactly the kind of real
// importance signal that system exists for.

// 0.8.0 — The single highest-leverage fix of this whole design audit,
// found while chasing one white card on bh-crm's People screen: it
// traced back to own-ur-shit's OWN shared admin design-token system
// (BHY_UI::print_design_system_css(), class-ui.php) — a --bhy-*
// custom-property convention used pervasively across bh-crm, bh-
// contest, and own-ur-shit's own Design Suite/Setup Wizard/Reports/
// Portal screens (including via inline `style="background:
// var(--bhy-surface,#fff)"` rendered straight from PHP), hardcoded to
// WP core's stock light-admin colors with zero dark-mode awareness.
// Every one of those screens has been silently light-mode this whole
// session regardless of anything this plugin did, because nothing
// here ever redefined the SAME variable names — every previous fix in
// this changelog has been a per-component override; this is the
// upstream cause several of them share. Fixed with a soft interop
// bridge (shsas_bridge_bhy_tokens(), admin_head priority 999 — must
// fire after class-ui.php's own priority-10 hook to win the cascade
// tie) that redefines the --bhy-* names to point at this skin's own
// --shsas-* tokens. Not a hard dependency — pure CSS custom-property
// redefinition, no PHP/class coupling — so this plugin stays exactly
// as portable on a bare WordPress install as its own 0.1.0 changelog
// entry promises; where own-ur-shit-style --bhy-* markup DOES exist,
// it now re-themes for free instead of needing individual overrides.
// Verified live: bh-crm's "Smart lists" card and the general .bhy-card/
// .bhy-alert component family should be re-audited under this fix
// before assuming they still need the one-off treatment Track A's
// checklist originally planned for them.

// 0.7.4 — Track A moves into peer-plugin bespoke UI (the least-audited
// category — generic wp-core/WooCommerce rules obviously can't reach a
// plugin's own hand-rolled admin widgets). First find: bh-contest's
// Contest Results screen has its own stat-bar widget (.bh-stat —
// "Total Votes"/"Unique Voters"/"Last Vote") with a hardcoded white
// card background; text color was already fine. Also fixed, same
// session, a real unrelated PHP fatal found while navigating here (not
// this plugin's bug — own-ur-shit's OUS_PageSurface::add_meta_boxes()
// crashed on WooCommerce's HPOS order screens; see own-ur-shit 3.10.28).

// 0.7.3 — Track A continued: WooCommerce's Orders screen. The
// dimension-field width regression from earlier this round (WooCommerce's
// own narrow fixed-width Length/Width/Height fields clipping their
// placeholder text against this skin's larger padding) also got a
// version note there — noting it here since this batch is the actual
// commit point. New finds: `.order-status` (the status pill on both
// the Orders list and presumably the single-order screen) was a
// completely unstyled WooCommerce-core light pill — the "orphaned
// light box" failure mode in reverse (light box on a dark table
// instead of the usual white-box-on-dark-chrome). A genuinely good
// case for the glow-for-importance system from 0.7.0 rather than a
// plain color fix: an order's status IS the kind of real importance
// signal that treatment exists for, so it now shares the same
// --shsas-glow mechanism as .bhy-badge, semantically mapped
// (completed=success, processing=accent, pending/on-hold=warning,
// cancelled/failed=danger, refunded=info).

// 0.7.2 — Real, honest gap found doing a slow critical pass at real
// desktop scale (direct feedback: "it all looks bad to me... don't
// move on till its magical"): this session's ecosystem-wide font
// rollout (Righteous + Atkinson Hyperlegible) only ever touched the
// FRONT END's design tokens (own-ur-shit's BHY_Style --bh-font-*) —
// this plugin's own --shsas-font/--shsas-font-display tokens were
// never wired to them at all, so wp-admin — the exact surface being
// looked at — hadn't visually changed one bit despite the whole font
// effort. Fixed: same two fonts, loaded independently here (this
// plugin stays standalone/portable per its own stated scope, no
// dependency on own-ur-shit being active), applied as
// --shsas-font (body, all running text) / --shsas-font-display
// (Righteous, the ONE big page h1 per screen only — deliberately NOT
// every h2/h3/postbox-header, which stay on the more legible body
// face; a dense admin UI needs real scanability more than it needs
// display-face flourish everywhere, same "useful over decorative"
// rule as the rest of this session's brief). Righteous ships a single
// weight (400, no true bold master) — font-weight/letter-spacing on h1
// retuned for it specifically rather than reusing values tuned for the
// old font.

// 0.7.1 — Track A of the deep GUI audit (plan file), first item: the
// media MODAL (Add Media, Set Featured Image, any image-select
// control) is a genuinely different surface than the Media Library
// grid PAGE fixed earlier this session — .media-modal-content and
// .media-frame-content both ship their own hardcoded white background,
// with the tab pills (.media-menu-item) defaulting to near-black text
// on top. Confirmed live by actually clicking "Set featured image" on
// a real post and watching the modal open, not guessed from the
// already-fixed grid page. Full coverage: modal frame, title, tabs
// (resting/hover/active), content area, attachment grid items and
// selection outline, the right-hand details sidebar and its form
// fields, and the close button.

// 0.7.0 — The "neon on chalkboard / frosted glass that glows" importance-
// signaling system, direct request (AJ's own words, saved to session
// memory: "tourmalinated or rutilated quartz of different clarity and
// color... but not gemmy" — material quality/translucency, explicitly
// NOT another faceted ornament shape like the "art glass" mark and
// diamond nav-marker already removed earlier this session). A new
// `--shsas-glow` custom property (default: accent hue, swappable per
// element via `data-tone`/`.bhy-badge-{success,warning,danger,info}`)
// drives one consistent treatment — color-mix() background tint + soft
// box-shadow glow + backdrop-filter blur ("frosted glass") — applied
// ONLY to elements that already carry real semantic weight:
// .bhy-badge, admin-bar count badges (the count itself IS the
// importance signal, so it's on by default there), and .notice/
// .updated/.error (a faint inset color bleed off the border edge,
// ADDING to the existing solid border-color signal, never replacing
// it — the accessible signal stays the border/background, the glow is
// purely the "feels alive" layer on top). forced-colors:active strips
// the glow/blur entirely, since it was never the primary accessible
// signal to begin with. This is also the first concrete piece of the
// "PS1/Xbox OG dashboard, but with modern UX sensibilities" reference
// (the original Xbox dashboard's glowing translucent "blade" menus are
// the literal precedent for this exact visual language) — see the
// session's design-direction memory for the full brief. Ecosystem-wide
// rollout (front end + theme, per "that goes ecosystem, front end,
// backend, and theme") is a separate, larger change against
// BHY_Style's shared badge_css() — tracked in the session's plan file,
// not done in this admin-skin-only version.

// 0.6.3 — Two more real bugs, direct feedback ("the alert/toast...
// still sucks, as does the text in the tables"), both found by actually
// triggering the real interaction rather than trusting a static
// screenshot:
// (1) Clicked Save on General Settings and watched the resulting
// "Settings saved." notice render as a colored border with NO visible
// text at all — WP core gives the <p>/<strong> inside a real notice an
// explicit near-black (#1e1e1e), not inherited from the notice
// container, so this skin's existing `.notice { color }` rule (which
// also lacked !important) never reached it. This is likely the actual
// "toast" being reported — every dismissible settings-saved
// notification in the entire ecosystem was rendering invisible.
// (2) `.wp-list-table td`'s color rule also lacked !important, and a
// plugin row's own description text (.column-description p, Plugins
// screen) has a more specific WP-core rule that was winning outright —
// same failure, different table, confirmed live at #2c3338.

// 0.6.2 — Starting the extensive, systematic GUI audit (checklist now
// tracked in the session's plan file, not just ad hoc clicking). Three
// more real bugs, all the same "background themed, text color never
// set" failure mode as prior rounds, found in three DIFFERENT
// component libraries:
// (1) `.subsubsub .current` (the bold "All" list-table status filter)
// measured pure black — a WP core default nothing had reached.
// (2) `.nav-tab-wrapper`/`.nav-tab` (tabbed settings screens, e.g.
// WooCommerce's General/Products/Shipping tabs) had zero coverage at
// all.
// (3) WooCommerce's OWN page title (.woocommerce-layout__header-heading,
// rendered through @woocommerce/components' <Text>/Truncate, a
// DIFFERENT package than core Gutenberg's @wordpress/components fixed
// in 0.6.1) — same near-black-#1e1e1e default, third distinct
// component library this exact bug class has now been found in.

// 0.6.1 — Same audit, one more real bug in the block-editor chrome:
// @wordpress/components' own <Button> (used everywhere in this
// sidebar/chrome — "Set featured image" being the first one found live)
// ships a near-black default text color (#1e1e1e) with no background of
// its own, so it was invisible-by-degrees against the now-dark sidebar
// from 0.6.0 — the same failure mode as the classic-wp-admin
// .form-table th bug, just in Gutenberg's own component library.

// 0.6.0 — Explicit direction: focus on fundamentals across custom
// plugins, WooCommerce, and Etch compatibility, not more one-off
// polish. The single biggest gap found: the block editor's OWN chrome
// (Settings sidebar, block inserter, modals/popovers) is a completely
// separate @wordpress/components UI from classic wp-admin, with its
// own class vocabulary — nothing built so far touched it at all, so it
// rendered as a stark white panel next to an otherwise fully dark
// screen on every single post/page edit. This is not a cosmetic
// afterthought for this specific ask: confirmed live that a peer
// plugin's own PluginDocumentSettingPanel ("Supporter access", from
// this ecosystem's monetization plugin) was sitting unstyled in that
// white sidebar — EVERY custom plugin that adds its own block-editor
// panel was hitting this same gap — and per
// plugins/ETCH-COMPATIBILITY-NOTES.md, this exact chrome (not the
// content canvas) is Etch's own operating surface, so this is also the
// actual "Etch compatibility" surface for a wp-admin skin (Etch's
// content-level compatibility is a front-end/data-format question,
// already solved for unrelated reasons per that doc — nothing for a
// visual skin to do there). Deliberately scoped to the CHROME only —
// .editor-styles-wrapper and its contents are untouched, preserving
// the theme's own editor-style.css WYSIWYG guarantee (front-end tokens
// in the canvas, on purpose, so what an editor sees while writing
// matches what a visitor sees). Also covers WooCommerce's newer
// Product Editor, which is block-editor-based (confirmed via the
// woocommerce-feature-enabled-product-block-editor body class seen
// auditing WooCommerce Settings earlier) — same chrome, same fix.
// NOT yet click-through-verified against a real product edit screen
// or a real peer-plugin sidebar panel beyond the one already found —
// worth a wider pass across bh-contest/bh-courses/bh-crm's own
// PluginDocumentSettingPanel registrations next.

// 0.5.0 — Direct feedback after the bug-fix pass: "it's not magical."
// Fixing contrast/layout bugs gets this to "not broken," which isn't
// the same thing as the original brief (JARVIS vibes, "it just works,"
// genuinely premium out of the box). Asked what "magical" meant
// concretely; answer was "all of the above" — motion/feel, the skin
// feeling smart/alive rather than static, and real visual
// distinctiveness instead of "dark WordPress with a blue accent." Also
// asked to use the ecosystem's own front-end (bh-courses' courses.css,
// bh-contest) as the reference rather than inventing a new visual
// language from scratch — real design language already exists there
// (Streamline-Moderne-influenced: tracked-out uppercase kickers, a
// thin gradient-fade accent rule instead of a flat bar, and a
// translateY(-4px) card-hover-lift with an accent-tinted glow shadow,
// not a plain gray shadow). Changes, all borrowing that same
// vocabulary rather than a generic "add some transitions" pass:
// - The h1 accent underline is now a gradient fade (matches bh-courses'
//   .bhc-archive-rule exactly in spirit) and draws in on page load
//   instead of just appearing.
// - Postboxes/.card get the front-end's hover treatment adapted for an
//   admin context: accent-tinted glow shadow + border tint on hover,
//   deliberately WITHOUT the front-end's translateY lift — a postbox is
//   a container full of its OWN separate buttons/inputs/links (unlike a
//   single-target course card), so lifting the whole box on any hover
//   inside it would jitter constantly while reading. Shadow-as-signal
//   only, consistent with this file's existing Eames stance.
// - Buttons (not just .button-primary, which already had this) now get
//   a real hover lift + press-down feedback.
// - The sidebar's current-item icon nudges 1px toward the label on
//   hover — the front-end's "image scales up on card hover" cue,
//   translated to a scale appropriate for a 30px nav icon.
// - The command palette existed since early this session but nothing
//   on screen said so — a real, visible, labeled trigger ("Jump to...
//   ⌘K") now lives in the admin bar next to the theme toggle, not just
//   a keyboard shortcut nobody could discover. This is the actual
//   answer to "smart & alive": the palette itself already does live
//   fuzzy search over the real menu, it just needed to be findable.
// - The theme toggle's sun/moon icon now does a real flip transition
//   (rotate out, swap glyph at the midpoint, rotate back in) instead of
//   instantly replacing the character.
// - The main content area does one quiet settle-in (fade + 4px rise) on
//   page load — never the sidebar/admin bar, which should read as
//   permanent structure, not something that "arrives."
// - Every animation added here has a prefers-reduced-motion: reduce
//   fallback, matching this file's existing --shsas-speed:0 convention.
// NOT runtime-verified beyond localhost screenshots + computed-style
// checks in this session's browser tool — genuinely worth a real
// click-through pass logged in normally before calling "magical" done.

// 0.4.13 — One more real bug from the same audit pass, scrolling
// further down WooCommerce Settings: Select2 ("enhanced select" —
// country/state, selling/shipping locations, product categories, used
// by WooCommerce and potentially any peer plugin following the same
// wc-enhanced-select convention) hides the real <select> entirely and
// renders its own widget/dropdown-panel markup, so the plain
// `.wp-admin select` rule never reached it — a fully white dropdown
// sitting next to otherwise-correctly-dark form fields. Covers the
// closed control, the open results panel (appended to <body>, outside
// the field's own DOM subtree), and the in-panel search box.

// 0.4.12 — The most serious finding of this whole systematic pass:
// `.form-table th`/label — the actual field labels on EVERY classic
// WordPress settings screen (General, Writing, Reading, Discussion,
// WooCommerce's tabs, any peer plugin's Settings-API page) — had no
// color rule at all in this file, so every field label in the entire
// ecosystem sat on WP core's own near-black default, unreadable
// against this skin's dark surfaces. Confirmed via computed style on
// both General Settings and WooCommerce Settings ("Site Title",
// "Address line 1", etc. all measured rgb(29,35,39) — WP core's
// #1d2327 — regardless of screen). This is very likely the single
// biggest contributor to "usability is garbage" of anything found in
// this pass, and 0.4.10's list-table header bug was the same failure
// mode in a different core component, not a coincidence — genuinely
// worth grepping for one MORE time before calling this pass done: any
// other core text element whose color was simply never set.

// 0.4.11 — Systematic pass continued, three more real bugs found by
// actually walking Tools, WooCommerce Settings, and re-checking Woo's
// embedded-admin pages specifically (a materially different rendering
// path than plain wp-admin screens — worth checking on its own since
// this ecosystem leans on bh-monetization-woo):
// (1) WP core's plain `.card` class (Tools screen's "Categories and
// Tags Converter", used on a few settings screens too) is a different
// class than `.postbox`/`.bhy-card` and had zero coverage — pure white
// box, same pattern as 0.4.9/0.4.10's fixes.
// (2) WooCommerce's own admin CSS sets a hardcoded light background on
// #wpcontent/#wpbody-content/#mainform on its embedded settings pages,
// beating this file's original (non-!important) global ground rule —
// the whole WooCommerce Settings screen rendered light gray with
// near-black form inputs sitting on it (the inputs themselves were
// actually fine — dark surface, correct — the PAGE around them was the
// bug, easy to misread as "black redacted boxes" at a glance).
// (3) The global body background rule itself never had !important,
// which is almost certainly the same root cause for every "just this
// one screen is wrong" report — added it, matching the established
// pattern everywhere else in this file.

// 0.4.10 — Continuing the systematic pass from 0.4.9. Two more real,
// confirmed bugs found by actually walking core screens (Dashboard,
// Posts list, post editor, Settings, Media Library, Plugins) and
// checking computed styles rather than trusting a screenshot:
// (1) EVERY .wp-list-table column header (Posts, Plugins, Media,
// Users — every list screen in wp-admin) had the right dark
// background but near-black (#2c3338, WP core's own default) text on
// top of it — a real, serious contrast failure, not just an aesthetic
// one, caused by core's own thead color rule beating ours in the
// cascade (no !important on the original rule). This was very likely
// invisible in most of the earlier per-screen spot checks because the
// header ROW itself still looked "dark and correct" at a glance; the
// text on it was just unreadable.
// (2) The Media Library's filter/view-switcher toolbar (.wp-filter) —
// a different core element than any table — ships a hardcoded white
// background untouched by anything in this file, same "orphaned white
// box" pattern as 0.4.9's welcome-panel fix.
// Also confirmed, live, with this skin's stylesheet fully disabled via
// devtools: the earlier "Screen Options overlapping Howdy" collision
// on Dashboard/Posts is a pre-existing WordPress core float-wrap quirk
// at certain mid-range viewport widths (reproduces identically with
// this plugin's CSS off) — not introduced by this skin, not touched
// here, noted for the record rather than silently dropped.

// 0.4.9 — Systematic pass (direct feedback: "like the default is almost
// better yo" — a serious signal after many rounds of "still broken").
// Root cause pattern identified: this skin themes WP core's own
// top-level containers (.postbox, notices, etc.) but several core
// widgets nest a SECOND, separately-styled inner block that ships its
// own hardcoded white background, which nothing here was touching —
// each one reads as a stray white/light box against the dark chrome
// around it. First confirmed instance: the Dashboard welcome panel's
// .welcome-panel-column-container (the three-column "Author content /
// Start Customizing / Discover" block) was pure WP-core white with no
// override at all. Fixed, and auditing the rest of core's admin
// surfaces for the same pattern rather than patching one screen at a
// time.

// 0.4.8 — Direct feedback: "weird dark boxy background". Found live: WP
// core ships its OWN hardcoded sidebar-hover indicator — an inset 4px
// solid near-black (#191b1f) left bar — completely untouched by any of
// this skin's tokens (it's not something this plugin's CSS ever set,
// core just always paints it on `li.menu-top:hover`). Against this
// skin's surfaces, especially in light mode, that read as a stray dark
// smudge rather than a themed accent. Replaced with an accent-colored
// inset bar so the hover state stays legible and on-brand in both
// themes (see #adminmenu li.menu-top:hover > a.menu-top).

// 0.4.7 — 0.4.6's custom-icon fix covered <img>-based icons but missed
// a real THIRD rendering mode, found by inspecting the actual live DOM
// (own-ur-shit's own menu item uses it): add_menu_page() passed a
// base64 SVG data URI renders as neither a font glyph nor a real
// <img> — WP core puts it on div.wp-menu-image itself as a CSS
// background-image (class="wp-menu-image svg"). Confirmed by directly
// reading the rendered element, not guessed. filter still applies to
// an element's background, so the same brightness/invert + per-item
// hue-rotate approach now covers this path too, with its own selector
// (CSS has no single selector meaning "however this icon happens to
// be implemented" — font glyph, <img>, or background-image all need
// their own rule). Also, on repeated request: sidebar icons sized up
// again (26px -> 30px container, 24px -> 28px glyph).

// 0.4.6 — Three more direct fixes:
// (1) The page h1's color/background pair already measured 15-16:1
// (well past WCAG AA/AAA) — "not enough contrast" was a real
// perceptual-weight problem, not a color one: font-weight 500 reads
// thin/quiet at 27px regardless of how much luminance separation the
// numbers say exists. True bold (700) now.
// (2) The admin bar's "W" logo (far top-left) — WP core hardcodes it
// to a fixed blue independent of the rest of the toolbar's icon
// color, so it never moved with either theme and read inconsistently.
// Matched to this skin's own accent/focus tokens like everything else.
// (3) Custom SVG/PNG menu icons — a real gap in every earlier icon
// pass: add_menu_page()'s own $icon_url argument renders as a real
// <img>, not a font glyph, so none of the :before dashicon color rules
// ever touched it — most ship as a plain dark silhouette meant for
// core's own light-gray treatment, nearly invisible against this
// skin's dark sidebar (the same class of bug already fixed on the
// wp-login.php logo). brightness(0) invert(1) forces a safe, visible
// white baseline first, then a per-item hue-rotate approximates the
// same rainbow differentiation the font-icon items get — not a pixel-
// exact hue match (a raster image can't be retargeted to an exact
// color the way a font glyph's `color` can), but real, distinct-per-
// item color instead of one flat white icon.

// 0.4.4 — Direct request: every sidebar icon its own distinct, vivid
// color instead of one uniform dim gray — real wayfinding (quick
// visual scanning by color), not decoration. Cycles through the full
// seven-hue set (six neons + the new blue) via :nth-of-type(7n+N) so
// it works for however many top-level items a given install actually
// has, rather than hardcoding specific WordPress menu items this skin
// has no way to know about ahead of time (any peer plugin can add its
// own top-level page). Each item keeps its own hue in every state —
// default, hover, AND current (a brightness/saturation lift signals
// the state change instead of swapping to the single accent color,
// which would have made every item look identical the moment it's
// hovered or active, defeating the whole point of per-item color).

// 0.4.3 — Real bug, direct feedback ("hover is awful"), caught by
// actually hovering a non-current sidebar item and looking (not
// guessed): WP core renders two genuinely different things under the
// same .wp-submenu class. The CURRENT top-level item's submenu sits
// INLINE, in normal flow under its own row — transparent background is
// correct there, it's just an indented list already inside the dark
// sidebar. Hovering any OTHER (non-current) item instead pops up a
// real absolutely-positioned FLYOVER on top of the page content — and
// this skin's transparent background rule was applying to that too,
// letting the actual page content underneath show straight through
// it: a washed-out, illegible overlap, not a submenu. Split into two
// real rules — li.wp-has-current-submenu keeps the transparent inline
// treatment, every other li's flyover now gets a real opaque surface,
// border, and shadow (the same popover treatment already used for
// folded/icon-only mode, now applied here too instead of being
// folded-mode-only).

// 0.4.2 — Real contrast audit, on request, not just a visual check:
// computed actual WCAG relative-luminance ratios (the real formula,
// not eyeballed) for every text/background pair this skin controls.
// Found and fixed two genuine failures: --shsas-text-faint was 4.48:1
// in dark mode and 3.55:1 in light mode — both below the 4.5:1 AA
// minimum for normal text (dark mode missed by a hair, light mode
// failed outright). New values, found by actually searching for the
// nearest color clearing 4.5:1 rather than guessing: #7b7e83 (dark,
// 4.56:1) and #6f6b66 (light, 4.52:1). Also found and fixed a real
// token gap while auditing: the prefers-color-scheme:light fallback
// block was missing its own --shsas-neon-blue definition entirely, so
// a visitor using OS-level light mode (never touching the manual
// toggle) would have silently gotten the dark-mode-tuned, brighter
// blue instead of the properly contrast-adjusted light-mode one.
// Also, on request: bigger/clearer badges — WP core's own admin-bar
// count bubbles (comments/updates) are a ~16px pill with 9px text,
// genuinely hard to read at a glance; now 20px/12px-bold with a real
// min-width so 2-digit counts don't squeeze back down. Same treatment
// applied to this ecosystem's own shared .bhy-badge status pills for
// one consistent scale, not two.

// 0.4.1 — Several direct, related requests in one pass:
// (1) 0.4.0's icon-size bump reconsidered with real judgment after
// being asked to think about the details, not just apply one blanket
// rule: a flat font-size bump on every .dashicons would have also
// inflated small, deliberately tight utility controls (a postbox's
// collapse arrow, a notice's dismiss "X", a column-sort arrow) that
// are correctly sized for their fixed containers today — blowing
// those up overflows/misaligns them, the opposite of the
// accessibility goal. Narrowed to where bigger glyphs genuinely help
// and have room to grow: primary nav (sidebar/admin bar, already
// sized) and icon-bearing buttons/toolbars.
// (2) Primary accent switched from pink to a real electric blue
// (--shsas-neon-blue, new token) on request — pink stays in the
// palette for danger/error only, the correct semantic (blue reads as
// "informational," not "something's wrong," in every established UI
// convention), so this is additive, not a straight swap that would
// have left errors and links using the same hue.
// (3) Colorblindness/low-vision consideration, on request: nothing in
// this skin conveys meaning through color alone — every notice type
// pairs its border color with WP core's own real text/icon content,
// the focus ring is a real outline SHAPE (not just a color change),
// and the six-neon set spans enough luminance/hue difference to stay
// distinguishable under the common deficiencies, not just to
// full-color vision.
// (4) "Cool old guy friendly" — plain-language ask for genuinely
// bigger, easier text and bigger tap targets, especially on mobile:
// running text (p/li/td/.description) bumped to 14px/1.6 line-height
// site-wide; the 782px mobile breakpoint now makes text/controls
// BIGGER, not smaller (WP core's own default actually shrinks table-
// header text at this width) — form inputs specifically held at 16px
// on mobile so iOS Safari's auto-zoom-on-focus never triggers, a
// genuinely disorienting default for exactly this audience.

// 0.4.0 — Bigger, clearer icons everywhere on request: WP core ships
// dashicons at a flat 20px across the whole admin (sidebar, admin bar,
// buttons, notices, list-table row actions, metaboxes). One real
// global rule (.dashicons, .dashicons-before:before at 22px) catches
// genuinely all of them at once, with the sidebar/admin-bar glyphs
// getting their own slightly more specific override (24px/22px) since
// those two already had their own resized container boxes to keep the
// larger glyph vertically centered rather than clipped.

// 0.3.5 — Real critique pass on the Posts list-table screen, after
// being asked to check GUIs across the admin critically rather than
// just Dashboard: every row-title link inherited the global accent
// link color, so an entire table of posts read as a solid block of
// pink — monotonous, and it drowned out the actual "what's hovered/
// selected" hierarchy the color was supposed to carry. Row content
// now stays on the neutral text color by default (same as any other
// body copy), accent only on hover/focus — scoped to table body
// content, the global link-accent rule is untouched everywhere else
// (nav, notices, real navigational links). Verified in both dark and
// light mode, not just one.

// 0.3.4 — Real regression caught immediately after 0.3.3 shipped (not
// left for later): swapping WP core's select-arrow SVG for a
// currentColor-matched one wasn't enough on its own — core's rule
// also carries background-position/-size tuned to ITS specific
// image's proportions, and without re-declaring those too, the new
// image rendered stretched across the whole control (looked like a
// strikethrough through the select's own text) instead of a small
// corner arrow. Fixed by setting background-image/-repeat/-position/
// -size and padding-right together as one real unit, not the image
// swapped in isolation. Confirmed on the Posts screen's "Bulk
// actions"/"All dates"/"All Categories" selects, both themes.

// 0.3.3 — Asked to make sure default WordPress GUI elements are
// accounted for, not just the sidebar/palette already covered: added
// real coverage for Screen Options and Help (the two tabs top-right of
// every admin screen, and the panels they open — entirely unstyled by
// default, stark white against this skin otherwise), native checkbox/
// radio theming (accent-color, the real modern low-code way to theme
// a native control's checked fill), and the admin bar's notification
// surfaces specifically (comments/updates/"New" bubble counts and
// their real dropdown panels — a separate DOM tree from the plain
// top-menu links already covered, so they needed their own rules).

// 0.3.2 — 0.3.1 fixed the WRONG cause: the truncation rules were
// correct all along, but WP core's own #adminmenu div.wp-menu-name
// rule ships a baked-in `padding: 8px 8px 8px 36px` (core reserves
// 36px on the left for its own absolutely-positioned icon — a totally
// different layout model than this skin's flex icon+gap). Never
// resetting it meant the icon indent was applied TWICE — this skin's
// own 20px icon + 10px gap, AND core's 36px padding baked into the
// text element itself — so even "Dashboard" truncated on a column
// with plenty of real room. Reset to `padding: 0 !important` so this
// skin's own flex spacing is the ONLY spacing in effect. Also dropped
// the small geometric "diamond" marker on the sidebar's current item
// and the earlier conic-gradient "art glass" mark on every h1 —
// direct, repeated feedback: no small gem/jewel shapes anywhere in
// this skin. The h1 now gets one plain, thin, single-hue accent
// underline instead.

// 0.3.1 — Real root cause found via live computed-geometry inspection
// (not another screenshot guess), after direct feedback that the whole
// skin "looks weird": 0.3.0's ellipsis fix for sidebar labels was
// itself losing a cascade fight against WP core's own .wp-menu-name
// styling (core intentionally supports wrapping for long custom-post-
// type labels) — EVERY label was still wrapping to 2 lines, including
// short ones like "Posts", confirmed by measuring a 33.5px-tall (2-
// line) box against 90px of genuinely available width. Fixed two ways:
// (1) !important on the truncation properties in admin-skin.css,
// matching this file's own established pattern for beating wp-admin's
// baked-in styles; (2) wp_enqueue_style() now declares 'wp-admin' as a
// real dependency instead of an empty array, guaranteeing this
// stylesheet prints after core's own regardless of plugin load order —
// defense in depth, not just the !important alone. Confirmed live:
// .wp-menu-name for "Posts"/"Dashboard"/"The Self-Hosted Self" all now
// report single-line box heights with a genuine ellipsis truncation on
// the long label, not a 2-line wrap.

// 0.3.0 — Real design pass, not another palette tweak: fixed a
// genuine layout bug caught live (sidebar labels were breaking
// mid-word — "Dashboard" -> "Dashb"/"oard" — because .wp-menu-name
// had no overflow handling once the flex layout gave it a
// content-driven width; now a clean single-line ellipsis truncation,
// nothing wraps mid-word). Then a real compositional pass, not just
// more color: the six-neon rainbow used to repeat as a bar under
// every h1 — now it's a single small faceted "art-glass" mark (a
// conic-gradient hexad, one per screen, at the front of the title)
// echoed by a small diamond marker on the sidebar's current item, so
// the whole skin shares one geometric "you are here" language instead
// of two unrelated idioms. Added real horizontal rhythm (a full-width
// divider under every h1/h2 — Wright's own horizontal emphasis,
// translated) and shifted postboxes toward shadow-as-the-signal
// rather than border-as-the-signal (Eames: the material should read
// as raised on its own, a border on top of a shadow is redundant
// weight) — border kept, but faint, only as a floor for displays with
// no shadow rendering. Confirmed visually on localhost, logged in,
// both the mid-word-wrap fix and the new title mark/sidebar marker.

// 0.2.0 — Real palette rework, direct feedback after the first pass:
// a genuinely "themeless" grayscale neutral ramp (warm/cool alternated
// step to step for real depth, not flat gray) instead of the first
// pass's single warm terracotta ground, with a full six-hue neon
// rainbow (pink/orange/yellow/lime/cyan/violet, ~60° apart) doing
// every accent job instead of one overworked color — links, focus
// rings, notice types, the h1 underline (now a real rainbow sweep, the
// one deliberately loud flourish on every screen). Neons are used only
// for small bounded elements (borders, underlines, icons, badges,
// focus rings) — body text stays on the neutral ramp throughout, so
// contrast never suffers. Confirmed visually on localhost's login
// screen (dark mode) — the sidebar/command-palette/toggle still need a
// real logged-in click-through, not yet done this pass.

// 0.1.0 — First pass. Pure wp-admin CSS/JS, zero dependency on any
// other plugin or theme in this ecosystem (or anywhere else) — this is
// deliberately a portable "WordPress mod," not something coupled to
// The Self-Hosted Self's own design tokens, so it behaves identically
// on a bare WordPress install.

define('SHSAS_VER', '0.31.0');
define('SHSAS_URL', plugin_dir_url(__FILE__));
define('SHSAS_PATH', plugin_dir_path(__FILE__));

/**
 * Deliberately admin-only — is_admin() covers wp-admin screens
 * (including AJAX-adjacent admin pages) without ever touching a
 * front-end request, so this plugin can never conflict with any
 * theme's own front-end styles regardless of what site it's on.
 */
function shsas_enqueue_assets(): void {
    // 'wp-admin' as a dependency (not just registered/loaded some other
    // way) guarantees WP core's own admin stylesheet prints BEFORE this
    // one — real bug found live: an empty deps array left load order to
    // chance, and core's own .wp-menu-name rule won a cascade tie
    // against this plugin's truncation rule on this exact install.
    // Defense in depth alongside the !important fix in the CSS itself.
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', ['wp-admin'], SHSAS_VER);
    // Same two fonts as the ecosystem-wide front-end default (own-ur-
    // shit's BHY_Style::FONT_OPTIONS) — Righteous for display, Atkinson
    // Hyperlegible for body — loaded independently here rather than via
    // a class_exists() call into own-ur-shit, matching this plugin's
    // own stated "standalone and portable" scope (it must keep working
    // even if own-ur-shit isn't the active core plugin on some other
    // install). Real gap this closes: the CSS tokens below
    // (--shsas-font/--shsas-font-display) were pointing at these fonts
    // with nothing fetching the actual webfont files — the exact same
    // bug just fixed on the front end (BHY_Style::print_global_css()),
    // just never wired into wp-admin at all in the first place.
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
    wp_enqueue_script('shsas-admin-skin', SHSAS_URL . 'assets/js/admin-skin.js', [], SHSAS_VER, true);

    // The command palette needs a flat list of every real admin-menu
    // link WordPress core already built for THIS user (capability-
    // filtered, current site) — reading it straight out of $menu/
    // $submenu (the same globals wp-admin's own menu renderer uses)
    // rather than re-deriving access rules is the only way this stays
    // correct for a role that isn't a full admin, and the only way it
    // stays accurate for whatever mix of plugins happens to be active.
    wp_localize_script('shsas-admin-skin', 'shsasMenu', ['items' => shsas_flatten_admin_menu()]);
}
add_action('admin_enqueue_scripts', 'shsas_enqueue_assets');

// wp-login.php is NOT an is_admin() screen — its own hook, and
// deliberately CSS-only there (no command palette/menu data makes
// sense on a page with no admin menu yet).
function shsas_enqueue_login_assets(): void {
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', [], SHSAS_VER);
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
}
add_action('login_enqueue_scripts', 'shsas_enqueue_login_assets');

/**
 * Real, high-leverage bug found auditing bh-crm's People screen (Track
 * A of the design audit): own-ur-shit's own shared admin design-token
 * system (BHY_UI::print_design_system_css(), class-ui.php) defines a
 * whole SECOND set of CSS custom properties — --bhy-surface,
 * --bhy-ink, --bhy-border, --bhy-accent, etc. — that bh-crm, bh-contest,
 * and own-ur-shit's own admin screens (Design Suite, Setup Wizard,
 * Reports, the Portal, "Layer 3" components like .bhy-card/.bhy-alert)
 * all reference directly, INCLUDING via inline `style="background:
 * var(--bhy-surface,#fff)"` attributes rendered straight from PHP.
 * Those tokens are hardcoded to WP core's stock LIGHT admin colors with
 * no dark-mode awareness at all — every one of those screens was
 * silently rendering in light mode no matter what this skin did,
 * because nothing here ever redefined the SAME variable names.
 *
 * This is a soft interop bridge, not a hard dependency: it only
 * redefines CSS custom properties by name (never calls any own-ur-shit
 * PHP/class), so this plugin stays exactly as portable as its own
 * doc comment above promises — on a bare WordPress install with no
 * own-ur-shit, these variables are simply unused. Where own-ur-shit
 * (or any plugin using this same, apparently ecosystem-wide --bhy-*
 * naming convention) IS active, every one of its own components
 * re-themes for free, instead of needing an individual CSS override
 * per component the way every other fix in this file's changelog has
 * worked so far.
 *
 * Priority 999 is load-bearing: class-ui.php's own token block is also
 * a plain :root rule with equal specificity, printed via its own
 * admin_head hook at the default priority (10) — later-in-source wins
 * a specificity tie, so this MUST fire after it, not just be present.
 */
function shsas_bridge_bhy_tokens(): void {
    echo '<style id="shsas-bhy-token-bridge">:root{'
        . '--bhy-ink:var(--shsas-text);--bhy-ink-dim:var(--shsas-text-dim);'
        . '--bhy-border:var(--shsas-border);--bhy-surface:var(--shsas-surface);'
        . '--bhy-subtle:var(--shsas-surface-2);--bhy-accent:var(--shsas-accent);'
        . '--bhy-success:var(--shsas-success);--bhy-success-bg:color-mix(in srgb,var(--shsas-success) 16%,var(--shsas-surface));'
        . '--bhy-warning:var(--shsas-warning);--bhy-warning-bg:color-mix(in srgb,var(--shsas-warning) 16%,var(--shsas-surface));'
        . '--bhy-danger:var(--shsas-danger);--bhy-danger-bg:color-mix(in srgb,var(--shsas-danger) 16%,var(--shsas-surface));'
        . '--bhy-hover-tint:var(--shsas-surface-2);'
        . '--bhy-selected-tint:color-mix(in srgb,var(--shsas-accent) 16%,var(--shsas-surface));'
        . '--bhy-focus-ring:0 0 0 2px color-mix(in srgb,var(--shsas-accent) 25%,transparent);'
        . '--bhy-radius:var(--shsas-radius);--bhy-radius-sm:var(--shsas-radius-sm);'
        . '}</style>';
}
add_action('admin_head', 'shsas_bridge_bhy_tokens', 999);

/**
 * Real bug caught by verifying live, not by trusting the plan on
 * paper: the depth-aware cross-document navigation transition
 * (admin-skin.css's data-shsas-nav-depth/-direction-scoped keyframes)
 * needs its 'pagereveal' listener (admin-skin.js) registered BEFORE
 * that event fires — but admin-skin.js is enqueued with in_footer=true
 * (correct for everything else it does: the theme toggle, the command
 * palette, the Wallet-stack default all have no reason to block
 * rendering). 'pagereveal' fires very early, essentially as soon as
 * the browser has resolved the page's `@view-transition` opt-in from
 * <head> — confirmed live: by the time footer-loaded admin-skin.js
 * ran, navigation.activation already held the right data, but the
 * listener attached too late to ever see the event that would have
 * used it.
 *
 * Fix: this ONE listener gets its own tiny, synchronous, INLINE script
 * printed as early in <head> as this plugin can manage (priority 1,
 * lower number than every other admin_head hook here), completely
 * separate from the main admin-skin.js file. Everything else this
 * plugin does stays exactly where it was — this is a narrow exception
 * for the one piece of logic that has a genuine "must run before a
 * specific early browser event" constraint, not a wholesale move of
 * admin-skin.js into <head>.
 */
function shsas_print_nav_depth_script(): void {
    ?>
<script>
window.addEventListener('pagereveal', function () {
    if (!('navigation' in window)) return;
    var activation = window.navigation.activation;
    if (!activation) return;
    var root = document.documentElement;
    var direction = activation.navigationType === 'traverse' ? 'back' : 'forward';
    root.setAttribute('data-shsas-nav-direction', direction);
    var fromUrl = activation.from && activation.from.url;
    var toUrl = activation.entry && activation.entry.url;
    if (!fromUrl || !toUrl) { root.setAttribute('data-shsas-nav-depth', 'jump'); return; }
    function sectionOf(u) {
        try {
            var url = new URL(u);
            var page = url.searchParams.get('page');
            if (page) return page.split('-')[0];
            return url.pathname.split('/').pop();
        } catch (e) { return null; }
    }
    var sameSection = sectionOf(fromUrl) !== null && sectionOf(fromUrl) === sectionOf(toUrl);
    root.setAttribute('data-shsas-nav-depth', sameSection ? 'lateral' : 'jump');
    setTimeout(function () {
        root.removeAttribute('data-shsas-nav-direction');
        root.removeAttribute('data-shsas-nav-depth');
    }, 700);
});
</script>
    <?php
}
add_action('admin_head', 'shsas_print_nav_depth_script', 1);

/**
 * A real, working light/dark toggle living in the admin bar (visible
 * on every admin screen) rather than a buried settings-page checkbox —
 * the whole point of a system preference is that you change your mind
 * about it in the moment, not that you go find a settings screen to
 * change it. admin-skin.js does the actual toggling (reads/writes
 * data-shsas-theme + localStorage); this just gives it somewhere real
 * to live and a11y-correct markup (a real <button>, not a div with a
 * click handler).
 */
function shsas_admin_bar_toggle($wp_admin_bar): void {
    if (!is_admin()) return;
    // Direct feedback: the Cmd/Ctrl+K command palette existed but
    // nothing on screen told anyone it was there — a keyboard shortcut
    // nobody knows about isn't a feature, it's a secret. A real, visible
    // trigger button makes the "smart" part of this skin discoverable
    // instead of hidden, same a11y-correct <button>-via-add_node pattern
    // as the theme toggle right next to it.
    $wp_admin_bar->add_node([
        'id' => 'shsas-palette-trigger',
        // Empty span, not a unicode glyph — the artwork is a CSS mask
        // (Lucide search icon) so it matches every other icon in the UI
        // rather than being whatever the system font renders for U+26B2.
        'title' => '<span class="shsas-palette-trigger-icon" aria-hidden="true"></span><span class="shsas-palette-trigger-label">Jump to&hellip;</span><kbd class="shsas-palette-trigger-kbd" aria-hidden="true">&#8984;K</kbd>',
        'href' => '#',
        'meta' => ['class' => 'shsas-palette-trigger', 'title' => 'Jump to any admin page (Cmd/Ctrl+K)'],
    ]);
    $wp_admin_bar->add_node([
        'id' => 'shsas-theme-toggle',
        // Empty span for the same reason as the palette trigger above —
        // the sun/moon artwork is a CSS mask keyed off
        // :root[data-shsas-theme], so it swaps with the theme
        // automatically and matches the rest of the icon set.
        'title' => '<span class="shsas-toggle-icon" aria-hidden="true"></span><span class="screen-reader-text">Toggle light/dark admin theme</span>',
        'href' => '#',
        'meta' => ['class' => 'shsas-theme-toggle', 'title' => 'Toggle light/dark admin theme'],
    ]);
}
add_action('admin_bar_menu', 'shsas_admin_bar_toggle', 999);

/**
 * @return array<int, array{label: string, url: string, parent: string}>
 */
function shsas_flatten_admin_menu(): array {
    global $menu, $submenu;
    $items = [];

    foreach ((array) $menu as $item) {
        // Core's own $menu rows: [0]=label (may carry a <span> update-count
        // badge), [2]=slug/url, [4]=css classes — a separator row's [4]
        // contains 'wp-menu-separator' and has no real label, skip it.
        if (empty($item[0]) || (isset($item[4]) && strpos((string) $item[4], 'wp-menu-separator') !== false)) continue;
        $label = trim(wp_strip_all_tags((string) $item[0]));
        if ($label === '') continue;
        $slug = (string) ($item[2] ?? '');
        $items[] = ['label' => $label, 'url' => shsas_menu_url($slug), 'parent' => ''];

        foreach ((array) ($submenu[$slug] ?? []) as $sub) {
            $sub_label = trim(wp_strip_all_tags((string) ($sub[0] ?? '')));
            if ($sub_label === '') continue;
            $items[] = ['label' => $sub_label, 'url' => shsas_menu_url((string) ($sub[2] ?? '')), 'parent' => $label];
        }
    }

    return $items;
}

function shsas_menu_url(string $slug): string {
    if ($slug === '') return '';
    // A real top-level page slug (no '.php', no query string) resolves
    // to admin.php?page=<slug> — the same fallback menu_page_url()
    // itself uses internally; anything else (edit.php?post_type=..., a
    // plain *.php core screen) is already a real, relative admin URL.
    if (strpos($slug, '.php') === false && strpos($slug, '?') === false) {
        return admin_url('admin.php?page=' . $slug);
    }
    return admin_url($slug);
}
